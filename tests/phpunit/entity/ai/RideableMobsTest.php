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
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\animal\CamelHusk;
use pocketmine\entity\aquatic\Nautilus;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Drowned;
use pocketmine\entity\monster\Husk;
use pocketmine\entity\monster\Parched;
use pocketmine\entity\monster\ZombieNautilus;
use pocketmine\entity\riding\Jockeys;
use pocketmine\entity\riding\RiderInput;
use pocketmine\entity\Zombie;
use pocketmine\inventory\HorseInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\InventoryManager;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\player\Player;
use Ramsey\Uuid\Uuid;
use function array_map;

/**
 * Camel husks, nautiluses and zombie nautiluses: seats, who may ride them, their riders, equipment and steering.
 */
final class RideableMobsTest extends MobTestCase{

	private function loc(float $x, float $y, float $z, float $yaw = 0.0) : Location{
		return new Location($x, $y, $z, $this->world, $yaw, 0);
	}

	/**
	 * A mocked player holding the item (see RidingTest::player()).
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
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

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

	/**
	 * Fills a box with water (y 1 to $top).
	 */
	private function pool(int $minX, int $minZ, int $maxX, int $maxZ, int $top) : void{
		for($x = $minX; $x <= $maxX; ++$x){
			for($z = $minZ; $z <= $maxZ; ++$z){
				for($y = 1; $y <= $top; ++$y){
					$this->world->setBlockAt($x, $y, $z, VanillaBlocks::WATER(), false);
				}
			}
		}
		PathBlockTypes::clearCache();
	}

	public function testCamelHuskSeatsAndFamilies() : void{
		$camelHusk = new CamelHusk($this->loc(0.5, 1, 0.5));
		$seats = $camelHusk->getRideableSeats();
		self::assertCount(2, $seats);
		self::assertEqualsWithDelta(1.905, $seats[0]->position->y, 0.001);
		self::assertEqualsWithDelta(0.5, $seats[0]->position->z, 0.001);
		self::assertEqualsWithDelta(-0.5, $seats[1]->position->z, 0.001);

		self::assertTrue($camelHusk->canBeRiddenBy($this->player(VanillaItems::AIR())));
		self::assertTrue($camelHusk->canBeRiddenBy(new Parched($this->loc(2.5, 1, 0.5))));
		$husk = new Husk($this->loc(3.5, 1, 0.5));
		self::assertFalse($camelHusk->canBeRiddenBy($husk), "only camel husk riders (husk_rider family)");
		$husk->setCamelHuskRider(true);
		self::assertTrue($camelHusk->canBeRiddenBy($husk));
		self::assertFalse($camelHusk->canBeRiddenBy(new Zombie($this->loc(4.5, 1, 0.5))));
		self::assertFalse($camelHusk->canBeSteeredBy($this->player(VanillaItems::AIR())), "needs a saddle");
	}

	public function testCamelHuskRiders() : void{
		$camelHusk = new CamelHusk($this->loc(0.5, 1, 0.5));
		$camelHusk->spawnToAll();
		Jockeys::onMobSpawned($camelHusk);
		self::assertSame([], $camelHusk->getPassengers(), "spawn eggs give no riders");

		Jockeys::onMobSpawned($camelHusk, true);
		$passengers = $camelHusk->getPassengers();
		self::assertCount(2, $passengers);
		[$husk, $parched] = $passengers;
		self::assertInstanceOf(Husk::class, $husk);
		self::assertInstanceOf(Parched::class, $parched);
		self::assertTrue($husk->isCamelHuskRider());
		self::assertFalse($husk->isBaby());
		self::assertSame(ItemTypeIds::IRON_SPEAR, $husk->getMainHandItem()->getTypeId());
		self::assertTrue($camelHusk->hasHostileRider());

		$player = $this->player(VanillaItems::SADDLE());
		self::assertSame("", $camelHusk->getInteractButtonText($player), "no saddle while a hostile rider is on, and no free seat");
		self::assertFalse($camelHusk->onInteract($player, new Vector3(0, 0, 0)));
		self::assertFalse($camelHusk->isSaddled(), "can't be saddled with a hostile rider");

		//the rider is saved with its flag and spear
		$loaded = new Husk($this->loc(0.5, 1, 0.5), $husk->saveNBT());
		self::assertTrue($loaded->isCamelHuskRider());
		self::assertSame(ItemTypeIds::IRON_SPEAR, $loaded->getMainHandItem()->getTypeId());
		$loaded->flagForDespawn();

		//all riders off: back to a normal camel husk
		$husk->dismount(true);
		$parched->dismount(true);
		self::assertFalse($camelHusk->hasHostileRider());
		self::assertTrue($camelHusk->onInteract($player, new Vector3(0, 0, 0)));
		self::assertTrue($camelHusk->isSaddled());
	}

	public function testCamelHuskSaddleDashAndSave() : void{
		$camelHusk = new CamelHusk($this->loc(0.5, 1, 0.5));
		$camelHusk->setSaddled(true);
		$loaded = new CamelHusk($this->loc(0.5, 1, 0.5), $camelHusk->saveNBT());
		self::assertTrue($loaded->isSaddled(), "the saddle is saved");
		$drops = array_map(fn(Item $item) => $item->getTypeId(), $loaded->getDrops());
		self::assertContains(ItemTypeIds::SADDLE, $drops);
		self::assertContains(ItemTypeIds::ROTTEN_FLESH, $drops);

		$rider = $this->player(VanillaItems::AIR());
		self::assertTrue($camelHusk->canBeSteeredBy($rider));
		$camelHusk->setSitting(true);
		$this->seat($rider, $camelHusk);
		$this->runTicks(1);
		self::assertTrue(self::flag($camelHusk, EntityMetadataFlags::WASD_CONTROLLED));
		self::assertTrue(self::flag($camelHusk, EntityMetadataFlags::CAN_DASH));

		//steered forwards (yaw 0 = +Z), then a dash
		for($i = 0; $i < 20; ++$i){
			$camelHusk->handleRiderInput($rider, new RiderInput(0.0, 1.0, 0.0, 0.0, false, false, false));
			$this->runTicks(1);
		}
		self::assertGreaterThan(1.0, $camelHusk->getPosition()->z, "walked forwards");
		for($i = 0; $i < 5; ++$i){
			$camelHusk->handleRiderInput($rider, new RiderInput(0.0, 0.0, 0.0, 0.0, true, false, false));
			$this->runTicks(1);
		}
		$camelHusk->handleRiderInput($rider, new RiderInput(0.0, 0.0, 0.0, 0.0, false, false, false));
		self::assertTrue($camelHusk->hasDashCooldown(), "releasing jump dashes");

		//shears take the saddle off only when nobody rides
		$shearer = $this->player(VanillaItems::SHEARS());
		self::assertFalse($camelHusk->onInteract($shearer, new Vector3(0, 0, 0)));
		self::assertTrue($camelHusk->isSaddled(), "not while ridden");
		(new \ReflectionProperty(Entity::class, "passengers"))->setValue($camelHusk, []);
		self::assertTrue($camelHusk->onInteract($shearer, new Vector3(0, 0, 0)));
		self::assertFalse($camelHusk->isSaddled());
		$shears = $shearer->getInventory()->getItemInHand();
		self::assertInstanceOf(Durable::class, $shears);
		self::assertSame(1, $shears->getDamage(), "the shears lose durability");
	}

	public function testNautilusTamingAndEquipment() : void{
		$nautilus = new Nautilus($this->loc(0.5, 1, 0.5));
		self::assertSame([], $nautilus->getRideableSeats(), "wild ones can't be ridden");
		$player = $this->player(VanillaItems::PUFFERFISH()->setCount(64));
		self::assertFalse($nautilus->canBeRiddenBy($player));
		for($i = 0; $i < 40 && !$nautilus->isTamed(); ++$i){
			self::assertTrue($nautilus->onInteract($player, new Vector3(0, 0, 0)));
		}
		self::assertTrue($nautilus->isTamed(), "pufferfish tame it (1/3)");
		self::assertFalse($nautilus->isSitting(), "a nautilus never sits");
		self::assertLessThan(64, $player->getInventory()->getItemInHand()->getCount());

		$seats = $nautilus->getRideableSeats();
		self::assertCount(1, $seats);
		self::assertEqualsWithDelta(0.925, $seats[0]->position->y, 0.001);
		self::assertSame(7.0, $seats[0]->thirdPersonCameraRadius);
		self::assertTrue($nautilus->canBeRiddenBy($player));
		self::assertFalse($nautilus->canBeSteeredBy($player), "needs a saddle");

		//saddle and armor by using them, shears take the armor off first
		$saddler = $this->player(VanillaItems::SADDLE());
		self::assertSame("action.interact.saddle", $nautilus->getInteractButtonText($saddler));
		self::assertTrue($nautilus->onInteract($saddler, new Vector3(0, 0, 0)));
		self::assertTrue($nautilus->isSaddled());
		self::assertTrue($nautilus->canBeSteeredBy($player));
		$armorer = $this->player(VanillaItems::DIAMOND_NAUTILUS_ARMOR());
		self::assertSame("action.interact.equipnautilusarmor", $nautilus->getInteractButtonText($armorer));
		$armorBefore = $nautilus->getArmorPoints();
		self::assertTrue($nautilus->onInteract($armorer, new Vector3(0, 0, 0)));
		self::assertSame(ItemTypeIds::DIAMOND_NAUTILUS_ARMOR, $nautilus->getNautilusArmor()->getTypeId());
		self::assertSame($armorBefore + 11, $nautilus->getArmorPoints());
		self::assertFalse($nautilus->canHoldInSlot(HorseInventory::SLOT_ARMOR, VanillaItems::DIAMOND_HORSE_ARMOR()), "horse armor doesn't fit");
		self::assertFalse($nautilus->canHoldInSlot(HorseInventory::SLOT_EQUIP, VanillaItems::DIAMOND_NAUTILUS_ARMOR()));

		//saved and loaded with the tamed state and the equipment
		$loaded = new Nautilus($this->loc(0.5, 1, 0.5), $nautilus->saveNBT());
		self::assertTrue($loaded->isTamed());
		self::assertTrue($loaded->isSaddled());
		self::assertSame(ItemTypeIds::DIAMOND_NAUTILUS_ARMOR, $loaded->getNautilusArmor()->getTypeId());
		$loaded->flagForDespawn();

		//the horse screen shows a saddle slot and a nautilus armor slot
		$slots = InventoryManager::createHorseEquipPacket(3, $nautilus->getInventory())->nbt->getRoot()->getListTag("slots");
		self::assertNotNull($slots);
		self::assertCount(2, $slots);

		$shearer = $this->player(VanillaItems::SHEARS());
		self::assertSame("action.interact.removenautilusarmor", $nautilus->getInteractButtonText($shearer));
		self::assertTrue($nautilus->onInteract($shearer, new Vector3(0, 0, 0)));
		self::assertTrue($nautilus->getNautilusArmor()->isNull());
		self::assertTrue($nautilus->isSaddled(), "armor first");
		self::assertTrue($nautilus->onInteract($shearer, new Vector3(0, 0, 0)));
		self::assertFalse($nautilus->isSaddled());
	}

	public function testNautilusSteeredUnderwater() : void{
		$this->pool(-12, -12, 12, 12, 8);
		$nautilus = new Nautilus($this->loc(0.5, 3, 0.5));
		$rider = $this->player(VanillaItems::AIR());
		$nautilus->setOwner($rider);
		$nautilus->setSaddled(true);
		$this->seat($rider, $nautilus);
		$this->runTicks(1);
		self::assertTrue($nautilus->isInWaterForRiding());
		self::assertTrue(self::flag($nautilus, EntityMetadataFlags::WASD_FREE_CAMERA_CONTROLLED));
		self::assertFalse(self::flag($nautilus, EntityMetadataFlags::WASD_CONTROLLED));
		self::assertTrue($nautilus->givesUnderwaterBreathingTo($rider), "the rider breathes underwater");

		//forwards while looking up (pitch -30) towards +Z: it swims forwards and up
		for($i = 0; $i < 30; ++$i){
			$nautilus->handleRiderInput($rider, new RiderInput(0.0, 1.0, 0.0, -30.0, false, false, false));
			$this->runTicks(1);
		}
		self::assertSame($rider, $nautilus->getSteeringPlayer());
		$pos = $nautilus->getPosition();
		self::assertGreaterThan(4.0, $pos->z, "swam forwards fast: " . $pos->z);
		self::assertGreaterThan(4.0, $pos->y, "and up");
		self::assertEqualsWithDelta(0.5, $pos->x, 0.5);

		//a dash in the rider's look direction
		$before = $nautilus->getPosition();
		for($i = 0; $i < 10; ++$i){
			$nautilus->handleRiderInput($rider, new RiderInput(0.0, 0.0, 90.0, 0.0, true, false, false));
			$this->runTicks(1);
		}
		$nautilus->handleRiderInput($rider, new RiderInput(0.0, 0.0, 90.0, 0.0, false, false, false));
		self::assertTrue($nautilus->hasDashCooldown());
		$this->runTicks(10);
		self::assertLessThan($before->x - 3.0, $nautilus->getPosition()->x, "dashed towards -X");
		self::assertTrue(self::flag($nautilus, EntityMetadataFlags::HAS_DASH_COOLDOWN));
		$this->runTicks(40);
		self::assertFalse($nautilus->hasDashCooldown(), "2 s cooldown");
	}

	public function testZombieNautilusDrownedRiderAndTaming() : void{
		$this->pool(-6, -6, 6, 6, 6);
		$drowned = new Drowned($this->loc(0.5, 2, 0.5));
		$drowned->spawnToAll();
		$nautilus = Jockeys::spawnDrownedRider($drowned);
		self::assertNotNull($nautilus);
		self::assertSame($nautilus, $drowned->getVehicle());
		self::assertSame(ItemTypeIds::TRIDENT, $drowned->getMainHandItem()->getTypeId());
		self::assertEqualsWithDelta(-0.2, $nautilus->getRideableSeats()[0]->position->z, 0.001, "drowned seat");
		self::assertTrue($nautilus->hasDrownedRider());
		$player = $this->player(VanillaItems::PUFFERFISH()->setCount(64));
		self::assertFalse($nautilus->canBeRiddenBy($player), "wild: only drowned");
		self::assertFalse($nautilus->onInteract($player, new Vector3(0, 0, 0)), "can't be tamed with a drowned on it");

		//the drowned rider is saved with its vehicle link
		self::assertNotNull($nautilus->saveNBT()->getTag(Entity::TAG_LINKS));

		$drowned->dismount(true);
		for($i = 0; $i < 40 && !$nautilus->isTamed(); ++$i){
			$nautilus->onInteract($player, new Vector3(0, 0, 0));
		}
		self::assertTrue($nautilus->isTamed());
		self::assertTrue($nautilus->canBeRiddenBy($player));
		self::assertFalse($nautilus->canBeRiddenBy(new Drowned($this->loc(3.5, 2, 0.5))), "tamed: players only");
		self::assertEqualsWithDelta(0.0, $nautilus->getRideableSeats()[0]->position->z, 0.001);
		$this->runTicks(1);
		self::assertTrue(self::flag($nautilus, EntityMetadataFlags::TAMED));

		//body armor keeps the sun off
		self::assertTrue($nautilus->burnsInDaylight());
		$nautilus->setNautilusArmor(VanillaItems::IRON_NAUTILUS_ARMOR());
		self::assertFalse($nautilus->burnsInDaylight());
		$nautilus->setSaddled(true);
		$loaded = new ZombieNautilus($this->loc(0.5, 2, 0.5), $nautilus->saveNBT());
		self::assertTrue($loaded->isTamed());
		self::assertTrue($loaded->isSaddled());
		self::assertSame(ItemTypeIds::IRON_NAUTILUS_ARMOR, $loaded->getNautilusArmor()->getTypeId());
		$loaded->flagForDespawn();

		//steered by its rider underwater
		$rider = $this->player(VanillaItems::AIR());
		$this->seat($rider, $nautilus);
		$start = $nautilus->getPosition();
		for($i = 0; $i < 20; ++$i){
			$nautilus->handleRiderInput($rider, new RiderInput(0.0, 1.0, 0.0, 0.0, false, false, false));
			$this->runTicks(1);
		}
		self::assertGreaterThan($start->z + 1.5, $nautilus->getPosition()->z, "swam forwards");
	}

	public function testZombieNautilusCoralVariant() : void{
		for($x = 8; $x < 12; ++$x){
			for($z = 8; $z < 12; ++$z){
				for($y = 0; $y < 4; ++$y){
					$this->world->setBiomeId($x, $y, $z, BiomeIds::WARM_OCEAN);
				}
			}
		}
		$coral = new ZombieNautilus($this->loc(9.5, 1, 9.5));
		self::assertTrue($coral->isCoral(), "spawned in a warm ocean");
		$plain = new ZombieNautilus($this->loc(-5.5, 1, -5.5));
		self::assertFalse($plain->isCoral());
		self::assertSame(1, $coral->getActorProperty("minecraft:variant"));

		//the variant is saved, wherever it is loaded
		$loaded = new ZombieNautilus($this->loc(-5.5, 1, -5.5), $coral->saveNBT());
		self::assertTrue($loaded->isCoral());
		foreach([$coral, $plain, $loaded] as $entity){
			$entity->flagForDespawn();
		}
	}
}
