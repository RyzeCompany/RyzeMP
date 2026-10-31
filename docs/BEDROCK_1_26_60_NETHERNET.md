# RyzeMP 1.26.60 / protocol 2225: authenticated NetherNet

## Current implementation

The feature branch now contains functional HTTP signaling and a first-party native transport adapter. It replaces the diagnostic-only receiver. A successful startup with RyzeRTC API 2 and fresh official authentication keys reports `NetherNet READY`; GET `/v1/join` returns actual server metadata and authenticated POST `/v1/join/{networkId}` returns a complete signed SDP answer. During initial key loading or a native failure, the server refuses new offers.

**Retail Minecraft login, world entry, movement, inventory, reconnect and public NAT behavior still require a real Minecraft Bedrock 1.26.60 client. Automated native peers are not that client. Terracotta is not declared fixed until that host test succeeds. Do not merge PR #48 without that test and the operator’s authorization.**

## Network and authentication

- The public TCP listener and RakLib’s public UDP socket use the existing `server-ip` and `server-port`. There are no additional administrator settings or public ICE ports.
- RakLib remains the only public UDP owner. Authorized STUN usernames route to an automatically allocated, private `127.0.0.1` RyzeRTC ICE mux. Native replies return through the original public socket.
- A relay observation is matched against RyzeRTC’s **selected remote ICE endpoint** before its real public endpoint is supplied to `NetherNetSessionGateway`. HTTP source ports and SDP candidate addresses are not treated as the player’s UDP endpoint.
- Official Minecraft HTTPS JWKS are fetched off the main tick, with TLS verification, no redirects, a 10-second timeout and a 256 KiB body limit. Refresh runs every 15 minutes; failures/unknown signer IDs are rate limited to one attempt per minute. Cached keys are rejected after six hours. No production test-authority or unauthenticated mode exists.
- Client JWT signatures, issuer, audience, subject, time claims and XUID are checked, followed by the detached ES384 signature over canonical DTLS fingerprints. Both SPKI `cpk` used by current Cloudburst/Engine and the documented public JWK representation are understood.
- Untrusted SDP cannot cause native probes to LAN candidates: candidate lines are removed before the private native peer sees the offer. Client-initiated ICE discovers the peer through the authorized UDP relay. DTLS verifies the exact signed fingerprint.
- The operator P-384 key is created once in `nethernet/operator-key.pem`, mode 0600. Keep it in backups. It survives restarts and egg reinstalls. A malformed existing key causes startup failure; the server does not silently regenerate it and reset the client’s TOFU trust.
- Every answer has a self-signed operator JWT and detached ES384 fingerprint JWS. Plain HTTP can show Minecraft’s first-use trust prompt. Confirm the intended server before accepting it.
- Minecraft sessions start only after ICE/DTLS/SCTP and both expected binary DataChannels open. The login’s public key **and authenticated XUID** must match signaling. The additional RakNet AES handshake is skipped on DTLS; RakNet behavior is preserved.

## Bounds and lifecycle

HTTP accepts at most 64 clients (8 per IP), 8 connections per tick and 4 offers per tick, with 8 KiB headers, 64 KiB SDP bodies and bounded reads/writes. Duplicate headers, transfer encoding, malformed lengths, text frames and unsupported channels fail closed. There are at most 32 pending signaling requests, 4 pending native connections per HTTP IP, 256 native connections and 8 candidate paths per ICE ufrag. Gathering/join, inactive peers and disconnected peers have separate deadlines. Duplicate live NetworkIDs return 409; closed IDs can reconnect.

RyzeRTC API 2 exposes channel metadata, the selected ICE endpoint and an explicit terminal overflow event. Native callbacks never call Zend; PHP polls copied events on its owning thread. Each peer has bounded inbound and outbound game queues. Reliable messages use NetherNet’s one-byte fragmentation countdown; unreliable input must have a zero countdown. A saturated event queue closes the affected peer instead of silently continuing after losing reliable game data.

SCTP delivery does not expose per-packet receipts through this API. No fake RakNet ACKs are generated. Resource-pack chunks use the reliable ordered channel and Minecraft’s next chunk requests; receipt diagnostics for dimension transitions apply to RakNet only. Plugins can call `NetworkSession::supportsTransportReceipts()`; receipt requests on an unsupported transport return a rejected promise.

## Reproducible tests

- RyzeRTC `native-linux.yml`: Debian 12 source compilation, PHP 8.4 ZTS ABI, exact Egg PHP, GDB/teardown and real native ICE/DTLS/SCTP binary loopback. API 2 is distributed separately in `v0.1.0-dev.3`; its ZIP SHA-256 is `9ecea6cc9d239b8c583d042c91df0448432321ceaf128850fe5b27bcde234602`.
- `php tests/nethernet-identity-verifier.php`: synthetic-authority positive tests and rejection of invalid signatures, issuer, audience, timestamps, keys and fingerprints; server signing and persistent identity. The synthetic authority is test code only.
- `php -n -d extension=/path/ryzertc.so tests/nethernet-native-integration.php`: real HTTP sockets, full-ICE signed offer/answer, native DTLS/SCTP through the real shared RakLib socket, both channels, fragmented 140 KB binary echo, concurrent peers, close, repeated-ID reconnect and simultaneous RakNet packets. Requires Composer dependencies and native libraries via `LD_LIBRARY_PATH`.
- `tests/nethernet-phar-smoke.py`: starts the actual built PHAR twice, obtains live official HTTPS JWKS, checks metadata and unsigned-offer rejection, receives a real RakNet discovery pong on the same TCP/UDP port, verifies operator-key persistence, then stops cleanly.
- `ci-2225-phar-preview.yml`: staged protocol/data, PHPUnit, native integration, PHAR creation, real startup/restart and absence of `.so` inside the PHAR. `native-phar-smoke.log` accompanies the artifact.

These tests validate transport and startup. They do **not** authenticate a retail Minecraft account or prove successful world gameplay.

## Pterodactyl host test

1. Import the egg from the existing feature PR and install its pinned official RyzeRTC release outside the PHAR. Back up the current PHAR and server data, then replace `RyzeMP.phar` with the new candidate. Reinstall preserves an existing PHAR, so reinstall alone will not upgrade it.
2. Keep the original allocation and existing server properties. Official Wings creates **both TCP and UDP** bindings for each allocation ([reviewed source](https://github.com/pterodactyl/wings/blob/747bc4393daf5d95fdf61d76382f5e23749cffe9/environment/allocations.go#L53)). The Egg integration CI also verifies the same TCP/UDP number with real container port bindings. The real host must retain both mappings. On the Wings host, inspect the server container’s `NetworkSettings.Ports`: the existing port must have both `/tcp` and `/udp` mappings. Allow both protocols in the host firewall. An egg cannot change the host’s Docker mappings or firewall.
3. Wait for `NetherNet READY`. From outside the host, request `http://YOUR_SERVER:YOUR_PORT/v1/join`; expect HTTP 200, `protocol: 2225` and `version: 1.26.60`. A valid externally reachable GET establishes TCP visibility; it does not establish UDP or login success.
4. Join using Minecraft Bedrock 1.26.60. The server logs progress without SDP or JWTs: authenticated offer, signed answer, verified ICE/DTLS/SCTP and Minecraft session start. Capture the first missing stage, client error screen and the complete relevant startup/join log.
5. Test world spawn, movement, blocks, inventory, optional resource-pack download, disconnect, immediate reconnect and one legacy RakNet client concurrently. Confirm only after all pass.

## Primary references

- [Mojang NetherNet HTTP Signaling Guide](https://mojang.github.io/bedrock-protocol-docs/guides/nether-net-onboarding-guide/)
- [Cloudburst Network NetherNet branch](https://github.com/CloudburstMC/Network/tree/nethernet), reviewed at `35cfff2` for identity, fragmentation and lifecycle behavior
- [Engine shared-port implementation](https://github.com/RyzeMCBE/Engine/tree/main/server/src/main/java/org/allaymc/server/network/nethernet)

The Egg installation, real PHAR startup, TCP/UDP mapping, unauthenticated-offer rejection, RakNet pong, clean shutdown and data-preserving reinstall passed in [Pterodactyl image CI 41157170619](https://github.com/RyzeCompany/egg-ryzemp/actions/runs/41157170619). This does not give access to the operator’s actual Wings node.

No Amber code or dependency is used. HardCoreFactions and the main branch are not modified.
