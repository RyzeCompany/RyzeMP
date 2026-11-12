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
