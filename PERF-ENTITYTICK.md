# Entity tick cost with 100 players (branch entity-tick-perf)

Base: `vanilla-content` 7d2c0b333 (same code as 2d3a6b669 + a CI change; A runs use the frozen tree
`work/wt-chunkload-base`). 4 vCPU KVM VM, PHP 8.4.16 + chunkutils2 0.3.7 (`work/tools/php84`, JIT tracing).

## Measurement fix first: every perf100 run used a different world

`archive/build-test/server-vanilla-play/server.properties` has `level-seed=` empty and perf100 copies it, so every
run generated a new random world (other terrain, biomes, mob counts from 170 to 450). "Seed 1001" only fixed the
StressBots decisions. `scripts/perf100.ps1` now writes `level-seed=1001` by default (`-LevelSeed ""` = random).
Earlier A/B results (including chunk-load-perf) carry that extra noise.

Two scenarios, 100 StressBots, bots seed 1001, world seed 1001, `-NoTimings`, sequential runs:
* **standard**: default bot config (world hops into nether/end every 60-180 s, exploration every 120-300 s).
* **stay** (`world-hop-*-seconds=0`, `explore-*-seconds=0`): bots wander around spawn. Steady state of a server.

## Where the main thread goes

Ablations (standard scenario, before the world seed fix, one run each, main-thread CPU ms per tick):

| run | main CPU | entities | workers CPU |
|---|---|---|---|
| base | 62-89 | 340 | 95-126 |
| `--chunk-ticking.blocks-per-subchunk-per-tick=0` (no block random ticks) | 64 | 445 | 104 |
| `--mobs.spawning.enabled=0` | 47 | 191 | 78 |
| `--mobs.enabled=0` (no mob AI, no spawning) | 31 | 123 | 50 |
| `--chunk-ticking.tick-radius=0` (no ticking chunks: no random ticks, spawning, light of ticking areas) | 23 | 153 | 51 |

* Block random ticks cost nothing measurable any more (native sampler).
* Mobs are about half of the main thread (AI, movement, and sending their movement to every viewer).
* World hops are the other half of the standard scenario: with bots staying at spawn, the base runs at 20 TPS with
  ~42 ms main CPU and the workers almost idle (4-7 ms vs ~110 ms per tick).

hrtime instrumentation of mob ticks (temporary, not committed; hrtime costs 7 us here, so only proportions count):
per mob, `updateMovement` (movement broadcast to every viewer) cost as much as the physics `move()`, about a third
of the mob's tick.

Timings of the stay scenario after the change (inflated ~2x by the slow VM clock): player ticks 22.5 ms/t (movement
handling and its broadcast 11.6), fish (cod, salmon, glow squid) ~15 ms/t for ~60 fish, other mobs a few ms each,
session compression 3.6 ms/t.

## Change: mobs.movement-broadcast

Same rule players already had (`player.movement-broadcast`): viewers within 24 blocks get every movement update of a
moving mob, within 48 blocks every 2nd tick, beyond every 4th tick (spread over ticks by viewer ID). The position
when the mob stops always goes to everyone, as do teleports and mobs with passengers. Only what distant clients
see changes (smoothness of far mobs), not what mobs do: no game logic, events, RNG or timing is affected.
`pocketmine.yml` `mobs.movement-broadcast.full-rate-distance: 24`, `half-rate-distance: 48`; `-1` = old behaviour.
Test: `tests/phpunit/entity/ai/MobMovementBroadcastTest.php` (40 ticks of movement: 40/20/10 updates for viewers at
5/35/100 blocks, final position to everyone exactly once, teleports to everyone, `-1` sends everything).

## A/B (world seed fixed, 3 runs each, interleaved A,B)

| scenario | A base | B branch |
|---|---|---|
| stay: main CPU ms/tick | 41.8 (40.6 / 42.3 / 42.5) | **38.4** (37.9 / 39.7 / 37.5) |
| stay: realTPS | 19.8-20.0 | 20.0 |
| standard: main CPU ms/tick | 71.5 (73.8 / 65.6 / 75.1) | 73.4 (72.8 / 72.4 / 75.1) |
| standard: realTPS | 13.1 (12.6 / 14.3 / 12.5) | 12.8 (12.9 / 13.0 / 12.5) |

* Stay: -8% main-thread CPU, outside the run-to-run spread.
* Standard: no measurable change; the bots are spread over three dimensions (fewer viewers per mob) and the world
  hops dominate and add noise.

## Behaviour differences

* Game: none.
* Network: distant clients get the movement of moving mobs every 2nd/4th tick instead of every tick (they
  interpolate; the final position is always sent). Configurable, `-1` restores the old stream.

## Next candidates (not done)

1. World hops / dimension changes: the largest remaining cost of the standard scenario (main thread and workers).
2. Light of chunks entering ticking areas runs in PHP in the workers (LightPopulationTask); with tick-radius 0 the
   workers' CPU halved. A native (chunkutils2) exact port would free CPU for the main thread on 4 vCPU machines.
3. Fish: ~60 fish cost about as much as all players' base ticks (3D swimming physics + broadcast every tick).
