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

use pocketmine\ActiveResourceProfile;
use pocketmine\block\Air;
use pocketmine\block\BaseFire;
use pocketmine\block\BaseSign;
use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\DecoratedPot;
use pocketmine\block\Fire;
use pocketmine\block\ItemFrame;
use pocketmine\block\Lava;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\tile\Container as TileContainer;
use pocketmine\block\tile\Spawnable;
use pocketmine\block\tile\Tile;
use pocketmine\block\tile\TileFactory;
use pocketmine\block\UnknownBlock;
use pocketmine\block\utils\BuildPermissionBlocks;
use pocketmine\block\utils\GameMasterBlocks;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\block\utils\Waterlogged;
use pocketmine\block\utils\WaterloggedHelper;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\WaterCauldron;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\data\bedrock\block\BlockStateDeserializeException;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\entity\ai\AiBudget;
use pocketmine\entity\ai\navigation\PathRecomputeWatch;
use pocketmine\entity\animal\Cat;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\EntitySpawnRegistry;
use pocketmine\entity\Location;
use pocketmine\entity\monster\PiglinAi;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\object\ExperienceOrb;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\projectile\Projectile;
use pocketmine\entity\SpawnReason;
use pocketmine\entity\vehicle\ContainerMinecart;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\BlockUpdateEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\world\ChunkLoadEvent;
use pocketmine\event\world\ChunkPopulateEvent;
use pocketmine\event\world\ChunkUnloadEvent;
use pocketmine\event\world\SpawnChangeEvent;
use pocketmine\event\world\WorldDifficultyChangeEvent;
use pocketmine\event\world\WorldDisplayNameChangeEvent;
use pocketmine\event\world\WorldGameRuleChangeEvent;
use pocketmine\event\world\WorldParticleEvent;
use pocketmine\event\world\WorldSaveEvent;
use pocketmine\event\world\WorldSoundEvent;
use pocketmine\item\Fertilizer;
use pocketmine\item\Item;
use pocketmine\item\ItemUseResult;
use pocketmine\item\LegacyStringToItemParser;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\player\Player;
use pocketmine\plugin\PluginFaultHandler;
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
use pocketmine\world\dimension\Dimension;
use pocketmine\world\dimension\DragonFight;
use pocketmine\world\dimension\NetherPortalShape;
use pocketmine\world\dimension\PortalTravel;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\ChunkData;
use pocketmine\world\format\io\exception\CorruptedChunkException;
use pocketmine\world\format\io\GameRulesWorldData;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\format\io\MobSpawningWorldData;
use pocketmine\world\format\io\ScheduledBlockUpdatesWorldProvider;
use pocketmine\world\format\io\WorldAgeWorldData;
use pocketmine\world\format\io\WritableWorldProvider;
use pocketmine\world\format\io\WriteBatchWorldProvider;
use pocketmine\world\format\LightArray;
use pocketmine\world\format\NativeEntityPhysics;
use pocketmine\world\format\RandomTickSampler;
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
use pocketmine\world\mobs\NaturalMobSpawner;
use pocketmine\world\mobs\PatrolSpawner;
use pocketmine\world\mobs\spawn\StructureSpawns;
use pocketmine\world\particle\BlockBreakParticle;
use pocketmine\world\particle\BoneMealUseParticle;
use pocketmine\world\particle\Particle;
use pocketmine\world\raid\PoiManager;
use pocketmine\world\raid\RaidManager;
use pocketmine\world\redstone\RedstoneEngine;
use pocketmine\world\sound\BlockPlaceSound;
use pocketmine\world\sound\Sound;
use pocketmine\world\utils\SubChunkExplorer;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\vibration\VibrationManager;
use pocketmine\world\weather\Weather;
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
use function class_exists;
use function cos;
use function count;
use function floor;
use function get_class;
use function gettype;
use function is_a;
use function is_array;
use function is_int;
use function is_object;
use function is_string;
use function max;
use function method_exists;
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

	/**
	 * Random ticks per subchunk per game tick at randomtickspeed 1. Measured on BDS 1.26.52 (ice melting and grass
	 * dying under stone in a ticking area): about 2.5 per subchunk per tick, so the Bedrock rate is close to this
	 * value and not 1.
	 */
	public const DEFAULT_TICKED_BLOCKS_PER_SUBCHUNK_PER_TICK = 3;
	/** Bedrock Dedicated Server's default simulation distance (server.properties tick-distance=4). */
	public const DEFAULT_SIMULATION_DISTANCE = 4;

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
	 * @var AxisAlignedBB[][][] chunkHash => [collisionCellKey => AxisAlignedBB[]]
	 * @phpstan-var array<ChunkPosHash, array<int, list<AxisAlignedBB>>>
	 */
	private array $blockCollisionBoxCache = [];

	private int $sendTimeTicker = 0;

	private int $worldId;

	private int $providerGarbageCollectionTicker = 0;

	private int $minY;
	private int $maxY;

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
	 * @var ClientboundPacket[][]
	 * @phpstan-var array<ChunkPosHash, list<ClientboundPacket>>
	 */
	private array $packetBuffersByChunk = [];

	/**
	 * @var float[] chunkHash => timestamp of request
	 * @phpstan-var array<ChunkPosHash, float>
	 */
	private array $unloadQueue = [];

	/**
	 * Most time spent unloading queued chunks in one tick (seconds); the remaining chunks stay queued for the next
	 * ticks. {@link World::MIN_CHUNK_UNLOADS_PER_TICK} chunks are always unloaded when possible, so the queue keeps
	 * moving.
	 */
	private const CHUNK_UNLOAD_TIME_BUDGET = 0.008;
	private const MIN_CHUNK_UNLOADS_PER_TICK = 4;
	/**
	 * Seconds a chunk nobody uses stays loaded before it is unloaded (a player walking back finds it still loaded).
	 * With 100 players exploring, a quarter of the loaded chunks are such chunks; on low memory they are unloaded at
	 * once, with {@link World::LOW_MEMORY_CHUNK_UNLOAD_TIME_BUDGET} and up to
	 * {@link World::LOW_MEMORY_MAX_CHUNK_UNLOADS_PER_TICK} chunks per tick.
	 */
	private const CHUNK_UNLOAD_DELAY = 30;
	/** Seconds before an unused chunk is unloaded: the resource profile's {@link \pocketmine\ResourceProfile::getChunkUnloadDelay()}. */
	private int $chunkUnloadDelay = self::CHUNK_UNLOAD_DELAY;
	private const LOW_MEMORY_CHUNK_UNLOAD_TIME_BUDGET = 0.025;
	private const LOW_MEMORY_MAX_CHUNK_UNLOADS_PER_TICK = 256;

	/**
	 * Chunks whose last save (by this world) cleared their terrain dirty flags and wrote no entities, tiles or
	 * scheduled block updates, with the populated flag they were saved with. While such a chunk still has none of
	 * these, no dirty flags and the same populated flag, saving it again would write exactly the same data, so saves
	 * skip it (see {@link World::isChunkUnchangedSinceSave()}). Chunks loaded from the provider are never in here
	 * until this world saved them: their stored data may differ from what this world would write.
	 * @phpstan-var array<ChunkPosHash, array{Chunk, bool}>
	 */
	private array $cleanSavedChunks = [];

	/** Depth of nested provider write batches opened by {@link World::beginWriteBatch()}. */
	private int $writeBatchDepth = 0;
	/**
	 * Chunks saved in the open write batch, made dirty again if the batch fails to be written.
	 * @phpstan-var array<ChunkPosHash, Chunk>
	 */
	private array $writeBatchChunks = [];

	/**
	 * Chunks to look at in the autosave in progress (see {@link World::startAutoSave()}), or null if none.
	 * @phpstan-var list<ChunkPosHash>|null
	 */
	private ?array $autoSaveQueue = null;
	private int $autoSaveQueuePosition = 0;
	private bool $autoSaveStarted = false;

	private int $time;
	public bool $stopTime = false;
	/** Ticks the world has run since it was created (not changed by /time, see {@link World::getWorldAge()}) */
	private int $worldAge;

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
	 * Due scheduled updates of blocks that wait while their chunk is not ticked, see
	 * {@link Block::pausesScheduledUpdatesOutsideTickingChunks()}.
	 * @var Vector3[][] chunkHash => blockHash => position
	 * @phpstan-var array<int, array<int, Vector3>>
	 */
	private array $pausedBlockUpdates = [];
	/**
	 * @var int[] blockHash => tick delay
	 * @phpstan-var array<BlockPosHash, int>
	 */
	private array $scheduledBlockUpdateQueueIndex = [];
	/**
	 * Due tick of the queued scheduled updates of each chunk, so that a chunk's updates can be saved with it (and
	 * dropped when it unloads) without going through the whole queue.
	 * @var int[][] chunkHash => blockHash => due tick
	 * @phpstan-var array<ChunkPosHash, array<BlockPosHash, int>>
	 */
	private array $scheduledBlockUpdateChunkIndex = [];
	/**
	 * Queue entries of unloaded chunks: they were saved with their chunk, so they are skipped when they come up. Several
	 * stale entries can share a due tick (a chunk unloaded and reloaded more than once in the same tick), so they are
	 * counted.
	 * @var int[][] blockHash => due tick => number of stale entries
	 * @phpstan-var array<BlockPosHash, array<int, int>>
	 */
	private array $droppedScheduledBlockUpdates = [];

	/** @phpstan-var \SplQueue<int> */
	private \SplQueue $neighbourBlockUpdateQueue;
	/**
	 * @var true[] blockhash => dummy
	 * @phpstan-var array<BlockPosHash, true>
	 */
	private array $neighbourBlockUpdateQueueIndex = [];

	/**
	 * Pending flows of the water held in the liquid layer by waterlogged blocks, which spreads like a water source.
	 * Ticks are in increasing order, since the delay is always the same.
	 * @var true[][] tick => blockHash => dummy
	 * @phpstan-var array<int, array<BlockPosHash, true>>
	 */
	private array $liquidLayerFlowQueue = [];
	/**
	 * @var true[] blockHash => dummy
	 * @phpstan-var array<BlockPosHash, true>
	 */
	private array $liquidLayerFlowQueueIndex = [];
	/** Ticks between a change next to a waterlogged block and its water flowing out (the tick rate of water). */
	private const LIQUID_LAYER_FLOW_DELAY = 5;
	/** Bits of subChunkRandomTickedBlocks(): random-ticked lava, other random-ticked blocks */
	private const RANDOM_TICK_LAVA = 1;
	private const RANDOM_TICK_OTHER = 2;
	/** Ticks the answer of canLavaIgnite() is reused for */
	private const LAVA_IGNITION_RECHECK_TICKS = 40;
	/**
	 * Hash of the block being broken by {@link World::useBreakOn()}: when it holds water in the liquid layer and is set
	 * to air, the water stays as a water source.
	 * @phpstan-var BlockPosHash|null
	 */
	private ?int $breakingBlockHash = null;
	/**
	 * Whether {@link World::useItemOn()} is setting the blocks of a placement: waterloggable blocks set over a water
	 * source then keep the water in the liquid layer.
	 */
	private bool $placingBlocks = false;

	private ?RedstoneEngine $redstone = null;
	private VibrationManager $vibrations;
	private ?NaturalMobSpawner $mobSpawner = null;
	private ?PatrolSpawner $patrolSpawner = null;
	/** Gamerule domobspawning (cached from the world data). */
	private bool $mobSpawningRule = true;
	/**
	 * Game rules without a state of their own (see getGameRule()).
	 * @var bool[]|int[]
	 * @phpstan-var array<string, bool|int>
	 */
	private array $gameRules = [];
	/** Random ticks per subchunk from pocketmine.yml, multiplied by the randomtickspeed game rule. */
	private int $configuredTickedBlocksPerSubchunkPerTick = self::DEFAULT_TICKED_BLOCKS_PER_SUBCHUNK_PER_TICK;
	/** Mobs are turned off in this world (mobs.disabled-worlds in pocketmine.yml). */
	private bool $mobsDisabled = false;
	private RaidManager $raidManager;
	private PoiManager $poiManager;
	private Weather $weather;
	/** Paths of the mobs in this world, created by the first mob following a path (see getPathRecomputeWatch()). */
	private ?PathRecomputeWatch $pathRecomputeWatch = null;

	/**
	 * @var bool[] chunkHash => isValid
	 * @phpstan-var array<ChunkPosHash, bool>
	 */
	private array $activeChunkPopulationTasks = [];
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
	/**
	 * Radius of chunks around each player in which entities are ticked (Bedrock "simulation distance"); 0 = entities
	 * in every loaded chunk are ticked.
	 */
	private int $simulationDistance;
	/**
	 * Chunks within the simulation distance of a player (or registered by a plugin), whose entities are ticked.
	 * @var ChunkTicker[][] chunkHash => tickerId => ticker
	 * @phpstan-var array<ChunkPosHash, array<int, ChunkTicker>>
	 */
	private array $registeredSimulatingChunks = [];
	private int $tickedBlocksPerSubchunkPerTick = self::DEFAULT_TICKED_BLOCKS_PER_SUBCHUNK_PER_TICK;
	/**
	 * @var true[]
	 * @phpstan-var array<int, true>
	 */
	private array $randomTickBlocks = [];
	/**
	 * Lava states (exactly {@link Lava}, not subclasses) that may be random-ticked: their random ticks only start fires,
	 * so they are left out of $randomTickBlocks while doFireTick is off, and ticked through {@link Lava::randomTickAt()}
	 * without building a block otherwise.
	 * @var true[]
	 * @phpstan-var array<int, true>
	 */
	private array $lavaRandomTickStates = [];
	/**
	 * $lavaRandomTickStates while doFireTick is on, empty otherwise: random ticks of these states go to
	 * {@link Lava::randomTickAt()}, which does not check doFireTick.
	 * @var true[]
	 * @phpstan-var array<int, true>
	 */
	private array $lavaFastRandomTickStates = [];
	/**
	 * Whether each loaded subchunk's palette contains any random-ticked block, with the subchunk's block modification
	 * count the answer was computed at. Most subchunks of a real world (stone, deepslate, ores...) have none, and
	 * picking random blocks in them every tick is wasted work. See subChunkRandomTickEntry() for the entries.
	 * @phpstan-var \WeakMap<SubChunk, array{int, int, int, bool}>
	 */
	private \WeakMap $randomTickSubChunkCache;
	/**
	 * Whether each subchunk's layer 0 palette contains a flammable block, with the subchunk's block modification count
	 * the answer was computed at.
	 * @phpstan-var \WeakMap<SubChunk, array{int, bool}>
	 */
	private \WeakMap $flammableSubChunkCache;
	/**
	 * Native random ticks only: whether lava in each subchunk may set anything on fire (canLavaIgnite()), with the world
	 * age until which the answer is used. Lava seas are full of random ticks which can't do anything.
	 * @phpstan-var \WeakMap<SubChunk, array{int, bool}>
	 */
	private \WeakMap $lavaIgnitionSubChunkCache;
	/**
	 * @var bool[] state ID => whether the block is flammable, filled when needed
	 * @phpstan-var array<int, bool>
	 */
	private array $flammableStates = [];
	/** Native random tick picks (chunkutils2 >= 0.3.6), null with older extensions. */
	private ?RandomTickSampler $randomTickSampler = null;
	private bool $randomTickSamplerReadsSubChunks = false;
	/** chunkutils2 >= 0.3.14: the sampler walks the ticking chunks itself (see tickChunksNative()). */
	private bool $randomTickSamplerTicksChunks = false;

	/** Number of entities in this world whose class may be stood on (see {@link World::hasEntitiesThatMayBeStoodOn()}). */
	private int $entitiesThatMayBeStoodOn = 0;
	/**
	 * @var bool[] entity class => whether it overrides Entity::canBeStoodOn()
	 * @phpstan-var array<class-string<Entity>, bool>
	 */
	private static array $mayBeStoodOnByClass = [];
	/**
	 * Number of entities of this world which are instances of each class asked about with
	 * {@link World::hasEntitiesOfClass()} (a handful of classes, counted from the first question on).
	 * @var int[]
	 * @phpstan-var array<class-string<Entity>, int>
	 */
	private array $entityCountsByClass = [];

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
	 * Key of a block in the collision box cache of its chunk (keyed by chunkHash()). It tells blocks apart exactly like
	 * chunkBlockHash() does within a chunk (the X and Z inside the chunk, and Y modulo 2^21, as morton3d_encode() keeps 21
	 * bits of Y), and is simple enough for the native movement clipping of chunkutils2 to compute the same way (the bits
	 * morton3d_encode() keeps beyond those depend on how ext-morton was built).
	 */
	private static function collisionCellKey(int $x, int $y, int $z) : int{
		return (($y & 0x1fffff) << 8) | (($x & 0xf) << 4) | ($z & 0xf);
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
		private AsyncPool $workerPool,
		private Dimension $dimension = Dimension::OVERWORLD
	){
		$this->folderName = $name;
		$this->worldId = self::$worldIdCounter++;

		$this->displayName = $this->provider->getWorldData()->getName();
		$this->chunkUnloadDelay = ActiveResourceProfile::get()->getChunkUnloadDelay();
		$this->logger = new \PrefixedLogger($server->getLogger(), "World: $this->displayName");

		$this->blockStateRegistry = RuntimeBlockStateRegistry::getInstance();
		self::getNativeEntityPhysics(); //sets $nativePhysics for the entities of this thread
		//the Nether and the End have a smaller build range than the Overworld, although the storage can hold more
		$this->minY = max($this->provider->getWorldMinY(), $this->dimension->getMinY());
		$this->maxY = min($this->provider->getWorldMaxY(), $this->dimension->getMaxY());

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
		$this->weather = new Weather($this, $this->provider->getWorldData());
		$worldData = $this->provider->getWorldData();
		//worlds saved without an age: the world time is the best estimate (old worlds keep their patrols)
		$this->worldAge = ($worldData instanceof WorldAgeWorldData ? $worldData->getWorldAge() : null) ?? max(0, $this->time);
		$this->mobSpawningRule = !$worldData instanceof MobSpawningWorldData || $worldData->isMobSpawningEnabled();
		foreach(GameRules::getNames() as $rule){
			$stored = $worldData instanceof GameRulesWorldData ? $worldData->getGameRule($rule) : null;
			$this->gameRules[$rule] = $stored !== null ? (GameRules::validate($rule, $stored) ?? GameRules::getDefault($rule)) : GameRules::getDefault($rule);
		}
		if($this->gameRules[GameRules::DO_DAYLIGHT_CYCLE] === false){
			$this->stopTime = true;
		}

		$cfg = $this->server->getConfigGroup();
		$this->chunkTickRadius = min($this->server->getViewDistance(), max(0, $cfg->getPropertyInt(YmlServerProperties::CHUNK_TICKING_TICK_RADIUS, 4)));
		if($cfg->getPropertyInt("chunk-ticking.per-tick", 40) <= 0){
			//TODO: this needs l10n
			$this->logger->warning("\"chunk-ticking.per-tick\" setting is deprecated, but you've used it to disable chunk ticking. Set \"chunk-ticking.tick-radius\" to 0 in \"pocketmine.yml\" instead.");
			$this->chunkTickRadius = 0;
		}
		//without the setting (older pocketmine.yml), a larger tick-radius keeps working as before
		$this->simulationDistance = max(0, $cfg->getPropertyInt(YmlServerProperties::CHUNK_TICKING_SIMULATION_DISTANCE, max(self::DEFAULT_SIMULATION_DISTANCE, $this->chunkTickRadius)));
		if($this->simulationDistance > 0){
			//random ticks only happen in simulated chunks
			$this->chunkTickRadius = min($this->chunkTickRadius, $this->simulationDistance);
		}
		$this->configuredTickedBlocksPerSubchunkPerTick = $cfg->getPropertyInt(YmlServerProperties::CHUNK_TICKING_BLOCKS_PER_SUBCHUNK_PER_TICK, self::DEFAULT_TICKED_BLOCKS_PER_SUBCHUNK_PER_TICK);
		$this->tickedBlocksPerSubchunkPerTick = $this->configuredTickedBlocksPerSubchunkPerTick * (int) $this->gameRules[GameRules::RANDOM_TICK_SPEED];
		$this->maxConcurrentChunkPopulationTasks = $cfg->getPropertyInt(YmlServerProperties::CHUNK_GENERATION_POPULATION_QUEUE_SIZE, 2);

		$this->initRandomTickBlocksFromConfig($cfg);

		if($cfg->getPropertyBool(YmlServerProperties::REDSTONE_ENABLED, true)){
			$this->redstone = new RedstoneEngine($this, max(1, $cfg->getPropertyInt(YmlServerProperties::REDSTONE_MAX_WIRE_UPDATES_PER_TICK, 20000)));
		}
		$this->vibrations = new VibrationManager($this);
		$disabledWorlds = $cfg->getProperty(YmlServerProperties::MOBS_DISABLED_WORLDS, []);
		if(is_array($disabledWorlds)){
			foreach($disabledWorlds as $disabledWorld){
				//YAML reads a world named 123 as an int
				if((is_string($disabledWorld) || is_int($disabledWorld)) && (string) $disabledWorld === $this->folderName){
					$this->mobsDisabled = true;
				}
			}
		}
		if($cfg->getPropertyBool(YmlServerProperties::MOBS_ENABLED, true) && $cfg->getPropertyBool(YmlServerProperties::MOBS_SPAWNING_ENABLED, true) && !$this->mobsDisabled){
			$this->mobSpawner = $mobSpawner = new NaturalMobSpawner($this);
			StructureSpawns::prepare($this);
			$mobSpawner->getRules(); //vanilla spawn rules: loaded now rather than on the first spawn tick
			if($cfg->getPropertyBool(YmlServerProperties::MOBS_SPAWNING_PATROLS_ENABLED, true)){
				$this->patrolSpawner = new PatrolSpawner($this);
			}
		}
		$this->poiManager = new PoiManager($this);
		$this->raidManager = new RaidManager($this, null, $cfg->getPropertyBool(YmlServerProperties::MOBS_ENABLED, true) && $cfg->getPropertyBool(YmlServerProperties::MOBS_RAIDS_ENABLED, true) && !$this->mobsDisabled);

		$this->timings = new WorldTimings($this);
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
				if(get_class($state) === Lava::class){
					$this->lavaRandomTickStates[$state->getStateId()] = true;
					if($this->gameRules[GameRules::DO_FIRE_TICK] === false){
						continue;
					}
				}
				$this->randomTickBlocks[$state->getStateId()] = true;
			}
		}
		if($this->gameRules[GameRules::DO_FIRE_TICK] !== false){
			$this->lavaFastRandomTickStates = $this->lavaRandomTickStates;
		}
		$this->randomTickSubChunkCache = new \WeakMap();
		$this->flammableSubChunkCache = new \WeakMap();
		$this->lavaIgnitionSubChunkCache = new \WeakMap();
		if(class_exists(RandomTickSampler::class)){
			//chunkutils2 >= 0.3.6 picks the random-ticked blocks natively
			$this->randomTickSampler = new RandomTickSampler();
			foreach($this->randomTickBlocks as $stateId => $_){
				$this->randomTickSampler->setTicked($stateId, true);
			}
			//chunkutils2 >= 0.3.9 also reads the subchunks of the chunk itself (the PHPStan stub always has it)
			/** @phpstan-ignore function.alreadyNarrowedType */
			$this->randomTickSamplerReadsSubChunks = method_exists($this->randomTickSampler, "sampleSubChunks");
			/** @phpstan-ignore function.alreadyNarrowedType */
			$this->randomTickSamplerTicksChunks = method_exists($this->randomTickSampler, "tickChunks");
			foreach($this->lavaFastRandomTickStates as $stateId => $_){
				$this->syncSamplerLavaState($stateId);
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

		$this->raidManager->close();

		$this->cancelAutoSave();
		$this->beginWriteBatch();
		try{
			foreach($this->chunks as $chunkHash => $chunk){
				self::getXZ($chunkHash, $chunkX, $chunkZ);
				$this->unloadChunk($chunkX, $chunkZ, false);
			}
		}finally{
			$this->commitWriteBatch();
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
		if($this->worldAge < PHP_INT_MAX){
			++$this->worldAge;
		}
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
		$this->skyLightReduction = $this->computeSkyLightReduction(); //Sky light reduction depends on the sun angle and the weather

		if(++$this->sendTimeTicker === 200){
			$this->sendTime();
			$this->sendTimeTicker = 0;
		}

		$this->unloadChunks();
		if(++$this->providerGarbageCollectionTicker >= 6000){
			$this->provider->doGarbageCollection();
			$this->providerGarbageCollectionTicker = 0;
		}

		$this->redstone?->beginTick($currentTick);

		$this->timings->scheduledBlockUpdates->startTiming();
		//Delayed updates
		while($this->scheduledBlockUpdateQueue->count() > 0 && ($due = $this->scheduledBlockUpdateQueue->current()["priority"]) <= $currentTick){
			/** @var Vector3 $vec */
			$vec = $this->scheduledBlockUpdateQueue->extract()["data"];
			$blockHash = World::blockHash($vec->getFloorX(), $vec->getFloorY(), $vec->getFloorZ());
			if(isset($this->droppedScheduledBlockUpdates[$blockHash][$due])){
				//saved with its chunk when it unloaded
				if(--$this->droppedScheduledBlockUpdates[$blockHash][$due] === 0){
					unset($this->droppedScheduledBlockUpdates[$blockHash][$due]);
					if(count($this->droppedScheduledBlockUpdates[$blockHash]) === 0){
						unset($this->droppedScheduledBlockUpdates[$blockHash]);
					}
				}
				continue;
			}
			unset($this->scheduledBlockUpdateQueueIndex[$blockHash]);
			$chunkHash = World::chunkHash($vec->getFloorX() >> Chunk::COORD_BIT_SIZE, $vec->getFloorZ() >> Chunk::COORD_BIT_SIZE);
			if(($this->scheduledBlockUpdateChunkIndex[$chunkHash][$blockHash] ?? null) === $due){
				unset($this->scheduledBlockUpdateChunkIndex[$chunkHash][$blockHash]);
				if(count($this->scheduledBlockUpdateChunkIndex[$chunkHash]) === 0){
					unset($this->scheduledBlockUpdateChunkIndex[$chunkHash]);
				}
			}
			if(!$this->isInLoadedTerrain($vec)){
				continue;
			}
			$block = $this->getBlock($vec);
			if($this->chunkTickRadius > 0 && !isset($this->validTickingChunks[$chunkHash]) && $block->pausesScheduledUpdatesOutsideTickingChunks()){
				//vanilla only runs block ticks in ticking chunks: wait until the chunk is ticked again
				$this->pausedBlockUpdates[$chunkHash][$blockHash] = $vec;
				continue;
			}
			try{
				$block->onScheduledUpdate();
			}catch(\Throwable $e){
				$this->isolateCallbackFailure($e, "scheduled update of block " . $block->getName(), $block);
			}
		}
		if(count($this->liquidLayerFlowQueue) > 0){
			$this->processLiquidLayerFlows($currentTick);
		}
		$this->timings->scheduledBlockUpdates->stopTiming();

		$this->timings->neighbourBlockUpdates->startTiming();
		//Normal updates
		$this->processNeighbourBlockUpdates();
		$this->timings->neighbourBlockUpdates->stopTiming();

		$redstone = $this->redstone;
		if($redstone !== null && $redstone->hasDirtyWires()){
			$this->timings->redstoneWireUpdates->startTiming();
			//wire networks are recalculated once per round, after all the neighbour updates which dirtied them; the
			//changes they cause may dirty more networks, so repeat a bounded number of times
			for($round = 0; $round < RedstoneEngine::MAX_FLUSH_ROUNDS_PER_TICK && $redstone->flushWires(); ++$round){
				$this->processNeighbourBlockUpdates();
			}
			$this->timings->redstoneWireUpdates->stopTiming();
		}

		$this->timings->entityTick->startTiming();
		//Update entities that need update
		$simulationLimited = $this->simulationDistance > 0;
		foreach($this->updateEntities as $id => $entity){
			try{
				if($entity->isClosed() || $entity->isFlaggedForDespawn()){
					$keepUpdating = false;
				}elseif($simulationLimited && !$this->isEntitySimulated($id, $entity)){
					$keepUpdating = $entity->onFrozenUpdate($currentTick);
				}else{
					$keepUpdating = $entity->onUpdate($currentTick);
				}
			}catch(\Throwable $e){
				$this->isolateCallbackFailure($e, "tick of entity " . get_class($entity), $entity);
				if(!$entity->isClosed() && PluginFaultHandler::getInstance()?->findPluginByObject($entity) !== null){
					//a plugin-defined entity which failed to tick is in an unknown state, and would most likely fail
					//again on every tick: get rid of it
					$entity->flagForDespawn();
					$keepUpdating = false;
				}else{
					//a core entity (e.g. a player) failed because of plugin code it called: keep ticking it
					$keepUpdating = !$entity->isClosed();
				}
			}
			if(!$keepUpdating){
				unset($this->updateEntities[$id]);
			}
			if($entity->isFlaggedForDespawn()){
				$entity->close();
			}
		}
		$this->timings->entityTick->stopTiming();

		$this->mobSpawner?->tick($currentTick);
		if($this->mobSpawningRule){
			$this->patrolSpawner?->tick($currentTick);
		}
		$this->raidManager->tick($currentTick);
		$this->poiManager->tick($currentTick);
		if($this->dimension === Dimension::END){
			DragonFight::tickWorld($this);
		}
		PortalTravel::tickWorld($this, $currentTick);

		$this->timings->randomChunkUpdates->startTiming();
		$this->tickChunks();
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
						foreach($this->createBlockUpdatePackets($blocks) as $packet){
							$this->broadcastPacketToPlayersUsingChunk($chunkX, $chunkZ, $packet);
						}
					}
				}
			}

			$this->changedBlocks = [];

		}

		if($this->sleepTicks > 0 && --$this->sleepTicks <= 0){
			$this->checkSleep();
		}

		foreach($this->packetBuffersByChunk as $index => $entries){
			World::getXZ($index, $chunkX, $chunkZ);
			$chunkPlayers = $this->getChunkPlayers($chunkX, $chunkZ);
			if(count($chunkPlayers) > 0){
				NetworkBroadcastUtils::broadcastPackets($chunkPlayers, $entries);
			}
		}

		$this->packetBuffersByChunk = [];
	}

	/**
	 * Whether the entity is ticked: players, whatever carries a player and projectiles in flight always are, other
	 * entities when their chunk is within the simulation distance of a player (or registered for ticking/simulation).
	 */
	private function isEntitySimulated(int $id, Entity $entity) : bool{
		if($entity instanceof Player){
			return true;
		}
		if($entity instanceof Projectile && $entity->hasMovementUpdate()){
			//a thrown pearl (snowball, arrow...) flies on until it lands instead of hanging in mid-air where it leaves
			//the simulation distance: thrown 30 degrees up a pearl goes ~80 blocks, while the simulated chunks end
			//48-64 blocks away. Few, short-lived and cheap; stuck arrows (no movement update) still freeze.
			return true;
		}
		$pos = $this->entityLastKnownPositions[$id] ?? null;
		if($pos === null){
			return true;
		}
		$chunkHash = World::chunkHash($pos->getFloorX() >> Chunk::COORD_BIT_SIZE, $pos->getFloorZ() >> Chunk::COORD_BIT_SIZE);
		if(isset($this->registeredSimulatingChunks[$chunkHash]) || isset($this->registeredTickingChunks[$chunkHash])){
			return true;
		}
		foreach($entity->getPassengers() as $passenger){
			if($passenger instanceof Player){
				return true;
			}
		}
		return false;
	}

	public function checkSleep() : void{
		if(count($this->players) === 0){
			return;
		}

		$anySleeping = false;
		foreach($this->players as $p){
			if($p->isSleeping()){
				$anySleeping = true;
				break;
			}
		}
		if(!$anySleeping){
			return;
		}
		//spectators don't count; playerssleepingpercentage of the others must be asleep (vanilla)
		$players = 0;
		$sleeping = 0;
		foreach($this->players as $p){
			if(!$p->isSpectator()){
				++$players;
				if($p->isSleeping()){
					++$sleeping;
				}
			}
		}
		$percentage = $this->getGameRule(GameRules::PLAYERS_SLEEPING_PERCENTAGE);
		if($sleeping < SleepRules::getRequiredSleepers($players, is_int($percentage) ? $percentage : 100)){
			return;
		}

		if(SleepRules::canSleepNow($this)){
			if($this->getGameRule(GameRules::DO_DAYLIGHT_CYCLE) !== false){
				$time = $this->getTimeOfDay();
				$this->setTime($this->getTime() + World::TIME_FULL - $time);
			}
			//waking up after a night's sleep clears the weather (vanilla, unless the weather cycle is off)
			if($this->weather->isWeatherCycleEnabled()){
				$this->weather->resetCycle();
			}

			foreach($this->getPlayers() as $p){
				$p->stopSleep();
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
	public function createBlockUpdatePackets(array $blocks) : array{
		$packets = [];

		$blockTranslator = TypeConverter::getInstance()->getBlockTranslator();

		foreach($blocks as $b){
			if(!($b instanceof Vector3)){
				throw new \TypeError("Expected Vector3 in blocks array, got " . (is_object($b) ? get_class($b) : gettype($b)));
			}

			$fullBlock = $this->getBlockAt($b->x, $b->y, $b->z);
			$blockPosition = BlockPosition::fromVector3($b);

			$tile = $this->getTileAt($b->x, $b->y, $b->z);
			if($tile instanceof Spawnable){
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
						$blockTranslator->getBlockStateDictionary()->lookupStateIdFromData($fakeStateData) ?? throw new AssumptionFailedError("Unmapped fake blockstate data: " . $fakeStateData->toNbt()),
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
			[$bx, $by, $bz] = [$blockPosition->getX(), $blockPosition->getY(), $blockPosition->getZ()];
			$chunk = $this->getChunk($bx >> Chunk::COORD_BIT_SIZE, $bz >> Chunk::COORD_BIT_SIZE);
			if($chunk !== null && $this->isInWorld($bx, $by, $bz)){
				$subChunk = $chunk->getSubChunk($by >> SubChunk::COORD_BIT_SIZE);
				if(count($subChunk->getBlockLayers()) > 1){
					//the client was sent this subchunk with a liquid layer (waterlogged blocks): keep that in sync too
					$packets[] = UpdateBlockPacket::create(
						$blockPosition,
						$blockTranslator->internalIdToNetworkId($subChunk->getLiquidLayerStateId($bx & SubChunk::COORD_MASK, $by & SubChunk::COORD_MASK, $bz & SubChunk::COORD_MASK)),
						UpdateBlockPacket::FLAG_NETWORK,
						UpdateBlockPacket::DATA_LAYER_LIQUID
					);
				}
			}

			if($tile instanceof Spawnable){
				$packets[] = BlockActorDataPacket::create($blockPosition, $tile->getSerializedSpawnCompound());
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
	public function getRandomTickedBlocks() : array{
		return $this->randomTickBlocks;
	}

	/**
	 * Tells the native chunk loop (chunkutils2 >= 0.3.14) whether a state is in $lavaFastRandomTickStates.
	 */
	private function syncSamplerLavaState(int $stateId) : void{
		if($this->randomTickSamplerTicksChunks){
			$this->randomTickSampler?->setLavaState($stateId, isset($this->lavaFastRandomTickStates[$stateId]));
		}
	}

	public function addRandomTickedBlock(Block $block) : void{
		if($block instanceof UnknownBlock){
			throw new \InvalidArgumentException("Cannot do random-tick on unknown block");
		}
		$this->randomTickBlocks[$block->getStateId()] = true;
		$this->randomTickSubChunkCache = new \WeakMap();
		$this->randomTickSampler?->setTicked($block->getStateId(), true);
		if(get_class($block) === Lava::class){
			$this->lavaRandomTickStates[$block->getStateId()] = true;
			if($this->gameRules[GameRules::DO_FIRE_TICK] !== false){
				$this->lavaFastRandomTickStates[$block->getStateId()] = true;
			}
			$this->syncSamplerLavaState($block->getStateId());
		}
	}

	public function removeRandomTickedBlock(Block $block) : void{
		unset($this->randomTickBlocks[$block->getStateId()]);
		unset($this->lavaRandomTickStates[$block->getStateId()], $this->lavaFastRandomTickStates[$block->getStateId()]);
		$this->randomTickSubChunkCache = new \WeakMap();
		$this->randomTickSampler?->setTicked($block->getStateId(), false);
		$this->syncSamplerLavaState($block->getStateId());
	}

	/**
	 * Adds or removes the random-ticked lava states when doFireTick changes: lava random ticks only start fires.
	 */
	private function setLavaRandomTicked(bool $ticked) : void{
		if(count($this->lavaRandomTickStates) === 0){
			return;
		}
		foreach($this->lavaRandomTickStates as $stateId => $_){
			if($ticked){
				$this->randomTickBlocks[$stateId] = true;
			}else{
				unset($this->randomTickBlocks[$stateId]);
			}
			$this->randomTickSampler?->setTicked($stateId, $ticked);
		}
		$this->lavaFastRandomTickStates = $ticked ? $this->lavaRandomTickStates : [];
		foreach($this->lavaRandomTickStates as $stateId => $_){
			$this->syncSamplerLavaState($stateId);
		}
		$this->randomTickSubChunkCache = new \WeakMap();
	}

	/**
	 * Returns the random tick cache entry of the subchunk, recomputed if the subchunk changed: its block modification
	 * count, the random-ticked blocks in its palette (RANDOM_TICK_OTHER and/or RANDOM_TICK_LAVA bits, lava being ticked
	 * by {@link Lava::randomTickAt()}), and the cached answer of canLavaIgnite() with the world age it is used until.
	 *
	 * @phpstan-return array{int, int, int, bool}
	 */
	private function subChunkRandomTickEntry(SubChunk $subChunk) : array{
		$modificationCount = $subChunk->getBlockModificationCount();
		$cached = $this->randomTickSubChunkCache[$subChunk] ?? null;
		if($cached !== null && $cached[0] === $modificationCount){
			return $cached;
		}
		$result = 0;
		$flammable = false;
		//random ticking only looks at layer 0; the palette may hold stale entries until garbage collection, which
		//only makes this answer conservative
		foreach($subChunk->getBlockLayers()[0]->getPalette() as $state){
			if(isset($this->lavaFastRandomTickStates[$state])){
				$result |= self::RANDOM_TICK_LAVA;
			}elseif(isset($this->randomTickBlocks[$state])){
				$result |= self::RANDOM_TICK_OTHER;
			}
			$flammable = $flammable || ($this->flammableStates[$state] ??= $this->blockStateRegistry->fromStateId($state)->isFlammable());
		}
		$this->flammableSubChunkCache[$subChunk] = [$modificationCount, $flammable];
		//flowing lava changes subchunks all the time: the lava ignition answer is kept until it expires, unless a
		//flammable block just appeared in this subchunk
		$ignitionExpiry = $cached !== null && ($cached[3] || !$flammable) ? $cached[2] : 0;
		return $this->randomTickSubChunkCache[$subChunk] = [$modificationCount, $result, $ignitionExpiry, $cached[3] ?? false];
	}

	/**
	 * Returns whether random ticks of lava in the subchunk may set something on fire: whether a flammable block is in
	 * reach, i.e. in this subchunk or the one above, in this chunk or the 8 around it. Lava can't do anything else on
	 * random ticks, so the ticks of the others are skipped (whole subchunks of a Nether lava sea). Callers reuse the
	 * answer for LAVA_IGNITION_RECHECK_TICKS: a flammable block placed near lava may wait that long before it can catch
	 * fire, little next to the time a given lava block waits between random ticks (about a minute on average).
	 */
	private function canLavaIgnite(int $chunkX, int $subChunkY, int $chunkZ) : bool{
		$result = false;
		$maxSubChunkY = min($subChunkY + 1, Chunk::MAX_SUBCHUNK_INDEX);
		for($x = $chunkX - 1; $x <= $chunkX + 1 && !$result; ++$x){
			for($z = $chunkZ - 1; $z <= $chunkZ + 1 && !$result; ++$z){
				$chunk = $this->chunks[World::chunkHash($x, $z)] ?? null;
				if($chunk === null){
					//fire never starts in chunks which are not loaded
					continue;
				}
				for($y = $subChunkY; $y <= $maxSubChunkY; ++$y){
					$other = $chunk->getSubChunk($y);
					if(!$other->isEmptyFast() && $this->subChunkHasFlammableBlocks($other)){
						$result = true;
						break;
					}
				}
			}
		}
		return $result;
	}

	private function subChunkHasFlammableBlocks(SubChunk $subChunk) : bool{
		$modificationCount = $subChunk->getBlockModificationCount();
		$cached = $this->flammableSubChunkCache[$subChunk] ?? null;
		if($cached !== null && $cached[0] === $modificationCount){
			return $cached[1];
		}
		$result = false;
		//waterlogging layers only hold water
		foreach($subChunk->getBlockLayers()[0]->getPalette() as $state){
			if($this->flammableStates[$state] ??= $this->blockStateRegistry->fromStateId($state)->isFlammable()){
				$result = true;
				break;
			}
		}
		$this->flammableSubChunkCache[$subChunk] = [$modificationCount, $result];
		return $result;
	}

	/**
	 * Returns the radius of chunks to be ticked around each player. This is referred to as "simulation distance" in the
	 * Minecraft: Bedrock world options screen.
	 */
	public function getChunkTickRadius() : int{
		return $this->chunkTickRadius;
	}

	/**
	 * Sets the radius of chunks ticked around each player. This may not take effect immediately, since each player
	 * needs to recalculate their tick radius.
	 */
	public function setChunkTickRadius(int $radius) : void{
		$this->chunkTickRadius = $radius;
		if($radius <= 0){
			//no chunk is ticked any more: paused updates run like any other
			foreach(array_keys($this->pausedBlockUpdates) as $chunkHash){
				$this->resumePausedBlockUpdates($chunkHash);
			}
		}
	}

	/**
	 * Schedules again the updates that waited for the chunk to be ticked (see
	 * {@link Block::pausesScheduledUpdatesOutsideTickingChunks()}). They were due already, so they run next tick.
	 */
	private function resumePausedBlockUpdates(int $chunkHash) : void{
		foreach($this->pausedBlockUpdates[$chunkHash] as $pos){
			$this->scheduleDelayedBlockUpdate($pos, 1);
		}
		unset($this->pausedBlockUpdates[$chunkHash]);
	}

	/**
	 * Returns a list of chunk position hashes (as returned by World::chunkHash()) which are currently valid for
	 * ticking.
	 *
	 * @return int[]
	 * @phpstan-return list<ChunkPosHash>
	 */
	public function getTickingChunks() : array{
		return array_keys($this->validTickingChunks);
	}

	/**
	 * Instructs the World to tick the specified chunk, for as long as this chunk ticker (or any other chunk ticker) is
	 * registered to it.
	 */
	public function registerTickingChunk(ChunkTicker $ticker, int $chunkX, int $chunkZ) : void{
		$chunkPosHash = World::chunkHash($chunkX, $chunkZ);
		$this->registeredTickingChunks[$chunkPosHash][spl_object_id($ticker)] = $ticker;
		$this->recheckTickingChunks[$chunkPosHash] = $chunkPosHash;
	}

	/**
	 * Unregisters the given chunk ticker from the specified chunk. If there are other tickers still registered to the
	 * chunk, it will continue to be ticked.
	 */
	public function unregisterTickingChunk(ChunkTicker $ticker, int $chunkX, int $chunkZ) : void{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		$tickerId = spl_object_id($ticker);
		if(isset($this->registeredTickingChunks[$chunkHash][$tickerId])){
			if(count($this->registeredTickingChunks[$chunkHash]) === 1){
				unset(
					$this->registeredTickingChunks[$chunkHash],
					$this->recheckTickingChunks[$chunkHash],
					$this->validTickingChunks[$chunkHash]
				);
			}else{
				unset($this->registeredTickingChunks[$chunkHash][$tickerId]);
			}
		}
	}

	/**
	 * Returns the radius of chunks around each player in which entities are ticked ("simulation distance"), or 0 if
	 * the entities of every loaded chunk are ticked.
	 */
	public function getSimulationDistance() : int{
		return $this->simulationDistance;
	}

	/**
	 * Sets the radius of chunks around each player in which entities are ticked (0 = no limit). Like
	 * {@link World::setChunkTickRadius()}, players pick it up when they next recalculate their chunks.
	 */
	public function setSimulationDistance(int $distance) : void{
		$this->simulationDistance = max(0, $distance);
	}

	/**
	 * Instructs the World to tick the entities of the specified chunk even if it is beyond the simulation distance of
	 * every player, for as long as this chunk ticker (or any other) is registered to it. Chunks registered with
	 * {@link World::registerTickingChunk()} are simulated too.
	 */
	public function registerSimulatingChunk(ChunkTicker $ticker, int $chunkX, int $chunkZ) : void{
		$this->registeredSimulatingChunks[World::chunkHash($chunkX, $chunkZ)][spl_object_id($ticker)] = $ticker;
	}

	public function unregisterSimulatingChunk(ChunkTicker $ticker, int $chunkX, int $chunkZ) : void{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		unset($this->registeredSimulatingChunks[$chunkHash][spl_object_id($ticker)]);
		if(isset($this->registeredSimulatingChunks[$chunkHash]) && count($this->registeredSimulatingChunks[$chunkHash]) === 0){
			unset($this->registeredSimulatingChunks[$chunkHash]);
		}
	}

	/**
	 * Returns whether the entities of the chunk are ticked: it is within the simulation distance of a player, or
	 * registered for ticking or simulation, or the simulation distance is unlimited.
	 */
	public function isChunkSimulated(int $chunkX, int $chunkZ) : bool{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		return $this->simulationDistance <= 0 || isset($this->registeredSimulatingChunks[$chunkHash]) || isset($this->registeredTickingChunks[$chunkHash]);
	}

	private function tickChunks() : void{
		if($this->chunkTickRadius <= 0 || count($this->registeredTickingChunks) === 0){
			return;
		}

		if(count($this->recheckTickingChunks) > 0){
			$this->timings->randomChunkUpdatesChunkSelection->startTiming();

			$chunkTickableCache = [];

			foreach($this->recheckTickingChunks as $hash => $_){
				World::getXZ($hash, $chunkX, $chunkZ);
				if($this->isChunkTickable($chunkX, $chunkZ, $chunkTickableCache)){
					$this->validTickingChunks[$hash] = $hash;
					if(isset($this->pausedBlockUpdates[$hash])){
						$this->resumePausedBlockUpdates($hash);
					}
				}
			}
			$this->recheckTickingChunks = [];

			$this->timings->randomChunkUpdatesChunkSelection->stopTiming();
		}

		if($this->randomTickSamplerTicksChunks && $this->randomTickSampler !== null){
			$this->tickChunksNative($this->randomTickSampler);
			return;
		}
		foreach($this->validTickingChunks as $index => $_){
			World::getXZ($index, $chunkX, $chunkZ);

			$this->tickChunk($chunkX, $chunkZ);
		}
	}

	/**
	 * The loop of tickChunks() with chunkutils2 >= 0.3.14: the sampler walks the ticking chunks natively and returns at
	 * each chunk which needs PHP, which does that part here exactly as tickChunk() would do it:
	 * - entities in the chunk (the sampler drew nothing for it yet): their random updates;
	 * - the precipitation draw of Weather::tickChunk() hit: the precipitation;
	 * - blocks were picked: they are ticked like tickChunkNative() does, unless all of them are lava which can't set
	 *   anything on fire according to $lavaIgnitionSubChunkCache (the sampler checks it: nothing would happen).
	 * After entities or precipitation the sampler goes on with the rest of the same chunk, unless that PHP code unloaded
	 * or replaced the chunk: tickChunk() goes on with the chunk it started with, so does the PHP code here then.
	 * Chunks without any of these (most of them) cost no PHP at all. Same chunks in the same order, same mt_rand() draws
	 * in the same order, same block ticks and events as the PHP loop over tickChunk().
	 */
	private function tickChunksNative(RandomTickSampler $sampler) : void{
		//a copy, like foreach: chunks made valid or invalid while ticking don't change this tick's list
		$order = $this->validTickingChunks;
		$weatherChance = $this->weather->getPrecipitationChance();
		$position = 0;
		$chunkHash = 0;
		$picks = null;
		$resume = RandomTickSampler::TICK_DONE;
		while(($stop = $sampler->tickChunks($order, $this->chunks, $this->entitiesByChunk, $this->tickedBlocksPerSubchunkPerTick, Chunk::MIN_SUBCHUNK_INDEX, $weatherChance, $this->lavaIgnitionSubChunkCache, $this->worldAge, $resume, $position, $chunkHash, $picks)) !== RandomTickSampler::TICK_DONE){
			$resume = RandomTickSampler::TICK_DONE;
			World::getXZ($chunkHash, $chunkX, $chunkZ);
			$chunk = $this->chunks[$chunkHash];
			if($stop === RandomTickSampler::TICK_PICKS){
				if($picks !== null){
					$this->tickChunkPicks($chunkX, $chunkZ, $chunk, $picks);
				}
				continue;
			}
			if($stop === RandomTickSampler::TICK_ENTITIES){
				foreach($this->getChunkEntities($chunkX, $chunkZ) as $entity){
					$entity->onRandomUpdate();
				}
				if(($this->chunks[$chunkHash] ?? null) !== $chunk){
					//the rest of tickChunk() with the chunk it started with
					$this->weather->tickChunk($chunkX, $chunkZ);
					if($this->isChunkLoaded($chunkX, $chunkZ)){
						$this->tickChunkNative($chunkX, $chunkZ, $chunk, $sampler);
					}
					continue;
				}
			}else{
				//TICK_WEATHER: the draw was made
				$this->weather->tickPrecipitationInChunk($chunkX, $chunkZ);
				if(($this->chunks[$chunkHash] ?? null) !== $chunk){
					if($this->isChunkLoaded($chunkX, $chunkZ)){
						$this->tickChunkNative($chunkX, $chunkZ, $chunk, $sampler);
					}
					continue;
				}
			}
			//the sampler goes on with the rest of this chunk (same chunk object)
			$resume = $stop;
			--$position;
		}
	}

	/**
	 * @param bool[] &$cache
	 *
	 * @phpstan-param array<int, bool> $cache
	 * @phpstan-param-out array<int, bool> $cache
	 */
	private function isChunkTickable(int $chunkX, int $chunkZ, array &$cache) : bool{
		for($cx = -1; $cx <= 1; ++$cx){
			for($cz = -1; $cz <= 1; ++$cz){
				$chunkHash = World::chunkHash($chunkX + $cx, $chunkZ + $cz);
				if(isset($cache[$chunkHash])){
					if(!$cache[$chunkHash]){
						return false;
					}
					continue;
				}
				if($this->isChunkLocked($chunkX + $cx, $chunkZ + $cz)){
					$cache[$chunkHash] = false;
					return false;
				}
				$adjacentChunk = $this->getChunk($chunkX + $cx, $chunkZ + $cz);
				if($adjacentChunk === null || !$adjacentChunk->isPopulated()){
					$cache[$chunkHash] = false;
					return false;
				}
				$lightPopulatedState = $adjacentChunk->isLightPopulated();
				if($lightPopulatedState !== true){
					if($lightPopulatedState === false){
						$this->orderLightPopulation($chunkX + $cx, $chunkZ + $cz);
					}
					$cache[$chunkHash] = false;
					return false;
				}

				$cache[$chunkHash] = true;
			}
		}

		return true;
	}

	/**
	 * Marks the 3x3 square of chunks centered on the specified chunk for chunk ticking eligibility recheck.
	 *
	 * This should be used whenever the chunk's eligibility to be ticked is changed. This includes:
	 * - Loading/unloading the chunk (the chunk may be registered for ticking before it is loaded)
	 * - Locking/unlocking the chunk (e.g. world population)
	 * - Light populated state change (i.e. scheduled for light population, or light population completed)
	 * - Arbitrary chunk replacement (i.e. setChunk() or similar)
	 */
	private function markTickingChunkForRecheck(int $chunkX, int $chunkZ) : void{
		for($cx = -1; $cx <= 1; ++$cx){
			for($cz = -1; $cz <= 1; ++$cz){
				$chunkHash = World::chunkHash($chunkX + $cx, $chunkZ + $cz);
				unset($this->validTickingChunks[$chunkHash]);
				if(isset($this->registeredTickingChunks[$chunkHash])){
					$this->recheckTickingChunks[$chunkHash] = $chunkHash;
				}else{
					unset($this->recheckTickingChunks[$chunkHash]);
				}
			}
		}
	}

	private function orderLightPopulation(int $chunkX, int $chunkZ) : void{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		$lightPopulatedState = $this->chunks[$chunkHash]->isLightPopulated();
		if($lightPopulatedState === false){
			$this->chunks[$chunkHash]->setLightPopulated(null);
			$this->markTickingChunkForRecheck($chunkX, $chunkZ);

			$this->workerPool->submitTask(new LightPopulationTask(
				$this->chunks[$chunkHash],
				function(array $blockLight, array $skyLight, array $heightMap) use ($chunkX, $chunkZ) : void{
					/**
					 * TODO: phpstan can't infer these types yet :(
					 * @phpstan-var array<int, LightArray> $blockLight
					 * @phpstan-var array<int, LightArray> $skyLight
					 * @phpstan-var non-empty-list<int>    $heightMap
					 */
					if($this->unloaded || ($chunk = $this->getChunk($chunkX, $chunkZ)) === null || $chunk->isLightPopulated() === true){
						return;
					}
					//TODO: calculated light information might not be valid if the terrain changed during light calculation

					$chunk->setHeightMapArray($heightMap);
					foreach($blockLight as $y => $lightArray){
						$chunk->getSubChunk($y)->setBlockLightArray($lightArray);
					}
					foreach($skyLight as $y => $lightArray){
						$chunk->getSubChunk($y)->setBlockSkyLightArray($lightArray);
					}
					//the light arrays come for every subchunk, and most block light ones (and the sky light ones below
					//the terrain) are all zero, which a subchunk keeps as no array at all. Kept, they were ~30% of the
					//main thread memory with many chunks loaded (2 KB each, ~16 per chunk).
					foreach($chunk->getSubChunks() as $subChunk){
						$subChunk->collectLightGarbage();
					}
					$chunk->setLightPopulated(true);
					$this->markTickingChunkForRecheck($chunkX, $chunkZ);
				}
			));
		}
	}

	/**
	 * Random block ticks of a chunk with the blocks picked natively: same picks (same mt_rand() calls, same order of
	 * subchunks and blocks) as the PHP loop in tickChunk(). All picks of the chunk are made before the blocks are
	 * ticked, so a picked block changed by an earlier tick of the same chunk is read again and skipped if it is no
	 * longer random-ticked.
	 */
	private function tickChunkNative(int $chunkX, int $chunkZ, Chunk $chunk, RandomTickSampler $sampler) : void{
		if($this->randomTickSamplerReadsSubChunks){
			$picks = $sampler->sampleSubChunks($chunk->getSubChunkStorage(), $this->tickedBlocksPerSubchunkPerTick, Chunk::MIN_SUBCHUNK_INDEX);
		}else{
			$layers = [];
			foreach($chunk->getSubChunks() as $Y => $subChunk){
				if(!$subChunk->isEmptyFast()){
					$layers[$Y] = $subChunk->getBlockLayers()[0];
				}
			}
			$picks = $sampler->sample($layers, $this->tickedBlocksPerSubchunkPerTick);
		}
		$this->tickChunkPicks($chunkX, $chunkZ, $chunk, $picks);
	}

	/**
	 * Ticks the blocks the sampler picked in a chunk (see tickChunkNative()).
	 *
	 * @param int[] $picks flat list of (subchunk Y, x | y << 4 | z << 8, block state ID) triples
	 * @phpstan-param list<int> $picks
	 */
	private function tickChunkPicks(int $chunkX, int $chunkZ, Chunk $chunk, array $picks) : void{
		$blockFactory = $this->blockStateRegistry;
		for($i = 0, $count = count($picks); $i < $count; $i += 3){
			$Y = $picks[$i];
			$packed = $picks[$i + 1];
			$x = $packed & SubChunk::COORD_MASK;
			$y = ($packed >> SubChunk::COORD_BIT_SIZE) & SubChunk::COORD_MASK;
			$z = ($packed >> (SubChunk::COORD_BIT_SIZE * 2)) & SubChunk::COORD_MASK;
			$state = $i === 0 ? $picks[$i + 2] : $chunk->getSubChunk($Y)->getBlockStateId($x, $y, $z);
			if(!isset($this->randomTickBlocks[$state])){
				continue;
			}
			if(isset($this->lavaFastRandomTickStates[$state])){
				$subChunk = $chunk->getSubChunk($Y);
				$ignition = $this->lavaIgnitionSubChunkCache[$subChunk] ?? null;
				if($ignition === null || $ignition[0] <= $this->worldAge){
					$ignition = $this->lavaIgnitionSubChunkCache[$subChunk] = [$this->worldAge + self::LAVA_IGNITION_RECHECK_TICKS, $this->canLavaIgnite($chunkX, $Y, $chunkZ)];
				}
				if(!$ignition[1]){
					continue;
				}
				try{
					Lava::randomTickAt($this, $chunk, $subChunk->getBlockLayers()[0], $chunkX * Chunk::EDGE_LENGTH + $x, ($Y << SubChunk::COORD_BIT_SIZE) + $y, $chunkZ * Chunk::EDGE_LENGTH + $z);
				}catch(\Throwable $e){
					$this->lavaRandomTickFailure($e, $chunkX * Chunk::EDGE_LENGTH + $x, ($Y << SubChunk::COORD_BIT_SIZE) + $y, $chunkZ * Chunk::EDGE_LENGTH + $z);
				}
				continue;
			}
			$block = $blockFactory->fromStateId($state);
			$block->position($this, $chunkX * Chunk::EDGE_LENGTH + $x, ($Y << SubChunk::COORD_BIT_SIZE) + $y, $chunkZ * Chunk::EDGE_LENGTH + $z);
			try{
				$block->onRandomTick();
			}catch(\Throwable $e){
				$this->isolateCallbackFailure($e, "random tick of block " . $block->getName(), $block);
			}
		}
	}

	private function lavaRandomTickFailure(\Throwable $e, int $x, int $y, int $z) : void{
		$block = $this->getBlockAt($x, $y, $z);
		$this->isolateCallbackFailure($e, "random tick of block " . $block->getName(), $block);
	}

	private function tickChunk(int $chunkX, int $chunkZ) : void{
		$chunk = $this->getChunk($chunkX, $chunkZ);
		if($chunk === null){
			//the chunk may have been unloaded during a previous chunk's update (e.g. during BlockGrowEvent)
			return;
		}
		foreach($this->getChunkEntities($chunkX, $chunkZ) as $entity){
			$entity->onRandomUpdate();
		}

		$this->weather->tickChunk($chunkX, $chunkZ);
		if(!$this->isChunkLoaded($chunkX, $chunkZ)){
			return;
		}

		$blockFactory = $this->blockStateRegistry;
		if($this->randomTickSampler !== null){
			$this->tickChunkNative($chunkX, $chunkZ, $chunk, $this->randomTickSampler);
			return;
		}
		//this loop runs for every subchunk of every ticking chunk, every tick: the random-ticked-blocks check is inlined
		//and blocks are read straight from layer 0 (non-empty subchunks always have it)
		$blocksPerSubChunk = $this->tickedBlocksPerSubchunkPerTick;
		foreach($chunk->getSubChunks() as $Y => $subChunk){
			if($subChunk->isEmptyFast()){
				continue;
			}
			$modificationCount = $subChunk->getBlockModificationCount();
			$cached = $this->randomTickSubChunkCache[$subChunk] ?? null;
			if($cached === null || $cached[0] !== $modificationCount){
				$cached = $this->subChunkRandomTickEntry($subChunk);
			}
			$ticked = $cached[1];
			if($ticked !== 0){
				$tickLava = false;
				if(($ticked & self::RANDOM_TICK_LAVA) !== 0){
					if($cached[2] > $this->worldAge){
						$tickLava = $cached[3];
					}else{
						$tickLava = $this->canLavaIgnite($chunkX, $Y, $chunkZ);
						$this->randomTickSubChunkCache[$subChunk] = [$cached[0], $ticked, $this->worldAge + self::LAVA_IGNITION_RECHECK_TICKS, $tickLava];
					}
					if(!$tickLava && $ticked === self::RANDOM_TICK_LAVA){
						//nothing for the lava to set on fire, and nothing else to tick
						continue;
					}
				}
				$layer = $subChunk->getBlockLayers()[0];
				$k = 0;
				for($i = 0; $i < $blocksPerSubChunk; ++$i){
					if(($i % 5) === 0){
						//60 bits will be used by 5 blocks (12 bits each)
						$k = mt_rand(0, (1 << 60) - 1);
					}
					$x = $k & SubChunk::COORD_MASK;
					$y = ($k >> SubChunk::COORD_BIT_SIZE) & SubChunk::COORD_MASK;
					$z = ($k >> (SubChunk::COORD_BIT_SIZE * 2)) & SubChunk::COORD_MASK;
					$k >>= (SubChunk::COORD_BIT_SIZE * 3);

					$state = $layer->get($x, $y, $z);

					if(isset($this->randomTickBlocks[$state])){
						if(isset($this->lavaFastRandomTickStates[$state])){
							//most hit the inside of a Nether lava sea and do nothing: no block is built for them
							if($tickLava){
								try{
									Lava::randomTickAt($this, $chunk, $layer, $chunkX * Chunk::EDGE_LENGTH + $x, ($Y << SubChunk::COORD_BIT_SIZE) + $y, $chunkZ * Chunk::EDGE_LENGTH + $z);
								}catch(\Throwable $e){
									$this->lavaRandomTickFailure($e, $chunkX * Chunk::EDGE_LENGTH + $x, ($Y << SubChunk::COORD_BIT_SIZE) + $y, $chunkZ * Chunk::EDGE_LENGTH + $z);
								}
							}
							continue;
						}
						$block = $blockFactory->fromStateId($state);
						$block->position($this, $chunkX * Chunk::EDGE_LENGTH + $x, ($Y << SubChunk::COORD_BIT_SIZE) + $y, $chunkZ * Chunk::EDGE_LENGTH + $z);
						try{
							$block->onRandomTick();
						}catch(\Throwable $e){
							$this->isolateCallbackFailure($e, "random tick of block " . $block->getName(), $block);
						}
					}
				}
			}
		}
	}

	/**
	 * @return mixed[]
	 */
	public function __debugInfo() : array{
		return [];
	}

	public function save(bool $force = false) : bool{

		if(!$this->getAutoSave() && !$force){
			return false;
		}

		(new WorldSaveEvent($this))->call();

		$timings = $this->timings->syncDataSave;
		$timings->startTiming();

		$this->updateWorldDataForSave();
		$this->saveChunks();
		$this->provider->getWorldData()->save();

		$timings->stopTiming();

		return true;
	}

	/**
	 * Copies the world state kept outside the world data (time, age, weather) into it, before it is saved.
	 */
	private function updateWorldDataForSave() : void{
		$this->provider->getWorldData()->setTime($this->time);
		$worldData = $this->provider->getWorldData();
		if($worldData instanceof WorldAgeWorldData){
			$worldData->setWorldAge($this->worldAge);
		}
		$this->weather->save($this->provider->getWorldData());
	}

	public function saveChunks() : void{
		$this->timings->syncChunkSave->startTiming();
		$this->beginWriteBatch();
		try{
			foreach($this->chunks as $chunkHash => $chunk){
				if($this->isChunkUnchangedSinceSave($chunkHash, $chunk)){
					continue;
				}
				self::getXZ($chunkHash, $chunkX, $chunkZ);
				$this->writeChunk($chunkX, $chunkZ, $chunkHash, $chunk, true);
			}
		}finally{
			try{
				$this->commitWriteBatch();
			}finally{
				$this->timings->syncChunkSave->stopTiming();
			}
		}
	}

	/**
	 * Starts an autosave spread over several ticks: calls {@link WorldSaveEvent} like {@link World::save()} and
	 * remembers the loaded chunks, which {@link World::continueAutoSave()} then saves a few at a time (when they
	 * changed). {@link World::finishAutoSave()} saves the world data at the end. Chunks loaded later are saved by the
	 * next save; chunks unloaded in between are saved when they unload.
	 *
	 * Returns false (and does nothing) if autosave is disabled for this world.
	 *
	 * @internal
	 */
	public function startAutoSave() : bool{
		if(!$this->getAutoSave()){
			return false;
		}

		(new WorldSaveEvent($this))->call();

		$this->autoSaveQueue = array_keys($this->chunks);
		$this->autoSaveQueuePosition = 0;
		$this->autoSaveStarted = true;
		return true;
	}

	/**
	 * Returns the number of chunks the autosave in progress has still to look at.
	 *
	 * @internal
	 */
	public function getAutoSaveChunksLeft() : int{
		return $this->autoSaveQueue === null ? 0 : count($this->autoSaveQueue) - $this->autoSaveQueuePosition;
	}

	/**
	 * Saves the changed chunks of the autosave in progress until the deadline (a microtime(true) value) has passed and
	 * at least $minChunks chunks have been looked at. Returns true when all of the chunks are done (or the autosave was
	 * stopped because autosave was disabled for this world).
	 *
	 * @param int $processed set to the number of chunks looked at
	 *
	 * @internal
	 */
	public function continueAutoSave(float $deadline, int $minChunks, ?int &$processed = null) : bool{
		$processed = 0;
		if($this->autoSaveQueue === null){
			return true;
		}
		if(!$this->getAutoSave()){
			//disabled meanwhile (save-off): stop, as no save would have been made
			$this->cancelAutoSave();
			return true;
		}

		$queue = $this->autoSaveQueue;
		$count = count($queue);
		$this->timings->syncDataSave->startTiming();
		$this->timings->syncChunkSave->startTiming();
		$this->beginWriteBatch();
		try{
			while($this->autoSaveQueuePosition < $count){
				//skipping an unchanged chunk is cheap, the clock is only read every 32 of them
				if($processed >= $minChunks && ($processed & 31) === 0 && microtime(true) >= $deadline){
					break;
				}
				$chunkHash = $queue[$this->autoSaveQueuePosition++];
				++$processed;
				$chunk = $this->chunks[$chunkHash] ?? null;
				if($chunk === null || $this->isChunkUnchangedSinceSave($chunkHash, $chunk)){
					continue; //unloaded meanwhile (saved when it unloaded), or nothing to write
				}
				self::getXZ($chunkHash, $chunkX, $chunkZ);
				$this->writeChunk($chunkX, $chunkZ, $chunkHash, $chunk, true);
				if($processed >= $minChunks && microtime(true) >= $deadline){
					break;
				}
			}
		}finally{
			try{
				$this->commitWriteBatch();
			}finally{
				$this->timings->syncChunkSave->stopTiming();
				$this->timings->syncDataSave->stopTiming();
			}
		}

		if($this->autoSaveQueuePosition < $count){
			return false;
		}
		$this->autoSaveQueue = null;
		return true;
	}

	/**
	 * Ends the autosave in progress by saving the world data (time, weather, level.dat...), like the end of
	 * {@link World::save()}. Does nothing if no autosave was started, or if it was stopped.
	 *
	 * @internal
	 */
	public function finishAutoSave() : void{
		if(!$this->autoSaveStarted){
			return;
		}
		$this->autoSaveStarted = false;
		$this->autoSaveQueue = null;
		if(!$this->getAutoSave()){
			return;
		}
		$this->timings->syncDataSave->startTiming();
		try{
			$this->updateWorldDataForSave();
			$this->provider->getWorldData()->save();
		}finally{
			$this->timings->syncDataSave->stopTiming();
		}
	}

	/**
	 * Stops the autosave in progress without saving anything more (the world data is not saved).
	 *
	 * @internal
	 */
	public function cancelAutoSave() : void{
		$this->autoSaveQueue = null;
		$this->autoSaveStarted = false;
	}

	/**
	 * Returns whether saving the chunk would write exactly what the provider already has: this world saved it last,
	 * without entities, tiles or scheduled block updates, and since then it got none of them, its populated flag is the
	 * same and its terrain is not dirty. Unchanged blocks and biomes are not written by a save anyway (dirty flags).
	 */
	private function isChunkUnchangedSinceSave(int $chunkHash, Chunk $chunk) : bool{
		$saved = $this->cleanSavedChunks[$chunkHash] ?? null;
		if(
			$saved === null ||
			$saved[0] !== $chunk ||
			$saved[1] !== $chunk->isPopulated() ||
			$chunk->getTerrainDirtyFlags() !== Chunk::DIRTY_FLAGS_NONE ||
			count($chunk->getTiles()) !== 0 ||
			count($this->scheduledBlockUpdateChunkIndex[$chunkHash] ?? []) !== 0 ||
			count($this->pausedBlockUpdates[$chunkHash] ?? []) !== 0
		){
			return false;
		}
		foreach($this->entitiesByChunk[$chunkHash] ?? [] as $entity){
			if($entity->canSaveWithChunk()){
				return false;
			}
		}
		return true;
	}

	/**
	 * Saves the chunk to the provider. With $clearDirtyFlags, its terrain dirty flags are cleared afterwards (the chunk
	 * stays loaded) and it is remembered as unchanged if nothing but terrain was written.
	 */
	private function writeChunk(int $chunkX, int $chunkZ, int $chunkHash, Chunk $chunk, bool $clearDirtyFlags) : void{
		$chunkData = $this->createChunkData($chunkX, $chunkZ, $chunk);
		$this->provider->saveChunk($chunkX, $chunkZ, $chunkData, $chunk->getTerrainDirtyFlags());
		if($this->writeBatchDepth > 0){
			$this->writeBatchChunks[$chunkHash] = $chunk;
		}
		if($clearDirtyFlags){
			$chunk->clearTerrainDirtyFlags();
			if(count($chunkData->getEntityNBT()) === 0 && count($chunkData->getTileNBT()) === 0 && count($chunkData->getScheduledBlockUpdates()) === 0){
				$this->cleanSavedChunks[$chunkHash] = [$chunk, $chunkData->isPopulated()];
				return;
			}
		}
		unset($this->cleanSavedChunks[$chunkHash]);
	}

	/**
	 * Lets the provider group the chunk saves made until the matching {@link World::commitWriteBatch()} into fewer
	 * writes (see {@link WriteBatchWorldProvider}). Must always be paired with commitWriteBatch() (try/finally).
	 */
	private function beginWriteBatch() : void{
		if($this->provider instanceof WriteBatchWorldProvider){
			$this->provider->beginWriteBatch();
		}
		++$this->writeBatchDepth;
	}

	private function commitWriteBatch() : void{
		if(--$this->writeBatchDepth > 0){
			return;
		}
		$chunks = $this->writeBatchChunks;
		$this->writeBatchChunks = [];
		if($this->provider instanceof WriteBatchWorldProvider){
			try{
				$this->provider->commitWriteBatch();
			}catch(\Throwable $e){
				//the provider keeps the data and writes it again later, but don't rely on it for loaded chunks: they
				//are saved in full again by the next save
				foreach(Utils::promoteKeys($chunks) as $chunkHash => $chunk){
					if(($this->chunks[$chunkHash] ?? null) === $chunk){
						unset($this->cleanSavedChunks[$chunkHash]);
						$chunk->setTerrainDirty();
					}
				}
				throw $e;
			}
		}
	}

	/**
	 * Schedules a block update to be executed after the specified number of ticks.
	 * Blocks will be updated with the scheduled update type.
	 */
	public function scheduleDelayedBlockUpdate(Vector3 $pos, int $delay) : void{
		if(
			!$this->isInWorld($pos->x, $pos->y, $pos->z) ||
			(isset($this->scheduledBlockUpdateQueueIndex[$index = World::blockHash($pos->x, $pos->y, $pos->z)]) && $this->scheduledBlockUpdateQueueIndex[$index] <= $delay)
		){
			return;
		}
		$this->scheduledBlockUpdateQueueIndex[$index] = $delay;
		$x = (int) $pos->x;
		$z = (int) $pos->z;
		$due = $delay + $this->server->getTick();
		$this->scheduledBlockUpdateQueue->insert(new Vector3($x, (int) $pos->y, $z), $due);
		$this->scheduledBlockUpdateChunkIndex[World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)][$index] = $due;
	}

	/**
	 * Returns the scheduled updates of the chunk with the ticks left until they run (0 for the due updates waiting for
	 * the chunk to be ticked), to be saved with the chunk.
	 *
	 * @phpstan-return list<array{int, int, int, int}> x, y, z, delay
	 */
	private function getChunkScheduledBlockUpdates(int $chunkHash) : array{
		$result = [];
		$currentTick = $this->server->getTick();
		foreach($this->scheduledBlockUpdateChunkIndex[$chunkHash] ?? [] as $blockHash => $due){
			World::getBlockXYZ($blockHash, $x, $y, $z);
			$result[] = [$x, $y, $z, max(0, $due - $currentTick)];
		}
		foreach($this->pausedBlockUpdates[$chunkHash] ?? [] as $pos){
			$result[] = [$pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), 0];
		}
		return $result;
	}

	/**
	 * Forgets the scheduled updates of an unloading chunk. Their queue entries can't be removed cheaply, so they are
	 * skipped when they come up if the updates were saved with the chunk by a provider that keeps them (they are
	 * scheduled again when it loads). Other queued updates stay in the queue and run if the chunk is loaded again by
	 * then, as before scheduled updates were saved.
	 */
	private function dropChunkScheduledBlockUpdates(int $chunkHash, bool $saved) : void{
		unset($this->pausedBlockUpdates[$chunkHash]);
		if($saved && $this->provider instanceof ScheduledBlockUpdatesWorldProvider){
			foreach($this->scheduledBlockUpdateChunkIndex[$chunkHash] ?? [] as $blockHash => $due){
				unset($this->scheduledBlockUpdateQueueIndex[$blockHash]);
				$this->droppedScheduledBlockUpdates[$blockHash][$due] = ($this->droppedScheduledBlockUpdates[$blockHash][$due] ?? 0) + 1;
			}
		}
		unset($this->scheduledBlockUpdateChunkIndex[$chunkHash]);
	}

	private function createChunkData(int $chunkX, int $chunkZ, Chunk $chunk) : ChunkData{
		return new ChunkData(
			$chunk->getSubChunks(),
			$chunk->isPopulated(),
			array_map(fn(Entity $e) => $e->saveNBT(), array_values(array_filter($this->getChunkEntities($chunkX, $chunkZ), fn(Entity $e) => $e->canSaveWithChunk()))),
			array_map(fn(Tile $t) => $t->saveNBT(), array_values($chunk->getTiles())),
			$this->getChunkScheduledBlockUpdates(World::chunkHash($chunkX, $chunkZ))
		);
	}

	/**
	 * Returns whether a delayed block update (see {@link World::scheduleDelayedBlockUpdate()}) is pending at the given
	 * position.
	 */
	public function isBlockUpdateScheduled(int $x, int $y, int $z) : bool{
		return isset($this->scheduledBlockUpdateQueueIndex[World::blockHash($x, $y, $z)]);
	}

	private function processNeighbourBlockUpdates() : void{
		//updates come in groups around a changed block: whether its subchunk has a liquid layer is looked up once
		$lastChunk = null;
		$lastSubChunkY = 0;
		$hasLiquidLayer = false;
		while($this->neighbourBlockUpdateQueue->count() > 0){
			$index = $this->neighbourBlockUpdateQueue->dequeue();
			unset($this->neighbourBlockUpdateQueueIndex[$index]);
			World::getBlockXYZ($index, $x, $y, $z);
			$chunk = $this->chunks[World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)] ?? null;
			if($chunk === null){
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
			try{
				$block->onNearbyBlockChange();
			}catch(\Throwable $e){
				$this->isolateCallbackFailure($e, "neighbour update of block " . $block->getName(), $block);
			}
			//water held in the liquid layer flows out (Waterlogged blocks schedule their own flow); most subchunks have
			//no liquid layer, which is checked first
			if($chunk !== $lastChunk || ($y >> SubChunk::COORD_BIT_SIZE) !== $lastSubChunkY){
				$lastChunk = $chunk;
				$lastSubChunkY = $y >> SubChunk::COORD_BIT_SIZE;
				$hasLiquidLayer = isset($chunk->getSubChunk($lastSubChunkY)->getBlockLayers()[1]);
			}
			if($hasLiquidLayer && !$block instanceof Waterlogged && LiquidLayer::hasLayerWaterAt($this, $x, $y, $z)){
				$this->scheduleLiquidLayerFlow($x, $y, $z);
			}
		}
	}

	/**
	 * Schedules the water held in the liquid layer at the given position to flow into its neighbours, like a water
	 * source would.
	 */
	private function scheduleLiquidLayerFlow(int $x, int $y, int $z) : void{
		$hash = World::blockHash($x, $y, $z);
		if(!isset($this->liquidLayerFlowQueueIndex[$hash])){
			$this->liquidLayerFlowQueueIndex[$hash] = true;
			$this->liquidLayerFlowQueue[$this->server->getTick() + self::LIQUID_LAYER_FLOW_DELAY][$hash] = true;
		}
	}

	private function processLiquidLayerFlows(int $currentTick) : void{
		foreach($this->liquidLayerFlowQueue as $tick => $hashes){
			if($tick > $currentTick){
				break;
			}
			unset($this->liquidLayerFlowQueue[$tick]);
			foreach($hashes as $hash => $_){
				unset($this->liquidLayerFlowQueueIndex[$hash]);
				World::getBlockXYZ($hash, $x, $y, $z);
				//the block may have been changed or its chunk unloaded since
				if(!LiquidLayer::hasLayerWaterAt($this, $x, $y, $z)){
					continue;
				}
				$block = $this->getBlockAt($x, $y, $z);
				if($block instanceof Waterlogged){
					continue;
				}
				try{
					WaterloggedHelper::flow($block);
				}catch(\Throwable $e){
					$this->isolateCallbackFailure($e, "water flow of waterlogged block " . $block->getName(), $block);
				}
			}
		}
	}

	/**
	 * Called when a block, entity or chunk listener (possibly provided by a plugin) throws while being ticked or
	 * notified by the world. The failure is logged and the world carries on if a plugin is to blame; otherwise (core
	 * bug) it is rethrown, as before.
	 *
	 * @throws \Throwable
	 */
	private function isolateCallbackFailure(\Throwable $e, string $context, object $origin) : void{
		PluginFaultHandler::isolate($e, null, $context . " in world " . $this->getFolderName(), $origin);
	}

	/**
	 * Queues {@link Block::onNearbyBlockChange()} for the block at the given position only (not its neighbours).
	 */
	public function scheduleNeighbourBlockUpdateAt(int $x, int $y, int $z) : void{
		$this->tryAddToNeighbourUpdateQueue($x, $y, $z);
	}

	/**
	 * Integer version of {@link World::notifyNeighbourBlockUpdate()}: queues updates for the block at the given
	 * position and its six neighbours.
	 */
	public function notifyNeighbourBlockUpdateAt(int $x, int $y, int $z) : void{
		$this->internalNotifyNeighbourBlockUpdate($x, $y, $z);
	}

	/**
	 * Returns the redstone engine of this world, or null if redstone is disabled in pocketmine.yml.
	 */
	public function getRedstone() : ?RedstoneEngine{
		return $this->redstone;
	}

	/**
	 * Returns the manager which delivers vibrations (game events) to the sculk sensors of this world.
	 */
	public function getVibrations() : VibrationManager{
		return $this->vibrations;
	}

	/**
	 * Emits a vibration (game event) that sculk sensors around the given position can detect.
	 *
	 * @param Entity|null $source   the entity which caused the event, if any
	 * @param Block|null  $affected the block placed, broken or changed, if any
	 */
	public function addGameEvent(Vector3 $pos, GameEvent $event, ?Entity $source = null, ?Block $affected = null) : void{
		$this->vibrations->emit($pos, $event, $source, $affected);
	}

	/**
	 * Returns whether the block at the given position receives redstone power from any side. Always false if redstone
	 * is disabled.
	 */
	public function isBlockPowered(Vector3 $pos) : bool{
		return $this->redstone !== null && $this->redstone->isPowered($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
	}

	/**
	 * Returns the highest redstone power (0-15) received by the block at the given position. Always 0 if redstone is
	 * disabled.
	 */
	public function getBlockPower(Vector3 $pos) : int{
		return $this->redstone !== null ? $this->redstone->getPower($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()) : 0;
	}

	private function tryAddToNeighbourUpdateQueue(int $x, int $y, int $z) : void{
		if($this->isInWorld($x, $y, $z)){
			$hash = World::blockHash($x, $y, $z);
			if(!isset($this->neighbourBlockUpdateQueueIndex[$hash])){
				$this->neighbourBlockUpdateQueue->enqueue($hash);
				$this->neighbourBlockUpdateQueueIndex[$hash] = true;
			}
		}
	}

	/**
	 * Identical to {@link World::notifyNeighbourBlockUpdate()}, but without the Vector3 requirement. We don't want or
	 * need Vector3 in the places where this is called.
	 *
	 * TODO: make this the primary method in PM6
	 */
	private function internalNotifyNeighbourBlockUpdate(int $x, int $y, int $z) : void{
		$this->tryAddToNeighbourUpdateQueue($x, $y, $z);
		foreach(Facing::OFFSET as [$dx, $dy, $dz]){
			$this->tryAddToNeighbourUpdateQueue($x + $dx, $y + $dy, $z + $dz);
		}
	}

	/**
	 * Notify the blocks at and around the position that the block at the position may have changed.
	 * This will cause onNearbyBlockChange() to be called for these blocks.
	 * TODO: Accept plain integers in PM6 - the Vector3 requirement is an unnecessary inconvenience
	 *
	 * @see Block::onNearbyBlockChange()
	 */
	public function notifyNeighbourBlockUpdate(Vector3 $pos) : void{
		$this->internalNotifyNeighbourBlockUpdate($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
	}

	/**
	 * @return Block[]
	 * @phpstan-return list<Block>
	 */
	public function getCollisionBlocks(AxisAlignedBB $bb, bool $targetFirst = false) : array{
		$minX = (int) floor($bb->minX - 1);
		$minY = (int) floor($bb->minY - 1);
		$minZ = (int) floor($bb->minZ - 1);
		$maxX = (int) floor($bb->maxX + 1);
		$maxY = (int) floor($bb->maxY + 1);
		$maxZ = (int) floor($bb->maxZ + 1);

		$collides = [];

		$collisionInfo = $this->blockStateRegistry->collisionInfo;
		if($targetFirst){
			for($z = $minZ; $z <= $maxZ; ++$z){
				$zOverflow = $z === $minZ || $z === $maxZ;
				for($x = $minX; $x <= $maxX; ++$x){
					$zxOverflow = $zOverflow || $x === $minX || $x === $maxX;
					for($y = $minY; $y <= $maxY; ++$y){
						$overflow = $zxOverflow || $y === $minY || $y === $maxY;

						$stateCollisionInfo = $this->getBlockCollisionInfo($x, $y, $z, $collisionInfo);
						if($overflow ?
							$stateCollisionInfo === RuntimeBlockStateRegistry::COLLISION_MAY_OVERFLOW && $this->getBlockAt($x, $y, $z)->collidesWithBB($bb) :
							match ($stateCollisionInfo) {
								RuntimeBlockStateRegistry::COLLISION_CUBE => true,
								RuntimeBlockStateRegistry::COLLISION_NONE => false,
								default => $this->getBlockAt($x, $y, $z)->collidesWithBB($bb)
							}
						){
							return [$this->getBlockAt($x, $y, $z)];
						}
					}
				}
			}
		}else{
			//TODO: duplicated code :( this way is better for performance though
			for($z = $minZ; $z <= $maxZ; ++$z){
				$zOverflow = $z === $minZ || $z === $maxZ;
				for($x = $minX; $x <= $maxX; ++$x){
					$zxOverflow = $zOverflow || $x === $minX || $x === $maxX;
					for($y = $minY; $y <= $maxY; ++$y){
						$overflow = $zxOverflow || $y === $minY || $y === $maxY;

						$stateCollisionInfo = $this->getBlockCollisionInfo($x, $y, $z, $collisionInfo);
						if($overflow ?
							$stateCollisionInfo === RuntimeBlockStateRegistry::COLLISION_MAY_OVERFLOW && $this->getBlockAt($x, $y, $z)->collidesWithBB($bb) :
							match ($stateCollisionInfo) {
								RuntimeBlockStateRegistry::COLLISION_CUBE => true,
								RuntimeBlockStateRegistry::COLLISION_NONE => false,
								default => $this->getBlockAt($x, $y, $z)->collidesWithBB($bb)
							}
						){
							$collides[] = $this->getBlockAt($x, $y, $z);
						}
					}
				}
			}
		}

		return $collides;
	}

	/**
	 * Returns whether {@link World::getCollisionBlocks()} would return at least one block for this box (with either
	 * value of its $targetFirst parameter). The same cells are checked with the same rules, but the check stops at the
	 * first hit and creates no Block objects for full cubes.
	 *
	 * The cells inside the box, where any colliding block counts, are checked first, each column from the bottom up,
	 * so a box around the feet of an entity standing on the ground usually stops at the first cell. The ring of cells
	 * around them, where only blocks whose collision boxes may reach out of their cell (like fences and walls) count,
	 * is checked afterwards.
	 *
	 * The answer is the same, but the blocks fetched with {@link World::getBlockAt()} (which adds them to the block
	 * cache) are not all the same: a full cube that decides the answer is not fetched; ring blocks that
	 * getCollisionBlocks() visits, in its z-x-y order, before the first colliding inner cell are not fetched; and when no
	 * inner cell collides, every inner block that needs a check is fetched, also those that getCollisionBlocks() would
	 * only reach after a colliding ring cell. setBlockAt() removes only the changed cell from the block cache, not its
	 * neighbours, so a cached block whose state is read from its neighbours (a chorus plant, a door half) keeps its old
	 * shape after a neighbour changes. In that rare case, which blocks were cached decides whether a later collision
	 * check sees the old shape or the current one.
	 */
	public function hasCollisionBlocks(AxisAlignedBB $bb) : bool{
		//same bounds as getCollisionBlocks(): a cell is in the ring ("overflow") when it is on the min or max of any
		//axis, so the inner cells are the ones strictly between them (floor(bb min)..floor(bb max) can differ from
		//that by rounding, e.g. for a min of -1e-20)
		$minX = (int) floor($bb->minX - 1);
		$minY = (int) floor($bb->minY - 1);
		$minZ = (int) floor($bb->minZ - 1);
		$maxX = (int) floor($bb->maxX + 1);
		$maxY = (int) floor($bb->maxY + 1);
		$maxZ = (int) floor($bb->maxZ + 1);

		//like getBlockCollisionInfo(), cells outside the world height, outside the 32-bit x/z range or in unloaded
		//chunks don't collide
		$worldMinY = $this->minY;
		$worldMaxY = $this->maxY - 1;
		$fromY = max($minY, $worldMinY);
		$toY = min($maxY, $worldMaxY);
		if($fromY > $toY){
			return false;
		}

		$collisionInfo = $this->blockStateRegistry->collisionInfo;

		$innerFromY = max($minY + 1, $worldMinY);
		$innerToY = min($maxY - 1, $worldMaxY);
		if($innerFromY <= $innerToY){
			for($z = $minZ + 1; $z < $maxZ; ++$z){
				if($z > Limits::INT32_MAX || $z < Limits::INT32_MIN){
					continue;
				}
				$localZ = $z & SubChunk::COORD_MASK;
				for($x = $minX + 1; $x < $maxX; ++$x){
					if($x > Limits::INT32_MAX || $x < Limits::INT32_MIN){
						continue;
					}
					$chunk = $this->chunks[World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)] ?? null;
					if($chunk === null){
						continue;
					}
					$localX = $x & SubChunk::COORD_MASK;
					for($y = $innerFromY; $y <= $innerToY; ++$y){
						$stateCollisionInfo = $collisionInfo[$chunk->getSubChunk($y >> SubChunk::COORD_BIT_SIZE)->getBlockStateId($localX, $y & SubChunk::COORD_MASK, $localZ)];
						if(
							$stateCollisionInfo === RuntimeBlockStateRegistry::COLLISION_CUBE ||
							($stateCollisionInfo !== RuntimeBlockStateRegistry::COLLISION_NONE && $this->getBlockAt($x, $y, $z)->collidesWithBB($bb))
						){
							return true;
						}
					}
				}
			}
		}

		for($z = $minZ; $z <= $maxZ; ++$z){
			if($z > Limits::INT32_MAX || $z < Limits::INT32_MIN){
				continue;
			}
			$zOverflow = $z === $minZ || $z === $maxZ;
			$localZ = $z & SubChunk::COORD_MASK;
			for($x = $minX; $x <= $maxX; ++$x){
				if($x > Limits::INT32_MAX || $x < Limits::INT32_MIN){
					continue;
				}
				$chunk = $this->chunks[World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)] ?? null;
				if($chunk === null){
					continue;
				}
				$localX = $x & SubChunk::COORD_MASK;
				if($zOverflow || $x === $minX || $x === $maxX){
					//the whole column is in the ring
					$y = $fromY;
					$yStep = 1;
				}else{
					//only the bottom and top cells: the ones between them were checked above
					$y = $minY;
					$yStep = max(1, $maxY - $minY);
				}
				$subChunkY = $fromY >> SubChunk::COORD_BIT_SIZE;
				$subChunk = $chunk->getSubChunk($subChunkY);
				for(; $y <= $toY; $y += $yStep){
					if($y < $worldMinY){
						continue;
					}
					if(($y >> SubChunk::COORD_BIT_SIZE) !== $subChunkY){
						$subChunkY = $y >> SubChunk::COORD_BIT_SIZE;
						$subChunk = $chunk->getSubChunk($subChunkY);
					}
					if(
						$collisionInfo[$subChunk->getBlockStateId($localX, $y & SubChunk::COORD_MASK, $localZ)] === RuntimeBlockStateRegistry::COLLISION_MAY_OVERFLOW &&
						$this->getBlockAt($x, $y, $z)->collidesWithBB($bb)
					){
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * @param int[] $collisionInfo
	 * @phpstan-param array<int, int> $collisionInfo
	 */
	private function getBlockCollisionInfo(int $x, int $y, int $z, array $collisionInfo) : int{
		if(!$this->isInWorld($x, $y, $z)){
			return RuntimeBlockStateRegistry::COLLISION_NONE;
		}
		$chunk = $this->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		if($chunk === null){
			return RuntimeBlockStateRegistry::COLLISION_NONE;
		}
		$stateId = $chunk
			->getSubChunk($y >> SubChunk::COORD_BIT_SIZE)
			->getBlockStateId(
				$x & SubChunk::COORD_MASK,
				$y & SubChunk::COORD_MASK,
				$z & SubChunk::COORD_MASK
			);
		return $collisionInfo[$stateId];
	}

	/**
	 * Returns a list of all block AABBs which overlap the full block area at the given coordinates.
	 * This checks a padding of 1 block around the coordinates to account for oversized AABBs of blocks like fences.
	 * Larger AABBs (>= 2 blocks on any axis) are not accounted for.
	 *
	 * @param int[] $collisionInfo
	 * @phpstan-param array<int, int> $collisionInfo
	 *
	 * @return AxisAlignedBB[]
	 * @phpstan-return list<AxisAlignedBB>
	 */
	private function getBlockCollisionBoxesForCell(int $x, int $y, int $z, array $collisionInfo) : array{
		$stateCollisionInfo = $this->getBlockCollisionInfo($x, $y, $z, $collisionInfo);
		$boxes = match($stateCollisionInfo){
			RuntimeBlockStateRegistry::COLLISION_NONE => [],
			RuntimeBlockStateRegistry::COLLISION_CUBE => [AxisAlignedBB::one()->offset($x, $y, $z)],
			default => $this->getBlockAt($x, $y, $z)->getCollisionBoxes()
		};

		//overlapping AABBs can't make any difference if this is a cube, so we can save some CPU cycles in this common case
		if($stateCollisionInfo !== RuntimeBlockStateRegistry::COLLISION_CUBE){
			$cellBB = null;
			foreach(Facing::OFFSET as [$dx, $dy, $dz]){
				$offsetX = $x + $dx;
				$offsetY = $y + $dy;
				$offsetZ = $z + $dz;
				$stateCollisionInfo = $this->getBlockCollisionInfo($offsetX, $offsetY, $offsetZ, $collisionInfo);
				if($stateCollisionInfo === RuntimeBlockStateRegistry::COLLISION_MAY_OVERFLOW){
					//avoid allocating this unless it's needed
					$cellBB ??= AxisAlignedBB::one()->offset($x, $y, $z);
					$extraBoxes = $this->getBlockAt($offsetX, $offsetY, $offsetZ)->getCollisionBoxes();
					foreach($extraBoxes as $extraBox){
						if($extraBox->intersectsWith($cellBB)){
							$boxes[] = $extraBox;
						}
					}
				}
			}
		}

		return $boxes;
	}

	/**
	 * @return AxisAlignedBB[]
	 * @phpstan-return list<AxisAlignedBB>
	 */
	public function getBlockCollisionBoxes(AxisAlignedBB $bb) : array{
		$minX = (int) floor($bb->minX);
		$minY = (int) floor($bb->minY);
		$minZ = (int) floor($bb->minZ);
		$maxX = (int) floor($bb->maxX);
		$maxY = (int) floor($bb->maxY);
		$maxZ = (int) floor($bb->maxZ);

		$collides = [];

		$collisionInfo = $this->blockStateRegistry->collisionInfo;

		//every moving entity asks this on every tick: the chunk hash is computed with the function behind chunkHash() and
		//the cell key (collisionCellKey()) inline, the cache of the column's chunk is read through a local copy, and
		//$blockBB->intersectsWith($bb) (AxisAlignedBB is final, default epsilon) is written out with the same comparisons in
		//the same order
		$bbMinX = $bb->minX;
		$bbMinY = $bb->minY;
		$bbMinZ = $bb->minZ;
		$bbMaxX = $bb->maxX;
		$bbMaxY = $bb->maxY;
		$bbMaxZ = $bb->maxZ;
		for($z = $minZ; $z <= $maxZ; ++$z){
			for($x = $minX; $x <= $maxX; ++$x){
				$chunkPosHash = morton2d_encode($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
				$chunkBoxes = $this->blockCollisionBoxCache[$chunkPosHash] ?? null;
				$columnKey = (($x & 0xf) << 4) | ($z & 0xf);
				for($y = $minY; $y <= $maxY; ++$y){
					$collisionCellKey = (($y & 0x1fffff) << 8) | $columnKey;

					if(isset($chunkBoxes[$collisionCellKey])){
						$boxes = $chunkBoxes[$collisionCellKey];
					}else{
						//the local copy is let go first, so that the cache isn't copied to add the new entry
						$chunkBoxes = null;
						$boxes = $this->blockCollisionBoxCache[$chunkPosHash][$collisionCellKey] = $this->getBlockCollisionBoxesForCell($x, $y, $z, $collisionInfo);
						$chunkBoxes = $this->blockCollisionBoxCache[$chunkPosHash];
					}

					foreach($boxes as $blockBB){
						if(
							$bbMaxX - $blockBB->minX > 0.00001 && $blockBB->maxX - $bbMinX > 0.00001 &&
							$bbMaxY - $blockBB->minY > 0.00001 && $blockBB->maxY - $bbMinY > 0.00001 &&
							$bbMaxZ - $blockBB->minZ > 0.00001 && $blockBB->maxZ - $bbMinZ > 0.00001
						){
							$collides[] = $blockBB;
						}
					}
				}
			}
		}

		return $collides;
	}

	/** The C++ entity physics of chunkutils2 >= 0.3.12 (one per thread), null with older extensions. */
	private static ?NativeEntityPhysics $nativeEntityPhysics = null;
	private static bool $nativeEntityPhysicsChecked = false;
	private static bool $nativeEntityPhysicsEnabled = true;

	/**
	 * @internal Read only (see getNativeEntityPhysics()): what getNativeEntityPhysics() returns, set once a world was
	 * created in this thread, for the per-tick paths of entities (a static property costs less than a call there).
	 */
	public static ?NativeEntityPhysics $nativePhysics = null;

	/**
	 * @internal
	 * The C++ entity physics of chunkutils2 (>= 0.3.12), or null when the extension doesn't have them (or they are
	 * turned off): callers then run the same code in PHP.
	 */
	public static function getNativeEntityPhysics() : ?NativeEntityPhysics{
		if(!self::$nativeEntityPhysicsChecked){
			self::$nativeEntityPhysicsChecked = true;
			if(class_exists(NativeEntityPhysics::class)){
				self::$nativeEntityPhysics = new NativeEntityPhysics();
			}
		}
		return self::$nativePhysics = self::$nativeEntityPhysicsEnabled ? self::$nativeEntityPhysics : null;
	}

	/**
	 * @internal for tests and benchmarks: false makes the entity physics run in PHP
	 */
	public static function setNativeEntityPhysicsEnabled(bool $enabled) : void{
		self::$nativeEntityPhysicsEnabled = $enabled;
		self::getNativeEntityPhysics();
	}

	/**
	 * @internal
	 * The collision part of {@link Entity::move()} in C++: the movement ($dx, $dy, $dz) of the box $bb clipped by the
	 * collision boxes of the blocks (getBlockCollisionBoxes()), with the step up of entities with a step height. Gives
	 * exactly what the PHP code gives: [0, dx, dy, dz, whether it stepped up, minX, minY, minZ, maxX, maxY, maxZ] of the
	 * moved box. The boxes come from this world's collision box cache: cells missing from it are worked out here (the
	 * same call getBlockCollisionBoxes() makes, in the same order) and the clipping runs again.
	 * Returns null when PHP must do it (no extension, a subclass of World, or boxes or values it leaves to PHP).
	 *
	 * @phpstan-return array{0: int, 1: float, 2: float, 3: float, 4: bool, 5: float, 6: float, 7: float, 8: float, 9: float, 10: float}|null
	 */
	public function clipEntityMovement(AxisAlignedBB $bb, float $dx, float $dy, float $dz, bool $onGround, float $stepHeight) : ?array{
		$native = self::$nativePhysics;
		if($native === null){
			return null;
		}
		//cells are missing at most twice: from the box of the movement, then from the box of the step up
		for($round = 0; $round < 3; ++$round){
			$result = $native->clipMovement($this->blockCollisionBoxCache, $bb, $dx, $dy, $dz, $onGround, $stepHeight, $this);
			if($result[0] === 0){
				/** @phpstan-var array{0: int, 1: float, 2: float, 3: float, 4: bool, 5: float, 6: float, 7: float, 8: float, 9: float, 10: float} $result */
				return $result;
			}
			if($result[0] !== 1){
				return null;
			}
			/** @phpstan-var list<int> $missing the status, then the X, Y and Z of each missing cell */
			$missing = $result;
			$collisionInfo = $this->blockStateRegistry->collisionInfo;
			for($i = 1, $count = count($missing); $i < $count; $i += 3){
				$x = $missing[$i];
				$y = $missing[$i + 1];
				$z = $missing[$i + 2];
				$this->blockCollisionBoxCache[World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)][self::collisionCellKey($x, $y, $z)] = $this->getBlockCollisionBoxesForCell($x, $y, $z, $collisionInfo);
			}
		}
		return null;
	}

	/**
	 * @deprecated Use {@link World::getBlockCollisionBoxes()} instead (alongside {@link World::getCollidingEntities()}
	 * if entity collision boxes are also required).
	 *
	 * @return AxisAlignedBB[]
	 * @phpstan-return list<AxisAlignedBB>
	 */
	public function getCollisionBoxes(Entity $entity, AxisAlignedBB $bb, bool $entities = true) : array{
		$collides = $this->getBlockCollisionBoxes($bb);

		if($entities){
			foreach($this->getCollidingEntities($bb->expandedCopy(0.25, 0.25, 0.25), $entity) as $ent){
				$collides[] = clone $ent->boundingBox;
			}
		}

		return $collides;
	}

	/**
	 * Computes the percentage of a circle away from noon the sun is currently at. This can be multiplied by 2 * M_PI to
	 * get an angle in radians, or by 360 to get an angle in degrees.
	 */
	public function computeSunAnglePercentage() : float{
		$timeProgress = ($this->time % self::TIME_FULL) / self::TIME_FULL;

		//0.0 needs to be high noon, not dusk
		$sunProgress = $timeProgress + ($timeProgress < 0.25 ? 0.75 : -0.25);

		//Offset the sun progress to be above the horizon longer at dusk and dawn
		//this is roughly an inverted sine curve, which pushes the sun progress back at dusk and forwards at dawn
		$diff = (((1 - ((cos($sunProgress * M_PI) + 1) / 2)) - $sunProgress) / 3);

		return $sunProgress + $diff;
	}

	/**
	 * Returns the percentage of a circle away from noon the sun is currently at.
	 */
	public function getSunAnglePercentage() : float{
		return $this->sunAnglePercentage;
	}

	/**
	 * Returns the current sun angle in radians.
	 */
	public function getSunAngleRadians() : float{
		return $this->sunAnglePercentage * 2 * M_PI;
	}

	/**
	 * Returns the current sun angle in degrees.
	 */
	public function getSunAngleDegrees() : float{
		return $this->sunAnglePercentage * 360.0;
	}

	/**
	 * Computes how many points of sky light is subtracted based on the current time. Used to offset raw chunk sky light
	 * to get a real light value.
	 */
	public function computeSkyLightReduction() : int{
		if(!$this->dimension->hasSkyLight()){
			return 15; //no sky in the Nether or the End: only block light counts
		}
		$percentage = max(0, min(1, -(cos($this->getSunAngleRadians()) * 2 - 0.5)));

		//rain and thunder each dim the sky by up to 5/16 (vanilla)
		$rain = $this->weather->getRainStrength();
		$thunder = $this->weather->getThunderStrength();
		if($rain > 0 || $thunder > 0){
			$percentage = 1 - (1 - $percentage) * (1 - $rain * 5 / 16) * (1 - $thunder * 5 / 16);
		}

		return (int) ($percentage * 11);
	}

	/**
	 * Returns how many points of sky light is subtracted based on the current time.
	 */
	public function getSkyLightReduction() : int{
		return $this->skyLightReduction;
	}

	/**
	 * Returns the highest level of any type of light at the given coordinates, adjusted for the current weather and
	 * time of day.
	 */
	public function getFullLight(Vector3 $pos) : int{
		$floorX = $pos->getFloorX();
		$floorY = $pos->getFloorY();
		$floorZ = $pos->getFloorZ();
		return $this->getFullLightAt($floorX, $floorY, $floorZ);
	}

	/**
	 * Returns the highest level of any type of light at the given coordinates, adjusted for the current weather and
	 * time of day.
	 */
	public function getFullLightAt(int $x, int $y, int $z) : int{
		$skyLight = $this->getRealBlockSkyLightAt($x, $y, $z);
		if($skyLight < 15){
			return max($skyLight, $this->getBlockLightAt($x, $y, $z));
		}else{
			return $skyLight;
		}
	}

	/**
	 * Returns the highest level of any type of light at, or adjacent to, the given coordinates, adjusted for the
	 * current weather and time of day.
	 */
	public function getHighestAdjacentFullLightAt(int $x, int $y, int $z) : int{
		return $this->getHighestAdjacentLight($x, $y, $z, $this->getFullLightAt(...));
	}

	/**
	 * Returns the highest potential level of any type of light at the target coordinates.
	 * This is not affected by weather or time of day.
	 */
	public function getPotentialLight(Vector3 $pos) : int{
		$floorX = $pos->getFloorX();
		$floorY = $pos->getFloorY();
		$floorZ = $pos->getFloorZ();
		return $this->getPotentialLightAt($floorX, $floorY, $floorZ);
	}

	/**
	 * Returns the highest potential level of any type of light at the target coordinates.
	 * This is not affected by weather or time of day.
	 */
	public function getPotentialLightAt(int $x, int $y, int $z) : int{
		return max($this->getPotentialBlockSkyLightAt($x, $y, $z), $this->getBlockLightAt($x, $y, $z));
	}

	/**
	 * Returns the highest potential level of any type of light at, or adjacent to, the given coordinates.
	 * This is not affected by weather or time of day.
	 */
	public function getHighestAdjacentPotentialLightAt(int $x, int $y, int $z) : int{
		return $this->getHighestAdjacentLight($x, $y, $z, $this->getPotentialLightAt(...));
	}

	/**
	 * Returns the highest potential level of sky light at the target coordinates, regardless of the time of day or
	 * weather conditions.
	 *
	 * @return int 0-15
	 */
	public function getPotentialBlockSkyLightAt(int $x, int $y, int $z) : int{
		if(!$this->isInWorld($x, $y, $z)){
			return $y >= self::Y_MAX ? 15 : 0;
		}
		if(($chunk = $this->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)) !== null && $chunk->isLightPopulated() === true){
			return $chunk->getSubChunk($y >> Chunk::COORD_BIT_SIZE)->getBlockSkyLightArray()->get($x & SubChunk::COORD_MASK, $y & SubChunk::COORD_MASK, $z & SubChunk::COORD_MASK);
		}
		return 0; //TODO: this should probably throw instead (light not calculated yet)
	}

	/**
	 * Returns the sky light level at the specified coordinates, offset by the current time and weather.
	 *
	 * @return int 0-15
	 */
	public function getRealBlockSkyLightAt(int $x, int $y, int $z) : int{
		$light = $this->getPotentialBlockSkyLightAt($x, $y, $z) - $this->skyLightReduction;
		return $light < 0 ? 0 : $light;
	}

	/**
	 * Gets the raw block light level
	 *
	 * @return int 0-15
	 */
	public function getBlockLightAt(int $x, int $y, int $z) : int{
		if(!$this->isInWorld($x, $y, $z)){
			return 0;
		}
		if(($chunk = $this->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)) !== null && $chunk->isLightPopulated() === true){
			return $chunk->getSubChunk($y >> Chunk::COORD_BIT_SIZE)->getBlockLightArray()->get($x & SubChunk::COORD_MASK, $y & SubChunk::COORD_MASK, $z & SubChunk::COORD_MASK);
		}
		return 0; //TODO: this should probably throw instead (light not calculated yet)
	}

	public function updateAllLight(int $x, int $y, int $z) : void{
		if(($chunk = $this->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)) === null || $chunk->isLightPopulated() !== true){
			return;
		}

		$blockFactory = $this->blockStateRegistry;
		$this->timings->doBlockSkyLightUpdates->startTiming();
		if($this->skyLightUpdate === null){
			$this->skyLightUpdate = new SkyLightUpdate(new SubChunkExplorer($this), $blockFactory->lightFilter, $blockFactory->blocksDirectSkyLight);
		}
		$this->skyLightUpdate->recalculateNode($x, $y, $z);
		$this->timings->doBlockSkyLightUpdates->stopTiming();

		$this->timings->doBlockLightUpdates->startTiming();
		if($this->blockLightUpdate === null){
			$this->blockLightUpdate = new BlockLightUpdate(new SubChunkExplorer($this), $blockFactory->lightFilter, $blockFactory->light);
		}
		$this->blockLightUpdate->recalculateNode($x, $y, $z);
		$this->timings->doBlockLightUpdates->stopTiming();
	}

	/**
	 * @phpstan-param \Closure(int $x, int $y, int $z) : int $lightGetter
	 */
	private function getHighestAdjacentLight(int $x, int $y, int $z, \Closure $lightGetter) : int{
		$max = 0;
		foreach(Facing::OFFSET as [$offsetX, $offsetY, $offsetZ]){
			$x1 = $x + $offsetX;
			$y1 = $y + $offsetY;
			$z1 = $z + $offsetZ;
			if(
				!$this->isInWorld($x1, $y1, $z1) ||
				($chunk = $this->getChunk($x1 >> Chunk::COORD_BIT_SIZE, $z1 >> Chunk::COORD_BIT_SIZE)) === null ||
				$chunk->isLightPopulated() !== true
			){
				continue;
			}
			$max = max($max, $lightGetter($x1, $y1, $z1));
		}
		return $max;
	}

	/**
	 * Returns the highest potential level of sky light in the positions adjacent to the specified block coordinates.
	 */
	public function getHighestAdjacentPotentialBlockSkyLight(int $x, int $y, int $z) : int{
		return $this->getHighestAdjacentLight($x, $y, $z, $this->getPotentialBlockSkyLightAt(...));
	}

	/**
	 * Returns the highest block sky light available in the positions adjacent to the given coordinates, adjusted for
	 * the world's current time of day and weather conditions.
	 */
	public function getHighestAdjacentRealBlockSkyLight(int $x, int $y, int $z) : int{
		return $this->getHighestAdjacentPotentialBlockSkyLight($x, $y, $z) - $this->skyLightReduction;
	}

	/**
	 * Returns the highest block light level available in the positions adjacent to the specified block coordinates.
	 */
	public function getHighestAdjacentBlockLight(int $x, int $y, int $z) : int{
		return $this->getHighestAdjacentLight($x, $y, $z, $this->getBlockLightAt(...));
	}

	private function executeQueuedLightUpdates() : void{
		if($this->blockLightUpdate !== null){
			$this->timings->doBlockLightUpdates->startTiming();
			$this->blockLightUpdate->execute();
			$this->blockLightUpdate = null;
			$this->timings->doBlockLightUpdates->stopTiming();
		}

		if($this->skyLightUpdate !== null){
			$this->timings->doBlockSkyLightUpdates->startTiming();
			$this->skyLightUpdate->execute();
			$this->skyLightUpdate = null;
			$this->timings->doBlockSkyLightUpdates->stopTiming();
		}
	}

	public function isInWorld(int $x, int $y, int $z) : bool{
		return (
			$x <= Limits::INT32_MAX && $x >= Limits::INT32_MIN &&
			$y < $this->maxY && $y >= $this->minY &&
			$z <= Limits::INT32_MAX && $z >= Limits::INT32_MIN
		);
	}

	/**
	 * Gets the Block object at the Vector3 location. This method wraps around {@link getBlockAt}, converting the
	 * vector components to integers.
	 *
	 * Note: If you're using this for performance-sensitive code, and you're guaranteed to be supplying ints in the
	 * specified vector, consider using {@link getBlockAt} instead for better performance.
	 *
	 * @param bool $cached     Whether to use the block cache for getting the block (faster, but may be inaccurate)
	 * @param bool $addToCache Whether to cache the block object created by this method call.
	 */
	public function getBlock(Vector3 $pos, bool $cached = true, bool $addToCache = true) : Block{
		return $this->getBlockAt((int) floor($pos->x), (int) floor($pos->y), (int) floor($pos->z), $cached, $addToCache);
	}

	/**
	 * Gets the Block object at the specified coordinates.
	 *
	 * Note for plugin developers: If you are using this method a lot (thousands of times for many positions for
	 * example), you may want to set addToCache to false to avoid using excessive amounts of memory.
	 *
	 * @param bool $cached     Whether to use the block cache for getting the block (faster, but may be inaccurate)
	 * @param bool $addToCache Whether to cache the block object created by this method call.
	 */
	public function getBlockAt(int $x, int $y, int $z, bool $cached = true, bool $addToCache = true) : Block{
		$relativeBlockHash = null;
		$chunkHash = World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);

		if($this->isInWorld($x, $y, $z)){
			$relativeBlockHash = World::chunkBlockHash($x, $y, $z);

			if($cached && isset($this->blockCache[$chunkHash][$relativeBlockHash])){
				return $this->blockCache[$chunkHash][$relativeBlockHash];
			}

			$chunk = $this->chunks[$chunkHash] ?? null;
			if($chunk !== null){
				$block = $this->blockStateRegistry->fromStateId($chunk->getBlockStateId($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK));
			}else{
				$addToCache = false;
				$block = VanillaBlocks::AIR();
			}
		}else{
			$block = VanillaBlocks::AIR();
		}

		$block->position($this, $x, $y, $z);

		if($this->inDynamicStateRecalculation){
			//this call was generated by a parent getBlock() call calculating dynamic stateinfo
			//don't calculate dynamic state and don't add to block cache (since it won't have dynamic state calculated).
			//this ensures that it's impossible for dynamic state properties to recursively depend on each other.
			$addToCache = false;
		}else{
			$this->inDynamicStateRecalculation = true;
			$replacement = $block->readStateFromWorld();
			if($replacement !== $block){
				$replacement->position($this, $x, $y, $z);
				$block = $replacement;
			}
			$this->inDynamicStateRecalculation = false;
		}

		if($addToCache && $relativeBlockHash !== null){
			$this->blockCache[$chunkHash][$relativeBlockHash] = $block;

			if(++$this->blockCacheSize >= self::BLOCK_CACHE_SIZE_CAP){
				$this->trimBlockCache();
			}
		}

		return $block;
	}

	/**
	 * Sets the block at the given Vector3 coordinates.
	 *
	 * @throws \InvalidArgumentException if the position is out of the world bounds
	 */
	public function setBlock(Vector3 $pos, Block $block, bool $update = true) : void{
		$this->setBlockAt((int) floor($pos->x), (int) floor($pos->y), (int) floor($pos->z), $block, $update);
	}

	/**
	 * Sets the block at the given coordinates.
	 *
	 * If $update is true, it'll get the neighbour blocks (6 sides) and update them, and also update local lighting.
	 * If you are doing big changes, you might want to set this to false, then update manually.
	 *
	 * @throws \InvalidArgumentException if the position is out of the world bounds
	 */
	public function setBlockAt(int $x, int $y, int $z, Block $block, bool $update = true) : void{
		if(!$this->isInWorld($x, $y, $z)){
			throw new \InvalidArgumentException("Pos x=$x,y=$y,z=$z is outside of the world bounds");
		}
		$chunkX = $x >> Chunk::COORD_BIT_SIZE;
		$chunkZ = $z >> Chunk::COORD_BIT_SIZE;
		if($this->loadChunk($chunkX, $chunkZ) === null){ //current expected behaviour is to try to load the terrain synchronously
			throw new WorldException("Cannot set a block in un-generated terrain");
		}

		//TODO: this computes state ID twice (we do it again in writeStateToWorld()). Not great for performance :(
		$stateId = $block->getStateId();
		if(!$this->blockStateRegistry->hasStateId($stateId)){
			throw new \LogicException("Block state ID not known to RuntimeBlockStateRegistry (probably not registered)");
		}
		if(!GlobalBlockStateHandlers::getSerializer()->isRegistered($block)){
			throw new \LogicException("Block not registered with GlobalBlockStateHandlers serializer");
		}

		$this->timings->setBlock->startTiming();

		$this->unlockChunk($chunkX, $chunkZ, null);

		$block = clone $block;

		$subChunk = $this->chunks[World::chunkHash($chunkX, $chunkZ)]->getSubChunk($y >> SubChunk::COORD_BIT_SIZE);
		$oldStateId = $subChunk->getBlockStateId($x & SubChunk::COORD_MASK, $y & SubChunk::COORD_MASK, $z & SubChunk::COORD_MASK);
		$liquidStateId = $subChunk->getLiquidLayerStateId($x & SubChunk::COORD_MASK, $y & SubChunk::COORD_MASK, $z & SubChunk::COORD_MASK);
		if(
			$liquidStateId !== Block::EMPTY_STATE_ID &&
			$this->breakingBlockHash === World::blockHash($x, $y, $z) &&
			$block->getTypeId() === BlockTypeIds::AIR &&
			LiquidLayer::canContainWater($oldStateId) &&
			LiquidLayer::isWaterState($liquidStateId)
		){
			//a waterlogged block which is broken leaves its water behind, like vanilla; other changes to air (plugins,
			//pistons...) clear it, like Java's Level.setBlock()
			$block = VanillaBlocks::WATER();
			$stateId = $block->getStateId();
		}

		$block->position($this, $x, $y, $z);
		$block->writeStateToWorld();
		$this->updateLiquidLayer($subChunk, $x, $y, $z, $oldStateId, $stateId, $liquidStateId, $block);
		$pos = new Vector3($x, $y, $z);

		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		$relativeBlockHash = World::chunkBlockHash($x, $y, $z);

		unset($this->blockCache[$chunkHash][$relativeBlockHash]);
		$this->blockCacheSize--;
		unset($this->blockCollisionBoxCache[$chunkHash][self::collisionCellKey($x, $y, $z)]);
		//blocks like fences have collision boxes that reach into neighbouring blocks, so we need to invalidate the
		//caches for those blocks as well
		foreach(Facing::OFFSET as [$offsetX, $offsetY, $offsetZ]){
			$sideChunkPosHash = World::chunkHash(($x + $offsetX) >> Chunk::COORD_BIT_SIZE, ($z + $offsetZ) >> Chunk::COORD_BIT_SIZE);
			unset($this->blockCollisionBoxCache[$sideChunkPosHash][self::collisionCellKey($x + $offsetX, $y + $offsetY, $z + $offsetZ)]);
		}

		if(!isset($this->changedBlocks[$chunkHash])){
			$this->changedBlocks[$chunkHash] = [];
		}
		$this->changedBlocks[$chunkHash][$relativeBlockHash] = $pos;

		foreach($this->getChunkListeners($chunkX, $chunkZ) as $listener){
			try{
				$listener->onBlockChanged($pos);
			}catch(\Throwable $e){
				$this->isolateCallbackFailure($e, "ChunkListener::onBlockChanged()", $listener);
			}
		}

		if($oldStateId !== $stateId){
			$this->vibrations->onBlockChanged($x, $y, $z, $oldStateId, $stateId);
			$this->poiManager->onBlockChanged($chunkHash, $oldStateId, $stateId);
			$this->pathRecomputeWatch?->onBlockChanged($x, $y, $z, $oldStateId, $stateId);
		}

		if($update){
			$this->updateAllLight($x, $y, $z);
			$this->internalNotifyNeighbourBlockUpdate($x, $y, $z);
			if($oldStateId !== $stateId){
				$this->redstone?->notifyObservers($x, $y, $z);
			}
			$this->redstone?->onBlockSet($x, $y, $z, $oldStateId, $stateId);
		}

		$this->timings->setBlock->stopTiming();

		if($update && $block instanceof BaseFire && $oldStateId !== $stateId && !$this->blockStateRegistry->fromStateId($oldStateId) instanceof BaseFire){
			if($block instanceof Fire){
				//since 1.26.60 the shape is a block state: give new fire the one that fits its surroundings, whatever placed it
				Fire::refreshShapeAt($this, new Vector3($x, $y, $z));
			}
			//new fire (not fire ageing): it lights the empty obsidian frame it is in
			NetherPortalShape::onFirePlaced($this, $x, $y, $z);
		}
	}

	/**
	 * Keeps the liquid layer (block layer 1, where vanilla stores the water of waterlogged blocks) in sync with a block
	 * change. {@link Waterlogged} blocks say themselves whether they hold water. Any other block keeps the water there
	 * as long as it can hold water (see {@link LiquidLayer::canContainWater()}), so waterlogged blocks of worlds made by
	 * vanilla (stairs, slabs, corals...) keep their water when they are updated or turned into another waterloggable
	 * block, and lose it when replaced by a block which can't hold water.
	 */
	private function updateLiquidLayer(SubChunk $subChunk, int $x, int $y, int $z, int $oldStateId, int $stateId, int $liquidStateId, Block $block) : void{
		$x &= SubChunk::COORD_MASK;
		$y &= SubChunk::COORD_MASK;
		$z &= SubChunk::COORD_MASK;
		if($block instanceof Waterlogged){
			if($block->isWaterlogged()){
				//vanilla stores still water there (minecraft:water, not flowing_water)
				$subChunk->setLiquidLayerStateId($x, $y, $z, VanillaBlocks::WATER()->getStillForm()->getStateId());
			}elseif($liquidStateId !== Block::EMPTY_STATE_ID){
				$subChunk->setLiquidLayerStateId($x, $y, $z, Block::EMPTY_STATE_ID);
			}
		}elseif($liquidStateId !== Block::EMPTY_STATE_ID){
			if(!LiquidLayer::canContainWater($stateId)){
				$subChunk->setLiquidLayerStateId($x, $y, $z, Block::EMPTY_STATE_ID);
			}
		}elseif(
			LiquidLayer::isWaterSourceState($oldStateId) &&
			(LiquidLayer::takesReplacedWater($stateId) || ($this->placingBlocks && LiquidLayer::canContainWater($stateId)))
		){
			//waterloggable blocks placed into a water source keep it, like vanilla (flowing water is only displaced);
			//corals keep it however they are set there
			$subChunk->setLiquidLayerStateId($x, $y, $z, VanillaBlocks::WATER()->getStillForm()->getStateId());
		}
	}

	/**
	 * @internal Fast path used by the redstone engine to change the power level of redstone wire: writes the state ID
	 * directly into the loaded chunk and sends it to players, without tile handling, light updates or neighbour
	 * updates (the engine notifies the neighbours itself).
	 */
	public function setWireStateIdAt(int $x, int $y, int $z, int $stateId) : void{
		$chunkX = $x >> Chunk::COORD_BIT_SIZE;
		$chunkZ = $z >> Chunk::COORD_BIT_SIZE;
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		$chunk = $this->chunks[$chunkHash] ?? null;
		if($chunk === null || !$this->isInWorld($x, $y, $z)){
			return;
		}
		$this->unlockChunk($chunkX, $chunkZ, null);
		$chunk->setBlockStateId($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK, $stateId);

		$relativeBlockHash = World::chunkBlockHash($x, $y, $z);
		if(isset($this->blockCache[$chunkHash][$relativeBlockHash])){
			unset($this->blockCache[$chunkHash][$relativeBlockHash]);
			$this->blockCacheSize--;
		}
		unset($this->blockCollisionBoxCache[$chunkHash][self::collisionCellKey($x, $y, $z)]);

		$pos = new Vector3($x, $y, $z);
		$this->changedBlocks[$chunkHash][$relativeBlockHash] = $pos;
		foreach($this->getChunkListeners($chunkX, $chunkZ) as $listener){
			try{
				$listener->onBlockChanged($pos);
			}catch(\Throwable $e){
				$this->isolateCallbackFailure($e, "ChunkListener::onBlockChanged()", $listener);
			}
		}
		$this->redstone?->notifyObservers($x, $y, $z);
	}

	public function dropItem(Vector3 $source, Item $item, ?Vector3 $motion = null, int $delay = 10) : ?ItemEntity{
		if($item->isNull()){
			return null;
		}

		$itemEntity = new ItemEntity(Location::fromObject($source, $this, Utils::getRandomFloat() * 360, 0), $item);

		$itemEntity->setPickupDelay($delay);
		$itemEntity->setMotion($motion ?? new Vector3(Utils::getRandomFloat() * 0.2 - 0.1, 0.2, Utils::getRandomFloat() * 0.2 - 0.1));
		$itemEntity->spawnToAll();

		return $itemEntity;
	}

	/**
	 * Drops XP orbs into the world for the specified amount, splitting the amount into several orbs if necessary.
	 *
	 * @return ExperienceOrb[]
	 * @phpstan-return list<ExperienceOrb>
	 */
	public function dropExperience(Vector3 $pos, int $amount) : array{
		$orbs = [];

		foreach(ExperienceOrb::splitIntoOrbSizes($amount) as $split){
			$orb = new ExperienceOrb(Location::fromObject($pos, $this, Utils::getRandomFloat() * 360, 0), $split);

			$orb->setMotion(new Vector3((Utils::getRandomFloat() * 0.2 - 0.1) * 2, Utils::getRandomFloat() * 0.4, (Utils::getRandomFloat() * 0.2 - 0.1) * 2));
			$orb->spawnToAll();

			$orbs[] = $orb;
		}

		return $orbs;
	}

	/**
	 * Tries to break a block using a item, including Player time checks if available
	 * It'll try to lower the durability if Item is a tool, and set it to Air if broken.
	 *
	 * @param Item   &$item          reference parameter (if null, can break anything)
	 * @param Item[] &$returnedItems Items to be added to the target's inventory (or dropped, if the inventory is full)
	 * @phpstan-param-out Item $item
	 */
	public function useBreakOn(Vector3 $vector, ?Item &$item = null, ?Player $player = null, bool $createParticles = false, array &$returnedItems = []) : bool{
		$vector = $vector->floor();

		$chunkX = $vector->getFloorX() >> Chunk::COORD_BIT_SIZE;
		$chunkZ = $vector->getFloorZ() >> Chunk::COORD_BIT_SIZE;
		if(!$this->isChunkLoaded($chunkX, $chunkZ) || $this->isChunkLocked($chunkX, $chunkZ)){
			return false;
		}

		$target = $this->getBlock($vector);
		$affectedBlocks = $target->getAffectedBlocks();

		if($item === null){
			$item = VanillaItems::AIR();
		}

		$tileDrops = $this->getGameRule(GameRules::DO_TILE_DROPS) !== false;
		if(!$tileDrops){
			$drops = [];
		}elseif($player === null || $player->hasFiniteResources()){
			$drops = array_merge(...array_map(fn(Block $block) => $block->getDrops($item), $affectedBlocks));
		}else{
			$drops = array_merge(...array_map(fn(Block $block) => $block->getCreativeDrops(), $affectedBlocks));
		}

		$xpDrop = 0;
		if($tileDrops && $player !== null && $player->hasFiniteResources()){ //dotiledrops also stops the experience
			$xpDrop = array_sum(array_map(fn(Block $block) => $block->getXpDropForTool($item), $affectedBlocks));
		}

		if($player !== null){
			$ev = new BlockBreakEvent($player, $target, $item, $player->isCreative(), $drops, $xpDrop);

			if($target instanceof Air || ($player->isSurvival() && !$target->getBreakInfo()->isBreakable()) || $player->isSpectator()){
				$ev->cancel();
			}

			//command, structure, jigsaw and world builder blocks: only operators in creative mode break them
			if(GameMasterBlocks::isGameMasterBlock($target) && !GameMasterBlocks::canUse($player)){
				$ev->cancel();
			}

			//allow and deny blocks under the block (only checked in worlds that have some)
			$buildRule = BuildPermissionBlocks::canBuild($this, $player, $vector);
			if($buildRule === false){
				$ev->cancel();
			}

			if($player->isAdventure(true) && !$ev->isCancelled() && $buildRule !== true){
				$canBreak = false;
				$itemParser = LegacyStringToItemParser::getInstance();
				foreach($item->getCanDestroy() as $v){
					$entry = $itemParser->parse($v);
					if($entry->getBlock()->hasSameTypeId($target)){
						$canBreak = true;
						break;
					}
				}

				if(!$canBreak){
					$ev->cancel();
				}
			}

			$ev->call();
			if($ev->isCancelled()){
				return false;
			}

			$drops = $ev->getDrops();
			$xpDrop = $ev->getXpDropAmount();

		}elseif(!$target->getBreakInfo()->isBreakable()){
			return false;
		}

		foreach($affectedBlocks as $t){
			$this->destroyBlockInternal($t, $item, $player, $createParticles, $returnedItems);
		}

		$item->onDestroyBlock($target, $returnedItems);
		$this->addGameEvent($vector, GameEvent::BLOCK_DESTROY, $player, $target);
		if($player !== null){
			PiglinAi::onBlockBroken($player, $target);
		}

		if(count($drops) > 0){
			$dropPos = $vector->add(0.5, 0.5, 0.5);
			foreach($drops as $drop){
				if(!$drop->isNull()){
					$this->dropItem($dropPos, $drop);
				}
			}
		}

		if($xpDrop > 0){
			$this->dropExperience($vector->add(0.5, 0.5, 0.5), $xpDrop);
		}

		return true;
	}

	/**
	 * @param Item[] &$returnedItems
	 */
	private function destroyBlockInternal(Block $target, Item $item, ?Player $player, bool $createParticles, array &$returnedItems) : void{
		if($createParticles){
			$this->addParticle($target->getPosition()->add(0.5, 0.5, 0.5), new BlockBreakParticle($target));
		}

		$pos = $target->getPosition();
		$previousBreakingBlockHash = $this->breakingBlockHash;
		$this->breakingBlockHash = World::blockHash($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
		try{
			$target->onBreak($item, $player, $returnedItems);
		}finally{
			$this->breakingBlockHash = $previousBreakingBlockHash;
		}

		$tile = $this->getTile($target->getPosition());
		if($tile !== null){
			$tile->onBlockDestroyed();
		}
	}

	/**
	 * Sends the bone meal feedback (LevelEvent BONE_MEAL_USE: green particles and the sound, like BDS) when a block
	 * used up some of the bone meal it was clicked with. Blocks that take it as a dye or store it are not fertilized.
	 */
	private function addBoneMealParticle(Block $block, Item $item, int $countBefore) : void{
		if(
			$countBefore > 0 && $item->getCount() < $countBefore &&
			!$block instanceof BaseSign && !$block instanceof WaterCauldron && !$block instanceof ItemFrame && !$block instanceof DecoratedPot
		){
			$this->addParticle($block->getPosition(), new BoneMealUseParticle());
		}
	}

	/**
	 * Uses a item on a position and face, placing it or activating the block
	 *
	 * @param Player|null $player         default null
	 * @param bool        $playSound      Whether to play a block-place sound if the block was placed successfully.
	 * @param Item[]      &$returnedItems Items to be added to the target's inventory (or dropped if the inventory is full)
	 */
	public function useItemOn(Vector3 $vector, Item &$item, int $face, ?Vector3 $clickVector = null, ?Player $player = null, bool $playSound = false, array &$returnedItems = []) : bool{
		$blockClicked = $this->getBlock($vector);
		$blockReplace = $blockClicked->getSide($face);

		if($clickVector === null){
			$clickVector = new Vector3(0.0, 0.0, 0.0);
		}else{
			$clickVector = new Vector3(
				min(1.0, max(0.0, $clickVector->x)),
				min(1.0, max(0.0, $clickVector->y)),
				min(1.0, max(0.0, $clickVector->z))
			);
		}

		if(!$this->isInWorld($blockReplace->getPosition()->x, $blockReplace->getPosition()->y, $blockReplace->getPosition()->z)){
			//TODO: build height limit messages for custom world heights and mcregion cap
			return false;
		}
		$chunkX = $blockReplace->getPosition()->getFloorX() >> Chunk::COORD_BIT_SIZE;
		$chunkZ = $blockReplace->getPosition()->getFloorZ() >> Chunk::COORD_BIT_SIZE;
		if(!$this->isChunkLoaded($chunkX, $chunkZ) || $this->isChunkLocked($chunkX, $chunkZ)){
			return false;
		}

		if($blockClicked->getTypeId() === BlockTypeIds::AIR){
			return false;
		}

		//blocks use bone meal in onInteract(): the feedback particles are sent here when they used it up
		$fertilizerCount = $item instanceof Fertilizer ? $item->getCount() : 0;
		if($player !== null){
			$ev = new PlayerInteractEvent($player, $item, $blockClicked, $clickVector, $face, PlayerInteractEvent::RIGHT_CLICK_BLOCK);
			if($player->isSneakPressed()){
				$ev->setUseItem(false);
				$ev->setUseBlock($item->isNull()); //opening doors is still possible when sneaking if using an empty hand
			}
			if($player->isSpectator()){
				$ev->cancel(); //set it to cancelled so plugins can bypass this
			}

			$ev->call();
			if(!$ev->isCancelled()){
				if($ev->useBlock() && $blockClicked->onInteract($item, $face, $clickVector, $player, $returnedItems)){
					$this->addBoneMealParticle($blockClicked, $item, $fertilizerCount);
					return true;
				}

				if($ev->useItem()){
					$result = $item->onInteractBlock($player, $blockReplace, $blockClicked, $face, $clickVector, $returnedItems);
					if($result !== ItemUseResult::NONE){
						return $result === ItemUseResult::SUCCESS;
					}
				}
			}else{
				return false;
			}
		}elseif($blockClicked->onInteract($item, $face, $clickVector, $player, $returnedItems)){
			$this->addBoneMealParticle($blockClicked, $item, $fertilizerCount);
			return true;
		}

		if($item->isNull() || !$item->canBePlaced()){
			return false;
		}

		//TODO: while passing Facing::UP mimics the vanilla behaviour with replaceable blocks, we should really pass
		//some other value like NULL and let place() deal with it. This will look like a bug to anyone who doesn't know
		//about the vanilla behaviour.
		$tx =
			$item->getPlacementTransaction($blockClicked, $blockClicked, Facing::UP, $clickVector, $player) ??
			$item->getPlacementTransaction($blockReplace, $blockClicked, $face, $clickVector, $player);
		if($tx === null){
			//no placement options available
			return false;
		}

		foreach($tx->getBlocks() as [$x, $y, $z, $block]){
			$block->position($this, $x, $y, $z);
			if(!$block->isPlacementObstructedByEntities()){
				continue;
			}
			foreach($block->getCollisionBoxes() as $collisionBox){
				if(count($this->getCollidingEntities($collisionBox)) > 0){
					return false;  //Entity in block
				}
			}
		}

		if($player !== null){
			$ev = new BlockPlaceEvent($player, $tx, $blockClicked, $item);
			if($player->isSpectator()){
				$ev->cancel();
			}

			//allow and deny blocks under the placed blocks (only checked in worlds that have some)
			//denied if any block is denied, allowed (in adventure mode too) only if all of them are allowed
			$buildRule = null;
			$allAllowed = true;
			foreach($tx->getBlocks() as [$x, $y, $z, $_]){
				$rule = BuildPermissionBlocks::canBuildAt($this, $player, $x, $y, $z);
				if($rule === false){
					$buildRule = false;
					break;
				}
				$allAllowed = $allAllowed && $rule === true;
			}
			if($buildRule === null && $allAllowed){
				$buildRule = true;
			}
			if($buildRule === false){
				$ev->cancel();
			}

			if($player->isAdventure(true) && !$ev->isCancelled() && $buildRule !== true){
				$canPlace = false;
				$itemParser = LegacyStringToItemParser::getInstance();
				foreach($item->getCanPlaceOn() as $v){
					$entry = $itemParser->parse($v);
					if($entry->getBlock()->hasSameTypeId($blockClicked)){
						$canPlace = true;
						break;
					}
				}

				if(!$canPlace){
					$ev->cancel();
				}
			}

			$ev->call();
			if($ev->isCancelled()){
				return false;
			}
		}

		$previousPlacingBlocks = $this->placingBlocks;
		$this->placingBlocks = true;
		try{
			if(!$tx->apply()){
				return false;
			}
		}finally{
			$this->placingBlocks = $previousPlacingBlocks;
		}
		$first = true;
		foreach($tx->getBlocks() as [$x, $y, $z, $_]){
			$tile = $this->getTileAt($x, $y, $z);
			if($tile !== null){
				//TODO: seal this up inside block placement
				$tile->copyDataFromItem($item);
			}

			$placed = $this->getBlockAt($x, $y, $z);
			$placed->onPostPlace();
			if($first && $playSound){
				$this->addSound($placed->getPosition(), new BlockPlaceSound($placed));
			}
			if($first){
				$this->addGameEvent($placed->getPosition(), GameEvent::BLOCK_PLACE, $player, $placed);
			}
			$first = false;
		}

		$item->pop();

		return true;
	}

	public function getEntity(int $entityId) : ?Entity{
		return $this->entities[$entityId] ?? null;
	}

	/**
	 * Returns a list of all the entities in this world, indexed by their entity runtime IDs
	 *
	 * @return Entity[]
	 * @phpstan-return array<int, Entity>
	 */
	public function getEntities() : array{
		return $this->entities;
	}

	/**
	 * Returns all collidable entities whose bounding boxes intersect the given bounding box.
	 * If an entity is given, it will be excluded from the result.
	 * If a non-collidable entity is given, the result will be empty.
	 *
	 * This function is the same as {@link World::getNearbyEntities()}, but with additional collidability filters.
	 *
	 * @return Entity[]
	 * @phpstan-return array<int, Entity>
	 */
	public function getCollidingEntities(AxisAlignedBB $bb, ?Entity $entity = null) : array{
		$nearby = [];

		foreach($this->getNearbyEntities($bb, $entity) as $ent){
			if($ent->canBeCollidedWith() && ($entity === null || $entity->canCollideWith($ent))){
				$nearby[] = $ent;
			}
		}

		return $nearby;
	}

	/**
	 * Returns all entities whose bounding boxes intersect the given bounding box, excluding the given entity.
	 *
	 * @return Entity[]
	 * @phpstan-return array<int, Entity>
	 */
	public function getNearbyEntities(AxisAlignedBB $bb, ?Entity $entity = null) : array{
		$nearby = [];

		$minX = ((int) floor($bb->minX - 2)) >> Chunk::COORD_BIT_SIZE;
		$maxX = ((int) floor($bb->maxX + 2)) >> Chunk::COORD_BIT_SIZE;
		$minZ = ((int) floor($bb->minZ - 2)) >> Chunk::COORD_BIT_SIZE;
		$maxZ = ((int) floor($bb->maxZ + 2)) >> Chunk::COORD_BIT_SIZE;

		for($x = $minX; $x <= $maxX; ++$x){
			for($z = $minZ; $z <= $maxZ; ++$z){
				foreach($this->getChunkEntities($x, $z) as $ent){
					if($ent !== $entity && $ent->boundingBox->intersectsWith($bb)){
						$nearby[] = $ent;
					}
				}
			}
		}

		return $nearby;
	}

	/**
	 * Returns the closest Entity to the specified position, within the given radius.
	 *
	 * @param string $entityType  Class of entity to use for instanceof
	 * @param bool   $includeDead Whether to include entitites which are dead
	 * @phpstan-template TEntity of Entity
	 * @phpstan-param class-string<TEntity> $entityType
	 *
	 * @return Entity|null an entity of type $entityType, or null if not found
	 * @phpstan-return TEntity
	 */
	public function getNearestEntity(Vector3 $pos, float $maxDistance, string $entityType = Entity::class, bool $includeDead = false) : ?Entity{
		assert(is_a($entityType, Entity::class, true));

		$minX = ((int) floor($pos->x - $maxDistance)) >> Chunk::COORD_BIT_SIZE;
		$maxX = ((int) floor($pos->x + $maxDistance)) >> Chunk::COORD_BIT_SIZE;
		$minZ = ((int) floor($pos->z - $maxDistance)) >> Chunk::COORD_BIT_SIZE;
		$maxZ = ((int) floor($pos->z + $maxDistance)) >> Chunk::COORD_BIT_SIZE;

		$currentTargetDistSq = $maxDistance ** 2;

		/**
		 * @var Entity|null $currentTarget
		 * @phpstan-var TEntity|null $currentTarget
		 */
		$currentTarget = null;

		for($x = $minX; $x <= $maxX; ++$x){
			for($z = $minZ; $z <= $maxZ; ++$z){
				foreach($this->getChunkEntities($x, $z) as $entity){
					if(!($entity instanceof $entityType) || $entity->isFlaggedForDespawn() || (!$includeDead && !$entity->isAlive())){
						continue;
					}
					$distSq = $entity->getPosition()->distanceSquared($pos);
					if($distSq < $currentTargetDistSq){
						$currentTargetDistSq = $distSq;
						$currentTarget = $entity;
					}
				}
			}
		}

		return $currentTarget;
	}

	/**
	 * Returns a list of the players in this world
	 *
	 * @return Player[] entity runtime ID => Player
	 * @phpstan-return array<int, Player>
	 */
	public function getPlayers() : array{
		return $this->players;
	}

	/**
	 * Returns the Tile in a position, or null if not found.
	 *
	 * Note: This method wraps getTileAt(). If you're guaranteed to be passing integers, and you're using this method
	 * in performance-sensitive code, consider using getTileAt() instead of this method for better performance.
	 */
	public function getTile(Vector3 $pos) : ?Tile{
		return $this->getTileAt((int) floor($pos->x), (int) floor($pos->y), (int) floor($pos->z));
	}

	/**
	 * Returns the tile at the specified x,y,z coordinates, or null if it does not exist.
	 */
	public function getTileAt(int $x, int $y, int $z) : ?Tile{
		return ($chunk = $this->loadChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)) !== null ? $chunk->getTile($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK) : null;
	}

	public function getBiomeId(int $x, int $y, int $z) : int{
		if(($chunk = $this->loadChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)) !== null){
			return $chunk->getBiomeId($x & Chunk::COORD_MASK, $y & Chunk::COORD_MASK, $z & Chunk::COORD_MASK);
		}
		return BiomeIds::OCEAN; //TODO: this should probably throw instead (terrain not generated yet)
	}

	public function getBiome(int $x, int $y, int $z) : Biome{
		return BiomeRegistry::getInstance()->getBiome($this->getBiomeId($x, $y, $z));
	}

	public function setBiomeId(int $x, int $y, int $z, int $biomeId) : void{
		$chunkX = $x >> Chunk::COORD_BIT_SIZE;
		$chunkZ = $z >> Chunk::COORD_BIT_SIZE;
		$this->unlockChunk($chunkX, $chunkZ, null);
		if(($chunk = $this->loadChunk($chunkX, $chunkZ)) !== null){
			$chunk->setBiomeId($x & Chunk::COORD_MASK, $y & Chunk::COORD_MASK, $z & Chunk::COORD_MASK, $biomeId);
		}else{
			//if we allowed this, the modifications would be lost when the chunk is created
			throw new WorldException("Cannot set biome in a non-generated chunk");
		}
	}

	/**
	 * @return Chunk[] chunkHash => Chunk
	 * @phpstan-return array<ChunkPosHash, Chunk>
	 */
	public function getLoadedChunks() : array{
		return $this->chunks;
	}

	public function getChunk(int $chunkX, int $chunkZ) : ?Chunk{
		return $this->chunks[World::chunkHash($chunkX, $chunkZ)] ?? null;
	}

	/**
	 * @return Entity[] entity runtime ID => Entity
	 * @phpstan-return array<int, Entity>
	 */
	public function getChunkEntities(int $chunkX, int $chunkZ) : array{
		return $this->entitiesByChunk[World::chunkHash($chunkX, $chunkZ)] ?? [];
	}

	/**
	 * Returns the chunk containing the given Vector3 position.
	 */
	public function getOrLoadChunkAtPosition(Vector3 $pos) : ?Chunk{
		return $this->loadChunk($pos->getFloorX() >> Chunk::COORD_BIT_SIZE, $pos->getFloorZ() >> Chunk::COORD_BIT_SIZE);
	}

	/**
	 * Returns the chunks adjacent to the specified chunk.
	 *
	 * @return Chunk[]|null[] chunkHash => Chunk|null
	 * @phpstan-return array<ChunkPosHash, Chunk|null>
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
		$this->poiManager->invalidateChunk($chunkX, $chunkZ);
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
		unset($this->cleanSavedChunks[$chunkHash]);

		$this->blockCacheSize -= count($this->blockCache[$chunkHash] ?? []);
		unset($this->blockCache[$chunkHash]);
		unset($this->blockCollisionBoxCache[$chunkHash]);
		unset($this->changedBlocks[$chunkHash]);
		$this->vibrations->onChunkUnloaded($chunkX, $chunkZ);
		$chunk->setTerrainDirty();
		$this->markTickingChunkForRecheck($chunkX, $chunkZ); //this replacement chunk may not meet the conditions for ticking

		if(count($chunk->getPendingTileNBT()) > 0){
			$this->createPendingTiles($chunk);
		}
		if(count($chunk->getPendingEntityNBT()) > 0){
			$this->createPendingEntities($chunkX, $chunkZ, $chunk);
		}

		if(!$this->isChunkInUse($chunkX, $chunkZ)){
			$this->unloadChunkRequest($chunkX, $chunkZ);
		}

		if($oldChunk === null){
			if(ChunkLoadEvent::hasHandlers()){
				(new ChunkLoadEvent($this, $chunkX, $chunkZ, $chunk, true))->call();
			}

			foreach($this->getChunkListeners($chunkX, $chunkZ) as $listener){
				try{
					$listener->onChunkLoaded($chunkX, $chunkZ, $chunk);
				}catch(\Throwable $e){
					$this->isolateCallbackFailure($e, "ChunkListener::onChunkLoaded()", $listener);
				}
			}
		}else{
			foreach($this->getChunkListeners($chunkX, $chunkZ) as $listener){
				try{
					$listener->onChunkChanged($chunkX, $chunkZ, $chunk);
				}catch(\Throwable $e){
					$this->isolateCallbackFailure($e, "ChunkListener::onChunkChanged()", $listener);
				}
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

	/**
	 * Creates the tiles of the block entity data a world generator left in a chunk (Chunk::addPendingTileNBT()).
	 * Generated data replaces an existing tile at the same position; data for a block that does not use that tile is
	 * dropped.
	 */
	private function createPendingTiles(Chunk $chunk) : void{
		$pending = $chunk->getPendingTileNBT();
		$chunk->clearPendingTileNBT();
		$tileFactory = TileFactory::getInstance();
		foreach($pending as $nbt){
			try{
				$tile = $tileFactory->createFromData($this, $nbt);
			}catch(SavedDataLoadingException $e){
				$this->logger->debug("Bad generated tile data: " . $e->getMessage());
				continue;
			}
			if($tile === null){
				continue;
			}
			$pos = $tile->getPosition();
			$x = $pos->getFloorX();
			$y = $pos->getFloorY();
			$z = $pos->getFloorZ();
			if(!$this->isInWorld($x, $y, $z)){
				continue;
			}
			$expectedTileClass = $this->getBlockAt($x, $y, $z, addToCache: false)->getIdInfo()->getTileClass();
			if($expectedTileClass === null || !($tile instanceof $expectedTileClass)){
				//not added to the world: just dropped (closing it would remove the tile at its position from the chunk)
				continue;
			}
			$this->getTileAt($x, $y, $z)?->close();
			$this->addTile($tile);
		}
	}

	/**
	 * Spawns the entities a world generator left in a chunk (Chunk::addPendingEntityNBT()). Entities that
	 * PocketMine-MP cannot create, or that are outside the chunk, are skipped.
	 */
	private function createPendingEntities(int $chunkX, int $chunkZ, Chunk $chunk) : void{
		$pending = $chunk->getPendingEntityNBT();
		$chunk->clearPendingEntityNBT();
		$registry = EntitySpawnRegistry::getInstance();
		foreach($pending as $nbt){
			$identifier = $nbt->getString(Chunk::PENDING_ENTITY_IDENTIFIER, "");
			if(!$registry->isRegistered($identifier)){
				$identifier = match($identifier){
					EntityIds::VILLAGER_V2 => EntityIds::VILLAGER,
					EntityIds::ZOMBIE_VILLAGER_V2 => EntityIds::ZOMBIE_VILLAGER,
					default => $identifier
				};
				if(!$registry->isRegistered($identifier)){
					continue;
				}
			}
			$pos = [];
			foreach($nbt->getListTag(Chunk::PENDING_ENTITY_POS) ?? [] as $value){
				if($value instanceof DoubleTag){
					$pos[] = $value->getValue();
				}
			}
			if(count($pos) !== 3 || ((int) floor($pos[0]) >> Chunk::COORD_BIT_SIZE) !== $chunkX || ((int) floor($pos[2]) >> Chunk::COORD_BIT_SIZE) !== $chunkZ){
				continue;
			}
			$rotation = [];
			foreach($nbt->getListTag(Chunk::PENDING_ENTITY_ROTATION) ?? [] as $value){
				if($value instanceof FloatTag){
					$rotation[] = $value->getValue();
				}
			}
			try{
				$entity = $registry->create($identifier, new Location($pos[0], $pos[1], $pos[2], $this, $rotation[0] ?? 0.0, $rotation[1] ?? 0.0));
			}catch(\Throwable $e){
				$this->logger->debug("Failed to spawn generated entity $identifier: " . $e->getMessage());
				continue;
			}
			if($entity instanceof ContainerMinecart && ($lootTable = $nbt->getTag(TileContainer::TAG_LOOT_TABLE)) instanceof StringTag){
				//chest minecarts of mineshafts
				$longSeed = $nbt->getTag(TileContainer::TAG_LOOT_TABLE_SEED_LONG);
				$intSeed = $nbt->getTag(TileContainer::TAG_LOOT_TABLE_SEED);
				$entity->setLootTable($lootTable->getValue(), $longSeed instanceof LongTag ? $longSeed->getValue() : ($intSeed instanceof IntTag ? $intSeed->getValue() : 0));
			}elseif($entity instanceof VillagerV2){
				//village villagers: Bedrock spawn_from_village (unemployed or nitwit adults)
				$entity->setSpawnReason(SpawnReason::STRUCTURE);
			}elseif($entity instanceof Cat){
				//the witch hut cat: Bedrock spawn_midnight_cat (an all black adult)
				$entity->setSpawnReason(SpawnReason::STRUCTURE);
			}
			$entity?->spawnToAll();
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
		if(self::mayBeStoodOn($entity)){
			++$this->entitiesThatMayBeStoodOn;
		}
		foreach($this->entityCountsByClass as $class => $count){
			if($entity instanceof $class){
				$this->entityCountsByClass[$class] = $count + 1;
			}
		}
		$this->entities[$entity->getId()] = $entity;
	}

	/**
	 * Whether the entity class overrides Entity::canBeStoodOn() (boats, happy ghasts, plugin entities...).
	 */
	private static function mayBeStoodOn(Entity $entity) : bool{
		return self::$mayBeStoodOnByClass[$entity::class] ??= (new \ReflectionMethod($entity, "canBeStoodOn"))->getDeclaringClass()->getName() !== Entity::class;
	}

	/**
	 * Returns whether this world holds any entity which players may be able to stand on (see
	 * {@link Entity::canBeStoodOn()}). Lets per-movement checks skip looking for such entities in most worlds.
	 */
	public function hasEntitiesThatMayBeStoodOn() : bool{
		return $this->entitiesThatMayBeStoodOn > 0;
	}

	/**
	 * Returns whether this world holds any entity which is an instance of the given class (or of a subclass). Answered
	 * from a count kept up to date by {@link World::addEntity()} and {@link World::removeEntity()}, so target searches
	 * for rare mobs (an enderman looking for endermites) can be skipped in worlds without any.
	 *
	 * @phpstan-param class-string<Entity> $class
	 */
	public function hasEntitiesOfClass(string $class) : bool{
		if(!isset($this->entityCountsByClass[$class])){
			$count = 0;
			foreach($this->entities as $entity){
				if($entity instanceof $class){
					++$count;
				}
			}
			$this->entityCountsByClass[$class] = $count;
		}
		return $this->entityCountsByClass[$class] > 0;
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
		if(self::mayBeStoodOn($entity)){
			--$this->entitiesThatMayBeStoodOn;
		}
		foreach($this->entityCountsByClass as $class => $count){
			if($entity instanceof $class){
				$this->entityCountsByClass[$class] = $count - 1;
			}
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
			try{
				$listener->onBlockChanged($pos->asVector3());
			}catch(\Throwable $e){
				$this->isolateCallbackFailure($e, "ChunkListener::onBlockChanged()", $listener);
			}
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
			try{
				$listener->onChunkLoaded($x, $z, $this->chunks[$chunkHash]);
			}catch(\Throwable $e){
				$this->isolateCallbackFailure($e, "ChunkListener::onChunkLoaded()", $listener);
			}
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

		foreach($chunkData->getScheduledBlockUpdates() as [$x, $y, $z, $delay]){
			if(($x >> Chunk::COORD_BIT_SIZE) !== $chunkX || ($z >> Chunk::COORD_BIT_SIZE) !== $chunkZ || !$this->isInWorld($x, $y, $z)){
				$logger->debug("Dropped scheduled block update outside the chunk at x=$x,y=$y,z=$z");
				continue;
			}
			//due updates (paused ones too) run on the next tick
			$this->scheduleDelayedBlockUpdate(new Vector3($x, $y, $z), max(1, $delay));
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
		$this->poiManager->invalidateChunk($x, $z);

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

			$saved = false;
			if($trySave && $this->getAutoSave()){
				if($this->isChunkUnchangedSinceSave($chunkHash, $chunk)){
					$saved = true; //the provider already has exactly this data (and no scheduled updates)
				}else{
					$this->timings->syncChunkSave->startTiming();
					try{
						$this->writeChunk($x, $z, $chunkHash, $chunk, false);
						$saved = true;
					}finally{
						$this->timings->syncChunkSave->stopTiming();
					}
				}
			}
			unset($this->cleanSavedChunks[$chunkHash]);
			$this->dropChunkScheduledBlockUpdates($chunkHash, $saved);

			foreach($this->getChunkListeners($x, $z) as $listener){
				try{
					$listener->onChunkUnloaded($x, $z, $chunk);
				}catch(\Throwable $e){
					$this->isolateCallbackFailure($e, "ChunkListener::onChunkUnloaded()", $listener);
				}
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
		$this->redstone?->onChunkUnloaded($x, $z);
		$this->vibrations->onChunkUnloaded($x, $z);
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
	 * Returns the age of the world: the number of ticks it has run since it was created. Unlike {@link World::getTime()},
	 * it is not changed by /time and keeps increasing while the daylight cycle is stopped (Bedrock "currentTick",
	 * used by world_age_filter spawn rules such as pillager patrols).
	 */
	public function getWorldAge() : int{
		return $this->worldAge;
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

	/**
	 * Returns the dimension this world is presented as to clients (sky, fog, chunk height, portals).
	 */
	public function getDimension() : Dimension{
		return $this->dimension;
	}

	public function getMaxY() : int{
		return $this->maxY;
	}

	/**
	 * Raids of this world (villages attacked by illagers) and the Bad Omen / Raid Omen effects that start them.
	 */
	public function getRaidManager() : RaidManager{
		return $this->raidManager;
	}

	/**
	 * Village points of interest of this world (beds, bells, job sites) and the villagers that claimed them.
	 */
	public function getPoiManager() : PoiManager{
		return $this->poiManager;
	}

	/**
	 * @internal The paths of the mobs in this world, which look for a new path when a block near it changes.
	 */
	public function getPathRecomputeWatch() : PathRecomputeWatch{
		return $this->pathRecomputeWatch ??= new PathRecomputeWatch($this);
	}

	/**
	 * Rain, snow and thunderstorms of this world (always clear outside the Overworld).
	 */
	public function getWeather() : Weather{
		return $this->weather;
	}

	/**
	 * Natural mob spawning around players, or null if disabled in pocketmine.yml.
	 */
	public function getNaturalMobSpawner() : ?NaturalMobSpawner{
		return $this->mobSpawner;
	}

	/**
	 * Pillager patrol spawning, or null if disabled in pocketmine.yml.
	 */
	public function getPatrolSpawner() : ?PatrolSpawner{
		return $this->patrolSpawner;
	}

	/**
	 * Returns whether mobs spawn naturally in this world (gamerule domobspawning). Mobs keep despawning when it is off.
	 */
	public function isMobSpawningEnabled() : bool{
		return $this->mobSpawningRule;
	}

	/**
	 * Turns natural mob spawning on or off (gamerule domobspawning, saved with the world when its format supports it).
	 */
	public function setMobSpawningEnabled(bool $enabled) : void{
		$this->mobSpawningRule = $enabled;
		$worldData = $this->provider->getWorldData();
		if($worldData instanceof MobSpawningWorldData){
			$worldData->setMobSpawningEnabled($enabled);
		}
	}

	/**
	 * Whether mobs live in this world: their AI runs and they spawn (mobs.enabled, and the world is not listed in
	 * mobs.disabled-worlds in pocketmine.yml).
	 */
	public function areMobsEnabled() : bool{
		return AiBudget::isEnabled() && !$this->mobsDisabled;
	}

	/**
	 * Returns the value of a game rule (see {@link GameRules}: bool rules give a bool, randomtickspeed an int).
	 *
	 * @throws \InvalidArgumentException if the rule doesn't exist
	 */
	public function getGameRule(string $rule) : bool|int{
		return match($rule){
			GameRules::DO_DAYLIGHT_CYCLE => !$this->stopTime,
			GameRules::DO_WEATHER_CYCLE => $this->weather->isWeatherCycleEnabled(),
			GameRules::DO_MOB_SPAWNING => $this->mobSpawningRule,
			default => $this->gameRules[$rule] ?? throw new \InvalidArgumentException("Unknown game rule \"$rule\"")
		};
	}

	/**
	 * Returns every game rule of this world.
	 *
	 * @return bool[]|int[]
	 * @phpstan-return array<string, bool|int>
	 */
	public function getGameRules() : array{
		$rules = [];
		foreach(GameRules::getNames() as $rule){
			$rules[$rule] = $this->getGameRule($rule);
		}
		return $rules;
	}

	/**
	 * Changes a game rule (WorldGameRuleChangeEvent), saves it with the world and tells the world's players when the
	 * client uses it. Returns false if a plugin cancelled the change.
	 *
	 * @throws \InvalidArgumentException if the rule doesn't exist or the value doesn't have its type
	 */
	public function setGameRule(string $rule, bool|int $value) : bool{
		$name = GameRules::normalizeName($rule) ?? throw new \InvalidArgumentException("Unknown game rule \"$rule\"");
		if(GameRules::validate($name, $value) === null){
			throw new \InvalidArgumentException("Invalid value for game rule \"$name\"");
		}
		$ev = new WorldGameRuleChangeEvent($this, $name, $this->getGameRule($name), $value);
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}
		$value = GameRules::validate($name, $ev->getNewValue()) ?? throw new \InvalidArgumentException("Invalid value for game rule \"$name\"");

		switch($name){
			case GameRules::DO_DAYLIGHT_CYCLE:
				$value === true ? $this->startTime() : $this->stopTime();
				break;
			case GameRules::DO_WEATHER_CYCLE:
				$this->weather->setWeatherCycleEnabled($value === true);
				break;
			case GameRules::DO_MOB_SPAWNING:
				$this->setMobSpawningEnabled($value === true);
				break;
			case GameRules::RANDOM_TICK_SPEED:
				$this->tickedBlocksPerSubchunkPerTick = $this->configuredTickedBlocksPerSubchunkPerTick * (int) $value;
				break;
			case GameRules::DO_FIRE_TICK:
				if($value !== $this->gameRules[$name]){
					$this->setLavaRandomTicked($value === true);
				}
				break;
		}
		$this->gameRules[$name] = $value;
		$worldData = $this->provider->getWorldData();
		if($worldData instanceof GameRulesWorldData){
			$worldData->setGameRule($name, $value);
		}
		if(GameRules::isSentToClients($name)){
			foreach($this->getPlayers() as $player){
				$player->getNetworkSession()->syncWorldGameRules($this);
			}
		}
		return true;
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
			$resolver->getPromise()->onCompletion(
				fn() => $this->unregisterChunkLoader($temporaryLoader, $chunkX, $chunkZ),
				static function() : void{}
			);
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
		return [$resolver, true];
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

			$this->generatorExecutor->populate(
				$chunkX,
				$chunkZ,
				$centerChunk,
				$adjacentChunks,
				function(Chunk $centerChunk, array $adjacentChunks) use ($chunkPopulationLockId, $chunkX, $chunkZ, $temporaryChunkLoader) : void{
					if(!$this->isLoaded()){
						return;
					}

					$this->generateChunkCallback($chunkPopulationLockId, $chunkX, $chunkZ, $centerChunk, $adjacentChunks, $temporaryChunkLoader);
				}
			);

			return $resolver->getPromise();
		}finally{
			$timings->stopTiming();
		}
	}

	/**
	 * @param Chunk[] $adjacentChunks chunkHash => chunk
	 * @phpstan-param array<int, Chunk> $adjacentChunks
	 */
	private function generateChunkCallback(ChunkLockId $chunkLockId, int $x, int $z, Chunk $chunk, array $adjacentChunks, ChunkLoader $temporaryChunkLoader) : void{
		$timings = $this->timings->chunkPopulationCompletion;
		$timings->startTiming();

		$dirtyChunks = 0;
		for($xx = -1; $xx <= 1; ++$xx){
			for($zz = -1; $zz <= 1; ++$zz){
				$this->unregisterChunkLoader($temporaryChunkLoader, $x + $xx, $z + $zz);
				if(!$this->unlockChunk($x + $xx, $z + $zz, $chunkLockId)){
					$dirtyChunks++;
				}
			}
		}

		$index = World::chunkHash($x, $z);
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

					foreach($this->getChunkListeners($x, $z) as $listener){
						try{
							$listener->onChunkPopulated($x, $z, $chunk);
						}catch(\Throwable $e){
							$this->isolateCallbackFailure($e, "ChunkListener::onChunkPopulated()", $listener);
						}
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
			$now = microtime(true);
			if($this->server->getMemoryManager()->isLowMemory()){
				//short of memory: the chunks nobody uses go now instead of after the usual delay, with a bigger budget
				$maxUnload = self::LOW_MEMORY_MAX_CHUNK_UNLOADS_PER_TICK;
				$minAge = 0;
				$deadline = $now + self::LOW_MEMORY_CHUNK_UNLOAD_TIME_BUDGET;
			}else{
				$maxUnload = 96;
				$minAge = $this->chunkUnloadDelay;
				$deadline = $now + self::CHUNK_UNLOAD_TIME_BUDGET;
			}
			$unloaded = 0;
			//the saves of the unloaded chunks are written together at the end
			$this->beginWriteBatch();
			try{
				foreach($this->unloadQueue as $index => $time){
					World::getXZ($index, $X, $Z);

					if(!$force){
						if($maxUnload <= 0){
							break;
						}elseif($time > ($now - $minAge)){
							continue;
						}elseif($unloaded >= self::MIN_CHUNK_UNLOADS_PER_TICK && microtime(true) >= $deadline){
							break; //the rest stays queued for the next ticks
						}
					}

					//If the chunk can't be unloaded, it stays on the queue
					if($this->unloadChunk($X, $Z, true)){
						unset($this->unloadQueue[$index]);
						--$maxUnload;
						++$unloaded;
					}
				}
			}finally{
				$this->commitWriteBatch();
			}
		}
	}
}
