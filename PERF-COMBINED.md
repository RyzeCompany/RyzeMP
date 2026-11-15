# Combined performance branch (perf-combined)

`vanilla-content` 7d2c0b333 plus the local branches chunk-load-perf (simulation distance, native subchunk serializer
when chunkutils2 >= 0.3.8 is loaded), entity-tick-perf (mobs.movement-broadcast) and world-hop-perf (fires only
update in ticking chunks), with `chunk-ticking.simulation-distance: 4` by default (Bedrock's tick-distance). Details:
PERF-CHUNKLOAD.md, PERF-ENTITYTICK.md, PERF-WORLDHOP.md.

A/B, 100 StressBots, bots seed 1001, world seed 1001, `work/tools/php84` (chunkutils2 0.3.7: the PHP subchunk
serializer is used), runs started only with CPU load < 12%, A base / D combined interleaved, main-thread CPU ms per
tick:

| scenario | A base | D combined |
|---|---|---|
| stay (no hops, no exploring) | 41.2 (39.7 / 43.3 / 40.6) | **37.6** (38.0 / 36.3 / 38.6), -8.7% |
| standard (hops + exploring) | 74.8 (74.4 / 74.4 / 75.7), 12.5 realTPS | **71.3** (73.1 / 69.5), 13.2 realTPS, -4.7% |
| hop (hops, no exploring) | 49.6 (48.8 / 52.1 / 47.9) | 50.3 (52.4 / 49.1 / 49.4), no change |

The third standard run of D is left out: during it the whole server process got about half its usual CPU (main
thread 64 s instead of ~113 s over 120 s, workers 68 s instead of ~175 s), so something outside the server took the
machine. A first round of the same A/B overlapped with another session's PHPStan run and was discarded.

Tests: PHPStan 0 errors, PHPUnit 1280 tests OK (39 skipped).
