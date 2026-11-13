# RyzeMP — Mob AI / Natural Spawning (Phase 3, draft)

Tracking: [issue #59](https://github.com/RyzeCompany/RyzeMP/issues/59). Source architecture: VanillaMine vanilla-content at commit 4dd2228b47d64c8015c28138c846e6c4acb28345 (LGPL-3.0-or-later). Goal scheduler, FairTickBudget, and spawn lifecycle events retain upstream license. Other components here are RyzeMP adapters.

## Implemented so far in the draft PR

- Server-side Mob goal runtime and fair per-server update/path-search quotas.
- A bounded ground A* pathfinder limited to populated, loaded terrain, never initiating chunk generation, rejecting water/lava/fire/cactus/magma/berry hazards, plus an abort-on-stuck path follower.
- Farm-animal age NBT (`Age` plus legacy `IsBaby` compatibility), baby growth, 4-species breeding food, PM5 pre-breed cancellation, child persistence and breeder cooldown.
- Zombie pursuit/melee of survival players, zombie/skeleton daylight burning with rain, water and helmet protection; skeleton ranged AI with native arrow projectiles, clear-line-of-sight targeting, difficulty cooldown and cancellable launch events; creeper fuse/explosions with cancellable pre-explode event and persisted `mobgriefing` gamerule; passive wandering for villagers, chickens, cows, pigs, sheep, and existing squid swimming.
- PM5 EntityFactory registration/persisted NBT for four additional animals, Skeleton and Creeper, and built-in block spawner factories. Existing Zombie, Villager, Squid save IDs retained.
- Natural Overworld population of animals/zombies/skeletons/creepers/squid with light, biome-aware farm-animal/squid habitat filters, surface/underground, minimum/maximum player distance, global/category and 9x9 nearby chunk density caps and rate checks; two spawn events allow plugins to cancel before/after entity creation. No automatic edits to saved worlds.
- Optional natural despawn; *naturally spawned* mobs are transient and do not persist with chunks, while bred and manually created/plugin mobs preserve their normal LevelDB persistence.
- Per-world \`/gamerule domobspawning\` and \`/gamerule mobgriefing\` saved in gamerules.yml and synchronized over the existing multiversion session protocol.

The initial version must not be described as complete Bedrock or VanillaMine mob parity. It does not include most other hostile/passive mobs, advanced breeding variants, tame/ride mechanics, all path block danger classes, Nether/End spawn rules, structure-specific spawn tables, herd density, independent category caps by 9x9 chunks, the entire Mob AI behavior tree or Trial Chamber Breeze waves. Those remain release blockers for the Phase 3 checklist.

## Configuration

Under \`pocketmine.yml\`:

- `mobs.enabled`: enable AI and natural spawning. Stock new `pocketmine.yml` has `true`; older configurations missing this key default to `false` to avoid unexpected activation. Set it explicitly to opt in.
- \`mobs.max-ai-updates-per-tick\`: default 120 (shared across worlds).
- \`mobs.max-path-searches-per-tick\`: default 8.
- \`mobs.max-path-nodes\`: default 160.
- \`mobs.spawning.enabled\`: default true.
- \`mobs.spawning.interval-ticks\`: default 20.
- \`mobs.spawning.max-attempts\`: default 6.
- \`mobs.spawning.max-total\`: default 72; per-category defaults monsters 40, animals 24, aquatic 12.

Do not turn this on for HCF production without checking plugin assumptions. New Mob AI is isolated from protocol 2225, RyzeRTC, RakNet, NetherNet, LevelDB and the native Go world generator.

## Acceptance not yet performed

- The current commit's PHPUnit regression suite, PHPStan 9, Bedrock 2225 integration and PHAR build/headless boot have been exercised in GitHub Actions. They do not replace actual Bedrock clients. Still pending: real clients 1.26.40/1.26.60 in mixed sessions and real-world interaction regression.
- Two real players moving across chunk boundaries; no chunk requests originating from AI; no duplication after restart or unload; no false attacks on creative/spectator/NPC.
- Mobs in daytime and underground darkness, spawn caps with 100+ players; PM5 plugins intercepting natural spawns; gamerule persists.
- CPU p50/p95 with 100–200 mobs and mixed version clients, memory and TPS; comparison against baseline with mobs off.
- Full parity with VanillaMine's population, biome/environment rules and additional species.

Keep the PR draft and keep \`main\` unchanged until reviewed. Existing entities/worlds must stay compatible.
