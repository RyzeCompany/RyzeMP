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
| Physical weather | Partially ported: biome-aware rain/snow, freezing, snow layers, fire extinguishing and water cauldrons | Lightning, rod power and remaining entity/block interactions need further work and validation |
| Vanilla terrain & structures | Not ported | Generator adapters, chunk formats, native Go helper and seed parity |
| Mob AI and natural spawning | Not ported | Entity registries, AI tick budgets, per-version actor data |
| Redstone | Not ported | World tick integration, block registry, network state translation |
| Villagers, raids, bosses | Not ported | Villager/trade states, raids, Dragon/Wither and Ryze dimensions |
| Combat, enchants, items | Not ported | Older-protocol item downgrade, transaction tests |
| Commands, world mechanics | Weather only | Integrate selectively with Ryze native command metadata |
| Full multiversion validation | Pending | Real Bedrock 1.21.x and 1.26.x mixed-version sessions |

### Scope and safety of this first slice

Implemented: per-world clear/rain/thunder state and vanilla-style independent weather timers; preserved Bedrock level.dat weather fields; operator `/weather` and `doweathercycle` gamerule; cancellable weather change event; synchronization on weather updates and world entry through each client's existing version-aware session; Nether/End report clear.

Not yet implemented: actual lightning strikes, rain effects on mobs and blocks, snow, frozen water, sound/sky-darkening parity, and weather-dependent combat behavior.

The changes are intentionally isolated from the RyzeMP network protocol registry, transport, dimension transition mechanics, world generator and plugin API version. Do not describe this as the complete VanillaMine weather feature until client and physical-weather tests pass.

## Validation checklist (pre-merge)

- [ ] Composer install and PHPUnit suite
- [ ] PHP syntax and static analysis of changed files
- [ ] PHAR startup and save/reload world NBT
- [ ] 1.21.x and 1.26.x clients: clear/rain/thunder, new join during weather, dimension roundtrip
- [ ] `/weather` syntax + autocomplete + permission test
- [ ] `/gamerule doweathercycle false` pauses both timers, persists, and resumes
- [ ] Nether/End do not receive weather effects
- [ ] PM5 plugin event cancellation
- [ ] Existing worlds remain unchanged unless explicitly saved

## Physical weather integration — incremental work, not yet verified on clients

The `vanilla-content` weather climate and precipitation types were adapted under LGPL-3.0-or-later. RyzeMP continues to read the existing Bedrock biome JSON and legacy biome mapping. No new numeric biome IDs or world formats are introduced; unmapped biome IDs do not invent precipitation. The engine selects one column at 1/16 chance only from chunks already eligible for world simulation, never loading a chunk for weather. Snow and freezing use the native cancellable `BlockFormEvent`; ordinary water cauldrons fill at a low probability; burning entities exposed to real rain are extinguished using `EntityExtinguishEvent`.

**Still pending:** complete ray and lightning-rod physics, Endermen behaviour, wet farmland and hydration, unsupported powder snow cauldrons, tests with real Bedrock clients and live mixed-protocol sessions, staging-level LevelDB persistence and performance measurements. Existing PR #58 checks represent automated CI only; no real Bedrock client tests were claimed.
