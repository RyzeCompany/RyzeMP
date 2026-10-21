# Redstone nativa: propagación y componentes temporizados

Trabajo aislado en `feature/native-redstone`. Estas etapas preparan la
propagación eléctrica y los componentes temporizados; todavía no representan
una implementación completa de redstone Bedrock.

## Implementado

- Contrato `pocketmine\block\utils\RedstonePowerSource`: potencia débil y fuerte
  por cara. La cara apunta desde la fuente hacia el receptor; valores 0–15.
- Consultas compartidas en `RedstoneSignal`, sin cargar chunks y sin encadenar
  indefinidamente la conducción entre bloques sólidos.
- Fuentes: bloque de redstone, palancas, botones, placas normales y ponderadas,
  sensor solar, antorchas, repetidores y comparadores.
  Las placas ponderadas conservan su intensidad analógica.
- Polvo: propagación horizontal, escalones, atenuación de una unidad por bloque,
  direcciones de salida y apagado al retirar las fuentes.
- Lámparas: encendido por señal, apagado diferido y actualización de iluminación
  mediante las escrituras normales del mundo.
- Tolvas: bloqueo mediante el motor compartido, incluyendo señales indirectas;
  se conservan los inventarios, transferencias y eventos existentes.
- Notificaciones deduplicadas mediante la cola nativa de vecinos del mundo,
  incluyendo receptores al otro lado de los bloques de soporte.
- Antorchas: inversión de la alimentación del soporte y cambio tras dos ticks de
  juego; se mantienen las reglas nativas de soporte e iluminación.
- Repetidores: entrada posterior, salida direccional, retardos de 2/4/6/8 ticks
  de juego y bloqueo por diodos laterales orientados hacia el repetidor. Conservan
  los pulsos cortos; una entrada restablecida evita un apagado pendiente.
- Comparadores: modos comparación/resta, entradas laterales y recálculo tras dos
  ticks de juego. Lectura de inventarios nativos, incluidos cofres dobles y
  contenedores detrás de un único bloque conductor. Las mutaciones de inventario
  notifican sólo a los comparadores afectados, sin sondeo periódico.
- Cola eléctrica por mundo: conserva el primer pulso pendiente, ordena por plazo
  y secuencia, comprueba estado/chunk antes de ejecutar e invalida actualizaciones
  cuando el bloque se reemplaza, incluidas las escrituras silenciosas.

## API para plugins

`BlockRedstoneUpdateEvent` se emite antes de cambiar la intensidad del cable,
el encendido eléctrico de una lámpara, el bloqueo eléctrico de una tolva o la
salida de una antorcha, repetidor o comparador.
`getOldPower()` y `getNewPower()` permiten consultar la transición;
`setNewPower(0..15)` permite ajustarla y `cancel()` conserva el estado anterior.
Lámparas, tolvas, antorchas y repetidores interpretan cualquier valor positivo
como activado; los comparadores conservan la intensidad analógica ajustada.

Las interacciones con palancas, botones y placas mantienen sus eventos nativos.
El evento nuevo no reemplaza las protecciones de interacción ni aplica reglas
de claims. Los futuros pistones y dispensadores requerirán eventos propios antes
de modificar bloques, inventarios o entidades.

## Pendiente antes de declarar redstone completa

1. Agotamiento/recuperación de antorchas y prioridad de diodos simultáneos
   conforme a Bedrock; lecturas de comparadores en bloques sin inventario
   (atriles, tocadiscos, tartas, calderos, marcos, etc.). No se importa el
   agotamiento Java del plugin de referencia como comportamiento Bedrock.
2. Recuperación de circuitos y actualizaciones pendientes al cargar/descargar
   chunks y reiniciar. Las consultas actuales sólo consideran terreno cargado;
   no se promete aún reanudación automática de circuitos guardados.
3. Puertas, trampillas, vallas, bombillas de cobre, cofres trampa, atriles,
   tocadiscos, bloques musicales y TNT.
4. Pistones normales/pegajosos, bloques inmovibles, slime/miel, movimientos
   completos y cancelables, colisiones y conservación de inventarios.
5. Dispensadores, soltadores y observadores.
6. Raíles/vagonetas y mecanismos modernos que necesitan sistemas adicionales.
7. Validación de temporización Bedrock, comportamiento en bordes de chunks,
   circuitos grandes y pruebas reales con las versiones de cliente compatibles.

## Validación

`tests/phpunit/block/NativeRedstoneTest.php` cubre fuentes analógicas y
direccionales, conducción por un único sólido, no conducción por cristal,
atenuación/apagado del cable, fuentes múltiples, escalones, chunks no cargados,
temporización de lámparas, bloqueo de tolvas y cancelación/ajuste por plugins.
Usa un mundo simulado para probar las transiciones de los componentes. La suite
general verifica además estados y serialización; esto no sustituye las pruebas
de juego ni de carga con un servidor real.

La suite completa pasa con PHP 8.4.16: 575 pruebas y 149.563 comprobaciones.
`RedstoneTimingTest.php` añade 17 pruebas de retardos, pulsos cortos, bloqueo,
reemplazo de bloques, chunks descargados, inversión de antorchas, comparación y
resta, inventarios simples/dobles y cancelación/ajuste de eventos.

La prueba de servidor real verifica palanca → tres cables (15, 14, 13) → lámpara,
repetidor de seis ticks → lámpara, inversión de antorcha de dos ticks y cofre →
sólido → comparador con salidas 1, 15 y 0 al retirar el cofre. Comprueba además
que el cambio analógico positivo se conserva en la tile nativa del comparador.
El servidor arranca y se detiene correctamente. El constructor está en
`tests/integration/build-redstone-smoke.php` y también se ejecuta desde CI.
Debe usarse exclusivamente con datos temporales de pruebas, pues reemplaza el
chunk de origen de ese mundo.

El bloqueo anterior de autenticación de Composer está resuelto: la revisión
`87d832d` superó regresión, integración HCF y construcción/validación del PHAR.

## Referencia

Se revisó [Cosmoverse/Redstone](https://github.com/Cosmoverse/Redstone), revisión
`c069e89a055105a0a2b6c68db03335c795d92204`. Su objetivo Java 1.8 orientado a cañones
no se adopta como contrato de compatibilidad de RyzeMP. Esta base utiliza los
registros, clases, estados e inventarios nativos de RyzeMP y mantiene PHP 8.1
como requisito declarado del proyecto.
