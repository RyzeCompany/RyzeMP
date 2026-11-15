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

namespace pocketmine\entity;

use pocketmine\block\Block;
use pocketmine\block\Lava;
use pocketmine\block\Liquid;
use pocketmine\entity\ai\AiBudget;
use pocketmine\entity\ai\control\LookControl;
use pocketmine\entity\ai\control\MoveControl;
use pocketmine\entity\ai\goal\GoalSelector;
use pocketmine\entity\ai\MobBlockPhysics;
use pocketmine\entity\ai\MobPushing;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\ai\navigation\PathNavigator;
use pocketmine\entity\ai\navigation\RandomPos;
use pocketmine\entity\ai\sensing\Sensing;
use pocketmine\entity\animal\TameableAnimal;
use pocketmine\entity\animation\ArmSwingAnimation;
use pocketmine\entity\boss\Wither;
use pocketmine\entity\effect\Effect;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\projectile\Projectile;
use pocketmine\entity\property\EntityPropertyRegistry;
use pocketmine\entity\riding\RiderInput;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityTargetEvent;
use pocketmine\item\Durable;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\SpawnEgg;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\format\Chunk;
use pocketmine\world\mobs\MobDespawner;
use pocketmine\world\sound\ItemBreakSound;
use pocketmine\world\sound\MobSound;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\World;
use pocketmine\YmlServerProperties;
use function abs;
use function count;
use function floatval;
use function floor;
use function is_int;
use function max;
use function min;
use function mt_rand;
use function spl_object_id;
use function sqrt;

/**
 * A living entity driven by server-side AI: goals ({@link GoalSelector}), pathfinding ({@link PathNavigator}), and
 * move/look controls. Subclasses register their goals in {@link Mob::registerGoals()}.
 *
 * Each tick the running goals and the controls run (cheap); starting new goals is evaluated every
 * {@link Mob::GOAL_EVALUATION_INTERVAL} ticks, staggered by entity ID and limited server-wide by {@link AiBudget}.
 * Mobs with no player within {@link Mob::getActivationRange()} blocks freeze their AI.
 *
 * Mobs running their AI push each other apart, and are pushed away by the players walking into them (see
 * {@link MobPushing}, {@link Mob::canBePushedBy()}).
 */
abstract class Mob extends Living implements Leashable{
	use LeashTrait;
	use HomeTrait;

	public const TAG_PERSISTENT = "Persistent"; //TAG_Byte (vanilla Bedrock)
	/** Home position (see {@link HomeTrait}), saved only for mobs that have one. */
	public const TAG_HOME_X = "HomeX"; //TAG_Int
	public const TAG_HOME_Y = "HomeY"; //TAG_Int
	public const TAG_HOME_Z = "HomeZ"; //TAG_Int
	public const TAG_LEASH_PLAYER = "LeashHolderUUID"; //TAG_String
	public const TAG_LEASH_FENCE = "LeashFence"; //TAG_List<TAG_Int>
	/** Persistent ID ({@link Entity::getPersistentUniqueId()}) of a mob holding the lead (vanilla Bedrock "LeasherID"). */
	public const TAG_LEASH_ENTITY = "LeasherID"; //TAG_Long
	/** Leads break beyond this distance, pull the mob beyond the elastic one, and make it follow beyond the last. */
	public const LEASH_BREAK_DISTANCE = 10.0;
	public const LEASH_ELASTIC_DISTANCE = 6.0;
	public const LEASH_FOLLOW_DISTANCE = 3.0;

	/**
	 * Ticks between two evaluations of the goals that may start. Goals with a per-tick chance to start scale it by
	 * this value.
	 */
	public const GOAL_EVALUATION_INTERVAL = 3;

	/**
	 * Mobs farther than this from every player don't run AI (vanilla simulation distance: 4 chunks), unless the world's
	 * simulation distance is larger (see {@link Mob::getActivationRange()}).
	 */
	public const ACTIVATION_RANGE = 64;
	/** Ticks after being hit by a player during which a death counts as a player kill (loot, XP). */
	private const PLAYER_KILL_TICKS = 100;
	/**
	 * Ticks during which a knockback carries the mob instead of its own movement. Only an applied knockback or push does
	 * this (see {@link Mob::carryPush()}): other damage (fire, poison, cactus, lava...) and resisted knockback leave the
	 * mob walking, like vanilla.
	 */
	private const KNOCKBACK_TICKS = 10;
	/** Ticks between two jumps while the mob keeps wanting to jump (vanilla LivingEntity.noJumpDelay). */
	private const JUMP_DELAY_TICKS = 10;
	/** Squared horizontal distance per tick above which the mob counts as moving (vanilla MoveControl.MIN_SPEED_SQR). */
	private const MOVING_DISTANCE_SQUARED = 2.5e-7;
	/** HURT_TIME sent after a hit (BDS counts it down from 9 to 0, one per tick; here only 9 and then 0 are sent). */
	private const MAX_HURT_TIME = 9;
	/**
	 * Part of a push from other entities a walking mob keeps from one tick to the next: vanilla ground friction
	 * (0.6 * 0.91) on the ground, the water drag in water (see {@link Mob::tryChangeMovement()}).
	 */
	private const GROUND_PUSH_KEPT = 0.546;
	private const WATER_PUSH_KEPT = 0.8;
	/**
	 * Horizontal speeds below this are dropped (vanilla LivingEntity.aiStep()): the end of a push or a knockback on a
	 * mob that doesn't walk, and pushes carried on top of the walking velocity.
	 */
	private const MIN_HORIZONTAL_SPEED = 0.003;
	/**
	 * Squared distance from the position last sent to viewers beyond which a mob jostled by other entities without walking
	 * is sent again (see {@link Mob::updateMovement()}): 0.15 blocks.
	 */
	private const JOSTLE_BROADCAST_DISTANCE_SQUARED = 0.0225;
	/**
	 * Part of its walking velocity a mob keeps from one tick to the next on a block with the default friction (vanilla
	 * 0.6 * 0.91), and the part of the velocity it walks with added on such a block every tick. On other blocks the
	 * friction is the block's times 0.91 times its speed factor, and the input is scaled by 0.216 / friction^3 (vanilla
	 * LivingEntity.getFrictionInfluencedSpeed()): slower to start and to stop on ice, slower on soul sand and honey.
	 */
	private const GROUND_INERTIA = 0.6 * 0.91;
	private const GROUND_INPUT = 1 - self::GROUND_INERTIA;
	private const FRICTION_INPUT_SCALE = 0.6 * 0.6 * 0.6;
	/** Vanilla LivingEntity.travel() in lava: drag on every axis (0.8 vertically when it isn't deep), a quarter of the gravity. */
	private const LAVA_DRAG = 0.5;
	private const LAVA_SHALLOW_VERTICAL_DRAG = 0.8;
	/** Part of the walking velocity added every tick in lava: mobs wade through it at a tenth of their speed. */
	private const LAVA_INPUT = 0.05;
	/** Vertical speed added by a jump in lava (vanilla LivingEntity.jumpInLiquid()). */
	private const LAVA_SWIM_UP = 0.04;
	/** Vertical speed of a mob in lava walking against a bank it can climb onto (vanilla: 0.3). */
	private const LIQUID_EDGE_HOP = 0.3;
	/**
	 * Climbing ladders, vines and scaffolding (vanilla LivingEntity.handleOnClimbable()): horizontal speed and fall speed
	 * capped at 0.15 blocks per tick, and 0.2 upwards while pushing against something or jumping.
	 */
	private const CLIMB_MAX_SPEED = 0.15;
	private const CLIMB_UP_SPEED = 0.2;
	/**
	 * Mobs which can climb (Bedrock minecraft:can_climb, sent to clients as the CAN_CLIMB flag): only these climb ladders,
	 * vines and scaffolding.
	 */
	private const CAN_CLIMB = [
		EntityIds::ARMADILLO => true, EntityIds::BLAZE => true, EntityIds::BOGGED => true, EntityIds::BREEZE => true,
		EntityIds::CAMEL => true, EntityIds::CAMEL_HUSK => true, EntityIds::CAT => true, EntityIds::CAVE_SPIDER => true,
		EntityIds::CHICKEN => true, EntityIds::COPPER_GOLEM => true, EntityIds::COW => true, EntityIds::CREAKING => true,
		EntityIds::CREEPER => true, EntityIds::DOLPHIN => true, EntityIds::DROWNED => true, EntityIds::ENDERMAN => true,
		EntityIds::ENDERMITE => true, EntityIds::EVOCATION_ILLAGER => true, EntityIds::FOX => true, EntityIds::GLOW_SQUID => true,
		EntityIds::GOAT => true, EntityIds::HOGLIN => true, EntityIds::HUSK => true, EntityIds::IRON_GOLEM => true,
		EntityIds::MAGMA_CUBE => true, EntityIds::MOOSHROOM => true, EntityIds::OCELOT => true, EntityIds::PANDA => true,
		EntityIds::PARCHED => true, EntityIds::PIG => true, EntityIds::POLAR_BEAR => true, EntityIds::RABBIT => true,
		EntityIds::SHEEP => true, EntityIds::SILVERFISH => true, EntityIds::SKELETON => true, EntityIds::SLIME => true,
		EntityIds::SNIFFER => true, EntityIds::SNOW_GOLEM => true, EntityIds::SPIDER => true, EntityIds::SQUID => true,
		EntityIds::STRAY => true, EntityIds::SULFUR_CUBE => true, EntityIds::VEX => true, EntityIds::VILLAGER => true,
		EntityIds::VILLAGER_V2 => true, EntityIds::WANDERING_TRADER => true, EntityIds::WARDEN => true, EntityIds::WITCH => true,
		EntityIds::WITHER => true, EntityIds::WITHER_SKELETON => true, EntityIds::WOLF => true, EntityIds::ZOGLIN => true,
		EntityIds::ZOMBIE => true, EntityIds::ZOMBIE_PIGMAN => true, EntityIds::ZOMBIE_VILLAGER => true,
		EntityIds::ZOMBIE_VILLAGER_V2 => true
	];

	protected GoalSelector $goalSelector;
	protected GoalSelector $targetSelector;
	protected PathNavigator $navigation;
	protected MoveControl $moveControl;
	protected LookControl $lookControl;
	protected Sensing $sensing;

	protected float $headYaw = 0.0;
	private float $lastHeadYaw = 0.0;

	protected ?Living $attackTarget = null;

	/** Persistent mobs never despawn (spawn eggs, bred, named, picked up items...). */
	protected bool $persistent = true;
	/** Ticks spent beyond the random despawn distance (see MobDespawner). */
	protected int $despawnFarTicks = 0;

	protected ?Living $lastAttacker = null;
	protected int $lastAttackedTick = -1000;
	protected int $lastHurtByPlayerTick = -1000;
	protected ?Player $lastHurtByPlayer = null;
	/**
	 * Whether the player credited with the last hit is the owner of the tamed pet that dealt it: no Looting, and no
	 * killed_by_player-only drops. False (a hit of the player) when lastHurtByPlayer is set by other code.
	 */
	private bool $lastHurtByPlayerPet = false;
	/** Server tick of the last damage of any kind (falls, cactus, explosions, attacks...), its cause and whether a mob dealt it. */
	private int $lastHurtTick = -1000;
	private int $lastHurtCause = -1;
	private bool $lastHurtByMob = false;
	/** Entity a mob of the group alerted this mob to, and when (see {@link Mob::alertTo()}). */
	private ?Living $alertTarget = null;
	private int $alertTick = -1000;

	/**
	 * @var int[] property index => encoded value
	 * @phpstan-var array<int, int>
	 */
	private array $actorProperties = [];

	private int $aiTick = 0;
	private bool $evaluationPending = true;
	/** Tick on which the pending goal evaluation was first refused by the AI update budget, -1 if it wasn't. */
	private int $evaluationWaitingSince = -1;
	private int $activationCheckTick = -1000;
	private bool $active = true;
	private int $ambientSoundTicks = 0;
	/** Some viewers missed the latest movement (see mobs.movement-broadcast): everyone gets the position once it stops. */
	private bool $movementBroadcastPending = false;
	/**
	 * Squared viewer distances of mobs.movement-broadcast (full rate, half rate); full rate -1 = no filtering.
	 * @phpstan-var array{int, int}|null
	 */
	private static ?array $movementBroadcastDistances = null;
	/**
	 * @var bool[] entity class => whether it keeps Entity::getLocation() and Entity::getPosition()
	 * @phpstan-var array<class-string<Entity>, bool>
	 */
	private static array $plainLocationByClass = [];
	/**
	 * @var bool[] entity class => whether it keeps Entity::getBoundingBox()
	 * @phpstan-var array<class-string<Entity>, bool>
	 */
	private static array $plainBoundingBoxByClass = [];
	/** Ticks the mob has been without a player nearby, used for despawning. */
	protected int $noActionTicks = 0;

	/** Ticks without input from its rider after which a ridden mob is no longer steered (or moved) by it. */
	private const RIDER_INPUT_TIMEOUT = 5;
	/** Farthest a client-predicted vehicle may move in one movement before it is sent back. */
	private const MAX_CLIENT_VEHICLE_MOVE = 4.0;

	protected ?RiderInput $riderInput = null;
	private int $riderInputTick = -1000;
	/** Last tick the client of the rider moved this mob itself (client-predicted vehicles). */
	private int $clientMovedTick = -1000;
	private bool $steered = false;
	/** Ticks of boost (carrot or warped fungus on a stick) left. */
	protected int $boostTicks = 0;

	/** Ticks left during which the last knockback carries the mob (see KNOCKBACK_TICKS). */
	private int $knockbackTicks = 0;
	private int $noJumpDelay = 0;
	/** Position at the previous tick, for the horizontal movement check. */
	private float $previousX = 0.0;
	private float $previousZ = 0.0;
	/** Whether the mob moved horizontally on this tick (MOVING flag, body rotation). */
	private bool $movingHorizontally = false;
	/** HURT_TIME value in the network properties. */
	private int $syncedHurtTime = 0;

	/** Push grid of the world the mob last took part in pushing (see MobPushing), and that world. */
	private ?MobPushing $pushing = null;
	private ?World $pushingWorld = null;
	/** Collision box when the mob last updated its place in the push grid, null while it isn't in it. */
	private ?AxisAlignedBB $pushingBox = null;
	/** Something overlapped the mob on this tick (see notifyEntityContact()): it is checked again on the next one. */
	private bool $pushContact = false;
	/** Push from the overlapping mobs and players, queued for the mob's next movement (see queueEntityPush()). */
	private float $queuedPushX = 0.0;
	private float $queuedPushZ = 0.0;
	/** Push added to the motion on this tick. */
	private float $appliedPushX = 0.0;
	private float $appliedPushZ = 0.0;
	/** Pushes carried on top of the walking velocity, which replaces the motion on every tick (see tryChangeMovement()). */
	private float $pushCarryX = 0.0;
	private float $pushCarryZ = 0.0;
	/**
	 * Tick of the last onUpdate() (see isPushingUpdated()), which is the server tick during the update: entityBaseTick()
	 * reads it instead of Server::getTick().
	 */
	private int $pushingUpdateTick = -1;
	/** A block the mob is inside slows down its next movement by these factors (see makeStuckInBlock()). */
	private bool $stuckInBlock = false;
	private float $stuckX = 1.0;
	private float $stuckY = 1.0;
	private float $stuckZ = 1.0;
	/** The mob is held up by the top of the powder snow below it on this tick (see canStandOnPowderSnow()). */
	private bool $onPowderSnow = false;
	/**
	 * Found after the last movement among the blocks the mob is inside (see checkBlockIntersections()): its feet are in a
	 * ladder, vine or scaffolding, it touches lava, it touches water.
	 */
	private bool $onClimbable = false;
	private bool $touchingLava = false;
	private bool $touchingWater = false;

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		if(isset(self::CAN_CLIMB[static::getNetworkTypeId()])){
			$this->setCanClimb();
		}
		$this->persistent = $nbt->getByte(self::TAG_PERSISTENT, 1) !== 0;
		$this->headYaw = $this->lastHeadYaw = $this->location->yaw;
		$this->previousX = $this->location->x;
		$this->previousZ = $this->location->z;
		$this->setStepHeight(0.6);
		$this->aiTick = $this->server->getTick();

		$this->moveControl = $this->createMoveControl();
		$this->lookControl = new LookControl($this);
		$this->sensing = new Sensing($this);
		$this->navigation = $this->createNavigation();
		$this->goalSelector = new GoalSelector(1);
		$this->targetSelector = new GoalSelector(1);
		$this->ambientSoundTicks = mt_rand(80, 240);
		AiBudget::load($this->server);
		$this->loadHome($nbt);
		$this->registerGoals();
		$this->loadLeash($nbt);
	}

	private function followLeashHolder(Entity $holder) : void{
		if($this->navigation->isDone()){
			$this->navigation->moveToEntity($holder, 1.0, 2.0);
		}
	}

	private function stopFollowingLeashHolder() : void{
		$this->navigation->stop();
	}

	protected function createNavigation() : PathNavigator{
		return new PathNavigator($this);
	}

	/**
	 * Creates the move control: walking by default, {@link \pocketmine\entity\ai\control\FlyingMoveControl} or
	 * {@link \pocketmine\entity\ai\control\SwimmingMoveControl} for flying and swimming mobs.
	 */
	protected function createMoveControl() : MoveControl{
		return new MoveControl($this);
	}

	/**
	 * Returns a random point to wander or flee to (used by the stroll and panic goals), or null if none was found.
	 * Walking mobs pick a standable block (its centre); swimming and flying mobs override this to pick water or air.
	 *
	 * @param Vector3|null $awayFrom if set, only points farther from it than the mob are picked
	 */
	public function findWanderPos(int $horizontal, int $vertical, ?Vector3 $awayFrom = null) : ?Vector3{
		$pos = RandomPos::getLandPos($this, $horizontal, $vertical, $awayFrom);
		return $pos?->add(0.5, 0, 0.5);
	}

	/**
	 * Returns whether the effect can't be given to this mob: potions, arrows, clouds and commands skip it. Undead mobs
	 * are immune to poison and regeneration (vanilla); others list their Bedrock mob_effect_immunity effects.
	 * Only checked when an effect is added.
	 */
	public function isImmuneToEffect(Effect $effect) : bool{
		return Wither::isUndead($this) && (
			$effect === VanillaEffects::POISON() ||
			$effect === VanillaEffects::FATAL_POISON() ||
			$effect === VanillaEffects::REGENERATION()
		);
	}

	/**
	 * Adds the mob's goals to {@link Mob::$goalSelector} and {@link Mob::$targetSelector}.
	 */
	abstract protected function registerGoals() : void;

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_PERSISTENT, $this->persistent ? 1 : 0);
		$this->saveLeash($nbt);
		$this->saveHome($nbt);
		return $nbt;
	}

	public function getGoalSelector() : GoalSelector{ return $this->goalSelector; }

	public function getTargetSelector() : GoalSelector{ return $this->targetSelector; }

	public function getNavigation() : PathNavigator{ return $this->navigation; }

	public function getMoveControl() : MoveControl{ return $this->moveControl; }

	public function getLookControl() : LookControl{ return $this->lookControl; }

	public function getSensing() : Sensing{ return $this->sensing; }

	public function isPersistent() : bool{
		return $this->persistent;
	}

	public function setPersistent(bool $persistent) : void{
		$this->persistent = $persistent;
	}

	/**
	 * Returns whether the mob may be despawned when far from players.
	 */
	public function canDespawn() : bool{
		return !$this->persistent && $this->nameTag === "" && !$this->isLeashed();
	}

	/**
	 * Called every 20 ticks for mobs which may despawn ({@link Mob::canDespawn()}). Returns whether the mob should
	 * despawn now (vanilla distance rules, see {@link MobDespawner}).
	 */
	protected function checkDespawn() : bool{
		return MobDespawner::shouldDespawn($this, $this->despawnFarTicks, 20);
	}

	public function onFrozenUpdate(int $currentTick) : bool{
		if($this->justCreated){
			return parent::onFrozenUpdate($currentTick);
		}
		parent::onFrozenUpdate($currentTick);
		//outside the simulation distance it neither pushes nor is pushed (Java LivingEntity.isPushable()), and the pushes
		//queued before it froze are dropped instead of piling up until it is simulated again
		if($this->pushing !== null){
			$this->leavePushing();
			$this->pushing = null;
			$this->pushingWorld = null;
			$this->queuedPushX = $this->queuedPushZ = 0.0;
			$this->appliedPushX = $this->appliedPushZ = 0.0;
			$this->pushCarryX = $this->pushCarryZ = 0.0;
		}
		//the despawn check of onUpdate(), without the random despawn
		if($currentTick - $this->activationCheckTick >= 20){
			$this->activationCheckTick = $currentTick;
			if($this->canDespawn() && MobDespawner::shouldDespawnWhileFrozen($this)){
				$this->flagForDespawn();
			}
		}
		return true;
	}

	public function setNameTag(string $name) : void{
		parent::setNameTag($name);
		if($name !== ""){
			$this->persistent = true;
		}
	}

	public function getHeadYaw() : float{
		return $this->headYaw;
	}

	/**
	 * Sets an actor property of this mob (e.g. "minecraft:armadillo_state"), registered for its type in
	 * {@link EntityPropertyRegistry}. Enum properties take the value name, booleans a bool.
	 */
	public function setActorProperty(string $name, int|bool|string $value) : void{
		$registry = EntityPropertyRegistry::getInstance();
		$index = $registry->getIndex(static::getNetworkTypeId(), $name) ?? throw new \InvalidArgumentException("Unknown property $name");
		$encoded = $registry->encodeValue(static::getNetworkTypeId(), $index, $value);
		if(($this->actorProperties[$index] ?? 0) !== $encoded){
			$this->actorProperties[$index] = $encoded;
			if(!$this->closed && count($this->hasSpawned) > 0){
				$this->sendData(null, []);
			}
		}
	}

	/**
	 * Returns the encoded value of an actor property (enum index, 0/1 for booleans).
	 */
	public function getActorProperty(string $name) : int{
		$index = EntityPropertyRegistry::getInstance()->getIndex(static::getNetworkTypeId(), $name);
		return $index !== null ? ($this->actorProperties[$index] ?? 0) : 0;
	}

	public function getNetworkSyncedProperties() : PropertySyncData{
		$count = EntityPropertyRegistry::getInstance()->getCount(static::getNetworkTypeId());
		if($count === 0){
			return parent::getNetworkSyncedProperties();
		}
		$values = [];
		for($i = 0; $i < $count; ++$i){
			$values[$i] = $this->actorProperties[$i] ?? 0;
		}
		return new PropertySyncData($values, []);
	}

	/**
	 * @internal Returns the mob's location object itself instead of a copy (unlike {@link Entity::getLocation()}), for
	 * the AI's hot paths. It must not be modified.
	 */
	public function getLiveLocation() : Location{
		return $this->location;
	}

	/**
	 * @internal Returns another entity's location object itself (not a copy: it must not be modified), for the AI's
	 * distance checks over every player of the world, or null when the entity's class overrides
	 * {@link Entity::getLocation()} or {@link Entity::getPosition()} (plugin subclasses, test doubles): callers then
	 * keep calling getLocation() exactly as before.
	 */
	public static function getLiveLocationOf(Entity $entity) : ?Location{
		return (self::$plainLocationByClass[$entity::class] ??= self::keepsEntityMethods($entity, "getLocation", "getPosition")) ? $entity->location : null;
	}

	/**
	 * @internal Returns another entity's bounding box object itself (it must not be modified), or null when the entity's
	 * class overrides {@link Entity::getBoundingBox()}: callers then keep calling getBoundingBox() exactly as before.
	 */
	public static function getLiveBoundingBoxOf(Entity $entity) : ?AxisAlignedBB{
		return (self::$plainBoundingBoxByClass[$entity::class] ??= self::keepsEntityMethods($entity, "getBoundingBox")) ? $entity->boundingBox : null;
	}

	private static function keepsEntityMethods(Entity $entity, string ...$methods) : bool{
		foreach($methods as $method){
			if((new \ReflectionMethod($entity, $method))->getDeclaringClass()->getName() !== Entity::class){
				return false;
			}
		}
		return true;
	}

	/**
	 * Returns the server tick of the mob's current (or last) update.
	 */
	public function getAiTick() : int{
		return $this->aiTick;
	}

	/**
	 * @internal used by the look control
	 */
	public function setHeadRotation(float $headYaw, float $bodyYaw, float $pitch) : void{
		$this->headYaw = $headYaw;
		$this->location->yaw = $bodyYaw;
		$this->location->pitch = $pitch;
	}

	/**
	 * @internal used by the move control
	 */
	public function setBodyYaw(float $yaw) : void{
		$this->location->yaw = $yaw;
	}

	public function setRotation(float $yaw, float $pitch) : void{
		parent::setRotation($yaw, $pitch);
		$this->headYaw = $yaw;
	}

	public function getAttackTarget() : ?Living{
		if($this->attackTarget !== null && ($this->attackTarget->isClosed() || !$this->attackTarget->isAlive())){
			$this->setAttackTarget(null);
		}
		return $this->attackTarget;
	}

	public function setAttackTarget(?Living $target) : void{
		if($target !== null && Sensing::isPluginNpc($target)){
			$target = null;
		}
		if($target !== null && $target !== $this->attackTarget && EntityTargetEvent::hasHandlers()){
			$ev = new EntityTargetEvent($this, $target);
			$ev->call();
			$target = $ev->isCancelled() ? null : $ev->getTarget();
		}
		if($target !== $this->attackTarget){
			$this->attackTarget = $target;
			$this->setTargetEntity($target);
		}
	}

	public function getLastAttacker() : ?Living{
		if($this->lastAttacker !== null && ($this->lastAttacker->isClosed() || !$this->lastAttacker->isAlive())){
			$this->lastAttacker = null;
		}
		return $this->lastAttacker;
	}

	public function getLastAttackedTick() : int{
		return $this->lastAttackedTick;
	}

	/**
	 * Returns the server tick of the last damage the mob took, of any cause (vanilla behavior.panic reacts to every damage
	 * source by default), or a tick long ago.
	 */
	public function getLastHurtTick() : int{
		return $this->lastHurtTick;
	}

	/**
	 * Called when a projectile is about to hit the mob. Returns whether the mob sent it away instead (Bedrock
	 * minecraft:reflect_projectiles, e.g. breezes): the projectile then doesn't hit, keeps flying with the motion set
	 * here, and passes through this mob from then on.
	 *
	 * @internal
	 */
	public function reflectProjectile(Projectile $projectile) : bool{
		return false;
	}

	/**
	 * Returns the cause ({@link EntityDamageEvent} CAUSE_* constant) of the last damage the mob took, -1 if none.
	 */
	public function getLastHurtCause() : int{
		return $this->lastHurtCause;
	}

	/**
	 * Returns whether the last damage the mob took was dealt by a living entity (a hit or a projectile of a mob or
	 * player: vanilla "mob damage", see ignore_mob_damage of behavior.panic).
	 */
	public function wasLastHurtByMob() : bool{
		return $this->lastHurtByMob;
	}

	/**
	 * Makes the mob angry at the entity because a mob of its group was hurt by it (Bedrock broadcast_anger, vanilla
	 * HurtByTargetGoal alerting others): its {@link \pocketmine\entity\ai\goal\HurtByTargetGoal} takes it as target like
	 * an attacker.
	 */
	public function alertTo(Living $target) : void{
		$this->alertTarget = $target;
		$this->alertTick = $this->server->getTick();
	}

	/**
	 * Returns the entity the mob was last alerted to (see {@link Mob::alertTo()}), or null.
	 */
	public function getAlertTarget() : ?Living{
		if($this->alertTarget !== null && ($this->alertTarget->isClosed() || !$this->alertTarget->isAlive())){
			$this->alertTarget = null;
		}
		return $this->alertTarget;
	}

	public function getAlertTick() : int{
		return $this->alertTick;
	}

	/**
	 * Returns how far the mob keeps following the targets it picked or that hurt it (follow_range attribute, Bedrock
	 * minecraft:follow_range: 16 unless the mob sets another value).
	 */
	public function getFollowRange() : float{
		return $this->attributeMap->get(Attribute::FOLLOW_RANGE)?->getValue() ?? 16.0;
	}

	/**
	 * Sets the follow_range attribute (see {@link Mob::getFollowRange()}). Target goals read it when they pick a target.
	 */
	public function setFollowRange(float $range) : void{
		$this->attributeMap->get(Attribute::FOLLOW_RANGE)?->setValue($range, true);
	}

	/**
	 * Returns whether the last damage was dealt by a player recently enough for a death to count as a player kill
	 * (vanilla: player-only loot and experience).
	 */
	public function wasRecentlyHitByPlayer() : bool{
		return $this->server->getTick() - $this->lastHurtByPlayerTick <= self::PLAYER_KILL_TICKS;
	}

	/**
	 * Like {@link Mob::wasRecentlyHitByPlayer()}, but a tamed pet's hit does not count (Bedrock killed_by_player,
	 * as opposed to killed_by_player_or_pets).
	 */
	protected function wasRecentlyHitByPlayerDirectly() : bool{
		return !$this->lastHurtByPlayerPet && $this->wasRecentlyHitByPlayer();
	}

	/**
	 * Returns the Looting level of the weapon of the player who last hit the mob, if it was a recent player kill.
	 */
	public function getLootingLevel() : int{
		$player = $this->lastHurtByPlayer;
		if($player === null || $this->lastHurtByPlayerPet || !$this->wasRecentlyHitByPlayer() || !$player->isConnected()){
			return 0;
		}
		return $player->getInventory()->getItemInHand()->getEnchantmentLevel(VanillaEnchantments::LOOTING());
	}

	/**
	 * Vanilla "looting_enchant" loot function: adds 0 to $perLevel extra items per Looting level.
	 */
	protected function lootingBonus(int $perLevel = 1) : int{
		$level = $this->getLootingLevel();
		return $level > 0 ? mt_rand(0, $level * $perLevel) : 0;
	}

	public function attack(EntityDamageEvent $source) : void{
		//the killing blow must count as a player kill too: the mob dies (loot, raid heroes...) inside parent::attack()
		$previousPlayer = $this->lastHurtByPlayer;
		$previousPlayerTick = $this->lastHurtByPlayerTick;
		$previousPet = $this->lastHurtByPlayerPet;
		$damager = $source instanceof EntityDamageByEntityEvent ? $source->getDamager() : null;
		//a tamed pet's hit counts for its owner (Bedrock killed_by_player_or_pets, Java lastHurtByPlayer)
		$player = $damager instanceof Player ? $damager : ($damager instanceof TameableAnimal && $damager->isTamed() ? $damager->getOwner() : null);
		if($player !== null){
			$this->lastHurtByPlayer = $player;
			$this->lastHurtByPlayerTick = $this->server->getTick();
			$this->lastHurtByPlayerPet = $player !== $damager;
		}
		parent::attack($source);
		if($source->isCancelled()){
			$this->lastHurtByPlayer = $previousPlayer;
			$this->lastHurtByPlayerTick = $previousPlayerTick;
			$this->lastHurtByPlayerPet = $previousPet;
			return;
		}
		$byMob = $damager instanceof Living && $damager !== $this;
		$tick = $this->server->getTick();
		if($byMob){
			$this->lastAttacker = $damager;
			$this->lastAttackedTick = $tick;
		}
		$this->lastHurtTick = $tick;
		$this->lastHurtCause = $source->getCause();
		$this->lastHurtByMob = $byMob;
		if($this->isAlive()){
			$this->playMobSound(LevelSoundEvent::HURT);
		}
	}

	public function knockBack(float $x, float $z, float $force = self::DEFAULT_KNOCKBACK_FORCE, ?float $verticalLimit = self::DEFAULT_KNOCKBACK_VERTICAL_LIMIT) : void{
		$motion = $this->motion;
		parent::knockBack($x, $z, $force, $verticalLimit);
		if($force > 0 && $this->motion !== $motion){
			//it was pushed (not resisted, not cancelled): the push carries it for a moment instead of its own walking
			$this->knockbackTicks = self::KNOCKBACK_TICKS;
		}
	}

	/**
	 * @internal Called after something other than {@link Mob::knockBack()} pushed the mob with setMotion() (an
	 * explosion, a sonic boom, a ravager's roar, a wind burst, a fishing rod reeling it in): the push carries it for a
	 * moment instead of its own walking, which would otherwise replace it on the next tick.
	 */
	public function carryPush() : void{
		$this->knockbackTicks = self::KNOCKBACK_TICKS;
	}

	/**
	 * @internal Returns whether the mob moved horizontally on this tick (more than vanilla's minimum speed), for the
	 * body rotation and the MOVING flag.
	 */
	public function isMovingHorizontally() : bool{
		return $this->movingHorizontally;
	}

	/**
	 * Returns whether the entity, a mob or a player whose collision box overlaps this mob's, pushes this mob away
	 * (Bedrock minecraft:pushable_by_entity, Java Entity.isPushable()). Mobs that can't be pushed still push the
	 * others. Asked on every tick for each overlapping pair, so it must be cheap.
	 *
	 * By default every mob can be pushed, except while it carries passengers (Java: vehicles are not pushed; the mob
	 * still pushes the others). Mobs that neither push nor are pushed override {@link Mob::pushesEntities()} instead.
	 */
	public function canBePushedBy(Entity $entity) : bool{
		return count($this->passengers) === 0;
	}

	/**
	 * Returns whether the mob takes part in entity pushing at all (see {@link Mob::canBePushedBy()}): false for mobs
	 * that neither push others nor are pushed, like the bat and the ender dragon (no pushable component in Bedrock), and
	 * mobs moving through blocks like the vex (Java: no physics, no push). Asked on every tick the mob runs its AI.
	 */
	protected function pushesEntities() : bool{
		return !$this->keepMovement;
	}

	/**
	 * Called on every tick the collision box of this mob overlaps the box of another mob or player taking part in
	 * pushing (see {@link MobPushing}), whether either of them is pushed or not: for behaviours started by touching,
	 * like Bedrock behavior.target_when_pushed (iron golems) or a warden angered by what bumps into it (Java
	 * Warden.doPush()). Does nothing by default.
	 */
	protected function onEntityContact(Entity $entity) : void{
		//NOOP
	}

	/**
	 * @internal Called by {@link MobPushing} for each mob or player overlapping this mob (see
	 * {@link Mob::onEntityContact()}).
	 */
	public function notifyEntityContact(Entity $entity) : void{
		$this->pushContact = true;
		$this->onEntityContact($entity);
	}

	/**
	 * @internal Adds a push from an overlapping mob or player (see {@link MobPushing}), applied on the mob's next
	 * movement: added to its motion, and carried on top of its walking velocity for a few ticks like a push on a mob
	 * standing still. Unlike a knockback it doesn't stop the mob from walking.
	 */
	public function queueEntityPush(float $x, float $z) : void{
		$this->queuedPushX += $x;
		$this->queuedPushZ += $z;
	}

	/**
	 * Called at the end of a tick the mob ran its AI or was steered, when it moved or something touched it: updates its
	 * place in the push grid of its world (see {@link MobPushing}).
	 */
	private function updatePushing(int $currentTick) : void{
		$box = (self::$plainBoundingBoxByClass[static::class] ??= self::keepsEntityMethods($this, "getBoundingBox")) ? $this->boundingBox : $this->getBoundingBox();
		if($box === $this->pushingBox && !$this->pushContact){
			return;
		}
		$world = $this->location->world;
		if($world === null){
			return;
		}
		if($this->pushing === null || $world !== $this->pushingWorld){
			$this->pushing?->leave($this);
			$this->pushing = MobPushing::get($world);
			$this->pushingWorld = $world;
		}
		$this->pushContact = false;
		$this->pushingBox = $this->pushing->update($this, $this->id, $box, $world, $currentTick) ? $box : null;
	}

	/**
	 * Takes the mob out of the push grid: it no longer runs its AI, rides something, or stopped taking part.
	 */
	private function leavePushing() : void{
		$this->pushing?->leave($this);
		$this->pushingBox = null;
	}

	/**
	 * @internal Returns the push from overlapping mobs and players added to the motion on this tick (X axis), for
	 * movement code that replaces the motion instead of slowing it down (slimes between hops).
	 */
	public function getAppliedEntityPushX() : float{
		return $this->appliedPushX;
	}

	/**
	 * @internal Same as {@link Mob::getAppliedEntityPushX()} on the Z axis.
	 */
	public function getAppliedEntityPushZ() : float{
		return $this->appliedPushZ;
	}

	/**
	 * @internal Returns whether the mob was updated on this tick or the previous one, so it may push and be pushed (see
	 * {@link MobPushing}): a mob no longer updated (frozen, removed from the world's updates) must not keep pushing.
	 */
	public function isPushingUpdated(int $currentTick) : bool{
		return $this->pushingUpdateTick >= $currentTick - 1;
	}

	public function onUpdate(int $currentTick) : bool{
		$this->pushingUpdateTick = $currentTick;
		$pushing = $this->pushing;
		if($pushing !== null){
			//the first mob of the world updated on this tick pushes all the mobs that overlap, before any of them moves
			if($pushing->passTick !== $currentTick){
				$world = $this->location->world;
				if($world !== null && $world === $this->pushingWorld){
					$pushing->beforeMobUpdate($world, $currentTick);
				}
			}
			$pushX = $this->queuedPushX;
			$pushZ = $this->queuedPushZ;
			if($pushX !== 0.0 || $pushZ !== 0.0 || $this->appliedPushX !== 0.0 || $this->appliedPushZ !== 0.0){
				$this->appliedPushX = $pushX;
				$this->appliedPushZ = $pushZ;
				if($pushX !== 0.0 || $pushZ !== 0.0){
					$this->queuedPushX = $this->queuedPushZ = 0.0;
					$this->motion = $this->motion->add($pushX, 0.0, $pushZ);
				}
			}
		}
		return parent::onUpdate($currentTick);
	}

	/**
	 * The body keeps moving during the death animation, as in vanilla: the killing blow knocks it back and it falls.
	 * BDS 1.26.60 moves a husk killed by a sword about 2 blocks back, like the earlier hits. Only bodies with a motion
	 * (or in the air) move, for at most the {@link Living::$maxDeadTicks} ticks of the animation.
	 */
	protected function onDeathUpdate(int $tickDiff) : bool{
		if($this->vehicle === null && $this->hasMovementUpdate()){
			$this->tryChangeMovement(); //the overrides handle dead mobs (no AI steering, dead flyers fall)
			$this->motion = $this->motion->withComponents(
				abs($this->motion->x) <= self::MOTION_THRESHOLD ? 0 : null,
				abs($this->motion->y) <= self::MOTION_THRESHOLD ? 0 : null,
				abs($this->motion->z) <= self::MOTION_THRESHOLD ? 0 : null
			);
			if(floatval($this->motion->x) !== 0.0 || floatval($this->motion->y) !== 0.0 || floatval($this->motion->z) !== 0.0){
				$this->move($this->motion->x, $this->motion->y, $this->motion->z);
			}
			$this->forceMovementUpdate = false;
			$this->updateMovement();
		}
		return parent::onDeathUpdate($tickDiff);
	}

	protected function onDeath() : void{
		$this->playMobSound(LevelSoundEvent::DEATH);
		$this->unleash(true);
		parent::onDeath();
	}

	public function playMobSound(string $sound, int $extraData = -1) : void{
		$this->broadcastSound(new MobSound($sound, static::getNetworkTypeId(), $this instanceof Ageable && $this->isBaby(), $extraData));
	}

	/** Whether no plugin changed the knockback of the last melee hit (see {@link Mob::lastMeleeHitHasDefaultKnockback()}). */
	private bool $lastMeleeDefaultKnockback = true;

	/**
	 * Returns whether no plugin changed the knockback of the last hit of {@link Mob::attackMelee()}: pushes a mob adds
	 * on top of the knockback (an iron golem's or a hoglin's toss) only apply then, a plugin's knockback is final.
	 */
	protected function lastMeleeHitHasDefaultKnockback() : bool{
		return $this->lastMeleeDefaultKnockback;
	}

	/**
	 * Hits the target in melee: base damage scaled by difficulty (vanilla: easy min(d/2+1, d), hard d*1.5), knockback,
	 * arm swing and attack sound.
	 *
	 * The arm swing (ActorEvent ARM_SWING) is what clients animate every melee attack from (variable.attack_time: the
	 * warden's and creaking's attack, the iron golem's arms, hoglin and zoglin heads...): BDS 1.26.52 sends it on the tick
	 * of the hit, then the "attack" sound. Mobs hitting with an item in their main hand (vindicators, piglin brutes,
	 * wither skeletons, zombified piglins, zombies given a sword) get it twice on that tick.
	 *
	 * Only zombies, husks, drowned and zombie villagers set their target on fire while burning (Bedrock
	 * can_spread_on_fire): see {@link \pocketmine\entity\ai\goal\MeleeAttackGoal}.
	 */
	public function attackMelee(Living $target, float $baseDamage) : bool{
		//the held weapon adds to the mob's own attack before the difficulty scaling (BDS 1.26.60 on Easy: a vindicator
		//with an iron axe hits 7.5 = min((8 + 5) / 2 + 1, 13), a wither skeleton with a stone sword 5.5)
		$baseDamage += $this->getMainHandAttackBonus();
		$damage = $target instanceof Player ? self::scaleDamageForDifficulty($baseDamage, $this->getWorld()->getDifficulty()) : $baseDamage;
		$swing = new ArmSwingAnimation($this);
		$this->broadcastAnimation($swing);
		if($this->holdsItemInMainHand()){
			$this->broadcastAnimation($swing);
		}
		if($damage <= 0){
			return false;
		}
		$ev = new EntityDamageByEntityEvent($this, $target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $damage);
		$target->attack($ev);
		$this->lastMeleeDefaultKnockback = Living::isDefaultKnockback($ev);
		if($ev->isCancelled()){
			return false;
		}
		$this->playMobSound(LevelSoundEvent::ATTACK);
		return true;
	}

	/**
	 * Returns whether the mob holds an item in its main hand (see {@link \pocketmine\entity\monster\HeldItemsTrait}).
	 */
	protected function holdsItemInMainHand() : bool{
		return false;
	}

	/**
	 * Returns the damage the item in the mob's main hand adds to its melee attack.
	 */
	protected function getMainHandAttackBonus() : float{
		return 0.0;
	}

	/**
	 * Returns whether clients are shown the mob angry (actor flag ANGRY) while it has an attack target: the vanilla mobs
	 * whose on_target_acquired event adds a minecraft:angry component group. BDS 1.26.52 sets the flag when the target is
	 * set (spiders, hoglins, zoglins, piglin brutes, vindicators: the vindicator raises its axe with it) and clears it
	 * when the target is gone. Mobs with their own anger (wolves, bees, endermen...) send the flag themselves.
	 *
	 * The answer is per class and must not change with the mob's state: mobs answering true get the flag written (set or
	 * cleared) on every sync of their network data. States that never show the anger (babies, tamed mobs) are left out
	 * by {@link Mob::showsTargetAnger()}.
	 */
	protected function isAngryWhenTargeting() : bool{
		return false;
	}

	/**
	 * For mobs angry while they have an attack target (see {@link Mob::isAngryWhenTargeting()}): returns whether the
	 * mob's current state shows that anger. Vanilla adds the angry component group to some states only (adult hoglins,
	 * wild adult nautiluses). Whatever changes the answer must mark the network properties dirty (growing up and taming
	 * do).
	 */
	protected function showsTargetAnger() : bool{
		return true;
	}

	public static function scaleDamageForDifficulty(float $damage, int $difficulty) : float{
		return match($difficulty){
			World::DIFFICULTY_PEACEFUL => 0.0,
			World::DIFFICULTY_EASY => min($damage / 2 + 1, $damage),
			World::DIFFICULTY_HARD => $damage * 1.5,
			default => $damage
		};
	}

	/**
	 * Returns whether the mob's feet are in water.
	 */
	public function isInWater() : bool{
		return (PathBlockTypes::at($this->getWorld(), (int) floor($this->location->x), (int) floor($this->location->y + 0.1), (int) floor($this->location->z)) & PathBlockTypes::WATER) !== 0;
	}

	/**
	 * Returns whether the mob touches water: in it, under it or in the rain (Bedrock filter in_contact_with_water).
	 * Callers should not check this every tick (the rain part looks up the column above the mob).
	 */
	public function isInContactWithWater() : bool{
		return $this->isInWater() || $this->isUnderwater() || $this->isInRain();
	}

	/**
	 * Returns whether the mob touches lava (its collision box was inside lava after its last movement).
	 */
	public function isInLava() : bool{
		return $this->touchingLava;
	}

	protected function checkBlockIntersections() : void{
		parent::checkBlockIntersections();
		$climbable = $lava = $water = false;
		//the blocks with entity collision the box is inside, worked out by Entity (usually none)
		foreach($this->getBlocksAroundWithEntityInsideActions() as $block){
			if($block instanceof Liquid){
				if($block instanceof Lava){
					$lava = true;
				}else{
					$water = true;
				}
			}elseif($block->canClimb()){
				//vanilla onClimbable(): the block at its feet
				$pos = $block->getPosition();
				$climbable = $climbable || ($pos->getFloorX() === (int) floor($this->location->x) && $pos->getFloorY() === (int) floor($this->location->y) && $pos->getFloorZ() === (int) floor($this->location->z));
			}
		}
		$this->onClimbable = $climbable;
		$this->touchingLava = $lava;
		if($water && !$this->touchingWater && $this->ticksLived > 1){
			//Java Entity.doWaterSplashEffect()
			$this->getWorld()->addGameEvent($this->location, GameEvent::SPLASH, $this);
		}
		$this->touchingWater = $water;
	}

	/**
	 * Uses the water found by the last block scan instead of looking the block up again (Java Entity.isInWater() is
	 * the same touching-water flag), so mobs in the air cost nothing here.
	 *
	 * @internal
	 */
	protected function getMovementGameEvent() : ?GameEvent{
		if($this->touchingWater){
			return GameEvent::SWIM;
		}
		if($this->onGround){
			return GameEvent::STEP;
		}
		return $this->makesFlapVibrations() ? GameEvent::FLAP : null;
	}

	/**
	 * Whether this mob flaps its wings while flying, which makes FLAP vibrations as it moves (Java isFlapping():
	 * bat, allay, bee, parrot, phantom and vex; ghasts, blazes and the wither do not).
	 */
	protected function makesFlapVibrations() : bool{
		return false;
	}

	/**
	 * Returns whether lava slows the mob down and keeps most of the gravity off it (vanilla travel in lava). Mobs walking
	 * on lava (striders) return false.
	 */
	protected function isSlowedByLava() : bool{
		return true;
	}

	/**
	 * Returns whether the mob, walking in lava against a bank, would be clear of blocks half a block higher: it hops out
	 * (vanilla LivingEntity.travel(), checked only while it is blocked).
	 */
	private function canHopOutOfLava(MoveControl $control) : bool{
		$box = $this->boundingBox->offsetCopy($control->getVelocityX(), 0.6, $control->getVelocityZ());
		return count($this->getWorld()->getBlockCollisionBoxes($box)) === 0;
	}

	/**
	 * Returns whether the mob can stand on powder snow instead of sinking into it (Bedrock can_stand_on_powder_snow:
	 * foxes, rabbits, endermites and silverfish; any mob wearing leather boots).
	 */
	protected function canStandOnPowderSnow() : bool{
		return $this->armorInventory->getBoots()->getTypeId() === ItemTypeIds::LEATHER_BOOTS;
	}

	/**
	 * Returns whether the given block, which slows down what moves through it, doesn't slow down this mob (Bedrock
	 * block_movement_slowdown_immunity: spiders in cobwebs).
	 */
	protected function isImmuneToBlockSlowdown(Block $block) : bool{
		return false;
	}

	/**
	 * @internal Called by the blocks slowing down what moves through them (cobwebs, sweet berry bushes, powder snow) while
	 * the mob is inside them: its next movement is multiplied by the given factors and its motion is dropped (vanilla
	 * Entity.makeStuckInBlock()). The last block to call this before the movement counts.
	 */
	public function makeStuckInBlock(Block $block, float $x, float $y, float $z) : void{
		if($this->keepMovement || $this->isImmuneToBlockSlowdown($block)){
			return;
		}
		$this->resetFallDistance();
		$this->stuckInBlock = true;
		$this->stuckX = $x;
		$this->stuckY = $y;
		$this->stuckZ = $z;
	}

	protected function move(float $dx, float $dy, float $dz) : void{
		if($this->stuckInBlock){
			$this->stuckInBlock = false;
			$dx *= $this->stuckX;
			$dy *= $this->stuckY;
			$dz *= $this->stuckZ;
			$this->motion = Vector3::zero();
		}
		parent::move($dx, $dy, $dz);
	}

	protected function checkGroundState(float $wantedX, float $wantedY, float $wantedZ, float $dx, float $dy, float $dz) : void{
		parent::checkGroundState($wantedX, $wantedY, $wantedZ, $dx, $dy, $dz);
		if($this->onPowderSnow && !$this->onGround){
			//held up by the top of the powder snow (see tryChangeMovement())
			$this->onGround = true;
		}
	}

	public function hasMovementUpdate() : bool{
		//a mob standing still on the bottom of a liquid moves when it swims up (FloatGoal)
		return parent::hasMovementUpdate() || ($this->isAlive() && ($this->moveControl->isMoving() || $this->moveControl->wantsToJump()));
	}

	protected function tryChangeMovement() : void{
		if($this->isMovedByClient()){
			//the client of the rider moves this mob (see handleClientVehicleMovement())
			$this->motion = Vector3::zero();
			return;
		}
		if($this->isAlive() && $this->moveControl->isThreeDimensional()){
			//pushes are in the motion, which is steered towards the wanted velocity
			$this->pushCarryX = $this->pushCarryZ = 0.0;
			$this->tryChangeMovementThreeDimensional($this->moveControl);
			return;
		}
		$beforeX = $this->motion->x;
		$beforeZ = $this->motion->z;
		$my = $this->motion->y;
		$world = $this->getWorld();
		$loc = $this->location;
		$blockX = (int) floor($loc->x);
		$blockZ = (int) floor($loc->z);
		//climbing and lava come from the blocks the mob was found inside after its last movement (see
		//checkBlockIntersections()): nothing to look up for most mobs
		$speedFactor = 1.0;
		$slipperiness = MobBlockPhysics::DEFAULT_FRICTION;
		$lava = $this->touchingLava && $this->isSlowedByLava();
		$this->onPowderSnow = false;
		if($lava){
			//vanilla travel in lava: a lot of drag on every axis, and only a quarter of the gravity
			$mx = $beforeX * self::LAVA_DRAG;
			$mz = $beforeZ * self::LAVA_DRAG;
			$deep = (MobBlockPhysics::at($world, $blockX, (int) floor($loc->y + 0.4), $blockZ) & MobBlockPhysics::LAVA) !== 0;
			$my *= $deep ? self::LAVA_DRAG : self::LAVA_SHALLOW_VERTICAL_DRAG;
			if($this->gravityEnabled){
				$my -= $this->gravity / 4;
			}
		}else{
			//Entity::tryChangeMovement(), with the friction of the block below read from a cache instead of a Block
			$friction = 1 - $this->drag;
			$dragBeforeGravity = $this->applyDragBeforeGravity();
			if($dragBeforeGravity){
				$my *= $friction;
			}
			if($this->gravityEnabled){
				$my -= $this->gravity;
			}
			if(!$dragBeforeGravity){
				$my *= $friction;
			}
			$onGround = $this->onGround;
			if($onGround || ($my < 0 && !$this->touchingWater)){
				//the block it walks on (vanilla: half a block below its feet, which is the block itself for blocks lower
				//than a full block like soul sand and honey, whose speed factor counts)
				$belowY = (int) floor($loc->y - 0.5000001);
				$below = MobBlockPhysics::at($world, $blockX, $belowY, $blockZ);
				if($onGround){
					$slipperiness = MobBlockPhysics::friction($below);
					$speedFactor = MobBlockPhysics::speedFactor($below);
				}
				if(($below & MobBlockPhysics::POWDER_SNOW) !== 0 && $my < 0 && $this->canStandOnPowderSnow()){
					//it walks on top of powder snow (vanilla: its collision shape for these mobs while they are above it)
					$top = $belowY + 1;
					$feetY = $this->boundingBox->minY;
					if($feetY >= $top - 0.001 && $feetY + $my < $top){
						$my = $top - $feetY;
						//standing on it (without a movement, which would update it, when it doesn't walk)
						$this->onPowderSnow = $this->onGround = true;
					}
				}
			}
			if($onGround){
				//vanilla ground inertia (LivingEntity.travel()): the friction of the block times 0.91 (0.546 on most
				//blocks, 0.8918 on ice), not the air drag times the friction
				$friction = $slipperiness * 0.91 * $speedFactor;
			}
			$mx = $beforeX * $friction;
			$mz = $beforeZ * $friction;
		}
		if(!$this->isAlive()){
			$this->motion = new Vector3($mx, $my, $mz);
			return;
		}
		$control = $this->moveControl;
		$inWater = !$lava && $this->isInWater();
		if($inWater){
			//water: buoyancy partly cancels gravity, and the water slows everything down
			$my = $my * 0.8 + 0.05;
		}
		$walking = $control->isMoving() && $this->knockbackTicks <= 0;
		//it reached the wanted position: it stops on it instead of sliding past it and walking back (on ice it slides)
		$arrived = !$walking && $control->consumeArrival() && $this->knockbackTicks <= 0 && $this->onGround && !$inWater && !$lava && $slipperiness <= MobBlockPhysics::DEFAULT_FRICTION;
		$carryX = $carryZ = 0.0;
		if($arrived || ($walking && ($inWater || $lava || $this->onGround))){
			//pushes from other entities are carried on top of the walking velocity, and fade like a push on a mob standing
			//still (vanilla adds both to the same velocity)
			if($this->pushCarryX !== 0.0 || $this->pushCarryZ !== 0.0 || $this->appliedPushX !== 0.0 || $this->appliedPushZ !== 0.0){
				$kept = $inWater ? self::WATER_PUSH_KEPT : ($lava ? self::LAVA_DRAG : self::GROUND_PUSH_KEPT);
				$carryX = $this->pushCarryX * $kept + $this->appliedPushX;
				$carryZ = $this->pushCarryZ * $kept + $this->appliedPushZ;
				if(abs($carryX) < self::MIN_HORIZONTAL_SPEED && abs($carryZ) < self::MIN_HORIZONTAL_SPEED){
					$carryX = $carryZ = 0.0;
				}
			}
			if($arrived){
				$mx = $carryX;
				$mz = $carryZ;
			}elseif($inWater){
				$mx = $control->getVelocityX() * 0.5 + $carryX;
				$mz = $control->getVelocityZ() * 0.5 + $carryZ;
			}else{
				//vanilla travel(): the velocity it walked with on the previous tick (the motion without the pushes; nothing
				//on an axis it bumped into) is slowed down by the friction, and the input of this tick is added to it
				$walkX = $beforeX - $this->appliedPushX;
				$walkX = $walkX === 0.0 ? 0.0 : $walkX - $this->pushCarryX;
				$walkZ = $beforeZ - $this->appliedPushZ;
				$walkZ = $walkZ === 0.0 ? 0.0 : $walkZ - $this->pushCarryZ;
				$wantedX = $control->getVelocityX();
				$wantedZ = $control->getVelocityZ();
				if($lava){
					$walkX = $walkX * self::LAVA_DRAG + $wantedX * self::LAVA_INPUT;
					$walkZ = $walkZ * self::LAVA_DRAG + $wantedZ * self::LAVA_INPUT;
				}else{
					$input = self::GROUND_INPUT * self::FRICTION_INPUT_SCALE / ($slipperiness * $slipperiness * $slipperiness);
					$inertia = $slipperiness * 0.91 * $speedFactor;
					if($slipperiness > MobBlockPhysics::DEFAULT_FRICTION){
						//slippery blocks: the velocity turns and changes slowly, the mob slides
						$walkX = $walkX * $inertia + $wantedX * $input;
						$walkZ = $walkZ * $inertia + $wantedZ * $input;
					}else{
						//elsewhere the vanilla velocity settles within a few ticks: only the speed builds up (by its walking
						//speed's input), the mob goes where it faces and keeps its speed round the corners of its path, and it
						//never goes faster than wanted, so the last small steps onto the wanted position are exact
						$wantedSpeed = sqrt($wantedX * $wantedX + $wantedZ * $wantedZ);
						if($wantedSpeed > 0.0){
							$speed = min($wantedSpeed, sqrt($walkX * $walkX + $walkZ * $walkZ) * $inertia + max($wantedSpeed, $control->getWalkSpeed()) * $input);
							$walkX = $wantedX * $speed / $wantedSpeed;
							$walkZ = $wantedZ * $speed / $wantedSpeed;
						}else{
							$walkX = $walkZ = 0.0;
						}
					}
				}
				$mx = $walkX + $carryX;
				$mz = $walkZ + $carryZ;
			}
		}elseif($walking){
			//limited air control: keeps the momentum of jumps (and of the pushes, which are in the motion)
			$mx += ($control->getVelocityX() - $mx) * 0.1;
			$mz += ($control->getVelocityZ() - $mz) * 0.1;
		}else{
			//not walking: the push of this tick moves the mob by its whole value, like vanilla, which adds it to the motion
			//after friction (LivingEntity.pushEntities() runs after travel()); parent::tryChangeMovement() slowed it down
			//with the rest of the motion
			if($this->appliedPushX !== 0.0 || $this->appliedPushZ !== 0.0){
				$friction = $beforeX !== 0.0 ? $mx / $beforeX : ($beforeZ !== 0.0 ? $mz / $beforeZ : 1.0);
				$mx += $this->appliedPushX * (1 - $friction);
				$mz += $this->appliedPushZ * (1 - $friction);
			}
			//what is left of a push or a knockback stops below vanilla's minimum speed (LivingEntity.aiStep()), instead of
			//moving the mob by fractions of a millimetre for many more ticks
			if(abs($mx) < self::MIN_HORIZONTAL_SPEED){
				$mx = 0.0;
			}
			if(abs($mz) < self::MIN_HORIZONTAL_SPEED){
				$mz = 0.0;
			}
		}
		//standing still or in the air, the pushes are in the motion and fade with it
		$this->pushCarryX = $carryX;
		$this->pushCarryZ = $carryZ;
		$this->appliedPushX = $this->appliedPushZ = 0.0;
		$climbing = $this->onClimbable && $this->canClimb && !$inWater && !$lava;
		if($climbing){
			//on a ladder, vine or scaffolding: slow horizontally, slides down slowly, and climbs while it pushes against
			//something (the ladder, the wall) or jumps
			$this->resetFallDistance();
			$mx = max(-self::CLIMB_MAX_SPEED, min(self::CLIMB_MAX_SPEED, $mx));
			$mz = max(-self::CLIMB_MAX_SPEED, min(self::CLIMB_MAX_SPEED, $mz));
			$my = max($my, -self::CLIMB_MAX_SPEED);
		}
		if($this->noJumpDelay > 0){
			--$this->noJumpDelay;
		}
		$jump = $control->consumeJump();
		if($climbing && ($jump || $this->isCollidedHorizontally)){
			//vanilla sets 0.2 after the movement and applies the gravity before the next one
			$my = (self::CLIMB_UP_SPEED - $this->gravity) * (1 - $this->drag);
		}elseif($lava && $walking && $this->isCollidedHorizontally && $this->canHopOutOfLava($control)){
			$my = self::LIQUID_EDGE_HOP;
		}
		if($jump){
			if($inWater){
				$my = max($my, 0.1);
			}elseif($lava){
				$my += self::LAVA_SWIM_UP;
			}elseif($climbing){
				//climbing instead
			}elseif($this->onGround && ($this->noJumpDelay <= 0 || $this->steered)){
				$my = $this->getJumpVelocity();
				//a mob that keeps wanting to jump (e.g. it didn't make it up) waits before the next jump
				$this->noJumpDelay = self::JUMP_DELAY_TICKS;
			}
		}else{
			$this->noJumpDelay = 0;
		}
		$this->motion = new Vector3($mx, $my, $mz);
	}

	/**
	 * Flying, or swimming in water: no gravity, drag on every axis, and the motion is steered towards the move
	 * control's 3D velocity (a knockback is kept for a moment, see {@link Mob::KNOCKBACK_TICKS}).
	 */
	private function tryChangeMovementThreeDimensional(MoveControl $control) : void{
		$mx = $this->motion->x;
		$my = $this->motion->y;
		$mz = $this->motion->z;
		if($control->isMoving() && $this->knockbackTicks <= 0){
			$mx += ($control->getVelocityX() - $mx) * 0.3;
			$my += ($control->getVelocityY() - $my) * 0.3;
			$mz += ($control->getVelocityZ() - $mz) * 0.3;
		}else{
			//no input: the mob brakes and hovers (or floats) in place
			$drag = $this->isInWater() ? 0.7 : 0.6;
			$mx *= $drag;
			$my *= $drag;
			$mz *= $drag;
		}
		$control->consumeJump();
		$this->motion = new Vector3(abs($mx) < 0.003 ? 0.0 : $mx, abs($my) < 0.003 ? 0.0 : $my, abs($mz) < 0.003 ? 0.0 : $mz);
	}

	protected function updateMovement(bool $teleport = false) : void{
		$motion = $this->motion;
		$lastMotion = $this->lastMotion;
		if(
			!$teleport && $this->onGround && $this->vehicle === null && $this->knockbackTicks <= 0 && (float) $motion->y === 0.0 &&
			count($this->passengers) === 0 &&
			//lastMotion->lengthSquared() written out, like in Entity::updateMovement()
			(float) ($lastMotion->x * $lastMotion->x + $lastMotion->y * $lastMotion->y + $lastMotion->z * $lastMotion->z) === 0.0 &&
			!$this->moveControl->isMoving() &&
			($this->location->yaw - $this->lastLocation->yaw) ** 2 + ($this->location->pitch - $this->lastLocation->pitch) ** 2 <= 1.0 &&
			abs(MoveControl::wrapDegrees($this->headYaw - $this->lastHeadYaw)) <= 1.0
		){
			//standing on the ground without walking, only moved by pushes from other entities (a crowded pen jostles all the
			//time): the viewers get its position once it moved a bit or came to rest, not every little step back and forth,
			//and neither its motion nor client prediction changes (it was sent still)
			$distanceSquared = $this->location->distanceSquared($this->lastLocation);
			if($distanceSquared > self::JOSTLE_BROADCAST_DISTANCE_SQUARED){
				$this->lastLocation = $this->location->asLocation();
				$this->broadcastMovement();
			}elseif((float) $motion->x === 0.0 && (float) $motion->z === 0.0 && ($distanceSquared > 0.0 || $this->movementBroadcastPending)){
				$this->lastLocation = $this->location->asLocation();
				$this->movementBroadcastPending = false;
				$this->broadcastMovementToAll(false);
			}
			return;
		}
		$before = $this->lastLocation;
		parent::updateMovement($teleport);
		if($this->lastLocation !== $before){
			$this->lastHeadYaw = $this->headYaw;
		}elseif(abs(MoveControl::wrapDegrees($this->headYaw - $this->lastHeadYaw)) > 1.0){
			$this->lastHeadYaw = $this->headYaw;
			$this->lastLocation = $this->location->asLocation();
			$this->broadcastMovement();
		}elseif($this->movementBroadcastPending){
			//it stopped: the viewers that missed updates get the final position
			$this->movementBroadcastPending = false;
			$this->broadcastMovementToAll(false);
		}
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		//before parent::entityBaseTick(), which sends changed network properties: MOVING goes out on this tick. Both values
		//below are set on the properties directly, not with a whole syncNetworkData() (they change often)
		$x = $this->location->x;
		$z = $this->location->z;
		$dx = $x - $this->previousX;
		$dz = $z - $this->previousZ;
		$moving = $dx * $dx + $dz * $dz > self::MOVING_DISTANCE_SQUARED;
		$this->previousX = $x;
		$this->previousZ = $z;
		if($moving !== $this->movingHorizontally){
			$this->movingHorizontally = $moving;
			$this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::MOVING, $moving);
		}

		$hasUpdate = parent::entityBaseTick($tickDiff);
		//Living counted the hurt time down: the change goes out on the next tick. BDS sends every value from 9 to 0 after
		//a hit, but clients only test HURT_TIME > 0 (the warden's render controller): only its start and its end are sent,
		//two updates per hit instead of ten (mobs burning or in lava are hurt every 10 or 20 ticks)
		if($this->attackTime > 0 ? $this->syncedHurtTime === 0 : $this->syncedHurtTime !== 0){
			$this->syncedHurtTime = $this->attackTime > 0 ? min(self::MAX_HURT_TIME, $this->attackTime) : 0;
			$this->getNetworkProperties()->setInt(EntityMetadataProperties::HURT_TIME, $this->syncedHurtTime);
		}
		if($this->knockbackTicks > 0){
			$this->knockbackTicks -= $tickDiff;
		}
		if($this->closed || !$this->isAlive()){
			return $hasUpdate;
		}

		//the tick onUpdate() was called with (the server tick: worlds tick their entities with it), instead of asking the
		//server again on every tick of every mob
		$now = $this->aiTick = $this->pushingUpdateTick;
		if($now - $this->activationCheckTick >= 20){
			$this->activationCheckTick = $now;
			$this->active = $this->updateActivation();
			if($this->canDespawn() && $this->checkDespawn()){
				$this->flagForDespawn();
				return false;
			}
		}

		if($this->boostTicks > 0){
			$this->boostTicks -= $tickDiff;
		}

		if($this->isLeashed()){
			$this->tickLeash();
			if($this->closed || !$this->isAlive()){
				return $hasUpdate;
			}
		}

		$steeringPlayer = $this->getSteeringPlayer();
		$steered = $steeringPlayer !== null || $this->isMovedByClient();
		if($steered !== $this->steered){
			$this->steered = $steered;
			//the rider takes over: the mob's own goals stop
			$this->goalSelector->stopAll();
			$this->navigation->stop();
			$this->moveControl->stop();
			$this->networkPropertiesDirty = true;
		}
		if($steered){
			if($steeringPlayer !== null && $this->riderInput !== null && !$this->isMovedByClient()){
				$this->steer($steeringPlayer, $this->riderInput);
				$this->moveControl->tick();
			}
		}elseif($this->active && $this->getWorld()->areMobsEnabled()){
			$this->tickAi($now);
			if(--$this->ambientSoundTicks <= 0){
				$this->ambientSoundTicks = $this->nextAmbientSoundDelay();
				$this->playAmbientSound();
			}
		}else{
			if($this->moveControl->isMoving()){
				$this->navigation->stop();
			}
			//far from players (or mobs disabled): it neither pushes nor is pushed
			if($this->pushingBox !== null){
				$this->leavePushing();
			}
			return true;
		}
		if($this->vehicle === null && !$this->closed && $this->pushesEntities()){
			//a mob that didn't move (every movement replaces its box) and that nothing touched has nothing to update
			if($this->boundingBox !== $this->pushingBox || $this->pushContact){
				$this->updatePushing($now);
			}
		}elseif($this->pushingBox !== null){
			$this->leavePushing();
		}

		return true;
	}

	/**
	 * Called every tick with the input of the player riding on the first seat. The mob follows it when the player can
	 * steer it (see {@link Mob::canBeSteeredBy()}).
	 */
	public function handleRiderInput(Player $rider, RiderInput $input) : void{
		if($this->getControllingPassenger() === $rider){
			$this->riderInput = $input;
			$this->riderInputTick = $this->server->getTick();
		}
	}

	/**
	 * Returns whether the given player, riding on the first seat, steers this mob (vanilla: saddled horses, pigs ridden
	 * with a carrot on a stick...). Mobs that can't be steered keep their own AI when ridden.
	 */
	public function canBeSteeredBy(Player $rider) : bool{
		return false;
	}

	/**
	 * Returns whether the client of a rider steering this mob drives it with its own movement controls (vanilla
	 * input_ground_controlled / free camera controlled mounts: horses, camels, nautiluses, happy ghasts).
	 */
	protected function isDrivenByRiderClient() : bool{
		return false;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$this->syncLeashData($properties);
		//BDS 1.26.52 sets MOVING while a mob moves horizontally (resource packs read it as query.is_moving: breeze dust,
		//guardian animations) and sets HURT_TIME after each hit (see entityBaseTick())
		$properties->setGenericFlag(EntityMetadataFlags::MOVING, $this->movingHorizontally);
		$this->syncedHurtTime = $this->attackTime > 0 ? min(self::MAX_HURT_TIME, $this->attackTime) : 0;
		$properties->setInt(EntityMetadataProperties::HURT_TIME, $this->syncedHurtTime);
		if($this->isAngryWhenTargeting()){
			//setting or clearing the target (Entity::setTargetEntity()) marks the properties dirty
			$properties->setGenericFlag(EntityMetadataFlags::ANGRY, $this->attackTarget !== null && $this->showsTargetAnger());
		}
		$rider = $this->getControllingPassenger();
		if($this->isDrivenByRiderClient() && $rider instanceof Player && $this->canBeSteeredBy($rider)){
			//entities standing still get NO_AI so clients don't move them on their own (Entity::updateMovement()), but
			//with it the rider's client doesn't drive the mount either: it only jumps in place
			$properties->setGenericFlag(EntityMetadataFlags::NO_AI, false);
		}
	}

	/**
	 * Returns the player steering this mob: its rider, if it may steer the mob and sent input recently.
	 */
	public function getSteeringPlayer() : ?Player{
		$rider = $this->passengers[0] ?? null;
		if($rider instanceof Player && $this->riderInput !== null && $this->server->getTick() - $this->riderInputTick <= self::RIDER_INPUT_TIMEOUT && $this->canBeSteeredBy($rider)){
			return $rider;
		}
		return null;
	}

	/**
	 * Returns whether the client of the rider is moving this mob itself (client-predicted vehicles like horses): the
	 * server then doesn't move it and doesn't send its movement back to that client.
	 */
	public function isMovedByClient() : bool{
		return ($this->passengers[0] ?? null) instanceof Player && $this->server->getTick() - $this->clientMovedTick <= self::RIDER_INPUT_TIMEOUT;
	}

	/**
	 * Moves the mob following the input of the player steering it (ground mobs: towards the rider's look direction with
	 * {@link Mob::getRiddenInput()}, at {@link Mob::getRiddenSpeed()}). Flying and swimming steered mobs override this.
	 */
	protected function steer(Player $rider, RiderInput $input) : void{
		$this->location->yaw = $input->yaw;
		$this->location->pitch = 0.0;
		$this->headYaw = $input->yaw;
		[$forward, $left] = $this->getRiddenInput($input);
		$speed = $this->getRiddenSpeed($rider);
		if($this->boostTicks > 0){
			$speed *= $this->getBoostSpeedMultiplier();
		}
		$this->moveControl->drive($forward, $left, $speed);
	}

	/**
	 * Returns the forward and left movement inputs (-1 to 1) of a steered mob. By default the rider's move vector, with
	 * slower backwards and sideways movement (vanilla horses).
	 *
	 * @return float[]
	 * @phpstan-return array{float, float}
	 */
	protected function getRiddenInput(RiderInput $input) : array{
		return [$input->forward < 0 ? $input->forward * 0.25 : $input->forward, $input->strafe * 0.5];
	}

	/**
	 * Returns the speed of the mob in blocks per tick when steered by its rider, before boosts.
	 */
	protected function getRiddenSpeed(Player $rider) : float{
		return $this->moveControl->getGroundSpeed(1.0);
	}

	/**
	 * Called when the client of the rider moved this mob itself (client-predicted vehicles). Accepts the movement if the
	 * rider steers this mob, the distance is plausible and the mob doesn't end up inside blocks.
	 */
	public function handleClientVehicleMovement(Player $rider, Vector3 $pos, float $yaw, float $pitch) : bool{
		if($this->getControllingPassenger() !== $rider || !$this->canBeSteeredBy($rider) || !$this->isAlive()){
			return false;
		}
		$world = $this->getWorld();
		$dy = $pos->y - $this->location->y;
		if($pos->distanceSquared($this->location) > self::MAX_CLIENT_VEHICLE_MOVE ** 2 || !$world->isInLoadedTerrain($pos)){
			return false;
		}
		$halfWidth = $this->size->getWidth() / 2;
		$bb = new AxisAlignedBB($pos->x - $halfWidth, $pos->y, $pos->z - $halfWidth, $pos->x + $halfWidth, $pos->y + $this->size->getHeight(), $pos->z + $halfWidth);
		//a little tolerance for client physics
		if($world->hasCollisionBlocks($bb->contractedCopy(0.2, 0.2, 0.2))){
			return false;
		}
		$this->clientMovedTick = $this->server->getTick();
		$this->setPositionAndRotation($pos, $yaw, $pitch);
		$this->headYaw = $yaw;
		$onGround = $world->hasCollisionBlocks(new AxisAlignedBB($bb->minX, $pos->y - 0.1, $bb->minZ, $bb->maxX, $pos->y, $bb->maxZ));
		$this->onGround = $onGround;
		$this->updateFallState($dy, $onGround);
		return true;
	}

	/**
	 * Sends the mob's position to the client of its rider, which moved it somewhere it was not allowed to go.
	 */
	public function resyncClientVehicle(Player $rider) : void{
		$rider->getNetworkSession()->sendDataPacket(MoveActorAbsolutePacket::create(
			$this->id,
			$this->getOffsetPosition($this->location),
			$this->location->pitch,
			$this->location->yaw,
			$this->headYaw,
			MoveActorAbsolutePacket::FLAG_TELEPORT | MoveActorAbsolutePacket::FLAG_FORCE_MOVE_LOCAL_ENTITY
		));
	}

	/**
	 * Sends the movement to the viewers, less often to the ones far away (see mobs.movement-broadcast in pocketmine.yml):
	 * like players, every moving mob would otherwise send a packet to every viewer every tick. Teleports and ridden
	 * mobs always go to everyone.
	 */
	protected function broadcastMovement(bool $teleport = false) : void{
		[$fullRateSquared, $halfRateSquared] = self::$movementBroadcastDistances ??= self::loadMovementBroadcastDistances($this->server);
		if($teleport || $fullRateSquared < 0 || count($this->passengers) > 0){
			$this->movementBroadcastPending = false;
			$this->broadcastMovementToAll($teleport);
			return;
		}
		$viewers = $this->hasSpawned;
		if(count($viewers) === 0){
			//nobody to send it to, nobody to skip: the code below would neither send nor mark anything
			return;
		}
		$tick = $this->server->getTick();
		$skipped = false;
		$x = $this->location->x;
		$y = $this->location->y;
		$z = $this->location->z;
		$viewerSendIds = $this->viewerSendIds;
		$sendIds = [];
		//selectMovement() takes int distances: squares of absurd configured distances are floats, the loop handles them
		$selector = is_int($fullRateSquared) && is_int($halfRateSquared) ? NetworkBroadcastUtils::getNativeMovementSelector() : null;
		if($selector !== null){
			//chunkutils2 >= 0.3.10 runs the loop below in C++ (PHP's arithmetic, ** 2 included): the same send ids, keyed
			//and ordered like the viewers, and the same skipped flag; -1 when it can't read the viewers as this loop
			//would (e.g. a class overriding getId()), then the loop runs
			$selected = $selector->selectMovement($viewers, $viewerSendIds, $x, $y, $z, $tick, $fullRateSquared, $halfRateSquared, $sendIds);
			if($selected >= 0){
				$this->movementBroadcastPending = $this->movementBroadcastPending || ($selected & 1) !== 0;
				if(count($sendIds) > 0){
					NetworkBroadcastUtils::broadcastPacketsToSendIds($viewers, $sendIds, [$this->createMovementPacket()]);
				}
				return;
			}
		}
		//the native send buffers only need the recipients' send ids (keyed like the viewers), the other paths the players
		$native = $selector !== null || NetworkBroadcastUtils::canAppendToNativeSendBuffers();
		$recipients = [];
		foreach($viewers as $key => $viewer){
			//inlined distanceSquared(): this loop runs for every viewer of every moving mob. Keep ** 2 (the C library's
			//pow()): $d * $d is not always the same float (Windows: 23.69 ** 2 + 41.746663339720939 ** 2 is above 2304,
			//the products are exactly 2304), so a viewer one rounding step from a limit would get another rate
			$viewerLocation = $viewer->location;
			$distanceSquared = ($viewerLocation->x - $x) ** 2 + ($viewerLocation->y - $y) ** 2 + ($viewerLocation->z - $z) ** 2;
			//beyond the full rate distance every 2nd or 4th tick (movementBroadcastInterval() inlined), offset by the
			//viewer's ID so that the skipped ticks are spread out instead of all landing on the same tick
			if($distanceSquared <= $fullRateSquared || (($tick + $viewer->getId()) % ($distanceSquared <= $halfRateSquared ? 2 : 4)) === 0){
				if($native){
					$sendIds[$key] = $viewerSendIds[$key] ?? 0;
				}else{
					$recipients[] = $viewer;
				}
			}else{
				$skipped = true;
			}
		}
		$this->movementBroadcastPending = $this->movementBroadcastPending || $skipped;
		if($native){
			if(count($sendIds) > 0){
				NetworkBroadcastUtils::broadcastPacketsToSendIds($viewers, $sendIds, [$this->createMovementPacket()]);
			}
		}elseif(count($recipients) > 0){
			NetworkBroadcastUtils::broadcastPackets($recipients, [$this->createMovementPacket()]);
		}
	}

	private function broadcastMovementToAll(bool $teleport) : void{
		$rider = $this->passengers[0] ?? null;
		if($rider instanceof Player && $this->isMovedByClient()){
			//its client moves the vehicle: sending the movement back would fight its prediction
			$targets = $this->hasSpawned;
			$sendIds = $this->viewerSendIds;
			unset($targets[spl_object_id($rider)], $sendIds[spl_object_id($rider)]);
			NetworkBroadcastUtils::broadcastPackets($targets, [$this->createMovementPacket()], $sendIds);
			return;
		}
		parent::broadcastMovement($teleport);
	}

	/**
	 * Every how many ticks a viewer at this squared distance gets the movement of a moving mob: 1 within the full rate
	 * distance, 2 within the half rate distance, 4 beyond (the same steps as players' movement-broadcast).
	 * @internal
	 */
	public static function movementBroadcastInterval(float $distanceSquared, int $fullRateSquared, int $halfRateSquared) : int{
		if($fullRateSquared < 0 || $distanceSquared <= $fullRateSquared){
			return 1;
		}
		return $distanceSquared <= $halfRateSquared ? 2 : 4;
	}

	/**
	 * @phpstan-return array{int, int}
	 */
	private static function loadMovementBroadcastDistances(Server $server) : array{
		$cfg = $server->getConfigGroup();
		$fullRate = $cfg->getPropertyInt(YmlServerProperties::MOBS_MOVEMENT_BROADCAST_FULL_RATE_DISTANCE, 24);
		$halfRate = max($fullRate, $cfg->getPropertyInt(YmlServerProperties::MOBS_MOVEMENT_BROADCAST_HALF_RATE_DISTANCE, 48));
		return [$fullRate < 0 ? -1 : $fullRate ** 2, $halfRate ** 2];
	}

	/**
	 * @internal for tests: overrides mobs.movement-broadcast (distances in blocks, full rate -1 = no filtering), or null
	 * to read the configuration again.
	 */
	public static function setMovementBroadcastDistances(?int $fullRate, int $halfRate = 48) : void{
		self::$movementBroadcastDistances = $fullRate === null ? null : [$fullRate < 0 ? -1 : $fullRate ** 2, max($fullRate, $halfRate) ** 2];
	}

	/**
	 * Returns the item boosting this mob when used by its rider (vanilla boostable component), or null.
	 */
	protected function getBoostItemTypeId() : ?int{
		return null;
	}

	/**
	 * Returns [boost duration in ticks, durability used] (vanilla boostable component).
	 *
	 * @return int[]
	 * @phpstan-return array{int, int}
	 */
	protected function getBoostProperties() : array{
		return [60, 1];
	}

	protected function getBoostSpeedMultiplier() : float{
		return 1.35;
	}

	public function isBoosting() : bool{
		return $this->boostTicks > 0;
	}

	/**
	 * Called when the rider steering this mob uses the held item: a boost item (carrot or warped fungus on a stick) speeds
	 * the mob up and loses durability (the stick turns into a fishing rod when it breaks). Returns whether the item was
	 * used.
	 */
	public function handleRiderItemUse(Player $rider, Item $item) : bool{
		$boostItem = $this->getBoostItemTypeId();
		if($boostItem === null || $item->getTypeId() !== $boostItem || $this->getSteeringPlayer() !== $rider || $this->boostTicks > 0){
			return false;
		}
		[$duration, $damage] = $this->getBoostProperties();
		$this->boostTicks = $duration;
		if($rider->hasFiniteResources() && $item instanceof Durable){
			$item->applyDamage($damage);
			$rider->getInventory()->setItemInHand($item->isBroken() ? VanillaItems::FISHING_ROD() : $item);
		}
		return true;
	}

	/**
	 * The interaction button of a mob the player may get on, or of an item use (see
	 * {@link Mob::getItemInteractButtonText()}).
	 */
	public function getInteractButtonText(Player $player) : string{
		if(!$this->isAlive()){
			return "";
		}
		$itemText = $this->getItemInteractButtonText($player, $player->getInventory()->getItemInHand());
		if($itemText !== null){
			return $itemText;
		}
		if(!$player->isSneaking() && $player->getVehicle() !== $this && $this->canAddPassenger($player)){
			return $this->getRideInteractText();
		}
		return "";
	}

	/**
	 * Returns the interaction button for using the held item on this mob (saddling...), or null if none.
	 */
	protected function getItemInteractButtonText(Player $player, Item $item) : ?string{
		return null;
	}

	/**
	 * Returns the interaction button for getting on this mob (vanilla rideable interact_text).
	 */
	protected function getRideInteractText() : string{
		return "action.interact.ride.horse";
	}

	/**
	 * Makes the player ride this mob after interacting with it, unless sneaking. Returns whether it got on.
	 */
	protected function tryRide(Player $player) : bool{
		if($player->isSneaking() || $player->getVehicle() === $this){
			return false;
		}
		return $player->mount($this, true);
	}

	/**
	 * Returns how far from the nearest player the mobs of the world run their AI: {@link Mob::ACTIVATION_RANGE}, or the
	 * radius around players in which the world ticks entities (chunk-ticking.simulation-distance, in blocks) when that
	 * is larger. Otherwise the mobs ticked beyond 64 blocks would fall, swim and be pushed, but never think (farms
	 * stall). With the default simulation distance (4 chunks) and without a limit (0) it is 64.
	 */
	public static function getActivationRange(World $world) : int{
		$simulationDistance = $world->getSimulationDistance();
		return $simulationDistance > 0 ? max(self::ACTIVATION_RANGE, $simulationDistance << Chunk::COORD_BIT_SIZE) : self::ACTIVATION_RANGE;
	}

	/**
	 * Returns whether a player is close enough for the mob to run its AI (see {@link Mob::getActivationRange()}). Called
	 * every 20 ticks.
	 */
	protected function updateActivation() : bool{
		if(!AiBudget::requiresNearbyPlayer()){
			return true;
		}
		$world = $this->getWorld();
		$range = self::getActivationRange($world) ** 2;
		foreach($world->getPlayers() as $player){
			//the player's live location instead of a copy (unless its class overrides getLocation())
			if((self::getLiveLocationOf($player) ?? $player->getLocation())->distanceSquared($this->location) <= $range){
				$this->noActionTicks = 0;
				return true;
			}
		}
		$this->noActionTicks += 20;
		return false;
	}

	protected function tickAi(int $currentTick) : void{
		$evaluate = false;
		if($this->evaluationPending || ($currentTick + $this->id) % self::GOAL_EVALUATION_INTERVAL === 0){
			$evaluate = AiBudget::tryAiUpdate($currentTick, $this->evaluationWaitingSince);
			$this->evaluationPending = !$evaluate;
			//refused: it asks again on the next ticks, and keeps its place among the mobs waiting
			$this->evaluationWaitingSince = $evaluate ? -1 : ($this->evaluationWaitingSince < 0 ? $currentTick : $this->evaluationWaitingSince);
		}
		if($evaluate){
			$this->sensing->reset();
		}
		$this->targetSelector->tick($evaluate);
		$this->goalSelector->tick($evaluate);
		if($this->vehicle === null){
			$this->navigation->tick();
			$this->moveControl->tick();
		}else{
			//a passenger is carried by its vehicle, which walks to its target (see MountPathingGoal)
			$this->navigation->stop();
		}
		if(self::$plainLookStateByClass[static::class] ??= self::keepsLookStateMethods($this)){
			//LookControl::tick() with the fields its getters return (every mob, every tick)
			$this->lookControl->tickWith($this->location, $this->headYaw, $this->movingHorizontally, $this->vehicle !== null, $this->moveControl, $this->navigation, $this->passengers);
		}else{
			$this->lookControl->tick();
		}
	}

	/**
	 * @var bool[] mob class => whether it keeps the getters {@link LookControl::tick()} reads the mob's state with
	 * @phpstan-var array<class-string<Mob>, bool>
	 */
	private static array $plainLookStateByClass = [];

	private static function keepsLookStateMethods(Mob $mob) : bool{
		foreach(["getLiveLocation" => self::class, "getHeadYaw" => self::class, "isMovingHorizontally" => self::class, "getMoveControl" => self::class, "getNavigation" => self::class, "getVehicle" => Entity::class, "getPassengers" => Entity::class] as $method => $declaringClass){
			if((new \ReflectionMethod($mob, $method))->getDeclaringClass()->getName() !== $declaringClass){
				return false;
			}
		}
		return true;
	}

	/**
	 * Returns the ticks until the next ambient sound: 8 to 24 seconds by default (Bedrock minecraft:ambient_sound_interval
	 * value 8, range 16).
	 */
	protected function nextAmbientSoundDelay() : int{
		return mt_rand(160, 480);
	}

	protected function playAmbientSound() : void{
		$this->playMobSound(LevelSoundEvent::AMBIENT);
	}

	/**
	 * Called when a player interacts with the mob holding the given item. Returns whether something happened.
	 */
	protected function onInteractWithItem(Player $player, Item $item) : bool{
		return false;
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		if(!$this->isAlive()){
			return false;
		}
		$item = $player->getInventory()->getItemInHand();
		if($item instanceof SpawnEgg && $this->spawnBabyFromEgg($player, $item)){
			return true;
		}
		if(LeashInteractions::interact($this, $player, $item)){
			//tied the mobs the player leads to this one, or cut its leads with shears (Java does it before anything else)
			return true;
		}
		if($this->interactLeash($player, $item) || $this->onInteractWithItem($player, $item)){
			//Java Mob.interact(): any interaction that does something
			$this->getWorld()->addGameEvent($this->location, GameEvent::ENTITY_INTERACT, $player);
			return true;
		}
		return false;
	}

	/**
	 * Percent chance (0-100) that this mob is a baby when it spawns naturally or from a spawn egg (Bedrock
	 * minecraft:entity_spawned "randomize" between the adult and baby groups). Mobs that roll it themselves when they
	 * are created (or have no baby) return 0.
	 *
	 * @see BabySpawning::rollSpawnBaby()
	 */
	public function getSpawnBabyChance() : int{
		return 0;
	}

	/**
	 * Turns this newly created mob into a baby (baby chance on spawn, spawn egg used on a mob of the same kind).
	 * Returns false if this mob has no baby form.
	 */
	public function makeSpawnBaby() : bool{
		return false;
	}

	/**
	 * Using this mob's own spawn egg on it spawns a baby of its kind there (Bedrock minecraft:spawn_egg_interaction,
	 * the baby gets the egg's custom name). Returns whether a baby spawned: false for any other spawn egg, which then
	 * interacts like any other item.
	 */
	protected function spawnBabyFromEgg(Player $player, SpawnEgg $egg) : bool{
		$baby = BabySpawning::createBabyFromEgg($this, $egg);
		if($baby === null){
			return false;
		}
		$baby->spawnToAll();
		$this->consumeHeldItem($player);
		return true;
	}

	/**
	 * Removes items from the stack in the player's hand (not in creative).
	 */
	protected function consumeHeldItem(Player $player, int $count = 1) : void{
		if(!$player->hasFiniteResources()){
			return;
		}
		$item = $player->getInventory()->getItemInHand();
		$item->pop($count);
		$player->getInventory()->setItemInHand($item);
	}

	/**
	 * Uses one durability of the tool in the player's hand (vanilla interact "hurt_item", e.g. shears taking a saddle
	 * off), not in creative.
	 */
	protected function damageHeldItem(Player $player) : void{
		$item = $player->getInventory()->getItemInHand();
		if(!$player->hasFiniteResources() || !$item instanceof Durable){
			return;
		}
		$item->applyDamage(1);
		if($item->isBroken()){
			$player->broadcastSound(new ItemBreakSound());
		}
		$player->getInventory()->setItemInHand($item);
	}

	/**
	 * Replaces one item of the stack in the player's hand with the result (e.g. bucket -> milk bucket).
	 */
	protected function exchangeHeldItem(Player $player, Item $result) : void{
		$inventory = $player->getInventory();
		if(!$player->hasFiniteResources()){
			if(!$inventory->contains($result)){
				$inventory->addItem($result);
			}
			return;
		}
		$item = $inventory->getItemInHand();
		if($item->getCount() <= 1){
			$inventory->setItemInHand($result);
			return;
		}
		$item->pop();
		$inventory->setItemInHand($item);
		foreach($inventory->addItem($result) as $leftover){
			$player->getWorld()->dropItem($player->getLocation(), $leftover);
		}
	}

	protected function onDispose() : void{
		$this->pushing?->leave($this);
		$this->pushing = null;
		$this->pushingWorld = null;
		$this->pushingBox = null;
		$this->goalSelector->stopAll();
		$this->targetSelector->stopAll();
		$this->navigation->dispose();
		$this->attackTarget = null;
		$this->lastAttacker = null;
		$this->lastHurtByPlayer = null;
		$this->alertTarget = null;
		parent::onDispose();
	}

	protected function destroyCycles() : void{
		unset($this->goalSelector, $this->targetSelector, $this->navigation, $this->moveControl, $this->lookControl, $this->sensing);
		parent::destroyCycles();
	}
}
