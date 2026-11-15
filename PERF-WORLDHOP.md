# Cost of world hops with 100 players (branch world-hop-perf)

Base: `vanilla-content` 7d2c0b333 (A runs use the frozen tree `work/wt-chunkload-base`, same code). 4 vCPU KVM VM,
PHP 8.4.16 + chunkutils2 0.3.7 (`work/tools/php84`, JIT tracing). 100 StressBots, bots seed 1001, world seed 1001,
`-NoTimings` unless noted, sequential runs, 120 s measured after 150 s of settling.

Scenarios (StressBots config):
* **hop**: world hops every 60-180 s into the overworld, nether or end (within 64 blocks of the world spawn), no
  exploration (`explore-*-seconds=0`). About 0.3 hops per second over the 100 bots.
* **stay**: no hops, no exploration: bots wander around the overworld spawn.

## Where the cost of the hop scenario comes from

Main-thread CPU ms per tick:

| scenario | with mobs | `--mobs.enabled=0` |
|---|---|---|
| stay | 41.8 (entity-tick-perf A runs) | 23.2 |
| hop | 50.6 (50.8 / 50.4) | 29.5 (28.4 / 30.2 / 30.0) |

* With mobs, hops add ~9 ms per tick, of which ~4 ms are mobs: players spread over three worlds keep three spawn
  areas loaded and ticked, each with its own mob population (entities 320 vs 225). That is vanilla behaviour.
* Without mobs, hops add ~6 ms. Timings of the two mobs-off scenarios (difference hop - stay, seconds over the
  measured 120 s, timings inflate ~2x on this VM):

| item | hop - stay |
|---|---|
| scheduled block updates (nether: fires, see below) | +10.1 |
| random chunk updates (more ticked area) | +4.5 |
| light population (workers) | +3.6 |
| chunk sending (cache hits, entity spawn packets) | +1.3 |
| chunk unload + save | +0.6 |
| chunk load from disk | +0.1 |

So the hop itself (unloading the old world's chunks, sending the new ones, spawning entities) costs little: about 2 s
of 120 s with timings. The cost is (1) a nether fire bug and (2) simulating three areas instead of one.

## Fix: fires only update in ticking chunks

A diagnostic counter of scheduled updates per block type (temporary, not committed) in the nether showed fire
blocks only, and growing: 5900 -> 37900 updates per 600 ticks within 5 minutes (queue 700 -> 2400 fires). Fires
reschedule themselves forever (on netherrack they never go out); a random tick of a nether fire starts the chain and
it then ran in every loaded chunk, also after the players had moved on. In vanilla (Java and Bedrock) block ticks
only run in ticking chunks (simulation distance).

`Block::pausesScheduledUpdatesOutsideTickingChunks()` (new, default false; `Fire` returns true): a due scheduled
update of such a block whose chunk is not ticked waits in `World::$pausedBlockUpdates` and runs on the next tick after
the chunk becomes ticking again (same moment as `validTickingChunks` gets the chunk). Dropped when the chunk unloads,
like every scheduled update (PM does not save them). With chunk ticking off (tick-radius 0, or set to 0 at runtime)
nothing waits. Fires inside ticking chunks are unchanged.

Test: `tests/phpunit/world/PausedBlockUpdatesTest.php` (fire of the ticked chunk keeps updating, the other waits,
resumes once its chunk is ticked, waits again when unticked; tick-radius 0 runs everything; unload drops waiting
updates). The test fails without the change.

A/B hop scenario, 3 runs each:

| | A base | B branch |
|---|---|---|
| mobs off: main CPU ms/tick | 29.5 (28.4 / 30.2 / 30.0) | **28.4** (28.6 / 28.8 / 27.9) |
| with mobs: main CPU ms/tick | 50.6 (50.8 / 50.4) | 50.2 (51.0 / 49.3) |

-3.7% without mobs; with mobs inside the noise in 2 minutes. The fire queue grows with uptime and with the nether
area the players have walked through, so the gain grows on long-running servers.

## Simulation distance 4 (branch chunk-load-perf, `--chunk-ticking.simulation-distance=4`, like BDS tick-distance)

| scenario | A base | C simulation-distance 4 |
|---|---|---|
| hop: main CPU ms/tick | 50.6 | **48.3** (46.8 / 48.8 / 49.4) |
| hop: realTPS | 18.6 | 19.1 |
| stay: main CPU ms/tick | 41.8 | **40.1** (41.0 / 39.2) |

About -4% in both scenarios with the world seed fixed (the earlier "no gain" of chunk-load-perf was measured on
random worlds). It freezes entities beyond 4 chunks of every player (Bedrock behaviour), so it stays opt-in.

## Behaviour differences

* Fires outside the chunk ticking radius of every player stop aging and spreading until a player comes close
  again (vanilla). Inside the radius: none.
* Global `mt_rand()` sequence: fewer fire updates consume fewer numbers (no determinism is guaranteed anyway).

## Next candidates (not done)

1. Native entity physics (`Entity::move()` and collision boxes). Checked with the timings of the hop scenario
   (with mobs, 120 s): "Entity Movement" 13.9 s of 120 s, of which the collision part (what a native port would
   replace) is only 3.9 s; much of the rest is the cost of the timers themselves (190k calls with nested timers).
   Expected gain ~3% of the main thread: not worth a native port on its own. The mob cost is spread over base
   ticks (23 s), AI and movement broadcast rather than concentrated in one hot loop.
2. Light population of chunks entering ticking areas runs in PHP in the workers (+3.6 s here).
3. Chunk save on unload is synchronous on the main thread (small: ~0.3% here).
