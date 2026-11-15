# RyzeMP VanillaMine base — integration and release gates

This branch is a **development candidate**, not a production-ready replacement for RyzeMP beta.5. The upstream VanillaMine gameplay code and attribution remain intact.

## Networking decision / known incompatibility

The current VanillaMine-derived runtime starts its **pm-nethernet** native helper for Bedrock 1.26.50+ and keeps RakLib available for legacy RakNet clients. The bridge can multiplex the game UDP port with RakNet and use the same numerical server port for TCP HTTP signaling. The Pterodactyl host must expose both TCP and UDP on the **same** public allocation. No additional public port is permitted.

**The existing official [RyzeMP egg](https://github.com/RyzeCompany/egg-ryzemp) instead installs [RyzeRTC](https://github.com/RyzeCompany/RyzeRTC) API 2 as an external native PHP module.** These are different transports with different startup and identity contracts. Do **not** start both transports on the same public port, assume a PM-NetherNet helper is equivalent to RyzeRTC, ship `ryzertc.so` inside `RyzeMP.phar`, or update the existing egg to this branch until a compatible transport integration has been tested.

For a production RyzeMP release, either (a) integrate RyzeRTC API 2 into this new base and repeat all authentication and shared-port tests, preserving the official egg, or (b) explicitly design, test and publish an approved new egg/runtime distribution for the pm-nethernet architecture. Neither option is currently approved. The CI candidate uses the upstream bridge solely for **testing**, with its pinned checksum.

## Automated test gates

- Locked public Composer dependencies (RyzeCompany protocol/data), PHP 8.4 and strict source syntax checks.
- Focused multiversion palette, block/tile NBT, entity equipment routing, pre-spawn and packet regressions.
- Full PHPUnit run; PHPStan and Go generator tests should pass with any warnings triaged.
- Build a named `RyzeMP.phar`, verify PHAR validity, startup/`--version`, no embedded `.so`, bridge SHA-256 and an uploadable test artifact.
- Boot a disposable test instance with headless smoke for both RakNet UDP and authenticated NetherNet TCP+UDP signaling. Verify failure modes and identity protections.

## Required real-client acceptance matrix

| Bedrock client | Expected transport | Manual checks |
| --- | --- | --- |
| 1.26.40 | RakNet | Connect, spawn, items/armor/inventory, block actors, movements, chunks, world transitions |
| 1.26.45 | RakNet | Same checks and command/available-command parsing |
| 1.26.52 | NetherNet | Real authenticated handshake, items, entities, inventory, chunks and reconnection |
| 1.26.60 | NetherNet | Same plus gameplay parity, resources, death/respawn and dimensions |
| Mixed clients | Both on the same server IP and port | Simultaneous equipment changes, entity metadata, blocks/tiles, crafting, skins and world swaps |

Also import a copy of an existing LevelDB world; check saving, restart, entity/container inventories and rollback restoration. Test representative PocketMine-MP 5 plugins, memory/TPS on a populated world, network reconnection, private operator identity and public port ownership. **No production merge or main/egg change without this evidence**.

## CI / artifact

The branch workflow [RyzeMP VanillaMine-base integration](https://github.com/RyzeCompany/RyzeMP/actions/workflows/ryzemp-integration.yml) builds a disposable PHAR with a checksummed Linux amd64 `pm-nethernet` bridge and Go `vanillagen`. It is not a signed, sanctioned production release. The normal RyzeMP egg continues consuming existing RyzeMP release manifests; this branch does not publish into that channel.

## Upstream attribution

VanillaMine: https://gitlab.com/VanillaMine/VanillaMine

PocketMine-MP: https://github.com/pmmp/PocketMine-MP

Keep copyright notices, LGPL obligations and native third-party attribution when packaging releases.
