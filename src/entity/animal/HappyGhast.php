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

use pocketmine\entity\Ageable;
use pocketmine\entity\ai\control\FlyingMoveControl;
use pocketmine\entity\ai\FlyingMobTrait;
use pocketmine\entity\ai\goal\FollowMobGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\TemptGoal;
use pocketmine\entity\ai\navigation\PathNavigator;
use pocketmine\entity\ai\navigation\RandomPos;
use pocketmine\entity\ai\navigation\StraightLineNavigator;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\riding\RideableSeat;
use pocketmine\entity\riding\RiderInput;
use pocketmine\item\Harness;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\MobArmorEquipmentPacket;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\vibration\GameEvent;
use function abs;
use function cos;
use function count;
use function deg2rad;
use function fmod;
use function round;
use function sin;

/**
 * Bedrock values (entities/happy_ghast.json, 1.21.90): 20 health, 4x4, babies (ghastlings) scale 0.2375. Floats
 * around slowly (wandering up to 16 blocks around; Bedrock flying speed 0.016, raised here to Java's 0.05 so it
 * visibly moves), ghastlings fly faster (0.083). Adults are tempted (float_tempt, within 16 blocks, stopping 7 away)
 * by snowballs, and by harnesses while they have none. Ghastlings are tempted by snowballs (speed 1.25), panic when
 * hurt (2.0) and follow players, or else grown-up passive mobs not underwater (follow_mob: 16 blocks, stopping 5 away).
 * Feeding a ghastling a snowball takes 10% off its 20 minutes of growing up. Not breedable. No loot; XP 1-3. Ghastlings
 * hatch from dried ghasts kept in water (see {@link \pocketmine\block\DriedGhast}).
 *
 * Harness: using one on an adult puts it on (shown in the body equipment slot, dropped on death; shears take it off
 * when nobody rides). A harnessed happy ghast carries four players (seats 3.8 up on its four sides, third person camera
 * 8 blocks away) who get off on top of it. The first rider steers it with free camera controls: it flies where the
 * rider looks, jump goes up (vanilla vertical_movement_action 0.5), backwards at half speed; the server moves it at
 * 0.18 blocks per tick when the client doesn't.
 *
 * Standing on it: while a player (not riding it) is on top, an adult holds still (vanilla entity_sensor and
 * adult_immobile: can_move property false, no movement or knockback, collidable for the clients, axis-aligned). A rider
 * getting off is put on top, so the happy ghast becomes solid before the rider is moved there.
 *
 * Home (minecraft:home, random_movement): it wanders within 64 blocks of its home (32 harnessed, and for ghastlings).
 * The home is where it spawned, and is moved to where it is harnessed or unharnessed, unleashed, left by a rider or
 * stops following a tempting player.
 */
class HappyGhast extends Animal{
	use FlyingMobTrait;

	private const ADULT_SPEED = 0.05;
	private const BABY_SPEED = 0.0833;
	private const RIDDEN_SPEED = 0.18;
	private const SENSOR_Y_OFFSET = 4.5;
	public const TAG_HARNESS = "Harness"; //TAG_Compound (item)
	/** minecraft:home restriction_radius of unharnessed adults, and of harnessed adults and ghastlings. */
	public const HOME_RADIUS_UNHARNESSED = 64;
	public const HOME_RADIUS_SMALL = 32;
	/**
	 * Mobs ghastlings follow besides players (follow_mob family filters of happy_ghast.json).
	 */
	private const GHASTLING_FOLLOWED = [
		EntityIds::ARMADILLO => true,
		EntityIds::BEE => true,
		EntityIds::CAMEL => true,
		EntityIds::CAT => true,
		EntityIds::CHICKEN => true,
		EntityIds::COW => true,
		EntityIds::DONKEY => true,
		EntityIds::FOX => true,
		EntityIds::GOAT => true,
		EntityIds::HAPPY_GHAST => true,
		EntityIds::HORSE => true,
		EntityIds::SKELETON_HORSE => true,
		EntityIds::LLAMA => true,
		EntityIds::TRADER_LLAMA => true, //family llama
		EntityIds::MULE => true,
		EntityIds::OCELOT => true,
		EntityIds::PANDA => true,
		EntityIds::PARROT => true,
		EntityIds::PIG => true,
		EntityIds::POLAR_BEAR => true,
		EntityIds::RABBIT => true,
		EntityIds::SHEEP => true,
		EntityIds::SNIFFER => true,
		EntityIds::STRIDER => true,
		EntityIds::VILLAGER => true,
		EntityIds::VILLAGER_V2 => true,
		EntityIds::WOLF => true
	];

	private ?Item $harness = null;
	private bool $immobile = false;

	public static function getNetworkTypeId() : string{ return EntityIds::HAPPY_GHAST; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(4.0, 4.0, 2.6);
	}

	public function getName() : string{
		return "Happy Ghast";
	}

	public function getSpawnBabyChance() : int{
		return 5; //entity_spawned: 95 adult / 5 baby (ghastling)
	}

	protected function getBabyScale() : float{
		return 0.2375;
	}

	protected function getFlyingSpeed() : float{
		return self::ADULT_SPEED;
	}

	/**
	 * Straight on, without path searches: the 4x4x4 body of an adult makes a 3D search (16 columns 4 blocks high per
	 * node) too expensive for the main thread, and it lives in the open sky; one that makes no progress gives up and
	 * picks another target.
	 */
	protected function createNavigation() : PathNavigator{
		return new StraightLineNavigator($this);
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(20);
		$this->setMovementSpeed(0.3);
		parent::initEntity($nbt);
		$this->initFlying();
		$this->setActorProperty("minecraft:can_move", true);
		if(($harnessTag = $nbt->getCompoundTag(self::TAG_HARNESS)) !== null){
			$harness = Item::nbtDeserialize($harnessTag);
			$this->harness = $harness->isNull() ? null : $harness;
		}
		if($this->getHomePos() === null){
			$this->setHomeHere();
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		if($this->harness !== null){
			$nbt->setTag(self::TAG_HARNESS, $this->harness->nbtSerialize());
		}
		return $nbt;
	}

	public function getHarness() : ?Item{
		return $this->harness !== null ? clone $this->harness : null;
	}

	public function setHarness(?Item $harness) : void{
		$wasHarnessed = $this->harness !== null;
		$this->harness = $harness !== null && !$harness->isNull() ? clone $harness : null;
		if($wasHarnessed !== ($this->harness !== null)){
			$this->setHomeHere(); //on_harnessed / on_unharnessed: set_home_position
		}
		if($this->harness === null){
			$this->ejectPassengers();
		}
		foreach($this->hasSpawned as $player){
			$this->sendHarness($player);
		}
		$this->networkPropertiesDirty = true;
	}

	private function sendHarness(Player $player) : void{
		$session = $player->getNetworkSession();
		$converter = $session->getTypeConverter();
		$air = new ItemStackWrapper(0, ItemStack::null());
		$session->sendDataPacket(MobArmorEquipmentPacket::create(
			$this->getId(),
			$air,
			$air,
			$air,
			$air,
			$this->harness !== null ? ItemStackWrapper::legacy($converter->coreItemStackToNet($this->harness)) : $air
		));
	}

	protected function sendSpawnPacket(Player $player) : void{
		parent::sendSpawnPacket($player);
		if($this->harness !== null){
			$this->sendHarness($player);
		}
	}

	public function getRideableSeats() : array{
		if($this->isBaby() || $this->harness === null){
			return [];
		}
		return [
			new RideableSeat(new Vector3(0, 3.8, 1.7), null, 8.0, 6.0),
			new RideableSeat(new Vector3(-1.7, 3.8, 0), null, 8.0, 6.0),
			new RideableSeat(new Vector3(0, 3.8, -1.7), null, 8.0, 6.0),
			new RideableSeat(new Vector3(1.7, 3.8, 0), null, 8.0, 6.0)
		];
	}

	public function canBeRiddenBy(Entity $entity) : bool{
		return $entity instanceof Player && !$this->isBaby() && $this->harness !== null;
	}

	public function canBeSteeredBy(Player $rider) : bool{
		return $this->harness !== null;
	}

	protected function isDrivenByRiderClient() : bool{
		return true;
	}

	public function getDismountPosition(Entity $passenger) : Vector3{
		//vanilla dismount_mode on_top_center; beside it when blocks leave no room on top
		$top = $this->location->add(0, $this->size->getHeight() + 0.01, 0);
		$halfWidth = $passenger->size->getWidth() / 2;
		$bb = new AxisAlignedBB($top->x - $halfWidth, $top->y, $top->z - $halfWidth, $top->x + $halfWidth, $top->y + $passenger->size->getHeight(), $top->z + $halfWidth);
		if(count($this->getWorld()->getCollisionBlocks($bb, true)) !== 0){
			return parent::getDismountPosition($passenger);
		}
		return $top;
	}

	/**
	 * Whether the happy ghast holds still because a player stands on it (vanilla can_move property false): it doesn't
	 * move, can't be knocked back and is solid for the clients, so players can stand on it.
	 */
	public function isImmobile() : bool{
		return $this->immobile;
	}

	/**
	 * An immobile happy ghast can't be pushed by other entities (pushable_by_entity isn't in adult_immobile).
	 */
	public function canBePushedBy(Entity $entity) : bool{
		return !$this->immobile && parent::canBePushedBy($entity);
	}

	public function canBeStoodOn() : bool{
		return $this->immobile && $this->isAlive();
	}

	/**
	 * Vanilla become_immobile / become_mobile events.
	 */
	public function setImmobile(bool $immobile) : void{
		if($this->immobile === $immobile){
			return;
		}
		$this->immobile = $immobile;
		$this->knockbackResistanceAttr->setValue($immobile ? 1.0 : 0.0);
		if($immobile){
			//vanilla stop_movement, rotation_axis_aligned
			$this->goalSelector->stopAll();
			$this->navigation->stop();
			$this->moveControl->stop();
			$this->motion = Vector3::zero();
			$this->location->yaw = $this->headYaw = fmod(round($this->location->yaw / 90) * 90, 360);
		}
		$this->networkPropertiesDirty = true;
		$this->setActorProperty("minecraft:can_move", !$immobile);
		if(!$this->closed){
			//now, not on the next tick: a dismounted rider is put on top right after this and must not fall through.
			//Only the changes, so it stays small and goes in the same batch as the unlink and the rider's teleport.
			$this->sendData(null, $this->getDirtyNetworkData());
		}
	}

	/**
	 * Counts the players standing on top of it (vanilla entity_sensor subsensors: 4.5 above its position, within the
	 * horizontal and vertical range, not riding a happy ghast, alive).
	 */
	private function countPlayersOnTop(float $horizontal, float $vertical) : int{
		$x = $this->location->x;
		$y = $this->location->y + self::SENSOR_Y_OFFSET;
		$z = $this->location->z;
		$count = 0;
		foreach($this->getWorld()->getPlayers() as $player){
			if(!$player->isAlive() || $player->isSpectator() || $player->getVehicle() instanceof HappyGhast){
				continue;
			}
			$pos = $player->getPosition();
			if($pos->world === $this->location->world && abs($pos->y - $y) <= $vertical && ($pos->x - $x) ** 2 + ($pos->z - $z) ** 2 <= $horizontal ** 2){
				++$count;
			}
		}
		return $count;
	}

	private function updateImmobile() : void{
		if($this->isBaby()){
			$this->setImmobile(false);
		}elseif(!$this->immobile){
			if($this->countPlayersOnTop(3.0, 1.5) > 0){
				$this->setImmobile(true);
			}
		}elseif($this->countPlayersOnTop(3.5, 2.0) === 0){
			$this->setImmobile(false);
		}
	}

	/**
	 * Home restriction (random_movement): 64 blocks for an adult without a harness, 32 with one and for ghastlings. A
	 * parked happy ghast stays around where it was left.
	 */
	public function getHomeRadius() : int{
		return $this->isBaby() || $this->harness !== null ? self::HOME_RADIUS_SMALL : self::HOME_RADIUS_UNHARNESSED;
	}

	protected function onUnleashed() : void{
		parent::onUnleashed();
		$this->setHomeHere(); //on_unleashed: set_home_position
	}

	protected function getLeashElasticDistance() : float{
		return 10.0; //leashable preset hard_distance
	}

	public function getLeashBreakDistance() : float{
		return 14.0; //leashable preset max_distance
	}

	protected function onPassengerRemoved(Entity $passenger) : void{
		parent::onPassengerRemoved($passenger);
		$this->setHomeHere(); //on_passenger_dismount: set_home_position
		if($passenger instanceof Player && $passenger->isAlive() && !$this->isBaby() && $this->isAlive()){
			//the rider is about to be put on top: be solid for its client first (the sensor lets go again on the next
			//tick if the rider ended up somewhere else)
			$this->setImmobile(true);
		}
	}

	public function knockBack(float $x, float $z, float $force = self::DEFAULT_KNOCKBACK_FORCE, ?float $verticalLimit = self::DEFAULT_KNOCKBACK_VERTICAL_LIMIT) : void{
		if(!$this->immobile){
			parent::knockBack($x, $z, $force, $verticalLimit);
		}
	}

	protected function tickAi(int $currentTick) : void{
		if(!$this->immobile){
			parent::tickAi($currentTick);
		}
	}

	protected function steer(Player $rider, RiderInput $input) : void{
		if($this->immobile){
			//vanilla movement 0 while someone stands on it
			$this->moveControl->stop();
			return;
		}
		$this->location->yaw = $input->yaw;
		$this->headYaw = $input->yaw;
		$forward = $input->forward < 0 ? $input->forward * 0.5 : $input->forward;
		$yaw = deg2rad($input->yaw);
		$pitch = deg2rad($input->pitch);
		//forwards follows the camera (pitch included), strafing stays horizontal
		$dx = -sin($yaw) * cos($pitch) * $forward + cos($yaw) * $input->strafe;
		$dy = -sin($pitch) * $forward + ($input->jumping ? 0.5 : 0.0);
		$dz = cos($yaw) * cos($pitch) * $forward + sin($yaw) * $input->strafe;
		if($dx * $dx + $dy * $dy + $dz * $dz < 0.0001){
			$this->moveControl->stop();
			return;
		}
		$loc = $this->location;
		$this->moveControl->setWantedPosition($loc->x + $dx * 4, $loc->y + $dy * 4, $loc->z + $dz * 4, self::RIDDEN_SPEED / self::ADULT_SPEED);
	}

	protected function getAdultDrops() : array{
		return $this->harness !== null ? [clone $this->harness] : [];
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::WASD_FREE_CAMERA_CONTROLLED, $this->harness !== null);
		//vanilla vertical_movement_action (harnessed): jumping makes it go up instead of getting the rider off
		$properties->setGenericFlag(EntityMetadataFlags::CAN_USE_VERTICAL_MOVEMENT_ACTION, $this->harness !== null && !$this->isBaby());
		$properties->setGenericFlag(EntityMetadataFlags::BODY_ROTATION_ALWAYS_FOLLOWS_HEAD, true);
		//sent by BDS (see GeyserMC's HappyGhastEntity): the client doesn't get off by itself (it would put the rider on
		//the ground first), it waits for the server to unlink it and put it on top
		$properties->setGenericFlag(EntityMetadataFlags::DOES_SERVER_AUTH_ONLY_DISMOUNT, true);
		//adult_immobile: is_collidable (players stand on it), body_rotation_blocked, rotation_axis_aligned
		$properties->setGenericFlag(EntityMetadataFlags::COLLIDABLE, $this->immobile);
		$properties->setGenericFlag(EntityMetadataFlags::BODY_ROTATION_BLOCKED, $this->immobile);
		$properties->setGenericFlag(EntityMetadataFlags::ROTATION_AXIS_ALIGNED, $this->immobile);
	}

	public function findWanderPos(int $horizontal, int $vertical, ?Vector3 $awayFrom = null) : ?Vector3{
		return RandomPos::getAirPos($this, $horizontal, $vertical, 16, $awayFrom);
	}

	protected function registerGoals() : void{
		//ghastlings: panic 2.0, tempt 1.25 within 16 blocks
		$this->goalSelector->addGoal(2, new PanicGoal($this, 2.0, fn() : bool => $this->isBaby()));
		$this->goalSelector->addGoal(3, new TemptGoal($this, 1.25, fn(Item $item) => $this->isBaby() && $item->getTypeId() === ItemTypeIds::SNOWBALL, 16.0, onStopTempting: $this->setHomeHere(...)));
		//adults: float_tempt within 16 blocks, stopping 7 blocks away; snowballs and harnesses without a harness, only
		//snowballs with one
		$this->goalSelector->addGoal(4, new TemptGoal($this, 1.0, fn(Item $item) => !$this->isBaby() && ($item->getTypeId() === ItemTypeIds::SNOWBALL || ($this->harness === null && $item instanceof Harness)), 16.0, 7.0, onStopTempting: $this->setHomeHere(...)));
		//ghastlings: follow_mob (players first, then grown-up passive mobs not underwater) within 16, stopping 5 away
		$this->goalSelector->addGoal(6, new FollowMobGoal($this, 1.1, 5.0, 16.0, self::isGhastlingFollowable(...), true, fn() : bool => $this->isBaby()));
		$this->goalSelector->addGoal(7, new RandomStrollGoal($this, 1.0, 100, 16, 4));
		$this->goalSelector->addGoal(8, new LookAtPlayerGoal($this, 8.0));
	}

	/**
	 * Whether a ghastling follows the entity (follow_mob filters): a player or one of the listed passive mob families,
	 * not a baby, not underwater.
	 */
	private static function isGhastlingFollowable(Living $entity) : bool{
		if(!$entity instanceof Player && !isset(self::GHASTLING_FOLLOWED[$entity::getNetworkTypeId()])){
			return false;
		}
		return !($entity instanceof Ageable && $entity->isBaby()) && !$entity->isUnderwater();
	}

	public function isBreedingItem(Item $item) : bool{
		return false;
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		return new HappyGhast($location);
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if($this->isBaby() && !$this->isAgeLocked() && $item->getTypeId() === ItemTypeIds::SNOWBALL){
			$this->consumeHeldItem($player);
			$this->ageUp($this->getFeedGrowth($item));
			$this->broadcastActorEvent(ActorEvent::BABY_ANIMAL_FEED);
			return true;
		}
		if($this->isBaby()){
			return false;
		}
		if($item instanceof Harness && $this->harness === null){
			$this->setHarness($item);
			$this->consumeHeldItem($player);
			$this->playMobSound(LevelSoundEvent::ARMOR_EQUIP_GENERIC);
			return true;
		}
		if($item->getTypeId() === ItemTypeIds::SHEARS && $this->harness !== null && count($this->passengers) === 0){
			$this->getWorld()->dropItem($this->location->add(0, 1, 0), $this->harness);
			$this->setHarness(null);
			$this->getWorld()->addGameEvent($this->location, GameEvent::SHEAR, $player);
			return true;
		}
		return $this->harness !== null && $this->tryRide($player);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->closed || !$this->isAlive()){
			return $hasUpdate;
		}
		$this->updateImmobile();
		$control = $this->moveControl;
		if($control instanceof FlyingMoveControl){
			$control->setFlyingSpeed($this->isBaby() ? self::BABY_SPEED : self::ADULT_SPEED);
		}
		return $hasUpdate;
	}
}
