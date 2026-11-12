# RyzeMP Phase 2 — Worldgen Vanilla (work in progress)

**Baseline:** RyzeMP `main` @ `aa6d91b8070eef31b756c8332acd6848760c5ee7`; VanillaMine `vanilla-content` @ `4dd2228b47d64c8015c28138c846e6c4acb28345` (audited 2026-11-11). Scope tracked by [#59](https://github.com/RyzeCompany/RyzeMP/issues/59). Independent from weather [#58](https://github.com/RyzeCompany/RyzeMP/pull/58).

## Architecture and audit

- RyzeMP registers `normal`, `flat`, `nether`, and dimension-specific `vanilla_nether` / `vanilla_end`. `DimensionManager` owns `dimensions.yml`, LevelDB source dimension mapping, portals and world preparation. Preserve all of these.
- VanillaMine `VanillaGenerator` is a large pipeline of multi-noise climate biome selection, density/material surfaces, carvers, feature/structure managers and worldgen data. The Nether and End subclasses select different dimension settings. Its data is generated from Mojang Java Edition data, which must be assessed separately for Bedrock parity.
- `native/vanillagen` is a Go **1.24** helper (native wire protocol version 7, algorithm version 4 at the audited ref). It exposes framed loopback requests to worker connections. PHP `NativeTerrainFiller` can select compiled PHP fallback, **but that code and its dependencies have NOT been integrated into RyzeMP**. No native helper, compiled backend, downloader or functional fallback is claimed for RyzeMP.
- RyzeMP's multiversion network and block conversion depend on RyzeCompany `BedrockProtocol` / `BedrockData`; VanillaMine's Composer setup uses the PocketMine-MP-Reborn packages instead. These dependency graphs must not be combined blindly. `RyzeRTC`, RakNet, `WorldManager`, public PM5 APIs and chunk serialization remain unchanged here.
- Both repositories use LGPL-3.0-family licensing at their audited commits. Future code/data/binary imports require per-component licensing checks and source attribution.

## Stage B: opt-in experimental generator

`ryze_vanilla_overworld` is a **new generator**, not an alias for the legacy `normal` terrain. It implements deterministic, continuous 2D terrain sampling at absolute coordinates, coarse temperature/humidity and land/river/ocean biome selection, bedrock/stone/topsoil/water fill, 24-subchunk height coverage where supported, and uses RyzeMP's existing `BiomeFiller`, `Chunk`, async worker and LevelDB provider path.

It is **not** Vanilla-equivalent and is **not production-ready**. The 2D biome/height algorithm is a provisional RyzeMP baseline, **not copied from VanillaMine**; it allows safe worker/chunk/biome testing while adapters are designed. It currently has **no** caves, aquifers, mineral distribution, vegetation, structures, native acceleration or compatible multi-noise routing. World-to-world topology and structures do not match Mojang or VanillaMine seeds. No claim of visual validation or performance benchmark is made.

### Safe opt-in

For a fresh **test world only**, select `ryze_vanilla_overworld` through the existing generator configuration. For the default world, set `level-type: ryze_vanilla_overworld` in `server.properties` **before first creation** (property name should be confirmed against the target runtime's documented config). Do not change settings of existing worlds or rename world directories. Existing worlds retain their stored generator and their chunks; the normal, Nether and End generators remain registered. Explicit nonempty presets fail validation rather than silently changing algorithm. Do **not** use this experimental generator for a permanent world: profiles and future structures may change.

### Preservation and rollback

1. Stop the server and take a **consistent, offline backup** of the entire `worlds/` tree (including `level.dat`, `db/` and dimension metadata) and the server configuration. Never copy a live LevelDB database as if it were a consistent backup.
2. Run the experimental generator only on a **newly named world**, with its own storage directory. Do not set it as the generator of an existing world. Worldgen happens only on missing chunks; populated chunks are additionally guarded against direct regeneration calls.
3. To roll back, stop the server, restore the original configuration and build, and **exclude the experimental world** from startup; preserve its data in a backup for inspection. Avoid trying to open its ungenerated areas with a different generator. Never rewrite or migrate existing LevelDB chunks automatically.
4. Test seams between old and new chunks only on copies; mixed algorithm borders are not guaranteed smooth. Freeze generator algorithm version before production and track the choice in world metadata before migration is considered.

## Validation matrix and blockers

| Test / requirement | Status |
|---|---|
| Unique generator name, seed determinism, local biome/height bounds | PHPUnit tests added; CI confirmation pending |
| Chunk creation, negative-height bedrock, biomes in subchunks | PHPUnit tests added; CI confirmation pending |
| Terrain serialization round-trip | PHPUnit test added; **not** a full LevelDB persistence test |
| Protection of populated chunk in direct invocation | PHPUnit test added; needs world lifecycle integration tests |
| LevelDB chunk save/reopen, metadata, blocks, biomes and NBT | PHPUnit integration test added; CI confirmation pending |
| Full server restart and existing legacy-world integrity | Not tested; required |
| Bedrock clients old/new and biome downgrade | Not tested; required |
| Real game visual validation / TPS / memory / PHAR startup | Not tested; required |
| Nether / End integration | Existing RyzeMP generators preserved; VanillaMine port not started |
| Vanilla multi-noise terrain/caves/aquifers/features/structures | Not implemented |
| Native Go helper build/platform checks / fallback | Not implemented |
| PHPStan level 9, isolated Worldgen scope | Dedicated CI job added; results must be checked on final HEAD |

**Follow-on sequence:** port data loaders and state mappings with tests; implement multi-noise terrain/carvers in isolated generators; evaluate native helper protocol with version/seed/chunk fixtures and bounded failure handling; integrate decoration, ores, structures and dimensions; then LevelDB, multiversion, PHAR, CI and real-client acceptance. Remain Draft until every approved acceptance criterion is evidenced.


## Phase 2 continuation: authentic VanillaMine terrain core (`core-v1`, still Draft)

This branch **retains the old empty-preset `ryze_vanilla_overworld` algorithm unchanged** for existing test worlds. It adds a separate, explicit, version-pinned generator preset using actual VanillaMine source components:

```properties
level-type=ryze_vanilla_overworld
generator-settings=core-v1;data=/absolute/path/to/prepared/vanilla-worldgen
```

Use only on a **new disposable test world**. Do not select this setting for an already-generated world or permanently operate a production server with it. It requires a **pre-existing, verified** VanillaMine **Minecraft Java 26.3 data cache, format 5** (`version.json`, noise settings, biome parameter report/tree, noise files, material rules and optionally carvers), prepared externally following VanillaMine's documented procedure. **RyzeMP does not download Mojang assets or run Java/Go executables.** Mismatched data stamp or missing principal data files fail validation; no fallback to the synthetic profile is performed for `core-v1`.

### Actual imported pipeline (source VanillaMine `vanilla-content` @ `4dd2228b47d64c8015c28138c846e6c4acb28345`)

- Seeded Xoroshiro and legacy Java random, normal/Perlin/simplex/blended noises and the JSON density function graph are **ported**, not re-created with ad hoc 2D noise.
- `NoiseRouter` and `ClimateBiomeTree` sample continentalness, erosion, temperature, vegetation, depth and ridges; every vertical 4×4×4 biome is mapped into Bedrock subchunk palettes through RyzeMP's own block/biome serializer.
- `InterpretedTerrainFiller` uses upstream trilinear density cells and the noise aquifer algorithm for stone/air/water/lava, when the supplied data enables aquifers. The *interpreted PHP backend* is the actual selected core; it has no native `vanillagen` dependency and is expected to be slower.
- The imported `MaterialSystem` evaluates worldgen JSON rules including surface layers, deepslate and ore-vein rules; `JavaBlockMapping` converts its resulting material palette to supported RyzeMP runtime block states.
- When the data cache supplies carver definitions, the imported `CarverSystem` runs cave/canyon carvers after material rules. With caches lacking carvers it correctly reports no carvers.
- Tests include independent deepslate noise reference fixtures, carver mathematics, synthetic router/material/3D-biome fixtures, deterministic generation order, invalid preset handling, and LevelDB save/close/reopen (CI verdict must be checked on final HEAD).

### Explicit limitations and differences

**Not finished:** The feature/decoration system (ore placed features, trees, plants, lakes), Overworld structure placement and terrain adaptation (`Beardifier`), Nether/End VanillaMine pipeline adaptations, Go `vanillagen` helper and any native acceleration/fallback. Structure-dependent density adjustment was intentionally omitted from the imported interpreted filler, without pretending to emulate structure placement. The only ore content currently included in `core-v1` is that which the *material rules* themselves produce, not VanillaMine's complete placed-feature ore distribution. Unknown Java biome names **fail safely**; unsupported Java blocks in the upstream mapping are either mapped conservatively (powder snow to snow, sulfur/cinnabar to stone) or fail with an explicit exception. These substitutions are not Bedrock parity.

**Not proven:** Exact Bedrock seed parity, complete Java 26.3 data-driven chunk equivalence, environmental decoration, legacy-client biome mappings, TPS/memory under realistic workload, full world restart, old world seam behavior, real-client visual acceptance or native protocol support. Synthetic tests verify the adapter's mechanics, not the authenticity of unbundled Mojang data.

### Operational safeguards

The `core-v1` preset is stored in `level.dat` for new worlds, so a world with empty legacy options never changes its generator semantics during this integration. Do not edit the preset on an existing world. Keep the chosen external data cache immutable and backed up; replacing a cache under the same path could change generated chunks despite the version stamp. This branch does not modify the LevelDB provider, automatically rewrite old chunks, touch `dimensions.yml`, replace RyzeCompany dependencies or change existing Nether/End generator names. Rollback remains stopping the server, using an offline LevelDB backup and disabling the experimental world.

All upstream source retains the LGPL-family header; the numeric noise fixture documents its separate MIT-licensed deepslate reference. Mojang data and Go binaries are not included in this branch. Attribution and component-level notices must be revalidated before release.


## Continued integration: Nether, End and guarded biome conversion (2026-11-12 UTC)

**Registered opt-in names, none of which change existing worlds or DimensionManager defaults:**

| Experimental class | Generator registry name | Dimension | Current pipeline |
| --- | --- | --- | --- |
| `RyzeOverworldGenerator` (nonempty preset) | `ryze_vanilla_overworld` | Overworld | VanillaMine density / multi-noise / biomes / aquifers / surface materials / optional carvers |
| `RyzeVanillaNetherCoreGenerator` | `ryze_vanillamine_nether_core_v1` | Nether | VanillaMine Nether density / biome tree / materials / optional carvers; no Nether vegetation or structures |
| `RyzeVanillaEndCoreGenerator` | `ryze_vanillamine_end_core_v1` | End | VanillaMine End density / End biome source / materials, **plus** seed-based obsidian spikes; no End cities / chorus / gateway placement |

Both secondary generators require a **new world** with `core-v1;data=/absolute/path` pointing to a preprepared **Minecraft Java 26.3 / format 5** VanillaMine cache. Their noise settings select `nether` and `end` respectively. These names are **not** aliases to the existing `vanilla_nether` and `vanilla_end`; the old RyzeMP dimension generators remain the default in `DimensionManager`. To test transitions, create separate test worlds with their correct dimension folder names and matching generator metadata while `DimensionManager` is configured to use them; **never edit an existing dimension's level.dat or NBT**. If the destination already exists, do not reuse it for this experiment.

The original VanillaMine `EndSpikes::forSeed()` seed permutation (ten pillars, heights 76–103) and `EndFeatures::spike()` block geometry were adapted to write *only* the requested chunk. This avoids population-order dependencies across chunk boundaries. Existing End crystals/boss AI remain separate. Basic iron-bar cages are placed, but **their directional blockstates and crystal entities have not been fully reproduced**. The deterministic seed layout is checked against the original numerical End fixtures. This is one partial End feature, not complete End structure support.

**Versioning guard:** Legacy experimental worlds whose `generatorOptions` is empty still use exactly the original 2D algorithm; new core worlds select `core-v1` explicitly. `level.dat` retains the literal generator option and seed. The external data cache is validated against `version.json` and the required metadata/noise files before generation. The version stamp **does not guarantee content immutability**; the data files must be retained read-only with an offline backup. A cryptographic data manifest/fingerprint remains required before production.

**Multiversion guard:** `BiomeMapping` rejects unknown upstream Java biome names rather than injecting arbitrary IDs. A PHPUnit case enumerates every mapped Java biome and checks that its Bedrock ID exists in **RyzeMP's** `LegacyBiomeIdToStringIdMap`. However, real 1.16/1.21/1.26 mixed-client packet translation and older-client fallbacks are **not** verified here. Mappings of some unfamiliar blocks are conservative substitutes and do not imply game parity.

### Tests and performance interpretation

- PHPUnit covers synthetic Overworld density/material surfaces, 3D biomes, generation order, negative height, deterministic aquifer invocation, seed/preset metadata and LevelDB reopen with NBT.
- Synthetic dimension tests check Nether and End biome/terrain isolation, generator names and a numeric End spike seed reference.
- Original upstream noise/deepslate fixture tests and carver arithmetic are present. The reference carver-mask test **skips** if `POCKETMINE_VANILLA_WORLDGEN_DATA` is absent, so CI success does not establish full real-data cave parity.
- A PHPUnit performance baseline measures 4 synthetic density chunks and prints average/maximum milliseconds and peak memory delta to CI stderr. It does **not** measure real Minecraft generation speed, server TPS or load with connected clients. The benchmark must be repeated with a verified full 26.3 cache and real server workloads before release.
- The existing PHAR workflow builds and tests RyzeMP's normal smoke boot; it does **not** automatically validate visual entry into the three opt-in worlds. Real-client acceptance is outstanding.

### Go/native audit and blockers

VanillaMine source `native/vanillagen` at the audited reference uses Go **1.24** and an explicit framed localhost protocol (**protocol version 7, algorithm version 4**). Its PHP manager starts a child process with a per-run token, and worker connections verify algorithm/biome data before using it. The native program, Java data downloader, build artifacts, packaged binaries and PHP/Go bridge are **not imported** into RyzeMP. The opted-in backend is real **interpreted PHP**; there is **no claim** of functional native generation, compiled PHP backend, tested Go build, measured production throughput or crash fallback in this PR. Before attempting native mode, verify OS/CPU support, provenance, LGPL notice and wire compatibility with the Ryze multiversion fork.

**Remaining highest-priority work:** full features (placed ore deposits, trees, vegetation, lakes, biome decoration), VanillaMine structure placement (villages, mineshafts, temples, fortresses, bastions, End cities), terrain adaptation around structure pieces, Nether/End decorations and transitions with live clients, full Mojang 26.3 cache oracle tests, old-world seam tests, stress TPS/memory, full server restart, binary build/timeout/fallback if native acceleration is pursued, and final visual approval. The PR remains **Draft**, with no merge permission.


## 2026-11-12 continuation: verified Java data bootstrap and opt-in decoration

The prior empty-preset 2D generator and historical core-v1 profiles stay untouched. Two new, explicit world-data presets prevent silent upgrades:

| Preset | Capabilities | Important qualification |
| --- | --- | --- |
| core-v2;data=/absolute/cache;sha256=HEX | Genuine VanillaMine density, biomes, aquifers, material rules and carvers | Canonical SHA-256 of all source inputs is checked before every new chunk; decoration remains disabled |
| core-v3;data=/absolute/cache;sha256=HEX | Same pipeline plus original VanillaMine seeded placed-feature engine | Experimental and unsafe for production until NBT persistence and real-data parity are verified |

In both cases, HEX is the 64-character lowercase SHA-256 printed by tools/worldgen-data-fingerprint.php. The entire generator-options string persists in the existing level.dat. Fingerprints cover all file bytes and relative names in the VanillaMine cache (data, reports, trees, Java blocks/items, Geyser mappings and the version stamp), not timestamps or absolute location. A changed cache makes the generator throw before writing any new chunk. Symlinked cache inputs are rejected. Full hashing per chunk can be expensive and has not been benchmarked on the real 26.3 dataset.

### Authentic Java data preparation: implemented but not run in this session

From an Internet-connected, trusted machine with rights to use Minecraft Java Edition server files, run these commands in the RyzeMP repository:

    composer install
    php tools/prepare-vanillamine-worldgen.php /absolute/new/empty/vanillamine-cache
    php tools/worldgen-data-fingerprint.php /absolute/new/empty/vanillamine-cache

The first CLI refuses nonempty/symlink directories and only runs with explicit operator action. It uses VanillaMine's original VanillaDataFetcher at commit 4dd2228b47d64c8015c28138c846e6c4acb28345, not an approximate generator. That fetcher resolves Java 26.3 using Mojang's version manifest, checks the server JAR against its published SHA-1, uses a SHA-256-verified Eclipse Temurin JRE if required, runs the official Minecraft data generator and verifies pinned GeyserMC mapping files using their SHA-256 checksums (revision 81700ab543c1f4e9f9041f58dac4154ecb0d0c8b). It emits Minecraft version 26.3 and cache format 5. The generated asset cache contains density/noise settings, material rules, biome lists and R-trees, features, placed features, tags, structures and templates. Do not commit or redistribute Mojang assets; observe the Minecraft EULA.

This session's container could not resolve Mojang domain names and had Go 1.23.2, below the audited native Go 1.24 requirement. No real data was downloaded or executed here. The procedure is reproducible but NOT itself proof of real-data parity.

### Placed features and shortcomings

The source for VanillaMine's FeatureRegistry, FeatureDecorator, feature placement modifiers, predicates, seeded FeatureRandom, OreFeature, trees, vegetation, geodes, caves, Nether and End features is ported and connected to populateChunk only in explicitly selected core-v3 new worlds. The decorator evaluates original JSON-based feature rules over the existing RyzeMP 3x3 chunk region. This is NOT a second noise simulation.

Important RyzeMP API differences: Chunk lacks VanillaMine pending tile/entity NBT methods, while SubChunk lacks its liquid-layer setters. FeatureLevel now writes second block layers using RyzeMP's existing palettes, and tests exercise it; it deliberately throws if a feature needs unsupported tile/entity NBT instead of silently discarding important data. This can abort a partially populated test chunk. Never use core-v3 on a production world until a transactional NBT adapter exists. VanillaMine's Java block shape setters for chorus, fire and redstone wires also do not exist in this fork; directional shapes are not fully reproduced. These limitations preclude claiming full Bedrock visual parity.

Village, temple, mineshaft, mansion, bastion, fortress, trial chamber, End City and other structure assembly plus terrain Beardifier remain unimplemented. Source settings existing in the cache do not prove structure placement. Neither the native Go vanillagen runtime nor its wire protocol are integrated. Nether/End default RyzeMP generators, DimensionManager, worlds, portals, multiversion dependencies, LevelDB provider and climate PR #58 are unchanged.

### Reference test procedure

Set POCKETMINE_VANILLA_WORLDGEN_DATA to the prepared authentic cache path, then run PHPUnit tests under tests/phpunit/world/generator/vanilla. CarverTest compares original cave masks, and VanillaRealDataReferenceTest compares Nether and End block/material and biome hashes against independently produced numerical Java 26.3 reference fixtures. Tests skip when real data is unavailable. Imported feature RNG and seed reference tests run without Mojang assets. A green CI result without the environment variable proves only synthetic/reference-numeric unit stages, not full Java world parity.

Actual PHAR startup with opt-in worlds, LevelDB restart, real Bedrock clients, mixed-protocol sessions, data-driven structures and production TPS benchmarks remain acceptance blockers. Keep PR #60 Draft and use offline LevelDB backup / previous build for rollback.
