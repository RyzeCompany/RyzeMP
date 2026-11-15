# RyzeMP VanillaMine base — integration and release gates

This branch is a **development candidate**, not a production-ready replacement for RyzeMP beta.5. The upstream VanillaMine gameplay code and attribution remain intact.

## Networking: RyzeRTC API 2 is the only NetherNet transport

RyzeMP no longer bundles, downloads or starts pm-nethernet. When
`enable-nethernet=on` (the default), the externally installed, official
`ryzertc.so` extension **with native API >=2 is required**. The server refuses
to start NetherNet if the module is missing or incompatible; it will never
quietly fall back to an obsolete helper. For a deliberate RakNet-only test,
`enable-nethernet=off` remains supported. The independent Egg installs
RyzeRTC separately and preserves the private operator identity under
`nethernet/operator-key.pem`.

RakLib continues to own the only public UDP socket, and forwards
authenticated STUN/DTLS via loopback to native ICE. HTTP and HTTPS signaling
both listen on TCP `server-port`, while RakNet and NetherNet share UDP
`server-port`, normally **19132**. No new public ICE port is configured.
RyzeMP validates Minecraft JWT/identity assertions before accepting a native
WebRTC game session, and the verified SDP/DTLS fingerprint must match the
login identity.

The native PHP signaling server detects TLS ClientHello without consuming
the bytes OpenSSL needs. If a PEM certificate and private key are configured
at `network.nethernet.https.certificate`/`private-key`, new TLS connections
use them on the *existing* TCP port; certificates renewed by an external
agent can be reloaded on a subsequent handshake. If neither is configured,
it sends a TLS handshake-failure alert so Android/Windows can try HTTP.
**iOS requires a publicly trusted HTTPS certificate.** Native RyzeRTC does
not implement automatic ACME challenge handling; that would need a separate
hostname validation strategy (often ports 80/443 or DNS-01). Do not claim
automatic certificate issuance based on this implementation.

RyzeRTC native DataChannels use bounded queues, close on overflow, and
avoid inventing SCTP ACK receipts. Advanced SCTP RTT/ACK reporting and real
Bedrock mobile reconnect/load tests remain outstanding. The transport is
still **experimental** and not production approved.


## Automated test gates

- Locked public Composer dependencies (RyzeCompany protocol/data), PHP 8.4 and strict source syntax checks.
- Focused multiversion palette, block/tile NBT, entity equipment routing, pre-spawn and packet regressions.
- Full PHPUnit run on stock PocketMine PHP (focused/regular gate), plus strict **zero-skipped** extended run with official October 2026 PHP 8.4 containing native chunkutils2 0.3.15, vanilla worldgen data, Bedrock sample fixtures and Go vanillagen; PHPStan warnings must be triaged.
- Build a named `RyzeMP.phar`, verify PHAR validity, startup/`--version`, no embedded `.so`, native Go SHA-256 and an uploadable test artifact.
- Boot a RakNet-only disposable world without the native extension and a separate full RyzeRTC API 2 native world; confirm one shared TCP/UDP port, no pm-nethernet process and authenticated NetherNet READY. Real retail-client and reconnect tests remain a separate gate.

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

The [integration CI](https://github.com/RyzeCompany/RyzeMP/actions/workflows/ryzemp-integration.yml) builds a disposable PHAR without Go or a bridge inside it, downloads the immutable SHA256-pinned RyzeVanillaGen v0.1.0 helper (protocol 7 / algorithm 4) from its own official release, then starts the PHAR **twice**: once with NetherNet explicitly disabled and once with a SHA-256-verified external RyzeRTC API 2 extension. The [extended coverage CI](https://github.com/RyzeCompany/RyzeMP/actions/workflows/ryzemp-extended-coverage.yml) exercises the formerly skipped native, vanilla-data and benchmark tests, including a full PHPUnit run that fails on any skipped test. Neither workflow releases RyzeMP to production; the Egg continues using existing official RyzeMP releases and downloads Go independently.

## Upstream attribution

VanillaMine: https://gitlab.com/VanillaMine/VanillaMine

PocketMine-MP: https://github.com/pmmp/PocketMine-MP

Keep copyright notices, LGPL obligations and native third-party attribution when packaging releases.

## Standalone RyzeVanillaGen distribution

The 75 Go source files and their golden terrain fixtures now live in [RyzeVanillaGen](https://github.com/RyzeCompany/RyzeVanillaGen). Native Go source is **not duplicated** in RyzeMP; the PHP IPC client and PHP terrain fallback remain here. Both the integration and strict extended test workflows download official `v0.1.0` from [its verified release](https://github.com/RyzeCompany/RyzeVanillaGen/releases/tag/v0.1.0), pinned to SHA-256 `3cddcb4be9bd17eaa3225be5d167fb63328566c252a8c4fe7b1e3b9e7d716dc7` on Linux amd64. The standalone release is independent of RyzeMP PR numbers and contains no Mojang-owned world data.

The Pterodactyl Egg independently installs `bin/ryzemp/vanillagen/vanillagen-linux-amd64` and verifies its version string and checksum before replacing the server. Existing RyzeMP beta.5 can ignore the binary; only a subsequent compatible RyzeMP release can enable native terrain stages. Never automatically upgrade to a generator with an incompatible protocol/algorithm.
