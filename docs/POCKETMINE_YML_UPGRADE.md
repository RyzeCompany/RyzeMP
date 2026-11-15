# Actualización de pocketmine.yml: RyzeMP clásico → RyzeMP con VanillaMine

Este documento compara `main` (configuración anterior de RyzeMP) con la nueva base VanillaMine del PR #63. No es una migración automática y **no modifica datos del servidor**.

## Comportamiento comprobado

- En el arranque, RyzeMP copia `resources/pocketmine.yml` **solo si no existe** `pocketmine.yml` en el directorio del servidor. Si ya existe, lo carga como está. **No se sobrescriben comentarios, opciones personalizadas ni claves antiguas**.
- Las opciones nuevas ausentes se resuelven por los valores de respaldo que pasa cada componente a `ServerConfigGroup::getProperty*`. Por ejemplo, NetherNet sin PEM configurados sigue utilizando el modo HTTP compatible; los certificados TLS siguen siendo opcionales.
- `server.properties` se gestiona aparte: conserva valores ya definidos, completa claves nuevas si faltan (por ejemplo `enable-nethernet`) y no sustituye `server-port`, `level-name` ni `level-type` existentes.
- El Egg conserva ambos archivos, mundos, plugins, clave NetherNet y datos de jugadores en reinstalaciones. Descarga RyzeRTC y RyzeVanillaGen como dependencias externas verificadas.

## Diferencias relevantes y acciones

| Opción | Anterior | Base VanillaMine / solución RyzeMP | Recomendación |
|---|---|---|---|
| `network.compression-level` | `6` | `1` para reducir CPU | En servidores actualizados, cambiar a `1` si se busca rendimiento; `6` sigue funcionando |
| `memory.async-worker-hard-limit` | `256` MiB | `384` MiB | Revisar RAM disponible y trabajadores si se usa generación Vanilla |
| `auto-report.enabled` | `false` | **`false`** (corregido; VanillaMine lo habilitaba) | Mantenerlo desactivado salvo consentimiento explícito |
| `auto-updater.enabled` | `false` | **`false`** (corregido; VanillaMine lo habilitaba) | Usar releases de GitHub y el Egg para las actualizaciones |
| `auto-updater.host` | `update.pmmp.io` | Vacío; no usar `updates.vanillamine.com` | Las APIs heredadas no publican las releases de RyzeMP. Un endpoint personalizado necesita HTTPS y formato compatible |
| `fishing.enabled` | `true` | Compatible | Se respeta la configuración anterior; `false` desactiva la caña |
| `fishing.pvp-enabled` | `true` | Compatible | Se respeta; también depende de `pvp` en `server.properties` |
| `fishing.max-distance` | `24` | `32` para instalaciones nuevas, **valor anterior conservado** | Si conservas `24`, la caña respeta `24` |
| `fishing.cooldown-ticks`, `pull-strength`, `pull-vertical`, `bite-wait-*`, `bite-window-ticks`, `lifetime-ticks` | Ajustables | **Obsoletos** en la nueva pesca VanillaMine | No se borran, pero ya no modifican la mecánica. RyzeMP advierte al iniciar |
| `level-settings.min-y` | `0` | Obsoleto | Altura definida por la dimensión/generador; se advierte sin alterar mundos |
| `chunk-generation.vanilla-native.enabled` | Sin opción equivalente | `true` | Activa RyzeVanillaGen externo cuando está disponible; el fallback PHP sigue funcionando |
| `network.nethernet.https.certificate` / `private-key` | No presentes | Vacíos | Solo añadir PEM válidos si se requiere HTTPS; iOS requiere certificado confiable |
| `mobs.spawning.*`, `dimensions.enabled`, `loot-tables.auto-download` | Sin opciones equivalentes | Defaults VanillaMine | Revisar solo si quieres controlar el comportamiento; no se fuerzan cambios en el archivo existente |

## Actualización de configuración sin perder ajustes

1. Detén el servidor y guarda una **copia** de `pocketmine.yml`, `server.properties` y `worlds/`.
2. Mantén el `pocketmine.yml` actual; RyzeMP **no hará una migración ni lo reemplazará automáticamente**.
3. Compara con el [archivo nuevo de referencia](https://github.com/RyzeCompany/RyzeMP/blob/integration/vanillamine-base/resources/pocketmine.yml). Copia únicamente las opciones que quieras personalizar. Las ausentes no requieren añadirse.
4. Recomendación de rendimiento para equipos con suficiente memoria: `network.compression-level: 1` y `memory.async-worker-hard-limit: 384`. Conserva valores antiguos si tienes limitaciones de RAM o razones para usarlos.
5. Verifica que `auto-report.enabled` y `auto-updater.enabled` estén en `false`; en archivos viejos con `true` explícito, el sistema **respeta la elección del operador**. Las URLs PMMP/VanillaMine dejan de usarse para comprobaciones de actualización.
6. Confirma el mismo `server-port` para TCP y UDP en Wings. Para NetherNet se requiere RyzeRTC API 2 cargado desde el Egg. El nuevo `level-type=vanilla` se aplica **solo a un servidor nuevo** sin `server.properties` antiguo.

No borres el archivo actual para «recibir las opciones nuevas»: eso descarta ajustes de red, memoria, juego y plugins. No copies ciegamente el archivo VanillaMine sobre el de RyzeMP.

## Límites de validación antes de una release

- La CI valida que un YAML antiguo se cargue sin modificar su contenido, mantenga compresión/memoria/puerto/tipo de mundo personalizados y utilice los defaults correctos para opciones nuevas.
- Para lanzar una **prerelease beta**, además de PHPUnit y el arranque nativo de la CI, probar Bedrock 1.26.40 RakNet y 1.26.60 NetherNet (inventarios, mundo existente LevelDB, chunks, persistencia, reinicio y reconexión). El acceso exitoso de un jugador en 1.26.60 no garantiza por sí solo la compatibilidad multiversión.
- La release debe incluir `RyzeMP.phar`, SHA-256 y `ryzemp-update.json` para que el Egg seleccione una release oficial, no un artefacto temporal de Actions. RyzeRTC y RyzeVanillaGen permanecen fuera del PHAR.
