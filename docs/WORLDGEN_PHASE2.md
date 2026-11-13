# RyzeMP Phase 2 — Worldgen Vanilla (work in progress)

**Guía Go VPS:** [instalación, activación, benchmarks y recuperación](WORLDGEN_GO_VPS.md).

**Baseline:** RyzeMP `main` @ `aa6d91b8070eef31b756c8332acd6848760c5ee7`; VanillaMine `vanilla-content` @ `4dd2228b47d64c8015c28138c846e6c4acb28345` (audited 2026-11-11). Scope tracked by [#59](https://github.com/RyzeCompany/RyzeMP/issues/59). Independent from weather [#58](https://github.com/RyzeCompany/RyzeMP/pull/58).

## Estado actual

El perfil experimental más completo es `core-v6;data=/absolute/cache;sha256=HEX`.
Usa Java 26.3, formato de caché 5, y el backend PHP compilado. Añade a los
tres generadores los placed features, las estructuras con Beardifier y los
bloques de estructura descritos más abajo. Las secciones de auditoría e
integraciones previas conservan el estado histórico de cada perfil.

### Crear un servidor de prueba

Prepara el caché en el checkout de RyzeMP y copia el SHA-256 que imprime la
segunda orden. La CLI obtiene los insumos verificados y deja el caché fuera
del PHAR.

    php tools/prepare-vanillamine-worldgen.php /absolute/new/vanillamine-cache
    php tools/worldgen-data-fingerprint.php /absolute/new/vanillamine-cache

En un directorio nuevo de servidor, usa estas propiedades:

    level-name=world
    level-seed=12345
    level-type=ryze_vanilla_overworld
    generator-settings=core-v6;data=/absolute/new/vanillamine-cache;sha256=HEX

Para crear Nether y End desde la configuración del servidor, usa el mismo
preset en ambas secciones de `dimensions.yml`:

    nether:
      world: world_nether
      source-dimension: nether
      worldgen-preset: 'core-v6;data=/absolute/new/vanillamine-cache;sha256=HEX'
    end:
      world: world_the_end
      source-dimension: end
      worldgen-preset: 'core-v6;data=/absolute/new/vanillamine-cache;sha256=HEX'

DimensionManager crea las carpetas nuevas con la semilla del Overworld y
conserva el preset en sus metadatos. Las carpetas existentes usan el
generador guardado. Para repetir la prueba automática de reinicio, ejecuta
`tests/worldgen-phar-smoke.py` con el PHP de RyzeMP, el PHAR y el caché.

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

### Authentic Java data preparation

From an Internet-connected, trusted machine with rights to use Minecraft Java Edition server files, run these commands in the RyzeMP repository:

    composer install
    php tools/prepare-vanillamine-worldgen.php /absolute/new/empty/vanillamine-cache
    php tools/worldgen-data-fingerprint.php /absolute/new/empty/vanillamine-cache

The first CLI refuses nonempty/symlink directories and only runs with explicit operator action. It uses VanillaMine's original VanillaDataFetcher at commit 4dd2228b47d64c8015c28138c846e6c4acb28345, not an approximate generator. That fetcher resolves Java 26.3 using Mojang's version manifest, checks the server JAR against its published SHA-1, uses a SHA-256-verified Eclipse Temurin JRE if required, runs the official Minecraft data generator and verifies pinned GeyserMC mapping files using their SHA-256 checksums (revision 81700ab543c1f4e9f9041f58dac4154ecb0d0c8b). It emits Minecraft version 26.3 and cache format 5. The generated asset cache contains density/noise settings, material rules, biome lists and R-trees, features, placed features, tags, structures and templates. Do not commit or redistribute Mojang assets; observe the Minecraft EULA.

The real-data workflow rebuilds this cache, runs the Java reference tests and removes the assets afterward. See the latest workflow run for the verified results.

### Placed features and shortcomings

The source for VanillaMine's FeatureRegistry, FeatureDecorator, feature placement modifiers, predicates, seeded FeatureRandom, OreFeature, trees, vegetation, geodes, caves, Nether and End features is ported and connected to populateChunk only in explicitly selected core-v3 new worlds. The decorator evaluates original JSON-based feature rules over the existing RyzeMP 3x3 chunk region. This is NOT a second noise simulation.

Important RyzeMP API differences: Chunk lacks VanillaMine pending tile/entity NBT methods, while SubChunk lacks its liquid-layer setters. FeatureLevel now writes second block layers using RyzeMP's existing palettes, and tests exercise it; it deliberately throws if a feature needs unsupported tile/entity NBT instead of silently discarding important data. This can abort a partially populated test chunk. Never use core-v3 on a production world until a transactional NBT adapter exists. VanillaMine's Java block shape setters for chorus, fire and redstone wires also do not exist in this fork; directional shapes are not fully reproduced. These limitations preclude claiming full Bedrock visual parity.

Village, temple, mineshaft, mansion, bastion, fortress, trial chamber, End City and other structure assembly plus terrain Beardifier remain unimplemented. Source settings existing in the cache do not prove structure placement. Neither the native Go vanillagen runtime nor its wire protocol are integrated. Nether/End default RyzeMP generators, DimensionManager, worlds, portals, multiversion dependencies, LevelDB provider and climate PR #58 are unchanged.

### Reference test procedure

Set POCKETMINE_VANILLA_WORLDGEN_DATA to the prepared authentic cache path, then run PHPUnit tests under tests/phpunit/world/generator/vanilla. CarverTest compares original cave masks, and VanillaRealDataReferenceTest compares Nether and End block/material and biome hashes against independently produced numerical Java 26.3 reference fixtures. Tests skip when real data is unavailable. Imported feature RNG and seed reference tests run without Mojang assets. A green CI result without the environment variable proves only synthetic/reference-numeric unit stages, not full Java world parity.

Actual PHAR startup with opt-in worlds, LevelDB restart, real Bedrock clients, mixed-protocol sessions, data-driven structures and production TPS benchmarks remain acceptance blockers. Keep PR #60 Draft and use offline LevelDB backup / previous build for rollback.

## PHAR restart coverage and generated block entities

The real-data workflow builds the PHAR and starts it twice per profile with
`core-v1`, `core-v4`, `core-v5` and `core-v6`, using Java 26.3 data and dedicated LevelDB folders for all
three dimensions. It checks the selected generator, seed, preset, dimension,
block and biome snapshots, and new chunk population after restart. The
`core-v4` test also checks the natural dungeon at seed 12345, chunk (0, 3).

Supported generated tiles travel with their chunk from the population worker
to the world thread. The test verifies a dungeon chest's loot-table metadata,
64-bit seed and stored inventory, and a skeleton spawner's identifier after
reopening the world. Existing tiles retain their inventories when population
touches a neighbouring chunk. The structure and loot section describes the subsequent container update.

To repeat the two-boot check with an already prepared cache and a locally
built PHAR, run:

    python3 tests/worldgen-phar-smoke.py /path/to/php RyzeMP.phar /path/to/cache

The fixture creates fresh worlds and uses the real asynchronous population
pipeline. It runs on loopback without a game client.

## Structure generation and container loot

New worlds can opt into `core-v5;data=/absolute/cache;sha256=HEX`. This profile
uses the compiled PHP terrain and adds the structure placement, Jigsaw pools,
processors, piece templates and Beardifier from VanillaMine
`4dd2228b47d64c8015c28138c846e6c4acb28345`. The structure positions use the Java
26.3 algorithm. Earlier presets keep their terrain and decoration selection.

The profile evaluates neighbouring decoration against fresh terrain and
combines the writes in fixed coordinate order. Each worker publishes writes
only in the requested chunk. This prevents a late neighbouring population
from replacing saved blocks and makes the result independent of request order
for the tested areas. Overlapping features can differ from Java's decoration
order. The test suite compares Java structure starts, piece counts and boxes,
and checks both population orders across an outpost and a Nether fortress.

The PHAR test also generates a village bell, a fortress blaze spawner and an
End City chest and verifies their terrain and block entities after restart.
Dungeon containers roll their Java loot once on first inventory access using
their saved seed and the world's pinned cache. Unsupported items and
components use the runtime's available representations. Retail Bedrock sessions
and production-load measurements are still required.

## End gateways, spike crystals and ship loot

The pinned profile `core-v6;data=/absolute/cache;sha256=HEX` includes
the structure generation from core-v5 and enables the added worldgen block
entities. A natural outer-island End gateway keeps its exact exit from the
Java configuration in LevelDB. The world loads the destination chunk before
teleporting an entity or the owner of an ender pearl. The central spikes use
the selected seed's layout when placing their crystals. End ships contain an
Elytra in a Bedrock item frame.

The two-boot fixture checks the generated gateway exit, an actual entity
teleport and the saved spike crystal. It also checks network serialization for
protocols 589, 2193 and 2225. Gateways created by the dragon fight and full
Elytra flight mechanics are outside this increment.

## Trial Chambers and legacy clients

Core-v6 places normal and ominous vaults and keeps the named or inline
normal and ominous spawner configurations from Java templates. Vaults use
the world's pinned loot tables and save the rewarded player IDs and pending
ejections. Trial spawner mob waves and other structure entities still need
the corresponding entity runtime.

The pinned ominous vault reward table is checked against the item converter
with reproducible random seeds. Its wind charge, heavy core, crossbow, Flow
template, ominous bottle and Flow banner pattern have persistent item IDs;
the ominous bottle amplifier and crossbow damage survive item NBT. This
establishes loot representation, not projectile, crossbow firing, or block
placement mechanics for these items.

The restart fixture generates both vault variants, a Breeze trial spawner
and the End Ship Elytra. It verifies the key, reward table and spawner NBT.
It also encodes the Trial Chamber chunk and live block updates for every
accepted protocol. When a client lacks a block state, it receives the safe
`info_update` block without the corresponding block entity. LevelDB retains
the original block and NBT. The chunk serializer also translates Pale Garden
and Sulfur Caves to Dark Forest and Dripstone Caves for protocols predating
their respective releases. This conversion only touches network data.

## Concurrent PHAR benchmark

Run the loopback benchmark on Linux with the compiled PHP profile:

    python3 tests/worldgen-phar-smoke.py /path/to/php RyzeMP.phar /path/to/cache --benchmark

Each run starts a fresh server with two async workers and requests 16 new
chunks in each of the three dimensions in the same batch. Seed 12345 uses
regions with a village, fortress and End City. The negative seed uses
different regions. The log reports p50 and p95 time from batch submission
until each populated chunk reaches the server, including queue delays. It
also reports observed biome IDs, process CPU time during population, peak
process RSS (including startup), server TPS and the peak sampled number of
async tasks. LevelDB is saved at the end of the batch. Timings exclude that
save and network traffic from players.

### Retail Bedrock acceptance

- Create fresh core-v6 worlds with the pinned cache. Connect using RakNet
  on 1.20.0 and using RyzeRTC/NetherNet on 1.26.60. Repeat with 1.26.40,
  including both older and newer players connected together.
- Use seed 12345 and explore the village near (164, 100, 882), a dungeon at
  (8, -39, 48), and the Trial Chamber at (4, -10, 215). Check underground
  biomes, caves, ore, trees, inventories and block actors while crossing
  chunk boundaries. Verify that legacy clients stay connected near blocks
  their protocol cannot render.
- Check travel to the Nether and back, the fortress near (117, 75, 681),
  the End island and spikes, the ship at (-164, 142, 1284), and the return
  gateway at (-3793, 69, -407). Walk and teleport across already populated
  and fresh chunks in each dimension.
- Save and stop the server. Start the same build again and revisit those
  locations with both protocol families. Compare inventories, changed
  blocks, structure boundaries, spawn and portal destinations.

## Failure of the pinned cache while a world is loaded

A fingerprint or cache-read failure in a generator worker blocks new
population in the affected world until its cache is restored and the world
is reopened. A population task checks the full hash before preparing its
3x3 area and again before the worker returns its result. Standalone generator
calls also check the hash. Existing populated chunks remain available and in-flight
results are discarded when the world is blocked. Requests for missing
chunks are rejected with a diagnostic. The PHAR test changes the version
stamp in a private copy of the real cache and verifies that the server
preserves the existing chunk while rejecting new and queued requests.

    python3 tests/worldgen-phar-smoke.py /path/to/php RyzeMP.phar /path/to/cache --cache-failure

## Archaeology blocks

Core-v6 places suspicious sand and gravel using the original structure
and feature loot data. Their seeds and revealed items persist in LevelDB.
The server supports brushing with a Brush and translates block actors and
sounds for each receiving client. The PHAR fixture locates a desert
pyramid, brushes a naturally placed suspicious block and checks the
revealed item after restarting. All 23 pottery sherd variants and decorated
pot item and tile storage have runtime representations; patterned pot
crafting still requires a dynamic special recipe. The pinned archaeology
loot tables also require persistent lead and sniffer egg item IDs; mob
leashing and sniffer egg hatching are gameplay work outside this generator.

## Upstream Go evaluation

The real-data job checks out VanillaMine at its pinned revision and compiles
vanillagen with Go 1.24. It runs the upstream reference tests with the same
Java data cache and measures the native terrain, materials and carvers for
all three dimensions. This measures the native kernel in isolation. It
excludes RyzeMP structures, PHP decoration, packet conversion and LevelDB,
which are included as applicable in the PHP server workload. The native
wire protocol and process management still require a RyzeMP integration
and equivalence tests before using Go on an existing pinned world.
