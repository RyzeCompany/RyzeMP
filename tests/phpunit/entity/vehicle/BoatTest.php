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

use pocketmine\block\dispenser\DispenseBehaviorRegistry;
use pocketmine\block\dispenser\DispenseContext;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\AiBudget;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\animal\Pig;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Location;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\riding\RiderInput;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\SimpleInventory;
use pocketmine\item\BoatType;
use pocketmine\item\ChestBoat as ChestBoatItem;
use pocketmine\item\ItemUseResult;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\ByteMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\FloatMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\IntMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\MetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\Vec3MetadataProperty;
use pocketmine\player\Player;
use pocketmine\world\redstone\RedstoneTestCase;
use function abs;
use function array_filter;
use function array_values;
use function cos;
use function deg2rad;
use function sin;

/**
 * Boats and chest boats: placement, floating, seats, client-driven movement, breaking, chest and saved data.
 */
final class BoatTest extends RedstoneTestCase{

	/** Top of the water of the pool built by {@link BoatTest::pool()}: source blocks at y 1-3. */
	private const SURFACE = 3 + 8 / 9;

	/**
	 * Vehicles with mocked passengers.
	 * @var Entity[]
	 */
	private array $seated = [];

	protected function getPocketmineYml() : array{
		return [
			"redstone" => ["enabled" => true, "max-wire-updates-per-tick" => 1_000_000],
			"mobs" => ["enabled" => true, "spawning" => ["enabled" => false]]
		];
	}

	protected function setUp() : void{
		parent::setUp();
		AiBudget::configure(true, 100000, 100000, 400, requireNearbyPlayer: false);
		PathBlockTypes::clearCache();
		$this->floor(-24, -24, 24, 24);
	}

	/**
	 * A pool of still water, 3 deep (y 1-3), x/z from -6 to 6, inside stone walls.
	 */
	private function pool() : void{
		$water = VanillaBlocks::WATER();
		$stone = VanillaBlocks::STONE();
		for($x = -7; $x <= 7; ++$x){
			for($z = -7; $z <= 7; ++$z){
				$wall = $x === -7 || $x === 7 || $z === -7 || $z === 7;
				for($y = 1; $y <= 3; ++$y){
					$this->world->setBlockAt($x, $y, $z, $wall ? $stone : $water, false);
				}
			}
		}
	}

	private function boat(float $x, float $y, float $z, BoatVariant $variant = BoatVariant::OAK, float $yaw = 0.0) : Boat{
		$boat = new Boat(new Location($x, $y, $z, $this->world, $yaw, 0));
		$boat->setVariant($variant);
		$boat->spawnToAll();
		return $boat;
	}

	private function chestBoat(float $x, float $y, float $z, BoatVariant $variant = BoatVariant::OAK) : ChestBoat{
		$boat = new ChestBoat(new Location($x, $y, $z, $this->world, 0, 0));
		$boat->setVariant($variant);
		$boat->spawnToAll();
		return $boat;
	}

	/**
	 * A mocked player in the test world.
	 */
	private function player(float $yaw = 0.0) : Player{
		$player = $this->createMock(Player::class);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("getLocation")->willReturn(new Location(0.5, 4, -3.5, $this->world, $yaw, 0));
		$player->method("getEyePos")->willReturn(new Vector3(0.5, 5.62, -3.5));
		$player->method("isAlive")->willReturn(true);
		$player->method("getOffsetPosition")->willReturnCallback(fn(Vector3 $v) => $v->add(0, 1.621, 0));
		$player->method("getSeatedHeightOffset")->willReturn(1.62 * 0.75308642);
		//keep the mock from running Player::onDispose() when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	/**
	 * Puts the (mocked) player on the boat's first seat, as Entity::mount() does after its checks.
	 */
	private function seat(Player $player, Entity $vehicle) : void{
		(new \ReflectionProperty(Entity::class, "passengers"))->setValue($vehicle, [$player]);
		(new \ReflectionProperty(Entity::class, "vehicle"))->setValue($player, $vehicle);
		$this->seated[] = $vehicle;
	}

	protected function tearDown() : void{
		//mocked passengers can't be ejected when the boats are destroyed
		foreach($this->seated as $vehicle){
			(new \ReflectionProperty(Entity::class, "passengers"))->setValue($vehicle, []);
		}
		$this->seated = [];
		parent::tearDown();
	}

	/**
	 * @phpstan-template T of Entity
	 * @phpstan-param class-string<T> $class
	 * @phpstan-return list<T>
	 */
	private function entities(string $class) : array{
		return array_values(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof $class && !$e->isFlaggedForDespawn() && !$e->isClosed()));
	}

	/**
	 * @return MetadataProperty[]
	 * @phpstan-return array<int, MetadataProperty>
	 */
	private static function networkData(Entity $entity) : array{
		/** @phpstan-var array<int, MetadataProperty> $data */
		$data = (new \ReflectionMethod(Entity::class, "getAllNetworkData"))->invoke($entity);
		return $data;
	}

	private static function flag(Entity $entity, int $flag) : bool{
		$flags = self::networkData($entity)[$flag >= 64 ? EntityMetadataProperties::FLAGS2 : EntityMetadataProperties::FLAGS] ?? null;
		self::assertInstanceOf(LongMetadataProperty::class, $flags);
		return ($flags->getValue() & (1 << ($flag % 64))) !== 0;
	}

	private static function intProperty(Entity $entity, int $id) : int{
		$property = self::networkData($entity)[$id] ?? null;
		self::assertInstanceOf(IntMetadataProperty::class, $property);
		return $property->getValue();
	}

	private static function floatProperty(Entity $entity, int $id) : float{
		$property = self::networkData($entity)[$id] ?? null;
		self::assertInstanceOf(FloatMetadataProperty::class, $property);
		return $property->getValue();
	}

	private static function seatPosition(Entity $passenger) : Vector3{
		$property = self::networkData($passenger)[EntityMetadataProperties::RIDER_SEAT_POSITION] ?? null;
		self::assertInstanceOf(Vec3MetadataProperty::class, $property);
		return $property->getValue();
	}

	public function testFloatsOnTheWaterSurface() : void{
		$this->pool();
		$boat = $this->boat(0.5, 5, 0.5);
		$this->runTicks(200);
		$location = $boat->getLocation();
		//vanilla boats float with their bottom a little under the surface
		self::assertGreaterThan(self::SURFACE - 0.3, $location->y);
		self::assertLessThan(self::SURFACE, $location->y);
		self::assertEqualsWithDelta(0.5, $location->x, 1e-6, "doesn't drift");
		self::assertEqualsWithDelta(0.5, $location->z, 1e-6);
		self::assertLessThan(0.01, abs($boat->getMotion()->y));
		self::assertFalse($boat->isBroken(), "falls don't break boats");
	}

	public function testComesBackUpFromUnderWater() : void{
		$this->pool();
		$boat = $this->boat(0.5, 1.2, 0.5);
		$this->runTicks(300);
		self::assertGreaterThan(self::SURFACE - 0.3, $boat->getLocation()->y);
	}

	public function testSlidesFurtherOnIce() : void{
		$this->set(0, 0, 10, VanillaBlocks::STONE());
		for($x = -5; $x <= 30; ++$x){
			for($z = 11; $z <= 13; ++$z){
				$this->set($x, 0, $z, VanillaBlocks::BLUE_ICE());
			}
		}
		$onStone = $this->boat(0.5, 1, 0.5);
		$onIce = $this->boat(0.5, 1, 12.5);
		$this->runTicks(2);
		$onStone->setMotion(new Vector3(0.5, 0, 0));
		$onIce->setMotion(new Vector3(0.5, 0, 0));
		$onStone->scheduleUpdate();
		$onIce->scheduleUpdate();
		$this->runTicks(40);
		self::assertLessThan(2.0, $onStone->getLocation()->x - 0.5, "stone stops it at once");
		self::assertGreaterThan(5.0, $onIce->getLocation()->x - 0.5, "blue ice keeps 98.9% of the speed");
	}

	public function testItemPlacesBoatOnWaterFacingThePlayer() : void{
		$this->pool();
		$item = VanillaItems::SPRUCE_BOAT();
		$returned = [];
		$player = $this->player(30.0);
		self::assertSame(ItemUseResult::SUCCESS, $item->onInteractBlock($player, $this->get(2, 4, 2), $this->get(2, 3, 2), Facing::UP, new Vector3(0.25, 0.9, 0.75), $returned));
		self::assertSame(0, $item->getCount());
		$boats = $this->entities(Boat::class);
		self::assertCount(1, $boats);
		$boat = $boats[0];
		self::assertSame(BoatVariant::SPRUCE, $boat->getVariant());
		self::assertNotInstanceOf(ChestBoat::class, $boat);
		$location = $boat->getLocation();
		self::assertEqualsWithDelta(2.25, $location->x, 1e-9);
		self::assertEqualsWithDelta(self::SURFACE, $location->y, 1e-9, "on the surface");
		self::assertEqualsWithDelta(2.75, $location->z, 1e-9);
		//the boat model faces its local X axis: Bedrock yaw = player yaw + 90
		self::assertEqualsWithDelta(120.0, $location->yaw, 1e-9);
		$forward = $boat->getForwardDirection();
		$look = $player->getLocation();
		$lookYaw = deg2rad($look->yaw);
		self::assertEqualsWithDelta(0.0, $forward->subtractVector(new Vector3(-sin($lookYaw), 0, cos($lookYaw)))->length(), 1e-9, "forwards is where the player looked");
	}

	public function testItemPlacesBoatOnLandAndInTheAirAboveWater() : void{
		$returned = [];
		$item = VanillaItems::BAMBOO_RAFT();
		self::assertSame(ItemUseResult::SUCCESS, $item->onInteractBlock($this->player(), $this->get(5, 1, 5), $this->get(5, 0, 5), Facing::UP, new Vector3(0.5, 1, 0.5), $returned));
		$boats = $this->entities(Boat::class);
		self::assertCount(1, $boats);
		self::assertSame(BoatVariant::BAMBOO, $boats[0]->getVariant());
		self::assertEqualsWithDelta(1.0, $boats[0]->getLocation()->y, 1e-9);

		//no room: a wall right above the floor
		$this->set(9, 1, 9, VanillaBlocks::STONE());
		$this->set(9, 2, 9, VanillaBlocks::STONE());
		$this->set(9, 1, 10, VanillaBlocks::STONE());
		$this->set(9, 2, 10, VanillaBlocks::STONE());
		$blocked = VanillaItems::OAK_BOAT();
		self::assertSame(ItemUseResult::FAIL, $blocked->onInteractBlock($this->player(), $this->get(9, 1, 9), $this->get(9, 1, 9), Facing::NORTH, new Vector3(0.5, 0.5, 0), $returned));
		self::assertSame(1, $blocked->getCount());

		//clicking the air while looking down at water (clients may not target liquids)
		$this->pool();
		$chestBoat = VanillaItems::CHERRY_CHEST_BOAT();
		self::assertSame(ItemUseResult::SUCCESS, $chestBoat->onClickAir($this->player(), (new Vector3(0, -1, 1))->normalize(), $returned));
		$chestBoats = $this->entities(ChestBoat::class);
		self::assertCount(1, $chestBoats);
		self::assertSame(BoatVariant::CHERRY, $chestBoats[0]->getVariant());
		self::assertEqualsWithDelta(self::SURFACE, $chestBoats[0]->getLocation()->y, 1e-9);
	}

	public function testEveryWoodHasItsItemsAndVariant() : void{
		foreach(BoatType::cases() as $type){
			$item = match($type){
				BoatType::OAK => VanillaItems::OAK_BOAT(),
				BoatType::SPRUCE => VanillaItems::SPRUCE_BOAT(),
				BoatType::BIRCH => VanillaItems::BIRCH_BOAT(),
				BoatType::JUNGLE => VanillaItems::JUNGLE_BOAT(),
				BoatType::ACACIA => VanillaItems::ACACIA_BOAT(),
				BoatType::DARK_OAK => VanillaItems::DARK_OAK_BOAT(),
				BoatType::MANGROVE => VanillaItems::MANGROVE_BOAT(),
				BoatType::POPLAR => VanillaItems::POPLAR_BOAT(),
				BoatType::CHERRY => VanillaItems::CHERRY_BOAT(),
				BoatType::PALE_OAK => VanillaItems::PALE_OAK_BOAT(),
			};
			$variant = BoatVariant::fromBoatType($type);
			self::assertSame($variant, $item->getBoatVariant());
			self::assertTrue($variant->getBoatItem()->equals($item));
		}
		//Bedrock numbering of the variants
		$expected = ["OAK" => 0, "SPRUCE" => 1, "BIRCH" => 2, "JUNGLE" => 3, "ACACIA" => 4, "DARK_OAK" => 5, "MANGROVE" => 6, "BAMBOO" => 7, "CHERRY" => 8, "PALE_OAK" => 9, "POPLAR" => 10];
		foreach(BoatVariant::cases() as $variant){
			self::assertSame($expected[$variant->name], $variant->value);
			$chestItem = $variant->getChestBoatItem();
			self::assertInstanceOf(ChestBoatItem::class, $chestItem);
			self::assertSame($variant, $chestItem->getBoatVariant());
			self::assertSame(1, $chestItem->getMaxStackSize());
			$boat = $this->boat(0.5, 1, 0.5, $variant);
			self::assertSame($variant->value, self::intProperty($boat, EntityMetadataProperties::VARIANT));
			self::assertTrue($boat->getPickedItem()?->equals($variant->getBoatItem()) ?? false);
			$boat->flagForDespawn();
		}
		self::assertTrue(VanillaItems::BAMBOO_RAFT()->equals(BoatVariant::BAMBOO->getBoatItem()));
		self::assertTrue(VanillaItems::BAMBOO_CHEST_RAFT()->equals(BoatVariant::BAMBOO->getChestBoatItem()));
	}

	public function testNetworkData() : void{
		$boat = $this->boat(0.5, 1, 0.5, BoatVariant::MANGROVE);
		self::assertSame(EntityIds::BOAT, $boat::getNetworkTypeId());
		self::assertSame(EntityIds::CHEST_BOAT, ChestBoat::getNetworkTypeId());
		//Bedrock places boats by a point 0.375 above their bottom (Nukkit, GeyserMC)
		self::assertEqualsWithDelta(1.375, $boat->getOffsetPosition($boat->getLocation())->y, 1e-9);
		self::assertSame(6, self::intProperty($boat, EntityMetadataProperties::VARIANT));
		self::assertSame(40, self::intProperty($boat, EntityMetadataProperties::HEALTH));
		self::assertTrue(self::flag($boat, EntityMetadataFlags::COLLIDABLE), "players can stand on it");
		self::assertTrue(self::flag($boat, EntityMetadataFlags::NO_AI), "clients don't move it on their own");
		$buoyant = self::networkData($boat)[EntityMetadataProperties::IS_BUOYANT] ?? null;
		self::assertNotNull($buoyant);
		self::assertTrue($boat->canBeStoodOn());

		//the driver's client must be allowed to move it
		$player = $this->player();
		$this->seat($player, $boat);
		(new \ReflectionProperty(Entity::class, "networkPropertiesDirty"))->setValue($boat, true);
		self::assertFalse(self::flag($boat, EntityMetadataFlags::NO_AI));
	}

	public function testSeats() : void{
		$boat = $this->boat(0.5, 1, 0.5);
		$zombie = new Zombie(new Location(5.5, 1, 5.5, $this->world, 0, 0));
		$zombie->spawnToAll();
		$pig = new Pig(new Location(6.5, 1, 6.5, $this->world, 0, 0));
		$pig->spawnToAll();

		self::assertTrue($zombie->mount($boat));
		$seat = self::seatPosition($zombie);
		self::assertEqualsWithDelta(0.0, $seat->x, 1e-6, "alone: in the middle");
		self::assertEqualsWithDelta(-0.2 - $zombie->getSize()->getHeight() * 0.24691358, $seat->y, 1e-3);
		$locked = self::networkData($zombie)[EntityMetadataProperties::RIDER_ROTATION_LOCKED] ?? null;
		self::assertInstanceOf(ByteMetadataProperty::class, $locked);
		self::assertSame(1, $locked->getValue());
		self::assertEqualsWithDelta(90.0, self::floatProperty($zombie, EntityMetadataProperties::RIDER_MAX_ROTATION), 1e-6);
		self::assertEqualsWithDelta(-90.0, self::floatProperty($zombie, EntityMetadataProperties::RIDER_SEAT_ROTATION_OFFSET), 1e-6);
		self::assertNull($boat->getControllingPassenger(), "mobs don't steer boats");

		self::assertTrue($pig->mount($boat));
		self::assertEqualsWithDelta(0.2, self::seatPosition($zombie)->x, 1e-6, "front seat");
		self::assertEqualsWithDelta(-0.6, self::seatPosition($pig)->x, 1e-6, "back seat");
		self::assertEqualsWithDelta(0.0, self::floatProperty($pig, EntityMetadataProperties::RIDER_SEAT_ROTATION_OFFSET), 1e-6, "animals sit across");
		$third = new Zombie(new Location(7.5, 1, 7.5, $this->world, 0, 0));
		$third->spawnToAll();
		self::assertFalse($third->mount($boat), "two seats");

		//a player getting in takes the front seat (moving the others back)
		$move = new \ReflectionMethod(Entity::class, "movePassengerToFront");
		$move->invoke($boat, $pig);
		self::assertSame([$pig, $zombie], $boat->getPassengers());
		self::assertEqualsWithDelta(0.2, self::seatPosition($pig)->x, 1e-6);

		$zombie->dismount(true);
		self::assertEqualsWithDelta(0.0, self::seatPosition($pig)->x, 1e-6, "back in the middle");

		//chest boats have one seat, in front of the chest
		$chestBoat = $this->chestBoat(10.5, 1, 10.5);
		self::assertTrue($third->mount($chestBoat));
		self::assertEqualsWithDelta(0.2, self::seatPosition($third)->x, 1e-6);
		$zombie->teleport(new Vector3(10.5, 1, 12.5));
		self::assertFalse($zombie->mount($chestBoat));
	}

	public function testMobsAreScoopedUp() : void{
		$this->pool();
		$boat = $this->boat(0.5, self::SURFACE - 0.1, 0.5);
		$pig = new Pig(new Location(1.4, self::SURFACE - 0.1, 0.5, $this->world, 0, 0));
		$pig->spawnToAll();
		$this->runTicks(3);
		self::assertSame($boat, $pig->getVehicle());
	}

	public function testDriverClientMovesTheBoat() : void{
		$this->pool();
		$boat = $this->boat(0.5, self::SURFACE - 0.1, 0.5);
		$this->runTicks(5);
		$player = $this->player();
		$this->seat($player, $boat);
		self::assertSame($player, $boat->getControllingPassenger());

		$start = $boat->getLocation();
		$to = $start->add(0.3, 0, 0.1);
		self::assertTrue($boat->handleClientVehicleMovement($player, $to, 45.0, 0.0));
		self::assertTrue($boat->isMovedByClient());
		self::assertEqualsWithDelta(0.0, $boat->getLocation()->distance($to), 1e-9);
		self::assertEqualsWithDelta(45.0, $boat->getLocation()->yaw, 1e-9);
		$this->runTicks(1);
		self::assertEqualsWithDelta(0.0, $boat->getLocation()->distance($to), 1e-9, "the server doesn't move it");

		self::assertFalse($boat->handleClientVehicleMovement($player, $to->add(10, 0, 0), 45.0, 0.0), "too far");
		self::assertFalse($boat->handleClientVehicleMovement($player, new Vector3(7.5, 2, 0.5), 45.0, 0.0), "in the wall");
		$other = $this->player();
		self::assertFalse($boat->handleClientVehicleMovement($other, $to->add(0.1, 0, 0), 45.0, 0.0), "not its driver");

		//other players see the paddles move
		$boat->handleRiderInput($player, new RiderInput(0.0, 1.0, 0.0, 0.0, false, false, false, true, false));
		self::assertTrue($boat->handleClientVehicleMovement($player, $to->add(0.2, 0, 0), 45.0, 0.0));
		$this->runTicks(2);
		self::assertGreaterThan(0.0, $boat->getPaddleTime(true));
		self::assertSame(0.0, $boat->getPaddleTime(false));
		self::assertEqualsWithDelta($boat->getPaddleTime(true), self::floatProperty($boat, EntityMetadataProperties::PADDLE_TIME_LEFT), 1e-6);
	}

	public function testServerSteersWhenTheClientDoesNot() : void{
		$this->pool();
		$boat = $this->boat(0.5, self::SURFACE - 0.1, 0.5, BoatVariant::OAK, 90.0);
		$this->runTicks(20);
		$player = $this->player();
		$this->seat($player, $boat);
		$start = $boat->getLocation();
		for($i = 0; $i < 20; ++$i){
			$boat->handleRiderInput($player, new RiderInput(0.0, 1.0, 0.0, 0.0, false, false, false));
			$this->runTicks(1);
		}
		//Bedrock yaw 90 = Java yaw 0: forwards is south (+Z)
		self::assertGreaterThan(1.0, $boat->getLocation()->z - $start->z);
		self::assertEqualsWithDelta($start->x, $boat->getLocation()->x, 0.01);
		self::assertGreaterThan(0.0, $boat->getPaddleTime(true));
		self::assertGreaterThan(0.0, $boat->getPaddleTime(false));

		//turning left
		for($i = 0; $i < 10; ++$i){
			$boat->handleRiderInput($player, new RiderInput(1.0, 0.0, 0.0, 0.0, false, false, false));
			$this->runTicks(1);
		}
		self::assertLessThan(90.0, $boat->getLocation()->yaw);
	}

	public function testHitsBreakTheBoat() : void{
		$boat = $this->boat(0.5, 1, 0.5, BoatVariant::BIRCH);
		$boat->setNameTag("Rowan");
		for($i = 0; $i < 4; ++$i){
			$boat->attack(new EntityDamageEvent($boat, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
			self::assertFalse($boat->isBroken(), "hit $i");
		}
		self::assertSame(10, self::intProperty($boat, EntityMetadataProperties::HURT_TIME));
		$boat->attack(new EntityDamageEvent($boat, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		self::assertTrue($boat->isBroken());
		$this->runTicks(1);
		$drops = $this->entities(ItemEntity::class);
		self::assertCount(1, $drops);
		self::assertTrue($drops[0]->getItem()->equals(VanillaItems::BIRCH_BOAT(), true, false));
		self::assertSame("Rowan", $drops[0]->getItem()->getCustomName());

		//falls, fire and drowning don't hurt boats, lava does
		$other = $this->boat(5.5, 1, 5.5);
		$other->attack(new EntityDamageEvent($other, EntityDamageEvent::CAUSE_FALL, 100));
		$other->attack(new EntityDamageEvent($other, EntityDamageEvent::CAUSE_FIRE_TICK, 100));
		self::assertSame(0.0, $other->getDamage());
		$other->attack(new EntityDamageEvent($other, EntityDamageEvent::CAUSE_LAVA, 4));
		$other->attack(new EntityDamageEvent($other, EntityDamageEvent::CAUSE_LAVA, 4));
		self::assertTrue($other->isBroken());
	}

	public function testChestBoatDropsItselfAndItsContents() : void{
		$boat = $this->chestBoat(0.5, 1, 0.5, BoatVariant::PALE_OAK);
		self::assertSame(ChestBoat::INVENTORY_SIZE, $boat->getInventory()->getSize());
		$boat->getInventory()->setItem(13, VanillaItems::DIAMOND()->setCount(9));
		$boat->breakBoat(true);
		$this->runTicks(1);
		$drops = [];
		foreach($this->entities(ItemEntity::class) as $item){
			$drops[$item->getItem()->getVanillaName()] = $item->getItem()->getCount();
		}
		self::assertSame(["Pale Oak Boat with Chest" => 1, "Diamond" => 9], $drops);
	}

	public function testSavedAndLoaded() : void{
		$boat = $this->boat(0.5, 1, 0.5, BoatVariant::CHERRY, 135.0);
		$boat->setNameTag("Sakura");
		$nbt = $boat->saveNBT();
		self::assertSame(EntityIds::BOAT, $nbt->getString(EntityFactory::TAG_IDENTIFIER));
		self::assertSame(8, $nbt->getInt(Boat::TAG_VARIANT));
		$loaded = EntityFactory::getInstance()->createFromData($this->world, $nbt);
		self::assertInstanceOf(Boat::class, $loaded);
		self::assertNotInstanceOf(ChestBoat::class, $loaded);
		self::assertSame(BoatVariant::CHERRY, $loaded->getVariant());
		self::assertSame("Sakura", $loaded->getNameTag());
		self::assertEqualsWithDelta(135.0, $loaded->getLocation()->yaw, 1e-6);

		$chestBoat = $this->chestBoat(5.5, 1, 5.5, BoatVariant::BAMBOO);
		$chestBoat->getInventory()->setItem(26, VanillaItems::EMERALD()->setCount(3));
		$loadedChest = EntityFactory::getInstance()->createFromData($this->world, $chestBoat->saveNBT());
		self::assertInstanceOf(ChestBoat::class, $loadedChest);
		self::assertSame(BoatVariant::BAMBOO, $loadedChest->getVariant());
		self::assertSame(3, $loadedChest->getInventory()->getItem(26)->getCount());

		$looted = $this->chestBoat(8.5, 1, 8.5);
		$looted->setLootTable("loot_tables/chests/shipwreck_supply.json", 77);
		$loadedLoot = EntityFactory::getInstance()->createFromData($this->world, $looted->saveNBT());
		self::assertInstanceOf(ChestBoat::class, $loadedLoot);
		self::assertSame("loot_tables/chests/shipwreck_supply.json", $loadedLoot->getLootTable());
		self::assertSame(77, $loadedLoot->getLootTableSeed());
	}

	public function testDispenserPutsBoatOnWater() : void{
		$this->pool();
		$this->set(-8, 3, 0, VanillaBlocks::STONE());
		$inventory = new SimpleInventory(9);
		$item = VanillaItems::ACACIA_BOAT();
		//a dispenser in the west wall facing east, water in front
		$context = new DispenseContext($this->world, new Vector3(-7, 3, 0), Facing::EAST, $inventory);
		$result = DispenseBehaviorRegistry::getInstance()->get($item)->dispense($context, (clone $item)->setCount(1));
		self::assertTrue($result === null || $result->isNull());
		$this->runTicks(60);
		$boats = $this->entities(Boat::class);
		self::assertCount(1, $boats);
		self::assertSame(BoatVariant::ACACIA, $boats[0]->getVariant());
		self::assertGreaterThan(self::SURFACE - 0.3, $boats[0]->getLocation()->y);
		//facing east, away from the dispenser
		self::assertGreaterThan(0.9, $boats[0]->getForwardDirection()->x);
	}
}
