Warning: truncated output (original token count: 34268)
Total output lines: 3955

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

/**
 * All World related classes are here, like Generators, Populators, Noise, ...
 */
namespace pocketmine\world;

use DaveRandom\CallbackValidator\BuiltInTypes;
use DaveRandom\CallbackValidator\CallbackType;
use DaveRandom\CallbackValidator\ParameterType;
use DaveRandom\CallbackValidator\ReturnType;
use pocketmine\block\Air;
use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\tile\Spawnable;
use pocketmine\block\tile\Tile;
use pocketmine\block\tile\TileFactory;
use pocketmine\block\UnknownBlock;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\data\bedrock\block\BlockStateDeserializeException;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\ai\AiBudget;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Location;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\entity\object\ExperienceOrb;
use pocketmine\entity\object\ItemEntity;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\BlockUpdateEvent;
use pocketmine\event\entity\ItemEntityDropEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\world\ChunkLoadEvent;
use pocketmine\event\world\ChunkPopulateEvent;
use pocketmine\event\world\ChunkUnloadEvent;
use pocketmine\event\world\SpawnChangeEvent;
use pocketmine\event\world\WorldDifficultyChangeEvent;
use pocketmine\event\world\WorldDisplayNameChangeEvent;
use pocketmine\event\world\WorldParticleEvent;
use pocketmine\event\world\WorldSaveEvent;
use pocketmine\event\world\WorldSoundEvent;
use pocketmine\item\Item;
use pocketmine\item\ItemUseResult;
use pocketmine\item\LegacyStringToItemParser;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\player\Player;
use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
use pocketmine\scheduler\AsyncPool;
use pocketmine\Server;
use pocketmine\ServerConfigGroup;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Limits;
use pocketmine\utils\ReversePriorityQueue;
use pocketmine\utils\Utils;
use pocketmine\world\biome\Biome;
use pocketmine\world\biome\BiomeRegistry;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\ChunkData;
use pocketmine\world\format\io\exception\CorruptedChunkException;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\format\io\WritableWorldProvider;
use pocketmine\world\format\LightArray;
use pocketmine\world\format\SubChunk;
use pocketmine\world\generator\executor\AsyncGeneratorExecutor;
use pocketmine\world\generator\executor\GeneratorExecutor;
use pocketmine\world\generator\executor\GeneratorExecutorSetupParameters;
use pocketmine\world\generator\executor\SyncGeneratorExecutor;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\generator\PopulationTask;
use pocketmine\world\light\BlockLightUpdate;
use pocketmine\world\light\LightPopulationTask;
use pocketmine\world\light\SkyLightUpdate;
use pocketmine\world\particle\BlockBreakParticle;
use pocketmine\world\particle\BlockParticle;
use pocketmine\world\particle\ItemParticle;
use pocketmine\world\particle\Particle;
use pocketmine\world\particle\ProtocolParticle;
use pocketmine\world\sound\BlockPlaceSound;
use pocketmine\world\sound\BlockSound;
use pocketmine\world\sound\ProtocolSound;
use pocketmine\world\sound\Sound;
use pocketmine\world\utils\SubChunkExplorer;
use pocketmine\world\weather\Weather;
use pocketmine\world\mobs\NaturalMobSpawner;
use pocketmine\world\mobs\spawn\MobSpawningSettings;
use pocketmine\YmlServerProperties;
use function abs;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_sum;
use function array_values;
use function assert;
use function cos;
use function count;
use function floor;
use function get_class;
use function gettype;
use function is_a;
use function is_object;
use function max;
use function microtime;
use function min;
use function morton2d_decode;
use function morton2d_encode;
use function morton3d_decode;
use function morton3d_encode;
use function mt_rand;
use function preg_match;
use function spl_object_id;
use function strtolower;
use function trim;
use const M_PI;
use const PHP_INT_MAX;
use const PHP_INT_MIN;

#include <rules/World.h>

/**
 * @phpstan-type ChunkPosHash int
 * @phpstan-type TypeConverterHash int|null
 * @phpstan-type BlockPosHash int
 * @phpstan-type ChunkBlockPosHash int
 */
class World implements ChunkManager{

	private static int $worldIdCounter = 1;

	public const Y_MAX = 320;
	public const Y_MIN = -64;

	public const TIME_DAY = 1000;
	public const TIME_NOON = 6000;
	public const TIME_SUNSET = 12000;
	public const TIME_NIGHT = 13000;
	public const TIME_MIDNIGHT = 18000;
	public const TIME_SUNRISE = 23000;

	public const TIME_FULL = 24000;

	public const DIFFICULTY_PEACEFUL = 0;
	public const DIFFICULTY_EASY = 1;
	public const DIFFICULTY_NORMAL = 2;
	public const DIFFICULTY_HARD = 3;

	public const DEFAULT_TICKED_BLOCKS_PER_SUBCHUNK_PER_TICK = 3;

	//TODO: this could probably do with being a lot bigger
	private const BLOCK_CACHE_SIZE_CAP = 2048;

	/**
	 * @var Player[] entity runtime ID => Player
	 * @phpstan-var array<int, Player>
	 */
	private array $players = [];

	/**
	 * @var Entity[] entity runtime ID => Entity
	 * @phpstan-var array<int, Entity>
	 */
	private array $entities = [];
	/**
	 * @var Vector3[] entity runtime ID => Vector3
	 * @phpstan-var array<int, Vector3>
	 */
	private array $entityLastKnownPositions = [];

	/**
	 * @var Entity[][] chunkHash => [entity runtime ID => Entity]
	 * @phpstan-var array<ChunkPosHash, array<int, Entity>>
	 */
	private array $entitiesByChunk = [];

	/**
	 * @var Entity[] entity runtime ID => Entity
	 * @phpstan-var array<int, Entity>
	 */
	public array $updateEntities = [];

	private bool $inDynamicStateRecalculation = false;
	/**
	 * @var Block[][] chunkHash => [relativeBlockHash => Block]
	 * @phpstan-var array<ChunkPosHash, array<ChunkBlockPosHash, Block>>
	 */
	private array $blockCache = [];
	private int $blockCacheSize = 0;
	/**
	 * @var AxisAlignedBB[][][] chunkHash => [relativeBlockHash => AxisAlignedBB[]]
	 * @phpstan-var array<ChunkPosHash, array<ChunkBlockPosHash, list<AxisAlignedBB>>>
	 */
	private array $blockCollisionBoxCache = [];

	private int $sendTimeTicker = 0;

	private int $worldId;

	private int $providerGarbageCollectionTicker = 0;

	private int $minY;
	private int $maxY;
	private int $damageY;

	/**
	 * @var ChunkTicker[][] chunkHash => [spl_object_id => ChunkTicker]
	 * @phpstan-var array<ChunkPosHash, array<int, ChunkTicker>>
	 */
	private array $registeredTickingChunks = [];

	/**
	 * Set of chunks which are definitely ready for ticking.
	 *
	 * @var int[]
	 * @phpstan-var array<ChunkPosHash, ChunkPosHash>
	 */
	private array $validTickingChunks = [];

	/**
	 * Set of chunks which might be ready for ticking. These will be checked at the next tick.
	 * @var int[]
	 * @phpstan-var array<ChunkPosHash, ChunkPosHash>
	 */
	private array $recheckTickingChunks = [];

	/**
	 * @var ChunkLoader[][] chunkHash => [spl_object_id => ChunkLoader]
	 * @phpstan-var array<ChunkPosHash, array<int, ChunkLoader>>
	 */
	private array $chunkLoaders = [];

	/**
	 * @var ChunkListener[][] chunkHash => [spl_object_id => ChunkListener]
	 * @phpstan-var array<ChunkPosHash, array<int, ChunkListener>>
	 */
	private array $chunkListeners = [];
	/**
	 * @var Player[][] chunkHash => [spl_object_id => Player]
	 * @phpstan-var array<ChunkPosHash, array<int, Player>>
	 */
	private array $playerChunkListeners = [];

	/**
	 * @var ClientboundPacket[][] chunkHash => ClientboundPacket[]
	 * @phpstan-var array<ChunkPosHash, list<ClientboundPacket>>
	 */
	private array $packetBuffersByChunk = [];
	/**
	 * @var \Closure[][] chunkHash => Closure[]
	 * @phpstan-var array<ChunkPosHash, list<\Closure(TypeConverter) : ClientboundPacket[]>>
	 */
	private array $packetBuffersByChunkTypeConverter = [];

	/**
	 * @var float[] chunkHash => timestamp of request
	 * @phpstan-var array<ChunkPosHash, float>
	 */
	private array $unloadQueue = [];

	private int $time;
	public bool $stopTime = false;

	private float $sunAnglePercentage = 0.0;
	private int $skyLightReduction = 0;

	private string $folderName;
	private string $displayName;

	/**
	 * @var Chunk[]
	 * @phpstan-var array<ChunkPosHash, Chunk>
	 */
	private array $chunks = [];

	/**
	 * @var true[]
	 * @phpstan-var array<ChunkPosHash, true>
	 */
	private array $knownUngeneratedChunks = [];

	/**
	 * @var Vector3[][] chunkHash => [relativeBlockHash => Vector3]
	 * @phpstan-var array<ChunkPosHash, array<ChunkBlockPosHash, Vector3>>
	 */
	private array $changedBlocks = [];

	/** @phpstan-var ReversePriorityQueue<int, Vector3> */
	private ReversePriorityQueue $scheduledBlockUpdateQueue;
	/**
	 * @var int[] blockHash => absolute due server tick
	 * @phpstan-var array<BlockPosHash, int>
	 */
	private array $scheduledBlockUpdateQueueIndex = [];

	/** @phpstan-var \SplQueue<int> */
	private \SplQueue $neighbourBlockUpdateQueue;
	/**
	 * @var true[] blockhash => dummy
	 * @phpstan-var array<BlockPosHash, true>
	 */
	private array $neighbourBlockUpdateQueueIndex = [];

	/**
	 * @var bool[] chunkHash => isValid
	 * @phpstan-var array<ChunkPosHash, bool>
	 */
	private array $activeChunkPopulationTasks = [];
	private bool $chunkPopulationBlocked = false;
	/**
	 * @var ChunkLockId[]
	 * @phpstan-var array<ChunkPosHash, ChunkLockId>
	 */
	private array $chunkLock = [];
	private int $maxConcurrentChunkPopulationTasks = 2;
	/**
	 * @var PromiseResolver[] chunkHash => promise
	 * @phpstan-var array<ChunkPosHash, PromiseResolver<Chunk>>
	 */
	private array $chunkPopulationRequestMap = [];
	/**
	 * @var \SplQueue (queue of chunkHashes)
	 * @phpstan-var \SplQueue<ChunkPosHash>
	 */
	private \SplQueue $chunkPopulationRequestQueue;
	/**
	 * @var true[] chunkHash => dummy
	 * @phpstan-var array<ChunkPosHash, true>
	 */
	private array $chunkPopulationRequestQueueIndex = [];

	private readonly GeneratorExecutor $generatorExecutor;

	private bool $autoSave = true;

	private int $sleepTicks = 0;

	private int $chunkTickRadius;
	private int $tickedBlocksPerSubchunkPerTick = self::DEFAULT_TICKED_BLOCKS_PER_SUBCHUNK_PER_TICK;
	/**
	 * @var true[]
	 * @phpstan-var array<int, true>
	 */
	private array $randomTickBlocks = [];

	public WorldTimings $timings;

	public float $tickRateTime = 0;

	private bool $doingTick = false;

	private bool $unloaded = false;
	/**
	 * @var \Closure[]
	 * @phpstan-var array<int, \Closure() : void>
	 */
	private array $unloadCallbacks = [];

	private ?BlockLightUpdate $blockLightUpdate = null;
	private ?SkyLightUpdate $skyLightUpdate = null;

	private \Logger $logger;

	private RuntimeBlockStateRegistry $blockStateRegistry;

	private Weather $weather;
	private NaturalMobSpawner $naturalMobSpawner;

	/**
	 * @phpstan-return ChunkPosHash
	 */
	public static function chunkHash(int $x, int $z) : int{
		return morton2d_encode($x, $z);
	}

	private const MORTON3D_BIT_SIZE = 21;
	private const BLOCKHASH_Y_BITS = 9;
	private const BLOCKHASH_Y_PADDING = 64; //size (in blocks) of padding after both boundaries of the Y axis
	private const BLOCKHASH_Y_OFFSET = self::BLOCKHASH_Y_PADDING - self::Y_MIN;
	private const BLOCKHASH_Y_MASK = (1 << self::BLOCKHASH_Y_BITS) - 1;
	private const BLOCKHASH_XZ_MASK = (1 << self::MORTON3D_BIT_SIZE) - 1;
	private const BLOCKHASH_XZ_EXTRA_BITS = (self::MORTON3D_BIT_SIZE - self::BLOCKHASH_Y_BITS) >> 1;
	private const BLOCKHASH_XZ_EXTRA_MASK = (1 << self::BLOCKHASH_XZ_EXTRA_BITS) - 1;
	private const BLOCKHASH_XZ_SIGN_SHIFT = 64 - self::MORTON3D_BIT_SIZE - self::BLOCKHASH_XZ_EXTRA_BITS;
	private const BLOCKHASH_X_SHIFT = self::BLOCKHASH_Y_BITS;
	private const BLOCKHASH_Z_SHIFT = self::BLOCKHASH_X_SHIFT + self::BLOCKHASH_XZ_EXTRA_BITS;

	/**
	 * @phpstan-return BlockPosHash
	 */
	public static function blockHash(int $x, int $y, int $z) : int{
		$shiftedY = $y + self::BLOCKHASH_Y_OFFSET;
		if(($shiftedY & (~0 << self::BLOCKHASH_Y_BITS)) !== 0){
			throw new \InvalidArgumentException("Y coordinate $y is out of range!");
		}
		//morton3d gives us 21 bits on each axis, but the Y axis only requires 9
		//so we use the extra space on Y (12 bits) and add 6 extra bits from X and Z instead.
		//if we ever need more space for Y (e.g. due to expansion), take bits from X/Z to compensate.
		return morton3d_encode(
			$x & self::BLOCKHASH_XZ_MASK,
			($shiftedY /* & self::BLOCKHASH_Y_MASK */) |
				((($x >> self::MORTON3D_BIT_SIZE) & self::BLOCKHASH_XZ_EXTRA_MASK) << self::BLOCKHASH_X_SHIFT) |
				((($z >> self::MORTON3D_BIT_SIZE) & self::BLOCKHASH_XZ_EXTRA_MASK) << self::BLOCKHASH_Z_SHIFT),
			$z & self::BLOCKHASH_XZ_MASK
		);
	}

	/**
	 * Computes a small index relative to chunk base from the given coordinates.
	 */
	public static function chunkBlockHash(int $x, int $y, int $z) : int{
		return morton3d_encode($x, $y, $z);
	}

	/**
	 * @phpstan-param BlockPosHash $hash
	 * @phpstan-param-out int      $x
	 * @phpstan-param-out int      $y
	 * @phpstan-param-out int      $z
	 */
	public static function getBlockXYZ(int $hash, ?int &$x, ?int &$y, ?int &$z) : void{
		[$baseX, $baseY, $baseZ] = morton3d_decode($hash);

		$extraX = ((($baseY >> self::BLOCKHASH_X_SHIFT) & self::BLOCKHASH_XZ_EXTRA_MASK) << self::MORTON3D_BIT_SIZE);
		$extraZ = ((($baseY >> self::BLOCKHASH_Z_SHIFT) & self::BLOCKHASH_XZ_EXTRA_MASK) << self::MORTON3D_BIT_SIZE);

		$x = (($baseX & self::BLOCKHASH_XZ_MASK) | $extraX) << self::BLOCKHASH_XZ_SIGN_SHIFT >> self::BLOCKHASH_XZ_SIGN_SHIFT;
		$y = ($baseY & self::BLOCKHASH_Y_MASK) - self::BLOCKHASH_Y_OFFSET;
		$z = (($baseZ & self::BLOCKHASH_XZ_MASK) | $extraZ) << self::BLOCKHASH_XZ_SIGN_SHIFT >> self::BLOCKHASH_XZ_SIGN_SHIFT;
	}

	/**
	 * @phpstan-param ChunkPosHash $hash
	 * @phpstan-param-out int      $x
	 * @phpstan-param-out int      $z
	 */
	public static function getXZ(int $hash, ?int &$x, ?int &$z) : void{
		[$x, $z] = morton2d_decode($hash);
	}

	public static function getDifficultyFromString(string $str) : int{
		switch(strtolower(trim($str))){
			case "0":
			case "peaceful":
			case "p":
				return World::DIFFICULTY_PEACEFUL;

			case "1":
			case "easy":
			case "e":
				return World::DIFFICULTY_EASY;

			case "2":
			case "normal":
			case "n":
				return World::DIFFICULTY_NORMAL;

			case "3":
			case "hard":
			case "h":
				return World::DIFFICULTY_HARD;
		}

		return -1;
	}

	/**
	 * Init the default world data
	 */
	public function __construct(
		private Server $server,
		string $name, //TODO: this should be folderName (named arguments BC break)
		private WritableWorldProvider $provider,
		private AsyncPool $workerPool
	){
		$this->folderName = $name;
		$data = $provider->getWorldData();
		$defaultCoordinates = false;
		if($data instanceof \pocketmine\world\format\io\data\BaseNbtWorldData){
			$tag = $data->getCompoundTag()->getTag(GameRules::SHOW_COORDINATES);
			$defaultCoordinates = $tag instanceof ByteTag && $tag->getValue() !== 0;
		}
		$this->gameRules = new GameRules($provider->getPath() . "/gamerules.yml", $defaultCoordinates);
		$this->weather = new Weather($this, $data);
		$this->worldId = self::$worldIdCounter++;

		$this->displayName = $this->provider->getWorldData()->getName();
		$this->logger = new \PrefixedLogger($server->getLogger(), "World: $this->displayName");

		$this->blockStateRegistry = RuntimeBlockStateRegistry::getInstance();
		$this->minY = $this->provider->getWorldMinY();
		$this->maxY = $this->provider->getWorldMaxY();

		$cfg = $this->server->getConfigGroup();
		$this->damageY = $cfg->getPropertyInt(YmlServerProperties::LEVEL_SETTINGS_MIN_Y, 0);

		$this->server->getLogger()->info($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_level_preparing($this->displayName)));
		$generator = GeneratorManager::getInstance()->getGenerator($this->provider->getWorldData()->getGenerator()) ??
			throw new AssumptionFailedError("WorldManager should already have checked that the generator exists");
		$generator->validateGeneratorOptions($this->provider->getWorldData()->getGeneratorOptions());

		$executorSetupParameters = new GeneratorExecutorSetupParameters(
			worldMinY: $this->minY,
			worldMaxY: $this->maxY,
			generatorSeed: $this->getSeed(),
			generatorClass: $generator->getGeneratorClass(),
			generatorSettings: $this->provider->getWorldData()->getGeneratorOptions()
		);
		$this->generatorExecutor = $generator->isFast() ?
			new SyncGeneratorExecutor($executorSetupParameters) :
			new AsyncGeneratorExecutor(
				$this->logger,
				$this->workerPool,
				$executorSetupParameters,
				$this->worldId
			);

		$this->chunkPopulationRequestQueue = new \SplQueue();
		$this->addOnUnloadCallback(function() : void{
			$this->logger->debug("Cancelling unfulfilled generation requests");

			foreach($this->chunkPopulationRequestMap as $chunkHash => $promise){
				$promise->reject();
				unset($this->chunkPopulationRequestMap[$chunkHash]);
			}
			if(count($this->chunkPopulationRequestMap) !== 0){
				//TODO: this might actually get hit because generation rejection callbacks might try to schedule new
				//requests, and we can't prevent that right now because there's no way to detect "unloading" state
				throw new AssumptionFailedError("New generation requests scheduled during unload");
			}
		});

		$this->scheduledBlockUpdateQueue = new ReversePriorityQueue();
		$this->scheduledBlockUpdateQueue->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);

		$this->neighbourBlockUpdateQueue = new \SplQueue();

		$this->time = $this->provider->getWorldData()->getTime();

		$this->chunkTickRadius = min($this->server->getViewDistance(), max(0, $cfg->getPropertyInt(YmlServerProperties::CHUNK_TICKING_TICK_RADIUS, 4)));
		if($cfg->getPropertyInt("chunk-ticking.per-tick", 40) <= 0){
			//TODO: this needs l10n
			$this->logger->warning("\"chunk-ticking.per-tick\" setting is deprecated, but you've used it to disable chunk ticking. Set \"chunk-ticking.tick-radius\" to 0 in \"pocketmine.yml\" instead.");
			$this->chunkTickRadius = 0;
		}
		$this->tickedBlocksPerSubchunkPerTick = $cfg->getPropertyInt(YmlServerProperties::CHUNK_TICKING_BLOCKS_PER_SUBCHUNK_PER_TICK, self::DEFAULT_TICKED_BLOCKS_PER_SUBCHUNK_PER_TICK);
		$this->maxConcurrentChunkPopulationTasks = $cfg->getPropertyInt(YmlServerProperties::CHUNK_GENERATION_POPULATION_QUEUE_SIZE, 2);

		$mobSettings = MobSpawningSettings::fromConfig($cfg, $this->folderName, $this->chunkTickRadius);
		AiBudget::initialize(
			$cfg->getPropertyInt("mobs.max-ai-updates-per-tick", 200),
			$cfg->getPropertyInt("mobs.max-path-searches-per-tick", 10),
			$cfg->getPropertyInt("mobs.max-path-nodes", 400),
			$mobSettings->aiEnabled
		);
		AiBudget::setDisabledWorlds($mobSettings->disabledWorlds);
		$this->naturalMobSpawner = new NaturalMobSpawner($this, $mobSettings);
		foreach($mobSettings->warnings as $warning){
			$this->logger->warning("[Mob spawning] $warning");
		}

		$this->initRandomTickBlocksFromConfig($cfg);

		$this->timings = new WorldTimings($this);
	}

	/** Preserve natural-spawn despawn tracking when an actor converts into another type. */
	public function transferNaturalMob(Living $from, Living $to) : void{
		$this->naturalMobSpawner->transferNaturalMob($from, $to);
	}

	private function initRandomTickBlocksFromConfig(ServerConfigGroup $cfg) : void{
		$dontTickBlocks = [];
		$parser = StringToItemParser::getInstance();
		foreach($cfg->getProperty(YmlServerProperties::CHUNK_TICKING_DISABLE_BLOCK_TICKING, []) as $name){
			$name = (string) $name;
			$item = $parser->parse($name);
			if($item !== null){
				$block = $item->getBlock();
			}elseif(preg_match("/^-?\d+$/", $name) === 1){
				//TODO: this is a really sketchy hack - remove this as soon as possible
				try{
					$blockStateData = GlobalBlockStateHandlers::getUpgrader()->upgradeIntIdMeta((int) $name, 0);
				}catch(BlockStateDeserializeException){
					continue;
				}
				$block = $this->blockStateRegistry->fromStateId(GlobalBlockStateHandlers::getDeserializer()->deserialize($blockStateData));
			}else{
				//TODO: we probably ought to log an error here
				continue;
			}

			if($block->getTypeId() !== BlockTypeIds::AIR){
				$dontTickBlocks[$block->getTypeId()] = $name;
			}
		}

		foreach($this->blockStateRegistry->getAllKnownStates() as $state){
			$dontTickName = $dontTickBlocks[$state->getTypeId()] ?? null;
			if($dontTickName === null && $state->ticksRandomly()){
				$this->randomTickBlocks[$state->getStateId()] = true;
			}
		}
	}

	public function getTickRateTime() : float{
		return $this->tickRateTime;
	}

	public function getServer() : Server{
		return $this->server;
	}

	public function getLogger() : \Logger{
		return $this->logger;
	}

	final public function getProvider() : WritableWorldProvider{
		return $this->provider;
	}

	private GameRules $gameRules;

	public function getGameRules() : GameRules{ return $this->gameRules; }

	/** Saves immediately and updates every player currently viewing this world. */
	public function setGameRule(string $name, bool $value) : void{
		$this->gameRules->setBool($name, $value);
		foreach($this->getPlayers() as $player){
			$player->getNetworkSession()->syncGameRules($this);
		}
	}

	/**
	 * Returns the unique world identifier
	 */
	final public function getId() : int{
		return $this->worldId;
	}

	public function isLoaded() : bool{
		return !$this->unloaded;
	}

	/**
	 * @internal
	 */
	public function onUnload() : void{
		if($this->unloaded){
			throw new \LogicException("Tried to close a world which is already closed");
		}

		foreach($this->unloadCallbacks as $callback){
			$callback();
		}
		$this->unloadCallbacks = [];

		foreach($this->chunks as $chunkHash => $chunk){
			self::getXZ($chunkHash, $chunkX, $chunkZ);
			$this->unloadChunk($chunkX, $chunkZ, false);
		}
		$this->knownUngeneratedChunks = [];
		foreach($this->entitiesByChunk as $chunkHash => $entities){
			self::getXZ($chunkHash, $chunkX, $chunkZ);

			$leakedEntities = 0;
			foreach($entities as $entity){
				if(!$entity->isFlaggedForDespawn()){
					$leakedEntities++;
				}
				$entity->close();
			}
			if($leakedEntities !== 0){
				$this->logger->warning("$leakedEntities leaked entities found in ungenerated chunk $chunkX $chunkZ during unload, they won't be saved!");
			}
		}

		$this->save();

		$this->generatorExecutor->shutdown();

		$this->provider->close();
		$this->blockCache = [];
		$this->blockCacheSize = 0;
		$this->blockCollisionBoxCache = [];

		$this->unloaded = true;
	}

	/** @phpstan-param \Closure() : void $callback */
	public function addOnUnloadCallback(\Closure $callback) : void{
		$this->unloadCallbacks[spl_object_id($callback)] = $callback;
	}

	/** @phpstan-param \Closure() : void $callback */
	public function removeOnUnloadCallback(\Closure $callback) : void{
		unset($this->unloadCallbacks[spl_object_id($callback)]);
	}

	/**
	 * Returns a list of players who are in the given filter and also using the chunk containing the target position.
	 * Used for broadcasting sounds and particles with specific targets.
	 *
	 * @param Player[] $allowed
	 *
	 * @return array<int, Player>
	 */
	private function filterViewersForPosition(Vector3 $pos, array $allowed) : array{
		$candidates = $this->getViewersForPosition($pos);
		$filtered = [];
		foreach($allowed as $player){
			$k = spl_object_id($player);
			if(isset($candidates[$k])){
				$filtered[$k] = $candidates[$k];
			}
		}

		return $filtered;
	}

	/**
	 * @param Player[]|null $players
	 */
	public function addSound(Vector3 $pos, Sound $sound, ?array $players = null) : void{
		$players ??= $this->getViewersForPosition($pos);

		if(WorldSoundEvent::hasHandlers()){
			$ev = new WorldSoundEvent($this, $sound, $pos, $players);
			$ev->call();
			if($ev->isCancelled()){
				return;
			}

			$sound = $ev->getSound();
			$players = $ev->getRecipients();
		}

		if(($blockSound = ($sound instanceof BlockSound)) || $sound instanceof ProtocolSound){
			if($blockSound){
				$closure = function(TypeConverter $typeConverter) use ($sound, $pos) : array{
					$sound->setBlockTranslator($typeConverter->getBlockTranslator());
					return $sound->encode($pos);
				};
			}else{
				/** @var ProtocolSound $sound */
				$closure = function(TypeConverter $typeConverter) use ($sound, $pos) : array{
					$sound->setProtocolId($typeConverter->getProtocolId());
					return $sound->encode($pos);
				};
			}

			if($players === $this->getViewersForPosition($pos)){
				$this->broadcastPacketToViewersByTypeConverter($pos, $closure);
			}else{
				TypeConverter::broadcastByTypeConverter($this->filterViewersForPosition($pos, $players), $closure);
			}
		}else{
			$pk = $sound->encode($pos);
			if(count($pk) > 0){
				if($players === $this->getViewersForPosition($pos)){
					foreach($pk as $e){
						$this->broadcastPacketToViewers($pos, $e);
					}
				}else{
					NetworkBroadcastUtils::broadcastPackets($this->filterViewersForPosition($pos, $players), $pk);
				}
			}
		}
	}

	/**
	 * @param Player[]|null $players
	 */
	public function addParticle(Vector3 $pos, Particle $particle, ?array $players = null) : void{
		$players ??= $this->getViewersForPosition($pos);

		if(WorldParticleEvent::hasHandlers()){
			$ev = new WorldParticleEvent($this, $particle, $pos, $players);
			$ev->call();
			if($ev->isCancelled()){
				return;
			}

			$particle = $ev->getParticle();
			$players = $ev->getRecipients();
		}

		if($particle instanceof BlockParticle || $particle instanceof ItemParticle || $particle instanceof ProtocolParticle){
			$closure = function(TypeConverter $typeConverter) use ($particle, $pos) : array{
				if($particle instanceof ItemParticle){
					$particle->setItemTranslator($typeConverter->getItemTranslator());
				}elseif($particle instanceof ProtocolParticle){
					$particle->setProtocolId($typeConverter->getProtocolId());
				}else{
					$particle->setBlockTranslator($typeConverter->getBlockTranslator());
				}

				return $particle->encode($pos);
			};

			if($players === $this->getViewersForPosition($pos)){
				$this->broadcastPacketToViewersByTypeConverter($pos, $closure);
			}else{
				TypeConverter::broadcastByTypeConverter($this->filterViewersForPosition($pos, $players), $closure);
			}
		}else{
			$pk = $particle->encode($pos);
			if(count($pk) > 0){
				if($players === $this->getViewersForPosition($pos)){
					foreach($pk as $e){
						$this->broadcastPacketToViewers($pos, $e);
					}
				}else{
					NetworkBroadcastUtils::broadcastPackets($this->filterViewersForPosition($pos, $players), $pk);
				}
			}
		}
	}

	public function getAutoSave() : bool{
		return $this->autoSave;
	}

	public function setAutoSave(bool $value) : void{
		$this->autoSave = $value;
	}

	/**
	 * @deprecated WARNING: This function has a misleading name. Contrary to what the name might imply, this function
	 * DOES NOT return players who are IN a chunk, rather, it returns players who can SEE the chunk.
	 *
	 * Returns a list of players who have the target chunk within their view distance.
	 *
	 * @return Player[] spl_object_id => Player
	 * @phpstan-return array<int, Player>
	 */
	public function getChunkPlayers(int $chunkX, int $chunkZ) : array{
		return $this->playerChunkListeners[World::chunkHash($chunkX, $chunkZ)] ?? [];
	}

	/**
	 * Gets the chunk loaders being used in a specific chunk
	 *
	 * @return ChunkLoader[]
	 * @phpstan-return array<int, ChunkLoader>
	 */
	public function getChunkLoaders(int $chunkX, int $chunkZ) : array{
		return $this->chunkLoaders[World::chunkHash($chunkX, $chunkZ)] ?? [];
	}

	/**
	 * Returns an array of players who have the target position within their view distance.
	 *
	 * @return Player[] spl_object_id => Player
	 * @phpstan-return array<int, Player>
	 */
	public function getViewersForPosition(Vector3 $pos) : array{
		return $this->getChunkPlayers($pos->getFloorX() >> Chunk::COORD_BIT_SIZE, $pos->getFloorZ() >> Chunk::COORD_BIT_SIZE);
	}

	/**
	 * Broadcasts a packet to every player who has the target position within their view distance.
	 */
	public function broadcastPacketToViewers(Vector3 $pos, ClientboundPacket $packet) : void{
		$this->broadcastPacketToPlayersUsingChunk($pos->getFloorX() >> Chunk::COORD_BIT_SIZE, $pos->getFloorZ() >> Chunk::COORD_BIT_SIZE, $packet);
	}

	/**
	 * Broadcasts packets to every player who has the target position within their view distance.
	 * @phpstan-param \Closure(TypeConverter) : ClientboundPacket[] $closure
	 */
	public function broadcastPacketToViewersByTypeConverter(Vector3 $pos, \Closure $closure) : void{
		$this->broadcastPacketToPlayersByTypeConverterUsingChunk($pos->getFloorX() >> Chunk::COORD_BIT_SIZE, $pos->getFloorZ() >> Chunk::COORD_BIT_SIZE, $closure);
	}

	/**
	 * @phpstan-param \Closure(TypeConverter) : ClientboundPacket[] $closure
	 */
	private function broadcastPacketToPlayersByTypeConverterUsingChunk(int $chunkX, int $chunkZ, \Closure $closure) : void{
		Utils::validateCallableSignature(new CallbackType(
			new ReturnType(BuiltInTypes::ARRAY, ReturnType::COVARIANT),
			new ParameterType('typeConverter', TypeConverter::class),
		), $closure);

		if(!isset($this->packetBuffersByChunkTypeConverter[$index = World::chunkHash($chunkX, $chunkZ)])){
			$this->packetBuffersByChunkTypeConverter[$index] = [$closure];
		}else{
			$this->packetBuffersByChunkTypeConverter[$index][] = $closure;
		}
	}

	private function broadcastPacketToPlayersUsingChunk(int $chunkX, int $chunkZ, ClientboundPacket $packet) : void{
		if(!isset($this->packetBuffersByChunk[$index = World::chunkHash($chunkX, $chunkZ)])){
			$this->packetBuffersByChunk[$index] = [$packet];
		}else{
			$this->packetBuffersByChunk[$index][] = $packet;
		}
	}

	public function registerChunkLoader(ChunkLoader $loader, int $chunkX, int $chunkZ, bool $autoLoad = true) : void{
		$loaderId = spl_object_id($loader);

		if(!isset($this->chunkLoaders[$chunkHash = World::chunkHash($chunkX, $chunkZ)])){
			$this->chunkLoaders[$chunkHash] = [];
		}elseif(isset($this->chunkLoaders[$chunkHash][$loaderId])){
			return;
		}

		$this->chunkLoaders[$chunkHash][$loaderId] = $loader;

		$this->cancelUnloadChunkRequest($chunkX, $chunkZ);

		if($autoLoad){
			$this->loadChunk($chunkX, $chunkZ);
		}
	}

	public function unregisterChunkLoader(ChunkLoader $loader, int $chunkX, int $chunkZ) : void{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		$loaderId = spl_object_id($loader);
		if(isset($this->chunkLoaders[$chunkHash][$loaderId])){
			if(count($this->chunkLoaders[$chunkHash]) === 1){
				unset($this->chunkLoaders[$chunkHash]);
				$this->unloadChunkRequest($chunkX, $chunkZ, true);
				if(isset($this->chunkPopulationRequestMap[$chunkHash]) && !isset($this->activeChunkPopulationTasks[$chunkHash])){
					$this->chunkPopulationRequestMap[$chunkHash]->reject();
					unset($this->chunkPopulationRequestMap[$chunkHash]);
				}
			}else{
				unset($this->chunkLoaders[$chunkHash][$loaderId]);
			}
		}
	}

	/**
	 * Registers a listener to receive events on a chunk.
	 */
	public function registerChunkListener(ChunkListener $listener, int $chunkX, int $chunkZ) : void{
		$hash = World::chunkHash($chunkX, $chunkZ);
		if(isset($this->chunkListeners[$hash])){
			$this->chunkListeners[$hash][spl_object_id($listener)] = $listener;
		}else{
			$this->chunkListeners[$hash] = [spl_object_id($listener) => $listener];
		}
		if($listener instanceof Player){
			$this->playerChunkListeners[$hash][spl_object_id($listener)] = $listener;
		}
	}

	/**
	 * Unregisters a chunk listener previously registered.
	 *
	 * @see World::registerChunkListener()
	 */
	public function unregisterChunkListener(ChunkListener $listener, int $chunkX, int $chunkZ) : void{
		$hash = World::chunkHash($chunkX, $chunkZ);
		if(isset($this->chunkListeners[$hash])){
			if(count($this->chunkListeners[$hash]) === 1){
				unset($this->chunkListeners[$hash]);
				unset($this->playerChunkListeners[$hash]);
			}else{
				unset($this->chunkListeners[$hash][spl_object_id($listener)]);
				unset($this->playerChunkListeners[$hash][spl_object_id($listener)]);
			}
		}
	}

	/**
	 * Unregisters a chunk listener from all chunks it is listening on in this World.
	 */
	public function unregisterChunkListenerFromAll(ChunkListener $listener) : void{
		foreach($this->chunkListeners as $hash => $listeners){
			World::getXZ($hash, $chunkX, $chunkZ);
			$this->unregisterChunkListener($listener, $chunkX, $chunkZ);
		}
	}

	/**
	 * Returns all the listeners attached to this chunk.
	 *
	 * @return ChunkListener[]
	 * @phpstan-return array<int, ChunkListener>
	 */
	public function getChunkListeners(int $chunkX, int $chunkZ) : array{
		return $this->chunkListeners[World::chunkHash($chunkX, $chunkZ)] ?? [];
	}

	/**
	 * @internal
	 */
	public function sendTime(Player ...$targets) : void{
		if(count($targets) === 0){
			$targets = $this->players;
		}
		foreach($targets as $player){
			$player->getNetworkSession()->syncWorldTime($this->time);
		}
	}

	public function isDoingTick() : bool{
		return $this->doingTick;
	}

	/**
	 * @internal
	 */
	public function doTick(int $currentTick) : void{
		if($this->unloaded){
			throw new \LogicException("Attempted to tick a world which has been closed");
		}

		$this->timings->doTick->startTiming();
		$this->doingTick = true;
		try{
			$this->actuallyDoTick($currentTick);
		}finally{
			$this->doingTick = false;
			$this->timings->doTick->stopTiming();
		}
	}

	protected function actuallyDoTick(int $currentTick) : void{
		if(!$this->stopTime){
			//this simulates an overflow, as would happen in any language which doesn't do stupid things to var types
			if($this->time === PHP_INT_MAX){
				$this->time = PHP_INT_MIN;
			}else{
				$this->time++;
			}
		}

		$this->weather->tick();
		$this->sunAnglePercentage = $this->computeSunAnglePercentage(); //Sun angle depends on the current time
		$this->skyLightReduction = $this->computeSkyLightReduction(); //Sky light reduction depends on the sun angle

		if(++$this->sendTimeTicker === 200){
			$this->sendTime();
			$this->sendTimeTicker = 0;
		}

		$this->unloadChunks();
		if(++$this->providerGarbageCollectionTicker >= 6000){
			$this->provider->doGarbageCollection();
			$this->providerGarbageCollectionTicker = 0;
		}

		$this->timings->scheduledBlockUpdates->startTiming();
		//Delayed updates
		while($this->scheduledBlockUpdateQueue->count() > 0 && $this->scheduledBlockUpdateQueue->current()["priority"] <= $currentTick){
			/** @var array{data: Vector3, priority: int} $entry */
			$entry = $this->scheduledBlockUpdateQueue->extract();
			$vec = $entry["data"];
			$index = World::blockHash($vec->x, $vec->y, $vec->z);
			//Ignore a previously scheduled tick replaced by a later lightning strike.
			if(($this->scheduledBlockUpdateQueueIndex[$index] ?? null) !== $entry["priority"]){
				continue;
			}
			unset($this->scheduledBlockUpdateQueueIndex[$index]);
			if(!$this->isInLoadedTerrain($vec)){
				continue;
			}
			$block = $this->getBlock($vec);
			$block->onScheduledUpdate();
		}
		$this->timings->scheduledBlockUpdates->stopTiming();

		$this->timings->neighbourBlockUpdates->startTiming();
		//Normal updates
		while($this->neighbourBlockUpdateQueue->count() > 0){
			$index = $this->neighbourBlockUpdateQueue->dequeue();
			unset($this->neighbourBlockUpdateQueueIndex[$index]);
			World::getBlockXYZ($index, $x, $y, $z);
			if(!$this->isChunkLoaded($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)){
				continue;
			}

			$block = $this->getBlockAt($x, $y, $z);

			if(BlockUpdateEvent::hasHandlers()){
				$ev = new BlockUpdateEvent($block);
				$ev->call();
				if($ev->isCancelled()){
					continue;
				}
			}
			foreach($this->getNearbyEntities(AxisAlignedBB::one()->offset($x, $y, $z)) as $entity){
				$entity->onNearbyBlockChange();
			}
			$block->onNearbyBlockChange();
		}

		$this->timings->neighbourBlockUpdates->stopTiming();

		$this->timings->entityTick->startTiming();
		//Update entities that need update
		foreach($this->updateEntities as $id => $entity){
			if($entity->isClosed() || $entity->isFlaggedForDespawn() || !$entity->onUpdate($currentTick)){
				unset($this->updateEntities[$id]);
			}
			if($entity->isFlaggedForDespawn()){
				$entity->close();
			}
		}
		$this->timings->entityTick->stopTiming();

		$this->timings->randomChunkUpdates->startTiming();
		$this->tickChunks();
		$this->naturalMobSpawner->tick($currentTick);
		$this->timings->randomChunkUpdates->stopTiming();

		$this->executeQueuedLightUpdates();

		if(count($this->changedBlocks) > 0){
			if(count($this->players) > 0){
				foreach($this->changedBlocks as $index => $blocks){
					if(count($blocks) === 0){ //blocks can be set normally and then later re-set with direct send
						continue;
					}
					World::getXZ($index, $chunkX, $chunkZ);
					if(!$this->isChunkLoaded($chunkX, $chunkZ)){
						//a previous chunk may have caused this one to be unloaded by a ChunkListener
						continue;
					}
					if(count($blocks) > 512){
						$chunk = $this->getChunk($chunkX, $chunkZ) ?? throw new AssumptionFailedError("We already checked that the chunk is loaded");
						foreach($this->getChunkPlayers($chunkX, $chunkZ) as $p){
							$p->onChunkChanged($chunkX, $chunkZ, $chunk);
						}
					}else{
						$this->broadcastPacketToPlayersByTypeConverterUsingChunk($chunkX, $chunkZ, fn(TypeConverter $typeConverter) : array => $this->createBlockUpdatePackets($typeConverter, $blocks));
					}
				}
			}

			$this->changedBlocks = [];

		}

		if($this->sleepTicks > 0 && --$this->sleepTicks <= 0){
			$this->checkSleep();
		}

		foreach($this->packetBuffersByChunkTypeConverter as $index => $entries){
			World::getXZ($index, $chunkX, $chunkZ);
			TypeConverter::broadcastByTypeConverter($this->getChunkPlayers($chunkX, $chunkZ), function(TypeConverter $typeConverter) use ($index, $entries) : array{
				return array_merge($this->packetBuffersByChunk[$index] ?? [], ...array_map(function(\Closure $closure) use ($typeConverter) : array{
					return $closure($typeConverter);
				}, $entries));
			});

			unset($this->packetBuffersByChunk[$index]);
		}

		foreach($this->packetBuffersByChunk as $index => $entries){
			World::getXZ($index, $chunkX, $chunkZ);
			$chunkPlayers = $this->getChunkPlayers($chunkX, $chunkZ);
			if(count($chunkPlayers) > 0){
				NetworkBroadcastUtils::broadcastPackets($chunkPlayers, $entries);
			}
		}

		$this->packetBuffersByChunk = [];
		$this->packetBuffersByChunkTypeConverter = [];
	}

	public function checkSleep() : void{
		if(count($this->players) === 0){
			return;
		}

		$resetTime = true;
		foreach($this->getPlayers() as $p){
			if(!$p->isSleeping()){
				$resetTime = false;
				break;
			}
		}

		if($resetTime){
			$time = $this->getTimeOfDay();

			if($time >= World::TIME_NIGHT && $time < World::TIME_SUNRISE){
				$this->setTime($this->getTime() + World::TIME_FULL - $time);
				if($this->weather->isWeatherCycleEnabled()){
					$this->weather->resetCycle();
				}

				foreach($this->getPlayers() as $p){
					$p->stopSleep();
				}
			}
		}
	}

	public function setSleepTicks(int $ticks) : void{
		$this->sleepTicks = $ticks;
	}

	/**
	 * @param Vector3[] $blocks
	 *
	 * @return ClientboundPacket[]
	 * @phpstan-return list<ClientboundPacket>
	 */
	public function createBlockUpdatePackets(TypeConverter $typeConverter, array $blocks) : array{
		$packets = [];

		$blockTranslator = $typeConverter->getBlockTranslator();

		foreach($blocks as $b){
			if(!($b instanceof Vector3)){
				throw new \TypeError("Expected Vector3 in blocks array, got " . (is_object($b) ? get_class($b) : gettype($b)));
			}

			$fullBlock = $this->getBlockAt($b->x, $b->y, $b->z);
			$blockPosition = BlockPosition::fromVector3($b);

			$tile = $this->getTileAt($b->x, $b->y, $b->z);
			$spawnTile = $tile instanceof Spawnable && $tile->canSpawnTo($typeConverter);
			if($spawnTile){
				$expectedClass = $fullBlock->getIdInfo()->getTileClass();
				if($expectedClass !== null && $tile instanceof $expectedClass && count($fakeStateProperties = $tile->getRenderUpdateBugWorkaroundStateProperties($fullBlock)) > 0){
					$originalStateData = $blockTranslator->internalIdToNetworkStateData($fullBlock->getStateId());
					$fakeStateData = new BlockStateData(
						$originalStateData->getName(),
						array_merge($originalStateData->getStates(), $fakeStateProperties),
						$originalStateData->getVersion()
					);
					$packets[] = UpdateBlockPacket::create(
						$blockPosition,
						$blockTranslator->networkStateDataToNetworkId($fakeStateData),
						UpdateBlockPacket::FLAG_NETWORK,
						UpdateBlockPacket::DATA_LAYER_NORMAL
					);
				}
			}
			$packets[] = UpdateBlockPacket::create(
				$blockPosition,
				$blockTranslator->internalIdToNetworkId($fullBlock->getStateId()),
				UpdateBlockPacket::FLAG_NETWORK,
				UpdateBlockPacket::DATA_LAYER_NORMAL
			);

			if($spawnTile){
				$packets[] = BlockActorDataPacket::create($blockPosition, $tile->getSerializedSpawnCompound($typeConverter));
			}
		}

		return $packets;
	}

	public function clearCache(bool $force = false) : void{
		if($force){
			$this->blockCache = [];
			$this->blockCacheSize = 0;
			$this->blockCollisionBoxCache = [];
		}else{
			//Recalculate this when we're asked - blockCacheSize may be higher than the real size
			$this->blockCacheSize = 0;
			foreach($this->blockCache as $list){
				$this->blockCacheSize += count($list);
				if($this->blockCacheSize > self::BLOCK_CACHE_SIZE_CAP){
					$this->blockCache = [];
					$this->blockCacheSize = 0;
					break;
				}
			}

			$count = 0;
			foreach($this->blockCollisionBoxCache as $list){
				$count += count($list);
				if($count > self::BLOCK_CACHE_SIZE_CAP){
					//TODO: Is this really the best logic?
					$this->blockCollisionBoxCache = [];
					break;
				}
			}
		}
	}

	private function trimBlockCache() : void{
		$before = $this->blockCacheSize;
		//Since PHP maintains key order, earliest in foreach should be the oldest entries
		//Older entries are less likely to be hot, so destroying these should usually have the lowest impact on performance
		foreach($this->blockCache as $chunkHash => $blocks){
			unset($this->blockCache[$chunkHash]);
			$this->blockCacheSize -= count($blocks);
			if($this->blockCacheSize < self::BLOCK_CACHE_SIZE_CAP){
				break;
			}
		}
	}

	/**
	 * @return true[] fullID => dummy
	 * @phpstan-return array<int, true>
	 */
	public function getRandomTickedBlocks() :…12268 tokens truncated…y<ChunkPosHash, Chunk|null>
	 */
	public function getAdjacentChunks(int $x, int $z) : array{
		$result = [];
		for($xx = -1; $xx <= 1; ++$xx){
			for($zz = -1; $zz <= 1; ++$zz){
				if($xx === 0 && $zz === 0){
					continue; //center chunk
				}
				$result[World::chunkHash($xx, $zz)] = $this->loadChunk($x + $xx, $z + $zz);
			}
		}

		return $result;
	}

	/**
	 * Flags a chunk as locked, usually for async modification.
	 *
	 * This is an **advisory lock**. This means that the lock does **not** prevent the chunk from being modified on the
	 * main thread, such as by setBlock() or setBiomeId(). However, you can use it to detect when such modifications
	 * have taken place - unlockChunk() with the same lockID will fail and return false if this happens.
	 *
	 * This is used internally by the generation system to ensure that two PopulationTasks don't try to modify the same
	 * chunk at the same time. Generation will respect these locks and won't try to do generation of chunks over which
	 * a lock is held.
	 *
	 * WARNING: Be sure to release all locks once you're done with them, or you WILL have problems with terrain not
	 * being generated.
	 */
	public function lockChunk(int $chunkX, int $chunkZ, ChunkLockId $lockId) : void{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		if(isset($this->chunkLock[$chunkHash])){
			throw new \InvalidArgumentException("Chunk $chunkX $chunkZ is already locked");
		}
		$this->chunkLock[$chunkHash] = $lockId;
		$this->markTickingChunkForRecheck($chunkX, $chunkZ);
	}

	/**
	 * Unlocks a chunk previously locked by lockChunk().
	 *
	 * You must provide the same lockID as provided to lockChunk().
	 * If a null lockID is given, any existing lock will be removed from the chunk, regardless of who owns it.
	 *
	 * Returns true if unlocking was successful, false otherwise.
	 */
	public function unlockChunk(int $chunkX, int $chunkZ, ?ChunkLockId $lockId) : bool{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		if(isset($this->chunkLock[$chunkHash]) && ($lockId === null || $this->chunkLock[$chunkHash] === $lockId)){
			unset($this->chunkLock[$chunkHash]);
			$this->markTickingChunkForRecheck($chunkX, $chunkZ);
			return true;
		}
		return false;
	}

	/**
	 * Returns whether anyone currently has a lock on the chunk at the given coordinates.
	 * You should check this to make sure that population tasks aren't currently modifying chunks that you want to use
	 * in async tasks.
	 */
	public function isChunkLocked(int $chunkX, int $chunkZ) : bool{
		return isset($this->chunkLock[World::chunkHash($chunkX, $chunkZ)]);
	}

	public function setChunk(int $chunkX, int $chunkZ, Chunk $chunk) : void{
		foreach($chunk->getSubChunks() as $subChunk){
			foreach($subChunk->getBlockLayers() as $blockLayer){
				foreach($blockLayer->getPalette() as $blockStateId){
					if(!$this->blockStateRegistry->hasStateId($blockStateId)){
						throw new \InvalidArgumentException("Provided chunk contains unknown/unregistered blocks (found unknown state ID $blockStateId)");
					}
				}
			}
		}

		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		$oldChunk = $this->loadChunk($chunkX, $chunkZ);
		if($oldChunk !== null && $oldChunk !== $chunk){
			$deletedTiles = 0;
			$transferredTiles = 0;
			foreach($oldChunk->getTiles() as $oldTile){
				$tilePosition = $oldTile->getPosition();
				$localX = $tilePosition->getFloorX() & Chunk::COORD_MASK;
				$localY = $tilePosition->getFloorY();
				$localZ = $tilePosition->getFloorZ() & Chunk::COORD_MASK;

				$newBlock = $this->blockStateRegistry->fromStateId($chunk->getBlockStateId($localX, $localY, $localZ));
				$expectedTileClass = $newBlock->getIdInfo()->getTileClass();
				if(
					$expectedTileClass === null || //new block doesn't expect a tile
					!($oldTile instanceof $expectedTileClass) || //new block expects a different tile
					(($newTile = $chunk->getTile($localX, $localY, $localZ)) !== null && $newTile !== $oldTile) //new chunk already has a different tile
				){
					$oldTile->close();
					$deletedTiles++;
				}else{
					$transferredTiles++;
					$chunk->addTile($oldTile);
					$oldChunk->removeTile($oldTile);
				}
			}
			if($deletedTiles > 0 || $transferredTiles > 0){
				$this->logger->debug("Replacement of chunk $chunkX $chunkZ caused deletion of $deletedTiles obsolete/conflicted tiles, and transfer of $transferredTiles");
			}
		}

		$this->chunks[$chunkHash] = $chunk;
		unset($this->knownUngeneratedChunks[$chunkHash]);

		$this->blockCacheSize -= count($this->blockCache[$chunkHash] ?? []);
		unset($this->blockCache[$chunkHash]);
		unset($this->blockCollisionBoxCache[$chunkHash]);
		unset($this->changedBlocks[$chunkHash]);
		$chunk->setTerrainDirty();
		if($chunk->getPendingTileNBT() !== []){
			$this->createPendingTiles($chunkX, $chunkZ, $chunk);
		}
		$this->markTickingChunkForRecheck($chunkX, $chunkZ); //this replacement chunk may not meet the conditions for ticking

		if(!$this->isChunkInUse($chunkX, $chunkZ)){
			$this->unloadChunkRequest($chunkX, $chunkZ);
		}

		if($oldChunk === null){
			if(ChunkLoadEvent::hasHandlers()){
				(new ChunkLoadEvent($this, $chunkX, $chunkZ, $chunk, true))->call();
			}

			foreach($this->getChunkListeners($chunkX, $chunkZ) as $listener){
				$listener->onChunkLoaded($chunkX, $chunkZ, $chunk);
			}
		}else{
			foreach($this->getChunkListeners($chunkX, $chunkZ) as $listener){
				$listener->onChunkChanged($chunkX, $chunkZ, $chunk);
			}
		}

		for($cX = -1; $cX <= 1; ++$cX){
			for($cZ = -1; $cZ <= 1; ++$cZ){
				foreach($this->getChunkEntities($chunkX + $cX, $chunkZ + $cZ) as $entity){
					$entity->onNearbyBlockChange();
				}
			}
		}
	}

	/** Installs generated tiles only after the chunk has passed the population lock checks. */
	private function createPendingTiles(int $chunkX, int $chunkZ, Chunk $chunk) : void{
		$pending = $chunk->getPendingTileNBT();
		$chunk->clearPendingTileNBT();
		$tileFactory = TileFactory::getInstance();
		foreach($pending as $nbt){
			$x = $nbt->getInt(Tile::TAG_X);
			$y = $nbt->getInt(Tile::TAG_Y);
			$z = $nbt->getInt(Tile::TAG_Z);
			if(($x >> Chunk::COORD_BIT_SIZE) !== $chunkX || ($z >> Chunk::COORD_BIT_SIZE) !== $chunkZ || !$this->isInWorld($x, $y, $z)){
				$this->logger->warning("Generated tile is outside its chunk at $x,$y,$z");
				continue;
			}
			$localX = $x & Chunk::COORD_MASK;
			$localZ = $z & Chunk::COORD_MASK;
			if($chunk->getTile($localX, $y, $localZ) !== null){
				continue; // Keep the saved inventory of a tile already present at this position.
			}
			$block = $this->blockStateRegistry->fromStateId($chunk->getBlockStateId($localX, $y, $localZ));
			$expectedClass = $block->getIdInfo()->getTileClass();
			$id = $nbt->getTag(Tile::TAG_ID);
			$tileClass = $id instanceof StringTag ? $tileFactory->getClassFromSaveId($id->getValue()) : null;
			if($expectedClass === null || $tileClass === null || !is_a($tileClass, $expectedClass, true)){
				$name = $id instanceof StringTag ? $id->getValue() : "<missing id>";
				$this->logger->debug("Generated tile $name has no compatible block at $x,$y,$z");
				continue;
			}
			try{
				$tile = $tileFactory->createFromData($this, $nbt);
				if($tile !== null){
					$this->addTile($tile);
				}
			}catch(SavedDataLoadingException $e){
				$this->logger->error("Invalid generated tile at $x,$y,$z: " . $e->getMessage());
			}
		}
	}

	/**
	 * Gets the highest block Y value at a specific $x and $z
	 *
	 * @return int|null 0-255, or null if the column is empty
	 * @throws WorldException if the terrain is not generated
	 */
	public function getHighestBlockAt(int $x, int $z) : ?int{
		if(($chunk = $this->loadChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)) !== null){
			return $chunk->getHighestBlockAt($x & Chunk::COORD_MASK, $z & Chunk::COORD_MASK);
		}
		throw new WorldException("Cannot get highest block in an ungenerated chunk");
	}

	/**
	 * Returns whether the given position is in a loaded area of terrain.
	 */
	public function isInLoadedTerrain(Vector3 $pos) : bool{
		return $this->isChunkLoaded($pos->getFloorX() >> Chunk::COORD_BIT_SIZE, $pos->getFloorZ() >> Chunk::COORD_BIT_SIZE);
	}

	public function isChunkLoaded(int $x, int $z) : bool{
		return isset($this->chunks[World::chunkHash($x, $z)]);
	}

	public function isChunkGenerated(int $x, int $z) : bool{
		return $this->loadChunk($x, $z) !== null;
	}

	public function isChunkPopulated(int $x, int $z) : bool{
		$chunk = $this->loadChunk($x, $z);
		return $chunk !== null && $chunk->isPopulated();
	}

	/**
	 * Returns a Position pointing to the spawn
	 */
	public function getSpawnLocation() : Position{
		return Position::fromObject($this->provider->getWorldData()->getSpawn(), $this);
	}

	/**
	 * Sets the world spawn location
	 */
	public function setSpawnLocation(Vector3 $pos) : void{
		$previousSpawn = $this->getSpawnLocation();
		$this->provider->getWorldData()->setSpawn($pos);
		(new SpawnChangeEvent($this, $previousSpawn))->call();

		$location = Position::fromObject($pos, $this);
		foreach($this->players as $player){
			$player->getNetworkSession()->syncWorldSpawnPoint($location);
		}
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public function addEntity(Entity $entity) : void{
		if($entity->isClosed()){
			throw new \InvalidArgumentException("Attempted to add a garbage closed Entity to world");
		}
		if($entity->getWorld() !== $this){
			throw new \InvalidArgumentException("Invalid Entity world");
		}
		if(array_key_exists($entity->getId(), $this->entities)){
			if($this->entities[$entity->getId()] === $entity){
				throw new \InvalidArgumentException("Entity " . $entity->getId() . " has already been added to this world");
			}else{
				throw new AssumptionFailedError("Found two different entities sharing entity ID " . $entity->getId());
			}
		}
		if(!EntityFactory::getInstance()->isRegistered($entity::class) && !$entity instanceof NeverSavedWithChunkEntity){
			//canSaveWithChunk is mutable, so that means it could be toggled after adding the entity and cause a crash
			//later on. Better we just force all entities to have a save ID, even if it might not be needed.
			throw new \LogicException("Entity " . $entity::class . " is not registered for a save ID in EntityFactory");
		}
		$pos = $entity->getPosition()->asVector3();
		$this->entitiesByChunk[World::chunkHash($pos->getFloorX() >> Chunk::COORD_BIT_SIZE, $pos->getFloorZ() >> Chunk::COORD_BIT_SIZE)][$entity->getId()] = $entity;
		$this->entityLastKnownPositions[$entity->getId()] = $pos;

		if($entity instanceof Player){
			$this->players[$entity->getId()] = $entity;
		}
		$this->entities[$entity->getId()] = $entity;
	}

	/**
	 * Removes the entity from the world index
	 *
	 * @throws \InvalidArgumentException
	 */
	public function removeEntity(Entity $entity) : void{
		if($entity->getWorld() !== $this){
			throw new \InvalidArgumentException("Invalid Entity world");
		}
		if(!array_key_exists($entity->getId(), $this->entities)){
			throw new \InvalidArgumentException("Entity is not tracked by this world (possibly already removed?)");
		}
		$pos = $this->entityLastKnownPositions[$entity->getId()];
		$chunkHash = World::chunkHash($pos->getFloorX() >> Chunk::COORD_BIT_SIZE, $pos->getFloorZ() >> Chunk::COORD_BIT_SIZE);
		if(isset($this->entitiesByChunk[$chunkHash][$entity->getId()])){
			if(count($this->entitiesByChunk[$chunkHash]) === 1){
				unset($this->entitiesByChunk[$chunkHash]);
			}else{
				unset($this->entitiesByChunk[$chunkHash][$entity->getId()]);
			}
		}
		unset($this->entityLastKnownPositions[$entity->getId()]);

		if($entity instanceof Player){
			unset($this->players[$entity->getId()]);
			$this->checkSleep();
		}

		unset($this->entities[$entity->getId()]);
		unset($this->updateEntities[$entity->getId()]);
	}

	/**
	 * @internal
	 */
	public function onEntityMoved(Entity $entity) : void{
		if(!array_key_exists($entity->getId(), $this->entityLastKnownPositions)){
			//this can happen if the entity was teleported before addEntity() was called
			return;
		}
		$oldPosition = $this->entityLastKnownPositions[$entity->getId()];
		$newPosition = $entity->getPosition();

		$oldChunkX = $oldPosition->getFloorX() >> Chunk::COORD_BIT_SIZE;
		$oldChunkZ = $oldPosition->getFloorZ() >> Chunk::COORD_BIT_SIZE;
		$newChunkX = $newPosition->getFloorX() >> Chunk::COORD_BIT_SIZE;
		$newChunkZ = $newPosition->getFloorZ() >> Chunk::COORD_BIT_SIZE;

		if($oldChunkX !== $newChunkX || $oldChunkZ !== $newChunkZ){
			$oldChunkHash = World::chunkHash($oldChunkX, $oldChunkZ);
			if(isset($this->entitiesByChunk[$oldChunkHash][$entity->getId()])){
				if(count($this->entitiesByChunk[$oldChunkHash]) === 1){
					unset($this->entitiesByChunk[$oldChunkHash]);
				}else{
					unset($this->entitiesByChunk[$oldChunkHash][$entity->getId()]);
				}
			}

			$newViewers = $this->getViewersForPosition($newPosition);
			foreach($entity->getViewers() as $player){
				if(!isset($newViewers[spl_object_id($player)])){
					$entity->despawnFrom($player);
				}else{
					unset($newViewers[spl_object_id($player)]);
				}
			}
			foreach($newViewers as $player){
				$entity->spawnTo($player);
			}

			$newChunkHash = World::chunkHash($newChunkX, $newChunkZ);
			$this->entitiesByChunk[$newChunkHash][$entity->getId()] = $entity;
		}
		$this->entityLastKnownPositions[$entity->getId()] = $newPosition->asVector3();
	}

	/**
	 * @internal Tiles are now bound with blocks, and their creation is automatic. They should not be directly added.
	 * @throws \InvalidArgumentException
	 */
	public function addTile(Tile $tile) : void{
		if($tile->isClosed()){
			throw new \InvalidArgumentException("Attempted to add a garbage closed Tile to world");
		}
		$pos = $tile->getPosition();
		if(!$pos->isValid() || $pos->getWorld() !== $this){
			throw new \InvalidArgumentException("Invalid Tile world");
		}
		if(!$this->isInWorld($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){
			throw new \InvalidArgumentException("Tile position is outside the world bounds");
		}
		if(!TileFactory::getInstance()->isRegistered($tile::class)){
			throw new \LogicException("Tile " . $tile::class . " is not registered for a save ID in TileFactory");
		}

		$chunkX = $pos->getFloorX() >> Chunk::COORD_BIT_SIZE;
		$chunkZ = $pos->getFloorZ() >> Chunk::COORD_BIT_SIZE;

		if(isset($this->chunks[$hash = World::chunkHash($chunkX, $chunkZ)])){
			$this->chunks[$hash]->addTile($tile);
		}else{
			throw new \InvalidArgumentException("Attempted to create tile " . get_class($tile) . " in unloaded chunk $chunkX $chunkZ");
		}

		//delegate tile ticking to the corresponding block
		$this->scheduleDelayedBlockUpdate($pos->asVector3(), 1);
	}

	/**
	 * @internal Tiles are now bound with blocks, and their removal is automatic. They should not be directly removed.
	 * @throws \InvalidArgumentException
	 */
	public function removeTile(Tile $tile) : void{
		$pos = $tile->getPosition();
		if(!$pos->isValid() || $pos->getWorld() !== $this){
			throw new \InvalidArgumentException("Invalid Tile world");
		}

		$chunkX = $pos->getFloorX() >> Chunk::COORD_BIT_SIZE;
		$chunkZ = $pos->getFloorZ() >> Chunk::COORD_BIT_SIZE;

		if(isset($this->chunks[$hash = World::chunkHash($chunkX, $chunkZ)])){
			$this->chunks[$hash]->removeTile($tile);
		}
		foreach($this->getChunkListeners($chunkX, $chunkZ) as $listener){
			$listener->onBlockChanged($pos->asVector3());
		}
	}

	public function isChunkInUse(int $x, int $z) : bool{
		return isset($this->chunkLoaders[$index = World::chunkHash($x, $z)]) && count($this->chunkLoaders[$index]) > 0;
	}

	/**
	 * Attempts to load a chunk from the world provider (if not already loaded). If the chunk is already loaded, it is
	 * returned directly.
	 *
	 * @return Chunk|null the requested chunk, or null on failure.
	 */
	public function loadChunk(int $x, int $z) : ?Chunk{
		if(isset($this->chunks[$chunkHash = World::chunkHash($x, $z)])){
			return $this->chunks[$chunkHash];
		}
		if(isset($this->knownUngeneratedChunks[$chunkHash])){
			return null;
		}

		$this->timings->syncChunkLoad->startTiming();

		$this->cancelUnloadChunkRequest($x, $z);

		$this->timings->syncChunkLoadData->startTiming();

		$loadedChunkData = null;

		try{
			$loadedChunkData = $this->provider->loadChunk($x, $z);
		}catch(CorruptedChunkException $e){
			$this->logger->critical("Failed to load chunk x=$x z=$z: " . $e->getMessage());
		}

		$this->timings->syncChunkLoadData->stopTiming();

		if($loadedChunkData === null){
			$this->timings->syncChunkLoad->stopTiming();
			$this->knownUngeneratedChunks[$chunkHash] = true;
			return null;
		}

		$chunkData = $loadedChunkData->getData();
		$chunk = new Chunk($chunkData->getSubChunks(), $chunkData->isPopulated());
		if(!$loadedChunkData->isUpgraded()){
			$chunk->clearTerrainDirtyFlags();
		}else{
			$this->logger->debug("Chunk $x $z has been upgraded, will be saved at the next autosave opportunity");
		}
		$this->chunks[$chunkHash] = $chunk;

		$this->blockCacheSize -= count($this->blockCache[$chunkHash] ?? []);
		unset($this->blockCache[$chunkHash]);
		unset($this->blockCollisionBoxCache[$chunkHash]);

		$this->initChunk($x, $z, $chunkData, $chunk);

		if(ChunkLoadEvent::hasHandlers()){
			(new ChunkLoadEvent($this, $x, $z, $this->chunks[$chunkHash], false))->call();
		}

		if(!$this->isChunkInUse($x, $z)){
			$this->logger->debug("Newly loaded chunk $x $z has no loaders registered, will be unloaded at next available opportunity");
			$this->unloadChunkRequest($x, $z);
		}
		foreach($this->getChunkListeners($x, $z) as $listener){
			$listener->onChunkLoaded($x, $z, $this->chunks[$chunkHash]);
		}
		$this->markTickingChunkForRecheck($x, $z); //tickers may have been registered before the chunk was loaded

		$this->timings->syncChunkLoad->stopTiming();

		return $this->chunks[$chunkHash];
	}

	private function initChunk(int $chunkX, int $chunkZ, ChunkData $chunkData, Chunk $chunk) : void{
		$logger = new \PrefixedLogger($this->logger, "Loading chunk $chunkX $chunkZ");

		if(count($chunkData->getEntityNBT()) !== 0){
			$this->timings->syncChunkLoadEntities->startTiming();
			$entityFactory = EntityFactory::getInstance();

			$deletedEntities = [];
			foreach($chunkData->getEntityNBT() as $k => $nbt){
				try{
					$entity = $entityFactory->createFromData($this, $nbt);
				}catch(SavedDataLoadingException $e){
					$logger->error("Bad entity data at list position $k: " . $e->getMessage());
					$logger->logException($e);
					continue;
				}
				if($entity === null){
					$saveIdTag = $nbt->getTag("identifier") ?? $nbt->getTag("id");
					$saveId = "<unknown>";
					if($saveIdTag instanceof StringTag){
						$saveId = $saveIdTag->getValue();
					}elseif($saveIdTag instanceof IntTag){ //legacy MCPE format
						$saveId = "legacy(" . $saveIdTag->getValue() . ")";
					}
					$deletedEntities[$saveId] = ($deletedEntities[$saveId] ?? 0) + 1;
				}
				//TODO: we can't prevent entities getting added to unloaded chunks if they were saved in the wrong place
				//here, because entities currently add themselves to the world
			}

			foreach(Utils::promoteKeys($deletedEntities) as $saveId => $count){
				$logger->warning("Deleted unknown entity type $saveId x$count");
			}
			$this->timings->syncChunkLoadEntities->stopTiming();
		}

		if(count($chunkData->getTileNBT()) !== 0){
			$this->timings->syncChunkLoadTileEntities->startTiming();
			$tileFactory = TileFactory::getInstance();

			$deletedTiles = [];
			foreach($chunkData->getTileNBT() as $k => $nbt){
				try{
					$tile = $tileFactory->createFromData($this, $nbt);
				}catch(SavedDataLoadingException $e){
					$logger->error("Bad tile entity data at list position $k: " . $e->getMessage());
					$logger->logException($e);
					continue;
				}
				if($tile === null){
					$saveId = $nbt->getString("id", "<unknown>");
					$deletedTiles[$saveId] = ($deletedTiles[$saveId] ?? 0) + 1;
					continue;
				}

				$tilePosition = $tile->getPosition();
				if(!$this->isChunkLoaded($tilePosition->getFloorX() >> Chunk::COORD_BIT_SIZE, $tilePosition->getFloorZ() >> Chunk::COORD_BIT_SIZE)){
					$logger->error("Found tile saved on wrong chunk - unable to fix due to correct chunk not loaded");
				}elseif(!$this->isInWorld($tilePosition->getFloorX(), $tilePosition->getFloorY(), $tilePosition->getFloorZ())){
					$logger->error("Cannot add tile with position outside the world bounds: x=$tilePosition->x,y=$tilePosition->y,z=$tilePosition->z");
				}elseif($this->getTile($tilePosition) !== null){
					$logger->error("Cannot add tile at x=$tilePosition->x,y=$tilePosition->y,z=$tilePosition->z: Another tile is already at that position");
				}else{
					$this->addTile($tile);
				}
				$expectedStateId = $chunk->getBlockStateId($tilePosition->getFloorX() & Chunk::COORD_MASK, $tilePosition->getFloorY(), $tilePosition->getFloorZ() & Chunk::COORD_MASK);
				$actualStateId = $this->getBlock($tilePosition)->getStateId();
				if($expectedStateId !== $actualStateId){
					//state ID was updated by readStateFromWorld - typically because the block pulled some data from the tile
					//make sure this is synced to the chunk
					//TODO: in the future we should pull tile reading logic out of readStateFromWorld() and do it only
					//when the tile is loaded - this would be cleaner and faster
					$chunk->setBlockStateId($tilePosition->getFloorX() & Chunk::COORD_MASK, $tilePosition->getFloorY(), $tilePosition->getFloorZ() & Chunk::COORD_MASK, $actualStateId);
					$this->logger->debug("Tile " . $tile::class . " at x=$tilePosition->x,y=$tilePosition->y,z=$tilePosition->z updated block state ID from $expectedStateId to $actualStateId");
				}
			}

			foreach(Utils::promoteKeys($deletedTiles) as $saveId => $count){
				$logger->warning("Deleted unknown tile entity type $saveId x$count");
			}

			$this->timings->syncChunkLoadTileEntities->stopTiming();
		}
	}

	private function queueUnloadChunk(int $x, int $z) : void{
		$this->unloadQueue[World::chunkHash($x, $z)] = microtime(true);
	}

	public function unloadChunkRequest(int $x, int $z, bool $safe = true) : bool{
		if(($safe && $this->isChunkInUse($x, $z)) || $this->isSpawnChunk($x, $z)){
			return false;
		}

		$this->queueUnloadChunk($x, $z);

		return true;
	}

	public function cancelUnloadChunkRequest(int $x, int $z) : void{
		unset($this->unloadQueue[World::chunkHash($x, $z)]);
	}

	public function unloadChunk(int $x, int $z, bool $safe = true, bool $trySave = true) : bool{
		if($safe && $this->isChunkInUse($x, $z)){
			return false;
		}

		if(!$this->isChunkLoaded($x, $z)){
			return true;
		}

		$this->timings->doChunkUnload->startTiming();

		$chunkHash = World::chunkHash($x, $z);

		$chunk = $this->chunks[$chunkHash] ?? null;

		if($chunk !== null){
			if(ChunkUnloadEvent::hasHandlers()){
				$ev = new ChunkUnloadEvent($this, $x, $z, $chunk);
				$ev->call();
				if($ev->isCancelled()){
					$this->timings->doChunkUnload->stopTiming();

					return false;
				}
			}

			if($trySave && $this->getAutoSave()){
				$this->timings->syncChunkSave->startTiming();
				try{
					$this->provider->saveChunk($x, $z, new ChunkData(
						$chunk->getSubChunks(),
						$chunk->isPopulated(),
						array_map(fn(Entity $e) => $e->saveNBT(), array_values(array_filter($this->getChunkEntities($x, $z), fn(Entity $e) => $e->canSaveWithChunk()))),
						array_map(fn(Tile $t) => $t->saveNBT(), array_values($chunk->getTiles())),
					), $chunk->getTerrainDirtyFlags());
				}finally{
					$this->timings->syncChunkSave->stopTiming();
				}
			}

			foreach($this->getChunkListeners($x, $z) as $listener){
				$listener->onChunkUnloaded($x, $z, $chunk);
			}

			foreach($this->getChunkEntities($x, $z) as $entity){
				if($entity instanceof Player){
					continue;
				}
				$entity->close();
			}

			$chunk->onUnload();
		}

		unset($this->chunks[$chunkHash]);
		$this->blockCacheSize -= count($this->blockCache[$chunkHash] ?? []);
		unset($this->blockCache[$chunkHash]);
		unset($this->blockCollisionBoxCache[$chunkHash]);
		unset($this->changedBlocks[$chunkHash]);
		unset($this->registeredTickingChunks[$chunkHash]);
		$this->markTickingChunkForRecheck($x, $z);

		if(array_key_exists($chunkHash, $this->chunkPopulationRequestMap)){
			$this->logger->debug("Rejecting population promise for chunk $x $z");
			$this->chunkPopulationRequestMap[$chunkHash]->reject();
			unset($this->chunkPopulationRequestMap[$chunkHash]);
			if(isset($this->activeChunkPopulationTasks[$chunkHash])){
				$this->logger->debug("Marking population task for chunk $x $z as orphaned");
				$this->activeChunkPopulationTasks[$chunkHash] = false;
			}
		}

		$this->timings->doChunkUnload->stopTiming();

		return true;
	}

	/**
	 * Returns whether the chunk at the specified coordinates is a spawn chunk
	 */
	public function isSpawnChunk(int $X, int $Z) : bool{
		$spawn = $this->getSpawnLocation();
		$spawnX = $spawn->x >> Chunk::COORD_BIT_SIZE;
		$spawnZ = $spawn->z >> Chunk::COORD_BIT_SIZE;

		return abs($X - $spawnX) <= 1 && abs($Z - $spawnZ) <= 1;
	}

	/**
	 * Requests a safe spawn position near the given position, or near the world's spawn position if not provided.
	 * Terrain near the position will be loaded or generated as needed.
	 *
	 * @return Promise Resolved to a Position object, or rejected if the world is unloaded.
	 * @phpstan-return Promise<Position>
	 */
	public function requestSafeSpawn(?Vector3 $spawn = null) : Promise{
		/** @phpstan-var PromiseResolver<Position> $resolver */
		$resolver = new PromiseResolver();
		$spawn ??= $this->getSpawnLocation();
		/*
		 * TODO: this relies on the assumption that getSafeSpawn() will only alter the Y coordinate of the provided
		 * position, which is currently OK, but might be a problem in the future.
		 */
		$this->requestChunkPopulation($spawn->getFloorX() >> Chunk::COORD_BIT_SIZE, $spawn->getFloorZ() >> Chunk::COORD_BIT_SIZE, null)->onCompletion(
			function() use ($spawn, $resolver) : void{
				$spawn = $this->getSafeSpawn($spawn);
				$resolver->resolve($spawn);
			},
			function() use ($resolver) : void{
				$resolver->reject();
			}
		);

		return $resolver->getPromise();
	}

	/**
	 * Returns a safe spawn position near the given position, or near the world's spawn position if not provided.
	 * This function will throw an exception if the terrain is not already generated in advance.
	 *
	 * @throws WorldException if the terrain is not generated
	 */
	public function getSafeSpawn(?Vector3 $spawn = null) : Position{
		if(!($spawn instanceof Vector3) || $spawn->y <= $this->minY){
			$spawn = $this->getSpawnLocation();
		}

		$max = $this->maxY;
		$v = $spawn->floor();
		$chunk = $this->getOrLoadChunkAtPosition($v);
		if($chunk === null){
			throw new WorldException("Cannot find a safe spawn point in non-generated terrain");
		}
		$x = (int) $v->x;
		$z = (int) $v->z;
		$y = (int) min($max - 2, $v->y);
		$wasAir = $this->getBlockAt($x, $y - 1, $z)->getTypeId() === BlockTypeIds::AIR; //TODO: bad hack, clean up
		for(; $y > $this->minY; --$y){
			if($this->getBlockAt($x, $y, $z)->isFullCube()){
				if($wasAir){
					$y++;
				}
				break;
			}else{
				$wasAir = true;
			}
		}

		for(; $y >= $this->minY && $y < $max; ++$y){
			if(!$this->getBlockAt($x, $y + 1, $z)->isFullCube()){
				if(!$this->getBlockAt($x, $y, $z)->isFullCube()){
					return new Position($spawn->x, $y === (int) $spawn->y ? $spawn->y : $y, $spawn->z, $this);
				}
			}else{
				++$y;
			}
		}

		return new Position($spawn->x, $y, $spawn->z, $this);
	}

	/**
	 * Gets the current time
	 */
	public function getTime() : int{
		return $this->time;
	}

	/**
	 * Returns the current time of day
	 */
	public function getTimeOfDay() : int{
		return $this->time % self::TIME_FULL;
	}

	/**
	 * Returns the World display name.
	 * WARNING: This is NOT guaranteed to be unique. Multiple worlds at runtime may share the same display name.
	 */
	public function getDisplayName() : string{
		return $this->displayName;
	}

	/**
	 * Sets the World display name.
	 */
	public function setDisplayName(string $name) : void{
		(new WorldDisplayNameChangeEvent($this, $this->displayName, $name))->call();

		$this->displayName = $name;
		$this->provider->getWorldData()->setName($name);
	}

	/**
	 * Returns the World folder name. This will not change at runtime and will be unique to a world per runtime.
	 */
	public function getFolderName() : string{
		return $this->folderName;
	}

	/**
	 * Sets the current time on the world
	 */
	public function setTime(int $time) : void{
		$this->time = $time;
		$this->sendTime();
	}

	/**
	 * Stops the time for the world, will not save the lock state to disk
	 */
	public function stopTime() : void{
		$this->stopTime = true;
		$this->sendTime();
	}

	/**
	 * Start the time again, if it was stopped
	 */
	public function startTime() : void{
		$this->stopTime = false;
		$this->sendTime();
	}

	/**
	 * Gets the world seed
	 */
	public function getSeed() : int{
		return $this->provider->getWorldData()->getSeed();
	}

	public function getMinY() : int{
		return $this->minY;
	}

	public function getMaxY() : int{
		return $this->maxY;
	}

	/**
	 * Returns the minimal Y level which an entity takes damage upon
	 */
	public function getDamageY() : int{
		return $this->damageY;
	}

	/** Rain and thunder for this world; Nether and End always report clear. */
	public function getWeather() : Weather{
		return $this->weather;
	}

	public function getDifficulty() : int{
		return $this->provider->getWorldData()->getDifficulty();
	}

	public function setDifficulty(int $difficulty) : void{
		if($difficulty < 0 || $difficulty > 3){
			throw new \InvalidArgumentException("Invalid difficulty level $difficulty");
		}
		(new WorldDifficultyChangeEvent($this, $this->getDifficulty(), $difficulty))->call();
		$this->provider->getWorldData()->setDifficulty($difficulty);

		foreach($this->players as $player){
			$player->getNetworkSession()->syncWorldDifficulty($this->getDifficulty());
		}
	}

	private function addChunkHashToPopulationRequestQueue(int $chunkHash) : void{
		if(!isset($this->chunkPopulationRequestQueueIndex[$chunkHash])){
			$this->chunkPopulationRequestQueue->enqueue($chunkHash);
			$this->chunkPopulationRequestQueueIndex[$chunkHash] = true;
		}
	}

	/**
	 * @phpstan-return Promise<Chunk>
	 */
	private function enqueuePopulationRequest(int $chunkX, int $chunkZ, ?ChunkLoader $associatedChunkLoader) : Promise{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		$this->addChunkHashToPopulationRequestQueue($chunkHash);
		/** @phpstan-var PromiseResolver<Chunk> $resolver */
		$resolver = $this->chunkPopulationRequestMap[$chunkHash] = new PromiseResolver();
		if($associatedChunkLoader === null){
			$temporaryLoader = new class implements ChunkLoader{};
			$this->registerChunkLoader($temporaryLoader, $chunkX, $chunkZ);
			$release = function() use ($temporaryLoader, $chunkX, $chunkZ) : void{
				$this->unregisterChunkLoader($temporaryLoader, $chunkX, $chunkZ);
			};
			$resolver->getPromise()->onCompletion($release, function() use ($release) : void{
				if($this->chunkPopulationBlocked){
					$release();
				}
			});
		}
		return $resolver->getPromise();
	}

	private function drainPopulationRequestQueue() : void{
		$failed = [];
		while(count($this->activeChunkPopulationTasks) < $this->maxConcurrentChunkPopulationTasks && !$this->chunkPopulationRequestQueue->isEmpty()){
			$nextChunkHash = $this->chunkPopulationRequestQueue->dequeue();
			unset($this->chunkPopulationRequestQueueIndex[$nextChunkHash]);
			World::getXZ($nextChunkHash, $nextChunkX, $nextChunkZ);
			if(isset($this->chunkPopulationRequestMap[$nextChunkHash])){
				assert(!($this->activeChunkPopulationTasks[$nextChunkHash] ?? false), "Population for chunk $nextChunkX $nextChunkZ already running");
				if(
					!$this->orderChunkPopulation($nextChunkX, $nextChunkZ, null)->isResolved() &&
					!isset($this->activeChunkPopulationTasks[$nextChunkHash])
				){
					$failed[] = $nextChunkHash;
				}
			}
		}

		//these requests failed even though they weren't rate limited; we can't directly re-add them to the back of the
		//queue because it would result in an infinite loop
		foreach($failed as $hash){
			$this->addChunkHashToPopulationRequestQueue($hash);
		}
	}

	/**
	 * Checks if a chunk needs to be populated, and whether it's ready to do so.
	 * @return bool[]|PromiseResolver[]|null[]
	 * @phpstan-return array{?PromiseResolver<Chunk>, bool}
	 */
	private function checkChunkPopulationPreconditions(int $chunkX, int $chunkZ) : array{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		$resolver = $this->chunkPopulationRequestMap[$chunkHash] ?? null;
		if($resolver !== null && isset($this->activeChunkPopulationTasks[$chunkHash])){
			//generation is already running
			return [$resolver, false];
		}

		$temporaryChunkLoader = new class implements ChunkLoader{};
		$this->registerChunkLoader($temporaryChunkLoader, $chunkX, $chunkZ);
		$chunk = $this->loadChunk($chunkX, $chunkZ);
		$this->unregisterChunkLoader($temporaryChunkLoader, $chunkX, $chunkZ);
		if($chunk !== null && $chunk->isPopulated()){
			//chunk is already populated; return a pre-resolved promise that will directly fire callbacks assigned
			$resolver ??= new PromiseResolver();
			unset($this->chunkPopulationRequestMap[$chunkHash]);
			$resolver->resolve($chunk);
			return [$resolver, false];
		}
		if($this->chunkPopulationBlocked){
			$resolver ??= new PromiseResolver();
			unset($this->chunkPopulationRequestMap[$chunkHash]);
			$resolver->reject();
			return [$resolver, false];
		}
		return [$resolver, true];
	}

	/** @internal Whether this world has stopped generating after a pinned-cache validation failure. */
	public function isChunkPopulationBlocked() : bool{
		return $this->chunkPopulationBlocked;
	}

	/**
	 * Attempts to initiate asynchronous generation/population of the target chunk, if it's currently reasonable to do
	 * so (and if it isn't already generated/populated).
	 * If the generator is busy, the request will be put into a queue and delayed until a better time.
	 *
	 * A ChunkLoader can be associated with the generation request to ensure that the generation request is cancelled if
	 * no loaders are attached to the target chunk. If no loader is provided, one will be assigned (and automatically
	 * removed when the generation request completes).
	 *
	 * @phpstan-return Promise<Chunk>
	 */
	public function requestChunkPopulation(int $chunkX, int $chunkZ, ?ChunkLoader $associatedChunkLoader) : Promise{
		[$resolver, $proceedWithPopulation] = $this->checkChunkPopulationPreconditions($chunkX, $chunkZ);
		if(!$proceedWithPopulation){
			return $resolver?->getPromise() ?? $this->enqueuePopulationRequest($chunkX, $chunkZ, $associatedChunkLoader);
		}

		if(count($this->activeChunkPopulationTasks) >= $this->maxConcurrentChunkPopulationTasks){
			//too many chunks are already generating; delay resolution of the request until later
			return $resolver?->getPromise() ?? $this->enqueuePopulationRequest($chunkX, $chunkZ, $associatedChunkLoader);
		}
		return $this->internalOrderChunkPopulation($chunkX, $chunkZ, $associatedChunkLoader, $resolver);
	}

	/**
	 * Initiates asynchronous generation/population of the target chunk, if it's not already generated/populated.
	 * If generation has already been requested for the target chunk, the promise for the already active request will be
	 * returned directly.
	 *
	 * If the chunk is currently locked (for example due to another chunk using it for async generation), the request
	 * will be queued and executed at the earliest opportunity.
	 *
	 * @phpstan-return Promise<Chunk>
	 */
	public function orderChunkPopulation(int $chunkX, int $chunkZ, ?ChunkLoader $associatedChunkLoader) : Promise{
		[$resolver, $proceedWithPopulation] = $this->checkChunkPopulationPreconditions($chunkX, $chunkZ);
		if(!$proceedWithPopulation){
			return $resolver?->getPromise() ?? $this->enqueuePopulationRequest($chunkX, $chunkZ, $associatedChunkLoader);
		}

		return $this->internalOrderChunkPopulation($chunkX, $chunkZ, $associatedChunkLoader, $resolver);
	}

	/**
	 * @phpstan-param PromiseResolver<Chunk>|null $resolver
	 * @phpstan-return Promise<Chunk>
	 */
	private function internalOrderChunkPopulation(int $chunkX, int $chunkZ, ?ChunkLoader $associatedChunkLoader, ?PromiseResolver $resolver) : Promise{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);

		$timings = $this->timings->chunkPopulationOrder;
		$timings->startTiming();

		try{
			for($xx = -1; $xx <= 1; ++$xx){
				for($zz = -1; $zz <= 1; ++$zz){
					if($this->isChunkLocked($chunkX + $xx, $chunkZ + $zz)){
						//chunk is already in use by another generation request; queue the request for later
						return $resolver?->getPromise() ?? $this->enqueuePopulationRequest($chunkX, $chunkZ, $associatedChunkLoader);
					}
				}
			}

			$this->activeChunkPopulationTasks[$chunkHash] = true;
			if($resolver === null){
				$resolver = new PromiseResolver();
				$this->chunkPopulationRequestMap[$chunkHash] = $resolver;
			}

			$chunkPopulationLockId = new ChunkLockId();

			$temporaryChunkLoader = new class implements ChunkLoader{
			};
			for($xx = -1; $xx <= 1; ++$xx){
				for($zz = -1; $zz <= 1; ++$zz){
					$this->lockChunk($chunkX + $xx, $chunkZ + $zz, $chunkPopulationLockId);
					$this->registerChunkLoader($temporaryChunkLoader, $chunkX + $xx, $chunkZ + $zz);
				}
			}

			$centerChunk = $this->loadChunk($chunkX, $chunkZ);
			$adjacentChunks = $this->getAdjacentChunks($chunkX, $chunkZ);

			$onCompletion = function(Chunk $centerChunk, array $adjacentChunks) use ($chunkPopulationLockId, $chunkX, $chunkZ, $temporaryChunkLoader) : void{
				if($this->isLoaded()){
					$this->generateChunkCallback($chunkPopulationLockId, $chunkX, $chunkZ, $centerChunk, $adjacentChunks, $temporaryChunkLoader);
				}
			};
			if($this->generatorExecutor instanceof AsyncGeneratorExecutor){
				$this->generatorExecutor->populate($chunkX, $chunkZ, $centerChunk, $adjacentChunks, $onCompletion,
					function(string $message) use ($chunkPopulationLockId, $chunkX, $chunkZ, $temporaryChunkLoader) : void{
						if($this->isLoaded()){
							$this->abortChunkPopulation($message, $chunkPopulationLockId, $chunkX, $chunkZ, $temporaryChunkLoader);
						}
					}
				);
			}else{
				$this->generatorExecutor->populate($chunkX, $chunkZ, $centerChunk, $adjacentChunks, $onCompletion);
			}

			return $resolver->getPromise();
		}finally{
			$timings->stopTiming();
		}
	}

	private function abortChunkPopulation(string $reason, ChunkLockId $lock, int $x, int $z, ChunkLoader $loader) : void{
		$this->releasePopulationArea($lock, $x, $z, $loader);
		unset($this->activeChunkPopulationTasks[self::chunkHash($x, $z)]);
		if($this->chunkPopulationBlocked){
			return;
		}

		$this->chunkPopulationBlocked = true;
		$this->logger->error("$reason. New chunk generation is disabled until the cache is restored and the world is reopened.");
		$requests = $this->chunkPopulationRequestMap;
		$this->chunkPopulationRequestMap = [];
		$this->chunkPopulationRequestQueue = new \SplQueue();
		$this->chunkPopulationRequestQueueIndex = [];
		foreach($requests as $request){
			$request->reject();
		}
	}

	private function releasePopulationArea(ChunkLockId $lock, int $x, int $z, ChunkLoader $loader) : int{
		$dirtyChunks = 0;
		for($xx = -1; $xx <= 1; ++$xx){
			for($zz = -1; $zz <= 1; ++$zz){
				$this->unregisterChunkLoader($loader, $x + $xx, $z + $zz);
				if(!$this->unlockChunk($x + $xx, $z + $zz, $lock)){
					++$dirtyChunks;
				}
			}
		}
		return $dirtyChunks;
	}

	/**
	 * @param Chunk[] $adjacentChunks chunkHash => chunk
	 * @phpstan-param array<int, Chunk> $adjacentChunks
	 */
	private function generateChunkCallback(ChunkLockId $chunkLockId, int $x, int $z, Chunk $chunk, array $adjacentChunks, ChunkLoader $temporaryChunkLoader) : void{
		$timings = $this->timings->chunkPopulationCompletion;
		$timings->startTiming();

		$dirtyChunks = $this->releasePopulationArea($chunkLockId, $x, $z, $temporaryChunkLoader);
		$index = World::chunkHash($x, $z);
		if($this->chunkPopulationBlocked){
			unset($this->activeChunkPopulationTasks[$index]);
			$timings->stopTiming();
			return;
		}
		if(!isset($this->activeChunkPopulationTasks[$index])){
			throw new AssumptionFailedError("This should always be set, regardless of whether the task was orphaned or not");
		}
		if(!$this->activeChunkPopulationTasks[$index]){
			$this->logger->debug("Discarding orphaned population result for chunk x=$x,z=$z");
			unset($this->activeChunkPopulationTasks[$index]);
		}else{
			if($dirtyChunks === 0){
				$oldChunk = $this->loadChunk($x, $z);
				$this->setChunk($x, $z, $chunk);

				foreach($adjacentChunks as $relativeChunkHash => $adjacentChunk){
					World::getXZ($relativeChunkHash, $relativeX, $relativeZ);
					if($relativeX < -1 || $relativeX > 1 || $relativeZ < -1 || $relativeZ > 1){
						throw new AssumptionFailedError("Adjacent chunks should be in range -1 ... +1 coordinates");
					}
					$this->setChunk($x + $relativeX, $z + $relativeZ, $adjacentChunk);
				}

				if(($oldChunk === null || !$oldChunk->isPopulated()) && $chunk->isPopulated()){
					if(ChunkPopulateEvent::hasHandlers()){
						(new ChunkPopulateEvent($this, $x, $z, $chunk))->call();
					}
					$this->server->getDimensionManager()->onChunkPopulated($this, $x, $z);

					foreach($this->getChunkListeners($x, $z) as $listener){
						$listener->onChunkPopulated($x, $z, $chunk);
					}
				}
			}else{
				$this->logger->debug("Discarding population result for chunk x=$x,z=$z - terrain was modified on the main thread before async population completed");
			}

			//This needs to be in this specific spot because user code might call back to orderChunkPopulation().
			//If it does, and finds the promise, and doesn't find an active task associated with it, it will schedule
			//another PopulationTask. We don't want that because we're here processing the results.
			//We can't remove the promise from the array before setting the chunks in the world because that would lead
			//to the same problem. Therefore, it's necessary that this code be split into two if/else, with this in the
			//middle.
			unset($this->activeChunkPopulationTasks[$index]);

			if($dirtyChunks === 0){
				$promise = $this->chunkPopulationRequestMap[$index] ?? null;
				if($promise !== null){
					unset($this->chunkPopulationRequestMap[$index]);
					$promise->resolve($chunk);
				}else{
					//Handlers of ChunkPopulateEvent, ChunkLoadEvent, or just ChunkListeners can cause this
					$this->logger->debug("Unable to resolve population promise for chunk x=$x,z=$z - populated chunk was forcibly unloaded while setting modified chunks");
				}
			}else{
				//request failed, stick it back on the queue
				//we didn't resolve the promise or touch it in any way, so any fake chunk loaders are still valid and
				//don't need to be added a second time.
				$this->addChunkHashToPopulationRequestQueue($index);
			}

			$this->drainPopulationRequestQueue();
		}
		$timings->stopTiming();
	}

	public function doChunkGarbageCollection() : void{
		$this->timings->doChunkGC->startTiming();

		foreach($this->chunks as $index => $chunk){
			if(!isset($this->unloadQueue[$index])){
				World::getXZ($index, $X, $Z);
				if(!$this->isSpawnChunk($X, $Z)){
					$this->unloadChunkRequest($X, $Z, true);
				}
			}
			$chunk->collectGarbage();
		}

		$this->provider->doGarbageCollection();

		$this->timings->doChunkGC->stopTiming();
	}

	public function unloadChunks(bool $force = false) : void{
		if(count($this->unloadQueue) > 0){
			$maxUnload = 96;
			$now = microtime(true);
			foreach($this->unloadQueue as $index => $time){
				World::getXZ($index, $X, $Z);

				if(!$force){
					if($maxUnload <= 0){
						break;
					}elseif($time > ($now - 30)){
						continue;
					}
				}

				//If the chunk can't be unloaded, it stays on the queue
				if($this->unloadChunk($X, $Z, true)){
					unset($this->unloadQueue[$index]);
					--$maxUnload;
				}
			}
		}
	}
}

