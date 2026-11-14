# NetherNet (pm-nethernet)

Bedrock clients 1.26.50 and newer connect with **NetherNet** (WebRTC data channels negotiated over HTTP) and newer
clients drop RakNet. VanillaMine terminates NetherNet in `pm-nethernet`, a small helper program written in Go that
relays game data to the server over a loopback link (`src/network/mcpe/nethernet`). The server starts and supervises
it; server owners normally never run it by hand.

The program and the NetherNet library it is built on live in their own repository,
[VanillaPocketMine/nethernet](https://gitlab.com/VanillaPocketMine/nethernet) (`cmd/pm-nethernet`; how it works,
building, test client). This folder only receives its binaries:

- `bin/pm-nethernet-<os>-<arch>[.exe]` for windows/amd64, linux/amd64, linux/arm64, darwin/amd64 and darwin/arm64
  (gitignored). The CI (`build/ci/build-native.sh`) downloads the release pinned by `NETHERNET_VERSION` from that
  project's package registry and checks it against its `SHA256SUMS`; locally they are built from a checkout of the
  repository with its `scripts/build.ps1 -Out <this folder>\bin`.
- The release phar contains all five; on start the server copies the one for its platform to
  `<server folder>/bin/` (a program cannot run from inside a phar) and refreshes it when the phar changes. A phar
  built without them finds the helper in `<server folder>/bin/pm-nethernet-<os>-<arch>[.exe]` or at
  `network.nethernet.binary`.
- If the helper is missing or cannot start, the server keeps running with RakNet only (`enable-raknet`), binding the
  UDP port itself.

## Hosting checklist

| What | Port | Why |
|---|---|---|
| TCP `server-port` (19132) | always | NetherNet signaling: `GET /v1/join`, `POST /v1/join/{id}` (HTTP and HTTPS on the same port) |
| UDP `server-port` (19132) | always | NetherNet game data (WebRTC), RakNet and Query, all on one port |
| UDP `nethernet-udp-port` | only if set | NetherNet game data on a port of its own instead of `server-port` |
| TCP 443 **or** TCP 80 | only for automatic HTTPS | Let's Encrypt checks the name while a certificate is issued or renewed |

So a typical server needs **`server-port` open in TCP and UDP**. A client that gets an answer from
`GET /v1/join` uses NetherNet and never falls back to RakNet, so a blocked UDP port means a failed join.

Behind NAT (home routers, some cloud VMs) forward the same ports. The address the player typed is offered to the
client as a WebRTC candidate automatically; other public addresses can be added with
`network.nethernet.advertise-addresses`.

## HTTPS and iOS

Clients try `https://<address>:<port>` first.

- **Windows and Android** fall back to plain `http://` when the server rejects TLS, after a one-time trust prompt for
  the server's identity key (`nethernet_identity.pem`; back it up and share it across a network of servers).
  Without a certificate the server answers TLS like Bedrock Dedicated Server does (a `handshake_failure` alert), which
  is what makes the fallback reliable.
- **iOS never falls back**: it only connects over HTTPS with a certificate valid for the address the player typed
  (this is the same with BDS; see Mojang's BDS-23108 / BDS-23125). iOS players can only join servers with HTTPS.

Automatic certificates (`pocketmine.yml`):

```yaml
network:
  nethernet:
    https:
      acme-domains: "play.example.com"   # or the server's public IP address, or both, comma-separated
      acme-email: "admin@example.com"     # optional
```

- The name must resolve to the server, and players must join with that name (the certificate has to match what they
  typed). With an IP address, players joining by IP get a valid certificate too; Let's Encrypt IP certificates last
  about 6 days and are renewed automatically every few days.
- Let's Encrypt connects to **TCP 443 (tls-alpn-01) or TCP 80 (http-01)** of the server to check the name. The helper
  listens on them only while a certificate is being issued or renewed, so one of them must be open in the firewall
  and not used by another program (a web server on the same machine should then provide certificates through
  `https.certificate` instead). If your router forwards 443/80 to other local ports, set `acme-tls-port` /
  `acme-http-port`.
- Certificates and the ACME account are kept in `nethernet_certificates/` in the server folder.
- Existing certificates (certbot, a panel...) can be used instead with `https.certificate` and `https.private-key`
  (PEM files, reloaded automatically when they change).
- Until a certificate is available, HTTPS attempts are rejected as without one, so Windows and Android keep working.

