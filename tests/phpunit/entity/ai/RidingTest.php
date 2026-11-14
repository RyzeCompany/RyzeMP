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

namespace pocketmine\entity\ai;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\animal\Camel;
use pocketmine\entity\animal\Chicken;
use pocketmine\entity\animal\HappyGhast;
use pocketmine\entity\animal\Horse;
use pocketmine\entity\animal\Llama;
use pocketmine\entity\animal\Pig;
use pocketmine\entity\animal\SkeletonHorse;
use pocketmine\entity\animal\Strider;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Bogged;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\monster\Spider;
use pocketmine\entity\monster\ZombifiedPiglin;
use pocketmine\entity\riding\Jockeys;
use pocketmine\entity\riding\RiderInput;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityMountEvent;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\event\RegisteredListener;
use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\PlayerOffHandInventory;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\Vec3MetadataProperty;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\timings\TimingsHandler;
use pocketmine\world\Position;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;
use function fmod;

final class RidingTest extends MobTestCase{

	private function loc(float $x, float $y, float $z, float $yaw = 0.0) : Location{
		return new Location($x, $y, $z, $this->world, $yaw, 0);
	}

	/**
	 * A mocked player holding the item. Its riding state is set with {@link RidingTest::seat()}: the real mount() can't
	 * run on a mock.
	 */
	private function player(Item $held) : Player{
		$player = $this->createMock(Player::class);
		$inventory = new PlayerInventory($player);
		$inventory->setItemInHand($held);
		$player->method("getInventory")->willReturn($inventory);
		$player->method("hasFiniteResources")->willReturn(true);
		$player->method("getUniqueId")->willReturn(Uuid::uuid4());
		$player->method("getWorld")->willReturn($this->world);
		$player->method("isConnected")->willReturn(false);
		$player->method("isAlive")->willReturn(true);
		$player->method("getName")->willReturn("Tester");
		$player->method("getOffsetPosition")->willReturnCallback(fn(Vector3 $v) => $v->add(0, 1.621, 0));
		$player->method("getSeatedHeightOffset")->willReturn(1.62 * 0.75308642);
		//keep the mock from running Player::onDispose() when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	/**
	 * Puts the (mocked) player on the vehicle's next seat, as Entity::mount() does after its checks.
	 */
	private function seat(Player $player, Entity $vehicle) : void{
		$list = $vehicle->getPassengers();
		$list[] = $player;
		(new \ReflectionProperty(Entity::class, "passengers"))->setValue($vehicle, $list);
	}

	private static function flag(Entity $entity, int $flag) : bool{
		$flags = $entity->getNetworkProperties()->getAll()[$flag >= 64 ? EntityMetadataProperties::FLAGS2 : EntityMetadataProperties::FLAGS] ?? null;
		self::assertInstanceOf(LongMetadataProperty::class, $flags);
		return ($flags->getValue() & (1 << ($flag % 64))) !== 0;
	}

	public function testSpiderJockeyLink() : void{
		$spider = new Spider($this->loc(0.5, 1, 0.5));
		$skeleton = new Skeleton($this->loc(3.5, 1, 0.5));
		self::assertTrue($skeleton->mount($spider));
		self::assertSame($spider, $skeleton->getVehicle());
		self::assertSame([$skeleton], $spider->getPassengers());
		self::assertSame($skeleton, $spider->getControllingPassenger());
		self::assertTrue($skeleton->isRiding());
		self::assertTrue(self::flag($skeleton, EntityMetadataFlags::RIDING));

		//vanilla seat 0.54 up, a mob's feet a quarter of its height below it
		$seat = $skeleton->getNetworkProperties()->getAll()[EntityMetadataProperties::RIDER_SEAT_POSITION] ?? null;
		self::assertInstanceOf(Vec3MetadataProperty::class, $seat);
		self::assertEqualsWithDelta(0.54 - 1.99 * 0.24691358, $seat->getValue()->y, 0.001);

		$this->runTicks(5);
		$expected = $spider->getPassengerPosition($skeleton);
		self::assertEqualsWithDelta(0.0, $skeleton->getPosition()->distance($expected), 0.001, "the passenger sits on its seat");
		self::assertEqualsWithDelta($spider->getPosition()->x, $skeleton->getPosition()->x, 0.2);

		//no loops, one seat
		self::assertFalse($spider->mount($skeleton));
		$other = new Skeleton($this->loc(5.5, 1, 0.5));
		self::assertFalse($other->mount($spider), "a spider has one seat");

		self::assertTrue($skeleton->dismount());
		self::assertNull($skeleton->getVehicle());
		self::assertSame([], $spider->getPassengers());
		self::assertFalse(self::flag($skeleton, EntityMetadataFlags::RIDING));
		self::assertGreaterThan(0.5, $skeleton->getPosition()->distance($spider->getPosition()), "put beside the spider");
		self::assertFalse($skeleton->dismount(), "already off");
	}

	public function testFamilyTypes() : void{
		$chicken = new Chicken($this->loc(0.5, 1, 0.5));
		$adult = new Zombie($this->loc(2.5, 1, 0.5));
		self::assertFalse($adult->mount($chicken), "only baby zombies ride chickens");
		$baby = new Zombie($this->loc(3.5, 1, 0.5));
		$baby->setBaby(true);
		self::assertTrue($baby->mount($chicken));

		$pig = new Pig($this->loc(6.5, 1, 0.5));
		$baby2 = new Zombie($this->loc(7.5, 1, 0.5));
		$baby2->setBaby(true);
		self::assertTrue($baby2->mount($pig), "an unsaddled pig carries a baby zombie");

		$strider = new Strider($this->loc(10.5, 1, 0.5));
		$strider->setAge(0);
		$piglin = new ZombifiedPiglin($this->loc(10.5, 1, 2.5));
		self::assertTrue($piglin->mount($strider));

		$bogged = new Bogged($this->loc(12.5, 1, 0.5));
		$spider = new Spider($this->loc(14.5, 1, 0.5));
		self::assertTrue($bogged->mount($spider));
		self::assertEqualsWithDelta(0.325 - $bogged->getSize()->getHeight() * 0.24691358, $spider->getPassengerSeatOffset($bogged)->y, 0.001, "bogged sit lower");
	}

	public function testMountEventCanBeCancelled() : void{
		$spider = new Spider($this->loc(0.5, 1, 0.5));
		$skeleton = new Skeleton($this->loc(3.5, 1, 0.5));
		$plugin = $this->createConfiguredMock(Plugin::class, ["isEnabled" => true]);
		$listener = new RegisteredListener(
			function(EntityMountEvent $ev) : void{
				$ev->cancel();
			},
			EventPriority::NORMAL,
			$plugin,
			false,
			new TimingsHandler("test")
		);
		HandlerListManager::global()->getListFor(EntityMountEvent::class)->register($listener);
		try{
			self::assertFalse($skeleton->mount($spider));
			self::assertNull($skeleton->getVehicle());
		}finally{
			HandlerListManager::global()->getListFor(EntityMountEvent::class)->unregister($listener);
		}
	}

	public function testDeathAndTeleportEndRides() : void{
		$spider = new Spider($this->loc(0.5, 1, 0.5));
		$skeleton = new Skeleton($this->loc(3.5, 1, 0.5));
		self::assertTrue($skeleton->mount($spider));
		$spider->kill();
		self::assertNull($skeleton->getVehicle(), "the vehicle died");

		$spider2 = new Spider($this->loc(6.5, 1, 0.5));
		$skeleton2 = new Skeleton($this->loc(8.5, 1, 0.5));
		self::assertTrue($skeleton2->mount($spider2));
		$skeleton2->teleport(new Vector3(10.5, 1, 10.5));
		self::assertNull($skeleton2->getVehicle(), "teleporting ends the ride");
		self::assertEqualsWithDelta(10.5, $skeleton2->getPosition()->x, 0.001);

		$spider3 = new Spider($this->loc(12.5, 1, 0.5));
		$skeleton3 = new Skeleton($this->loc(14.5, 1, 0.5));
		self::assertTrue($skeleton3->mount($spider3));
		$spider3->flagForDespawn();
		$this->runTicks(1);
		self::assertTrue($spider3->isClosed());
		self::assertNull($skeleton3->getVehicle(), "the vehicle despawned");
	}

	public function testJockeySavedWithLinksTag() : void{
		$spider = new Spider($this->loc(0.5, 1, 0.5));
		$skeleton = new Skeleton($this->loc(0.5, 1, 0.5));
		self::assertTrue($skeleton->mount($spider));
		$this->runTicks(2);

		$spiderNbt = $spider->saveNBT();
		$skeletonNbt = $skeleton->saveNBT();
		$links = $spiderNbt->getTag(Entity::TAG_LINKS);
		self::assertInstanceOf(ListTag::class, $links);
		self::assertCount(1, $links);
		$link = $links->get(0);
		self::assertInstanceOf(CompoundTag::class, $link);
		self::assertSame($skeleton->getPersistentUniqueId(), $link->getLong(Entity::TAG_LINK_ENTITY_ID));
		self::assertSame(0, $link->getInt(Entity::TAG_LINK_ID));
		self::assertSame($skeleton->getPersistentUniqueId(), $skeletonNbt->getLong(Entity::TAG_UNIQUE_ID));
		self::assertNull($skeletonNbt->getTag(Entity::TAG_LINKS));

		$spider->flagForDespawn();
		$skeleton->flagForDespawn();
		$this->runTicks(1);

		//the chunk loads both entities separately; the vehicle finds its passenger by UniqueID
		$loadedSpider = EntityFactory::getInstance()->createFromData($this->world, $spiderNbt);
		$loadedSkeleton = EntityFactory::getInstance()->createFromData($this->world, $skeletonNbt);
		self::assertInstanceOf(Spider::class, $loadedSpider);
		self::assertInstanceOf(Skeleton::class, $loadedSkeleton);
		$this->runTicks(2);
		self::assertSame($loadedSpider, $loadedSkeleton->getVehicle());
		self::assertSame($skeleton->getPersistentUniqueId(), $loadedSkeleton->getPersistentUniqueId());
	}

	public function testPlayerRidesAreNotSaved() : void{
		$pig = new Pig($this->loc(0.5, 1, 0.5));
		$pig->setSaddled(true);
		$this->seat($this->player(VanillaItems::AIR()), $pig);
		self::assertNull($pig->saveNBT()->getTag(Entity::TAG_LINKS));
		self::assertNull($pig->saveNBT()->getTag(Entity::TAG_UNIQUE_ID));
	}

	public function testPigSteeredWithCarrotOnAStick() : void{
		$pig = new Pig($this->loc(0.5, 1, 0.5));
		$pig->setSaddled(true);
		self::assertCount(1, $pig->getRideableSeats());
		$rider = $this->player(VanillaItems::CARROT_ON_A_STICK());
		self::assertTrue($pig->canBeRiddenBy($rider));
		self::assertTrue($pig->canBeSteeredBy($rider));
		self::assertFalse($pig->canBeSteeredBy($this->player(VanillaItems::AIR())), "only with a carrot on a stick");
		$this->seat($rider, $pig);

		//the rider looks towards -X (yaw 90): the pig walks there, whatever the move vector
		for($i = 0; $i < 20; ++$i){
			$pig->handleRiderInput($rider, new RiderInput(0.0, 0.0, 90.0, 0.0, false, false, false));
			$this->runTicks(1);
		}
		self::assertSame($rider, $pig->getSteeringPlayer());
		self::assertLessThan(-1.5, $pig->getPosition()->x, "walked towards -X");
		self::assertEqualsWithDelta(0.5, $pig->getPosition()->z, 0.3);

		$stick = $rider->getInventory()->getItemInHand();
		self::assertTrue($pig->handleRiderItemUse($rider, $stick));
		self::assertTrue($pig->isBoosting());
		$used = $rider->getInventory()->getItemInHand();
		self::assertInstanceOf(Durable::class, $used);
		self::assertSame(2, $used->getDamage(), "a boost costs 2 durability");
		self::assertFalse($pig->handleRiderItemUse($rider, $used), "no boost while boosting");

		//without input the pig is no longer steered
		$this->runTicks(10);
		self::assertNull($pig->getSteeringPlayer());
	}

	public function testHorseSteeringAndClientPrediction() : void{
		$horse = new Horse($this->loc(0.5, 1, 0.5));
		$rider = $this->player(VanillaItems::AIR());
		self::assertTrue($horse->canBeRiddenBy($rider));
		self::assertFalse($horse->canBeSteeredBy($rider), "untamed");
		$horse->setOwner($rider);
		self::assertFalse($horse->canBeSteeredBy($rider), "no saddle");
		$horse->setSaddled(true);
		self::assertTrue($horse->canBeSteeredBy($rider));
		$this->runTicks(1);
		self::assertTrue(self::flag($horse, EntityMetadataFlags::WASD_CONTROLLED));
		self::assertTrue(self::flag($horse, EntityMetadataFlags::CAN_POWER_JUMP));
		$this->seat($rider, $horse);

		//server-moved: forwards in the rider's look direction (yaw 0 = +Z)
		for($i = 0; $i < 20; ++$i){
			$horse->handleRiderInput($rider, new RiderInput(0.0, 1.0, 0.0, 0.0, false, false, false));
			$this->runTicks(1);
		}
		self::assertGreaterThan(2.0, $horse->getPosition()->z);

		//client-predicted: the client's positions are taken if plausible
		$start = $horse->getPosition();
		self::assertTrue($horse->handleClientVehicleMovement($rider, $start->add(0, 0, 0.5), 10.0, 0.0));
		self::assertTrue($horse->isMovedByClient());
		self::assertEqualsWithDelta($start->z + 0.5, $horse->getPosition()->z, 0.001);
		self::assertEqualsWithDelta(10.0, $horse->getLocation()->yaw, 0.001);
		self::assertFalse($horse->handleClientVehicleMovement($rider, $start->add(0, 0, 30), 10.0, 0.0), "too far in one movement");
		$this->set((int) $start->x, (int) $start->y, (int) $start->z + 2, VanillaBlocks::STONE());
		self::assertFalse($horse->handleClientVehicleMovement($rider, $start->add(0, 0, 2), 10.0, 0.0), "inside a block");
		$before = $horse->getPosition();
		$this->runTicks(1);
		self::assertTrue($before->equals($horse->getPosition()), "the server doesn't move a client-moved horse");

		//standing still, entities get NO_AI so clients don't move them; the rider's client must still drive it
		$horse->setNoClientPredictions(true);
		$horse->sendData(null); //syncs the network properties
		self::assertFalse(self::flag($horse, EntityMetadataFlags::NO_AI), "NO_AI keeps the rider's client from driving the horse");
		(new \ReflectionProperty(Entity::class, "passengers"))->setValue($horse, []);
		(new \ReflectionProperty(Entity::class, "networkPropertiesDirty"))->setValue($horse, true);
		$horse->sendData(null);
		self::assertTrue(self::flag($horse, EntityMetadataFlags::NO_AI), "without a rider a still horse keeps NO_AI");
		$this->seat($rider, $horse);

		$other = $this->player(VanillaItems::AIR());
		self::assertFalse($horse->handleClientVehicleMovement($other, $start->add(0, 0, 0.5), 0.0, 0.0), "only the rider moves it");
	}

	public function testUntamedHorseAndLlama() : void{
		$llama = new Llama($this->loc(0.5, 1, 0.5));
		$llama->setOwner($this->player(VanillaItems::AIR()));
		self::assertFalse($llama->canBeSteeredBy($this->player(VanillaItems::AIR())), "llamas can't be steered");
		self::assertEqualsWithDelta(1.17, $llama->getRideableSeats()[0]->position->y, 0.001);

		$skeletonHorse = new SkeletonHorse($this->loc(4.5, 1, 0.5));
		$skeletonHorse->setSaddled(true);
		self::assertTrue($skeletonHorse->canBeSteeredBy($this->player(VanillaItems::AIR())), "skeleton horses need no taming");
		self::assertTrue($skeletonHorse->canBeRiddenBy(new Skeleton($this->loc(6.5, 1, 0.5))));

		$horse = new Horse($this->loc(8.5, 1, 0.5));
		$horse->setTemper(100);
		$rider = $this->player(VanillaItems::AIR());
		$this->seat($rider, $horse);
		self::assertTrue($horse->isBeingBrokenIn());
		for($i = 0; $i < 400 && !$horse->isTamed(); ++$i){
			$this->runTicks(1);
		}
		self::assertTrue($horse->isTamed(), "with a full temper, riding tames it");
		self::assertFalse($horse->isBeingBrokenIn());
	}

	public function testCamelSeatsAndDash() : void{
		$camel = new Camel($this->loc(0.5, 1, 0.5));
		self::assertCount(2, $camel->getRideableSeats());
		$camel->setSitting(true);
		$first = $this->player(VanillaItems::AIR());
		self::assertTrue($camel->canBeRiddenBy($first));
		self::assertFalse($camel->canBeSteeredBy($first), "needs a saddle");

		$saddled = new Camel($this->loc(6.5, 1, 0.5));
		$saddled->setSaddled(true);
		$rider = $this->player(VanillaItems::AIR());
		$this->seat($rider, $saddled);
		$this->runTicks(1);
		for($i = 0; $i < 5; ++$i){
			$saddled->handleRiderInput($rider, new RiderInput(0.0, 0.0, 0.0, 0.0, true, false, false));
			$this->runTicks(1);
		}
		$saddled->handleRiderInput($rider, new RiderInput(0.0, 0.0, 0.0, 0.0, false, false, false));
		self::assertTrue($saddled->hasDashCooldown(), "releasing jump dashes");
		$this->runTicks(2);
		self::assertTrue(self::flag($saddled, EntityMetadataFlags::HAS_DASH_COOLDOWN));
		self::assertGreaterThan(0.5, $saddled->getPosition()->z, "dashed forwards");
		$this->runTicks(60);
		self::assertFalse($saddled->hasDashCooldown());
	}

	public function testHappyGhastHarness() : void{
		$ghast = new HappyGhast($this->loc(0.5, 5, 0.5));
		self::assertSame([], $ghast->getRideableSeats(), "no harness, no seats");
		$ghast->setHarness(VanillaItems::HARNESS());
		self::assertCount(4, $ghast->getRideableSeats());
		self::assertSame(8.0, $ghast->getRideableSeats()[0]->thirdPersonCameraRadius);
		$nbt = $ghast->saveNBT();
		$loaded = new HappyGhast($this->loc(0.5, 5, 0.5), $nbt);
		$harness = $loaded->getHarness();
		self::assertNotNull($harness);
		self::assertSame(ItemTypeIds::HARNESS, $harness->getTypeId());
		$dismount = $ghast->getDismountPosition(new Skeleton($this->loc(3.5, 1, 0.5)));
		self::assertEqualsWithDelta(5 + $ghast->getSize()->getHeight(), $dismount->y, 0.1, "riders get off on top");
	}

	public function testHappyGhastDismountOnTop() : void{
		$ghast = new HappyGhast($this->loc(0.5, 5, 0.5, 30));
		$ghast->setHarness(VanillaItems::HARNESS());
		$rider = new Skeleton($this->loc(3.5, 1, 0.5));
		$top = $ghast->getDismountPosition($rider);
		self::assertEqualsWithDelta(0.5, $top->x, 0.001, "on top, centered");
		self::assertEqualsWithDelta(0.5, $top->z, 0.001);
		self::assertEqualsWithDelta(9.01, $top->y, 0.001);

		//no room on top: beside it, like other mounts
		$this->world->setBlockAt(0, 10, 0, VanillaBlocks::STONE(), false);
		$beside = $ghast->getDismountPosition($rider);
		self::assertGreaterThan(2.0, (($beside->x - 0.5) ** 2 + ($beside->z - 0.5) ** 2) ** 0.5, "beside the happy ghast");
		$this->world->setBlockAt(0, 10, 0, VanillaBlocks::AIR(), false);
	}

	/**
	 * A mocked player at the given position for the happy ghast's sensor.
	 */
	private function playerAt(float $x, float $y, float $z, ?Entity $vehicle = null) : Player{
		$player = $this->createMock(Player::class);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("isAlive")->willReturn(true);
		$player->method("isSpectator")->willReturn(false);
		$player->method("getPosition")->willReturn(new Position($x, $y, $z, $this->world));
		$player->method("getVehicle")->willReturn($vehicle);
		//empty hands, for the tempt goal
		$player->method("getInventory")->willReturn(new PlayerInventory($player));
		$player->method("getOffHandInventory")->willReturn(new PlayerOffHandInventory($player));
		//keep the mock from running Player::onDispose() when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	public function testHappyGhastHoldsStillWithPlayersOnTop() : void{
		//the sensor looks at the players of the world
		$worldPlayers = new \ReflectionProperty(World::class, "players");
		$sense = fn(Player ...$players) => $worldPlayers->setValue($this->world, $players);
		$ghast = new HappyGhast($this->loc(0.5, 5, 0.5, 30));
		$ghast->setHarness(VanillaItems::HARNESS());
		$ghast->spawnToAll();
		$this->runTicks(2);
		self::assertFalse($ghast->isImmobile());
		self::assertFalse(self::flag($ghast, EntityMetadataFlags::COLLIDABLE));
		self::assertTrue(self::flag($ghast, EntityMetadataFlags::CAN_USE_VERTICAL_MOVEMENT_ACTION), "jumping makes a harnessed happy ghast go up");
		self::assertTrue(self::flag($ghast, EntityMetadataFlags::DOES_SERVER_AUTH_ONLY_DISMOUNT), "the client waits for the server to get off");

		//a rider getting off becomes solid before being put on top
		$rider = $this->playerAt(0.5, 9.8, 2.2, $ghast);
		(new \ReflectionMethod(HappyGhast::class, "onPassengerRemoved"))->invoke($ghast, $rider);
		self::assertTrue($ghast->isImmobile());
		self::assertTrue(self::flag($ghast, EntityMetadataFlags::COLLIDABLE), "players can stand on it");
		self::assertTrue(self::flag($ghast, EntityMetadataFlags::BODY_ROTATION_BLOCKED));
		self::assertSame(0, $ghast->getActorProperty("minecraft:can_move"));
		self::assertEqualsWithDelta(0.0, fmod($ghast->getLocation()->yaw, 90), 0.001, "axis-aligned");

		//standing on top keeps it still, riders of a happy ghast don't count
		$otherRider = $this->playerAt(0.5, 8.8, 0.5, $ghast);
		try{
			$sense($this->playerAt(1.5, 9.0, 1.0), $otherRider);
			$this->runTicks(5);
			self::assertTrue($ghast->isImmobile());
			$ghast->knockBack(1, 0, 2);
			self::assertTrue($ghast->getMotion()->equals(Vector3::zero()), "no knockback");

			//the player walked off the edge (out of the 3.5 block range)
			$sense($this->playerAt(4.5, 9.0, 0.5), $otherRider);
			$this->runTicks(1);
			self::assertFalse($ghast->isImmobile(), "riders of a happy ghast don't keep it still");
			self::assertFalse(self::flag($ghast, EntityMetadataFlags::COLLIDABLE));
			self::assertSame(1, $ghast->getActorProperty("minecraft:can_move"));

			//a player landing on it (within 3 blocks) stops it again
			$sense($this->playerAt(2.0, 9.5, 2.0));
			$this->runTicks(1);
			self::assertTrue($ghast->isImmobile());
		}finally{
			$sense();
		}
		$ghast->flagForDespawn();
	}

	public function testJockeyHelpers() : void{
		$this->world->setTime(18000); //night
		$spider = new Spider($this->loc(0.5, 1, 0.5));
		$spider->spawnToAll();
		$rider = Jockeys::spawnSkeletonJockey($spider, false);
		self::assertInstanceOf(Skeleton::class, $rider);
		self::assertSame($spider, $rider->getVehicle());

		$chicken = Jockeys::spawnChickenJockey($this->loc(4.5, 1, 0.5));
		$jockey = $chicken->getControllingPassenger();
		self::assertInstanceOf(Zombie::class, $jockey);
		self::assertTrue($jockey->isBaby());

		$strider = new Strider($this->loc(8.5, 1, 0.5));
		$strider->setAge(0);
		$piglin = Jockeys::spawnStriderJockey($strider);
		self::assertNotNull($piglin);
		self::assertTrue($strider->isSaddled());
		self::assertSame(ItemTypeIds::WARPED_FUNGUS_ON_A_STICK, $piglin->getMainHandItem()->getTypeId());

		$strider2 = new Strider($this->loc(12.5, 1, 0.5));
		$strider2->setAge(0);
		$baby = Jockeys::spawnStriderParentJockey($strider2);
		self::assertNotNull($baby);
		self::assertTrue($baby->isBaby());
	}

	public function testBabyZombieFindsAMount() : void{
		$chicken = new Chicken($this->loc(4.5, 1, 0.5));
		$baby = new Zombie($this->loc(0.5, 1, 0.5));
		$baby->setBaby(true);
		Jockeys::makeMountSeeker($baby);
		for($i = 0; $i < 400 && $baby->getVehicle() === null; ++$i){
			$this->runTicks(1);
		}
		self::assertSame($chicken, $baby->getVehicle(), "the baby zombie found the chicken");
		self::assertCount(1, $chicken->getPassengers());
	}
}
