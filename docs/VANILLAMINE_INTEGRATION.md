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
| Physical weather | Partially ported: precipitation by Bedrock biome, snow/ice, rain extinguishing, water cauldrons, cancellable lightning actor and rod attraction | Lightning-powered redstone, mob variants, fire/copper interactions and real-client validation pending |
| Vanilla terrain & structures | Not ported | Generator adapters, chunk formats, native Go helper and seed parity |
| Mob AI and natural spawning | Not ported | Entity registries, AI tick budgets, per-version actor data |
| Redstone | Not ported | World tick integration, block registry, network state translation |
| Villagers, raids, bosses | Not ported | Villager/trade states, raids, Dragon/Wither and Ryze dimensions |
| Combat, enchants, items | Not ported | Older-protocol item downgrade, transaction tests |
| Commands, world mechanics | Weather only | Integrate selectively with Ryze native command metadata |
| Full multiversion validation | Pending | Real Bedrock 1.21.x and 1.26.x mixed-version sessions |

### Scope and safety of this first slice

Implemented: per-world clear/rain/thunder state and vanilla-style independent weather timers; preserved Bedrock level.dat weather fields; operator `/weather` and `doweathercycle` gamerule; cancellable weather change event; synchronization on weather updates and world entry through each client's existing version-aware session; Nether/End report clear.

Now added in this branch: simulated rain/snow columns, freezing source water, cancellable snow and ice formation, rain-driven water cauldrons, rain extinguishing entities, cancellable natural lightning strikes, entity damage/fire, standard thunder sound, and lightning-rod target attraction. **Still incomplete:** redstone-powered lightning rods, ignition and copper oxidation effects, Endermen special behavior, skeleton-horse traps, complete sky-darkening parity, and physical weather effects beyond the supported block/entity classes.

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

**Still pending:** complete ray and lightning-rod physics, Endermen behaviour, wet farmland and hydration, unsupported powder snow cauldrons, tests with real Bedrock clients and live mixed-protocol sessions, staging-level LevelDB persistence and performance measurements. Existing PR #58 checks represent automated CI only; no real Bedrock client tests were claimed.

## Lightning integration and known technical boundaries

The native `minecraft:lightning_bolt` actor is short-lived and implements `NeverSavedWithChunkEntity`. Its spawn is preceded by the cancellable `LightningStrikeEvent`; the default entity hook applies 5 damage and up to 8 seconds of fire, while server/plugin event handlers can act on damage. Natural strikes occur only during Overworld storms, choose existing simulated chunks, and can be attracted to a surface lightning rod within 64 blocks. Target selection scans loaded chunks only. Natural attempts are randomized, and expensive rod scans are capped at **one per world tick**.

The actor has the vanilla thunder and explosion sounds and uses each player's own normal version-aware packet serializer. **Important limitation:** a rod currently attracts the strike but does **not** emit the vanilla redstone pulse: RyzeMP does not yet provide the `PoweredByRedstoneTrait` and redstone world systems from VanillaMine. Do not claim working redstone or full lightning visual/behavior parity. Copper-cleaning and fire ignition are also deferred pending native block interactions. `Enderman`, `SkeletonHorse` and powder-snow cauldrons are not fully implemented in RyzeMP; no fake entities or unregistered block states have been added.

## Performance and compatibility guarantees of this change

- Effects sample only already simulated/ticking, loaded chunks; no chunk is generated or loaded solely for weather.
- The world limits precipitation work to **32 samples per tick**, lightning attempts to **1 per tick**, and individual weather columns to a bounded vertical scan.
- The original native `World`, LevelDB storage, `DimensionManager`, RakNet, RyzeRTC, `NetworkSession`, `TypeConverter` and the two RyzeMCBE protocol/data dependencies have not been replaced.
- The test `WeatherProtocolCompatibilityTest` encodes rain and thunder event/sound packets with `PROTOCOL_1_21_50` and `CURRENT_PROTOCOL`. This is **serializer coverage**, not an actual client session or proof of correct visual behavior.
- The independent world state test checks isolation; existing tests cover pause/resume, NBT round-trip, and Nether/End's lack of weather. Other test gaps remain explicit below.

## Staging acceptance procedure (not performed in this integration session)

Run these steps on a disposable staging server using the exact PHAR from this PR, PHP 8.4 ZTS, a backup copy of an existing LevelDB world and copies of the existing dimension configuration. Capture server logs, packet errors, TPS and steps completed.

1. Join the Overworld from an actual Bedrock 1.21.x client. Run `/weather rain 600` and verify rain appearance, duration and that rejoining during rain restores the correct state.
2. Run `/weather thunder 600`, observe thunder audio and real lightning visibility/damage where safe to test. A rod should attract a nearby natural strike, but redstone power is **not** yet implemented. Record any missing lightning visuals.
3. Inspect desert, temperate, cold and high-altitude biomes. Verify that desert remains dry, snow accumulates correctly without building an unsupported block state, freezing occurs under the appropriate lighting and rain fills only normal water cauldrons.
4. Enter Nether and return; enter End and return. Neither non-Overworld dimension should receive weather. Verify world and dimension identifiers and inventory remain consistent.
5. Run `/gamerule doweathercycle false`, confirm timers do not advance. Re-enable it and verify they resume. Sleep in the Overworld and verify weather reset while respecting gamerules.
6. Save and restart the server while raining, then check that the same world recovers its rain/thunder levels and remaining timers. Confirm unmodified LevelDB worlds still load and save.
7. Repeat steps 1–6 with compatible current Bedrock 1.26.x and mixed sessions. Check that no old client disconnects, gets invalid actor packets, or suffers inventory/dimension corruption.
8. Use a test PocketMine-MP 5 plugin to cancel `WeatherChangeEvent`, `LightningStrikeEvent` and `BlockFormEvent` independently. Confirm no effects from cancelled events.
9. Capture 10-minute TPS/memory baselines without weather, then with thunderstorms in a representative simulation distance. Investigate discrepancies and test a server with several loaded worlds.

**Do not mark Phase 1 complete or merge this PR** until the unresolved gameplay features are implemented or explicitly scoped down and the real Bedrock/LevelDB tests have evidence. CI success alone is not equivalent to production compatibility.
