# Captura forense de dimensiones, protocolo 2193

Esta rama no altera portales, ACK, teletransporte ni generadores. Durante la transferencia guarda lotes de red comprimidos, **antes de cifrarlos**, solo si se habilita expresamente.

## Seguridad

- Variable opt-in: `PMMP_DIMENSION_WIRE_CAPTURE=1`.
- Utilizar únicamente en un servidor de PRUEBAS con un jugador, sin plugins ni resource packs.
- Los archivos `.jsonl` BRUTOS pueden contener chat, NBT, letreros y datos del jugador; **no los compartas ni los subas a GitHub**.
- Archivos privados (0600), carpeta restringida (0700); captura limitada a tres segundos, 8 MiB y 512 lotes.
- El analizador produce un resumen sin datos binarios: tiempos, IDs, dimensiones, hashes, conteos y errores estructurales.

## Uso desde celular y VPS

1. Respalda el servidor y mundos. Prueba el PHAR generado por CI de esta rama en una COPIA.
2. Arranca con variable de entorno:

```bash
PMMP_DIMENSION_WIRE_CAPTURE=1 ./start.sh
```

3. Desde Android 26.52, entra UNA VEZ al portal. No repitas el fallo hasta analizar.
4. Localiza la captura en la carpeta de datos:

```bash
find . -path '*/dimension-captures/dim2193-*.jsonl' -type f
```

5. Analiza con Python 3 en el VPS (cambia RUTA_REAL):

```bash
python3 tools/analyze_dimension_capture.py 'RUTA_REAL.jsonl' > dimension-report.json
```

6. Comparte solo `dimension-report.json` y un `server.log` revisado por si contiene IPs, XUIDs o nombres privados.
7. Desactiva la variable al terminar. Conserva la captura en el VPS para análisis adicional, luego bórrala.

## Qué valida

- Decodificación independiente de lotes ZLIB, y SNAPPY si hay python-snappy instalado.
- Orden real de envío al transporte de ChangeDimension, MovePlayer y LevelChunk, tras comprimirse.
- Campos 2193 de ChangeDimension y LevelChunk, integridad de subchunks y paletas, NBT restante y hashes de chunk.
- Errores estructurales y diferencias con un servidor de referencia usando el mismo cliente.

**Limitación:** estar en el transporte no prueba recepción ni aceptación por Minecraft Android; un reporte válido no descarta incompatibilidades semánticas con el cliente.
