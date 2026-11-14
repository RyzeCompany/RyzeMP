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
 * All the entity classes
 */
namespace pocketmine\entity;

use pocketmine\block\Block;
use pocketmine\block\BubbleColumn;
use pocketmine\block\Lava;
use pocketmine\block\Liquid;
use pocketmine\block\PowderSnow;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\block\utils\SteppedOnBlock;
use pocketmine\block\utils\Waterlogged;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Water;
use pocketmine\entity\animation\Animation;
use pocketmine\entity\object\LightningBolt;
use pocketmine\entity\riding\RideableSeat;
use pocketmine\entity\riding\RiderInput;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDespawnEvent;
use pocketmine\event\entity\EntityDismountEvent;
use pocketmine\event\entity\EntityExtinguishEvent;
use pocketmine\event\entity\EntityMotionEvent;
use pocketmine\event\entity\EntityMountEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\event\entity\EntitySpawnEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\network\mcpe\protocol\SetActorLinkPacket;
use pocketmine\network\mcpe\protocol\SetActorMotionPacket;
use pocketmine\network\mcpe\protocol\types\entity\Attribute as NetworkAttribute;
use pocketmine\network\mcpe\protocol\types\entity\EntityLink;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\MetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\timings\Timings;
use pocketmine\timings\TimingsHandler;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Limits;
use pocketmine\utils\Utils;
use pocketmine\VersionInfo;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\Position;
use pocketmine\world\sound\Sound;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use function abs;
use function array_map;
use function array_search;
use function array_splice;
use function array_unshift;
use function array_values;
use function assert;
use function cos;
use function count;
use function deg2rad;
use function floatval;
use function floor;
use function fmod;
use function get_class;
use function in_array;
use function is_finite;
use function ksort;
use function min;
use function random_int;
use function sin;
use function spl_object_id;
use const INF;
use const M_PI_2;
use const NAN;
use const PHP_INT_MAX;

abstract class Entity{

	public const MOTION_THRESHOLD = 0.00001;
	protected const STEP_CLIP_MULTIPLIER = 0.4;

	private const TAG_FIRE = "Fire"; //TAG_Short
	private const TAG_ON_GROUND = "OnGround"; //TAG_Byte
	private const TAG_FALL_DISTANCE = "FallDistance"; //TAG_Float
	private const TAG_CUSTOM_NAME = "CustomName"; //TAG_String
	private const TAG_CUSTOM_NAME_VISIBLE = "CustomNameVisible"; //TAG_Byte
	private const TAG_COMMAND_TAGS = "Tags"; //TAG_List<TAG_String> (vanilla Bedrock: the /tag tags)
	private const TAG_OWNER_ID = "OwnerID"; //TAG_Long (vanilla Bedrock: the owner's UniqueID), owners that aren't players
	private const TAG_OWNER_PLAYER_UUID = "VanillaMineOwnerUUID"; //TAG_String, player owners
	/** Ticks between two searches of the world for a missing owner saved by unique ID. */
	private const OWNER_LOOKUP_INTERVAL = 20;
	/** Most tags an entity can have (/tag add fails beyond this, like Bedrock). */
	public const MAX_COMMAND_TAGS = 1024;
	public const TAG_POS = "Pos"; //TAG_List<TAG_Double>|TAG_List<TAG_Float>
	public const TAG_MOTION = "Motion"; //TAG_List<TAG_Double>|TAG_List<TAG_Float>
	public const TAG_ROTATION = "Rotation"; //TAG_List<TAG_Float>
	public const TAG_UNIQUE_ID = "UniqueID"; //TAG_Long (vanilla Bedrock, only saved for entities taking part in a saved link)
	public const TAG_LINKS = "LinksTag"; //TAG_List<TAG_Compound> (vanilla Bedrock: the passengers of a vehicle)
	public const TAG_LINK_ENTITY_ID = "entityID"; //TAG_Long, the passenger's UniqueID
	public const TAG_LINK_ID = "linkID"; //TAG_Int, the passenger's seat order

	/**
	 * Vertical offset of a passenger's position from its seat sent to clients: players are placed with their eyes
	 * (their network position) at 0.753 of their standing eye height above the seat, mobs with their feet a quarter of
	 * their height below it (same values as PowerNukkitX, which match vanilla).
	 */
	protected const SEATED_EYE_HEIGHT_FACTOR = 0.75308642;
	private const SEATED_FEET_FACTOR = 0.24691358;
	/** Ticks after loading during which a vehicle looks for the passengers listed in its LinksTag. */
	private const LINK_RESTORE_TICKS = 100;
	/**
	 * Depth below the bottom of the world at which entities that aren't living are removed (vanilla: 64). The block
	 * position hashes used by entity ticks cover 64 blocks below the lowest possible world bottom.
	 */
	private const VOID_REMOVAL_DEPTH = 64;
	/**
	 * Bedrock void (checked on BDS 1.26.52): entities more than 16 blocks below the bottom of the world take 4 damage
	 * every 10 ticks, and nothing falls further than 40 blocks below it (the client stops players there too).
	 */
	private const VOID_DAMAGE_DEPTH = 16;
	private const VOID_DAMAGE = 4;
	private const VOID_DAMAGE_INTERVAL = 10;
	private const VOID_FLOOR_DEPTH = 40;

	private static int $entityCount = 1;
	private static ?int $adoptedRuntimeId = null;

	/**
	 * Returns a new runtime entity ID for a new entity.
	 */
	public static function nextRuntimeId() : int{
		return self::$entityCount++;
	}

	/**
	 * @internal
	 * Behind pm-proxy: the next entity created (a player arriving from another server of the network) keeps the
	 * runtime ID its client already has for itself, which can only change with a new StartGamePacket.
	 */
	public static function adoptRuntimeIdForNextEntity(int $id) : void{
		self::$adoptedRuntimeId = $id;
	}

	/**
	 * @internal
	 * Behind pm-proxy: entity IDs of this server start at $base, a range of its own, so they never collide with the
	 * ID of a player that arrives from another server of the network.
	 */
	public static function setRuntimeIdBase(int $base) : void{
		if(self::$entityCount < $base){
			self::$entityCount = $base;
		}
	}

	/**
	 * @var Player[]
	 * @phpstan-var array<int, Player>
	 */
	protected array $hasSpawned = [];
	/**
	 * @var int[] native send buffer id of every viewer, same keys as $hasSpawned (see NetworkSession::getSendId())
	 * @phpstan-var array<int, int>
	 */
	protected array $viewerSendIds = [];

	protected int $id;

	private EntityMetadataCollection $networkProperties;

	protected ?EntityDamageEvent $lastDamageCause = null;

	/** @var Block[]|null */
	protected ?array $blocksAround = null;

	/** The block can have entity collision ({@link Block::hasEntityCollision()}). */
	private const ENTITY_INSIDE_COLLISION = 1;
	/** The block can hold water in the liquid layer, which acts on entities inside it like water. */
	private const ENTITY_INSIDE_WATERLOGGABLE = 2;
	/** The block state holds water itself (waterlogged {@link Waterlogged} blocks: kelp, seagrass...). */
	private const ENTITY_INSIDE_WATER_STATE = 4;
	/**
	 * @var int[] block state ID => ENTITY_INSIDE_* flags
	 * @phpstan-var array<int, int>
	 */
	private static array $entityInsideFlagsByStateId = [];

	private static function computeEntityInsideFlags(int $stateId) : int{
		$block = RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId);
		return
			($block->hasEntityCollision() ? self::ENTITY_INSIDE_COLLISION : 0) |
			(LiquidLayer::canContainWater($stateId) ? self::ENTITY_INSIDE_WATERLOGGABLE : 0) |
			(!$block instanceof Liquid && LiquidLayer::isWaterState($stateId) ? self::ENTITY_INSIDE_WATER_STATE : 0);
	}

	/** No liquid around the entity (see $waterTopY and $lavaTopY). */
	private const NO_LIQUID_TOP = -INF;
	/**
	 * Highest surface of the water and lava blocks found around the entity by the last checkBlockIntersections() pass
	 * (NO_LIQUID_TOP when there is none), with the block it belongs to, to look for more of the same liquid above.
	 */
	private float $waterTopY = self::NO_LIQUID_TOP;
	private ?Liquid $waterTopBlock = null;
	private float $lavaTopY = self::NO_LIQUID_TOP;
	private ?Liquid $lavaTopBlock = null;
	/** Whether the fields above were worked out from the blocks around the entity since they were last looked for. */
	private bool $liquidTopsFound = false;
	/**
	 * Surface of the water / lava including the same liquid in the block above the top block (see findLiquidSurface()),
	 * null when not looked up yet since the blocks around the entity were last looked for.
	 */
	private ?float $waterSurfaceY = null;
	private ?float $lavaSurfaceY = null;

	protected const BLOCK_FLAG_SOLID_OPAQUE = 1; //isSolid() && !isTransparent()
	protected const BLOCK_FLAG_WATER = 2; //Water, or Waterlogged and waterlogged
	protected const BLOCK_FLAG_BUBBLE_COLUMN = 4;
	protected const BLOCK_FLAG_POWDER_SNOW = 8;
	/** Can hold water in the liquid layer (see {@link LiquidLayer}): the water is only known by reading that layer. */
	private const BLOCK_FLAG_WATERLOGGABLE = 16;
	/** Acts on the entities standing on it (see {@link SteppedOnBlock}). */
	private const BLOCK_FLAG_STEPPED_ON = 32;
	/**
	 * @var int[] block state ID => BLOCK_FLAG_* (these only depend on the block state)
	 * @phpstan-var array<int, int>
	 */
	private static array $blockFlagsByStateId = [];

	/**
	 * The last block under the feet known not to be a {@link SteppedOnBlock} (see stepOnBlockBelow()); forgotten when
	 * a block nearby changes, the entity is teleported, or after STEP_ON_RECHECK_TICKS (a block replaced right under
	 * the feet doesn't always notify the entity standing on it).
	 */
	private int $noStepOnBlockX = 0;
	private int $noStepOnBlockY = World::Y_MIN - 1;
	private int $noStepOnBlockZ = 0;
	private int $noStepOnBlockUntil = 0;
	/**
	 * An exact position whose block below is the one above (X is NAN when forgotten along with it): an entity standing on
	 * that very spot is still on that block, without working out the block coordinates again.
	 */
	private float $noStepOnBlockFromX = NAN;
	private float $noStepOnBlockFromY = NAN;
	private float $noStepOnBlockFromZ = NAN;
	private const STEP_ON_RECHECK_TICKS = 20;

	/**
	 * Returns the BLOCK_FLAG_* of the block at the given position (0 outside the world and in unloaded chunks, like the
	 * air World::getBlockAt() returns there), without building the block.
	 */
	protected static function blockFlagsAt(World $world, int $x, int $y, int $z) : int{
		//the same lookup in C++ when chunkutils2 can (0.3.12): the flags, or the state when it has none yet
		$native = World::$nativePhysics;
		if($native !== null && ($flags = $native->blockFlags($world, self::$blockFlagsByStateId, $x, $y, $z)) !== null){
			return $flags >= 0 ? $flags : (self::$blockFlagsByStateId[-1 - $flags] = self::computeBlockFlags(-1 - $flags));
		}
		if(!$world->isInWorld($x, $y, $z)){
			return 0;
		}
		$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		if($chunk === null){
			return 0;
		}
		//Chunk::getBlockStateId() without its own call layer (it reads the block from this subchunk)
		$stateId = $chunk->getSubChunk($y >> SubChunk::COORD_BIT_SIZE)->getBlockStateId($x & SubChunk::COORD_MASK, $y & SubChunk::COORD_MASK, $z & SubChunk::COORD_MASK);
		if(!isset(self::$blockFlagsByStateId[$stateId])){
			self::$blockFlagsByStateId[$stateId] = self::computeBlockFlags($stateId);
		}
		return self::$blockFlagsByStateId[$stateId];
	}

	private static function computeBlockFlags(int $stateId) : int{
		$block = RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId);
		return
			($block->isSolid() && !$block->isTransparent() ? self::BLOCK_FLAG_SOLID_OPAQUE : 0) |
			($block instanceof Water || ($block instanceof Waterlogged && $block->isWaterlogged()) ? self::BLOCK_FLAG_WATER : 0) |
			($block instanceof BubbleColumn ? self::BLOCK_FLAG_BUBBLE_COLUMN : 0) |
			($block instanceof PowderSnow ? self::BLOCK_FLAG_POWDER_SNOW : 0) |
			(LiquidLayer::canContainWater($stateId) ? self::BLOCK_FLAG_WATERLOGGABLE : 0) |
			($block instanceof SteppedOnBlock ? self::BLOCK_FLAG_STEPPED_ON : 0);
	}

	protected Location $location;
	protected Location $lastLocation;
	protected Vector3 $motion;
	protected Vector3 $lastMotion;
	protected bool $forceMovementUpdate = false;
	private bool $checkBlockIntersectionsNextTick = true;

	public AxisAlignedBB $boundingBox;
	public bool $onGround = false;

	public EntitySizeInfo $size;

	private float $health = 20.0;
	private int $maxHealth = 20;

	protected float $ySize = 0.0;
	protected float $stepHeight = 0.0;
	public bool $keepMovement = false;

	public float $fallDistance = 0.0;
	public int $ticksLived = 0;
	public int $lastUpdate;
	protected int $fireTicks = 0;

	private bool $savedWithChunk = true;

	public bool $isCollided = false;
	public bool $isCollidedHorizontally = false;
	public bool $isCollidedVertically = false;

	public int $noDamageTicks = 0;
	protected bool $justCreated = true;

	protected AttributeMap $attributeMap;

	protected float $gravity;
	protected float $drag;
	protected bool $gravityEnabled = true;

	protected Server $server;

	protected bool $closed = false;
	private bool $closeInFlight = false;
	private bool $needsDespawn = false;

	protected TimingsHandler $timings;

	protected bool $networkPropertiesDirty = false;

	protected string $nameTag = "";
	protected bool $nameTagVisible = true;
	/**
	 * Command tags (Bedrock /tag, matched by the tag= selector argument), as keys.
	 * @var true[]
	 * @phpstan-var array<string, true>
	 */
	private array $commandTags = [];
	protected bool $alwaysShowNameTag = false;
	protected string $scoreTag = "";
	protected float $scale = 1.0;

	protected bool $canClimb = false;
	protected bool $canClimbWalls = false;
	protected bool $noClientPredictions = false;
	protected bool $invisible = false;
	protected bool $silent = false;

	protected ?int $ownerId = null;
	protected ?int $targetId = null;
	/**
	 * Saved identity of the owner, so it is found again after a reload or after the owner reconnects (its runtime ID
	 * changes): the UUID of a player owner, or the persistent unique ID of any other owner.
	 */
	private ?UuidInterface $savedOwnerUuid = null;
	private ?int $savedOwnerUniqueId = null;
	/** Server tick before which a missing non-player owner isn't searched for again. */
	private int $nextOwnerLookupTick = 0;

	/** Persistent ID (vanilla "UniqueID"), only assigned to entities that need one to save links. */
	private ?int $uniqueId = null;
	/** The entity this entity is riding. */
	protected ?Entity $vehicle = null;
	/**
	 * Entities riding this one, by seat. The first one is the rider (it may control the vehicle).
	 * @var Entity[]
	 * @phpstan-var list<Entity>
	 */
	protected array $passengers = [];
	/**
	 * UniqueIDs of saved passengers waiting to be found after loading, by seat order.
	 * @var int[]
	 * @phpstan-var list<int>
	 */
	private array $pendingPassengerLinks = [];
	private int $linkRestoreTicks = 0;
	/** Whether the seat properties (and the seat camera ones) were sent while riding: they are reset on dismount. */
	private bool $riderDataSent = false;
	private bool $seatCameraSent = false;
	private bool $seatRotationSent = false;

	private bool $constructorCalled = false;

	public function __construct(Location $location, ?CompoundTag $nbt = null){
		if($this->constructorCalled){
			throw new \LogicException("Attempted to call constructor for an Entity multiple times");
		}
		$this->constructorCalled = true;
		Utils::checkLocationNotInfOrNaN($location);

		$this->timings = Timings::getEntityTimings($this);

		$this->size = $this->getInitialSizeInfo();
		$this->drag = $this->getInitialDragMultiplier();
		$this->gravity = $this->getInitialGravity();

		$this->id = self::$adoptedRuntimeId ?? self::nextRuntimeId();
		self::$adoptedRuntimeId = null;
		$this->server = $location->getWorld()->getServer();

		$this->location = $location->asLocation();

		$this->boundingBox = new AxisAlignedBB(0, 0, 0, 0, 0, 0);
		$this->recalculateBoundingBox();

		if($nbt !== null){
			$this->motion = EntityDataHelper::parseVec3($nbt, self::TAG_MOTION, true);
		}else{
			$this->motion = Vector3::zero();
		}

		$this->resetLastMovements();

		$this->networkProperties = new EntityMetadataCollection();

		$this->attributeMap = new AttributeMap();
		$this->addAttributes();

		$this->initEntity($nbt ?? new CompoundTag());

		$this->getWorld()->addEntity($this);

		$this->lastUpdate = $this->server->getTick();

		$this->scheduleUpdate();
	}

	abstract protected function getInitialSizeInfo() : EntitySizeInfo;

	/**
	 * Returns the percentage by which the entity's velocity is reduced per tick when moving through air.
	 * The entity's velocity is multiplied by 1 minus this value.
	 *
	 * @return float 0-1
	 */
	abstract protected function getInitialDragMultiplier() : float;

	/**
	 * Returns the downwards acceleration of the entity when falling, in blocks/tick².
	 *
	 * @return float minimum 0
	 */
	abstract protected function getInitialGravity() : float;

	public function getNameTag() : string{
		return $this->nameTag;
	}

	public function isNameTagVisible() : bool{
		return $this->nameTagVisible;
	}

	public function isNameTagAlwaysVisible() : bool{
		return $this->alwaysShowNameTag;
	}

	/**
	 * Returns whether players can rename this entity using a name tag.
	 * Note that plugins can still name entities using setNameTag().
	 */
	public function canBeRenamed() : bool{
		return false;
	}

	public function setNameTag(string $name) : void{
		$this->nameTag = $name;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Returns the command tags of this entity (Bedrock /tag; selected with @e[tag=...]). They are saved with the entity.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getCommandTags() : array{
		$tags = [];
		foreach(Utils::stringifyKeys($this->commandTags) as $tag => $_){
			$tags[] = $tag;
		}
		return $tags;
	}

	public function hasCommandTag(string $tag) : bool{
		return isset($this->commandTags[$tag]);
	}

	/**
	 * Adds a command tag. Returns false if the entity already has it, the tag is empty, or the entity has
	 * {@link MAX_COMMAND_TAGS} tags.
	 */
	public function addCommandTag(string $tag) : bool{
		if($tag === "" || isset($this->commandTags[$tag]) || count($this->commandTags) >= self::MAX_COMMAND_TAGS){
			return false;
		}
		$this->commandTags[$tag] = true;
		return true;
	}

	/**
	 * Removes a command tag. Returns false if the entity did not have it.
	 */
	public function removeCommandTag(string $tag) : bool{
		if(!isset($this->commandTags[$tag])){
			return false;
		}
		unset($this->commandTags[$tag]);
		return true;
	}

	public function setNameTagVisible(bool $value = true) : void{
		$this->nameTagVisible = $value;
		$this->networkPropertiesDirty = true;
	}

	public function setNameTagAlwaysVisible(bool $value = true) : void{
		$this->alwaysShowNameTag = $value;
		$this->networkPropertiesDirty = true;
	}

	public function getScoreTag() : ?string{
		return $this->scoreTag; //TODO: maybe this shouldn't be nullable?
	}

	public function setScoreTag(string $score) : void{
		$this->scoreTag = $score;
		$this->networkPropertiesDirty = true;
	}

	public function getScale() : float{
		return $this->scale;
	}

	public function setScale(float $value) : void{
		if($value <= 0){
			throw new \InvalidArgumentException("Scale must be greater than 0");
		}
		$this->scale = $value;
		$this->setSize($this->getInitialSizeInfo()->scale($value));
	}

	public function getBoundingBox() : AxisAlignedBB{
		return $this->boundingBox;
	}

	protected function recalculateBoundingBox() : void{
		$halfWidth = $this->size->getWidth() / 2;

		$this->boundingBox = new AxisAlignedBB(
			$this->location->x - $halfWidth,
			$this->location->y + $this->ySize,
			$this->location->z - $halfWidth,
			$this->location->x + $halfWidth,
			$this->location->y + $this->size->getHeight() + $this->ySize,
			$this->location->z + $halfWidth
		);
	}

	public function getSize() : EntitySizeInfo{
		return $this->size;
	}

	protected function setSize(EntitySizeInfo $size) : void{
		$this->size = $size;
		$this->recalculateBoundingBox();
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Returns whether clients may predict this entity's behaviour and movement. Used for things like water movement,
	 * burning, and movement smoothing (interpolation).
	 */
	public function hasNoClientPredictions() : bool{
		return $this->noClientPredictions;
	}

	/**
	 * Things such as movement in water, burning, etc. may be predicted by the client. This is sometimes not desirable,
	 * since server-side logic may differ from client-side prediction. However, things like movement smoothing
	 * (interpolation) are also controlled by this, so it should be used with care.
	 *
	 * Setting this flag will also disable player movement inputs, but this should not be relied on, as cheat clients
	 * will be able to bypass it.
	 */
	public function setNoClientPredictions(bool $value = true) : void{
		$this->noClientPredictions = $value;
		$this->networkPropertiesDirty = true;
	}

	public function isInvisible() : bool{
		return $this->invisible;
	}

	public function setInvisible(bool $value = true) : void{
		$this->invisible = $value;
		$this->networkPropertiesDirty = true;
	}

	public function isSilent() : bool{
		return $this->silent;
	}

	public function setSilent(bool $value = true) : void{
		$this->silent = $value;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Returns whether the entity is able to climb blocks such as ladders or vines.
	 */
	public function canClimb() : bool{
		return $this->canClimb;
	}

	/**
	 * Sets whether the entity is able to climb climbable blocks.
	 */
	public function setCanClimb(bool $value = true) : void{
		$this->canClimb = $value;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Returns whether this entity is climbing a block. By default this is only true if the entity is climbing a ladder or vine or similar block.
	 */
	public function canClimbWalls() : bool{
		return $this->canClimbWalls;
	}

	/**
	 * Sets whether the entity is climbing a block. If true, the entity can climb anything.
	 */
	public function setCanClimbWalls(bool $value = true) : void{
		$this->canClimbWalls = $value;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Returns the entity ID of the owning entity, or null if the entity doesn't have an owner.
	 */
	public function getOwningEntityId() : ?int{
		return $this->ownerId;
	}

	/**
	 * Returns the owning entity, or null if the entity was not found.
	 */
	public function getOwningEntity() : ?Entity{
		if($this->ownerId !== null && ($owner = $this->server->getWorldManager()->findEntity($this->ownerId)) !== null){
			return $owner;
		}
		//a player owner is found again when it reconnects; other owners only after loading this entity (a mob owner
		//that died or was unloaded since isn't searched for)
		if($this->savedOwnerUuid === null && ($this->savedOwnerUniqueId === null || $this->ownerId !== null)){
			return null;
		}
		$owner = $this->findSavedOwner();
		if($owner !== null){
			$this->ownerId = $owner->getId();
			$this->networkPropertiesDirty = true;
		}
		return $owner;
	}

	/**
	 * Finds the owner again by its saved identity (after a reload, or after a player owner reconnected): a player by
	 * UUID (a map lookup), anything else among the entities of this entity's world, searched at most once a second.
	 */
	private function findSavedOwner() : ?Entity{
		if($this->savedOwnerUuid !== null){
			$player = $this->server->getPlayerByUUID($this->savedOwnerUuid);
			return $player !== null && $player->isConnected() && !$player->isClosed() ? $player : null;
		}
		$currentTick = $this->server->getTick();
		if($currentTick < $this->nextOwnerLookupTick || $this->closed || !$this->location->isValid()){
			return null;
		}
		$this->nextOwnerLookupTick = $currentTick + self::OWNER_LOOKUP_INTERVAL;
		foreach($this->getWorld()->getEntities() as $entity){
			if($entity->uniqueId === $this->savedOwnerUniqueId && !$entity->isClosed()){
				return $entity;
			}
		}
		return null;
	}

	/**
	 * Sets the owner of the entity. Passing null will remove the current owner.
	 *
	 * @throws \InvalidArgumentException if the supplied entity is not valid
	 */
	public function setOwningEntity(?Entity $owner) : void{
		if($owner === null){
			$this->ownerId = null;
			$this->savedOwnerUuid = null;
			$this->savedOwnerUniqueId = null;
		}elseif($owner->closed){
			throw new \InvalidArgumentException("Supplied owning entity is garbage and cannot be used");
		}else{
			$this->ownerId = $owner->getId();
			if($owner instanceof Player){
				$this->savedOwnerUuid = $owner->getUniqueId();
				$this->savedOwnerUniqueId = null;
			}else{
				$this->savedOwnerUuid = null;
				$this->savedOwnerUniqueId = $owner->getPersistentUniqueId();
			}
		}
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Returns the entity ID of the entity's target, or null if it doesn't have a target.
	 */
	public function getTargetEntityId() : ?int{
		return $this->targetId;
	}

	/**
	 * Returns the entity's target entity, or null if not found.
	 * This is used for things like hostile mobs attacking entities, and for fishing rods reeling hit entities in.
	 */
	public function getTargetEntity() : ?Entity{
		return $this->targetId !== null ? $this->server->getWorldManager()->findEntity($this->targetId) : null;
	}

	/**
	 * Sets the entity's target entity. Passing null will remove the current target.
	 *
	 * @throws \InvalidArgumentException if the target entity is not valid
	 */
	public function setTargetEntity(?Entity $target) : void{
		if($target === null){
			$this->targetId = null;
		}elseif($target->closed){
			throw new \InvalidArgumentException("Supplied target entity is garbage and cannot be used");
		}else{
			$this->targetId = $target->getId();
		}
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Returns whether this entity will be saved when its chunk is unloaded.
	 */
	public function canSaveWithChunk() : bool{
		return $this->savedWithChunk;
	}

	/**
	 * Sets whether this entity will be saved when its chunk is unloaded. This can be used to prevent the entity being
	 * saved to disk.
	 */
	public function setCanSaveWithChunk(bool $value) : void{
		$this->savedWithChunk = $value;
	}

	public function saveNBT() : CompoundTag{
		$nbt = CompoundTag::create()
			->setTag(self::TAG_POS, new ListTag([
				new DoubleTag($this->location->x),
				new DoubleTag($this->location->y),
				new DoubleTag($this->location->z)
			]))
			->setTag(self::TAG_MOTION, new ListTag([
				new DoubleTag($this->motion->x),
				new DoubleTag($this->motion->y),
				new DoubleTag($this->motion->z)
			]))
			->setTag(self::TAG_ROTATION, new ListTag([
				new FloatTag($this->location->yaw),
				new FloatTag($this->location->pitch)
			]));

		if(!($this instanceof NeverSavedWithChunkEntity)){
			EntityFactory::getInstance()->injectSaveId(get_class($this), $nbt);

			if($this->getNameTag() !== ""){
				$nbt->setString(self::TAG_CUSTOM_NAME, $this->getNameTag());
				$nbt->setByte(self::TAG_CUSTOM_NAME_VISIBLE, $this->isNameTagVisible() ? 1 : 0);
			}
		}

		if(count($this->commandTags) > 0){
			$tagList = [];
			foreach($this->getCommandTags() as $tag){
				$tagList[] = new StringTag($tag);
			}
			$nbt->setTag(self::TAG_COMMAND_TAGS, new ListTag($tagList));
		}

		$nbt->setFloat(self::TAG_FALL_DISTANCE, $this->fallDistance);
		$nbt->setShort(self::TAG_FIRE, $this->fireTicks);
		$nbt->setByte(self::TAG_ON_GROUND, $this->onGround ? 1 : 0);

		if($this->uniqueId !== null){
			$nbt->setLong(self::TAG_UNIQUE_ID, $this->uniqueId);
		}
		if($this->savedOwnerUuid !== null){
			$nbt->setString(self::TAG_OWNER_PLAYER_UUID, $this->savedOwnerUuid->toString());
		}elseif($this->savedOwnerUniqueId !== null){
			$nbt->setLong(self::TAG_OWNER_ID, $this->savedOwnerUniqueId);
		}
		$links = [];
		foreach($this->passengers as $passenger){
			//players' rides are not saved, nor the parrots on a player's shoulders (they get off when it leaves)
			if(!$passenger instanceof Player && !$this instanceof Player && !$passenger->isClosed() && $passenger->canSaveWithChunk()){
				$links[] = $passenger->getPersistentUniqueId();
			}
		}
		foreach($this->pendingPassengerLinks as $uniqueId){
			if(!in_array($uniqueId, $links, true)){
				$links[] = $uniqueId;
			}
		}
		if(count($links) > 0){
			$linkTags = [];
			foreach($links as $linkId => $uniqueId){
				$linkTags[] = CompoundTag::create()
					->setLong(self::TAG_LINK_ENTITY_ID, $uniqueId)
					->setInt(self::TAG_LINK_ID, $linkId);
			}
			$nbt->setTag(self::TAG_LINKS, new ListTag($linkTags));
		}

		$nbt->setLong(VersionInfo::TAG_WORLD_DATA_VERSION, VersionInfo::WORLD_DATA_VERSION);

		return $nbt;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		if(($uniqueIdTag = $nbt->getTag(self::TAG_UNIQUE_ID)) instanceof LongTag){
			$this->uniqueId = $uniqueIdTag->getValue();
		}
		if(($ownerUuidTag = $nbt->getTag(self::TAG_OWNER_PLAYER_UUID)) instanceof StringTag && Uuid::isValid($ownerUuidTag->getValue())){
			$this->savedOwnerUuid = Uuid::fromString($ownerUuidTag->getValue());
		}elseif(($ownerIdTag = $nbt->getTag(self::TAG_OWNER_ID)) instanceof LongTag){
			$this->savedOwnerUniqueId = $ownerIdTag->getValue();
		}
		$linksTag = $nbt->getTag(self::TAG_LINKS);
		if($linksTag instanceof ListTag){
			$links = [];
			foreach($linksTag as $link){
				if($link instanceof CompoundTag && ($passengerId = $link->getTag(self::TAG_LINK_ENTITY_ID)) instanceof LongTag){
					$links[$link->getInt(self::TAG_LINK_ID, count($links))] = $passengerId->getValue();
				}
			}
			ksort($links);
			$this->pendingPassengerLinks = array_values($links);
			$this->linkRestoreTicks = self::LINK_RESTORE_TICKS;
		}

		$this->fireTicks = $nbt->getShort(self::TAG_FIRE, 0);

		$commandTags = $nbt->getTag(self::TAG_COMMAND_TAGS);
		if($commandTags instanceof ListTag){
			foreach($commandTags as $tag){
				if($tag instanceof StringTag){
					$this->addCommandTag($tag->getValue());
				}
			}
		}

		$this->onGround = $nbt->getByte(self::TAG_ON_GROUND, 0) !== 0;

		$this->fallDistance = $nbt->getFloat(self::TAG_FALL_DISTANCE, 0.0);

		if(($customNameTag = $nbt->getTag(self::TAG_CUSTOM_NAME)) instanceof StringTag){
			$this->setNameTag($customNameTag->getValue());

			if(($customNameVisibleTag = $nbt->getTag(self::TAG_CUSTOM_NAME_VISIBLE)) instanceof StringTag){
				//Older versions incorrectly saved this as a string (see 890f72dbf23a77f294169b79590770470041adc4)
				$this->setNameTagVisible($customNameVisibleTag->getValue() !== "");
			}else{
				$this->setNameTagVisible($nbt->getByte(self::TAG_CUSTOM_NAME_VISIBLE, 1) !== 0);
			}
		}
	}

	protected function addAttributes() : void{

	}

	public function attack(EntityDamageEvent $source) : void{
		if($this->isFireProof() && EntityDamageEvent::isFireCause($source->getCause())){
			$source->cancel();
		}
		$source->call();
		if($source->isCancelled()){
			return;
		}

		$this->setLastDamageCause($source);

		$this->setHealth($this->getHealth() - $source->getFinalDamage());
	}

	public function heal(EntityRegainHealthEvent $source) : void{
		$source->call();
		if($source->isCancelled()){
			return;
		}

		$this->setHealth($this->getHealth() + $source->getAmount());
	}

	public function kill() : void{
		if($this->isAlive()){
			$this->health = 0;
			$this->onDeath();
			$this->dismount(true);
			$this->ejectPassengers();
			$this->scheduleUpdate();
		}
	}

	/**
	 * Override this to do actions on death.
	 */
	protected function onDeath() : void{

	}

	/**
	 * Called to tick entities while dead. Returns whether the entity should be flagged for despawn yet.
	 */
	protected function onDeathUpdate(int $tickDiff) : bool{
		return true;
	}

	public function isAlive() : bool{
		return $this->health > 0;
	}

	public function getHealth() : float{
		return $this->health;
	}

	/**
	 * Sets the health of the Entity. This won't send any update to the players
	 */
	public function setHealth(float $amount) : void{
		if($amount === $this->health){
			return;
		}

		if($amount <= 0){
			if($this->isAlive()){
				if(!$this->justCreated){
					$this->kill();
				}else{
					$this->health = 0;
				}
			}
		}elseif($amount <= $this->getMaxHealth() || $amount < $this->health){
			$this->health = $amount;
		}else{
			$this->health = $this->getMaxHealth();
		}
	}

	public function getMaxHealth() : int{
		return $this->maxHealth;
	}

	public function setMaxHealth(int $amount) : void{
		$this->maxHealth = $amount;
	}

	public function setLastDamageCause(EntityDamageEvent $type) : void{
		$this->lastDamageCause = $type;
	}

	public function getLastDamageCause() : ?EntityDamageEvent{
		return $this->lastDamageCause;
	}

	public function getAttributeMap() : AttributeMap{
		return $this->attributeMap;
	}

	public function getNetworkProperties() : EntityMetadataCollection{
		return $this->networkProperties;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		if(count($this->pendingPassengerLinks) > 0){
			$this->restorePassengerLinks($tickDiff);
		}

		if($this->justCreated){
			$this->justCreated = false;
			if(!$this->isAlive()){
				$this->kill();
			}
		}

		$changedProperties = $this->getDirtyNetworkData();
		if(count($changedProperties) > 0){
			$this->sendData(null, $changedProperties);
			$this->networkProperties->clearDirtyProperties();
		}

		$hasUpdate = false;

		if($this->checkBlockIntersectionsNextTick){
			$this->checkBlockIntersections();
		}
		$this->checkBlockIntersectionsNextTick = true;

		if($this->location->y < ($minY = $this->getWorld()->getMinY()) - self::VOID_DAMAGE_DEPTH){
			if($this->isAlive()){
				$ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_VOID, self::VOID_DAMAGE);
				$ev->setAttackCooldown(self::VOID_DAMAGE_INTERVAL);
				$this->attack($ev);
				$hasUpdate = true;
			}
			//mobs stop on Bedrock's void floor like players do on the client, instead of falling for thousands of blocks
			//while the void damage kills them (players move themselves)
			if($this instanceof Living && !$this instanceof Player && $this->location->y < $minY - self::VOID_FLOOR_DEPTH){
				$this->stopOnVoidFloor($minY - self::VOID_FLOOR_DEPTH);
			}
			//anything else doesn't fall forever (vanilla removes it 64 blocks below the world): items, orbs, arrows...
			//which survived the void damage (cancelled, ignored, or dropped already dead by a mob dying down there) are
			//removed before they get out of the range of block position hashes
			if(!$this instanceof Living && $this->location->y <= $minY - self::VOID_REMOVAL_DEPTH && !$this->isFlaggedForDespawn()){
				$this->flagForDespawn();
			}
		}

		if($this->ticksLived % 10 === 0 && $this->isOnFire() && $this->isInRain()){
			//rain puts out burning entities (vanilla checks every tick; every 10 ticks is close enough)
			$this->extinguish(EntityExtinguishEvent::CAUSE_RAIN);
		}

		if($this->isOnFire() && $this->doOnFireTick($tickDiff)){
			$hasUpdate = true;
		}

		if($this->noDamageTicks > 0){
			$this->noDamageTicks -= $tickDiff;
			if($this->noDamageTicks < 0){
				$this->noDamageTicks = 0;
			}
		}

		$this->ticksLived += $tickDiff;

		return $hasUpdate;
	}

	private function stopOnVoidFloor(float $floorY) : void{
		if($this->setPosition(new Vector3($this->location->x, $floorY, $this->location->z))){
			$this->motion = $this->motion->withComponents(null, 0, null);
			$this->onGround = true;
			$this->resetFallDistance();
		}
	}

	public function isOnFire() : bool{
		return $this->fireTicks > 0;
	}

	public function setOnFire(int $seconds) : void{
		$ticks = $seconds * 20;
		if($ticks > $this->getFireTicks()){
			$this->setFireTicks($ticks);
		}
		$this->networkPropertiesDirty = true;
	}

	public function getFireTicks() : int{
		return $this->fireTicks;
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public function setFireTicks(int $fireTicks) : void{
		if($fireTicks < 0){
			throw new \InvalidArgumentException("Fire ticks cannot be negative");
		}

		//Since the max value is not externally obvious or intuitive, many plugins use this without being aware that
		//reasonably large values are not accepted. We even have such usages within PM itself. It doesn't make sense
		//to force all those calls to be aware of this limitation, as it's not a functional limit but a limitation of
		//the Mojang save format. Truncating this to the max acceptable value is the next best thing we can do.
		$fireTicks = min($fireTicks, Limits::INT16_MAX);

		if(!$this->isFireProof()){
			$this->fireTicks = $fireTicks;
			$this->networkPropertiesDirty = true;
		}
	}

	public function extinguish(int $cause = EntityExtinguishEvent::CAUSE_CUSTOM) : void{
		$ev = new EntityExtinguishEvent($this, $cause);
		$ev->call();

		$this->fireTicks = 0;
		$this->networkPropertiesDirty = true;
	}

	public function isFireProof() : bool{
		return false;
	}

	/**
	 * Returns whether rain falls on the entity: it rains in the world and rain (not snow) reaches its feet or the top of
	 * its bounding box under the open sky (see {@link \pocketmine\world\weather\Weather::isRainingAt()}).
	 */
	public function isInRain() : bool{
		$weather = $this->getWorld()->getWeather();
		if(!$weather->isRaining()){
			return false;
		}
		$x = (int) floor($this->location->x);
		$z = (int) floor($this->location->z);
		return $weather->isRainingAt($x, (int) floor($this->location->y), $z) || $weather->isRainingAt($x, (int) floor($this->boundingBox->maxY), $z);
	}

	/**
	 * Called when a lightning bolt strikes the entity (once per bolt). Vanilla: 8 seconds of fire and 5 damage. Mobs
	 * that change when struck (pigs, villagers, creepers, mooshrooms...) override this.
	 */
	public function onStruckByLightning(LightningBolt $bolt) : void{
		$this->setOnFire(LightningBolt::FIRE_SECONDS);
		$this->attack(new EntityDamageEvent($this, EntityDamageEvent::CAUSE_LIGHTNING, LightningBolt::DAMAGE));
	}

	protected function doOnFireTick(int $tickDiff = 1) : bool{
		if($this->isFireProof() && $this->isOnFire()){
			$this->extinguish(EntityExtinguishEvent::CAUSE_FIRE_PROOF);
			return false;
		}

		$this->fireTicks -= $tickDiff;

		if(($this->fireTicks % 20 === 0) || $tickDiff > 20){
			$this->dealFireDamage();
		}

		if(!$this->isOnFire()){
			$this->extinguish(EntityExtinguishEvent::CAUSE_TICKING);
		}else{
			return true;
		}

		return false;
	}

	/**
	 * Called to deal damage to entities when they are on fire.
	 */
	protected function dealFireDamage() : void{
		$ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_FIRE_TICK, 1);
		$this->attack($ev);
	}

	public function canCollideWith(Entity $entity) : bool{
		return !$this->justCreated && $entity !== $this && $entity !== $this->vehicle && $entity->vehicle !== $this;
	}

	public function canBeCollidedWith() : bool{
		return $this->isAlive();
	}

	/**
	 * Returns whether players can stand on top of this entity as if it was a block (Bedrock is_collidable, shown to
	 * clients with the COLLIDABLE metadata flag). Players standing on it are on the ground: their falls end there.
	 */
	public function canBeStoodOn() : bool{
		return false;
	}

	protected function updateMovement(bool $teleport = false) : void{
		//passengers are placed on their seat by the clients, only their rotation is sent
		$diffPosition = $this->vehicle === null ? $this->location->distanceSquared($this->lastLocation) : 0.0;
		$diffRotation = ($this->location->yaw - $this->lastLocation->yaw) ** 2 + ($this->location->pitch - $this->lastLocation->pitch) ** 2;

		//motion->subtractVector(lastMotion)->lengthSquared() without the temporary vector (every entity, every tick)
		$motion = $this->motion;
		$lastMotion = $this->lastMotion;
		$dx = $motion->x - $lastMotion->x;
		$dy = $motion->y - $lastMotion->y;
		$dz = $motion->z - $lastMotion->z;
		$diffMotion = $dx * $dx + $dy * $dy + $dz * $dz;

		//lengthSquared() of both written out (the same products and sums; (float) as its return type does with int 0)
		$still = (float) ($motion->x * $motion->x + $motion->y * $motion->y + $motion->z * $motion->z) === 0.0;
		$wasStill = (float) ($lastMotion->x * $lastMotion->x + $lastMotion->y * $lastMotion->y + $lastMotion->z * $lastMotion->z) === 0.0;
		if($wasStill !== $still){
			//TODO: hack for client-side AI interference: prevent client sided movement when motion is 0
			$this->setNoClientPredictions($still);
		}

		if($teleport || $diffPosition > 0.0001 || $diffRotation > 1.0 || (!$wasStill && $still)){
			$this->lastLocation = $this->location->asLocation();

			$this->broadcastMovement($teleport);
		}

		if($diffMotion > 0.0025 || $wasStill !== $still){ //0.05 ** 2
			$this->lastMotion = clone $this->motion;

			$this->broadcastMotion();
		}
	}

	/**
	 * Returns the yaw of the entity's head sent to clients. Only mobs turn their head independently of their body.
	 */
	public function getHeadYaw() : float{
		return $this->location->yaw;
	}

	public function getOffsetPosition(Vector3 $vector3) : Vector3{
		return $vector3;
	}

	protected function broadcastMovement(bool $teleport = false) : void{
		NetworkBroadcastUtils::broadcastPackets($this->hasSpawned, [$this->createMovementPacket()], $this->viewerSendIds);
	}

	protected function createMovementPacket() : MoveActorAbsolutePacket{
		return MoveActorAbsolutePacket::create(
			$this->id,
			$this->getOffsetPosition($this->location),
			$this->location->pitch,
			$this->location->yaw,
			$this->getHeadYaw(),
			(
				//TODO: We should be setting FLAG_TELEPORT here to disable client-side movement interpolation, but it
				//breaks player teleporting (observers see the player rubberband back to the pre-teleport position while
				//the teleported player sees themselves at the correct position), and does nothing whatsoever for
				//non-player entities (movement is still interpolated). Both of these are client bugs.
				//See https://github.com/pmmp/PocketMine-MP/issues/4394
				($this->onGround ? MoveActorAbsolutePacket::FLAG_GROUND : 0)
			)
		);
	}

	protected function broadcastMotion() : void{
		NetworkBroadcastUtils::broadcastPackets($this->hasSpawned, [SetActorMotionPacket::create($this->id, $this->getMotion(), tick: 0)], $this->viewerSendIds);
	}

	public function getGravity() : float{
		return $this->gravity;
	}

	public function setGravity(float $gravity) : void{
		Utils::checkFloatNotInfOrNaN("gravity", $gravity);
		$this->gravity = $gravity;
	}

	public function hasGravity() : bool{
		return $this->gravityEnabled;
	}

	public function setHasGravity(bool $v = true) : void{
		$this->gravityEnabled = $v;
	}

	protected function applyDragBeforeGravity() : bool{
		return false;
	}

	protected function tryChangeMovement() : void{
		$friction = 1 - $this->drag;

		$mY = $this->motion->y;

		if($this->applyDragBeforeGravity()){
			$mY *= $friction;
		}

		if($this->gravityEnabled){
			$mY -= $this->gravity;
		}

		if(!$this->applyDragBeforeGravity()){
			$mY *= $friction;
		}

		if($this->onGround){
			$friction *= $this->getWorld()->getBlockAt((int) floor($this->location->x), (int) floor($this->location->y - 1), (int) floor($this->location->z))->getFrictionFactor();
		}

		$this->motion = new Vector3($this->motion->x * $friction, $mY, $this->motion->z * $friction);
	}

	protected function checkObstruction(float $x, float $y, float $z) : bool{
		$world = $this->getWorld();
		if(count($world->getBlockCollisionBoxes($this->boundingBox)) === 0){
			return false;
		}

		$floorX = (int) floor($x);
		$floorY = (int) floor($y);
		$floorZ = (int) floor($z);

		$diffX = $x - $floorX;
		$diffY = $y - $floorY;
		$diffZ = $z - $floorZ;

		if($world->getBlockAt($floorX, $floorY, $floorZ)->isSolid()){
			$westNonSolid = !$world->getBlockAt($floorX - 1, $floorY, $floorZ)->isSolid();
			$eastNonSolid = !$world->getBlockAt($floorX + 1, $floorY, $floorZ)->isSolid();
			$downNonSolid = !$world->getBlockAt($floorX, $floorY - 1, $floorZ)->isSolid();
			$upNonSolid = !$world->getBlockAt($floorX, $floorY + 1, $floorZ)->isSolid();
			$northNonSolid = !$world->getBlockAt($floorX, $floorY, $floorZ - 1)->isSolid();
			$southNonSolid = !$world->getBlockAt($floorX, $floorY, $floorZ + 1)->isSolid();

			$direction = -1;
			$limit = 9999;

			if($westNonSolid){
				$limit = $diffX;
				$direction = Facing::WEST;
			}

			if($eastNonSolid && 1 - $diffX < $limit){
				$limit = 1 - $diffX;
				$direction = Facing::EAST;
			}

			if($downNonSolid && $diffY < $limit){
				$limit = $diffY;
				$direction = Facing::DOWN;
			}

			if($upNonSolid && 1 - $diffY < $limit){
				$limit = 1 - $diffY;
				$direction = Facing::UP;
			}

			if($northNonSolid && $diffZ < $limit){
				$limit = $diffZ;
				$direction = Facing::NORTH;
			}

			if($southNonSolid && 1 - $diffZ < $limit){
				$direction = Facing::SOUTH;
			}

			if($direction === -1){
				return false;
			}

			$force = Utils::getRandomFloat() * 0.2 + 0.1;

			$this->motion = match($direction){
				Facing::WEST => $this->motion->withComponents(-$force, null, null),
				Facing::EAST => $this->motion->withComponents($force, null, null),
				Facing::DOWN => $this->motion->withComponents(null, -$force, null),
				Facing::UP => $this->motion->withComponents(null, $force, null),
				Facing::NORTH => $this->motion->withComponents(null, null, -$force),
				Facing::SOUTH => $this->motion->withComponents(null, null, $force),
			};
			return true;
		}

		return false;
	}

	public function getHorizontalFacing() : int{
		$angle = fmod($this->location->yaw, 360);
		if($angle < 0){
			$angle += 360.0;
		}

		if((0 <= $angle && $angle < 45) || (315 <= $angle && $angle < 360)){
			return Facing::SOUTH;
		}
		if(45 <= $angle && $angle < 135){
			return Facing::WEST;
		}
		if(135 <= $angle && $angle < 225){
			return Facing::NORTH;
		}

		return Facing::EAST;
	}

	public function getDirectionVector() : Vector3{
		$y = -sin(deg2rad($this->location->pitch));
		$xz = cos(deg2rad($this->location->pitch));
		$x = -$xz * sin(deg2rad($this->location->yaw));
		$z = $xz * cos(deg2rad($this->location->yaw));

		return (new Vector3($x, $y, $z))->normalize();
	}

	public function getDirectionPlane() : Vector2{
		return (new Vector2(-cos(deg2rad($this->location->yaw) - M_PI_2), -sin(deg2rad($this->location->yaw) - M_PI_2)))->normalize();
	}

	/**
	 * Called from onUpdate() on the first tick of a new entity. This is called before any movement processing or
	 * main ticking logic. Use this to fire any events related to spawning the entity.
	 */
	protected function onFirstUpdate(int $currentTick) : void{
		(new EntitySpawnEvent($this))->call();
	}

	/**
	 * Called by the world instead of onUpdate() while the entity is beyond the simulation distance of every player
	 * (see {@link World::getSimulationDistance()}): as in vanilla, it stays loaded and visible but doesn't move or act,
	 * and its own clock doesn't advance (the next onUpdate() sees a tick difference of 1). A new entity still gets its
	 * first update (spawn event, passenger links) at once.
	 *
	 * @return bool whether the entity still needs updates, like onUpdate()
	 */
	public function onFrozenUpdate(int $currentTick) : bool{
		if($this->justCreated){
			return $this->onUpdate($currentTick);
		}
		$this->lastUpdate = $currentTick;
		return true;
	}

	public function onUpdate(int $currentTick) : bool{
		if($this->closed){
			return false;
		}

		$tickDiff = $currentTick - $this->lastUpdate;
		if($tickDiff <= 0){
			if(!$this->justCreated){
				$this->server->getLogger()->debug("Expected tick difference of at least 1, got $tickDiff for " . get_class($this));
			}

			return true;
		}

		$this->lastUpdate = $currentTick;

		if($this->justCreated){
			$this->onFirstUpdate($currentTick);
		}

		if(!$this->isAlive()){
			if($this->onDeathUpdate($tickDiff)){
				$this->flagForDespawn();
			}

			return true;
		}

		//the timers are only started and stopped while timings are enabled (startTiming() and stopTiming() do nothing
		//otherwise; stopping a timer that wasn't started does nothing either, should timings be enabled meanwhile): one
		//check instead of four calls per entity per tick
		$timed = TimingsHandler::isEnabled();
		if($timed){
			$this->timings->startTiming();
		}

		if($this->vehicle !== null){
			//passengers don't move by themselves: the vehicle places them on their seat
			$this->motion = Vector3::zero();
			$this->forceMovementUpdate = false;
		}elseif($this->hasMovementUpdate()){
			$this->tryChangeMovement();

			$this->motion = $this->motion->withComponents(
				abs($this->motion->x) <= self::MOTION_THRESHOLD ? 0 : null,
				abs($this->motion->y) <= self::MOTION_THRESHOLD ? 0 : null,
				abs($this->motion->z) <= self::MOTION_THRESHOLD ? 0 : null
			);

			if(floatval($this->motion->x) !== 0.0 || floatval($this->motion->y) !== 0.0 || floatval($this->motion->z) !== 0.0){
				$this->move($this->motion->x, $this->motion->y, $this->motion->z);
			}

			$this->forceMovementUpdate = false;
		}

		$this->updateMovement();
		if(count($this->passengers) > 0){
			$this->positionPassengers();
		}

		if($timed){
			Timings::$entityBaseTick->startTiming();
		}
		$hasUpdate = $this->entityBaseTick($tickDiff);
		if($timed){
			Timings::$entityBaseTick->stopTiming();

			$this->timings->stopTiming();
		}

		return ($hasUpdate || $this->hasMovementUpdate());
	}

	final public function scheduleUpdate() : void{
		if($this->closed){
			throw new \LogicException("Cannot schedule update on garbage entity " . get_class($this));
		}
		$this->getWorld()->updateEntities[$this->id] = $this;
	}

	public function onNearbyBlockChange() : void{
		$this->setForceMovementUpdate();
		$this->scheduleUpdate();
	}

	/**
	 * Called when a random update is performed on the chunk the entity is in. This happens when the chunk is within the
	 * ticking chunk range of a player (or chunk loader).
	 */
	public function onRandomUpdate() : void{
		$this->scheduleUpdate();
	}

	/**
	 * Flags the entity as needing a movement update on the next tick. Setting this forces a movement update even if the
	 * entity's motion is zero. Used to trigger movement updates when blocks change near entities.
	 */
	final public function setForceMovementUpdate(bool $value = true) : void{
		$this->forceMovementUpdate = $value;

		$this->blocksAround = null;
		$this->noStepOnBlockY = World::Y_MIN - 1;
		$this->noStepOnBlockFromX = NAN;
	}

	/**
	 * Returns whether the entity needs a movement update on the next tick.
	 */
	public function hasMovementUpdate() : bool{
		return (
			$this->forceMovementUpdate ||
			floatval($this->motion->x) !== 0.0 ||
			floatval($this->motion->y) !== 0.0 ||
			floatval($this->motion->z) !== 0.0 ||
			!$this->onGround
		);
	}

	public function getFallDistance() : float{ return $this->fallDistance; }

	public function setFallDistance(float $fallDistance) : void{
		$this->fallDistance = $fallDistance;
	}

	public function resetFallDistance() : void{
		$this->fallDistance = 0.0;
	}

	protected function updateFallState(float $distanceThisTick, bool $onGround) : ?float{
		if($distanceThisTick < $this->fallDistance){
			//we've fallen some distance (distanceThisTick is negative)
			//or we ascended back towards where fall distance was measured from initially (distanceThisTick is positive but less than existing fallDistance)
			$this->fallDistance -= $distanceThisTick;
		}else{
			//we ascended past the apex where fall distance was originally being measured from
			//reset it so it will be measured starting from the new, higher position
			$this->fallDistance = 0;
		}
		if($onGround && $this->fallDistance > 0){
			$this->getWorld()->addGameEvent($this->location, GameEvent::HIT_GROUND, $this);
			$newVerticalVelocity = $this->onHitGround();
			$this->resetFallDistance();
			return $newVerticalVelocity;
		}
		return null;
	}

	/**
	 * Called when a falling entity hits the ground.
	 */
	protected function onHitGround() : ?float{
		//the block landed on acts on anything (vanilla Block.fallOn()): slime and beds bounce items, orbs and minecarts
		//too, hay bales and honey reduce the fall... (living entities do this with their fall damage)
		$world = $this->getWorld();
		$x = (int) floor($this->location->x);
		$y = (int) floor($this->location->y);
		$z = (int) floor($this->location->z);
		$block = $world->getBlockAt($x, $y, $z);
		if(count($block->getCollisionBoxes()) === 0){
			$block = $world->getBlockAt($x, $y - 1, $z);
		}
		return $block->onEntityLand($this);
	}

	public function getEyeHeight() : float{
		return $this->size->getEyeHeight();
	}

	public function getEyePos() : Vector3{
		return new Vector3($this->location->x, $this->location->y + $this->getEyeHeight(), $this->location->z);
	}

	public function onCollideWithPlayer(Player $player) : void{

	}

	/**
	 * Called when interacted or tapped by a Player. Returns whether something happened as a result of the interaction.
	 */
	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		return false;
	}

	public function isUnderwater() : bool{
		//checked every tick for every living entity: most eyes are in air, which is ruled out by the block state ID
		$world = $this->getWorld();
		$eyeX = (int) floor($this->location->x);
		$eyeY = (int) floor($this->location->y + $this->getEyeHeight());
		$eyeZ = (int) floor($this->location->z);
		return $this->isUnderwaterAtEye($world, $eyeX, $eyeY, $eyeZ, self::blockFlagsAt($world, $eyeX, $eyeY, $eyeZ));
	}

	/**
	 * @internal
	 * The check of {@link Entity::isUnderwater()} for the block at the eyes, at the coordinates it works out, whose
	 * BLOCK_FLAG_* were just read with blockFlagsAt(): for the base tick of living entities, which checks the same
	 * block for suffocation (see {@link Entity::isInsideOfSolidAtEye()}).
	 */
	protected function isUnderwaterAtEye(World $world, int $eyeX, int $eyeY, int $eyeZ, int $flags) : bool{
		if(($flags & self::BLOCK_FLAG_WATER) === 0){
			//water held in the liquid layer (waterlogged stairs, slabs...) fills the whole block, like a source
			return ($flags & self::BLOCK_FLAG_WATERLOGGABLE) !== 0 && LiquidLayer::hasLayerWaterAt($world, $eyeX, $eyeY, $eyeZ);
		}
		$block = $world->getBlockAt($eyeX, $blockY = $eyeY, $eyeZ);
		$y = $this->location->y + $this->getEyeHeight();

		if($block instanceof Water){
			$f = ($blockY + 1) - ($block->getFluidHeightPercent() - 0.1111111);
			return $y < $f;
		}

		//kelp, seagrass and other blocks holding a water source fill their whole space with water
		return $block instanceof Waterlogged && $block->isWaterlogged();
	}

	public function isInsideOfSolid() : bool{
		$world = $this->getWorld();
		$eyeX = (int) floor($this->location->x);
		$eyeY = (int) floor($this->location->y + $this->getEyeHeight());
		$eyeZ = (int) floor($this->location->z);
		return $this->isInsideOfSolidAtEye($world, $eyeX, $eyeY, $eyeZ, self::blockFlagsAt($world, $eyeX, $eyeY, $eyeZ));
	}

	/**
	 * @internal
	 * The check of {@link Entity::isInsideOfSolid()} for the block at the eyes, at the coordinates it works out, whose
	 * BLOCK_FLAG_* were just read with blockFlagsAt() (see {@link Entity::isUnderwaterAtEye()}).
	 */
	protected function isInsideOfSolidAtEye(World $world, int $eyeX, int $eyeY, int $eyeZ, int $flags) : bool{
		if(($flags & self::BLOCK_FLAG_SOLID_OPAQUE) === 0){
			return false; //checked every tick for every living entity
		}
		$block = $world->getBlockAt($eyeX, $eyeY, $eyeZ);

		return $block->isSolid() && !$block->isTransparent() && $block->collidesWithBB($this->getBoundingBox());
	}

	protected function move(float $dx, float $dy, float $dz) : void{
		$this->blocksAround = null;

		//see onUpdate(): one check instead of eight calls per movement
		$timed = TimingsHandler::isEnabled();
		if($timed){
			Timings::$entityMove->startTiming();
			Timings::$entityMoveCollision->startTiming();
		}

		$wantedX = $dx;
		$wantedY = $dy;
		$wantedZ = $dz;

		if($this->keepMovement){
			$this->boundingBox->offset($dx, $dy, $dz);
		}else{
			$this->ySize *= self::STEP_CLIP_MULTIPLIER;

			$moveBB = clone $this->boundingBox;

			assert(abs($dx) <= 20 && abs($dy) <= 20 && abs($dz) <= 20, "Movement distance is excessive: dx=$dx, dy=$dy, dz=$dz");

			$stepHeight = $this->getStepHeight();
			//the same clipping in C++ (chunkutils2 >= 0.3.12), with the same results
			$clipped = World::$nativePhysics !== null ? $this->getWorld()->clipEntityMovement($this->boundingBox, $dx, $dy, $dz, $this->onGround, $stepHeight) : null;
			if($clipped !== null){
				[, $dx, $dy, $dz, $stepped, $moveBB->minX, $moveBB->minY, $moveBB->minZ, $moveBB->maxX, $moveBB->maxY, $moveBB->maxZ] = $clipped;
				if($stepped){
					$this->ySize += $dy;
				}
			}else{
				$list = $this->getWorld()->getBlockCollisionBoxes($moveBB->addCoord($dx, $dy, $dz));

				//every moving entity runs this on every tick: the boxes run through calculate[XYZ]Offset() of their own
				//(see clipOffsetY())
				$dy = self::clipOffsetY($list, $moveBB, $dy);

				$moveBB->offset(0, $dy, 0);

				$fallingFlag = ($this->onGround || ($dy !== $wantedY && $wantedY < 0));

				$dx = self::clipOffsetX($list, $moveBB, $dx);

				$moveBB->offset($dx, 0, 0);

				$dz = self::clipOffsetZ($list, $moveBB, $dz);

				$moveBB->offset(0, 0, $dz);

				if($stepHeight > 0 && $fallingFlag && ($wantedX !== $dx || $wantedZ !== $dz)){
					$cx = $dx;
					$cy = $dy;
					$cz = $dz;
					$dx = $wantedX;
					$dy = $stepHeight;
					$dz = $wantedZ;

					$stepBB = clone $this->boundingBox;

					$list = $this->getWorld()->getBlockCollisionBoxes($stepBB->addCoord($dx, $dy, $dz));
					$dy = self::clipOffsetY($list, $stepBB, $dy);

					$stepBB->offset(0, $dy, 0);

					$dx = self::clipOffsetX($list, $stepBB, $dx);

					$stepBB->offset($dx, 0, 0);

					$dz = self::clipOffsetZ($list, $stepBB, $dz);

					$stepBB->offset(0, 0, $dz);

					$reverseDY = self::clipOffsetY($list, $stepBB, -$dy);
					$dy += $reverseDY;
					$stepBB->offset(0, $reverseDY, 0);

					if(($cx ** 2 + $cz ** 2) >= ($dx ** 2 + $dz ** 2)){
						$dx = $cx;
						$dy = $cy;
						$dz = $cz;
					}else{
						$moveBB = $stepBB;
						$this->ySize += $dy;
					}
				}
			}

			$this->boundingBox = $moveBB;
		}
		if($timed){
			Timings::$entityMoveCollision->stopTiming();
		}

		$this->location = new Location(
			($this->boundingBox->minX + $this->boundingBox->maxX) / 2,
			$this->boundingBox->minY - $this->ySize,
			($this->boundingBox->minZ + $this->boundingBox->maxZ) / 2,
			$this->location->world,
			$this->location->yaw,
			$this->location->pitch
		);

		if($timed){
			Timings::$entityMoveChunkTracking->startTiming();
		}
		$this->getWorld()->onEntityMoved($this);
		if($timed){
			Timings::$entityMoveChunkTracking->stopTiming();
			Timings::$entityMoveBlockIntersections->startTiming();
		}
		$this->checkBlockIntersections();
		if($timed){
			Timings::$entityMoveBlockIntersections->stopTiming();
		}
		$this->checkGroundState($wantedX, $wantedY, $wantedZ, $dx, $dy, $dz);
		$postFallVerticalVelocity = $this->updateFallState($dy, $this->onGround);

		$this->motion = $this->motion->withComponents(
			$wantedX !== $dx ? 0 : null,
			$postFallVerticalVelocity ?? ($wantedY !== $dy ? 0 : null),
			$wantedZ !== $dz ? 0 : null
		);

		//TODO: vehicle collision events (first we need to spawn them!)

		if($timed){
			Timings::$entityMove->stopTiming();
		}
	}

	/**
	 * Returns $dy after $bb->calculateYOffset($moveBB, $dy) for each box of the list in turn, without a method call per
	 * box (AxisAlignedBB is final): the same comparisons and subtractions in the same form, $moveBB read once (it doesn't
	 * change here). The skips keep the form of the method's early returns: a negated form ("not <=" written as ">")
	 * would answer differently for NaN coordinates.
	 *
	 * @param AxisAlignedBB[] $list
	 */
	private static function clipOffsetY(array $list, AxisAlignedBB $moveBB, float $dy) : float{
		foreach($list as $bb){
			if($moveBB->maxX <= $bb->minX || $moveBB->minX >= $bb->maxX){
				continue;
			}
			if($moveBB->maxZ <= $bb->minZ || $moveBB->minZ >= $bb->maxZ){
				continue;
			}
			if($dy > 0 && $moveBB->maxY <= $bb->minY){
				$y1 = $bb->minY - $moveBB->maxY;
				if($y1 < $dy){
					$dy = $y1;
				}
			}elseif($dy < 0 && $moveBB->minY >= $bb->maxY){
				$y2 = $bb->maxY - $moveBB->minY;
				if($y2 > $dy){
					$dy = $y2;
				}
			}
		}
		return $dy;
	}

	/**
	 * Same as {@link Entity::clipOffsetY()} with calculateXOffset().
	 *
	 * @param AxisAlignedBB[] $list
	 */
	private static function clipOffsetX(array $list, AxisAlignedBB $moveBB, float $dx) : float{
		foreach($list as $bb){
			if($moveBB->maxY <= $bb->minY || $moveBB->minY >= $bb->maxY){
				continue;
			}
			if($moveBB->maxZ <= $bb->minZ || $moveBB->minZ >= $bb->maxZ){
				continue;
			}
			if($dx > 0 && $moveBB->maxX <= $bb->minX){
				$x1 = $bb->minX - $moveBB->maxX;
				if($x1 < $dx){
					$dx = $x1;
				}
			}elseif($dx < 0 && $moveBB->minX >= $bb->maxX){
				$x2 = $bb->maxX - $moveBB->minX;
				if($x2 > $dx){
					$dx = $x2;
				}
			}
		}
		return $dx;
	}

	/**
	 * Same as {@link Entity::clipOffsetY()} with calculateZOffset().
	 *
	 * @param AxisAlignedBB[] $list
	 */
	private static function clipOffsetZ(array $list, AxisAlignedBB $moveBB, float $dz) : float{
		foreach($list as $bb){
			if($moveBB->maxX <= $bb->minX || $moveBB->minX >= $bb->maxX){
				continue;
			}
			if($moveBB->maxY <= $bb->minY || $moveBB->minY >= $bb->maxY){
				continue;
			}
			if($dz > 0 && $moveBB->maxZ <= $bb->minZ){
				$z1 = $bb->minZ - $moveBB->maxZ;
				if($z1 < $dz){
					$dz = $z1;
				}
			}elseif($dz < 0 && $moveBB->minZ >= $bb->maxZ){
				$z2 = $bb->maxZ - $moveBB->minZ;
				if($z2 > $dz){
					$dz = $z2;
				}
			}
		}
		return $dz;
	}

	public function setStepHeight(float $stepHeight) : void{
		$this->stepHeight = $stepHeight;
	}

	public function getStepHeight() : float{
		return $this->stepHeight;
	}

	protected function checkGroundState(float $wantedX, float $wantedY, float $wantedZ, float $dx, float $dy, float $dz) : void{
		$this->isCollidedVertically = $wantedY !== $dy;
		$this->isCollidedHorizontally = ($wantedX !== $dx || $wantedZ !== $dz);
		$this->isCollided = ($this->isCollidedHorizontally || $this->isCollidedVertically);
		$this->onGround = ($wantedY !== $dy && $wantedY < 0);
	}

	/**
	 * Yields all the blocks whose full-cube areas are intersected by the entity's AABB.
	 *
	 * @phpstan-return \Generator<int, Block, void, void>
	 */
	protected function getBlocksIntersected(float $inset) : \Generator{
		$minX = (int) floor($this->boundingBox->minX + $inset);
		$minY = (int) floor($this->boundingBox->minY + $inset);
		$minZ = (int) floor($this->boundingBox->minZ + $inset);
		$maxX = (int) floor($this->boundingBox->maxX - $inset);
		$maxY = (int) floor($this->boundingBox->maxY - $inset);
		$maxZ = (int) floor($this->boundingBox->maxZ - $inset);

		$world = $this->getWorld();

		for($z = $minZ; $z <= $maxZ; ++$z){
			for($x = $minX; $x <= $maxX; ++$x){
				for($y = $minY; $y <= $maxY; ++$y){
					yield $world->getBlockAt($x, $y, $z);
				}
			}
		}
	}

	/**
	 * @return Block[]
	 */
	protected function getBlocksAroundWithEntityInsideActions() : array{
		if($this->blocksAround === null){
			$this->blocksAround = [];
			$this->liquidTopsFound = false;
			$this->waterSurfaceY = $this->lavaSurfaceY = null;

			$inset = 0.001; //Offset against floating-point errors
			$minX = (int) floor($this->boundingBox->minX + $inset);
			$minY = (int) floor($this->boundingBox->minY + $inset);
			$minZ = (int) floor($this->boundingBox->minZ + $inset);
			$maxX = (int) floor($this->boundingBox->maxX - $inset);
			$maxY = (int) floor($this->boundingBox->maxY - $inset);
			$maxZ = (int) floor($this->boundingBox->maxZ - $inset);

			//this runs after every movement: most blocks are air or stone, so the block state ID is checked first and
			//a Block is only created for states which can have entity collision (same blocks, same order as
			//getBlocksIntersected())
			$world = $this->getWorld();
			//the same scan in C++ when chunkutils2 can (0.3.12): only the blocks whose state has flags come back
			if(($native = World::$nativePhysics) !== null){
				do{
					$found = $native->entityInsideBlocks($world, self::$entityInsideFlagsByStateId, $minX, $minY, $minZ, $maxX, $maxY, $maxZ);
					if($found === null || ($found[0] ?? 0) !== 1){
						break;
					}
					//a state without flags yet
					self::$entityInsideFlagsByStateId[$found[1]] = self::computeEntityInsideFlags($found[1]);
				}while(true);
				if($found !== null){
					$blocks = [];
					for($i = 1, $count = count($found); $i < $count; $i += 4){
						self::addEntityInsideBlock($world, $found[$i], $found[$i + 1], $found[$i + 2], $found[$i + 3], $blocks);
					}
					return $this->blocksAround = $blocks;
				}
			}
			//World::isInWorld() once for the whole box instead of for each block: the Y range is clamped to the world's,
			//X and Z are checked once per column
			$worldMinY = $world->getMinY();
			$worldMaxY = $world->getMaxY() - 1;
			$fromY = $minY > $worldMinY ? $minY : $worldMinY;
			$toY = $maxY < $worldMaxY ? $maxY : $worldMaxY;
			//the chunk is looked up again only when the column is in another chunk, and the blocks are read from their
			//subchunk (what Chunk::getBlockStateId() does), looked up again only when the column or the subchunk changes:
			//nothing below loads, unloads or replaces chunks or subchunks (World::getBlockAt() and the block checks only
			//read them), so these are the same objects the lookups for each block would find
			$chunk = null;
			$chunkX = $chunkZ = 0;
			$chunkFound = false;
			for($z = $minZ; $z <= $maxZ; ++$z){
				for($x = $minX; $x <= $maxX; ++$x){
					if(!$chunkFound || $x >> Chunk::COORD_BIT_SIZE !== $chunkX || $z >> Chunk::COORD_BIT_SIZE !== $chunkZ){
						$chunkX = $x >> Chunk::COORD_BIT_SIZE;
						$chunkZ = $z >> Chunk::COORD_BIT_SIZE;
						$chunk = $world->getChunk($chunkX, $chunkZ);
						$chunkFound = true;
					}
					if($chunk === null){
						continue; //World::getBlockAt() gives air there
					}
					if($x > Limits::INT32_MAX || $x < Limits::INT32_MIN || $z > Limits::INT32_MAX || $z < Limits::INT32_MIN){
						continue;
					}
					$localX = $x & SubChunk::COORD_MASK;
					$localZ = $z & SubChunk::COORD_MASK;
					$subChunk = null;
					$subChunkY = 0;
					for($y = $fromY; $y <= $toY; ++$y){
						if($subChunk === null || $y >> SubChunk::COORD_BIT_SIZE !== $subChunkY){
							$subChunkY = $y >> SubChunk::COORD_BIT_SIZE;
							$subChunk = $chunk->getSubChunk($subChunkY);
						}
						$stateId = $subChunk->getBlockStateId($localX, $y & SubChunk::COORD_MASK, $localZ);
						$flags = self::$entityInsideFlagsByStateId[$stateId] ??= self::computeEntityInsideFlags($stateId);
						if($flags === 0){
							continue;
						}
						if(($flags & self::ENTITY_INSIDE_COLLISION) !== 0){
							$block = $world->getBlockAt($x, $y, $z);
							if($block->hasEntityCollision()){
								$this->blocksAround[] = $block;
							}
						}
						if(
							($flags & self::ENTITY_INSIDE_WATERLOGGABLE) !== 0 &&
							LiquidLayer::isWaterInSubChunk($subChunk, $localX, $y & SubChunk::COORD_MASK, $localZ)
						){
							//the water of a waterlogged block acts on the entity like a water source
							$water = VanillaBlocks::WATER();
							$water->position($world, $x, $y, $z);
							$this->blocksAround[] = $water;
						}elseif(($flags & self::ENTITY_INSIDE_WATER_STATE) !== 0){
							//kelp, seagrass...: the water they hold acts on the entity like a water source too
							$water = VanillaBlocks::WATER();
							$water->position($world, $x, $y, $z);
							$this->blocksAround[] = $water;
						}
					}
				}
			}
		}

		return $this->blocksAround;
	}

	/**
	 * The body of the scan of getBlocksAroundWithEntityInsideActions() for a block whose state has the given
	 * ENTITY_INSIDE_* flags (not 0), found by the native scan in a loaded chunk inside the world: adds what it finds to
	 * $blocks.
	 *
	 * @param Block[] $blocks
	 * @phpstan-param list<Block> $blocks
	 */
	private static function addEntityInsideBlock(World $world, int $x, int $y, int $z, int $flags, array &$blocks) : void{
		if(($flags & self::ENTITY_INSIDE_COLLISION) !== 0){
			$block = $world->getBlockAt($x, $y, $z);
			if($block->hasEntityCollision()){
				$blocks[] = $block;
			}
		}
		//the subchunk the PHP scan holds there (the chunk is loaded: the native scan found the block in it)
		$subChunk = ($flags & self::ENTITY_INSIDE_WATERLOGGABLE) !== 0 ? $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)?->getSubChunk($y >> SubChunk::COORD_BIT_SIZE) : null;
		if(
			$subChunk !== null &&
			LiquidLayer::isWaterInSubChunk($subChunk, $x & SubChunk::COORD_MASK, $y & SubChunk::COORD_MASK, $z & SubChunk::COORD_MASK)
		){
			//the water of a waterlogged block acts on the entity like a water source
			$water = VanillaBlocks::WATER();
			$water->position($world, $x, $y, $z);
			$blocks[] = $water;
		}elseif(($flags & self::ENTITY_INSIDE_WATER_STATE) !== 0){
			//kelp, seagrass...: the water they hold acts on the entity like a water source too
			$water = VanillaBlocks::WATER();
			$water->position($world, $x, $y, $z);
			$blocks[] = $water;
		}
	}

	/**
	 * Returns whether this entity can be moved by currents in liquids.
	 */
	public function canBeMovedByCurrents() : bool{
		return true;
	}

	/**
	 * Returns how much motion the current of flowing water adds to this entity per tick.
	 */
	protected function getWaterCurrentPush() : float{
		return self::WATER_CURRENT_PUSH;
	}

	protected function checkBlockIntersections() : void{
		$this->checkBlockIntersectionsNextTick = false;
		if($this->onGround){
			$this->stepOnBlockBelow();
		}
		$blocks = $this->getBlocksAroundWithEntityInsideActions();
		if(count($blocks) === 0){
			//the usual case (only air and solid blocks around): no liquid, no current
			if($this->waterTopBlock !== null || $this->lavaTopBlock !== null){
				$this->waterTopY = $this->lavaTopY = self::NO_LIQUID_TOP;
				$this->waterTopBlock = $this->lavaTopBlock = null;
			}
			return;
		}

		$vectors = [];
		$lavaVectors = [];
		//the liquid surfaces only change with the blocks around the entity: worked out again once they were looked for
		$findTops = !$this->liquidTopsFound;
		$waterTopY = $lavaTopY = self::NO_LIQUID_TOP;
		$waterTopBlock = $lavaTopBlock = null;
		foreach($blocks as $block){
			if(!$block->onEntityInside($this)){
				$this->blocksAround = null;
			}
			$lava = $block instanceof Lava;
			if($findTops && $block instanceof Liquid){
				//surface of the liquid in this block (vanilla FlowingFluid.getOwnHeight(): amount / 9, falling liquid is
				//full); more liquid above is only looked for when it matters (see getBoundingBoxLiquidDepth())
				$top = $block->getPosition()->y + ($block->isFalling() ? 1.0 : (8 - $block->getDecay()) / 9);
				if($lava){
					if($top > $lavaTopY){
						$lavaTopY = $top;
						$lavaTopBlock = $block;
					}
				}elseif($top > $waterTopY){
					$waterTopY = $top;
					$waterTopBlock = $block;
				}
			}
			if(($v = $block->addVelocityToEntity($this)) !== null){
				if($lava){
					$lavaVectors[] = $v;
				}else{
					$vectors[] = $v;
				}
			}
		}
		if($findTops){
			$this->waterTopY = $waterTopY;
			$this->waterTopBlock = $waterTopBlock;
			$this->lavaTopY = $lavaTopY;
			$this->lavaTopBlock = $lavaTopBlock;
			$this->liquidTopsFound = true;
		}

		//vanilla Entity.updateFluidHeightAndDoFluidPushing(): each liquid pushes with its own strength
		if(count($vectors) > 0){
			$vector = Vector3::sum(...$vectors);
			if($vector->lengthSquared() > 0){
				$this->motion = $this->motion->addVector($vector->normalize()->multiply($this->getWaterCurrentPush()));
			}
		}
		if(count($lavaVectors) > 0){
			$vector = Vector3::sum(...$lavaVectors);
			if($vector->lengthSquared() > 0){
				$push = $this->getWorld()->getDimension()->isUltraWarm() ? self::LAVA_CURRENT_PUSH_ULTRA_WARM : self::LAVA_CURRENT_PUSH;
				$this->motion = $this->motion->addVector($vector->normalize()->multiply($push));
			}
		}
	}

	/** How strongly the current of flowing water pushes entities, per tick (vanilla Entity.updateInWaterStateAndDoWaterCurrentPushing()). */
	private const WATER_CURRENT_PUSH = 0.014;
	/** How strongly flowing lava pushes entities, per tick (vanilla Entity.updateFluidHeightAndDoFluidPushing()). */
	private const LAVA_CURRENT_PUSH = 0.0023333333333333335;
	/** Lava in ultra-warm dimensions (the Nether) flows faster and pushes harder. */
	private const LAVA_CURRENT_PUSH_ULTRA_WARM = 0.007;
	/** See getBoundingBoxLiquidDepth(). */
	private const LIQUID_DEPTH_EXACT_BELOW = 0.25;

	/**
	 * Returns how deep the bottom of the entity's bounding box is in water, or in lava (vanilla Entity.getFluidHeight()),
	 * as found among the blocks around the entity by the last block intersection check: 0 when it isn't in that liquid.
	 * Depths from LIQUID_DEPTH_EXACT_BELOW on may be underestimated (the liquid above the blocks around the entity
	 * isn't looked for then): callers compare with small depths.
	 *
	 * @internal
	 */
	final protected function getBoundingBoxLiquidDepth(bool $lava = false) : float{
		$minY = $this->boundingBox->minY;
		if($lava){
			if(($topBlock = $this->lavaTopBlock) === null){
				return 0.0;
			}
			$depth = $this->lavaTopY - $minY;
			if($depth < self::LIQUID_DEPTH_EXACT_BELOW){
				$depth = ($this->lavaSurfaceY ??= $this->findLiquidSurface($topBlock, $this->lavaTopY, true)) - $minY;
			}
		}else{
			if(($topBlock = $this->waterTopBlock) === null){
				return 0.0;
			}
			$depth = $this->waterTopY - $minY;
			if($depth < self::LIQUID_DEPTH_EXACT_BELOW){
				$depth = ($this->waterSurfaceY ??= $this->findLiquidSurface($topBlock, $this->waterTopY, false)) - $minY;
			}
		}
		return $depth > 0.0 ? $depth : 0.0;
	}

	/**
	 * Returns the surface of the liquid whose highest block around the entity is given: the top of that block when the
	 * same liquid fills the block above (a deep pool, a falling column), else the surface found in that block. One block
	 * lookup, only near the surface of the liquid blocks around the entity, kept until the blocks around the entity are
	 * looked for again (after a movement or a block change nearby).
	 */
	private function findLiquidSurface(Liquid $topBlock, float $topY, bool $lava) : float{
		$pos = $topBlock->getPosition();
		$x = (int) $pos->x;
		$y = (int) $pos->y + 1;
		$z = (int) $pos->z;
		$world = $this->getWorld();
		return ($lava ? $world->getBlockAt($x, $y, $z) instanceof Lava : LiquidLayer::hasWaterAt($world, $x, $y, $z)) ? (float) $y : $topY;
	}

	/**
	 * Lets the block under the feet act on the entity standing on it (vanilla Entity.move() calling Block.stepOn() on
	 * the block 0.2 below the position): runs once per tick for entities on the ground, and only builds the block when
	 * its state is a {@link SteppedOnBlock}.
	 */
	private function stepOnBlockBelow() : void{
		$location = $this->location;
		if(
			$location->x === $this->noStepOnBlockFromX && $location->y === $this->noStepOnBlockFromY && $location->z === $this->noStepOnBlockFromZ &&
			$this->ticksLived < $this->noStepOnBlockUntil
		){
			return; //standing exactly where the ordinary block below was found (the coordinates below are the same)
		}
		$x = (int) floor($location->x);
		$y = (int) floor($location->y - 0.2);
		$z = (int) floor($location->z);
		if($y === $this->noStepOnBlockY && $x === $this->noStepOnBlockX && $z === $this->noStepOnBlockZ && $this->ticksLived < $this->noStepOnBlockUntil){
			//still on the same ordinary block (most entities stand still most of the time)
			$this->noStepOnBlockFromX = $location->x;
			$this->noStepOnBlockFromY = $location->y;
			$this->noStepOnBlockFromZ = $location->z;
			return;
		}
		$world = $this->getWorld();
		if((self::blockFlagsAt($world, $x, $y, $z) & self::BLOCK_FLAG_STEPPED_ON) === 0){
			$this->noStepOnBlockX = $x;
			$this->noStepOnBlockY = $y;
			$this->noStepOnBlockZ = $z;
			$this->noStepOnBlockUntil = $this->ticksLived + self::STEP_ON_RECHECK_TICKS;
			$this->noStepOnBlockFromX = $location->x;
			$this->noStepOnBlockFromY = $location->y;
			$this->noStepOnBlockFromZ = $location->z;
			return;
		}
		$block = $world->getBlockAt($x, $y, $z);
		if($block instanceof SteppedOnBlock){
			$block->onEntityStepOn($this);
		}
	}

	public function getPosition() : Position{
		return $this->location->asPosition();
	}

	public function getLocation() : Location{
		return $this->location->asLocation();
	}

	public function getWorld() : World{
		//Position::getWorld() without its call layer (asked many times per tick by every entity): the location is always
		//a Location built by Entity (Location::fromObject(), asLocation() or new Location), which keeps that method
		$world = $this->location->world;
		if($world === null || !$world->isLoaded()){
			throw new AssumptionFailedError("Position world is null or has been unloaded");
		}
		return $world;
	}

	protected function setPosition(Vector3 $pos) : bool{
		if($this->closed){
			return false;
		}

		$oldWorld = $this->getWorld();
		$newWorld = $pos instanceof Position ? $pos->getWorld() : $oldWorld;
		if($oldWorld !== $newWorld){
			//links don't cross worlds
			$this->leaveVehicle(false, false);
			$this->ejectPassengers(false);
			$this->despawnFromAll();
			$oldWorld->removeEntity($this);
		}

		$this->location = Location::fromObject(
			$pos,
			$newWorld,
			$this->location->yaw,
			$this->location->pitch
		);

		$this->recalculateBoundingBox();

		$this->blocksAround = null;
		$this->noStepOnBlockY = World::Y_MIN - 1;
		$this->noStepOnBlockFromX = NAN;

		if($oldWorld !== $newWorld){
			$newWorld->addEntity($this);
		}else{
			$newWorld->onEntityMoved($this);
		}

		return true;
	}

	public function setRotation(float $yaw, float $pitch) : void{
		Utils::checkFloatNotInfOrNaN("yaw", $yaw);
		Utils::checkFloatNotInfOrNaN("pitch", $pitch);
		$this->location->yaw = $yaw;
		$this->location->pitch = $pitch;
		$this->scheduleUpdate();
	}

	protected function setPositionAndRotation(Vector3 $pos, float $yaw, float $pitch) : bool{
		if($this->setPosition($pos)){
			$this->setRotation($yaw, $pitch);

			return true;
		}

		return false;
	}

	protected function resetLastMovements() : void{
		$this->lastLocation = $this->location->asLocation();
		$this->lastMotion = clone $this->motion;
	}

	public function getMotion() : Vector3{
		return clone $this->motion;
	}

	public function setMotion(Vector3 $motion) : bool{
		Utils::checkVector3NotInfOrNaN($motion);
		if(!$this->justCreated){
			$ev = new EntityMotionEvent($this, $motion);
			$ev->call();
			if($ev->isCancelled()){
				return false;
			}
		}

		$this->motion = clone $motion;

		if(!$this->justCreated){
			$this->updateMovement();
		}

		return true;
	}

	/**
	 * Adds the given values to the entity's motion vector.
	 */
	public function addMotion(float $x, float $y, float $z) : void{
		Utils::checkFloatNotInfOrNaN("x", $x);
		Utils::checkFloatNotInfOrNaN("y", $y);
		Utils::checkFloatNotInfOrNaN("z", $z);
		$this->motion = $this->motion->add($x, $y, $z);
	}

	public function isOnGround() : bool{
		return $this->onGround;
	}

	/**
	 * @param Vector3|Position|Location $pos
	 */
	public function teleport(Vector3 $pos, ?float $yaw = null, ?float $pitch = null) : bool{
		if($pos instanceof Location){
			$yaw = $yaw ?? $pos->yaw;
			$pitch = $pitch ?? $pos->pitch;
		}
		//destinations often come from user input (e.g. command arguments parsed by plugins, where a long digit string
		//becomes INF): refuse NaN/infinite values and X/Z beyond the world's block range instead of throwing or
		//corrupting chunk lookups (chunk hashes and region keys only keep 32 bits of the chunk coordinates)
		if(
			!is_finite($pos->x) || !is_finite($pos->y) || !is_finite($pos->z) ||
			$pos->x > Limits::INT32_MAX || $pos->x < Limits::INT32_MIN || $pos->z > Limits::INT32_MAX || $pos->z < Limits::INT32_MIN ||
			($yaw !== null && !is_finite($yaw)) || ($pitch !== null && !is_finite($pitch))
		){
			\GlobalLogger::get()->debug("Refused to teleport " . (new \ReflectionClass($this))->getShortName() . " " . $this->id . " to invalid destination (" . $pos->x . ", " . $pos->y . ", " . $pos->z . ", yaw " . ($yaw ?? "-") . ", pitch " . ($pitch ?? "-") . ")");
			return false;
		}

		$from = $this->location->asPosition();
		$to = Position::fromObject($pos, $pos instanceof Position ? $pos->getWorld() : $this->getWorld());
		$ev = new EntityTeleportEvent($this, $from, $to);
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}
		$this->ySize = 0;
		$pos = $ev->getTo();

		//teleporting ends rides (vanilla): the rider is taken off its vehicle, a vehicle leaves its passengers behind
		$this->leaveVehicle(false, false);
		$this->ejectPassengers(false);

		$this->setMotion(new Vector3(0, 0, 0));
		if($this->setPositionAndRotation($pos, $yaw ?? $this->location->yaw, $pitch ?? $this->location->pitch)){
			$this->resetFallDistance();
			$this->setForceMovementUpdate();

			$this->updateMovement(true);

			return true;
		}

		return false;
	}

	/**
	 * Returns the persistent ID of this entity (vanilla "UniqueID"), assigning a random one the first time. It is only
	 * saved once assigned, which happens to the vehicles and passengers of saved links (see {@link Entity::mount()}).
	 */
	public function getPersistentUniqueId() : int{
		return $this->uniqueId ??= random_int(1, PHP_INT_MAX);
	}

	/**
	 * Returns the persistent ID of this entity if it has one, without assigning it (see
	 * {@link Entity::getPersistentUniqueId()}).
	 * @internal
	 */
	public function getPersistentUniqueIdIfAssigned() : ?int{
		return $this->uniqueId;
	}

	/**
	 * Returns the entity this entity is riding, if any.
	 */
	public function getVehicle() : ?Entity{
		return $this->vehicle;
	}

	public function isRiding() : bool{
		return $this->vehicle !== null;
	}

	/**
	 * Returns the entities riding this one, by seat (the first one is the rider).
	 *
	 * @return Entity[]
	 * @phpstan-return list<Entity>
	 */
	public function getPassengers() : array{
		return $this->passengers;
	}

	public function hasPassenger(Entity $entity) : bool{
		return $entity->vehicle === $this;
	}

	/**
	 * Returns the passenger on the first seat, which controls the vehicle if it can, or null.
	 */
	public function getControllingPassenger() : ?Entity{
		return $this->passengers[0] ?? null;
	}

	/**
	 * Returns the seats of this entity in its current state (vanilla minecraft:rideable component), by seat order. An
	 * entity with no seats can't be ridden.
	 *
	 * @return RideableSeat[]
	 * @phpstan-return list<RideableSeat>
	 */
	public function getRideableSeats() : array{
		return [];
	}

	/**
	 * Returns whether the given entity may ride this one (the vanilla "family_types" of the rideable component, a
	 * saddle...). Free seats are checked by {@link Entity::canAddPassenger()}.
	 */
	public function canBeRiddenBy(Entity $entity) : bool{
		return false;
	}

	/**
	 * Returns whether the given passenger of this entity can breathe underwater while riding it (vanilla
	 * underwater_mount_breathing, e.g. a saddled nautilus in water).
	 */
	public function givesUnderwaterBreathingTo(Entity $passenger) : bool{
		return false;
	}

	/**
	 * Returns whether the given entity may get on this one now: it is allowed to ride it and a seat is free.
	 */
	public function canAddPassenger(Entity $entity) : bool{
		return count($this->passengers) < count($this->getRideableSeats()) && $this->canBeRiddenBy($entity);
	}

	/**
	 * Returns the seat of a passenger of this entity, or null if it isn't one.
	 */
	public function getSeatOf(Entity $passenger) : ?RideableSeat{
		$index = array_search($passenger, $this->passengers, true);
		if($index === false){
			return null;
		}
		$seats = $this->getRideableSeats();
		return $seats[$index] ?? $seats[count($seats) - 1] ?? null;
	}

	/**
	 * Returns the position of a passenger relative to this entity, as sent to clients (RIDER_SEAT_POSITION): its seat
	 * (before rotating by the vehicle's yaw) raised by the passenger's {@link Entity::getSeatedHeightOffset()}.
	 */
	public function getPassengerSeatOffset(Entity $passenger) : Vector3{
		$seat = $this->getSeatOf($passenger);
		$position = $seat !== null ? $seat->position : new Vector3(0, $this->size->getHeight(), 0);
		return $position->add(0, $passenger->getSeatedHeightOffset(), 0);
	}

	/**
	 * Returns the height of this entity's network position above its seat when riding something: mobs have their feet
	 * a quarter of their height below the seat (players override this).
	 */
	public function getSeatedHeightOffset() : float{
		return -$this->size->getHeight() * self::SEATED_FEET_FACTOR;
	}

	/**
	 * Returns where a passenger of this entity is in the world (its feet): its seat offset rotated by this entity's
	 * yaw.
	 */
	public function getPassengerPosition(Entity $passenger) : Vector3{
		$offset = $this->getPassengerSeatOffset($passenger);
		$yaw = deg2rad($this->location->yaw);
		$cos = cos($yaw);
		$sin = sin($yaw);
		//seat x is to the vehicle's left, z forwards; yaw 0 faces +Z, whose left is +X
		return new Vector3(
			$this->location->x + $offset->x * $cos - $offset->z * $sin,
			$this->location->y + $offset->y - $passenger->getOffsetPosition(Vector3::zero())->y,
			$this->location->z + $offset->x * $sin + $offset->z * $cos
		);
	}

	/**
	 * Moves the passengers onto their seats. Called after this entity moved, every tick.
	 */
	protected function positionPassengers() : void{
		foreach($this->passengers as $passenger){
			if(!$passenger->closed){
				$passenger->setPosition($this->getPassengerPosition($passenger));
			}
		}
	}

	/**
	 * Returns where a passenger is put when it gets off: the first free spot with ground beside this entity (left,
	 * right, back, front), or on top of it.
	 */
	public function getDismountPosition(Entity $passenger) : Vector3{
		$world = $this->getWorld();
		$loc = $this->location;
		$halfWidth = $passenger->size->getWidth() / 2;
		$height = $passenger->size->getHeight();
		$distance = $this->size->getWidth() / 2 + $halfWidth + 0.1;
		$yaw = deg2rad($loc->yaw);
		$cos = cos($yaw);
		$sin = sin($yaw);
		$fallback = null;
		foreach([[$cos, $sin], [-$cos, -$sin], [$sin, -$cos], [-$sin, $cos]] as [$dx, $dz]){
			$x = $loc->x + $dx * $distance;
			$z = $loc->z + $dz * $distance;
			foreach([0.0, 1.0, -1.0] as $dy){
				$y = floor($loc->y) + $dy;
				$bb = new AxisAlignedBB($x - $halfWidth, $y, $z - $halfWidth, $x + $halfWidth, $y + $height, $z + $halfWidth);
				if(count($world->getCollisionBlocks($bb, true)) !== 0){
					continue;
				}
				if(count($world->getCollisionBlocks(new AxisAlignedBB($x - $halfWidth, $y - 0.5, $z - $halfWidth, $x + $halfWidth, $y - 0.01, $z + $halfWidth), true)) !== 0){
					return new Vector3($x, $y, $z);
				}
				$fallback ??= new Vector3($x, $y, $z);
			}
		}
		return $fallback ?? new Vector3($loc->x, $loc->y + $this->size->getHeight(), $loc->z);
	}

	/**
	 * Makes this entity ride the given vehicle, on its first free seat. Fails if the vehicle has no free seat this
	 * entity may take, or if the {@link EntityMountEvent} is cancelled. An entity riding something else gets off it
	 * first.
	 *
	 * @param bool $causedByRider whether the rider asked for it (e.g. a player using the vehicle)
	 */
	public function mount(Entity $vehicle, bool $causedByRider = false) : bool{
		if($this->vehicle === $vehicle){
			return true;
		}
		if(!$this->canMount($vehicle) || !$vehicle->canAddPassenger($this)){
			return false;
		}
		$ev = new EntityMountEvent($this, $vehicle);
		$ev->call();
		if($ev->isCancelled() || ($this->vehicle !== null && !$this->dismount(false, $causedByRider))){
			return false;
		}
		if(!$this->canMount($vehicle) || !$vehicle->canAddPassenger($this)){
			return false; //something changed during the events
		}
		$this->addToVehicle($vehicle, $causedByRider);
		$this->getWorld()->addGameEvent($vehicle->location, GameEvent::ENTITY_MOUNT, $this);
		return true;
	}

	private function canMount(Entity $vehicle) : bool{
		if($vehicle === $this || $this->closed || $vehicle->closed || !$this->isAlive() || !$vehicle->isAlive() || $vehicle->getWorld() !== $this->getWorld()){
			return false;
		}
		for($v = $vehicle; $v !== null; $v = $v->vehicle){
			if($v === $this){
				return false; //no loops
			}
		}
		return true;
	}

	private function addToVehicle(Entity $vehicle, bool $causedByRider) : void{
		$this->vehicle = $vehicle;
		$vehicle->passengers[] = $this;
		if(!$this instanceof Player && !$vehicle instanceof Player){
			//saved links refer to UniqueIDs, which must exist before either entity is saved
			$this->getPersistentUniqueId();
			$vehicle->getPersistentUniqueId();
		}
		$this->motion = Vector3::zero();
		$this->resetFallDistance();
		$this->setPosition($vehicle->getPassengerPosition($this));

		$this->networkPropertiesDirty = true;
		$vehicle->networkPropertiesDirty = true;
		$this->flushNetworkData();
		$vehicle->flushNetworkData();
		$vehicle->broadcastLink($this, count($vehicle->passengers) - 1, $causedByRider);

		$vehicle->onPassengerAdded($this);
		$this->onMount($vehicle);
	}

	/**
	 * Gets this entity off its vehicle, beside it. Unless forced, the {@link EntityDismountEvent} may refuse it.
	 * Returns whether the entity got off.
	 */
	public function dismount(bool $force = false, bool $causedByRider = false) : bool{
		$vehicle = $this->vehicle;
		if($vehicle === null){
			return false;
		}
		$ev = new EntityDismountEvent($this, $vehicle, $force);
		$ev->call();
		if($ev->isCancelled() && !$force){
			//the client of a player gets off by itself: put it back on its seat
			if($this instanceof Player && $this->isConnected() && ($seat = array_search($this, $vehicle->passengers, true)) !== false){
				$this->getNetworkSession()->sendDataPacket(SetActorLinkPacket::create($vehicle->createLink($this, $seat, true, false)));
			}
			return false;
		}
		if($this->vehicle === $vehicle){
			$this->removeFromVehicle($this->isAlive() && !$this->closeInFlight, $causedByRider);
		}
		return true;
	}

	/**
	 * Gets every passenger off this entity (forced dismounts).
	 */
	public function ejectPassengers(bool $reposition = true) : void{
		foreach($this->passengers as $passenger){
			$passenger->leaveVehicle($reposition && $passenger->isAlive() && !$passenger->closeInFlight, false);
		}
	}

	/**
	 * Forced dismount (teleports, world changes, despawning...): the event is called but can't refuse it.
	 */
	private function leaveVehicle(bool $reposition, bool $causedByRider) : void{
		$vehicle = $this->vehicle;
		if($vehicle === null){
			return;
		}
		(new EntityDismountEvent($this, $vehicle, true))->call();
		if($this->vehicle === $vehicle){
			$this->removeFromVehicle($reposition, $causedByRider);
		}
	}

	private function removeFromVehicle(bool $reposition, bool $causedByRider) : void{
		$vehicle = $this->vehicle;
		if($vehicle === null){
			return;
		}
		$recipients = $vehicle->getLinkViewers($this);
		$index = array_search($this, $vehicle->passengers, true);
		$this->vehicle = null;
		if($index !== false){
			array_splice($vehicle->passengers, $index, 1);
		}

		$this->networkPropertiesDirty = true;
		$vehicle->networkPropertiesDirty = true;
		NetworkBroadcastUtils::broadcastPackets($recipients, [SetActorLinkPacket::create(new EntityLink($vehicle->id, $this->id, EntityLink::TYPE_REMOVE, true, $causedByRider, 0.0))]);
		if(!$this->closeInFlight){
			$this->flushNetworkData();
		}
		if(!$vehicle->closeInFlight){
			$vehicle->flushNetworkData();
			//the passengers behind moved forward a seat
			if($index !== false){
				for($seat = $index, $count = count($vehicle->passengers); $seat < $count; ++$seat){
					$moved = $vehicle->passengers[$seat];
					$moved->networkPropertiesDirty = true;
					$moved->flushNetworkData();
					$vehicle->broadcastLink($moved, $seat, false);
				}
			}
		}

		$vehicle->onPassengerRemoved($this);
		$this->onDismount($vehicle);
		if(!$this->closeInFlight && !$vehicle->closeInFlight && $vehicle->location->isValid()){
			$vehicle->getWorld()->addGameEvent($vehicle->location, GameEvent::ENTITY_DISMOUNT, $this);
		}

		$this->resetFallDistance();
		if($reposition && !$this->closed){
			$pos = $vehicle->getDismountPosition($this);
			if($this instanceof Player){
				$this->teleport($pos);
			}else{
				$this->setPosition($pos);
			}
		}
	}

	/**
	 * Moves a passenger to the first seat (e.g. a player getting in a boat a mob sits in: the player steers it); the
	 * passengers before it move back a seat. Clients get the new links and seats at once.
	 */
	protected function movePassengerToFront(Entity $passenger) : void{
		$index = array_search($passenger, $this->passengers, true);
		if($index === false || $index === 0){
			return;
		}
		array_splice($this->passengers, $index, 1);
		array_unshift($this->passengers, $passenger);
		$this->networkPropertiesDirty = true;
		$this->flushNetworkData();
		foreach($this->passengers as $seat => $moved){
			$moved->networkPropertiesDirty = true;
			$moved->flushNetworkData();
			$this->broadcastLink($moved, $seat, false);
		}
	}

	/**
	 * Sends the seats of all the passengers again, for vehicles whose seats depend on how many passengers they carry
	 * (boats).
	 */
	protected function refreshPassengerSeats() : void{
		foreach($this->passengers as $passenger){
			$passenger->networkPropertiesDirty = true;
			if(!$passenger->closeInFlight){
				$passenger->flushNetworkData();
			}
		}
	}

	/**
	 * Called when an entity got on this one.
	 */
	protected function onPassengerAdded(Entity $passenger) : void{

	}

	/**
	 * Called when a passenger got off this entity.
	 */
	protected function onPassengerRemoved(Entity $passenger) : void{

	}

	/**
	 * Called when this entity got on a vehicle.
	 */
	protected function onMount(Entity $vehicle) : void{

	}

	/**
	 * Called when this entity got off its vehicle.
	 */
	protected function onDismount(Entity $vehicle) : void{

	}

	/**
	 * Returns the text of the interaction button shown to a touch player pointing at this entity (vanilla
	 * interact_text, e.g. "action.interact.ride.horse"), or "" for none.
	 */
	public function getInteractButtonText(Player $player) : string{
		return "";
	}

	/**
	 * Called every tick with the input of the player controlling this vehicle (its rider). Vehicles steered by their
	 * rider override this.
	 */
	public function handleRiderInput(Player $rider, RiderInput $input) : void{

	}

	/**
	 * Called when the client of the rider moved this vehicle itself (client-predicted vehicles like horses), with the
	 * vehicle's new position (feet) and rotation. Returns false if the movement was refused; the vehicle is then sent
	 * back to its position.
	 */
	public function handleClientVehicleMovement(Player $rider, Vector3 $pos, float $yaw, float $pitch) : bool{
		return false;
	}

	/**
	 * Returns the players seeing both this entity and the passenger (a riding player sees itself).
	 *
	 * @return Player[]
	 * @phpstan-return array<int, Player>
	 */
	private function getLinkViewers(Entity $passenger) : array{
		$recipients = [];
		foreach($this->hasSpawned as $id => $player){
			if(($player === $passenger || isset($passenger->hasSpawned[$id])) && $player->isConnected()){
				$recipients[$id] = $player;
			}
		}
		if($this instanceof Player && $this->isConnected() && isset($passenger->hasSpawned[$id = spl_object_id($this)])){
			$recipients[$id] = $this;
		}
		return $recipients;
	}

	private function createLink(Entity $passenger, int $seat, bool $immediate, bool $causedByRider) : EntityLink{
		return new EntityLink($this->id, $passenger->id, $seat === 0 ? EntityLink::TYPE_RIDER : EntityLink::TYPE_PASSENGER, $immediate, $causedByRider, 0.0);
	}

	private function broadcastLink(Entity $passenger, int $seat, bool $causedByRider) : void{
		NetworkBroadcastUtils::broadcastPackets($this->getLinkViewers($passenger), [SetActorLinkPacket::create($this->createLink($passenger, $seat, false, $causedByRider))]);
	}

	/**
	 * Sends the changed network properties now instead of on the next tick.
	 */
	private function flushNetworkData() : void{
		$changed = $this->getDirtyNetworkData();
		if(count($changed) > 0){
			$this->sendData(null, $changed);
			$this->networkProperties->clearDirtyProperties();
		}
	}

	/**
	 * Looks for the passengers listed in the LinksTag of a loaded vehicle (they are saved as separate entities) and puts
	 * them back on their seats.
	 */
	private function restorePassengerLinks(int $tickDiff) : void{
		$first = $this->linkRestoreTicks === self::LINK_RESTORE_TICKS;
		$this->linkRestoreTicks -= $tickDiff;
		if($this->linkRestoreTicks < 0){
			$this->pendingPassengerLinks = [];
			return;
		}
		if(!$first && $this->linkRestoreTicks % 5 !== 0){
			return;
		}
		$found = [];
		foreach($this->getWorld()->getEntities() as $entity){
			if($entity->uniqueId !== null && in_array($entity->uniqueId, $this->pendingPassengerLinks, true)){
				$found[$entity->uniqueId] = $entity;
			}
		}
		$pending = [];
		foreach($this->pendingPassengerLinks as $uniqueId){
			$passenger = $found[$uniqueId] ?? null;
			if($passenger === null){
				$pending[] = $uniqueId;
			}elseif($passenger->vehicle === null && $passenger->canMount($this)){
				$passenger->addToVehicle($this, false);
			}
		}
		$this->pendingPassengerLinks = $pending;
	}

	public function getId() : int{
		return $this->id;
	}

	/**
	 * @return Player[]
	 */
	public function getViewers() : array{
		return $this->hasSpawned;
	}

	abstract public static function getNetworkTypeId() : string;

	/**
	 * Returns the actor property values sent to clients with the entity's metadata (see
	 * {@link \pocketmine\entity\property\EntityPropertyRegistry}). Most entities have none.
	 */
	public function getNetworkSyncedProperties() : PropertySyncData{
		return new PropertySyncData([], []);
	}

	/**
	 * Called by spawnTo() to send whatever packets needed to spawn the entity to the client.
	 */
	protected function sendSpawnPacket(Player $player) : void{
		$player->getNetworkSession()->sendDataPacket(AddActorPacket::create(
			$this->getId(), //TODO: actor unique ID
			$this->getId(),
			static::getNetworkTypeId(),
			$this->getOffsetPosition($this->location->asVector3()),
			$this->getMotion(),
			$this->location->pitch,
			$this->location->yaw,
			$this->getHeadYaw(),
			$this->location->yaw, //TODO: body yaw (wtf mojang?)
			array_map(function(Attribute $attr) : NetworkAttribute{
				return new NetworkAttribute($attr->getId(), $attr->getMinValue(), $attr->getMaxValue(), $attr->getValue(), $attr->getDefaultValue(), []);
			}, $this->attributeMap->getAll()),
			$this->getAllNetworkData(),
			$this->getNetworkSyncedProperties(),
			$this->getSpawnLinks($player)
		));
	}

	/**
	 * Returns the links to this entity's passengers that the given player already sees, sent with the spawn packet.
	 * Passengers spawned later send their own link (see {@link Entity::spawnTo()}).
	 *
	 * @return EntityLink[]
	 * @phpstan-return list<EntityLink>
	 */
	protected function getSpawnLinks(Player $player) : array{
		$links = [];
		foreach($this->passengers as $seat => $passenger){
			if($passenger === $player || isset($passenger->hasSpawned[spl_object_id($player)])){
				$links[] = $this->createLink($passenger, $seat, true, false);
			}
		}
		return $links;
	}

	public function spawnTo(Player $player) : void{
		$id = spl_object_id($player);
		//TODO: this will cause some visible lag during chunk resends; if the player uses a spawn egg in a chunk, the
		//created entity won't be visible until after the resend arrives. However, this is better than possibly crashing
		//the player by sending them entities too early.
		if(!isset($this->hasSpawned[$id]) && $player->getWorld() === $this->getWorld() && $player->hasReceivedChunk($this->location->getFloorX() >> Chunk::COORD_BIT_SIZE, $this->location->getFloorZ() >> Chunk::COORD_BIT_SIZE)){
			$this->hasSpawned[$id] = $player;
			$this->viewerSendIds[$id] = $player->getNetworkSession()->getSendId();

			$this->sendSpawnPacket($player);

			//the vehicle's spawn packet only links passengers which were already spawned
			$vehicle = $this->vehicle;
			if($vehicle !== null && ($vehicle === $player || isset($vehicle->hasSpawned[$id]))){
				$seat = array_search($this, $vehicle->passengers, true);
				if($seat !== false){
					$player->getNetworkSession()->sendDataPacket(SetActorLinkPacket::create($vehicle->createLink($this, $seat, true, false)));
				}
			}
		}
	}

	public function spawnToAll() : void{
		if($this->closed){
			return;
		}
		foreach($this->getWorld()->getViewersForPosition($this->location) as $player){
			$this->spawnTo($player);
		}
	}

	public function respawnToAll() : void{
		foreach($this->hasSpawned as $key => $player){
			unset($this->hasSpawned[$key], $this->viewerSendIds[$key]);
			$this->spawnTo($player);
		}
	}

	/**
	 * @deprecated WARNING: This function DOES NOT permanently hide the entity from the player. As soon as the entity or
	 * player moves, the player will once again be able to see the entity.
	 */
	public function despawnFrom(Player $player, bool $send = true) : void{
		$id = spl_object_id($player);
		if(isset($this->hasSpawned[$id])){
			if($send){
				$player->getNetworkSession()->getEntityEventBroadcaster()->onEntityRemoved([$player->getNetworkSession()], $this);
			}
			unset($this->hasSpawned[$id], $this->viewerSendIds[$id]);
		}
	}

	/**
	 * @deprecated WARNING: This function DOES NOT permanently hide the entity from viewers. As soon as the entity or
	 * player moves, viewers will once again be able to see the entity.
	 */
	public function despawnFromAll() : void{
		NetworkBroadcastUtils::broadcastEntityEvent(
			$this->hasSpawned,
			fn(EntityEventBroadcaster $broadcaster, array $recipients) => $broadcaster->onEntityRemoved($recipients, $this)
		);
		$this->hasSpawned = [];
		$this->viewerSendIds = [];
	}

	/**
	 * Returns the item that players will equip when middle-clicking on this entity.
	 */
	public function getPickedItem() : ?Item{
		return null;
	}

	/**
	 * Flags the entity to be removed from the world on the next tick.
	 */
	public function flagForDespawn() : void{
		$this->needsDespawn = true;
		$this->scheduleUpdate();
	}

	public function isFlaggedForDespawn() : bool{
		return $this->needsDespawn;
	}

	/**
	 * Returns whether the entity has been "closed".
	 */
	public function isClosed() : bool{
		return $this->closed;
	}

	/**
	 * Closes the entity and frees attached references.
	 *
	 * WARNING: Entities are unusable after this has been executed!
	 */
	final public function close() : void{
		if($this->closeInFlight){
			return;
		}

		if(!$this->closed){
			$this->closeInFlight = true;
			(new EntityDespawnEvent($this))->call();

			$this->onDispose();
			$this->closed = true;
			$this->destroyCycles();
			$this->closeInFlight = false;
		}
	}

	/**
	 * Called when the entity is disposed to clean up things like viewers. This SHOULD NOT destroy internal state,
	 * because it may be needed by descendent classes.
	 */
	protected function onDispose() : void{
		$this->leaveVehicle(false, false);
		$this->ejectPassengers();
		$this->despawnFromAll();
		if($this->location->isValid()){
			$this->getWorld()->removeEntity($this);
		}
	}

	/**
	 * Called when the entity is disposed, after all events have been fired. This should be used to perform destructive
	 * circular object references and things which could impact memory usage.
	 *
	 * It is expected that the object is unusable after this is called.
	 */
	protected function destroyCycles() : void{
		$this->lastDamageCause = null;
		$this->vehicle = null;
		$this->passengers = [];
	}

	/**
	 * @param Player[]|null      $targets
	 * @param MetadataProperty[] $data    Properly formatted entity data, defaults to everything
	 *
	 * @phpstan-param array<int, MetadataProperty> $data
	 */
	public function sendData(?array $targets, ?array $data = null) : void{
		$targets = $targets ?? $this->hasSpawned;
		$data = $data ?? $this->getAllNetworkData();

		NetworkBroadcastUtils::broadcastEntityEvent($targets, fn(EntityEventBroadcaster $broadcaster, array $recipients) => $broadcaster->syncActorData($recipients, $this, $data));
	}

	/**
	 * @return MetadataProperty[]
	 * @phpstan-return array<int, MetadataProperty>
	 */
	final protected function getDirtyNetworkData() : array{
		if($this->networkPropertiesDirty){
			$this->syncNetworkData($this->networkProperties);
			$this->networkPropertiesDirty = false;
		}
		return $this->networkProperties->getDirty();
	}

	/**
	 * @return MetadataProperty[]
	 * @phpstan-return array<int, MetadataProperty>
	 */
	final protected function getAllNetworkData() : array{
		if($this->networkPropertiesDirty){
			$this->syncNetworkData($this->networkProperties);
			$this->networkPropertiesDirty = false;
		}
		return $this->networkProperties->getAll();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		$properties->setByte(EntityMetadataProperties::ALWAYS_SHOW_NAMETAG, $this->alwaysShowNameTag ? 1 : 0);
		$properties->setFloat(EntityMetadataProperties::BOUNDING_BOX_HEIGHT, $this->size->getHeight() / $this->scale);
		$properties->setFloat(EntityMetadataProperties::BOUNDING_BOX_WIDTH, $this->size->getWidth() / $this->scale);
		$properties->setFloat(EntityMetadataProperties::SCALE, $this->scale);
		$properties->setLong(EntityMetadataProperties::LEAD_HOLDER_EID, -1);
		$properties->setLong(EntityMetadataProperties::OWNER_EID, $this->ownerId ?? -1);
		$properties->setLong(EntityMetadataProperties::TARGET_EID, $this->targetId ?? 0);
		$properties->setString(EntityMetadataProperties::NAMETAG, $this->nameTag);
		$properties->setString(EntityMetadataProperties::SCORE_TAG, $this->scoreTag);
		$properties->setByte(EntityMetadataProperties::COLOR, 0);

		$properties->setGenericFlag(EntityMetadataFlags::AFFECTED_BY_GRAVITY, $this->gravityEnabled);
		$properties->setGenericFlag(EntityMetadataFlags::CAN_CLIMB, $this->canClimb);
		$properties->setGenericFlag(EntityMetadataFlags::CAN_SHOW_NAMETAG, $this->nameTagVisible);
		$properties->setGenericFlag(EntityMetadataFlags::HAS_COLLISION, true);
		$properties->setGenericFlag(EntityMetadataFlags::NO_AI, $this->noClientPredictions);
		$properties->setGenericFlag(EntityMetadataFlags::INVISIBLE, $this->invisible);
		$properties->setGenericFlag(EntityMetadataFlags::SILENT, $this->silent);
		$properties->setGenericFlag(EntityMetadataFlags::ONFIRE, $this->isOnFire());
		$properties->setGenericFlag(EntityMetadataFlags::WALLCLIMBING, $this->canClimbWalls);

		$vehicle = $this->vehicle;
		$properties->setGenericFlag(EntityMetadataFlags::RIDING, $vehicle !== null);
		if($vehicle !== null){
			$seat = $vehicle->getSeatOf($this);
			$properties->setVector3(EntityMetadataProperties::RIDER_SEAT_POSITION, $vehicle->getPassengerSeatOffset($this));
			$properties->setByte(EntityMetadataProperties::RIDER_ROTATION_LOCKED, $seat?->lockRiderRotation !== null ? 1 : 0);
			if($seat?->lockRiderRotation !== null){
				//58 is the lock angle (CloudburstMC SEAT_LOCK_RIDER_ROTATION_DEGREES; BedrockProtocol calls it RIDER_MAX_ROTATION)
				$properties->setFloat(EntityMetadataProperties::RIDER_MAX_ROTATION, $seat->lockRiderRotation);
			}
			$rotationOffset = $seat?->riderRotationOffset;
			if($rotationOffset !== null || $this->seatRotationSent){
				//59 is a byte flag (CloudburstMC SEAT_HAS_ROTATION; BedrockProtocol calls it RIDER_MIN_ROTATION), 60 the
				//rotation of the rider relative to the vehicle (SEAT_ROTATION_OFFSET_DEGREES, vanilla rotate_rider_by)
				$this->seatRotationSent = $rotationOffset !== null;
				$properties->setByte(EntityMetadataProperties::RIDER_MIN_ROTATION, $rotationOffset !== null ? 1 : 0);
				$properties->setFloat(EntityMetadataProperties::RIDER_SEAT_ROTATION_OFFSET, $rotationOffset ?? 0.0);
			}
			if($seat?->thirdPersonCameraRadius !== null || $seat?->cameraRelaxDistanceSmoothing !== null){
				$this->seatCameraSent = true;
				$properties->setFloat(EntityMetadataProperties::SEAT_THIRD_PERSON_CAMERA_RADIUS, $seat->thirdPersonCameraRadius ?? 0.0);
				$properties->setFloat(EntityMetadataProperties::SEAT_CAMERA_RELAX_DISTANCE_SMOOTHING, $seat->cameraRelaxDistanceSmoothing ?? 0.0);
			}
			$this->riderDataSent = true;
		}elseif($this->riderDataSent){
			$this->riderDataSent = false;
			$properties->setVector3(EntityMetadataProperties::RIDER_SEAT_POSITION, Vector3::zero());
			$properties->setByte(EntityMetadataProperties::RIDER_ROTATION_LOCKED, 0);
			if($this->seatRotationSent){
				$this->seatRotationSent = false;
				$properties->setByte(EntityMetadataProperties::RIDER_MIN_ROTATION, 0);
				$properties->setFloat(EntityMetadataProperties::RIDER_SEAT_ROTATION_OFFSET, 0.0);
			}
			if($this->seatCameraSent){
				$this->seatCameraSent = false;
				$properties->setFloat(EntityMetadataProperties::SEAT_THIRD_PERSON_CAMERA_RADIUS, 0.0);
				$properties->setFloat(EntityMetadataProperties::SEAT_CAMERA_RELAX_DISTANCE_SMOOTHING, 0.0);
			}
		}
		if(count($this->getRideableSeats()) > 0){
			$properties->setByte(EntityMetadataProperties::CONTROLLING_RIDER_SEAT_NUMBER, 0);
		}
	}

	/**
	 * @param Player[]|null $targets
	 */
	public function broadcastAnimation(Animation $animation, ?array $targets = null) : void{
		NetworkBroadcastUtils::broadcastPackets($targets ?? $this->getViewers(), $animation->encode());
	}

	/**
	 * Broadcasts a sound caused by the entity. If the entity is considered "silent", the sound will be dropped.
	 * @param Player[]|null $targets
	 */
	public function broadcastSound(Sound $sound, ?array $targets = null) : void{
		if(!$this->silent){
			$this->getWorld()->addSound($this->location->asVector3(), $sound, $targets ?? $this->getViewers());
		}
	}

	public function __destruct(){
		$this->close();
	}

	public function __toString(){
		return (new \ReflectionClass($this))->getShortName() . "(" . $this->getId() . ")";
	}
}
