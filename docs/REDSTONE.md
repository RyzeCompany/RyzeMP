# Redstone nativa: base de desarrollo

Trabajo aislado en `feature/native-redstone`. Esta primera etapa prepara la
propagación eléctrica; todavía no representa una implementación completa de
redstone Bedrock.

## Implementado en esta etapa

- Contrato `pocketmine\block\utils\RedstonePowerSource`: potencia débil y fuerte
  por cara. La cara apunta desde la fuente hacia el receptor; valores 0–15.
- Consultas compartidas en `RedstoneSignal`, sin cargar chunks y sin encadenar
  indefinidamente la conducción entre bloques sólidos.
- Fuentes: bloque de redstone, palancas, botones, placas normales y ponderadas,
  sensor solar, y estados existentes de antorchas, repetidores y comparadores.
  Las placas ponderadas conservan su intensidad analógica.
- Polvo: propagación horizontal, escalones, atenuación de una unidad por bloque,
  direcciones de salida y apagado al retirar las fuentes.
- Lámparas: encendido por señal, apagado diferido y actualización de iluminación
  mediante las escrituras normales del mundo.
- Tolvas: bloqueo mediante el motor compartido, incluyendo señales indirectas;
  se conservan los inventarios, transferencias y eventos existentes.
- Notificaciones deduplicadas mediante la cola nativa de vecinos del mundo,
  incluyendo receptores al otro lado de los bloques de soporte.

## API para plugins

`BlockRedstoneUpdateEvent` se emite antes de cambiar la intensidad del cable,
el encendido eléctrico de una lámpara o el bloqueo eléctrico de una tolva.
`getOldPower()` y `getNewPower()` permiten consultar la transición;
`setNewPower(0..15)` permite ajustarla y `cancel()` conserva el estado anterior.
Lámparas y tolvas interpretan cualquier valor positivo como activado.

Las interacciones con palancas, botones y placas mantienen sus eventos nativos.
El evento nuevo no reemplaza las protecciones de interacción ni aplica reglas
de claims. Los futuros pistones y dispensadores requerirán eventos propios antes
de modificar bloques, inventarios o entidades.

## Pendiente antes de declarar redstone completa

1. Temporización e inversión de antorchas, agotamiento y recuperación;
   retardos/bloqueo de repetidores; cálculo y lectura de comparadores.
   Esta etapa sólo expone eléctricamente sus estados existentes.
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

Validación de esta base: 558 pruebas y 148.763 comprobaciones con PHP 8.4.16.
Las 17 pruebas nuevas aportan 1.831 comprobaciones. Se usaron las dependencias
del PHAR anterior y se contrastaron los archivos de código con `main`.

También se verificó un circuito con un mundo real: palanca → tres cables
(15, 14, 13) → lámpara; al retirar la fuente se apagaron los cables y después
la lámpara. El servidor arrancó y se detuvo correctamente. El constructor del
plugin de prueba está en `tests/integration/build-redstone-smoke.php`; debe
ejecutarse exclusivamente con datos temporales de pruebas, pues reemplaza el
chunk de origen de ese mundo.

La ejecución de GitHub Actions se detuvo al instalar dependencias por un error
de autenticación de Composer contra GitHub, antes de ejecutar PHPUnit. No se
modificaron secretos ni workflows para evitar ese error.

## Referencia

Se revisó [Cosmoverse/Redstone](https://github.com/Cosmoverse/Redstone), revisión
`c069e89a055105a0a2b6c68db03335c795d92204`. Su objetivo Java 1.8 orientado a cañones
no se adopta como contrato de compatibilidad de RyzeMP. Esta base utiliza los
registros, clases, estados e inventarios nativos de RyzeMP y mantiene PHP 8.1
como requisito declarado del proyecto.
