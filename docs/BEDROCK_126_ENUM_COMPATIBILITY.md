# Bedrock 1.26: resultados de la investigación de autocompletado

## Evidencia en cliente

En Minecraft Bedrock 1.26.60, los valores de **hard enums con mayúsculas**
(en HCF: `King`, `Zeus`, `NONE`, `HEART`) se presentaban como
`default default`, aunque el servidor anunciaba e indexaba sus valores
correctamente. La matriz visual suministrada por el usuario compara:

| Control | Valores anunciados | Resultado visual |
|---|---|---|
| `elab1` | hard enum `king/zeus/mythic` | sugerencias correctas |
| `elab2` | hard enum `King/Zeus/Mythic` | `default default` |
| `elab3` | soft enum en mayúsculas | sugerencias sin tipado hard enum |
| `elab4` | hard enum minúsculo + collapse | sugerencias literales |
| `elab5` | un valor de hard enum minúsculo | `king none` |

Las dos primeras pruebas usan nombres internos de enum distintos (ambos
alfanuméricos), así que la comparación respalda con fuerza **normalizar los
valores de hard enums en HCF**, pero no demuestra por sí sola el detalle
interno del error del cliente. Falta probar `/crate` real en 1.26.40/1.26.60.

## Revisión de RyzeMP PR #62

Se descartaron y **retiraron del código ejecutable**:
- `EnumProbeTrace`: instrumentación de red dependiente de comandos de
  diagnóstico que ya no existirán en el PHAR de HCF.
- `rewriteDiagnosticProbeEnumNames`: modificar nombres internos de enums
  no tiene evidencia de resolver el fallo de los valores en mayúsculas.
- `withVanillaCompatibleFallback`: el cliente mostraba
  `[args: text]`, pero los hard enums seguían saliendo como `default`.

Se conservaron de `main`, sin alteraciones:
- API nativa de `CommandOverloadProvider`, Commando integrado y
  `CommandMetadata::canonicalizePacketEnums`.
- Adaptación multiversión del paquete `AvailableCommandsPacket`.
- Protección existente para `/help` en 1.26.

El **único cambio de este PR frente a main** es una prueba de regresión que
verifica que los valores minúsculos de hard enums conservan sus valores y
metadata al codificarse y decodificarse con los protocolos 2168, 2193 y 2225.
**No hay modificaciones de código de producción en RyzeMP ni BedrockProtocol.**

## Corrección en HardCoreFactions PR #4

`HcfArgument::wireValues()` normaliza únicamente los valores de hard enums
anunciados a Bedrock; los nombres guardados en la BD, mensajes, modos de
recompensas, IDs de crates y nombres internos de efectos siguen intactos.
`HcfCommand` aplica la misma regla a los alias literales de subcomandos.

Las pruebas verifican `king` y `rainbow_helix` en metadata y que
`crate edit effect test rainbow_helix` almacena `RAINBOW_HELIX`.
Los comandos de laboratorio no se distribuyen en el PHAR final.

## Pruebas finales

Instalar el PHAR de RyzeMP de este PR **y** el PHAR de HCF PR #4 del mismo
lote, reiniciar y comprobar en cliente Bedrock 1.26.60 y 1.26.40:
`/crate edit effect king`, `/crate place king`, `/crate remove king`.
Confirmar también los menús `hopper/chest` y los modos
`/crateitem setcommand player|console`. No fusionar como solución
definitiva hasta comprobar la UI real y el comportamiento del comando.
