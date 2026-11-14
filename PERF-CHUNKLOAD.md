# Chunk load/generation pipeline and simulation distance (branch chunk-load-perf)

Base: `vanilla-content` 2d3a6b669 (frozen copy in `work/wt-chunkload-base`). Machine: the 4 vCPU KVM VM (slow QPC
clock: PocketMine timings are inflated ~2x, so only A/B CPU numbers are used for conclusions).

## 1. Profile before any change

### Where the main thread spends time in the chunk pipeline

Timings run, 100 StressBots, seed 1001, bots teleporting every 15-30 s (`explore-min/max-seconds=15/30`),
timings ms per tick (inflated ~2x, total "Full Server Tick" 54 ms/t):

| Main-thread part | ms/tick | avg per call |
|---|---|---|
| PopulationTask completion (deserialize up to 9 chunks + `generateChunkCallback`: setChunk x9, events, drain queue) | 1.04 | 6.1 ms |
| of which `generateChunkCallback` (Chunk Population - Completion) | 0.88 | 5.2 ms |
| Chunk Population - Order (loadChunk + serialize 9 chunks for the worker) | ~0.3 | 0.06-0.5 ms |
| ChunkRequestTask creation (Player Send Chunk Prepare: serialize terrain + tiles) | 0.09 | 0.26 ms |
| LightPopulationTask completion (unserialize light arrays, set them) | 0.01 | 0.15 ms |
| Chunk unload + save | 0.28 | 2 ms |
| Chunk load from disk | 0.07 | 1.2 ms |
| ChunkRequestTask completion (mostly spawning entities to the player: AddPlayer etc.) | 3.5 | |
| **Entity ticks** | **28** | |

Note: that first run had no `vanillagen` binary in the worktree (`native/vanillagen/bin` is gitignored) and used the
PHP terrain generator (PopulationTask 920 ms per chunk in the workers). Every later run copies the binary in.

Standard scenario (seed 1001, default bot config: world hops every 60-180 s, exploration every 120-300 s), native
generator, timings on: entity ticks 71 of 186 ms/t (fish alone ~22 ms/t), the chunk pipeline ~3-4 ms/t
(population completion 1.4, population order 0.7, chunk send prepare 1.0, light < 0.1).

Microbenchmarks on 300 real chunks of the generated vanilla world (JIT on, best of 5, per chunk, 24 subchunks):

| | PHP | native (chunkutils2 0.3.8) |
|---|---|---|
| FastChunkSerializer::serializeTerrain | 31.9 us | 13.6 us |
| FastChunkSerializer::deserializeTerrain | 61.7 us | 47.2 us |
| of which PalettedBlockArray construction/validation (chunkutils2, both paths) | 26 us | 26 us |
| setChunk() palette check | 10 us | (unchanged) |
| light result unserialize | 10-12 us | (unchanged) |

One population round on the main thread is ~9 serializations + up to 9 deserializations + 9 setChunk: ~1 ms with
the PHP code, ~0.65 ms native.

### Conclusions of the profile

* The main-thread part of loading/generation is small: 2-4% of the tick. "Exploration halves TPS" is not main-thread
  chunk code: the workers (PHP decoration, chunk encoding, light) plus the Go terrain process use more CPU than
  the main thread (perf100 now reports `otherThreadsCpuMsPerTick` and `childProcCpuMsPerTick`; see the tables
  below) and on 4 vCPUs they take CPU time from the main thread, whose wall time per tick is ~2x its CPU time.
* Light of new chunks already runs entirely in a worker (LightPopulationTask); the main thread only applies the
  result (< 0.1 ms/t). Moving it into PopulationTask would compute light for every generated chunk (today only
  chunks next to ticking chunks get it) and add worker load, which is the real bottleneck: not done.
* Entities are the main-thread cost, and many of them are far from every player: simulation distance pays more
  than anything in the chunk pipeline.

## 2. Simulation distance

Vanilla Bedrock: the dedicated server's `tick-distance` (default 4, range 4-12) / the world's "simulation
distance". Chunks within it around a player are ticked: entities, random ticks, redstone, fluids. Chunks only
within view distance stay loaded and are sent, but are frozen: entities don't move or act.

`pocketmine.yml`: `chunk-ticking.simulation-distance`, **default 4** (BDS default, `World::DEFAULT_SIMULATION_DISTANCE`;
0 = tick the entities of every loaded chunk). The branch first shipped it disabled because the A/B runs below showed
no gain, but those ran on a different random world every time. With the world seed fixed (PERF-WORLDHOP.md) 4 gave
-4% main-thread CPU in both the hop and stay scenarios, and the user chose (2026-10-16, branch perf-combined) to
enable it by default like Bedrock.

* Entities whose chunk is not within the simulation distance of a player (ChunkSelector ring < distance, the same
  rule as `tick-radius`) get `Entity::onFrozenUpdate()` instead of `onUpdate()`: they stay in the update list,
  loaded and visible, but don't move/act and their clock (`lastUpdate`) doesn't advance (next onUpdate sees a tick
  difference of 1: no ageing, no catch-up).
* Always ticked: players, entities carrying a player, chunks registered with `registerTickingChunk()` (plugins) or
  the new `registerSimulatingChunk()`. A new entity still gets its first update (spawn event).
* Frozen despawnable mobs keep the deterministic instant despawn rule of `MobDespawner` (distance only, no random
  despawn, no `mt_rand()`): otherwise mobs left behind by teleporting players would stay frozen and fill the
  world's global mob cap.
* Random ticks: when set, `tick-radius` is clamped to the simulation distance.
* `0` (default, also when the key is missing) = PocketMine-MP behaviour: every loaded entity ticks.
* API, additions only: `World::DEFAULT_SIMULATION_DISTANCE`, `BEDROCK_SIMULATION_DISTANCE`, `get/setSimulationDistance()`,
  `register/unregisterSimulatingChunk()`, `isChunkSimulated()`, `Entity::onFrozenUpdate()`.
* Test: `tests/phpunit/world/SimulationDistanceTest.php`.

## 3. Work moved off the PHP main-thread code

* chunkutils2 0.3.8 (`work/deps/ext-chunkutils2-chunkload`, branch `native-chunk-serializer`, 2d07f28):
  `pocketmine\world\format\io\FastSubChunkSerializer`, the subchunk section of FastChunkSerializer in C++. Same
  bytes; SubChunk's private properties are read from the property table (no calls into PHP, no `EG()`).
  `.phpt` test against a PHP reference.
* PocketMine: FastChunkSerializer uses it when the class exists, PHP code kept as fallback/reference.
  `FastChunkSerializerNativeTest`: identical bytes and identical decoded chunks (random chunks, every
  bits-per-block, multiple layers, pending tiles/entities, truncated data). Also checked on 300 real chunks: identical
  bytes and identical round trip.
* Used by every chunk sent to a worker (PopulationTask, LightPopulationTask, ChunkRequestTask) and every population
  result (in the workers too).

## 4. A/B (perf100, -NoTimings, seed 1001, sequential runs, 100 bots)

A = base tree + deployed PHP (chunkutils2 0.3.7). B = this branch + the same PHP with chunkutils2 0.3.8.
Each run waits until the machine is quiet (CPU load < 40%) before starting.

C = B with every non-main thread of the server and the vanillagen process set to BelowNormal OS priority
(experiment from outside, `perf100.ps1 -OtherThreadsPriority BelowNormal`, no code). Runs 2026-10-16 03:41-05:27,
order A,B,C interleaved, CPU load of the idle machine (live server only) 9-35% before each run.

Mean of 3 runs (realTPS range in brackets); CPU in ms per server tick:

| Scenario | | realTPS | main thread CPU | worker threads CPU | vanillagen CPU | entities |
|---|---|---|---|---|---|---|
| standard (world hops 60-180 s, exploration 120-300 s) | A base | 13.42 (12.95-14.29) | 68.9 | 110.0 | 27.1 | 334 |
| | B branch | 13.82 (12.70-15.00) | 67.1 | 104.3 | 27.9 | 364 |
| | C branch + low priority | 13.45 (12.36-14.46) | 69.8 | 101.0 | 28.8 | 390 |
| exploration (teleport every 15-30 s) | A base | 20.00 (20.00-20.00) | 35.9 | 78.3 | 21.8 | 270 |
| | B branch | 19.40 (18.95-20.00) | 43.7 | 78.5 | 25.3 | 299 |
| | C branch + low priority | 19.98 (19.97-19.98) | 36.8 | 73.4 | 23.1 | 261 |

Reading:
* No difference beyond the run-to-run noise (+-1 realTPS, +-7 ms main CPU between repetitions of the same
  configuration). Standard: B +3% realTPS / -3% main CPU, C equal to A. Exploration: all three at or near the
  20 TPS cap; B's higher main CPU there comes with B's single 19 TPS runs and is not seen in C (same code), so
  it is noise, not a regression from the code.
* With the native generator, exploration by itself no longer halves TPS at 100 bots: A runs at 20 TPS. The
  standard scenario (world hops into nether/end) is the heavy one, and there entities dominate.
* Simulation distance does not reduce the entity cost here because the fork already removes most entities beyond
  4 chunks: natural mobs despawn instantly beyond 44 blocks with tick radius <= 4, and Mob activation range
  (AiBudget) already skips AI of far mobs. Few entities are left to freeze.
* Lower priority for workers/vanillagen (C): no measurable gain on this machine when nothing else runs; not
  implemented in code.

## 5. Behaviour differences

With the default (`simulation-distance: 0`): **none** against the base branch.

Only when an admin sets a simulation distance (intended, as in Bedrock):
* Entities beyond the simulation distance are frozen (vanilla Bedrock does the same). Consequences: projectiles,
  items, falling blocks and mobs beyond 4 chunks stop until a player comes back; far mobs no longer despawn
  randomly (instant-distance despawn still applies), named/persistent mobs simply wait.
* The order of `mt_rand()` calls in a tick changes with which entities tick (expected with any change in the set
  of ticked entities).

Not changed (differences from vanilla that remain):
* Scheduled block updates (water/lava flow, redstone delays...) and neighbour updates still run in every loaded
  chunk, not only within the simulation distance as in vanilla.

Chunk serialization: none (byte-identical, tested).
