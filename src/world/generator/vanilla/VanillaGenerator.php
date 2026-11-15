<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use pocketmine\block\VanillaBlocks;
use pocketmine\loot\LootTables;
use pocketmine\Server;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\world\ChunkManager;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use pocketmine\world\generator\Generator;
use pocketmine\world\generator\InvalidGeneratorOptionsException;
use pocketmine\world\generator\populator\BubbleColumns;
use pocketmine\world\generator\structure\StructureLocators;
use pocketmine\world\generator\vanilla\biome\ClimateBiomeTree;
use pocketmine\world\generator\vanilla\biome\TheEndBiomeSource;
use pocketmine\world\generator\vanilla\carver\CarverSystem;
use pocketmine\world\generator\vanilla\compiler\KernelFactory;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\feature\FeatureDecorator;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use pocketmine\world\generator\vanilla\native\NativeBackendException;
use pocketmine\world\generator\vanilla\native\NativeTerrainClient;
use pocketmine\world\generator\vanilla\native\NativeTerrainFiller;
use pocketmine\world\generator\vanilla\native\VanillaNativeBackend;
use pocketmine\world\generator\vanilla\structure\placement\BedrockStructurePlacements;
use pocketmine\world\generator\vanilla\structure\StructureEnvironment;
use pocketmine\world\generator\vanilla\structure\StructureManager;
use pocketmine\world\generator\vanilla\structure\StructureRegistry;
use pocketmine\world\generator\vanilla\structure\template\StructureTemplates;
use pocketmine\world\generator\vanilla\structure\TerrainSampler;
use pocketmine\world\generator\vanilla\structure\VanillaStructureFinder;
use pocketmine\world\generator\vanilla\structure\VanillaStructureSpawnProvider;
use pocketmine\world\generator\vanilla\surface\MaterialSystem;
use pocketmine\world\World;
use pocketmine\YmlServerProperties;
use function array_key_first;
use function array_map;
use function count;
use function explode;
use function gc_mem_caches;
use function getcwd;
use function implode;
use function in_array;
use function is_string;
use function max;
use function memory_get_usage;
use function str_contains;
use function trim;

/**
 * Vanilla (1.18+) world generator: exact terrain shape, noise caves, aquifers and biomes, following the Java 26.3
 * worldgen data (Bedrock shares the overworld algorithm since 1.18). This class generates the Overworld; the
 * {@link VanillaNetherGenerator} and {@link VanillaEndGenerator} subclasses generate the other dimensions from the
 * "nether" and "end" noise settings.
 *
 * Includes the material rules (surface blocks, bedrock, deepslate, ore veins), the carvers (old-style caves and
 * canyons), the decoration features (ores, trees, vegetation, lakes... read from the vanilla placed/configured
 * feature data, placed like Java Edition for the same seed, see FeatureDecorator) and the structures (villages,
 * temples, ruins... see StructureManager and STRUCTURES-PLAN.md).
 *
 * Options (generator preset, ";"-separated key=value):
 *  - data=<dir>     vanilla worldgen data cache (default: <working directory>/vanilla-worldgen), filled automatically
 *                   from Mojang's Java Edition data generator on first use (see VanillaDataFetcher)
 *  - backend=auto|compiled|interpreted (auto: the native core when the server started it, else compiled)
 *  - carvers=on|off (default on; also off when the data cache has no carver definitions)
 *  - features=true|false (default true): decoration features
 *  - structures=true|false (default true): structures
 *  - structure-positions=bedrock|java (default bedrock): Bedrock's structure positions where known, or Java's
 *  - disabled-structures=<ids> comma-separated structure or structure set ids (or patterns like village_*) not to
 *                   generate, e.g. disabled-structures=mansion,minecraft:ancient_city
 *  - decoration=native|php (default native): with the native core, the decoration features it implements are placed
 *                   by it (same blocks as PHP, less CPU); php places every feature in PHP
 */
class VanillaGenerator extends Generator{
	public const DEFAULT_DATA_DIR = "vanilla-worldgen";
	private const CARVING_MASKS = 64;
	/** Freed memory kept by the allocator after a population above which it is given back (gc_mem_caches()). */
	private const FREE_MEMORY_SLACK = 16 * 1024 * 1024;

	/** Dimension generated by this class: the noise settings and biome source id ("overworld", "nether", "end"). */
	public const DIMENSION = "overworld";
	/**
	 * Async worker memory limit (memory.async-worker-hard-limit, MB) recommended for vanilla worlds: a generator worker
	 * holds the three dimension generators with their feature and structure data (~175 MB) and reaches ~240 MB while
	 * generating big structures. A lower limit is only warned about, never changed.
	 */
	public const RECOMMENDED_WORKER_MEMORY_LIMIT_MB = 384;
	private static bool $memoryLimitChecked = false;

	private TerrainFiller $filler;
	private NoiseRouter $router;
	/**
	 * @var int[]
	 * @phpstan-var list<int>
	 */
	private array $bedrockBiomes;
	/**
	 * Java biome names by biome index.
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $biomeNames;
	/** The End's biome source (the End has no multi-noise biomes). */
	private ?TheEndBiomeSource $endBiomes = null;
	/**
	 * PHP material rules and carvers, built on first use: with the native core they are only needed if it fails
	 * (it applies both itself), and they hold ~40 MB per generator.
	 */
	private ?MaterialSystem $materials = null;
	private ?CarverSystem $carvers = null;
	private bool $carversEnabled;
	/** @phpstan-var \Closure() : void */
	private \Closure $phpStagesFactory;
	/**
	 * Carving masks of recently generated chunks (World::chunkHash), for features placed in carved blocks: unpacked, or
	 * still packed as the native core sent them (TerrainChunk::$carvingMaskRuns).
	 * @var true[][]|string[]
	 * @phpstan-var array<int, array<int, true>|string>
	 */
	private array $carvingMasks = [];
	/**
	 * Block state ids per palette of native results (keyed by the palette names).
	 * @var int[][]
	 * @phpstan-var array<string, list<int>>
	 */
	private array $paletteStates = [];
	/**
	 * Block state id per material palette id.
	 * @var int[]|null
	 * @phpstan-var array<int, int>|null
	 */
	private ?array $states = null;
	/** Whether the height classes were given to the native core (see sendHeightClasses()). */
	private bool $heightClassesSent = false;

	private WorldgenData $data;
	private ClimateBiomeTree $tree;
	private bool $featuresEnabled;
	private ?FeatureDecorator $decorator = null;
	private bool $nativeDecoration = true;
	private bool $structuresEnabled;
	private string $structurePositions;
	/**
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $disabledStructures;
	private ?StructureManager $structureManager = null;

	/**
	 * @throws InvalidGeneratorOptionsException
	 */
	public function __construct(int $seed, string $preset){
		parent::__construct($seed, $preset);
		$options = self::parseOptions($preset);
		$dataDir = self::dataDirectory($options);
		$dimension = static::DIMENSION;
		try{
			$data = WorldgenData::shared($dataDir);
			$this->router = new NoiseRouter($data, $seed, $dimension);
			if($dimension === "end"){
				//every multi-noise lookup (material rules, carvers) gets the_end, the real biomes come from the End source
				$tree = ClimateBiomeTree::single(TheEndBiomeSource::NAMES[TheEndBiomeSource::THE_END]);
				$this->endBiomes = new TheEndBiomeSource($this->router->erosion);
				$this->biomeNames = TheEndBiomeSource::NAMES;
			}else{
				$tree = ClimateBiomeTree::fromData($data, $dimension);
				$this->biomeNames = $tree->getBiomeNames();
			}
			$this->data = $data;
			$this->tree = $tree;
		}catch(\RuntimeException $e){
			throw new InvalidGeneratorOptionsException("Vanilla generator: " . $e->getMessage(), 0, $e);
		}
		$this->bedrockBiomes = array_map(fn(string $name) : int => BiomeMapping::toBedrock($name), $this->biomeNames);
		$backend = $options["backend"] ?? "auto";
		$this->featuresEnabled = ($options["features"] ?? "true") !== "false";
		$this->nativeDecoration = ($options["decoration"] ?? "native") !== "php";
		$this->structuresEnabled = !in_array($options["structures"] ?? "true", ["false", "off", "0"], true);
		$this->structurePositions = ($options["structure-positions"] ?? BedrockStructurePlacements::MODE_BEDROCK) === BedrockStructurePlacements::MODE_JAVA ? BedrockStructurePlacements::MODE_JAVA : BedrockStructurePlacements::MODE_BEDROCK;
		$disabled = [];
		foreach(explode(",", $options["disabled-structures"] ?? "", 256) as $id){
			$id = trim($id);
			if($id !== ""){
				$disabled[] = $id;
			}
		}
		$this->disabledStructures = $disabled;
		$router = $this->router;
		$compiled = fn() : TerrainFiller => new CompiledTerrainFiller($router, $tree, $dataDir . "/compiled");
		$this->filler = match($backend){
			"interpreted" => new InterpretedTerrainFiller($router, $tree),
			"compiled" => $compiled(),
			default => NativeTerrainFiller::connect($dataDir, $seed, $tree->getBiomeNames(), $router->minY, $router->height, $compiled,
				NativeTerrainClient::FLAG_PACKED_SECTIONS | (($options["carvers"] ?? "on") === "off" ? NativeTerrainClient::FLAG_MATERIALS : NativeTerrainClient::FLAG_MATERIALS | NativeTerrainClient::FLAG_CARVERS) |
				//the End's biomes come from the PHP End biome source
				($dimension !== "end" ? NativeTerrainClient::FLAG_PACKED_BIOMES : 0), $dimension, $this->bedrockBiomes) ?? $compiled()
		};
		$this->carversEnabled = ($options["carvers"] ?? "on") !== "off";
		$filler = $this->filler;
		$this->phpStagesFactory = function() use ($filler, $backend, $router, $data, $tree, $seed, $dataDir, $dimension) : void{
			//the terrain kernel samples the biomes around chunks faster; the native core does not provide one
			$kernel = $filler instanceof CompiledTerrainFiller ? $filler->getKernel() : ($backend === "interpreted" ? null : KernelFactory::create($router, $dataDir . "/compiled"));
			$this->materials = new MaterialSystem($data, $router, $tree, $seed, $backend === "interpreted" ? null : $dataDir . "/compiled", $kernel, $dimension);
			$this->carvers = $this->carversEnabled ? CarverSystem::create($data, $router, $tree, $seed, $this->materials, $kernel) : null;
		};
		if(!$this->filler instanceof NativeTerrainFiller){
			try{
				$this->materials();
			}catch(\RuntimeException $e){
				throw new InvalidGeneratorOptionsException("Vanilla generator: " . $e->getMessage(), 0, $e);
			}
		}
	}

	/**
	 * The PHP material rules (and carvers), built the first time a chunk needs them.
	 */
	private function materials() : MaterialSystem{
		if($this->materials === null){
			($this->phpStagesFactory)();
		}
		return $this->materials ?? throw new AssumptionFailedError("Material system not built");
	}

	/**
	 * @return string[]
	 * @phpstan-return array<string, string>
	 */
	public static function parseOptions(string $preset) : array{
		$options = [];
		foreach(explode(";", $preset, 64) as $part){
			$part = trim($part);
			if($part === ""){
				continue;
			}
			if(!str_contains($part, "=")){
				throw new InvalidGeneratorOptionsException("Invalid vanilla generator option \"$part\" (expected key=value)");
			}
			[$key, $value] = explode("=", $part, 2);
			$options[trim($key)] = trim($value);
		}
		return $options;
	}

	/**
	 * @param string[] $options
	 * @phpstan-param array<string, string> $options
	 */
	public static function dataDirectory(array $options) : string{
		return $options["data"] ?? ((getcwd() !== false ? getcwd() : ".") . "/" . self::DEFAULT_DATA_DIR);
	}

	/**
	 * Preset validator for GeneratorManager (main thread): checks the options and makes sure the vanilla data is ready,
	 * fetching it automatically if needed (see VanillaDataManager).
	 */
	public static function validatePreset(string $preset) : ?InvalidGeneratorOptionsException{
		try{
			$dir = self::dataDirectory(self::parseOptions($preset));
		}catch(InvalidGeneratorOptionsException $e){
			return $e;
		}
		$error = VanillaDataManager::ensureReady($dir);
		if($error === null){
			//the tiles of generated blocks (dungeon chests, suspicious sand...) roll their loot from this data
			LootTables::setDefaultDataRoot($dir);
		}
		return $error !== null ? new InvalidGeneratorOptionsException($error) : null;
	}

	/**
	 * Main thread: starts the native terrain core for the worker threads, if the server is configured to use it.
	 * Called when a vanilla world is loaded or created; does nothing after the first call.
	 */
	public static function prepareNativeBackend() : void{
		VanillaNativeBackend::prepareForServer();
		//witch huts and pillager outposts for the natural mob spawner
		VanillaStructureSpawnProvider::register();
		self::checkWorkerMemoryLimit();
		//locate structure (only if nobody registered another locator for these generators)
		foreach([
			"vanilla" => VanillaStructureFinder::DIMENSION_OVERWORLD,
			"vanilla_nether" => VanillaStructureFinder::DIMENSION_NETHER,
			"vanilla_end" => VanillaStructureFinder::DIMENSION_END
		] as $name => $dimension){
			if(!StructureLocators::hasGeneratorLocator($name)){
				StructureLocators::registerForGenerator($name, new VanillaStructureFinder($dimension));
			}
		}
	}

	/**
	 * Warns once (main thread) if the async workers' memory limit is below {@link RECOMMENDED_WORKER_MEMORY_LIMIT_MB}.
	 */
	private static function checkWorkerMemoryLimit() : void{
		if(self::$memoryLimitChecked){
			return;
		}
		self::$memoryLimitChecked = true;
		try{
			$limit = Server::getInstance()->getConfigGroup()->getPropertyInt(YmlServerProperties::MEMORY_ASYNC_WORKER_HARD_LIMIT, 256);
		}catch(\RuntimeException){
			return; //not on a server thread (tools, tests)
		}
		if($limit > 0 && $limit < self::RECOMMENDED_WORKER_MEMORY_LIMIT_MB){
			\GlobalLogger::get()->warning("Vanilla generator: memory.async-worker-hard-limit is $limit MB; generator workers use up to ~240 MB with structures. Set it to " . self::RECOMMENDED_WORKER_MEMORY_LIMIT_MB . " (or 0, no limit) in pocketmine.yml to avoid worker crashes.");
		}
	}

	public function generateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{
		$chunk = $world->getChunk($chunkX, $chunkZ) ?? throw new \InvalidArgumentException("Chunk $chunkX $chunkZ does not yet exist");
		//terrain adaptation of nearby structures (vanilla's Beardifier, part of the final density)
		$terrain = $this->filler->fill($chunkX, $chunkZ, $this->getStructureManager()?->getBeardifier($chunkX, $chunkZ));
		if($terrain->stage < ($this->carversEnabled ? TerrainChunk::STAGE_CARVED : TerrainChunk::STAGE_MATERIALS) || $terrain->biomeSections === null){
			//the PHP stages and the biome loop of writeChunk() read the cells
			$terrain->unpackBiomes();
		}
		if($this->endBiomes !== null){
			$terrain->biomes = $this->endBiomes->fillChunk($chunkX, $chunkZ, $terrain->height >> 2);
			$terrain->biomeSections = null;
		}
		if($terrain->stage < TerrainChunk::STAGE_MATERIALS){
			$this->materials()->apply($terrain);
			$terrain->stage = TerrainChunk::STAGE_MATERIALS;
			$terrain->terrainHeights = null;
		}
		if($terrain->stage < TerrainChunk::STAGE_CARVED && $this->carversEnabled){
			$this->materials();
			if($this->carvers !== null){
				$this->carvers->apply($terrain);
				$terrain->stage = TerrainChunk::STAGE_CARVED;
				$terrain->terrainHeights = null;
			}
		}
		$hash = World::chunkHash($chunkX, $chunkZ);
		unset($this->carvingMasks[$hash]);
		$this->carvingMasks[$hash] = $terrain->carvingMaskRuns ?? $terrain->carvingMask;
		if(count($this->carvingMasks) > self::CARVING_MASKS){
			unset($this->carvingMasks[array_key_first($this->carvingMasks)]);
		}
		$this->writeChunk($chunk, $terrain);
		if($this->featuresEnabled || $this->structuresEnabled){
			$decorator = $this->getDecorator();
			if($terrain->terrainHeights !== null){
				//computed by the native core from the same materials (see sendHeightClasses())
				$decorator->recordTerrainHeights($chunkX, $chunkZ, $terrain->terrainHeights);
			}else{
				$decorator->recordTerrain($chunkX, $chunkZ, $chunk);
				if(!$this->heightClassesSent && $terrain->palette !== null && $terrain->biomeSections !== null){
					$this->sendHeightClasses($decorator, $terrain->palette);
				}
			}
		}
	}

	/**
	 * Lets the native core compute the terrain heights of the next chunks (FeatureDecorator::recordTerrain()) from the
	 * height class of every material of its palette: the class of the block the material becomes in the chunk.
	 *
	 * @param string[] $palette
	 * @phpstan-param list<string> $palette
	 */
	private function sendHeightClasses(FeatureDecorator $decorator, array $palette) : void{
		$this->heightClassesSent = true;
		$client = $this->filler instanceof NativeTerrainFiller ? $this->filler->getClient() : null;
		if($client === null || $client->palette !== $palette || !$client->hasFlag(NativeTerrainClient::FLAG_PACKED_BIOMES)){
			return;
		}
		$states = $this->paletteStates[implode(",", $palette)] ?? null;
		if($states === null){
			return;
		}
		$air = VanillaBlocks::AIR()->getStateId();
		$classes = [];
		foreach($palette as $material => $_){
			//the same block as writeChunk() puts in the chunk
			$classes[] = $decorator->terrainHeightClass($material === Aquifer::MATERIAL_AIR ? $air : $states[$material]);
		}
		try{
			$client->sendHeightClasses($classes);
		}catch(NativeBackendException){
			//the next fill() notices the closed connection and switches to PHP
		}
	}

	/**
	 * Blocks of a recently generated chunk visited by the carvers (index ((x << 4) | z) * height + (y - minY)), or null
	 * if this generator did not generate the chunk recently.
	 * @return true[]|null
	 * @phpstan-return array<int, true>|null
	 */
	public function getCarvingMask(int $chunkX, int $chunkZ) : ?array{
		$mask = $this->carvingMasks[World::chunkHash($chunkX, $chunkZ)] ?? null;
		return is_string($mask) ? TerrainChunk::unpackCarvingMask($mask, 256 * $this->router->height) : $mask;
	}

	public function populateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{
		if(!$this->featuresEnabled && !$this->structuresEnabled){
			$this->fillBubbleColumns($world, $chunkX, $chunkZ);
			return;
		}
		$this->getDecorator()->decorate($world, $chunkX, $chunkZ);
		$this->fillBubbleColumns($world, $chunkX, $chunkZ);
		//structure starts and terrain fills allocate big transient arrays: give the freed memory back so the worker's
		//real usage (what its 256 MB limit checks) does not creep up with fragmentation
		if(memory_get_usage(true) - memory_get_usage() > self::FREE_MEMORY_SLACK){
			gc_mem_caches();
		}
	}

	/**
	 * Vanilla post-processes the block above soul sand and magma, which turns the water there into a bubble column
	 * once the chunk is ticking; written directly here (see {@link BubbleColumns}).
	 */
	private function fillBubbleColumns(ChunkManager $world, int $chunkX, int $chunkZ) : void{
		$chunk = $world->getChunk($chunkX, $chunkZ);
		if($chunk !== null){
			BubbleColumns::fillChunk($chunk, $world->getMinY(), $world->getMaxY());
		}
	}

	/**
	 * Biome index (climate tree / End biome names) of a noise biome cell, by quart coordinates.
	 * @phpstan-return \Closure(int, int, int) : int
	 */
	private function biomeSampler() : \Closure{
		$router = $this->router;
		$tree = $this->tree;
		$endBiomes = $this->endBiomes;
		return $endBiomes !== null ? fn(int $qx, int $qy, int $qz) : int => $endBiomes->getNoiseBiome($qx, $qy, $qz) : function(int $qx, int $qy, int $qz) use ($router, $tree) : int{
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
	}

	/**
	 * Height that features and structures can build in: the End noise only covers y 0-127, but the dimension is 256
	 * blocks high (Java's dimension type) and End cities reach y 170. The Nether keeps its noise height (its features
	 * matched Java with it; nothing generates above the bedrock roof).
	 */
	private function levelHeight() : int{
		return static::DIMENSION === "end" ? max($this->router->height, 256) : $this->router->height;
	}

	public function getDecorator() : FeatureDecorator{
		if($this->decorator === null){
			$router = $this->router;
			$endBiomes = $this->endBiomes;
			$this->decorator = new FeatureDecorator(
				$this->data,
				$this->seed,
				$router->minY,
				$this->levelHeight(),
				$router->seaLevel,
				$this->biomeNames,
				$this->biomeSampler(),
				fn(string $message) => \GlobalLogger::get()->debug("[Vanilla features] " . $message),
				$endBiomes !== null ? TheEndBiomeSource::NAMES : FeatureRegistry::multiNoiseBiomes($this->data, static::DIMENSION)
			);
			$this->decorator->featuresEnabled = $this->featuresEnabled;
			if($this->nativeDecoration && $this->featuresEnabled && static::DIMENSION !== "end" && $this->filler instanceof NativeTerrainFiller){
				$client = $this->filler->getClient();
				if($client !== null){
					$this->decorator->enableNative($client);
				}
			}
			$this->decorator->regionRandomFactory = $router->random->fromHashOf("minecraft:worldgen_region_random")->forkPositional();
			if($this->structuresEnabled){
				$this->decorator->structures = $this->getStructureManager();
			}
		}
		return $this->decorator;
	}

	/**
	 * The structures of this generator (positions, starts, placement), or null if they are disabled
	 * (structures=false) or the data cache has no structure data.
	 */
	public function getStructureManager() : ?StructureManager{
		if(!$this->structuresEnabled){
			return null;
		}
		if($this->structureManager === null){
			$blocks = JavaBlocks::get($this->data->getRoot());
			$logger = fn(string $message) => \GlobalLogger::get()->debug("[Vanilla structures] " . $message);
			try{
				$registry = StructureRegistry::shared($this->data, $blocks, $logger);
			}catch(\RuntimeException $e){
				\GlobalLogger::get()->warning("Vanilla structures disabled: " . $e->getMessage());
				$this->structuresEnabled = false;
				return null;
			}
			$filler = $this->filler;
			$router = $this->router;
			$sampler = $this->biomeSampler();
			$names = $this->biomeNames;
			$env = new StructureEnvironment(
				$this->seed,
				$router->minY,
				$this->levelHeight(),
				$router->seaLevel,
				static::DIMENSION,
				new TerrainSampler(fn(int $chunkX, int $chunkZ) : TerrainChunk => $filler->fill($chunkX, $chunkZ), $router->minY, $router->height),
				fn(int $x, int $y, int $z) : string => $names[$sampler($x >> 2, $y >> 2, $z >> 2)] ?? "minecraft:plains",
				$registry,
				StructureTemplates::shared($this->data, $blocks),
				$blocks
			);
			$seaLevel = $router->seaLevel;
			$env->setColdEnoughToSnow(function(string $biome, int $x, int $y, int $z) use ($seaLevel) : bool{
				$registry = $this->getDecorator()->registry;
				$index = $registry->biomeIndex[$biome] ?? null;
				return $index !== null && !$registry->climate()->warmEnoughToRain($index, $x, $y, $z, $seaLevel);
			});
			$this->structureManager = new StructureManager($env, $this->structurePositions, $this->disabledStructures, $names);
		}
		return $this->structureManager;
	}

	private function writeChunk(Chunk $chunk, TerrainChunk $terrain) : void{
		$air = VanillaBlocks::AIR()->getStateId();
		if($terrain->palette !== null){
			$states = $this->paletteStates[implode(",", $terrain->palette)] ??= array_map(fn(string $id) : int => JavaBlockMapping::toBlock($id)->getStateId(), $terrain->palette);
		}else{
			$states = $this->states ??= array_map(fn(string $id) : int => JavaBlockMapping::toBlock($id)->getStateId(), $this->materials()->palette->getNames());
		}

		$minY = $terrain->minY;
		$height = $terrain->height;
		$materials = $terrain->materials;
		$sections = $terrain->sections;
		$biomes = $terrain->biomes;
		$biomeSections = $terrain->biomeSections;
		$quartsY = $height >> 2;

		for($sy = $minY >> 4, $maxSy = ($minY + $height - 1) >> 4; $sy <= $maxSy; ++$sy){
			$sectionBottom = $sy << 4;
			$ly0 = $sectionBottom - $minY;

			//blocks
			if($sections !== null){
				//packed by the native core: the palette of material ids becomes a palette of block states
				$section = $sections[$sy - ($minY >> 4)] ?? null;
				if($section === null){
					$blocks = null;
					$empty = true;
				}else{
					[$bits, $words, $palette] = $section;
					$blocks = PalettedBlockArray::fromData($bits, $words, array_map(fn(int $material) : int => $material === Aquifer::MATERIAL_AIR ? $air : $states[$material], $palette));
					$empty = false;
				}
			}else{
				$blocks = new PalettedBlockArray($air);
				$empty = true;
				for($x = 0; $x < 16; ++$x){
					for($z = 0; $z < 16; ++$z){
						$column = (($x << 4) | $z) * $height + $ly0;
						for($y = 0; $y < 16; ++$y){
							$material = $materials[$column + $y];
							if($material === Aquifer::MATERIAL_AIR){
								continue;
							}
							$blocks->set($x, $y, $z, $states[$material]);
							$empty = false;
						}
					}
				}
			}

			//biomes (one per 4x4x4 cell)
			$biomeSection = $biomeSections !== null ? ($biomeSections[$sy - ($minY >> 4)] ?? null) : null;
			if($biomeSection !== null){
				//packed by the native core exactly as the loop below fills the array
				[$bits, $words, $palette] = $biomeSection;
				$chunk->setSubChunk($sy, new SubChunk($air, $empty || $blocks === null ? [] : [$blocks], $bits === 0 ? new PalettedBlockArray($palette[0]) : PalettedBlockArray::fromData($bits, $words, $palette)));
				continue;
			}
			$q0 = ($sectionBottom - $minY) >> 2;
			$first = $biomes[$q0];
			$uniform = true;
			for($qx = 0; $qx < 4 && $uniform; ++$qx){
				for($qz = 0; $qz < 4 && $uniform; ++$qz){
					for($qy = 0; $qy < 4; ++$qy){
						if($biomes[(($qx << 2) | $qz) * $quartsY + $q0 + $qy] !== $first){
							$uniform = false;
							break;
						}
					}
				}
			}
			if($uniform){
				$biomeArray = new PalettedBlockArray($this->bedrockBiomes[$first]);
			}else{
				$biomeArray = new PalettedBlockArray($this->bedrockBiomes[$first]);
				for($x = 0; $x < 16; ++$x){
					for($z = 0; $z < 16; ++$z){
						$base = ((($x >> 2) << 2) | ($z >> 2)) * $quartsY + $q0;
						for($y = 0; $y < 16; ++$y){
							$biomeArray->set($x, $y, $z, $this->bedrockBiomes[$biomes[$base + ($y >> 2)]]);
						}
					}
				}
			}
			$chunk->setSubChunk($sy, new SubChunk($air, $empty || $blocks === null ? [] : [$blocks], $biomeArray));
		}
	}
}
