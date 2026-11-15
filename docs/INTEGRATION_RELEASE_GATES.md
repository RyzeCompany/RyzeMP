# RyzeMP VanillaMine base — integration and release gates

This branch is a **development candidate**, not a production-ready replacement for RyzeMP beta.5. The upstream VanillaMine gameplay code and attribution remain intact.

## Networking: authenticated native RyzeRTC API 2 with exclusive fallback

RyzeMP now has **two mutually exclusive** NetherNet implementations. If the official
`ryzertc.so` extension is loaded **outside** the PHAR with API 2, the default
`network.nethernet.transport: auto` chooses the native RyzeRTC adapter.
Otherwise it keeps VanillaMine's checksum-pinned `pm-nethernet` bridge. Use
`network.nethernet.transport: ryzertc` only to require the native runtime (and
fail startup if missing); `bridge` forces the fallback. This optional selection
does **not** introduce any additional public ports or separate settings for ICE.

The native mode reserves a loopback-only ICE backend, then configures RakLib's
**existing public UDP socket** for authenticated STUN/DTLS peer traffic. The
same configured `server-ip`/`server-port` is used for TCP HTTP signaling and
public UDP RakNet/NetherNet (typically 19132). The two transports cannot be
started together and RakLib must own UDP in native mode. All signed Minecraft
identity/ICE assertions, transport-key-to-login-key checks and SDP public-port
rewriting remain enabled. RyzeRTC does not belong inside `RyzeMP.phar`.

The [official RyzeMP egg](https://github.com/RyzeCompany/egg-ryzemp) supplies
the compatible native runtime separately; no Egg or production release was
altered by this integration. The native API 2 smoke test reached `NetherNet READY`
using the pinned RyzeRTC release and a real headless RyzeMP world on
[GitHub Actions](https://github.com/RyzeCompany/RyzeMP/actions/runs/43723527405).
**Authenticated retail-client login, sessions and mixed-version interoperability
remain unverified** and block production deployment.

## Automated test gates

- Locked public Composer dependencies (RyzeCompany protocol/data), PHP 8.4 and strict source syntax checks.
- Focused multiversion palette, block/tile NBT, entity equipment routing, pre-spawn and packet regressions.
- Full PHPUnit run on stock PocketMine PHP (focused/regular gate), plus strict **zero-skipped** extended run with official October 2026 PHP 8.4 containing native chunkutils2 0.3.15, vanilla worldgen data, Bedrock sample fixtures and Go vanillagen; PHPStan warnings must be triaged.
- Build a named `RyzeMP.phar`, verify PHAR validity, startup/`--version`, no embedded `.so`, bridge SHA-256 and an uploadable test artifact.
- Boot disposable test worlds with both pm-nethernet fallback and verified external RyzeRTC API 2 native module; confirm one shared public TCP/UDP port, no duplicate transport and authenticated NetherNet READY. Real retail-client and reconnect tests remain a separate gate.

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

The [integration CI](https://github.com/RyzeCompany/RyzeMP/actions/workflows/ryzemp-integration.yml) builds a disposable PHAR with the checksummed Linux amd64 pm-nethernet fallback and native Go vanillagen, then starts it **twice**: once with the default bridge and once with a SHA-256-verified external RyzeRTC API 2 extension. The [extended coverage CI](https://github.com/RyzeCompany/RyzeMP/actions/workflows/ryzemp-extended-coverage.yml) exercises the formerly skipped native, vanilla-data and benchmark tests, including a full PHPUnit run that fails on any skipped test. Neither workflow releases to production; the normal egg continues using existing official releases.

## Upstream attribution

VanillaMine: https://gitlab.com/VanillaMine/VanillaMine

PocketMine-MP: https://github.com/pmmp/PocketMine-MP

Keep copyright notices, LGPL obligations and native third-party attribution when packaging releases.
