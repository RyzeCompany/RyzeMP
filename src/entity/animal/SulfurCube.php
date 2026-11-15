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

namespace pocketmine\entity\animal;

use pocketmine\block\Lava;
use pocketmine\block\Liquid;
use pocketmine\entity\ai\hostile\goal\HopGoal;
use pocketmine\entity\ai\hostile\HopControl;
use pocketmine\entity\BucketableMob;
use pocketmine\entity\BucketableMobTrait;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Explosive;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\HeldItemsTrait;
use pocketmine\entity\object\ItemEntity;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityPreExplodeEvent;
use pocketmine\item\Bucket;
use pocketmine\item\FireCharge;
use pocketmine\item\FlintSteel;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\Shears;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use pocketmine\world\Explosion;
use pocketmine\world\GameRules;
use pocketmine\world\Position;
use pocketmine\world\vibration\GameEvent;
use function abs;
use function count;
use function floor;
use function is_int;
use function max;
use function min;
use function mt_rand;
use function round;
use function sin;
use function sqrt;

/**
 * Sulfur cube (Bedrock values from sulfur_cube.json): a passive slime-like cube that hops around. 95% spawn medium
 * (0.98 blocks, 8 health, movement 0.4), 5% small (0.49, 4 health, 0.3) which grow up in 20 minutes, faster when fed
 * slimeballs. Small ones hop towards players holding slimeballs within 8 blocks; medium ones towards players holding a
 * block they can absorb, and to such blocks lying on the ground (not for 5 seconds after losing one). Hops are quicker
 * while lured (0.16-0.5 seconds instead of 0.5-1.5). Experience 1-2 (medium). A dead medium cube splits into 2 small
 * ones (Java SulfurCube, and the minecraft:offspring component).
 *
 * A medium cube given (or picking up) a block absorbs it (shown as its main hand item) and takes one of the twelve
 * archetypes of {@link SulfurCubeArchetype} (actor property minecraft:sulfur_cube_archetype): it loses its AI and
 * becomes a ball that players push by walking into it and kick by hitting it ({@link SulfurCubeKnockback}; a Knockback
 * weapon kicks it further), with the bounciness, friction, air drag and knockback of the archetype; light, bouncy,
 * regular, explosive and hot ones float. With a block it is immune to melee, projectiles, falls, explosions, cacti,
 * falling blocks, freezing and poison, breathes in water and lava, can be leashed and doesn't despawn. Shears take the
 * block out again, another block replaces it.
 *
 * Explosive (TNT): flint and steel, a fire charge, fire, magma, a burning projectile or redstone power prime it (6
 * second fuse, FUSE_LENGTH counts down; an explosion: 0.75-3 seconds) when the tntexplodes game rule is on; it is then
 * invulnerable and explodes with power 3 (blocks only with mobgriefing), taking its TNT with it. Hot (magma block):
 * burns the mobs and players touching it (1 damage, not with Frost Walker boots, not other cubes holding a block that
 * isn't TNT).
 *
 * An empty bucket picks a medium cube up (Java Bucketable; small cubes only eat slimeballs) into a Bucket of Sulfur Cube
 * keeping its age, health and block. A cube put in a bucket or exploding drops its lead.
 */
class SulfurCube extends Animal implements BucketableMob, Explosive{
	use HeldItemsTrait{
		setMainHandItem as private setHeldMainHandItem;
	}
	use BucketableMobTrait;

	public const TAG_PICKUP_TIMER = "PickupTimer"; //TAG_Int
	public const TAG_FUSE = "Fuse"; //TAG_Short
	public const TAG_FROM_BUCKET = "FromBucket"; //TAG_Byte
	/** Absorbed block kept in the bucket (TAG_Compound, item NBT). */
	public const BUCKET_TAG_ABSORBED_BLOCK = "AbsorbedBlock";

	/** Fuse of a primed explosive cube (minecraft:explode fuse_length 6). */
	public const FUSE_TICKS = 120;
	/** Fuse range of a cube primed by an explosion (0.75-3 seconds). */
	public const EXPLOSION_FUSE_MIN = 15;
	public const EXPLOSION_FUSE_MAX = 60;
	public const EXPLOSION_POWER = 3.0;
	/** Ticks after losing a block during which the cube doesn't pick up another (pickup timeout, 5 seconds). */
	public const PICKUP_TIMEOUT = 100;

	private const PICKUP_RANGE = 8.0;
	private const ITEM_SEARCH_INTERVAL = 20;
	private const STEP_HEIGHT = 0.5625;
	private const GRAVITY = 0.08;
	/** Players closer than this (horizontally, centre to centre) push the cube (Java PUSH_DISTANCE_THRESHOLD). */
	private const PUSH_DISTANCE = 1.3;
	private const MAX_PUSH_SPEED = 0.5;
	private const PUSH_SPEED_SCALE = 0.3;
	private const VERTICAL_PUSH_SCALE = 0.3;
	private const HOT_DAMAGE_RANGE = 0.4;

	public static function getNetworkTypeId() : string{ return EntityIds::SULFUR_CUBE; }

	/**
	 * Damage dealt by the area attack of hot cubes, which has its own death message (not attributed to the cube).
	 * @phpstan-var \WeakMap<EntityDamageEvent, true>|null
	 */
	private static ?\WeakMap $hotContactDamage = null;

	/**
	 * Returns whether the damage was dealt by a hot sulfur cube touching the entity (death.attack.sulfurCube.hot).
	 * @internal
	 */
	public static function isHotContactDamage(EntityDamageEvent $source) : bool{
		return self::$hotContactDamage !== null && isset(self::$hotContactDamage[$source]);
	}

	private HopControl $hop;
	private SulfurCubeArchetype $archetype = SulfurCubeArchetype::NONE;
	private int $pickupTimer = 0;
	private int $fuse = -1;
	private bool $fromBucket = false;
	private ?ItemEntity $wantedItem = null;
	private int $itemSearchCooldown = 0;
	private int $pushSoundCooldown = 0;
	/**
	 * Positions of the players near the cube during the previous tick, to know how fast they push it.
	 * @var float[][]
	 * @phpstan-var array<int, array{float, float, float}>
	 */
	private array $pusherPositions = [];
	private ?EntityDamageByEntityEvent $playerHit = null;
	private float $playerHitDamage = 0.0;
	/** Player whose hit kicked the cube during this tick (for the extra knockback after it), with the hit's damage. */
	private ?Player $lastKicker = null;
	private int $lastKickTick = -1;
	private float $lastKickDamage = 0.0;

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return $this->isBaby() ? new EntitySizeInfo(0.49, 0.49, 0.3) : new EntitySizeInfo(0.98, 0.98, 0.6);
	}

	protected function getBabyScale() : float{
		//the small cube has a model of its own (geometry.sulfur_cube.small), only its collision box is smaller
		return 1.0;
	}

	public function getName() : string{
		return "Sulfur Cube";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(8);
		$this->setMovementSpeed(0.4);
		$this->hop = new HopControl($this, [10, 30], [3, 10]);
		parent::initEntity($nbt);
		$this->setStepHeight(self::STEP_HEIGHT);
		if($nbt->getTag(self::TAG_AGE) === null && mt_rand(0, 99) < 5){
			$this->setAge(self::BABY_AGE);
		}
		$this->loadHeldItems($nbt);
		$this->pickupTimer = max(0, $nbt->getInt(self::TAG_PICKUP_TIMER, 0));
		$this->fromBucket = $nbt->getByte(self::TAG_FROM_BUCKET, 0) !== 0;
		$this->updateArchetype(false);
		$fuse = $nbt->getShort(self::TAG_FUSE, -1);
		if($fuse >= 0 && $this->archetype === SulfurCubeArchetype::EXPLOSIVE){
			$this->fuse = $fuse;
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$this->saveHeldItems($nbt);
		$nbt->setInt(self::TAG_PICKUP_TIMER, $this->pickupTimer);
		$nbt->setShort(self::TAG_FUSE, max(-1, min(0x7fff, $this->fuse)));
		$nbt->setByte(self::TAG_FROM_BUCKET, $this->fromBucket ? 1 : 0);
		return $nbt;
	}

	public function setAge(int $age) : void{
		$wasBaby = $this->isBaby();
		parent::setAge($age);
		if($wasBaby !== $this->isBaby() || $this->getMaxHealth() !== ($this->isBaby() ? 4 : 8)){
			$this->setMaxHealth($this->isBaby() ? 4 : 8);
			//growing up: the medium component group comes with its full health
			$this->setHealth($wasBaby && !$this->isBaby() && $this->isAlive() ? $this->getMaxHealth() : $this->getHealth());
			$this->setMovementSpeed($this->isBaby() ? 0.3 : 0.4);
		}
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(2, new HopGoal($this, $this->hop, $this->findLure(...), true));
	}

	/**
	 * What lures the cube (vanilla behavior.tempt and behavior.pickup_items): the nearest player within 8 blocks
	 * holding a slimeball (small) or a block it can absorb (medium), else the block it is going to pick up.
	 */
	private function findLure() : ?Vector3{
		if($this->archetype !== SulfurCubeArchetype::NONE){
			return null;
		}
		$baby = $this->isBaby();
		$player = $this->sensing->getNearestPlayer(self::PICKUP_RANGE, fn(Player $p) => $baby ?
			$p->getInventory()->getItemInHand()->getTypeId() === ItemTypeIds::SLIMEBALL :
			SulfurCubeArchetype::fromItem($p->getInventory()->getItemInHand()) !== null
		);
		if($player !== null){
			return $player->getPosition();
		}
		$item = $this->wantedItem;
		return $item !== null && !$item->isClosed() && !$item->isFlaggedForDespawn() ? $item->getPosition() : null;
	}

	public function isBreedingItem(Item $item) : bool{
		return $item->getTypeId() === ItemTypeIds::SLIMEBALL;
	}

	public function canMate(Animal $other) : bool{
		return false; //sulfur cubes don't breed
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		return new SulfurCube($location);
	}

	/**
	 * Returns the archetype of the cube: {@link SulfurCubeArchetype::NONE} unless it holds a block.
	 */
	public function getArchetype() : SulfurCubeArchetype{
		return $this->archetype;
	}

	/**
	 * Returns the block the cube absorbed, or null.
	 */
	public function getAbsorbedBlock() : ?Item{
		return $this->archetype !== SulfurCubeArchetype::NONE ? $this->getMainHandItem() : null;
	}

	public function setMainHandItem(Item $item) : void{
		$this->setHeldMainHandItem($item);
		$this->updateArchetype(true);
	}

	/**
	 * Makes the cube absorb one of the given block. Returns false if it can't: small cubes, primed cubes, items no
	 * archetype takes and the block it already holds. A block it already held is dropped.
	 */
	public function absorbBlock(Item $item) : bool{
		if($this->isBaby() || $this->isPrimed() || !$this->isAlive() || SulfurCubeArchetype::fromItem($item) === null){
			return false;
		}
		$held = $this->getAbsorbedBlock();
		if($held !== null){
			if($held->equals($item, true, false)){
				return false;
			}
			$this->dropAbsorbedItem($held);
		}
		$this->setHeldMainHandItem((clone $item)->setCount(1));
		$this->updateArchetype(true);
		$this->wantedItem = null;
		return true;
	}

	/**
	 * Takes the block out of the cube (shears), dropping it. Returns the dropped item, or null if it held none.
	 */
	public function ejectBlock() : ?Item{
		$held = $this->getAbsorbedBlock();
		if($held === null || $this->isPrimed()){
			return null;
		}
		$this->setHeldMainHandItem(VanillaItems::AIR());
		$this->updateArchetype(true);
		$this->dropAbsorbedItem($held);
		$this->pickupTimer = self::PICKUP_TIMEOUT;
		return $held;
	}

	private function dropAbsorbedItem(Item $item) : void{
		$this->getWorld()->dropItem($this->location->add(0, $this->size->getHeight() / 2, 0), $item);
	}

	/**
	 * Follows the main hand item: archetype, actor property, AI and step height.
	 */
	private function updateArchetype(bool $sound) : void{
		$item = $this->getMainHandItem();
		$archetype = $item->isNull() ? SulfurCubeArchetype::NONE : (SulfurCubeArchetype::fromItem($item) ?? SulfurCubeArchetype::NONE);
		if($archetype === $this->archetype){
			return;
		}
		$old = $this->archetype;
		$this->archetype = $archetype;
		$this->setActorProperty("minecraft:sulfur_cube_archetype", $archetype->value);
		if($archetype !== SulfurCubeArchetype::EXPLOSIVE && $this->fuse >= 0){
			$this->fuse = -1;
			$this->networkPropertiesDirty = true;
		}
		if($archetype !== SulfurCubeArchetype::NONE){
			$this->setStepHeight(0.0);
			if($old === SulfurCubeArchetype::NONE){
				//on_block_absorbed: the AI goes away, the cube turns to face a side (rotation_axis_aligned)
				$this->goalSelector->stopAll();
				$this->hop->setWantsToMove(false);
				$yaw = round($this->location->yaw / 90) * 90;
				$this->setRotation($yaw, 0);
				if($sound){
					$this->playMobSound(LevelSoundEvent::ABSORB_BLOCK);
				}
			}
		}else{
			$this->setStepHeight(self::STEP_HEIGHT);
			if($this->isLeashed()){
				$this->unleash(true);
			}
			if($sound){
				$this->playMobSound(LevelSoundEvent::EJECT_BLOCK);
			}
		}
	}

	public function isPrimed() : bool{
		return $this->fuse >= 0;
	}

	/**
	 * Ticks left before a primed cube explodes, or -1.
	 */
	public function getFuse() : int{
		return $this->fuse;
	}

	/**
	 * Lights the fuse of an explosive cube (minecraft:try_priming; minecraft:try_priming_on_explosion with a shorter,
	 * random fuse). Returns false if it isn't explosive, is primed already or the tntexplodes game rule is off.
	 */
	public function prime(bool $byExplosion = false) : bool{
		if($this->archetype !== SulfurCubeArchetype::EXPLOSIVE || $this->isPrimed() || !$this->isAlive() ||
			$this->getWorld()->getGameRule(GameRules::TNT_EXPLODES) === false
		){
			return false;
		}
		$this->fuse = $byExplosion ? mt_rand(self::EXPLOSION_FUSE_MIN, self::EXPLOSION_FUSE_MAX) : self::FUSE_TICKS;
		$this->networkPropertiesDirty = true;
		$this->playMobSound(LevelSoundEvent::FUSE);
		$this->getWorld()->addGameEvent($this->location, GameEvent::PRIME_FUSE, $this);
		return true;
	}

	public function explode() : void{
		$ev = new EntityPreExplodeEvent($this, self::EXPLOSION_POWER);
		$ev->call();
		if($ev->isCancelled()){
			$this->fuse = -1;
			$this->networkPropertiesDirty = true;
			return;
		}
		$this->fuse = -1;
		//removed without dying: the lead drops here (Java tickFuse() dropLeash())
		$this->unleash(true);
		$this->flagForDespawn();
		$explosion = new Explosion(Position::fromObject($this->location->add(0, $this->size->getHeight() * 0.0625, 0), $this->getWorld()), $ev->getRadius(), $this, $ev->getFireChance());
		if($ev->isBlockBreaking()){
			$explosion->explodeA();
		}
		$explosion->explodeB();
	}

	public function isFromBucket() : bool{
		return $this->fromBucket;
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if($this->isPrimed()){
			return false;
		}
		if($this->isBaby()){
			return parent::onInteractWithItem($player, $item); //slimeballs make it grow; no bucket (Java mobInteract())
		}
		if($item instanceof Bucket){
			return $this->tryPickUpWithEmptyBucket($player);
		}
		if($this->archetype !== SulfurCubeArchetype::NONE){
			if($item instanceof Shears && !$player->isSneaking()){
				$this->ejectBlock();
				$this->damageHeldItem($player);
				$this->getWorld()->addGameEvent($this->location, GameEvent::SHEAR, $player);
				return true;
			}
			if($this->archetype === SulfurCubeArchetype::EXPLOSIVE && ($item instanceof FlintSteel || $item instanceof FireCharge)){
				if(!$this->prime()){
					return false;
				}
				if($item instanceof FlintSteel){
					$this->damageHeldItem($player);
				}else{
					$this->consumeHeldItem($player);
				}
				return true;
			}
		}
		if($this->absorbBlock($item)){
			$this->consumeHeldItem($player);
			return true;
		}
		return false;
	}

	protected function getItemInteractButtonText(Player $player, Item $item) : ?string{
		if($this->isPrimed() || $this->isBaby()){
			return null;
		}
		if($this->archetype !== SulfurCubeArchetype::NONE){
			if($item instanceof Shears && !$player->isSneaking()){
				return "action.interact.take_sulfur_cube";
			}
			if($this->archetype === SulfurCubeArchetype::EXPLOSIVE && ($item instanceof FlintSteel || $item instanceof FireCharge) &&
				$this->getWorld()->getGameRule(GameRules::TNT_EXPLODES) !== false
			){
				return "action.interact.creeper";
			}
		}
		if(SulfurCubeArchetype::fromItem($item) !== null && !($this->getAbsorbedBlock()?->equals($item, true, false) ?? false)){
			return "action.interact.give_sulfur_cube";
		}
		return null;
	}

	/**
	 * Puts the cube in the player's empty bucket.
	 */
	private function tryPickUpWithEmptyBucket(Player $player) : bool{
		if(!$this->isAlive() || $this->isPrimed()){
			return false;
		}
		$this->exchangeHeldItem($player, $this->createBucket());
		$this->playMobSound(LevelSoundEvent::BUCKET_FILL_LAND_ANIMAL);
		$this->unleash(true); //Java Bucketable.bucketMobPickup() drops the lead
		$this->flagForDespawn(); //no death: the block stays in the bucket
		return true;
	}

	protected function getBucketItem() : Item{
		return VanillaItems::SULFUR_CUBE_BUCKET();
	}

	protected function writeBucketData(CompoundTag $tag) : void{
		$tag->setFloat(BucketableMob::BUCKET_TAG_HEALTH, $this->getHealth());
		$tag->setInt(BucketableMob::BUCKET_TAG_AGE, $this->age);
		$this->saveAgeLock($tag);
		$block = $this->getAbsorbedBlock();
		if($block !== null){
			$tag->setTag(self::BUCKET_TAG_ABSORBED_BLOCK, $block->nbtSerialize());
		}
	}

	public function applyBucketData(CompoundTag $tag) : void{
		$this->setAge($tag->getInt(BucketableMob::BUCKET_TAG_AGE, $this->age));
		$this->loadAgeLock($tag);
		$block = $tag->getCompoundTag(self::BUCKET_TAG_ABSORBED_BLOCK);
		if($block !== null && !$this->isBaby()){
			try{
				$item = Item::nbtDeserialize($block);
				if(!$item->isNull()){
					$this->setHeldMainHandItem($item);
					$this->updateArchetype(false);
				}
			}catch(\Exception){
				//an unknown block: the cube comes out empty
			}
		}
		if($tag->getTag(BucketableMob::BUCKET_TAG_HEALTH) !== null){
			$this->setHealth(max(1.0, min((float) $this->getMaxHealth(), $tag->getFloat(BucketableMob::BUCKET_TAG_HEALTH))));
		}
		$this->fromBucket = true;
		$this->setPersistent(true);
	}

	public function canBeLeashed() : bool{
		return $this->archetype !== SulfurCubeArchetype::NONE;
	}

	public function canDespawn() : bool{
		return parent::canDespawn() && $this->archetype === SulfurCubeArchetype::NONE && !$this->fromBucket;
	}

	public function canBePushedBy(Entity $entity) : bool{
		//holding a block, players push it like a ball (the "ball" pushable_by_entity preset, see pushByPlayers()); other
		//mobs push it like any mob
		return ($this->archetype === SulfurCubeArchetype::NONE || !$entity instanceof Player) && parent::canBePushedBy($entity);
	}

	public function canBreathe() : bool{
		return $this->archetype !== SulfurCubeArchetype::NONE || parent::canBreathe();
	}

	public function canFreeze() : bool{
		return $this->archetype === SulfurCubeArchetype::NONE && parent::canFreeze();
	}

	public function hasMovementUpdate() : bool{
		//hopping counts its jump delay every tick; a cube holding a block only moves while it has motion or falls
		return ($this->isAlive() && $this->archetype === SulfurCubeArchetype::NONE) || parent::hasMovementUpdate();
	}

	protected function tickAi(int $currentTick) : void{
		if($this->archetype === SulfurCubeArchetype::NONE){
			parent::tickAi($currentTick);
		}
	}

	protected function tryChangeMovement() : void{
		if(!$this->isAlive()){
			parent::tryChangeMovement();
			return;
		}
		if($this->archetype !== SulfurCubeArchetype::NONE){
			$this->motion = $this->computeBallMotion();
			return;
		}
		$this->motion = $this->hop->computeMotion($this->motion, $this->getMovementSpeed() * 0.5, 0.42, $this->isInWater());
		if($this->hop->hasJumped()){
			$this->playMobSound($this->isBaby() ? LevelSoundEvent::SQUISH_SMALL : LevelSoundEvent::SQUISH_BIG);
		}elseif($this->hop->hasLanded()){
			$this->playMobSound(LevelSoundEvent::SLIME_LANDING);
		}
	}

	/**
	 * Motion of a cube holding a block (Java LivingEntity.travelInAir() with the archetype's attributes, uniform air
	 * drag): block friction and air drag scaled by the archetype, buoyancy in liquids for the floating ones.
	 */
	private function computeBallMotion() : Vector3{
		$archetype = $this->archetype;
		$world = $this->getWorld();
		$loc = $this->location;
		$mx = $this->motion->x;
		$my = $this->motion->y;
		$mz = $this->motion->z;
		$x = (int) floor($loc->x);
		$z = (int) floor($loc->z);
		$feet = $world->getBlockAt($x, (int) floor($loc->y + 0.1), $z);
		if($feet instanceof Liquid){
			$drag = $feet instanceof Lava ? 0.5 : 0.8;
			$mx *= $drag;
			$mz *= $drag;
			$my = $my * $drag - self::GRAVITY / 16;
			if($archetype->isBuoyant()){
				$height = $this->size->getHeight();
				$depth = $world->getBlockAt($x, (int) floor($loc->y + $height), $z) instanceof Liquid ? $height :
					($world->getBlockAt($x, (int) floor($loc->y + $height * 0.5), $z) instanceof Liquid ? $height * 0.5 : 0.1);
				$immersion = $depth - $height * 0.2 + 0.2 * sin($this->ticksLived * 0.4);
				if($immersion > 0){
					$my += min(1.0, $immersion) * 0.04;
				}
			}
		}else{
			$airDrag = SulfurCubeArchetype::modifyFriction(0.91, $archetype->getAirDragModifier());
			$friction = $airDrag;
			if($this->onGround){
				$below = $world->getBlockAt($x, (int) floor($loc->y - 0.5), $z);
				$friction *= SulfurCubeArchetype::modifyFriction($below->getFrictionFactor(), $archetype->getFrictionModifier());
			}
			$mx *= $friction;
			$mz *= $friction;
			$my = ($my - self::GRAVITY) * $airDrag;
		}
		return new Vector3($mx, $my, $mz);
	}

	protected function move(float $dx, float $dy, float $dz) : void{
		$before = $this->motion;
		parent::move($dx, $dy, $dz);
		if($this->archetype === SulfurCubeArchetype::NONE || !$this->isAlive()){
			return;
		}
		//bounce off the blocks hit (Java Entity.restituteMovementAfterCollisions())
		$bounciness = $this->archetype->getBounciness();
		$mx = $this->motion->x;
		$my = $this->motion->y;
		$mz = $this->motion->z;
		$bounced = false;
		if($this->isCollidedHorizontally && (float) $mx === 0.0 && (float) $before->x !== 0.0 && $bounciness > 0){
			$mx = -$before->x * $bounciness;
			$bounced = true;
		}
		if($this->isCollidedHorizontally && (float) $mz === 0.0 && (float) $before->z !== 0.0 && $bounciness > 0){
			$mz = -$before->z * $bounciness;
			$bounced = true;
		}
		if($this->isCollidedVertically && $before->y < -self::GRAVITY && $bounciness > 0){
			$my = -$before->y * $bounciness;
			$bounced = true;
		}
		if($bounced){
			$this->motion = new Vector3($mx, $my, $mz);
			$this->playMobSound(LevelSoundEvent::BOUNCE);
		}
	}

	protected function calculateFallDamage(float $fallDistance) : float{
		return $this->archetype !== SulfurCubeArchetype::NONE ? 0.0 : parent::calculateFallDamage($fallDistance);
	}

	public function attack(EntityDamageEvent $source) : void{
		$hit = $source instanceof EntityDamageByEntityEvent && !$source instanceof EntityDamageByChildEntityEvent &&
			$source->getCause() === EntityDamageEvent::CAUSE_ENTITY_ATTACK && $source->getDamager() instanceof Player ? $source : null;
		//the kick scales with the damage the hit would have done
		$damage = $this->playerHitDamage = $hit !== null ? max(1.0, $source->getFinalDamage()) : 0.0;
		$this->lastKicker = null;
		if($this->isPrimed()){
			//about to explode: invulnerable, knockback still applies
			$this->removeDamage($source);
		}elseif($this->archetype !== SulfurCubeArchetype::NONE){
			$this->filterDamageWithBlock($source);
		}
		$this->playerHit = $hit;
		try{
			parent::attack($source);
		}finally{
			$this->playerHit = null;
		}
		$kicker = $hit?->getDamager();
		if($kicker instanceof Player && !$source->isCancelled() && $this->archetype !== SulfurCubeArchetype::NONE){
			//the extra knockback of the player's weapon (Knockback enchantment) comes right after the hit
			$this->lastKicker = $kicker;
			$this->lastKickTick = $this->server->getTick();
			$this->lastKickDamage = $damage;
		}
	}

	/**
	 * The damage sensor of a cube holding a block: most damage only knocks it back, fire, magma, burning projectiles
	 * and explosions prime an explosive one.
	 */
	private function filterDamageWithBlock(EntityDamageEvent $source) : void{
		switch($source->getCause()){
			case EntityDamageEvent::CAUSE_MAGMA:
				//magma (blocks and hot cubes) only primes; fire hurts too
				$this->prime();
				$this->removeDamage($source);
				break;
			case EntityDamageEvent::CAUSE_FIRE:
			case EntityDamageEvent::CAUSE_FIRE_TICK:
			case EntityDamageEvent::CAUSE_CAMPFIRE:
				$this->prime();
				break;
			case EntityDamageEvent::CAUSE_BLOCK_EXPLOSION:
			case EntityDamageEvent::CAUSE_ENTITY_EXPLOSION:
				$this->prime(true);
				$this->removeDamage($source);
				break;
			case EntityDamageEvent::CAUSE_FIREBALL:
				$this->prime();
				$this->removeDamage($source);
				break;
			case EntityDamageEvent::CAUSE_PROJECTILE:
			case EntityDamageEvent::CAUSE_WITHER_SKULL:
				if($source instanceof EntityDamageByChildEntityEvent && ($source->getChild()?->isOnFire() ?? false)){
					$this->prime();
				}
				$this->removeDamage($source);
				break;
			case EntityDamageEvent::CAUSE_ENTITY_ATTACK:
			case EntityDamageEvent::CAUSE_CONTACT:
			case EntityDamageEvent::CAUSE_FALL:
			case EntityDamageEvent::CAUSE_FALLING_BLOCK:
			case EntityDamageEvent::CAUSE_FREEZE:
			case EntityDamageEvent::CAUSE_FLY_INTO_WALL:
			case EntityDamageEvent::CAUSE_SWEET_BERRY_BUSH:
			case EntityDamageEvent::CAUSE_STALACTITE:
			case EntityDamageEvent::CAUSE_STALAGMITE:
				$this->removeDamage($source);
				break;
		}
	}

	/**
	 * "no_but_entity_effects_apply": the hit happens (knockback, sound) without hurting.
	 */
	private function removeDamage(EntityDamageEvent $source) : void{
		$source->setBaseDamage(0.0);
		foreach(Utils::promoteKeys($source->getModifiers()) as $type => $amount){
			if(is_int($type)){ //modifier types are the MODIFIER_* constants
				$source->setModifier(0.0, $type);
			}
		}
	}

	public function knockBack(float $x, float $z, float $force = self::DEFAULT_KNOCKBACK_FORCE, ?float $verticalLimit = self::DEFAULT_KNOCKBACK_VERTICAL_LIMIT) : void{
		if($this->archetype !== SulfurCubeArchetype::NONE){
			$damager = $this->playerHit?->getDamager();
			if($damager instanceof Player){
				//kicked like a ball (player.json apply_knockback_rules)
				$damage = max(1.0, $this->playerHitDamage);
				$this->playerHitDamage = 0.0;
				$this->setMotion($this->motion->addVector(SulfurCubeKnockback::compute(
					$this->archetype,
					$damager->getEyePos(),
					$damager->getDirectionVector(),
					$damager->getPosition(),
					$this->location,
					$this->size->getHeight(),
					$damage
				)));
				return;
			}
			$kicker = $this->lastKicker;
			if($kicker !== null && $this->lastKickTick === $this->server->getTick()){
				//extra knockback of the hit (Knockback enchantment): the ball rules again (extra_knockback_approach
				//"multiply_reduced"), not the generic knockback that would stop it
				$this->lastKicker = null;
				$this->setMotion($this->motion->addVector(SulfurCubeKnockback::computeExtra(
					$this->archetype,
					$kicker->getEyePos(),
					$kicker->getDirectionVector(),
					$kicker->getLocation()->yaw,
					$kicker->getPosition(),
					$this->location,
					$this->size->getHeight(),
					$this->lastKickDamage,
					$force
				)));
				return;
			}
		}
		parent::knockBack($x, $z, $force, $verticalLimit);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		$this->lastKicker = null; //the extra knockback comes in the same call as the hit
		if($this->closed || !$this->isAlive()){
			return $hasUpdate;
		}
		if($this->pickupTimer > 0){
			$this->pickupTimer = max(0, $this->pickupTimer - $tickDiff);
		}
		if($this->pushSoundCooldown > 0){
			$this->pushSoundCooldown -= $tickDiff;
		}
		if($this->fuse >= 0){
			$this->fuse -= $tickDiff;
			$this->networkPropertiesDirty = true;
			if($this->fuse <= 0){
				$this->explode();
				return true;
			}
		}
		if($this->archetype !== SulfurCubeArchetype::NONE){
			$this->tickWithBlock();
		}elseif(!$this->isBaby()){
			$this->tickItemPickup($tickDiff);
		}
		return true;
	}

	private function tickWithBlock() : void{
		if($this->effectManager->has(VanillaEffects::POISON())){
			$this->effectManager->remove(VanillaEffects::POISON());
		}
		$this->pushByPlayers();
		if($this->archetype === SulfurCubeArchetype::EXPLOSIVE && !$this->isPrimed() && $this->isOnRedstonePower()){
			$this->prime();
		}elseif($this->archetype === SulfurCubeArchetype::HOT){
			$this->burnTouchingEntities();
		}
	}

	/**
	 * Players walking into the cube push it like a ball (Java SulfurCube.playerPush(), push scale of the Bedrock
	 * "ball" preset): the faster they move, the harder it goes, a bit upwards when it is on the ground.
	 */
	private function pushByPlayers() : void{
		$players = $this->getWorld()->getPlayers();
		if(count($players) === 0){
			$this->pusherPositions = [];
			return;
		}
		$loc = $this->location;
		$height = $this->size->getHeight();
		$scale = $this->archetype->getPushScale();
		$positions = [];
		$pushX = $pushY = $pushZ = 0.0;
		foreach($players as $player){
			$pos = Mob::getLiveLocationOf($player) ?? $player->getLocation();
			$dx = $loc->x - $pos->x;
			$dz = $loc->z - $pos->z;
			$distanceSquared = $dx * $dx + $dz * $dz;
			if($distanceSquared > 16 || abs($pos->y - $loc->y) > 4){
				continue;
			}
			$id = $player->getId();
			$positions[$id] = [$pos->x, $pos->y, $pos->z];
			$previous = $this->pusherPositions[$id] ?? null;
			if($previous === null || $player->isSpectator() || !$player->isAlive() ||
				$distanceSquared >= self::PUSH_DISTANCE ** 2 || $pos->y > $loc->y + $height || $pos->y + $player->getSize()->getHeight() <= $loc->y
			){
				continue;
			}
			$speed = sqrt(($pos->x - $previous[0]) ** 2 + ($pos->y - $previous[1]) ** 2 + ($pos->z - $previous[2]) ** 2);
			$speed = min(self::MAX_PUSH_SPEED, $speed * 2 * self::PUSH_SPEED_SCALE);
			$distance = sqrt($distanceSquared);
			if($speed <= 0 || $distance < 1.0e-4){
				continue;
			}
			$pushX += $dx / $distance * $scale * $speed;
			$pushZ += $dz / $distance * $scale * $speed;
			if($this->onGround){
				$pushY += $scale * self::VERTICAL_PUSH_SCALE * $speed;
			}
		}
		$this->pusherPositions = $positions;
		if($pushX === 0.0 && $pushY === 0.0 && $pushZ === 0.0){
			return;
		}
		$threshold = $this->archetype->getPushSoundThreshold();
		if($pushX ** 2 + $pushY ** 2 + $pushZ ** 2 > $threshold ** 2 && $this->pushSoundCooldown <= 0){
			$this->pushSoundCooldown = $this->archetype->getPushSoundCooldown();
			$this->playMobSound(LevelSoundEvent::PUSHED_BY_PLAYER);
		}
		$this->setMotion($this->motion->add($pushX, $pushY, $pushZ));
	}

	/**
	 * Whether the cube is in a block powered by redstone (Bedrock redstone_strength_at_position > 0): a powered block or
	 * a power source itself, like a pressed pressure plate or a lit redstone wire.
	 */
	private function isOnRedstonePower() : bool{
		$world = $this->getWorld();
		$pos = $this->location;
		if($world->isBlockPowered($pos)){
			return true;
		}
		$block = $world->getBlockAt((int) floor($pos->x), (int) floor($pos->y), (int) floor($pos->z));
		return $block->isRedstoneSource() && $block->getWeakPower(Facing::DOWN) > 0;
	}

	/**
	 * The hot archetype's area attack: 1 magma damage to the mobs and players within 0.4 blocks of the cube.
	 */
	private function burnTouchingEntities() : void{
		$box = $this->boundingBox->expandedCopy(self::HOT_DAMAGE_RANGE, self::HOT_DAMAGE_RANGE, self::HOT_DAMAGE_RANGE);
		foreach($this->getWorld()->getNearbyEntities($box, $this) as $entity){
			if(!$entity instanceof Living || !$entity->isAlive() || $entity->getFrostWalkerLevel() > 0){
				continue;
			}
			if($entity instanceof SulfurCube && $entity->archetype !== SulfurCubeArchetype::NONE && $entity->archetype !== SulfurCubeArchetype::EXPLOSIVE){
				continue;
			}
			if(!$entity instanceof Player && !$entity instanceof Mob){
				continue;
			}
			//Bedrock cause "magma": an explosive cube's damage filter primes without hurting
			$ev = new EntityDamageEvent($entity, EntityDamageEvent::CAUSE_MAGMA, 1);
			self::$hotContactDamage ??= new \WeakMap();
			self::$hotContactDamage[$ev] = true;
			$entity->attack($ev);
		}
	}

	/**
	 * Medium cubes without a block look for blocks lying on the ground within 8 blocks (vanilla behavior.pickup_items,
	 * once a second) and absorb the one they reach.
	 */
	private function tickItemPickup(int $tickDiff) : void{
		$item = $this->wantedItem;
		if($item !== null && ($item->isClosed() || $item->isFlaggedForDespawn() || $item->getWorld() !== $this->getWorld() || $this->pickupTimer > 0)){
			$item = $this->wantedItem = null;
		}
		if($item === null){
			if($this->pickupTimer > 0 || ($this->itemSearchCooldown -= $tickDiff) > 0){
				return;
			}
			$this->itemSearchCooldown = self::ITEM_SEARCH_INTERVAL;
			if(!$this->getWorld()->hasEntitiesOfClass(ItemEntity::class) || !GameRules::allowsMobGriefing($this->getWorld())){
				return;
			}
			$item = $this->wantedItem = $this->findSwallowableItem();
			if($item === null){
				return;
			}
		}
		if($item->getBoundingBox()->intersectsWith($this->boundingBox->expandedCopy(1.0, 0.0, 1.0)) && $item->getPickupDelay() === 0){
			$stack = $item->getItem();
			if($this->absorbBlock($stack)){
				//singular_pickup: one block of the stack
				if($stack->getCount() > 1){
					$item->setStackSize($stack->getCount() - 1);
				}else{
					$item->flagForDespawn();
				}
			}
			$this->wantedItem = null;
		}
	}

	private function findSwallowableItem() : ?ItemEntity{
		$loc = $this->location;
		$r = self::PICKUP_RANGE;
		$best = null;
		$bestDistance = $r * $r;
		foreach($this->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - $r, $loc->z - $r, $loc->x + $r, $loc->y + $r, $loc->z + $r), $this) as $entity){
			//no pickup delay: not the items nobody may pick up (ItemEntity::NEVER_DESPAWN, e.g. a plugin's display items)
			if($entity instanceof ItemEntity && !$entity->isFlaggedForDespawn() && $entity->getPickupDelay() === 0 && SulfurCubeArchetype::fromItem($entity->getItem()) !== null){
				$distance = $entity->getPosition()->distanceSquared($loc);
				if($distance < $bestDistance){
					$best = $entity;
					$bestDistance = $distance;
				}
			}
		}
		return $best;
	}

	protected function onDeath() : void{
		parent::onDeath();
		$this->split();
	}

	/**
	 * A medium cube that dies (not by its own explosion) splits into 2 small ones keeping its name.
	 *
	 * @return SulfurCube[]
	 */
	public function split() : array{
		if($this->isBaby() || $this->isPrimed()){
			return [];
		}
		$children = [];
		$offset = $this->size->getWidth() / 2;
		for($i = 0; $i < 2; ++$i){
			$dx = (($i % 2) - 0.5) * $offset;
			$dz = ((int) ($i / 2) - 0.5) * $offset;
			$child = new SulfurCube(Location::fromObject($this->location->add($dx, 0.5, $dz), $this->getWorld(), mt_rand(0, 359), 0));
			$child->setAge(self::BABY_AGE);
			if($this->getNameTag() !== ""){
				$child->setNameTag($this->getNameTag());
			}
			$child->setPersistent($this->isPersistent());
			$child->spawnToAll();
			$children[] = $child;
		}
		return $children;
	}

	public function getDrops() : array{
		//the absorbed block always drops (equipment drop chance 1.0); there is no loot table
		$block = $this->getAbsorbedBlock();
		return $block !== null ? [$block] : [];
	}

	public function getXpDropAmount() : int{
		return !$this->isBaby() && $this->wasRecentlyHitByPlayer() ? mt_rand(1, 2) : 0;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::SULFUR_CUBE_SPAWN_EGG();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->isBaby() ? 1 : 2);
		//query.fuse_time: ticks left, -1 when not primed (the client swells and flashes the cube for the last ticks)
		$properties->setInt(EntityMetadataProperties::FUSE_LENGTH, $this->fuse);
		$properties->setGenericFlag(EntityMetadataFlags::IGNITED, $this->fuse >= 0);
	}

	protected function destroyCycles() : void{
		unset($this->hop);
		$this->wantedItem = null;
		$this->playerHit = null;
		$this->lastKicker = null;
		$this->pusherPositions = [];
		parent::destroyCycles();
	}
}
