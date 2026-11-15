# Más jugadores por servidor: red, C++ y Go (rama perf-network)

Base: `vanilla-content` d4d549d75 (árbol congelado `work/wt-perfnet-base`). PHP 8.4.16 + chunkutils2 0.3.9
(`work/tools/php84-perf`, JIT tracing). VM KVM de 4 vCPU (Ryzen 5950X), Windows Server 2025. 100 StressBots con
semilla 1001, mundo con semilla 1001, mediciones de 120 s tras 150 s de asentamiento (`scripts/perf100.ps1`,
cola `scripts/perf-queue.ps1`).

## El reloj: por qué los timings anteriores engañaban

`hrtime()` cuesta ~7 µs en esta VM (QueryPerformanceCounter sobre el temporizador ACPI). Cada par de timings de
PocketMine sumaba ~15 µs y los informes de timings salían inflados ×2, con las secciones pequeñas mucho más
afectadas que las grandes. chunkutils2 0.3.9 añade `chunkutils2_tsc()` (rdtsc, 12 ns) y `TimingsHandler::now()`
lo usa cuando existe, calibrado contra `hrtime()` una vez por hilo. Con él, un tick completo con timings activados
mide 47 ms en vez de 89 ms, y los informes se pueden leer tal cual.

Perfil del escenario "stay" (100 bots paseando por el spawn, sin cambios de mundo), base, ms por tick del hilo
principal:

| sección | ms/tick | notas |
|---|---|---|
| tick de entidades | 29.4 | jugadores 11.9, peces/calamares 10.2, osos polares 2.9, resto |
| reparto de paquetes (broadcast) | 7.6 | 162 broadcasts/tick a ~100 destinatarios cada uno |
| envío por sesión | 5.8 | compresión 3.2, chunks 1.3, ensamblado y entrega 1.3 |
| random ticks | 5.2 | ~800 chunks ticking: la lista de capas en PHP, no el muestreo |
| plugin StressBots | 6.3 | coste del propio harness (mueve a los bots) |

El reparto y la compresión crecen con el cuadrado de los jugadores juntos: cada movimiento va a cada espectador.

## Cambios

### Red (PHP)

1. **Prefijo de longitud una sola vez**: el broadcaster codifica y prefija cada paquete una vez; cada sesión
   concatena (`implode`) sus buffers al vaciar, en vez de volver a recorrer y copiar cada paquete
   (`PacketBatch::encodeRaw`, 26 µs por sesión y tick con 150 paquetes). Mismos bytes (`PacketBufferPrefixTest`).
2. **Sin agrupar por broadcaster/compresor**: `NetworkSession::hasOnlyStandardSessions()` (contador mantenido por
   las sesiones) evita dos llamadas por destinatario en cada broadcast cuando todas las sesiones usan el
   broadcaster estándar y el compresor del servidor. `Player::getConnectedNetworkSession()` reúne
   `isConnected()` + `getNetworkSession()`.
3. **Hilo de compresión** (`network.compression-thread`, desactivado por defecto desde el 2026-10-17: un jugador real notaba retraso al romper bloques y al entrar con él activado): `CompressionThread` comprime los
   lotes de las sesiones en un hilo propio; el hilo principal solo los entrega (microsegundos) y los envía cuando el
   hilo lo despierta, normalmente mientras espera el siguiente tick. El lote de una sesión que se desconecta se
   comprime en síncrono para que salga antes de cerrar; al apagar se comprime lo que quede. A diferencia de
   `async-compression` no hay una AsyncTask por lote ni umbral de tamaño (`CompressionThreadDispatcherTest`).
4. **Distancia de espectadores inline** en `Player::broadcastMovement()` y `Mob::broadcastMovement()` (una llamada
   menos por espectador y tick).

### C++ (chunkutils2 0.3.9, rama `fast-clock` de work/deps/ext-chunkutils2-perf)

5. **`NativeSendBuffers`**: los buffers de envío de todas las sesiones viven en C++ (id numérico por sesión, nunca
   reutilizado). Las entidades guardan los ids de sus espectadores junto a `hasSpawned`; un broadcast codifica el
   paquete una vez y lo añade a todos los destinatarios en **una llamada** (`appendMany`), sin ninguna llamada PHP
   por destinatario. Sin la extensión se usan los arrays PHP de antes (`NativeSendBuffersTest`,
   `MobMovementBroadcastTest` con los buffers nativos desactivados).
6. **`RandomTickSampler::sampleSubChunks()`**: el muestreador lee el almacén de subchunks del chunk
   (`Chunk::getSubChunkStorage()`); antes PHP construía cada tick la lista de la capa 0 de los 24 subchunks de cada
   chunk ticking (~5 ms con 100 jugadores). Mismas elecciones (`RandomTickSamplerTest`).
7. Comprobación de nieve polvo por id de estado (`Living::doFreezeTick()`): antes dos objetos Block por jugador y
   tick.

### Go (vanillagen, núcleo de terreno)

8. **Secciones empaquetadas** (`FLAG_PACKED_SECTIONS`, protocolo 6): el núcleo devuelve cada subchunk ya empaquetado
   en el formato de `PalettedBlockArray::fromData()` (paleta de materiales + palabras) y los materiales como bytes;
   PHP solo traduce la paleta a estados de bloque. Antes `writeChunk()` recorría los 98k bloques del chunk y el
   muestreador de terreno de las estructuras otro tanto. Mismos bloques, biomas y máscaras
   (`NativeTerrainFillerTest::testPackedSectionsMatchRuns`). `generateChunk()` con el núcleo: 77.6 → 65.4 ms
   por chunk; lo que queda es casi todo el propio núcleo Go (`fill` 66 ms), siguiente objetivo.

### Estructuras (PHP, el coste real de explorar)

Perfilando `generateChunk()` por fases con el núcleo Go: `fill` (Go) 18 ms, `writeChunk` 5 ms, `recordTerrain`
1.5 ms... y la adaptación del terreno a las estructuras (`StructureManager::getBeardifier()`) **67 ms por chunk**.
No eran las comprobaciones de emplazamiento (6 ms en 49 chunks) ni los biomas, sino ensamblar los jigsaw: un
*trial chambers* (531 piezas) tardaba 2.2 s en el worker. Causas y cambios:

9. `FreeSpace::fits()` comprobaba cada candidato contra todas las cajas ya colocadas: ahora rejilla de celdas de 16
   bloques (`FreeSpaceTest`, misma respuesta que la lista plana).
10. Cada candidato (plantilla × rotación × jigsaw, cientos de miles por estructura) volvía a rotar los jigsaws de su
    plantilla y a calcular su caja: `SinglePoolElement` los guarda por rotación en el origen y los desplaza. La
    ordenación estable por prioridad de selección se omite cuando todas son iguales; la LRU de plantillas no reordena
    la entrada que acaba de devolver. Mismo consumo del generador aleatorio, mismas piezas (`StructureTest`,
    `FeatureTest`). Adaptación: 67 → 30 ms por chunk; queda ~1 s por trial chambers repartido en el bucle de
    candidatos (siguiente objetivo si hace falta).

## Resultados

Hilo principal, CPU ms por tick (sin timings), secuencial, base y rama alternados:

| escenario | base | perf-network |
|---|---|---|
| stay, 100 bots (2 repeticiones) | 41.1 / 39.7, 20 TPS | **33.0 / 31.7**, 20 TPS (−20 %) |
| estándar, 100 bots (hops + exploración) | 77.3, 12.0 TPS reales, workers 117, núcleo Go 37 | **72.8**, 12.7 TPS, workers 97, núcleo Go 29 |
| stay, 200 bots (2 repeticiones) | 80.7 / 80.4, 11.7 TPS reales | **55.7 / 58.7**, 16.8 / 15.9 TPS (−29 %) |

Cola completa en `archive/build-test/perf-queue-pn.txt` (plan en el scratchpad de la sesión: base y rama
alternados, 10 ejecuciones).

En el escenario estándar manda la generación de mundo (workers y el núcleo Go): el hilo principal baja poco, los
workers un 17 % (secciones empaquetadas). Con 200 jugadores juntos la parte cuadrática (reparto y compresión) es
la que se ha recortado.

Tests: PHPUnit completo OK (1334 + los nuevos), PHPStan 0 errores.

## Qué NO cambia (vanilla)

Ninguna regla de juego: el mismo movimiento, los mismos random ticks (mismas elecciones aleatorias), el mismo
terreno. Cambia cuándo se comprime un lote (en otro hilo, enviado unos milisegundos más tarde como mucho mientras
el servidor no está saturado) y dónde viven los buffers.

Notas para el operador:
- `chunk-ticking.blocks-per-subchunk-per-tick` es 3 (Java). Bedrock vanilla usa `randomTickSpeed` 1: con 1 el
  servidor haría un tercio del trabajo de random ticks y se comportaría como Bedrock (cultivos más lentos que
  Java). No se ha cambiado.
- `network.compression-level` 6 por defecto; con el hilo de compresión ya no cuesta al hilo principal. Medido sobre
  lotes reales: nivel 1 es 2× más rápido y solo un 5 % más grande.
- Windows Defender (MsMpEng) llegó al 90 % de un núcleo durante compilaciones y pruebas; una exclusión para la
  carpeta del servidor es decisión del operador.
