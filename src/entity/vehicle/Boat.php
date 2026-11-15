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

namespace pocketmine\entity\vehicle;

use pocketmine\block\Water;
use pocketmine\block\WaterLily;
use pocketmine\entity\animal\Animal;
use pocketmine\entity\animal\HappyGhast;
use pocketmine\entity\aquatic\AbstractFish;
use pocketmine\entity\aquatic\Dolphin;
use pocketmine\entity\aquatic\Tadpole;
use pocketmine\entity\boss\EnderDragon;
use pocketmine\entity\boss\Wither;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Leashable;
use pocketmine\entity\LeashInteractions;
use pocketmine\entity\LeashTrait;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Creaking;
use pocketmine\entity\object\LeashKnot;
use pocketmine\entity\riding\RideableSeat;
use pocketmine\entity\riding\RiderInput;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use pocketmine\world\GameRules;
use function abs;
use function ceil;
use function cos;
use function count;
use function deg2rad;
use function floor;
use function fmod;
use function max;
use function min;
use function sin;
use function spl_object_id;
use function sqrt;
use const M_PI;
use const PHP_FLOAT_MAX;

/**
 * A boat (vanilla minecraft:boat, every wood and the bamboo raft): two seats, the first one steers. A player driving a
 * boat moves it with its own client (Bedrock boats are client-predicted vehicles): the server checks the positions it
 * gets and animates the paddles for the other players. Boats nobody steers float, drift and slide with vanilla's
 * physics (Java AbstractBoat.floatBoat()): they keep 90% of their speed per tick in water, the friction of the blocks
 * under them on land (much more on ice), and come back up to the surface.
 *
 * Like minecarts, hits add 10 x the damage to an accumulated "damage" value that decays by 1 per tick: the boat breaks
 * above 40, at once (without drops) when hit by a creative player. Falls don't break Bedrock boats, lava does.
 *
 * Boats can be led like mobs (Bedrock minecraft:leashable, see {@link LeashTrait}): a lead holds them softly from
 * 2 blocks and pulls them beyond 4 (the default 4 and 6 when a happy ghast tows them), and the boat drifts after its
 * holder while it isn't steered. Mobs a player leads can be tied to a boat (minecraft:leashable_to). A breaking boat
 * drops its lead.
 */
class Boat extends Entity implements Leashable{
	use LeashTrait;

	public const TAG_VARIANT = "Variant"; //TAG_Int

	/** Network position of a boat: Bedrock clients place boats by a point 0.375 above their bottom. */
	public const NETWORK_Y_OFFSET = 0.375;
	/** Accumulated damage above which the boat breaks. */
	public const BREAK_DAMAGE = 40.0;
	/** Ticks under water after which the passengers are thrown out. */
	public const OUT_OF_CONTROL_TICKS = 60;
	/** Phase advanced by a moving paddle per tick, in radians (vanilla PADDLE_SPEED). */
	public const PADDLE_SPEED = M_PI / 8;
	/** Widest passenger a boat can carry (vanilla passenger_max_width). */
	public const MAX_PASSENGER_WIDTH = 1.375;
	/** Furthest a driver's client may move the boat in one input, in blocks (boats on blue ice reach ~3.6). */
	public const MAX_CLIENT_MOVE = 6.0;

	/**
	 * Buoyancy settings of the client's own simulation (vanilla minecraft:buoyant, the BDS values GeyserMC sends):
	 * without them the driver's client neither floats the boat nor moves it on land. No waves: the server doesn't bob
	 * boats.
	 */
	public const BUOYANCY_DATA = '{"apply_gravity":true,"base_buoyancy":1.0,"big_wave_probability":0.02999999932944775,"big_wave_speed":10.0,"drag_down_on_buoyancy_removed":0.0,"liquid_blocks":["minecraft:water","minecraft:flowing_water"],"simulate_waves":false}';

	private const STATUS_IN_WATER = 0;
	private const STATUS_UNDER_WATER = 1;
	private const STATUS_UNDER_FLOWING_WATER = 2;
	private const STATUS_ON_LAND = 3;
	private const STATUS_IN_AIR = 4;

	/** Upwards push on a sunken boat per tick (rises at ~0.05 blocks per tick). */
	private const UNDER_WATER_RISE_SPEED = 0.03;

	/** Ticks the last input or client movement of the driver counts for. */
	private const INPUT_TIMEOUT = 5;

	/** Pull of a lead between its soft and hard distances, per block past the soft one and per tick, and its cap. */
	private const LEASH_SPRING = 0.02;
	private const LEASH_SPRING_MAX = 0.06;

	protected BoatVariant $variant = BoatVariant::OAK;
	protected int $hurtTime = 0;
	protected int $hurtDirection = 1;
	protected float $damage = 0.0;
	private bool $broken = false;

	private int $status = self::STATUS_IN_AIR;
	private int $oldStatus = self::STATUS_IN_AIR;
	private float $waterLevel = 0.0;
	private float $landFriction = 0.6;
	private float $deltaRotation = 0.0;
	private float $lastYd = 0.0;
	private int $outOfControlTicks = 0;

	private bool $paddlingLeft = false;
	private bool $paddlingRight = false;
	private float $paddleTimeLeft = 0.0;
	private float $paddleTimeRight = 0.0;

	private ?RiderInput $riderInput = null;
	private int $riderInputTick = -1000;
	private int $clientMovedTick = -1000;

	public static function getNetworkTypeId() : string{ return EntityIds::BOAT; }

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.455, 1.4); }

	protected function getInitialDragMultiplier() : float{ return 0.0; }

	protected function getInitialGravity() : float{ return 0.04; }

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->variant = BoatVariant::tryFrom($nbt->getInt(self::TAG_VARIANT, 0)) ?? BoatVariant::OAK;
		$this->status = $this->computeStatus();
		$this->oldStatus = $this->status;
		$this->loadLeash($nbt);
		if($this->motion->lengthSquared() === 0.0){
			//see AbstractMinecart::initEntity(): clients must not move a boat the server keeps still
			$this->setNoClientPredictions(true);
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_VARIANT, $this->variant->value);
		$this->saveLeash($nbt);
		return $nbt;
	}

	public function getName() : string{
		return $this->variant->isRaft() ? "Raft" : "Boat";
	}

	public function getVariant() : BoatVariant{
		return $this->variant;
	}

	public function setVariant(BoatVariant $variant) : void{
		$this->variant = $variant;
		$this->networkPropertiesDirty = true;
		$this->refreshPassengerSeats();
	}

	/**
	 * Returns the item this boat drops when broken (and that picks it).
	 */
	public function getBoatItem() : Item{
		return $this->variant->getBoatItem();
	}

	public function getPickedItem() : ?Item{
		return $this->getBoatItem();
	}

	public function canBeStoodOn() : bool{
		//vanilla is_collidable: players can stand on boats
		return true;
	}

	// ---- damage ----

	public function getHurtTime() : int{ return $this->hurtTime; }

	public function getHurtDirection() : int{ return $this->hurtDirection; }

	/**
	 * Returns the accumulated damage (the boat breaks above {@link Boat::BREAK_DAMAGE}).
	 */
	public function getDamage() : float{ return $this->damage; }

	public function setDamage(float $damage) : void{
		$this->damage = max(0.0, $damage);
		$this->networkPropertiesDirty = true;
	}

	public function isBroken() : bool{
		return $this->broken;
	}

	protected function animateHurt() : void{
		$this->hurtDirection = -$this->hurtDirection;
		$this->hurtTime = 10;
		$this->networkPropertiesDirty = true;
		//a boat resting on land sleeps: the hurt time and damage must wear off
		$this->scheduleUpdate();
	}

	/**
	 * Whether this kind of damage hurts boats: hits, projectiles, explosions and lava (Bedrock hurt_on_condition), not
	 * fire, falls, drowning...
	 */
	protected function canBeDamagedBy(EntityDamageEvent $source) : bool{
		return match($source->getCause()){
			EntityDamageEvent::CAUSE_ENTITY_ATTACK,
			EntityDamageEvent::CAUSE_PROJECTILE,
			EntityDamageEvent::CAUSE_FIREBALL,
			EntityDamageEvent::CAUSE_WITHER_SKULL,
			EntityDamageEvent::CAUSE_ENTITY_EXPLOSION,
			EntityDamageEvent::CAUSE_BLOCK_EXPLOSION,
			EntityDamageEvent::CAUSE_LAVA,
			EntityDamageEvent::CAUSE_CONTACT,
			EntityDamageEvent::CAUSE_MAGIC,
			EntityDamageEvent::CAUSE_SONIC_BOOM,
			EntityDamageEvent::CAUSE_CUSTOM => true,
			default => false
		};
	}

	public function attack(EntityDamageEvent $source) : void{
		if($this->broken || $this->isFlaggedForDespawn()){
			return;
		}
		if($source->getCause() === EntityDamageEvent::CAUSE_VOID){
			parent::attack($source);
			return;
		}
		if(!$this->canBeDamagedBy($source)){
			return;
		}
		$source->call();
		if($source->isCancelled()){
			return;
		}
		$this->setLastDamageCause($source);
		$this->animateHurt();
		$this->setDamage($this->damage + $source->getFinalDamage() * 10);

		$attacker = $source instanceof EntityDamageByEntityEvent ? $source->getDamager() : null;
		if($attacker instanceof Player && $attacker->isCreative()){
			$this->breakBoat(false);
		}elseif($this->damage > self::BREAK_DAMAGE){
			$this->breakBoat(true);
		}
	}

	/**
	 * Returns the items dropped by the boat when it breaks (container contents are dropped separately).
	 *
	 * @return Item[]
	 */
	public function getBoatDrops() : array{
		return [$this->getBoatItem()];
	}

	/**
	 * Breaks the boat: drops its item (if $drops and the doentitydrops game rule allows it) and removes it. A chest boat drops its contents in any case.
	 */
	public function breakBoat(bool $drops) : void{
		if($this->broken){
			return;
		}
		$this->broken = true;
		$this->unleash(true);
		if($drops && GameRules::allowsEntityDrops($this->getWorld())){
			$world = $this->getWorld();
			$pos = $this->location->add(0, 0.3, 0);
			foreach($this->getBoatDrops() as $item){
				if($this->getNameTag() !== ""){
					$item->setCustomName($this->getNameTag());
				}
				$world->dropItem($pos, $item);
			}
		}
		$this->kill();
	}

	protected function onDeathUpdate(int $tickDiff) : bool{
		return true;
	}

	// ---- riding ----

	public function getRideableSeats() : array{
		//vanilla rideable: one rider sits in the middle, two riders front and back; rafts are higher
		$y = $this->variant->isRaft() ? 0.1 : -0.2;
		if(count($this->passengers) < 2){
			return [
				new RideableSeat(new Vector3(0, $y, 0), 90.0, null, null, -90.0),
				new RideableSeat(new Vector3(-0.6, $y, 0), 90.0, null, null, $this->getRiderRotation(1))
			];
		}
		return [
			new RideableSeat(new Vector3(0.2, $y, 0), 90.0, null, null, $this->getRiderRotation(0)),
			new RideableSeat(new Vector3(-0.6, $y, 0), 90.0, null, null, $this->getRiderRotation(1))
		];
	}

	/**
	 * Vanilla rotate_rider_by of a seat shared by two riders: people and monsters face forwards (-90), animals sit
	 * across the boat.
	 */
	protected function getRiderRotation(int $seat) : float{
		$passenger = $this->passengers[$seat] ?? null;
		return $passenger instanceof Animal ? 0.0 : -90.0;
	}

	public function canBeRiddenBy(Entity $entity) : bool{
		return $entity instanceof Living &&
			$entity->getSize()->getWidth() < self::MAX_PASSENGER_WIDTH &&
			!$entity instanceof EnderDragon && !$entity instanceof Wither;
	}

	public function canAddPassenger(Entity $entity) : bool{
		return parent::canAddPassenger($entity) && $this->outOfControlTicks < self::OUT_OF_CONTROL_TICKS;
	}

	public function getControllingPassenger() : ?Entity{
		//only players steer boats (mobs just sit in them)
		$first = $this->passengers[0] ?? null;
		return $first instanceof Player ? $first : null;
	}

	public function getInteractButtonText(Player $player) : string{
		return !$player->isSneaking() && $this->canAddPassenger($player) ? "action.interact.ride.boat" : "";
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		if($player->isSpectator() || !$this->isAlive()){
			return false;
		}
		if($player->getVehicle() !== $this){
			//tying the mobs the player leads, cutting leads with shears, leashing the boat or letting it go
			$item = $player->getInventory()->getItemInHand();
			if(LeashInteractions::interact($this, $player, $item) || $this->interactLeash($player, $item)){
				return true;
			}
		}
		if($player->isSneaking() || $player->getVehicle() === $this){
			return false;
		}
		return $player->mount($this, true);
	}

	// ---- leads ----

	public function canBeLeashed() : bool{
		return true;
	}

	/**
	 * Bedrock boat leashable presets: soft 2 / hard 4, or the default distances (4 / 6) with the quad_dampened spring
	 * when a happy ghast holds the lead.
	 */
	private function isTowedByHappyGhast() : bool{
		return $this->getLeashHolder() instanceof HappyGhast;
	}

	protected function getLeashFollowDistance() : float{
		return $this->isTowedByHappyGhast() ? 4.0 : 2.0;
	}

	protected function getLeashElasticDistance() : float{
		return $this->isTowedByHappyGhast() ? 6.0 : 4.0;
	}

	/**
	 * Between the soft and the hard distance the lead draws the boat gently towards its holder (more the further it
	 * is), unless a player steers it.
	 */
	private function followLeashHolder(Entity $holder) : void{
		if($this->isMovedByClient()){
			return;
		}
		$dx = $holder->getPosition()->x - $this->location->x;
		$dy = $holder->getPosition()->y - $this->location->y;
		$dz = $holder->getPosition()->z - $this->location->z;
		$distance = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
		if($distance < 0.01){
			return;
		}
		$pull = min(self::LEASH_SPRING_MAX, ($distance - $this->getLeashFollowDistance()) * self::LEASH_SPRING) / $distance;
		$this->motion = $this->motion->add($dx * $pull, 0, $dz * $pull);
	}

	private function stopFollowingLeashHolder() : void{
		//the elastic pull is all there is
	}

	/**
	 * Whether the lead keeps an otherwise idle boat awake: a holder that can move (player, mob), a knot the boat is
	 * still drawn to, or a fence or mob holder being looked for. A boat tied to a knot it rests by, or whose player
	 * holder is offline or in another world, sleeps: knots let go themselves when they break, motion wakes the boat
	 * and {@link Boat::spawnTo()} wakes it when its player comes back.
	 */
	private function needsLeashTicks() : bool{
		$holder = $this->getLeashHolder();
		if($holder instanceof LeashKnot){
			return $holder->getPosition()->distanceSquared($this->location) > $this->getLeashFollowDistance() ** 2;
		}
		if($holder !== null || $this->leashPlayerUuid === null){
			return true;
		}
		if(($this->server->getTick() + $this->id) % 20 !== 0){
			return true;
		}
		//the player is in this world (e.g. dead, about to respawn): keep looking for them once a second
		foreach($this->getWorld()->getPlayers() as $player){
			if($player->getUniqueId()->toString() === $this->leashPlayerUuid){
				return true;
			}
		}
		return false;
	}

	public function spawnTo(Player $player) : void{
		parent::spawnTo($player);
		if($this->getLeashHolder() === null && $this->leashPlayerUuid !== null && $player->getUniqueId()->toString() === $this->leashPlayerUuid){
			//the player leading this sleeping boat is back: look for them again
			$this->scheduleUpdate();
		}
	}

	protected function consumeHeldItem(Player $player, int $count = 1) : void{
		if(!$player->hasFiniteResources()){
			return;
		}
		$item = $player->getInventory()->getItemInHand();
		$item->pop($count);
		$player->getInventory()->setItemInHand($item);
	}

	protected function onPassengerAdded(Entity $passenger) : void{
		parent::onPassengerAdded($passenger);
		if($passenger instanceof Player && !($this->passengers[0] ?? null) instanceof Player){
			//a player getting in a boat a mob sits in takes the front seat and steers (vanilla)
			$this->movePassengerToFront($passenger);
		}
		//the seats move when a second passenger gets in
		$this->refreshPassengerSeats();
		$this->networkPropertiesDirty = true;
		$this->scheduleUpdate();
	}

	protected function onPassengerRemoved(Entity $passenger) : void{
		parent::onPassengerRemoved($passenger);
		$this->refreshPassengerSeats();
		$this->networkPropertiesDirty = true;
		if($this->getControllingPassenger() === null){
			$this->riderInput = null;
			$this->clientMovedTick = -1000;
		}
	}

	public function getPassengerPosition(Entity $passenger) : Vector3{
		//vanilla seats are relative to the network position of the boat
		return parent::getPassengerPosition($passenger)->add(0, self::NETWORK_Y_OFFSET, 0);
	}

	/**
	 * Called every tick with the input of the player steering this boat.
	 */
	public function handleRiderInput(Player $rider, RiderInput $input) : void{
		if($this->getControllingPassenger() === $rider){
			$this->riderInput = $input;
			$this->riderInputTick = $this->server->getTick();
		}
	}

	/**
	 * Returns the last input of the player steering this boat, or null if it sent none recently.
	 */
	private function getRecentInput() : ?RiderInput{
		if($this->riderInput !== null && $this->server->getTick() - $this->riderInputTick <= self::INPUT_TIMEOUT && $this->getControllingPassenger() !== null){
			return $this->riderInput;
		}
		return null;
	}

	/**
	 * Returns whether the client of the driver moves this boat itself: the server then doesn't move it and doesn't
	 * send its movement back to that client.
	 */
	public function isMovedByClient() : bool{
		return $this->server->getTick() - $this->clientMovedTick <= self::INPUT_TIMEOUT && $this->getControllingPassenger() !== null;
	}

	/**
	 * Called when the client of the driver moved this boat (client-predicted vehicle). Accepts the movement if the
	 * player steers the boat, the distance is plausible and the boat doesn't end up inside blocks.
	 */
	public function handleClientVehicleMovement(Player $rider, Vector3 $pos, float $yaw, float $pitch) : bool{
		if($this->getControllingPassenger() !== $rider || !$this->isAlive() || $this->broken){
			return false;
		}
		$world = $this->getWorld();
		if($pos->distanceSquared($this->location) > self::MAX_CLIENT_MOVE ** 2 || !$world->isInLoadedTerrain($pos)){
			return false;
		}
		$bb = $this->boxAt($pos);
		//a little tolerance for client physics
		if($world->hasCollisionBlocks($bb->contractedCopy(0.2, 0.1, 0.2))){
			return false;
		}
		$this->clientMovedTick = $this->server->getTick();
		$this->setPositionAndRotation($pos, $yaw, 0.0);
		$this->motion = Vector3::zero();
		$this->onGround = $world->hasCollisionBlocks(new AxisAlignedBB($bb->minX, $pos->y - 0.1, $bb->minZ, $bb->maxX, $pos->y, $bb->maxZ));
		$this->resetFallDistance();
		return true;
	}

	/**
	 * Sends the boat's position to the client of its driver, which moved it somewhere it was not allowed to go.
	 */
	public function resyncClientVehicle(Player $rider) : void{
		$rider->getNetworkSession()->sendDataPacket(MoveActorAbsolutePacket::create(
			$this->id,
			$this->getOffsetPosition($this->location),
			$this->location->pitch,
			$this->location->yaw,
			0.0,
			MoveActorAbsolutePacket::FLAG_TELEPORT | MoveActorAbsolutePacket::FLAG_FORCE_MOVE_LOCAL_ENTITY
		));
	}

	private function boxAt(Vector3 $pos) : AxisAlignedBB{
		$halfWidth = $this->size->getWidth() / 2;
		return new AxisAlignedBB($pos->x - $halfWidth, $pos->y, $pos->z - $halfWidth, $pos->x + $halfWidth, $pos->y + $this->size->getHeight(), $pos->z + $halfWidth);
	}

	// ---- physics ----

	public function hasMovementUpdate() : bool{
		return parent::hasMovementUpdate() || ($this->isAlive() && count($this->passengers) > 0);
	}

	protected function tryChangeMovement() : void{
		$this->oldStatus = $this->status;
		$this->status = $this->computeStatus();
		if($this->status === self::STATUS_UNDER_WATER || $this->status === self::STATUS_UNDER_FLOWING_WATER){
			++$this->outOfControlTicks;
			if($this->outOfControlTicks >= self::OUT_OF_CONTROL_TICKS && count($this->passengers) > 0){
				$this->ejectPassengers();
			}
		}else{
			$this->outOfControlTicks = 0;
		}

		if($this->isMovedByClient()){
			//the client of the driver moves the boat (see handleClientVehicleMovement())
			$this->motion = Vector3::zero();
			$this->deltaRotation = 0.0;
			return;
		}

		$this->floatBoat();
		$input = $this->getRecentInput();
		if($input !== null){
			//a driver whose client doesn't move the boat itself: the server steers it (vanilla controlBoat())
			$this->controlBoat($input);
		}
	}

	/**
	 * Height of the water surface in a block (Java FluidState.getHeight()), or null if there is no water.
	 */
	private function getWaterHeight(int $x, int $y, int $z) : ?float{
		$world = $this->getWorld();
		$block = $world->getBlockAt($x, $y, $z);
		if(!$block instanceof Water){
			return null;
		}
		if($world->getBlockAt($x, $y + 1, $z) instanceof Water){
			return 1.0;
		}
		return $block->isFalling() ? 8 / 9 : (8 - $block->getDecay()) / 9;
	}

	/**
	 * Vanilla AbstractBoat.getStatus().
	 */
	private function computeStatus() : int{
		$underwater = $this->getUnderwaterStatus();
		if($underwater !== null){
			$this->waterLevel = $this->boundingBox->maxY;
			return $underwater;
		}
		if($this->checkInWater()){
			return self::STATUS_IN_WATER;
		}
		$friction = $this->getGroundFriction();
		if($friction > 0){
			$this->landFriction = $friction;
			return self::STATUS_ON_LAND;
		}
		return self::STATUS_IN_AIR;
	}

	/**
	 * Vanilla AbstractBoat.checkInWater(): whether the bottom of the boat is in water; also finds the water level.
	 */
	private function checkInWater() : bool{
		$bb = $this->boundingBox;
		$minX = (int) floor($bb->minX);
		$maxX = (int) ceil($bb->maxX);
		$minY = (int) floor($bb->minY);
		$maxY = (int) ceil($bb->minY + 0.001);
		$minZ = (int) floor($bb->minZ);
		$maxZ = (int) ceil($bb->maxZ);
		$inWater = false;
		$this->waterLevel = -PHP_FLOAT_MAX;
		for($x = $minX; $x < $maxX; ++$x){
			for($y = $minY; $y < $maxY; ++$y){
				for($z = $minZ; $z < $maxZ; ++$z){
					$height = $this->getWaterHeight($x, $y, $z);
					if($height === null){
						continue;
					}
					$surface = $y + $height;
					$this->waterLevel = max($surface, $this->waterLevel);
					$inWater = $inWater || $bb->minY < $surface;
				}
			}
		}
		return $inWater;
	}

	/**
	 * Vanilla AbstractBoat.isUnderwater(): UNDER_WATER / UNDER_FLOWING_WATER when the top of the boat is under water.
	 */
	private function getUnderwaterStatus() : ?int{
		$bb = $this->boundingBox;
		$maxY = $bb->maxY + 0.001;
		$x0 = (int) floor($bb->minX);
		$x1 = (int) ceil($bb->maxX);
		$y0 = (int) floor($bb->maxY);
		$y1 = (int) ceil($maxY);
		$z0 = (int) floor($bb->minZ);
		$z1 = (int) ceil($bb->maxZ);
		$underWater = false;
		$world = $this->getWorld();
		for($x = $x0; $x < $x1; ++$x){
			for($y = $y0; $y < $y1; ++$y){
				for($z = $z0; $z < $z1; ++$z){
					$height = $this->getWaterHeight($x, $y, $z);
					if($height === null || $maxY >= $y + $height){
						continue;
					}
					$block = $world->getBlockAt($x, $y, $z);
					if(!$block instanceof Water || !$block->isSource()){
						return self::STATUS_UNDER_FLOWING_WATER;
					}
					$underWater = true;
				}
			}
		}
		return $underWater ? self::STATUS_UNDER_WATER : null;
	}

	/**
	 * Vanilla AbstractBoat.getWaterLevelAbove(): the water surface above the boat, when it comes up from below.
	 */
	private function getWaterLevelAbove() : float{
		$bb = $this->boundingBox;
		$minX = (int) floor($bb->minX);
		$maxX = (int) ceil($bb->maxX);
		$minY = (int) floor($bb->maxY);
		$maxY = (int) ceil($bb->maxY - $this->lastYd);
		$minZ = (int) floor($bb->minZ);
		$maxZ = (int) ceil($bb->maxZ);
		for($y = $minY; $y < $maxY; ++$y){
			$blockHeight = 0.0;
			for($x = $minX; $x < $maxX; ++$x){
				for($z = $minZ; $z < $maxZ; ++$z){
					$height = $this->getWaterHeight($x, $y, $z);
					if($height !== null){
						$blockHeight = max($blockHeight, $height);
					}
					if($blockHeight >= 1.0){
						continue 3;
					}
				}
			}
			return $y + $blockHeight;
		}
		return $maxY + 1;
	}

	/**
	 * Vanilla AbstractBoat.getGroundFriction(): average friction of the blocks the bottom of the boat rests on (0 if
	 * none). Lily pads don't count.
	 */
	private function getGroundFriction() : float{
		$bb = $this->boundingBox;
		$box = new AxisAlignedBB($bb->minX, $bb->minY - 0.001, $bb->minZ, $bb->maxX, $bb->minY, $bb->maxZ);
		$friction = 0.0;
		$count = 0;
		foreach($this->getWorld()->getCollisionBlocks($box) as $block){
			if($block instanceof WaterLily){
				continue;
			}
			$friction += $block->getFrictionFactor();
			++$count;
		}
		return $count > 0 ? $friction / $count : 0.0;
	}

	/**
	 * Vanilla AbstractBoat.floatBoat().
	 */
	private function floatBoat() : void{
		$vspeed = -$this->gravity;
		$buoyancy = 0.0;
		$invFriction = 0.05;
		if($this->oldStatus === self::STATUS_IN_AIR && $this->status !== self::STATUS_IN_AIR && $this->status !== self::STATUS_ON_LAND){
			//fell into water: put it on the surface
			$this->waterLevel = $this->location->y + $this->size->getHeight();
			$targetY = $this->getWaterLevelAbove() - $this->size->getHeight() + 0.101;
			$target = new Vector3($this->location->x, $targetY, $this->location->z);
			if(count($this->getWorld()->getCollisionBlocks($this->boxAt($target))) === 0){
				$this->setPosition($target);
				$this->motion = $this->motion->withComponents(null, 0, null);
				$this->lastYd = 0.0;
			}
			$this->status = self::STATUS_IN_WATER;
			return;
		}
		switch($this->status){
			case self::STATUS_IN_WATER:
				$buoyancy = ($this->waterLevel - $this->location->y) / $this->size->getHeight();
				$invFriction = 0.9;
				break;
			case self::STATUS_UNDER_FLOWING_WATER:
				$vspeed = -0.0007;
				$invFriction = 0.9;
				break;
			case self::STATUS_UNDER_WATER:
				//Bedrock boats are buoyant (minecraft:buoyant): a sunken boat comes back up, where Java's slowly sinks
				$vspeed = 0.0;
				$buoyancy = 0.01;
				$invFriction = 0.45;
				$this->motion = $this->motion->withComponents(null, $this->motion->y * 0.75 + self::UNDER_WATER_RISE_SPEED, null);
				break;
			case self::STATUS_IN_AIR:
				$invFriction = 0.9;
				break;
			case self::STATUS_ON_LAND:
				$invFriction = $this->landFriction;
				break;
		}
		$m = $this->motion;
		$my = $m->y + $vspeed;
		$this->deltaRotation *= $invFriction;
		if($buoyancy > 0){
			$my = ($my + $buoyancy * ($this->gravity / 0.65)) * 0.75;
		}
		$this->motion = new Vector3($m->x * $invFriction, $my, $m->z * $invFriction);
	}

	/**
	 * Vanilla AbstractBoat.controlBoat(), for a driver whose client doesn't move the boat itself. The Bedrock yaw of a
	 * boat is 90 degrees more than its Java yaw (its model faces its local X axis).
	 */
	private function controlBoat(RiderInput $input) : void{
		$left = $input->strafe > 0.35;
		$right = $input->strafe < -0.35;
		$up = $input->forward > 0.35;
		$down = $input->forward < -0.35;
		$acceleration = 0.0;
		if($left){
			$this->deltaRotation -= 1;
		}
		if($right){
			$this->deltaRotation += 1;
		}
		if($right !== $left && !$up && !$down){
			$acceleration += 0.005;
		}
		$yaw = fmod($this->location->yaw + $this->deltaRotation, 360);
		if($yaw < 0){
			$yaw += 360;
		}
		$this->location->yaw = $yaw;
		if($up){
			$acceleration += 0.04;
		}
		if($down){
			$acceleration -= 0.005;
		}
		$javaYaw = deg2rad($yaw - 90);
		$this->motion = $this->motion->add(-sin($javaYaw) * $acceleration, 0, cos($javaYaw) * $acceleration);
		$this->paddlingLeft = ($right && !$left) || $up;
		$this->paddlingRight = ($left && !$right) || $up;
	}

	/**
	 * Direction the boat moves when paddling forwards (its Bedrock yaw minus 90 degrees).
	 */
	public function getForwardDirection() : Vector3{
		$javaYaw = deg2rad($this->location->yaw - 90);
		return new Vector3(-sin($javaYaw), 0, cos($javaYaw));
	}

	protected function move(float $dx, float $dy, float $dz) : void{
		parent::move($dx, $dy, $dz);
		$this->lastYd = $this->motion->y;
	}

	protected function updateFallState(float $distanceThisTick, bool $onGround) : ?float{
		//Bedrock boats don't break when they fall, and break the fall of their passengers
		$this->resetFallDistance();
		foreach($this->passengers as $passenger){
			$passenger->resetFallDistance();
		}
		return null;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->hurtTime > 0){
			$this->hurtTime = max(0, $this->hurtTime - $tickDiff);
			$this->networkPropertiesDirty = true;
			$hasUpdate = true;
		}
		if($this->damage > 0){
			$this->setDamage($this->damage - $tickDiff);
			$hasUpdate = true;
		}
		if($this->closed || $this->isFlaggedForDespawn()){
			return $hasUpdate;
		}
		if($this->isLeashed()){
			$this->tickLeash();
			if($this->closed || $this->isFlaggedForDespawn()){
				return $hasUpdate;
			}
			$hasUpdate = $hasUpdate || $this->needsLeashTicks();
		}
		$this->tickPaddles();
		$this->pushAndPickupEntities();
		$this->breakLilyPads();
		return $hasUpdate;
	}

	/**
	 * Animates the paddles for the players watching (the driver's client animates its own boat).
	 */
	private function tickPaddles() : void{
		if($this->getControllingPassenger() === null){
			$this->paddlingLeft = $this->paddlingRight = false;
		}elseif($this->isMovedByClient()){
			$input = $this->getRecentInput();
			$this->paddlingLeft = $input !== null && $input->paddlingLeft;
			$this->paddlingRight = $input !== null && $input->paddlingRight;
		}
		$left = $this->paddlingLeft ? $this->paddleTimeLeft + self::PADDLE_SPEED : 0.0;
		$right = $this->paddlingRight ? $this->paddleTimeRight + self::PADDLE_SPEED : 0.0;
		if($left !== $this->paddleTimeLeft || $right !== $this->paddleTimeRight){
			$this->paddleTimeLeft = $left;
			$this->paddleTimeRight = $right;
			$this->networkPropertiesDirty = true;
		}
	}

	/**
	 * Returns the animation phase of a paddle in radians (0 when it doesn't move).
	 */
	public function getPaddleTime(bool $left) : float{
		return $left ? $this->paddleTimeLeft : $this->paddleTimeRight;
	}

	/**
	 * Vanilla AbstractBoat.tick() end: mobs bumping into a boat nobody steers get in (Bedrock pull_in_entities), boats
	 * and mobs push each other, players push a boat they walk into.
	 */
	protected function pushAndPickupEntities() : void{
		$box = $this->boundingBox->expandedCopy(0.2, -0.01, 0.2);
		$pickUp = !$this->getControllingPassenger() instanceof Player;
		foreach($this->getWorld()->getNearbyEntities($box, $this) as $entity){
			if($entity->isClosed() || !$entity->isAlive() || $entity->isFlaggedForDespawn() || $this->hasPassenger($entity) || $entity->hasPassenger($this) || $entity->getVehicle() !== null){
				continue;
			}
			if($pickUp && $this->canPickUp($entity) && $entity->mount($this)){
				continue;
			}
			if($entity instanceof Boat){
				$this->pushApart($entity);
			}elseif($entity instanceof Player){
				//players push a boat with their own collision box (they move themselves)
				if(!$entity->isSpectator() && $entity->getBoundingBox()->intersectsWith($this->boundingBox)){
					$this->pushApart($entity);
				}
			}elseif($entity instanceof Living && $entity->getBoundingBox()->minY <= $this->boundingBox->minY){
				$this->pushApart($entity);
			}
		}
	}

	/**
	 * Whether the entity gets in this boat when they bump into each other.
	 */
	protected function canPickUp(Entity $entity) : bool{
		return $entity instanceof Mob &&
			!$entity instanceof AbstractFish && !$entity instanceof Dolphin && !$entity instanceof Tadpole &&
			!$entity instanceof Creaking &&
			$this->canAddPassenger($entity);
	}

	/**
	 * Vanilla Entity.push(Entity): both are pushed away from each other, unless they carry passengers (players move
	 * themselves).
	 */
	private function pushApart(Entity $entity) : void{
		$dx = $entity->location->x - $this->location->x;
		$dz = $entity->location->z - $this->location->z;
		$dd = max(abs($dx), abs($dz));
		if($dd < 0.01){
			return;
		}
		$dd = sqrt($dd);
		$dx /= $dd;
		$dz /= $dd;
		$pow = min(1.0, 1 / $dd);
		$dx *= $pow * 0.05;
		$dz *= $pow * 0.05;
		if(count($this->passengers) === 0){
			$this->motion = $this->motion->add(-$dx, 0, -$dz);
		}
		if(!$entity instanceof Player && count($entity->getPassengers()) === 0){
			$entity->setMotion($entity->getMotion()->add($dx, 0, $dz));
			$entity->scheduleUpdate();
		}
	}

	/**
	 * Boats break the lily pads they run into (vanilla LilyPadBlock.entityInside()).
	 */
	private function breakLilyPads() : void{
		if($this->motion->x === 0.0 && $this->motion->z === 0.0 && !$this->isMovedByClient()){
			return;
		}
		$world = $this->getWorld();
		$bb = $this->boundingBox->expandedCopy(0.1, 0.1, 0.1);
		for($x = (int) floor($bb->minX); $x <= (int) floor($bb->maxX); ++$x){
			for($y = (int) floor($bb->minY); $y <= (int) floor($bb->maxY); ++$y){
				for($z = (int) floor($bb->minZ); $z <= (int) floor($bb->maxZ); ++$z){
					if($world->getBlockAt($x, $y, $z) instanceof WaterLily){
						$world->useBreakOn(new Vector3($x, $y, $z));
					}
				}
			}
		}
	}

	// ---- network ----

	public function getOffsetPosition(Vector3 $vector3) : Vector3{
		return $vector3->add(0, self::NETWORK_Y_OFFSET, 0);
	}

	public function getHeadYaw() : float{
		//MoveActorAbsolute: the head yaw of things that aren't mobs is 0
		return 0.0;
	}

	protected function broadcastMovement(bool $teleport = false) : void{
		$rider = $this->getControllingPassenger();
		if($rider instanceof Player && $this->isMovedByClient()){
			//its client moves the boat: sending the movement back would fight its prediction
			$targets = $this->hasSpawned;
			unset($targets[spl_object_id($rider)]);
			NetworkBroadcastUtils::broadcastPackets($targets, [$this->createMovementPacket()]);
			return;
		}
		parent::broadcastMovement($teleport);
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->variant->value);
		$properties->setByte(EntityMetadataProperties::IS_BUOYANT, 1);
		$properties->setString(EntityMetadataProperties::BUOYANCY_DATA, self::BUOYANCY_DATA);
		$properties->setInt(EntityMetadataProperties::HURT_TIME, $this->hurtTime);
		$properties->setInt(EntityMetadataProperties::HURT_DIRECTION, $this->hurtDirection);
		//structural integrity: what is left of the 40 points of the boat (the hurt animation rocks it by 40 - this)
		$properties->setInt(EntityMetadataProperties::HEALTH, (int) max(0, self::BREAK_DAMAGE - $this->damage));
		$properties->setFloat(EntityMetadataProperties::PADDLE_TIME_LEFT, $this->paddleTimeLeft);
		$properties->setFloat(EntityMetadataProperties::PADDLE_TIME_RIGHT, $this->paddleTimeRight);
		$properties->setGenericFlag(EntityMetadataFlags::COLLIDABLE, true);
		$this->syncLeashData($properties);
		if($this->getControllingPassenger() instanceof Player){
			//without NO_AI cleared the driver's client doesn't move the boat (see Mob::syncNetworkData())
			$properties->setGenericFlag(EntityMetadataFlags::NO_AI, false);
		}
	}
}
