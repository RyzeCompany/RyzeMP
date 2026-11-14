# Diagnóstico Bedrock de enums en el protocolo 2225

## Evidencia de cliente real
HardCoreFactions PR #4 contiene dos comandos independientes, ambos con un único
overload de dos enums: `/crateprobe` (Commando integrado) y
`/crateprobenative` (API nativa sin Commando). En Minecraft Bedrock 1.26.60,
los dos muestran `default default` en lugar de `King / Zeus` y
`NONE / HEART`. También falla `/crate edit effect`.

Este resultado **no demuestra** aún que el serializador de RyzeMP esté mal:
los comandos comparten el sistema de envío de metadatos y el cliente, y el
round-trip interno del servidor no reemplaza un cliente real ni un decodificador
de protocolo independiente.

## Prueba diferencial C: nombres de enums con y sin `:`

En el **último PHAR experimental** de este PR, los probes siguen anunciando
exactamente los mismos valores, pero con nombres internos distintos:

| Comando | nombre de enum de primer argumento | segundo |
|---|---|---|
| `/crateprobe` (control) | `hcf:crateprobe:commando:namecrate` | `hcf:crateprobe:commando:effect` |
| `/crateprobenative` (variante) | `RyzeProbeNativeNamesB` | `RyzeProbeNativeEffectsB` |

Esta transformación se hace en `NetworkSession::syncAvailableCommands()`,
**solo en los metadatos del comando de prueba nativo**, no modifica el comando
registrado en el mapa, los parámetros reales, su ejecución, la base de datos
ni la serialización de los demás comandos de RyzeMP.

Si el segundo comando empieza a sugerir `King/Zeus` y `NONE/HEART`
mientras el primero sigue mostrando `default`, la evidencia señalará los
**identificadores de los enums**, no los valores, como causa probable.
Si ninguno funciona, descartaremos esta hipótesis y continuaremos con el
paquete global y el parser de comandos del cliente/protocolo.

Este cambio requiere instalar un **nuevo PHAR de RyzeMP** de esta rama,
reiniciar y reconectar; conservar el PHAR experimental de HCF PR #4.

## Cambios en esta rama
- Únicamente instrumentación de los paquetes `AvailableCommandsPacket`
  **ya serializados, después de `DataPacketSendEvent`**.
- El código analiza directamente las referencias numéricas `type_info`,
  `enum_index`, `enumValueIndex`, nombre de enum y valores de los dos
  probes. No usa el disassembler de alto nivel para obtener los valores.
- Registra como máximo **cuatro paquetes por sesión**, solo cuando aparecen
  ambos comandos de diagnóstico de HCF. No registra IPs, XUID, chat ni bytes
  de paquetes completos.
- No altera el paquete enviado, los comandos, los permisos ni los protocolos.
- Prueba de regresión para protocolos 2168, 2193 y 2225 con enums de pruebas y
  un rango externo que contiene `default`.

## Cómo probar
1. Usa el PHAR experimental de HardCoreFactions PR #4 que incluya los probes.
2. Usa una compilación de RyzeMP **de esta rama** en un servidor de prueba;
   evita mezclar versiones anteriores y conserva copias de mundos/configuración.
3. Reinicia, entra con un jugador OP usando Bedrock 1.26.60 y comprueba los dos
   probes y `/crate edit effect`.
4. En `server.log`, busca `[EnumProbeTrace]` para cada paquete enviado.
   Identifica `stage=wire`, `stage=raw` y los dos nombres de comando.
5. Compara las referencias y valores. Si ambos comandos muestran
   `valid=true` y sus cuatro enums son correctos en todos los paquetes, la
   investigación deberá continuar con una decodificación independiente del
   formato o con la interpretación del cliente. Si aparecen valores
   `default`, índices inválidos o cambios en un refresh, ya habrá un
   punto concreto para corregir en el núcleo.
6. Repite con Bedrock 1.26.40 (protocolo 2168) tras el análisis 2225.

Esta instrumentación es **temporal** y no debe fusionarse como corrección
definitiva. Eliminaremos el tracing cuando se aísle el fallo y añadiremos una
prueba de regresión que reproduzca su causa.
