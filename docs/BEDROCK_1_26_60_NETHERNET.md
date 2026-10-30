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
fragment countdown; `NetherNetDatagramClassifier.php` recognizes STUN/DTLS
headers without confusing basic RakNet pings. `NetherNetPacketSender.php` and
`NetherNetSessionGateway.php` bridge previously authenticated clients to the
existing multiversion `NetworkSession` and enforce honest acknowledgment
semantics. **None of these components owns a UDP socket or implements WebRTC.**

### Verified native dependency limitation (blocking)

The available `amber-pm/nethernet` library depends on native `ext-rtc`.
Its `udpMux` mode shares one UDP ICE socket among **multiple WebRTC peers**,
but does **not** share the socket with a foreign RakLib worker. RyzeMP
currently makes RakLib the exclusive owner of `server-ip:server-port/udp`.
Starting native `ext-rtc` on that very same address/port causes a bind
conflict, while using a different advertised UDP port violates this
migration's explicit same-address guarantee.

Do not enable `nethernet` or expose 2225 until one of the following is
implemented with tests:
- a native external-datagram injection/egress API in `ext-rtc` wired to
  RakLib's **single** socket owner, with separate authenticated session
  routing, or
- a verified in-front multiplexor that owns the *only* public UDP bind,
  translates ICE/DTLS and RakNet end-to-end, and preserves correct real
  client endpoint identity, query and ICE SDP candidates. A naive shared
  `SO_REUSEPORT` or localhost UDP redirect is not sufficient.

This blocker is architectural; unit tests for packet framing or the PHP
session gateway **cannot substitute for real-client connection tests**.

Deployment requires inbound **TCP and UDP on the same port**. Verify the
hosting panel and firewall expose both. iOS may require HTTPS signaling and
a trusted certificate. Persist the server identity across restarts.

## Outstanding runtime integrations

1. Complete 2225 codec changes in BedrockProtocol; packet IDs alone do not
   enable the protocol. Validate new and modified packets against official
   2225 schema and known wire fixtures.
2. **Implemented:** import Endstone's 23,960 ordered block hashes, canonical network NBT and matching metadata map; CI cross-repository data loading succeeds. Real client rendering remains unverified.
3. **Partially implemented:** 2,080 item runtime IDs and the 2225 required-item registry are imported, retaining previously validated component NBT; new item component serialization, recipes, creative inventory and biomes still need full validation.
4. **Implemented staging only:** 2225 selects paired block palette, FNV hashes, item dictionary and last known item-upgrade schema. The existing production Composer lock retains 2193; separate CI installs both 2225 feature dependencies. Do not enable 2225 in ACCEPTED_PROTOCOL yet.
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
