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
The new optional shared-port socket adapter already dispatches UDP before
RakLib's packet decoder and accepts ICE ufrags only from trusted main-thread
commands. It limits active peers, expires inactive relays, and never logs ICE
credentials. This adapter remains inactive until the native ICE backend is
configured and fully authenticated signaling is connected.

`src/network/mcpe/nethernet/NetherNetEndpoint.php` provides the shared
endpoint contract. `NetherNetFrameCodec.php` implements the official one-byte
fragment countdown; `NetherNetDatagramClassifier.php` recognizes STUN/DTLS
headers without confusing basic RakNet pings. `NetherNetPacketSender.php` and
`NetherNetSessionGateway.php` bridge previously authenticated clients to the
existing multiversion `NetworkSession` and enforce honest acknowledgment
semantics. `NetherNetSharedPortSocket.php` extends the owning RakLib socket
with **optional IPv4 ICE/DTLS relaying** to a local native ICE backend;
`NetherNetSharedPortSdp.php` rewrites signed backend ICE answer candidates
to the original public IP:port. Neither component initializes WebRTC or signs
client identities. The relay's loopback source IP must never substitute for
a player's verified signaling identity.

### Verified native dependency limitation (blocking)

The available `amber-pm/nethernet` library depends on native `ext-rtc`.
Its `udpMux` mode shares one UDP ICE socket among **multiple WebRTC peers**,
but does **not** share the socket with a foreign RakLib worker. RyzeMP
currently makes RakLib the exclusive owner of `server-ip:server-port/udp`.
Starting native `ext-rtc` on that very same address/port causes a bind
conflict, while using a different advertised UDP port violates this
migration's explicit same-address guarantee.

**Engine-style ingress is now implemented as a gated PHP relay, not as a
second public UDP bind:**

- `RakLibInterface::configureNetherNetSharedPort($privateIcePort)` must be
  invoked **before** the RakLib thread starts, only after a native WebRTC ICE
  mux binds `127.0.0.1:$privateIcePort`. The default path still instantiates
  the untouched `raklib\\server\\ServerSocket`.
- Once an **authenticated and signed** SDP answer is generated, a signaling
  adapter must call `NetherNetSharedPortSdp::rewriteAnswer()` using an
  externally reachable concrete IPv4 address and the existing server UDP
  port, then call `RakLibInterface::authorizeNetherNetUfrag()` before sending
  the HTTP response. It must call `revokeNetherNetUfrag()` on disconnect.
- RakLib's single bound socket accepts STUN/DTLS and forwards it through
  **one local connected UDP socket per peer** to the ICE mux. A native
  response travels back over the same RakLib public UDP socket. These relays
  do **not** send PROXY v2, because the present `ext-rtc` backend cannot
  consume that protocol. The authenticated external IP/port must instead
  remain associated with the transport session by the signaling adapter.
- Remaining blocker: `amber-pm/nethernet` `HttpSignaling` currently prepares
  HTTP answers internally before notifying its external listener, so SDP
  rewriting cannot be retrofitted in that callback. Extend or adapt this
  integration **before** enabling any live client and perform actual
  native-ICE + signaling + RakNet concurrent tests. Also verify
  `ext-rtc` handles loopback per-peer ICE tuples and DTLS correctly.

This relay and SDP rewrite are **staging components**, not a production
NetherNet listener or proof of official Minecraft-client connectivity.
A naive `SO_REUSEPORT` solution remains forbidden.

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
