# Actualizaciones mediante Reinstalar

El egg de [RyzeCompany/egg-ryzemp](https://github.com/RyzeCompany/egg-ryzemp/pull/1) actualiza la instalacion al pulsar **Reinstalar**. El administrador del panel debe importar una vez la nueva version del egg. Un reinicio normal conserva la version instalada.

## Canales

- `RYZEMP_CHANNEL=pruebas` (predeterminado): ultima prerelease publicada y preparada para el egg.
- `RYZEMP_CHANNEL=estable`: ultima release no marcada como prerelease y preparada para el egg.

La seleccion usa la fecha de publicacion. Solo participan releases con el asset `ryzemp-update.json`. Los borradores y releases antiguas sin ese archivo no se instalan. Por ahora, la integracion 2225 se distribuye por **pruebas**; no existe una release estable preparada. Cambiar a estable no convierte una beta en estable.

## Publicar una version actualizable

1. Completar las pruebas del PHAR y de la version nativa necesaria.
2. Mantener `tools/ryzertc-runtime.json` con la version **exacta** de RyzeRTC, API, ABI y SHA-256 probados con ese PHAR.
3. Publicar PHAR y checksums mediante el workflow de release. El pipeline normal verificado y los tags `v*` generan el manifiesto automaticamente: tags con sufijo (`-beta`, etc.) van a pruebas; los demas, a estable.
4. El workflow publica `ryzemp-update.json` **despues** de los binarios. Ese archivo habilita la actualizacion. No editar los assets de una version ya distribuida: publicar un tag nuevo.

La publicacion inicial genero el manifiesto para `ryzemp-2225-ryzertc-integration.2`, conservando el PHAR y los logs ya publicados. El manifiesto describe el mismo PHAR probado y RyzeRTC v0.1.0-dev.3; no implica una recompilacion.

## Validacion y recuperacion

El egg obtiene las releases publicas oficiales por HTTPS, verifica el digest del manifiesto proporcionado por GitHub y los SHA-256 de PHAR y ZIP nativo. Antes de sustituir la instalacion comprueba PHP 8.4 ZTS x86_64, OPCache, ABI/API/version nativa y el PHAR real con `--version`.

PHP, RyzeRTC, PHAR, lanzador y registro de version se sustituyen juntos con un diario de recuperacion. Un error de sustitucion restaura la instalacion anterior. Si el proceso se interrumpe, el lanzador detiene el arranque y la siguiente reinstalacion recupera el diario. Una reinstalacion sin cambios conserva la ultima copia util en `.ryzemp-backups/previous/`.

Se conservan mundos, plugins, configuraciones y `nethernet/operator-key.pem`. RyzeRTC permanece fuera del PHAR. La IP y el unico puerto publico TCP/UDP siguen siendo `server-ip` y `server-port`.

Si no hay release preparada, GitHub falla o un archivo no pasa la validacion, el instalador termina con un mensaje util sin sustituir la instalacion actual. Un nuevo formato de manifiesto o una ABI distinta requieren actualizar el egg del panel. No se baja automaticamente de version dentro del mismo canal.

Composer es una distribucion independiente para desarrolladores. El egg descarga binarios de GitHub Releases y no ejecuta `composer update` sobre el servidor.
