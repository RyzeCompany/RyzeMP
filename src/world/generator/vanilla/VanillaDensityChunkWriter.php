<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use pocketmine\block\VanillaBlocks;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use pocketmine\world\generator\InvalidGeneratorOptionsException;
use pocketmine\world\generator\vanilla\biome\ClimateBiomeTree;
use pocketmine\world\generator\vanilla\biome\TheEndBiomeSource;
use pocketmine\world\generator\vanilla\carver\CarverSystem;
use pocketmine\world\generator\vanilla\feature\DeterministicDecoration;
use pocketmine\world\generator\vanilla\feature\FeatureDecorator;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use pocketmine\world\generator\vanilla\surface\MaterialSystem;
use pocketmine\world\generator\vanilla\native\NativeTerrainClient;
use pocketmine\world\generator\vanilla\native\NativeTerrainFiller;
use function getenv;
use pocketmine\world\generator\vanilla\structure\placement\BedrockStructurePlacements;
use pocketmine\world\generator\vanilla\structure\StructureEnvironment;
use pocketmine\world\generator\vanilla\structure\StructureManager;
use pocketmine\world\generator\vanilla\structure\StructureRegistry;
use pocketmine\world\generator\vanilla\structure\template\StructureTemplates;
use pocketmine\world\generator\vanilla\structure\TerrainSampler;
use Symfony\Component\Filesystem\Path;
use function file_exists;
use function file_get_contents;
use function is_array;
use function is_dir;
use function is_string;
use function json_decode;
use function max;
use function min;
use const JSON_THROW_ON_ERROR;

/**
 * Adapter for VanillaMine's real multi-noise / density / aquifer terrain stage.
 *
 * Terrain, placed features and structure terrain adaptation are selected by the world's preset.
 */
final class VanillaDensityChunkWriter{
	public const PRESET_PREFIX = "core-v1;data=";
	public const MINECRAFT_DATA_VERSION = "26.3";
	public const DATA_FORMAT = 5;

	private TerrainFiller $terrain;
	private MaterialSystem $materials;
	private ?CarverSystem $carvers;
	private ?TheEndBiomeSource $endBiomes = null;
	private ?FeatureDecorator $features = null;
	private ?StructureManager $structures = null;
	private ?DeterministicDecoration $deterministicDecoration = null;
	private bool $checkedPopulationCache = false;
	/** @var list<int> */
	private array $biomes;

	private string $terrainDataDir;
	private int $air;
	private int $bedrock;

	public function __construct(
		int $seed,
		string $dataDir,
		private string $dimension = "overworld",
		private ?string $pinnedHash = null,
		bool $enablePlacedFeatures = false,
		bool $useCompiledTerrain = false,
		bool $enableStructures = false,
		bool $enableStructureContent = false
	){
		$this->terrainDataDir = $dataDir;
		if($this->pinnedHash !== null){
			VanillaDataIdentity::assertMatches($dataDir, $this->pinnedHash);
		}
		$data = new WorldgenData($dataDir);
		$router = new NoiseRouter($data, $seed, $dimension);
		$expectedMinY = $dimension === "overworld" ? -64 : 0;
		$expectedHeight = $dimension === "overworld" ? 384 : 128;
		if($router->minY !== $expectedMinY || $router->height !== $expectedHeight){
			throw new InvalidGeneratorOptionsException("Vanilla worldgen core-v1 dimension $dimension has unexpected height: $router->minY / $router->height");
		}
		$tree = $dimension === "end" ? ClimateBiomeTree::single("minecraft:the_end") : ClimateBiomeTree::fromData($data, $dimension);
		$names = $dimension === "end" ? TheEndBiomeSource::NAMES : $tree->getBiomeNames();
		$this->biomes = array_map(static fn(string $name) : int => BiomeMapping::toBedrock($name), $names);
		if($dimension === "end"){
			$this->endBiomes = new TheEndBiomeSource($router->erosion);
		}
		//Preserve the exact existing PHP fallback. Go is strictly opt-in via
		//RYZEMP_VANILLAGEN_BINARY, and its transport is internal to the host.
		$fallback = static fn() : TerrainFiller => $useCompiledTerrain ?
			new CompiledTerrainFiller($router, $tree, $dataDir . "/compiled") :
			new InterpretedTerrainFiller($router, $tree);
		$stage = getenv("RYZEMP_VANILLAGEN_STAGE");
		$flags = $stage === "full" ?
			NativeTerrainClient::FLAG_MATERIALS | NativeTerrainClient::FLAG_CARVERS : 0;
		$this->terrain = $useCompiledTerrain ?
			(NativeTerrainFiller::connect($dataDir, $seed, $tree->getBiomeNames(), $router->minY, $router->height,
				$fallback, $flags, $dimension) ?? $fallback()) :
			$fallback();
		$this->materials = new MaterialSystem($data, $router, $tree, $seed, null, null, $dimension);
		$this->carvers = CarverSystem::create($data, $router, $tree, $seed, $this->materials, null);
		if($enablePlacedFeatures || $enableStructures){
			$sample = $this->endBiomes !== null ?
				fn(int $qx, int $qy, int $qz) : int => $this->endBiomes->getNoiseBiome($qx, $qy, $qz) :
				function(int $qx, int $qy, int $qz) use ($router, $tree) : int{
					$x = (float) ($qx << 2);
					$y = (float) ($qy << 2);
					$z = (float) ($qz << 2);
					return $tree->search(
						ClimateBiomeTree::quantize($router->temperature->compute($x, 0.0, $z)),
						ClimateBiomeTree::quantize($router->vegetation->compute($x, 0.0, $z)),
						ClimateBiomeTree::quantize($router->continents->compute($x, 0.0, $z)),
						ClimateBiomeTree::quantize($router->erosion->compute($x, 0.0, $z)),
						ClimateBiomeTree::quantize($router->depth->compute($x, $y, $z)),
						ClimateBiomeTree::quantize($router->ridges->compute($x, 0.0, $z))
					);
				};
			$buildHeight = $dimension === "end" ? 256 : $router->height;
			$this->features = new FeatureDecorator(
				$data,
				$seed,
				$router->minY,
				$buildHeight,
				$router->seaLevel,
				$names,
				$sample,
				null,
				$dimension === "end" ? TheEndBiomeSource::NAMES : FeatureRegistry::multiNoiseBiomes($data, $dimension),
				$enableStructureContent
			);
			$this->features->regionRandomFactory = $router->random->fromHashOf("minecraft:worldgen_region_random")->forkPositional();
			$this->features->featuresEnabled = $enablePlacedFeatures;
			if($enableStructures){
				$blocks = $this->features->blocks;
				$registry = StructureRegistry::shared($data, $blocks);
				$environment = new StructureEnvironment(
					$seed, $router->minY, $buildHeight, $router->seaLevel, $dimension,
					new TerrainSampler($this->terrain->fill(...), $router->minY, $router->height),
					static fn(int $x, int $y, int $z) : string => $names[$sample($x >> 2, $y >> 2, $z >> 2)],
					$registry,
					StructureTemplates::shared($data, $blocks),
					$blocks
				);
				$features = $this->features->registry;
				$environment->setColdEnoughToSnow(static function(string $biome, int $x, int $y, int $z) use ($features, $router) : bool{
					$index = $features->biomeIndex[$biome] ?? null;
					return $index !== null && !$features->climate()->warmEnoughToRain($index, $x, $y, $z, $router->seaLevel);
				});
				$this->structures = new StructureManager($environment, BedrockStructurePlacements::MODE_JAVA, [], $names);
				$this->features->structures = $this->structures;
				if($enablePlacedFeatures){
					$this->deterministicDecoration = new DeterministicDecoration($this->features, $this->writeTerrain(...));
				}
			}
		}
		$this->air = VanillaBlocks::AIR()->getStateId();
		$this->bedrock = VanillaBlocks::BEDROCK()->getStateId();
	}

	/** Checks the preset, data stamp and required baseline assets before world creation. */
	public static function validatePreset(string $preset, string $dimension = "overworld") : string{
		if(!in_array($dimension, ["overworld", "nether", "end"], true)){
			throw new InvalidGeneratorOptionsException("Unsupported VanillaMine dimension: $dimension");
		}
		if(($hash = VanillaDataIdentity::expectedHash($preset)) !== null){
			$pinned = VanillaDataIdentity::parse($preset);
			$dir = $pinned["directory"];
			VanillaDataIdentity::assertMatches($dir, $hash);
		}else{
			// Preserve the old unpinned core-v1 preset verbatim for existing
			// experimental worlds; never upgrade their algorithm silently.
			if(!str_starts_with($preset, self::PRESET_PREFIX)){
				throw new InvalidGeneratorOptionsException("Expected core-v1 or a pinned core-v2 through core-v6 preset");
			}
			$dir = substr($preset, strlen(self::PRESET_PREFIX));
			if($dir === "" || str_contains($dir, ";") || str_contains($dir, "\0") || !Path::isAbsolute($dir)){
				throw new InvalidGeneratorOptionsException("core-v1 requires a single absolute data directory");
			}
		}
		$stampFile = $dir . "/version.json";
		if(!is_file($stampFile)){
			throw new InvalidGeneratorOptionsException("Missing VanillaMine version.json in $dir");
		}
		try{
			$raw = file_get_contents($stampFile);
			if($raw === false){
				throw new InvalidGeneratorOptionsException("Could not read VanillaMine data stamp");
			}
			$stamp = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		}catch(\JsonException $e){
			throw new InvalidGeneratorOptionsException("Invalid VanillaMine data stamp", 0, $e);
		}
		if(!is_array($stamp) || ($stamp["minecraft"] ?? null) !== self::MINECRAFT_DATA_VERSION || ($stamp["format"] ?? null) !== self::DATA_FORMAT){
			throw new InvalidGeneratorOptionsException("VanillaMine worldgen cache must be Minecraft 26.3, format 5");
		}
		$settingsFile = $dir . "/data/minecraft/worldgen/noise_settings/" . $dimension . ".json";
		if(!is_file($settingsFile) ||
			($dimension !== "end" && !is_file($dir . "/multi_noise_biome_tree.json") && !is_file($dir . "/reports/biome_parameters/minecraft/" . $dimension . ".json"))){
			throw new InvalidGeneratorOptionsException("VanillaMine worldgen cache is incomplete");
		}
		$settingsRaw = file_get_contents($settingsFile);
		if($settingsRaw === false){
			throw new InvalidGeneratorOptionsException("Cannot read VanillaMine Overworld noise settings");
		}
		try{
			$settings = json_decode($settingsRaw, true, 512, JSON_THROW_ON_ERROR);
		}catch(\JsonException $e){
			throw new InvalidGeneratorOptionsException("Malformed VanillaMine Overworld noise settings", 0, $e);
		}
		if(!is_array($settings) || !isset($settings["material_rule"]) || !isset($settings["noise_router"])){
			throw new InvalidGeneratorOptionsException("VanillaMine cache is missing Overworld material/density rules");
		}
		foreach(["surface", "surface_secondary", "badlands_pillar", "badlands_pillar_roof", "badlands_surface", "iceberg_pillar", "iceberg_pillar_roof", "iceberg_surface"] as $noise){
			if(!is_file($dir . "/data/minecraft/worldgen/noise/" . $noise . ".json")){
				throw new InvalidGeneratorOptionsException("VanillaMine cache is missing noise $noise");
			}
		}
		if(VanillaDataIdentity::hasPlacedFeatures($preset)){
			foreach([
				"java_blocks.json",
				"geyser/blocks.nbt",
				"geyser/collisions.nbt",
				"data/minecraft/worldgen/placed_feature",
				"data/minecraft/worldgen/feature",
				"data/minecraft/tags/block"
			] as $required){
				if(!file_exists($dir . "/" . $required)){
					throw new InvalidGeneratorOptionsException("VanillaMine placed-feature cache is missing $required");
				}
			}
		}
		if(VanillaDataIdentity::hasStructures($preset)){
			foreach(["worldgen/structure", "worldgen/structure_set", "worldgen/template_pool", "worldgen/processor_list", "structure"] as $required){
				if(!is_dir($dir . "/data/minecraft/" . $required)){
					throw new InvalidGeneratorOptionsException("VanillaMine structure cache is missing $required");
				}
			}
		}
		return $dir;
	}

	/**
	 * Applies the upstream VanillaMine density and climate stages to a RyzeMP chunk.
	 * Decoration runs separately after the neighbouring terrain is available.
	 */
	/** Validate the shared input once before generating the entire 3x3 population area. @internal */
	public function beginPopulation() : void{
		// Keep the pre-check AND the final result verification. A previously
		// altered cache must be rejected before any chunk work starts; a cache
		// changed during generation must be rejected before publication. The
		// PHAR cache-failure test enforces both guarantees.
		$this->checkData();
		$this->checkedPopulationCache = true;
	}

	/** Validate again before returning worker results, including lazy feature reads. @internal */
	public function validatePopulationResult() : void{
		$this->checkData();
	}

	/** @internal */
	public function endPopulation() : void{
		$this->checkedPopulationCache = false;
	}

	private function checkData() : void{
		if($this->pinnedHash !== null){
			$start = VanillaWorldgenProfiler::active() ? microtime(true) : 0.0;
			VanillaDataIdentity::assertMatches($this->terrainDataDir, $this->pinnedHash);
			if($start !== 0.0){
				VanillaWorldgenProfiler::add("verify", (microtime(true) - $start) * 1000);
			}
		}
	}

	public function fill(Chunk $chunk, int $chunkX, int $chunkZ) : void{
		if($chunk->isPopulated()){
			return;
		}
		if(!$this->checkedPopulationCache){
			$this->checkData();
		}
		$this->writeTerrain($chunk, $chunkX, $chunkZ);
		$this->deterministicDecoration?->rememberTerrain($chunk, $chunkX, $chunkZ);
	}

	private function writeTerrain(Chunk $chunk, int $chunkX, int $chunkZ) : void{
		$profile = VanillaWorldgenProfiler::active();
		$stageStarted = $profile ? microtime(true) : 0.0;
		$terrain = $this->terrain->fill($chunkX, $chunkZ, $this->structures?->getBeardifier($chunkX, $chunkZ));
		$terrain->unpackBiomes();
		if($this->endBiomes !== null){
			$terrain->biomes = $this->endBiomes->fillChunk($chunkX, $chunkZ, $terrain->height >> 2);
		}
		//The native response may already include the material rules and carvers.
		//Never run those stages twice, and retain the PHP path if Go is unavailable.
		if($terrain->stage < TerrainChunk::STAGE_MATERIALS){
			$this->materials->apply($terrain);
		}
		if($terrain->stage < TerrainChunk::STAGE_CARVED){
			$this->carvers?->apply($terrain);
		}
		if($profile){
			VanillaWorldgenProfiler::add("terrain", (microtime(true) - $stageStarted) * 1000);
			$stageStarted = microtime(true);
		}
		$height = $terrain->height;
		$quartsY = $height >> 2;
		$minY = $terrain->minY;
		$maxY = $minY + $height;
		$biomeCount = count($this->biomes);

		// Each 4x4x4 VanillaMine noise biome is expanded into RyzeMP's
		// per-subchunk Bedrock biomes. This preserves vertical cave biomes.
		for($subY = Chunk::MIN_SUBCHUNK_INDEX; $subY <= Chunk::MAX_SUBCHUNK_INDEX; ++$subY){
			$old = $chunk->getSubChunk($subY);
			$palette = new PalettedBlockArray($this->biomes[0]);
			for($qx = 0; $qx < 4; ++$qx){
				for($qz = 0; $qz < 4; ++$qz){
					for($qy = 0; $qy < 4; ++$qy){
						$index = (($qx << 2) | $qz) * $quartsY + max(0, min($quartsY - 1, (($subY << 2) - ($minY >> 2)) + $qy));
						$id = $terrain->biomes[$index] ?? throw new \UnexpectedValueException("Missing 3D biome cell $index");
						if($id < 0 || $id >= $biomeCount){
							throw new \UnexpectedValueException("Unknown VanillaMine biome index $id");
						}
						$bedrockBiome = $this->biomes[$id];
						for($dx = 0; $dx < 4; ++$dx){
							for($dz = 0; $dz < 4; ++$dz){
								for($dy = 0; $dy < 4; ++$dy){
									$palette->set(($qx << 2) | $dx, ($qy << 2) | $dy, ($qz << 2) | $dz, $bedrockBiome);
								}
							}
						}
					}
				}
			}
			$chunk->setSubChunk($subY, new SubChunk($old->getEmptyBlockId(), $old->getBlockLayers(), $palette));
		}

		$states = [];
		foreach(($terrain->palette ?? $this->materials->palette->getNames()) as $material => $javaId){
			$states[$material] = JavaBlockMapping::toBlock($javaId)->getStateId();
		}
		// The original Chunk::setBlockStateId() resolves the subchunk and
		// marks the same dirty flag for EVERY solid block. All terrain writes
		// below are in the world height range; cache the subchunk references
		// and mark the chunk dirty once without changing any block state.
		$subChunks = $chunk->getSubChunks();
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$base = (($x << 4) | $z) * $height;
				$highest = $minY;
				for($y = $minY; $y < $maxY; ++$y){
					$m = $terrain->materials[$base + $y - $minY] ?? throw new \UnexpectedValueException("Missing VanillaMine terrain material");
					$state = $states[$m] ?? throw new \UnexpectedValueException("Unknown VanillaMine terrain material $m");
					if($y === $minY && $this->dimension !== "end"){
						$state = $this->bedrock;
					}
					if($state !== $this->air){
						$subChunks[$y >> SubChunk::COORD_BIT_SIZE]->setBlockStateId($x, $y & SubChunk::COORD_MASK, $z, $state);
						$highest = $y + 1;
					}
				}
				$chunk->setHeightMap($x, $z, $highest);
			}
		}
		$chunk->setTerrainDirtyFlag(Chunk::DIRTY_FLAG_BLOCKS, true);
		if($this->features !== null){
			$this->features->recordTerrain($chunkX, $chunkZ, $chunk);
		}
		if($profile){
			VanillaWorldgenProfiler::add("blocks", (microtime(true) - $stageStarted) * 1000);
		}
	}

	/** Runs decoration enabled by the world's preset. */
	public function populate(\pocketmine\world\ChunkManager $world, int $chunkX, int $chunkZ) : void{
		$features = $this->features;
		if($features === null){
			return;
		}
		if(!$this->checkedPopulationCache){
			$this->checkData();
		}
		$profile = VanillaWorldgenProfiler::active();
		$stageStarted = $profile ? microtime(true) : 0.0;
		if($this->deterministicDecoration !== null){
			$this->deterministicDecoration->decorate($world, $chunkX, $chunkZ);
		}else{
			$features->decorate($world, $chunkX, $chunkZ);
		}
		if($profile){
			VanillaWorldgenProfiler::add("decoration", (microtime(true) - $stageStarted) * 1000);
		}
	}

	public function hasPlacedFeatures() : bool{
		return $this->features !== null;
	}

	/** @internal */
	public function getStructureManager() : ?StructureManager{
		return $this->structures;
	}
}
