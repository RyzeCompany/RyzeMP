# VanillaMine → RyzeMP: integration ledger

Source: https://gitlab.com/VanillaMine/VanillaMine
Source branch: `vanilla-content`, inspected at `4dd2228b47d64c8015c28138c846e6c4acb28345`.
Target: `RyzeCompany/RyzeMP`, base `aa6d91b8070eef31b756c8332acd6848760c5ee7` (beta.5 baseline).

## Non-negotiable RyzeMP boundaries

- Keep `pocketmine\` public API namespaces and the stable plugin API version.
- Keep `ryzemcbe/bedrock-protocol` and `ryzemcbe/bedrock-data` with per-session protocol conversion and the RyzeRTC/RakNet transport.
- Preserve the RyzeMP `DimensionManager`, its `dimensions.yml` semantics, its portal transitions and world storage identifiers.
- Never replace `src/Server.php`, `src/world/World.php`, `src/player/Player.php`, or `src/network/mcpe/NetworkSession.php` wholesale with VanillaMine files.
- New Bedrock content requires an explicit older-protocol rendering strategy: native, safe fallback, or unsupported for that client. Do not let unknown block or item IDs corrupt inventories.
- Preserve source provenance (PocketMine and VanillaMine, LGPL-3.0-or-later), and inspect licenses for separate native/data resources before porting them.
- Integrate in small PRs. Test rollback, existing worlds, PM5 plugins and mixed Bedrock versions before merge.

## Status

| Subsystem | Status | Next technical gate |
| --- | --- | --- |
| Weather cycle and per-world state | First slice in this branch | Verify real-client rain/thunder packets, command, save/restart and dimension switch |
| Physical weather | Expanded: biome precipitation, snow/ice, rain effects, powder-snow cauldrons, cancellable ray actor, fire/copper and powered lightning rods | Pending Enderman AI, real skeleton-horse trap, full redstone network and client-level gameplay verification |
| Vanilla terrain & structures | Not ported | Generator adapters, chunk formats, native Go helper and seed parity |
| Mob AI and natural spawning | Not ported | Entity registries, AI tick budgets, per-version actor data |
| Redstone | Narrow support: powered lightning rods, neighbor notifications and local strength query only | Full redstone propagation network, circuits and consumers remain outside this weather-only slice |
| Villagers, raids, bosses | Not ported | Villager/trade states, raids, Dragon/Wither and Ryze dimensions |
| Combat, enchants, items | Not ported | Older-protocol item downgrade, transaction tests |
| Commands, world mechanics | Weather only | Integrate selectively with Ryze native command metadata |
| Full multiversion validation | Pending | Real Bedrock 1.21.x and 1.26.x mixed-version sessions |

### Scope and safety of this first slice

Implemented: per-world clear/rain/thunder state and vanilla-style independent weather timers; preserved Bedrock level.dat weather fields; operator `/weather` and `doweathercycle` gamerule; cancellable weather change event; synchronization on weather updates and world entry through each client's existing version-aware session; Nether/End report clear.

Now added in this branch: simulated rain/snow columns, freezing source water, cancellable snow and ice formation, rain-driven water cauldrons, rain extinguishing entities, cancellable natural lightning strikes, entity damage/fire, standard thunder sound, and lightning-rod target attraction. **Still incomplete:** natural Enderman AI and rain reactions, skeleton-horse traps and their mount/riding dependencies, complete sky-darkening parity, broader redstone consumers, actual per-client visual validation and non-weather mob systems. Redstone rods, ignition, copper oxidation and powder-snow cauldrons have now been implemented at core level; final CI and staging evidence must still verify these changes.

The changes are intentionally isolated from the RyzeMP network protocol registry, transport, dimension transition mechanics, world generator and plugin API version. Do not describe this as the complete VanillaMine weather feature until client and physical-weather tests pass.

## Validation checklist (pre-merge)

- [x] Composer install and PHPUnit suite (verified in GitHub Actions; recheck at final head)
- [x] PHP syntax and targeted PHPStan level 9 on weather/integration files (GitHub Actions; recheck at final head)
- [ ] PHAR startup from final commit and real-world LevelDB save/reload (CI builds/boots PHAR; staging LevelDB still pending)
- [ ] 1.21.x and 1.26.x clients: clear/rain/thunder, new join during weather, dimension roundtrip
- [ ] `/weather` syntax + autocomplete + permission test
- [ ] `/gamerule doweathercycle false` pauses both timers, persists, and resumes
- [ ] Nether/End do not receive weather effects
- [ ] PM5 plugin event cancellation
- [ ] Existing worlds remain unchanged unless explicitly saved

## Physical weather integration — incremental work, not yet verified on clients

The `vanilla-content` weather climate and precipitation types were adapted under LGPL-3.0-or-later. RyzeMP continues to read the existing Bedrock biome JSON and legacy biome mapping. No new numeric biome IDs or world formats are introduced; unmapped biome IDs do not invent precipitation. The engine selects one column at 1/16 chance only from chunks already eligible for world simulation, never loading a chunk for weather. Snow and freezing use the native cancellable `BlockFormEvent`; ordinary water cauldrons fill at a low probability; burning entities exposed to real rain are extinguished using `EntityExtinguishEvent`.

**Still pending:** native Endermen behaviour, skeleton-horse mob/trap dependencies, wet farmland/hydration, actual Bedrock clients and mixed sessions, staging LevelDB persistence and TPS measurements. The physical ray/rod and powder-snow cauldron code is now present but not yet validated by final CI. Existing PR #58 checks represent automated CI only; no real Bedrock client tests were claimed.

## Lightning integration and known technical boundaries

The native `minecraft:lightning_bolt` actor is short-lived and implements `NeverSavedWithChunkEntity`. Its spawn is preceded by the cancellable `LightningStrikeEvent`; the default entity hook applies 5 damage and up to 8 seconds of fire, while server/plugin event handlers can act on damage. Natural strikes occur only during Overworld storms, choose existing simulated chunks, and can be attracted to a surface lightning rod within 64 blocks. Target selection scans loaded chunks only. Natural attempts are randomized, and expensive rod scans are capped at **one per world tick**.

The actor uses vanilla thunder/explosion sounds and existing per-session serialization. A rod now stores the Bedrock `powered_bit` state, emits 15 redstone power for 8 ticks (strong toward its attached face) and notifies neighboring blocks through the existing World block-update system. Its pulse restarts on repeated strikes. A minimal loaded-neighbor world query (`getRedstonePowerAt`) makes source strength accessible to consumers, **but does not implement full wire propagation or powered-device behavior**. Copper cleaning, bounded fire placement, difficulty and the persistent `dofiretick` gamerule have now been integrated with cancellable `BlockFormEvent`; gameplay validation remains pending. Enderman and SkeletonHorse are *not* spawned as incomplete actors: their VanillaMine implementations require absent mob AI/mounting infrastructure.

## Performance and compatibility guarantees of this change

- Effects sample only already simulated/ticking, loaded chunks; no chunk is generated or loaded solely for weather.
- The world limits precipitation work to **32 samples per tick**, lightning attempts to **1 per tick**, and individual weather columns to a bounded vertical scan.
- The original native `World`, LevelDB storage, `DimensionManager`, RakNet, RyzeRTC, `NetworkSession`, `TypeConverter` and the two RyzeMCBE protocol/data dependencies have not been replaced.
- The test `WeatherProtocolCompatibilityTest` encodes rain and thunder event/sound packets with `PROTOCOL_1_21_50` and `CURRENT_PROTOCOL`. This is **serializer coverage**, not an actual client session or proof of correct visual behavior.
- The independent world state test checks isolation; existing tests cover pause/resume, NBT round-trip, and Nether/End's lack of weather. Other test gaps remain explicit below.

## Staging acceptance procedure (not performed in this integration session)

Run these steps on a disposable staging server using the exact PHAR from this PR, PHP 8.4 ZTS, a backup copy of an existing LevelDB world and copies of the existing dimension configuration. Capture server logs, packet errors, TPS and steps completed.

1. Join the Overworld from an actual Bedrock 1.21.x client. Run `/weather rain 600` and verify rain appearance, duration and that rejoining during rain restores the correct state.
2. Run `/weather thunder 600`, observe thunder audio and real lightning visibility/damage where safe to test. Verify a rod enters its powered Bedrock state, exposes signal strength 15, and returns to zero after 8 ticks or after the renewed pulse. Test adjacent redstone consumers only when compatible consumers are installed, and record limitations. Record any missing lightning visuals.
3. Inspect desert, temperate, cold and high-altitude biomes. Verify that desert remains dry, snow accumulates correctly without building an unsupported block state, freezing occurs under the appropriate lighting and rain fills water cauldrons while snowfall fills powder-snow cauldrons with a distinct Bedrock state. Verify powder-snow buckets and state translation with an older client.
4. Enter Nether and return; enter End and return. Neither non-Overworld dimension should receive weather. Verify world and dimension identifiers and inventory remain consistent.
5. Run `/gamerule doweathercycle false`, confirm timers do not advance. Re-enable it and verify they resume. Sleep in the Overworld and verify weather reset while respecting gamerules.
6. Save and restart the server while raining, then check that the same world recovers its rain/thunder levels and remaining timers. Confirm unmodified LevelDB worlds still load and save.
7. Repeat steps 1–6 with compatible current Bedrock 1.26.x and mixed sessions. Check that no old client disconnects, gets invalid actor packets, or suffers inventory/dimension corruption.
8. Use a test PocketMine-MP 5 plugin to cancel `WeatherChangeEvent`, `LightningStrikeEvent` and `BlockFormEvent` independently. Confirm no effects from cancelled events.
9. Capture 10-minute TPS/memory baselines without weather, then with thunderstorms in a representative simulation distance. Investigate discrepancies and test a server with several loaded worlds.

**Do not mark Phase 1 complete or merge this PR** until the unresolved gameplay features are implemented or explicitly scoped down and the real Bedrock/LevelDB tests have evidence. CI success alone is not equivalent to production compatibility.

## Fase 1 finalización técnica: commits de pararrayos, rayos y calderos (11 Nov 2026)

**Estado:** implementación adicional publicada en `integration/vanillamine-weather`, **validación final pendiente**. Los cuatro workflows aprobados anteriores corresponden a `43808c5`, no prueban automáticamente los commits posteriores. GitHub Actions mostró los nuevos workflows en cola durante esta ejecución. No se realizaron pruebas con clientes Bedrock reales.

- Los pararrayos implementan `PoweredByRedstone`, `PoweredByRedstoneTrait` y `powered_bit` Bedrock. Dan potencia 15, pulso de 8 ticks, renovación de pulso y notificación de vecinos. `World::getRedstonePowerAt()` consulta fuentes adyacentes sin cargar chunks.
- El planificador usa plazos absolutos para evitar que un pulso renovado sea cancelado por su antiguo evento. Las pruebas incluyen señal, repetición y vencimiento.
- `LightningBolt` aplica daño y efectos sobre entidades, fuego según dificultad y `dofiretick`, desoxidación de cobre no encerado, y pulsos de pararrayos. Las formaciones de fuego y cambios de cobre pasan por `BlockFormEvent`; la creación del rayo puede cancelarse con `LightningStrikeEvent`.
- Se registró el bloque `PowderSnowCauldron`, el item `PowderSnowBucket` y los estados Bedrock `cauldron_liquid=powder_snow` con sus seis niveles. La lluvia llena calderos de agua, la nieve llena los de nieve en polvo, y se mantienen los sonidos nativos.
- La congelación de fuentes de agua verifica frío, luminosidad y orilla cargada. Se amplió PHPStan de nivel 9 sobre los archivos de integración, las conversiones y las pruebas.

### Matriz de compatibilidad por diccionario

| Versión presente en `BlockTranslator::PATHS` | Prueba en esta rama | Forma |
| --- | --- | --- |
| 1.20.0 | `WeatherBlockProtocolMatrixTest` | Codificación de pararrayos encendido y caldero con fallback seguro `info_update` si falta el estado |
| 1.21.50 | Matriz de bloques y paquetes clima/rayo | Diccionario real de la versión; evita enviar estados desconocidos |
| 1.26.40 | Matriz de bloques | Diccionario de la versión; comprueba `minecraft:cauldron` o fallback |
| `CURRENT_PROTOCOL` | Matriz de bloques y paquetes | Datos reales del protocolo actual |
| 2225 | Integración previa cross-repository; ruta de datos preparada | **No asumir que está habilitado para login**: el código del traductor lo marca como preparado |

**Importante:** las filas de la matriz representan pruebas **definidas en código**; no representan resultados aprobados mientras la CI del último HEAD siga en cola. Si el fallback no puede representar una mecánica, se prioriza un estado seguro sobre un ID Bedrock inválido.

### Dependencias no resueltas que impiden declarar 100%

- **Endermen:** VanillaMine `src/entity/monster/Enderman.php` depende de `Monster`, `Angerable`, múltiples goals/AI, sensores, teletransporte y red de monturas. La base RyzeMP no aporta estas clases; portar solo un actor de apariencia sin gameplay violaría el criterio de validez.
- **Trampas de caballo esqueleto:** `SkeletonHorse` depende de `AbstractHorse` (más de 1000 líneas), `TameableAnimal`, monturas/jinetes (`Jockeys`), inventario de caballo y `Skeleton`; aún faltan esos contratos. No se generaron spawns incorrectos ni monturas irreales.
- **Redstone general:** se ofrece una señal auténtica legible y un estado de bloque correcto, pero sin el grafo completo de consumidores/redstone de VanillaMine no está probada la propagación hasta circuitos de redstone ajenos a la mecánica del pararrayos.
- **Persistencia del pulso tras crash/reinicio:** el estado del bloque se serializa, pero el scheduler de ticks diferidos no es persistente; el estado debe volver a evaluarse al cargar/interactuar con el bloque. No está verificado el retorno exacto al estado no alimentado tras reinicio.
- **Validación real:** staging con varios clientes, inventarios, cancelación de eventos PM5, niveles de redstone y carga/reinicio LevelDB de mundos antiguos, TPS y la compatibilidad de todas las variantes necesitan evidencias independientes.

No hacer merge directo a `main`. Mantener PR #58 como **Draft**.
