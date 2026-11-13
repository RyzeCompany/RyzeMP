# RyzeMP — Go nativo en VPS Linux (beta)

**Rama:** integration/vanillamine-worldgen (PR #60, Draft). Usa solo mundos nuevos de prueba, sin afectar producción. Para el desarrollo se utilizan los archivos de VanillaMine fijados a 4dd2228b47d64c8015c28138c846e6c4acb28345.

## 1. Obtener PHAR y ejecutable Go por separado

Descarga el PHAR desde un workflow verde de [Build and Release Phar](https://github.com/RyzeCompany/RyzeMP/actions), seleccionando la rama del PR #60 y el artefacto **RyzeMP-phar**.

Descarga el artefacto **RyzeMP-vanillagen-linux-amd64** de [Build native VanillaMine Go worldgen](https://github.com/RyzeCompany/RyzeMP/actions/workflows/ci-worldgen-go.yml). Contiene el ejecutable, SHA256SUMS, VERSION.txt y SOURCE.txt. Go no va dentro del PHAR.

Después de extraer el ZIP en una carpeta de tu servidor Linux x86_64:

    cd /home/container/bin
    sha256sum -c SHA256SUMS
    chmod 700 vanillagen-linux-amd64
    ./vanillagen-linux-amd64 -version

Debe responder con **protocol 7 algorithm 4**. No instales un ejecutable con SHA-256 incorrecto.

## 2. Preparar los datos originales de Java 26.3

Desde el checkout de desarrollo RyzeMP, con PHP 8.4 y Composer instalados:

    php -d memory_limit=2G tools/prepare-vanillamine-worldgen.php /home/container/vanillamine-cache
    php tools/worldgen-data-fingerprint.php /home/container/vanillamine-cache

La segunda orden imprime **sha256: HEX** (64 caracteres). Usa ese HEX verdadero en las siguientes configuraciones. Guarda los datos del caché fuera de los mundos y no los alteres después de crear un mundo. Los archivos de Mojang no van en el PHAR.

## 3. Crear un mundo de prueba nuevo

En server.properties, **antes de crear el mundo**:

    level-name=world
    level-seed=12345
    level-type=ryze_vanilla_overworld
    generator-settings=core-v6;data=/home/container/vanillamine-cache;sha256=HEX

En dimensions.yml:

    nether:
      world: world_nether
      source-dimension: nether
      worldgen-preset: 'core-v6;data=/home/container/vanillamine-cache;sha256=HEX'
    end:
      world: world_the_end
      source-dimension: end
      worldgen-preset: 'core-v6;data=/home/container/vanillamine-cache;sha256=HEX'

No reutilices los nombres ni carpetas de tus mundos actuales. Haz copias offline de LevelDB, inventarios, configuraciones y caché antes de probar.

## 4. Arrancar Go de forma opcional

En el proceso/shell que inicia tu servidor RyzeMP de pruebas, configura:

    export RYZEMP_VANILLAGEN_BINARY=/home/container/bin/vanillagen-linux-amd64
    export RYZEMP_VANILLAGEN_SHA256="$(sha256sum "$RYZEMP_VANILLAGEN_BINARY" | cut -d ' ' -f1)"
    export RYZEMP_VANILLAGEN_STAGE=full

Luego ejecuta tu comando habitual para iniciar RyzeMP.phar con PHP 8.4. Si usas Pterodactyl, pasa las tres variables al entorno de arranque de un **servidor de pruebas**. No hace falta cambiar el Egg ni asignar otro puerto público.

En la consola deben aparecer los mensajes **[Worldgen Go] vanillagen ready on loopback** y **[Worldgen Go] Native terrain session verified** al generar chunks. El proceso Go se comunica exclusivamente a través de 127.0.0.1 y un puerto efímero interno. No modifica el puerto público de Bedrock, RakNet ni RyzeRTC.

**Modos:**
- Sin la variable RYZEMP_VANILLAGEN_BINARY: backend PHP compilado original.
- Con binario pero sin la opción full: terreno calculado en Go; materiales y carvers en PHP.
- Con RYZEMP_VANILLAGEN_STAGE=full: terreno, materiales y carvers en Go; estructuras, placed features, NBT, LevelDB y multiversión continúan bajo RyzeMP PHP.
- Si Go falla, el proceso vuelve al backend PHP. Requiere equivalencia confirmada por CI antes de mezclar resultados en mundos permanentes.

## 5. Validación y métricas reales

Compara dos mundos **nuevos distintos** con las mismas semillas, caché, coordenadas, número de async-workers y versión de PHAR: uno con Go y otro usando PHP.

Mide tiempo de generación por lote, p50/p95 incluyendo cola, TPS al explorar con jugadores, uso total CPU/memoria de PHP **más** Go, guardado LevelDB y reinicio. Prueba Bedrock 1.26.40, 1.26.60 y clientes mixtos. Las pruebas automáticas no sustituyen conexiones reales.

El workflow [Worldgen real Java 26.3](https://github.com/RyzeCompany/RyzeMP/actions/workflows/ci-worldgen-realdata.yml) ejecuta la matriz de equivalencia Go/PHP con datos auténticos, y arranca el PHAR con Go. **Usa el ejecutable solo si todas esas pruebas están verdes.** El benchmark Go aislado no mide el rendimiento completo del servidor.

## 6. Recuperación

Para regresar a PHP, detén el servidor y elimina las tres variables RYZEMP_VANILLAGEN_BINARY, RYZEMP_VANILLAGEN_SHA256 y RYZEMP_VANILLAGEN_STAGE antes de reiniciar. Conserva intacto el caché y los LevelDB. Si la CI muestra diferencias de generación o sucede una corrupción en pruebas, descarta el mundo experimental y restaura el respaldo offline, no reutilices el mismo mundo con otra versión del algoritmo.

**Licencia y origen:** componentes PHP de VanillaMine bajo LGPL-3.0-or-later; binario Go compilado desde la revisión fijada indicada arriba. No incluir ni redistribuir activos propietarios de Mojang.
