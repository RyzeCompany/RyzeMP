# Pterodactyl egg

`egg-vanillamine.json` installs VanillaMine on a Pterodactyl (or Pelican) panel: Admin > Nests > Import Egg.

- Install: downloads `PocketMine-MP.phar` from updates.vanillamine.com/download (redirected to the VanillaMine
  package registry; `VANILLAMINE_VERSION`, default
  `latest`) and the PHP 8.4 binaries from PHP-Binaries (`PHP_MARCH`: `x86-64-v3`, or `x86-64-v2` for CPUs without
  AVX2), and fixes `extension_dir` in `php.ini` for `/home/container`. Reinstalling updates both and keeps worlds,
  plugins and configs.
- Start: `./bin/php7/bin/php ./PocketMine-MP.phar --no-wizard --disable-ansi`; the panel sets `server-ip` and
  `server-port` (the primary allocation) in `server.properties` on every start. NetherNet uses that port in TCP
  (signaling) and UDP (data), RakNet the same UDP port: Pterodactyl allocations open both.
- Behind VanillaGate (the proxy; its egg is in its own repository): set `network.proxy` in `pocketmine.yml` (see its README); the backend port then only needs
  to be reachable from the proxy.
- The server unpacks the phar (~25 MB) into a temporary directory. The panel's `/tmp` is a tmpfs (RAM, counted as the
  server's memory, 100 MB by default), so the phar puts its temporary files in `.tmp` next to it instead (only when
  `/tmp` is a tmpfs and neither `TMPDIR` nor `sys_temp_dir` is set). The startup command is the plain PocketMine one; the egg also sets
  `TMPDIR=/home/container/.tmp` as a hidden variable (the panel exports it to the server), which older phars need.
  If `.tmp` is missing (deleted in the file manager, left out of a restored backup), the phar creates it again on start;
  phars older than 5.46.2 (an old `VANILLAMINE_VERSION`) do not: create the folder by hand or reinstall.
- `PHP_JIT` (default `tracing`): the install script writes `opcache.jit` into `php.ini` (PHP-Binaries ships it `off`);
  the JIT saves ~5-30% of the server's PHP CPU. Set it to `off` and reinstall if PHP crashes with the JIT. Existing
  servers get it on their next reinstall (worlds, plugins and configs are kept).
- x86_64 nodes only (no ARM PHP builds yet).

## Memory
The panel's "Memory" is what Wings reads from Docker: the container's cgroup usage minus its inactive file cache
(`memory.current - inactive_file` on cgroup v2), so it includes the PHP process with all its threads, the native
helpers (vanillagen, pm-nethernet), the touched part of opcache's shared memory and any file in a tmpfs, plus the
recently used file cache.

What uses it (5.46.2, vanilla generator, measured with the default settings):
- main thread ~190 MB, plus ~45 MB per player with view distance 12;
- 2 async workers generate vanilla terrain (`chunk-generation.max-workers`), ~135 MB each (~165 MB with players
  around); the other workers ~30-80 MB. `async-workers: auto` uses the CPU limit (or the pinned threads) of the
  server minus 2, at least 2 and at most 4 (before 5.46.2: every core of the node, minus 2, all generating); idle
  workers stop after 5 minutes (`memory.async-worker-idle-timeout`);
- vanillagen 50-250 MB while generating, given back after 30 s without work; pm-nethernet ~15-50 MB;
- the first start only: Java (Mojang's data generator, ~0.5-0.6 GB for ~35 s).

Recommended memory: 1.5 GB minimum, 2 GB for 10-20 players or with Nether and End generated (`dimensions.enabled`);
add ~50 MB per extra player. Setting a CPU limit (e.g. 200-400 %) also limits the number of workers. Keep
`memory.async-worker-hard-limit` at 384 for vanilla worlds.

Edit `install.sh` and regenerate the egg with `php build/pterodactyl/make-egg.php`.

The `latest` URLs are filled by the release pipelines: VanillaMine uploads the phar of every stable tag to
`packages/generic/PocketMine-MP/latest`, PHP-Binaries every tagged build to `packages/generic/PHP-Binaries/latest`.
