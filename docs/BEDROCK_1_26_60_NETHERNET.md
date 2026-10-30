# RyzeMP · Bedrock 1.26.60/1.26.61 (protocol 2225) and first-party RyzeRTC

> **Development branch, NOT production-ready.** The codec/data staging PHAR does **not** yet accept actual Minecraft Bedrock 1.26.60 players. Keep protocol 2225 out of the production `ACCEPTED_PROTOCOL` list and do not merge this branch while the native transport remains incomplete.

## Repository ownership and compatibility

- **RyzeMP:** `RyzeCompany/RyzeMP`, branch `feature/bedrock-1.26.60-nethernet`, draft PR #48.
- **First-party native extension:** `RyzeCompany/RyzeRTC`, branch `feature/native-bootstrap-php84-linux`, draft PR #1.
- **Protocol and data:** `RyzeCompany/BedrockProtocol` and `RyzeCompany/BedrockData` feature branches for protocol 2225.
- Preserve the older Bedrock protocol translators, PM5 plugin APIs, existing worlds, RakNet and committed production `composer.lock`. Do **not** modify HardCoreFactions or pinned git submodules.
- No external PHP WebRTC extension or third-party signaling package is installed by the *current staging workflow*. Previous experiments are not a supported RyzeRTC distribution.

## Network contract (must not change)

- TCP signaling uses **the same** `server-ip` and `server-port` values already in `server.properties`.
- UDP media and legacy RakNet both appear on the existing `server-ip:server-port`; the server needs **one** UDP socket owner with datagram demultiplexing before RakLib.
- Internal ICE sockets may use temporary **loopback-only** ports. Never advertise a loopback port to players or require a new operator port setting.
- **No** `nethernet-enabled`, `nethernet-ice-port` or other additional NetherNet properties.
- The host/egg must expose **TCP and UDP on the same game port**. `0.0.0.0` is a bind address, not a player-facing endpoint; discovering/validating the advertised IP must not trust unverified user input.

The existing pure-PHP staging pieces include `NetherNetEndpoint`, `NetherNetFrameCodec`, `NetherNetDatagramClassifier`, `NetherNetSharedPortSocket`, `NetherNetSharedPortSdp`, `NetherNetSharedPortSignalingBridge` and `NetherNetSessionGateway`. They are **not** a complete native WebRTC service. In particular, shared UDP relay authorization must be bound to an authenticated signaling exchange, then revoked on close or expiry. Do not route unauthenticated DTLS packets as trusted clients.

## What is verified

1. Official Endstone BDS export from [RyzeMCBE/endstone Actions](https://github.com/RyzeMCBE/endstone/actions/runs/40758269886): 23,960 ordered block states and 2,080 items (plus 89 biomes) imported for 2225. Earlier dictionaries remain intact.
2. Staged data and protocol Composer path dependencies pass the cross-repository integration workflow. The production lockfile intentionally still uses the released, previously supported libraries.
3. RyzeMP regression tests and native dimensions pass independent PHP tests. The normal PHAR startup previously failed because its locked protocol library lacked a `PROTOCOL_1_26_60` constant; this was corrected by comparing numeric protocol 2225 without dereferencing a non-existent symbol.
4. A **staging-only** PHAR can build from the 2225 feature branches and emit a clear warning when a first-party WebRTC transport is unavailable. It is **not** a real-client test build.
5. RyzeRTC's first native bootstrap compiled and loaded under PHP 8.4 ZTS, including the exact PocketMine PHP binary used by the existing egg ([passing native CI](https://github.com/RyzeCompany/RyzeRTC/actions/runs/41145632552)). Its current API supports peer creation, bounded offer/SDP operations, remote ICE candidate validation and teardown; new tests are tracked in the RyzeRTC PR.

## Native runtime and Egg

- RyzeRTC builds `ryzertc.so` independently of `RyzeMP.phar`; it is never included in the PHAR.
- The development workflow currently publishes only **temporary experimental Actions artifacts**, not a permanent production release.
- Once the backend is feature-complete and ABI/runtime tests pass, publish a **versioned, checksummed release in RyzeCompany/RyzeRTC**. Only then update the Pterodactyl Egg to download that official release and its shared library dependencies to a private persistent directory. Pin integrity hashes; fail closed if the binary is incompatible or absent.
- Do not deploy the earlier external RTC release or confuse that binary with `ryzertc.so`. The two are not interchangeable.

## Blocking implementation and verification work

1. **Native RyzeRTC:** bounded async event queue, reliable and unreliable SCTP DataChannels, incoming channel notifications, backpressure and safe multithreaded cleanup. Validate native ICE/DTLS and fingerprint binding, especially multiple peers sharing a private ICE mux.
2. **Own signaling:** trusted Minecraft identity token verification, server signing key persisted across restarts, fingerprint verification, SDP/ICE candidate exchange and HTTP protocol on TCP `server-port`. Associate the transport identity with the later Bedrock login public key.
3. **Shared UDP integration:** put the native backend behind the existing RakLib owning socket without a second public bind. Confirm STUN/DTLS/RakNet demultiplexing, per-peer authorization, relay retirement, response source IP/port and NAT address handling.
4. **Protocol 2225:** validate packet schemas/serialization beyond IDs, item component NBT, recipes, creative inventory, biomes, commands, entities, interactions and Jigsaw packet 313 against real-client captures. The headless Endstone fixture could not capture packet 313.
5. **Gameplay integration:** test real Minecraft 1.26.60/1.26.61 join, login, chunks, inventories, movement, skins, PvP, Nether/End and reconnects concurrently with a prior RakNet version.
6. **Distribution:** pass Composer, reproducible PHAR, Linux/PHP ABI, first-party RTC binary load, full network integration, authentication-negative tests and host TCP+UDP smoke tests. Only then tag and publish a beta.

The HCF command API CI job currently fails at an external Git checkout with HTTP **403**. It is an authorization issue in the existing CI credentials, not evidence of a protocol regression. **Do not modify HardCoreFactions to hide this failure.**

## Staging CI

`.github/workflows/ci-2225-phar-preview.yml` creates a PHAR with staged 2225 protocol/data libraries and **no external RTC transport**, expressly for data and headless startup testing. It must not be described as full Minecraft 1.26.60 access.

The **normal released RyzeMP PHAR remains on the previously supported protocol and RakNet** until the preceding blockers are resolved.
