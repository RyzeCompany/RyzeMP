# Bedrock 1.26.60 / 1.26.61 (protocol 2225) upgrade

Status: **in progress; NOT supported by a release build yet**.

Do not switch `ProtocolInfo::CURRENT_PROTOCOL` or announce 2225 in
`ProtocolInfo::ACCEPTED_PROTOCOL` until the packet codecs, data files,
transport, and real-client tests have passed.

## Reuse

- Preserve `tests/plugins/DevTools`, `build/php` and
  `examples/plugins/ExamplePlugin` submodules and their pinned commits.
- Use the already-verified official BDS 1.26.60.6 Endstone Actions export:
  https://github.com/RyzeMCBE/endstone/actions/runs/40758269886
- Its manifest reports 23,960 block states, 2,080 items and 89 biomes.
- Reuse the published Composer dependency names `ryzemcbe/bedrock-protocol`
  and `ryzemcbe/bedrock-data`; update the lockfile only after both libraries
  have tested compatible tags.
- Preserve all previous protocol-specific block and item dictionaries.
- Preserve PM5 plugin API/namespaces, saved world data and the existing RakNet
  transport for legacy clients.

## Required network transport: NetherNet **and** RakNet, same IP and port

Players must continue using the current `server-ip` and `server-port`
from `server.properties`. No independent public NetherNet bind address or
random WebRTC UDP port should be introduced.

The intended network layout is:

| Listener | External bind | Responsibility |
| --- | --- | --- |
| NetherNet signaling | TCP `server-ip:server-port` | HTTP(S) signaling, identity and SDP/ICE negotiation |
| Shared game ingress | UDP `server-ip:server-port` | A **single owner** multiplexes ICE/STUN/DTLS and legacy RakNet/Query packets |
| Bedrock game sessions | existing `NetworkSession` | Login, encryption/auth, version-specific packets and plugins |

**Do not** create two independent UDP listeners with the same bind endpoint.
The existing `RakLibInterface` owns UDP today. A real demultiplexer must
be inserted *before* RakLib so both transports are delivered reliably.
The future helper must authenticate the local IPC channel, bound request
sizes and rates, clean up DTLS connections, and avoid storing temporary
session keys in logs.

`src/network/mcpe/nethernet/NetherNetEndpoint.php` provides the shared
endpoint contract. `NetherNetFrameCodec.php` implements the official one-byte
fragment countdown for reliable and unreliable WebRTC data channels, with
bounded reassembly and ordering checks. **Neither class is a WebRTC listener.**

Deployment requires inbound **TCP and UDP on the same port**. Verify the
hosting panel and firewall expose both. iOS may require HTTPS signaling and
a trusted certificate. Persist the server identity across restarts.

## Outstanding runtime integrations

1. Complete 2225 codec changes in BedrockProtocol; packet IDs alone do not
   enable the protocol. Validate new and modified packets against official
   2225 schema and known wire fixtures.
2. Convert the Endstone block palette to RyzeMP canonical-state format;
   rebuild the parallel metadata map and verify **ordering** against the
   23,960 signed network hashes. The Endstone gzip Big Endian NBT root is
   not a drop-in replacement for the current canonical NBT file.
3. Generate or obtain authoritative 2225 item registry and component data;
   verify recipe mappings, creative content and biome definitions.
4. Replace 2193-specific data routing in `BlockTranslator`,
   `ItemTypeDictionaryFromDataHelper` and `ItemTranslator` with 2225
   selections without changing the older versions.
5. Capture/validate Jigsaw 313 for 2225 if the login sequence requires it.
   The headless Endstone export had no player and no Jigsaw packet.
6. Implement and package tested WebRTC/NetherNet transport (signal endpoint,
   persistent identity, STUN/ICE/DTLS, same-port UDP demux, RakLib bridging,
   session lifecycle and backpressure). A config flag without a working
   transport is insufficient.
7. Validate actual joins on 26.60/26.61, mixed versions, player/NPC skins,
   movement, inventory, death/respawn, creative, PvP, and Nether/End.
8. Run Composer/CI/PHAR smoke tests before producing a beta release.

Separate pending PRs: legacy 419, 844 player/NPC visibility and native
redstone. Do not merge these automatically into the protocol upgrade.
