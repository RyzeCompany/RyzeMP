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

use PHPUnit\Framework\MockObject\MockObject;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\animal\AbstractHorse;
use pocketmine\entity\animal\Animal;
use pocketmine\entity\animal\Camel;
use pocketmine\entity\animal\CamelHusk;
use pocketmine\entity\animal\Donkey;
use pocketmine\entity\animal\Horse;
use pocketmine\entity\animal\Llama;
use pocketmine\entity\animal\Pig;
use pocketmine\entity\animal\SkeletonHorse;
use pocketmine\entity\animal\ZombieHorse;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Parched;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\Zombie;
use pocketmine\inventory\HorseInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\InventoryManager;
use pocketmine\network\mcpe\protocol\types\entity\ByteMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\player\Player;
use pocketmine\world\Position;
use Ramsey\Uuid\Uuid;
use function array_values;
use function max;
use function min;

/**
 * Rideable animals (horses, camels, pigs, llamas) against Bedrock 1.26 behaviour packs: shears, the skeleton horse's
 * controls, riders thrown off under water, offspring stats, the camel's saddle screen and sitting.
 */
final class HorsesVanillaTest extends MobTestCase{

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * @phpstan-return Player&MockObject
	 */
	private function player(Item $held, Vector3 $at = new Vector3(0.5, 1, 1.5), bool $sneaking = false) : Player{
		$player = $this->createMock(Player::class);
		$inventory = new PlayerInventory($player);
		$inventory->setItemInHand($held);
		$player->method("getInventory")->willReturn($inventory);
		$player->method("hasFiniteResources")->willReturn(true);
		$player->method("getUniqueId")->willReturn(Uuid::uuid4());
		$player->method("getWorld")->willReturn($this->world);
		$player->method("getPosition")->willReturn(Position::fromObject($at, $this->world));
		$player->method("isSneaking")->willReturn($sneaking);
		$player->method("isConnected")->willReturn(false);
		$player->method("isAlive")->willReturn(true);
		$player->method("getName")->willReturn("Tester");
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	private static function flag(Entity $entity, int $flag) : bool{
		$flags = $entity->getNetworkProperties()->getAll()[$flag >= 64 ? EntityMetadataProperties::FLAGS2 : EntityMetadataProperties::FLAGS] ?? null;
		self::assertInstanceOf(LongMetadataProperty::class, $flags);
		return ($flags->getValue() & (1 << ($flag % 64))) !== 0;
	}

	private static function containerType(Entity $entity) : int{
		$type = $entity->getNetworkProperties()->getAll()[EntityMetadataProperties::CONTAINER_TYPE] ?? null;
		self::assertInstanceOf(ByteMetadataProperty::class, $type);
		return $type->getValue();
	}

	/**
	 * @return Item[]
	 */
	private function droppedItems(int $typeId) : array{
		$items = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof ItemEntity && !$entity->isClosed() && $entity->getItem()->getTypeId() === $typeId){
				$items[] = $entity->getItem();
			}
		}
		return $items;
	}

	private static function shearsDamage(Player $player) : int{
		$shears = $player->getInventory()->getItemInHand();
		self::assertInstanceOf(Durable::class, $shears);
		return $shears->getDamage();
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

	public function testShearsTakeThePigSaddleOff() : void{
		$pig = new Pig($this->loc(0.5, 1, 0.5));
		$shearer = $this->player(VanillaItems::SHEARS());
		self::assertNotSame("action.interact.removesaddle", $pig->getInteractButtonText($shearer), "nothing to take off");
		$pig->setSaddled(true);
		self::assertSame("action.interact.removesaddle", $pig->getInteractButtonText($shearer));
		self::assertNotSame("action.interact.removesaddle", $pig->getInteractButtonText($this->player(VanillaItems::SHEARS(), sneaking: true)));
		self::assertFalse($pig->onInteract($this->player(VanillaItems::SHEARS(), sneaking: true), new Vector3(0, 0, 0)), "not while sneaking");
		self::assertTrue($pig->isSaddled());

		self::assertTrue($pig->onInteract($shearer, new Vector3(0, 0, 0)));
		self::assertFalse($pig->isSaddled());
		self::assertSame(1, self::shearsDamage($shearer));
		self::assertCount(1, $this->droppedItems(ItemTypeIds::SADDLE));
		$this->runTicks(1);
		self::assertFalse(self::flag($pig, EntityMetadataFlags::SADDLED));
	}

	public function testShearsTakeHorseArmorOffThenTheSaddle() : void{
		$at = new Vector3(0.5, 1, 1.5);
		$horse = new Horse($this->loc(0.5, 1, 0.5));
		$owner = $this->player(VanillaItems::AIR(), $at);
		$horse->setSaddled(true);
		$horse->setHorseArmor(VanillaItems::DIAMOND_HORSE_ARMOR());

		$shearer = $this->player(VanillaItems::SHEARS(), $at);
		self::assertFalse($horse->onInteract($shearer, $at), "wild: nothing comes off");
		self::assertTrue($horse->isSaddled());

		$horse->setOwner($owner);
		self::assertSame("action.interact.removehorsearmor", $horse->getInteractButtonText($shearer));
		self::assertTrue($horse->onInteract($shearer, $at));
		self::assertTrue($horse->getHorseArmor()->isNull(), "armor first");
		self::assertTrue($horse->isSaddled());
		self::assertCount(1, $this->droppedItems(ItemTypeIds::DIAMOND_HORSE_ARMOR));

		self::assertSame("action.interact.removesaddle", $horse->getInteractButtonText($shearer));
		self::assertTrue($horse->onInteract($shearer, $at));
		self::assertFalse($horse->isSaddled());
		self::assertCount(1, $this->droppedItems(ItemTypeIds::SADDLE));
		self::assertSame(2, self::shearsDamage($shearer));
		self::assertFalse($horse->onInteract($shearer, $at), "nothing left: no ride with shears either");

		//a tamed zombie horse too
		$zombieHorse = new ZombieHorse($this->loc(4.5, 1, 0.5));
		$zombieHorse->setOwner($owner);
		$zombieHorse->setSaddled(true);
		self::assertTrue($zombieHorse->onInteract($this->player(VanillaItems::SHEARS(), new Vector3(4.5, 1, 1.5)), $at));
		self::assertFalse($zombieHorse->isSaddled());
	}

	public function testShearsTakeTheLlamaCarpetOff() : void{
		$at = new Vector3(0.5, 1, 1.5);
		$llama = new Llama($this->loc(0.5, 1, 0.5));
		$llama->setOwner($this->player(VanillaItems::AIR(), $at));
		$llama->setCarpet(VanillaBlocks::CARPET()->setColor(DyeColor::RED)->asItem());
		$shearer = $this->player(VanillaItems::SHEARS(), $at);
		self::assertSame("action.interact.removecarpet", $llama->getInteractButtonText($shearer));
		self::assertTrue($llama->onInteract($shearer, $at));
		self::assertTrue($llama->getCarpet()->isNull());
		self::assertSame(1, self::shearsDamage($shearer));
	}

	public function testSkeletonHorseIsSteeredWithoutASaddle() : void{
		$horse = new SkeletonHorse($this->loc(0.5, 1, 0.5));
		$player = $this->player(VanillaItems::AIR());
		self::assertFalse($horse->isSaddled());
		self::assertTrue($horse->canBeSteeredBy($player));
		self::assertFalse($horse->canWearSaddle());
		self::assertFalse($horse->canHoldInSlot(HorseInventory::SLOT_EQUIP, VanillaItems::SADDLE()), "no saddle slot");
		self::assertFalse($horse->canOpenInventory($player), "no inventory screen");
		self::assertNotSame("action.interact.saddle", $horse->getInteractButtonText($this->player(VanillaItems::SADDLE())));
		$this->runTicks(1);
		self::assertTrue(self::flag($horse, EntityMetadataFlags::WASD_CONTROLLED));
		self::assertTrue(self::flag($horse, EntityMetadataFlags::CAN_POWER_JUMP));
		self::assertSame(0, self::containerType($horse));

		//horses still need one
		$normal = new Horse($this->loc(4.5, 1, 0.5));
		$normal->setOwner($player);
		self::assertFalse($normal->canBeSteeredBy($player));
		$this->runTicks(1);
		self::assertFalse(self::flag($normal, EntityMetadataFlags::WASD_CONTROLLED));
		self::assertSame(WindowTypes::HORSE, self::containerType($normal));
	}

	public function testRidersAreThrownOffAfterTwoSecondsUnderwater() : void{
		$this->pool(-8, -8, 12, 8, 20);
		$horse = new Horse($this->loc(0.5, 1, 0.5));
		$horse->setHasGravity(false);
		$rider = new Zombie($this->loc(0.5, 1, 0.5));
		$rider->setBaby(true);
		self::assertTrue($rider->mount($horse));
		$llama = new Llama($this->loc(6.5, 1, 0.5));
		$llama->setHasGravity(false);
		$llamaRider = new Zombie($this->loc(6.5, 1, 0.5));
		$llamaRider->setBaby(true);
		$llamaPassengers = $llama->getPassengers();
		$llamaPassengers[] = $llamaRider;
		(new \ReflectionProperty(Entity::class, "passengers"))->setValue($llama, $llamaPassengers);
		$camel = new Camel($this->loc(10.5, 1, 0.5));
		$camel->setHasGravity(false);
		$camelRider = new Parched($this->loc(10.5, 1, 0.5));
		$camelPassengers = $camel->getPassengers();
		$camelPassengers[] = $camelRider;
		(new \ReflectionProperty(Entity::class, "passengers"))->setValue($camel, $camelPassengers);
		(new \ReflectionProperty(Entity::class, "vehicle"))->setValue($camelRider, $camel);

		$this->runTicks(AbstractHorse::UNDERWATER_DISMOUNT_TICKS - 2);
		self::assertTrue($horse->isUnderwater());
		self::assertSame($horse, $rider->getVehicle(), "still on it");
		self::assertSame($camel, $camelRider->getVehicle());
		$this->runTicks(4);
		self::assertNull($rider->getVehicle(), "thrown off after 2 seconds");
		self::assertCount(0, $horse->getPassengers());
		self::assertNull($camelRider->getVehicle(), "camels too");
		self::assertCount(1, $llama->getPassengers(), "llamas keep their riders");
	}

	public function testOffspringAttributeBlending() : void{
		//equal parents: the average +- 15% of the range at most
		$sum = 0.0;
		$n = 3000;
		for($i = 0; $i < $n; ++$i){
			$value = AbstractHorse::blendOffspringAttribute(0.7, 0.7, 0.4, 1.0);
			self::assertGreaterThanOrEqual(0.7 - 0.09 - 1e-9, $value);
			self::assertLessThanOrEqual(0.7 + 0.09 + 1e-9, $value);
			$sum += $value;
		}
		self::assertEqualsWithDelta(0.7, $sum / $n, 0.005, "centred on the parents' average");

		//different parents: wider spread, still centred on their average
		$sum = 0.0;
		$min = 1.0;
		$max = 0.0;
		for($i = 0; $i < $n; ++$i){
			$value = AbstractHorse::blendOffspringAttribute(0.5, 0.8, 0.4, 1.0);
			$sum += $value;
			$min = min($min, $value);
			$max = max($max, $value);
		}
		self::assertEqualsWithDelta(0.65, $sum / $n, 0.01);
		self::assertGreaterThanOrEqual(0.65 - 0.24 - 1e-9, $min);
		self::assertLessThan(0.55, $min, "spread of |a-b| + 30% of the range");
		self::assertGreaterThan(0.75, $max);

		//reflected back into the range at the ends
		for($i = 0; $i < 500; ++$i){
			$value = AbstractHorse::blendOffspringAttribute(30, 30, 15, 30);
			self::assertLessThanOrEqual(30.0, $value);
			self::assertGreaterThanOrEqual(30 - 2.25 - 1e-9, $value);
		}

		//horses blend health, movement and jump; donkeys only health
		$mare = new Horse($this->loc(0.5, 1, 0.5));
		$stallion = new Horse($this->loc(2.5, 1, 0.5));
		foreach([$mare, $stallion] as $parent){
			$parent->setMaxHealth(30);
			$parent->setMovementSpeed(0.3375);
			$parent->setJumpStrength(1.0);
		}
		$inherit = new \ReflectionMethod(AbstractHorse::class, "inheritStats");
		$foal = new Horse($this->loc(4.5, 1, 0.5));
		$inherit->invoke($mare, $foal, $stallion);
		self::assertGreaterThanOrEqual(28, $foal->getMaxHealth());
		self::assertGreaterThan(0.3375 - 0.034, $foal->getMovementSpeed());
		self::assertGreaterThan(1.0 - 0.091, $foal->getJumpStrength());

		$jenny = new Donkey($this->loc(6.5, 1, 0.5));
		$jack = new Donkey($this->loc(8.5, 1, 0.5));
		$jenny->setMaxHealth(30);
		$jack->setMaxHealth(30);
		$jenny->setMovementSpeed(0.3);
		$jack->setMovementSpeed(0.3);
		$babyDonkey = new Donkey($this->loc(10.5, 1, 0.5));
		$ownSpeed = $babyDonkey->getMovementSpeed();
		$inherit->invoke($jenny, $babyDonkey, $jack);
		self::assertGreaterThanOrEqual(28, $babyDonkey->getMaxHealth());
		self::assertSame($ownSpeed, $babyDonkey->getMovementSpeed(), "donkeys don't blend movement");
	}

	public function testZombieHorseJumpStrengthRange() : void{
		for($i = 0; $i < 30; ++$i){
			$horse = new ZombieHorse($this->loc(0.5, 1, 0.5));
			self::assertGreaterThanOrEqual(0.5, $horse->getJumpStrength());
			self::assertLessThanOrEqual(0.7, $horse->getJumpStrength());
			$horse->close();
		}
	}

	public function testHorseMarkingsMutateOneTimeInFive() : void{
		$createBaby = new \ReflectionMethod(Horse::class, "createBaby");
		$mare = new Horse($this->loc(0.5, 1, 0.5));
		$stallion = new Horse($this->loc(2.5, 1, 0.5));
		$mare->setAppearance(0, 0);
		$stallion->setAppearance(0, 0);
		$n = 1500;
		$otherMarkings = 0;
		$otherCoat = 0;
		for($i = 0; $i < $n; ++$i){
			$baby = $createBaby->invoke($mare, $stallion, $this->loc(4.5, 1, 0.5));
			self::assertInstanceOf(Horse::class, $baby);
			$otherMarkings += $baby->getMarkings() !== 0 ? 1 : 0;
			$otherCoat += $baby->getCoat() !== 0 ? 1 : 0;
			$baby->close();
		}
		//random markings 1/5 (4 of 5 differ): 16%; random coat 1/9 (6 of 7 differ): 9.5%
		self::assertEqualsWithDelta(0.16, $otherMarkings / $n, 0.04);
		self::assertEqualsWithDelta(0.095, $otherCoat / $n, 0.035);
	}

	public function testUndeadHorseBabiesNeverGrowUp() : void{
		$zombieFoal = new ZombieHorse($this->loc(0.5, 1, 0.5));
		$zombieFoal->setAge(-5);
		$skeletonFoal = new SkeletonHorse($this->loc(4.5, 1, 0.5));
		$skeletonFoal->setAge(-5);
		$foal = new Horse($this->loc(8.5, 1, 0.5));
		$foal->setAge(-5);
		$this->runTicks(10);
		self::assertTrue($zombieFoal->isBaby());
		self::assertTrue($skeletonFoal->isBaby());
		self::assertFalse($foal->isBaby(), "horse foals grow up");
		$zombieFoal->ageUp(1000);
		self::assertTrue($zombieFoal->isBaby(), "feeding doesn't make it grow either");
	}

	public function testCamelSitChanceAndSittingSaved() : void{
		$camel = new Camel($this->loc(0.5, 1, 0.5));
		$this->runTicks(1);
		$createSitGoal = new \ReflectionMethod(Camel::class, "createSitGoal");
		$goal = $createSitGoal->invoke($camel);
		self::assertInstanceOf(Goal::class, $goal);
		$tries = 40000;
		$starts = 0;
		for($i = 0; $i < $tries; ++$i){
			$starts += $goal->canStart() ? 1 : 0;
		}
		//0.001 per tick, evaluated every 3 ticks
		self::assertEqualsWithDelta(0.001 * Mob::GOAL_EVALUATION_INTERVAL, $starts / $tries, 0.0012);

		$camel->setSitting(true);
		$loaded = new Camel($this->loc(4.5, 1, 0.5), $camel->saveNBT());
		self::assertTrue($loaded->isSitting(), "continue_sitting_on_reload");
		$loadedGoal = $createSitGoal->invoke($loaded);
		self::assertInstanceOf(Goal::class, $loadedGoal);
		self::assertTrue($loadedGoal->canStart(), "the goal resumes the sitting");
	}

	public function testCamelSaddleScreen() : void{
		$at = new Vector3(0.5, 1, 1.5);
		$camel = new Camel($this->loc(0.5, 1, 0.5));
		self::assertSame(1, $camel->getInventory()->getSize());
		self::assertTrue($camel->onInteract($this->player(VanillaItems::SADDLE(), $at), $at));
		self::assertTrue($camel->isSaddled());
		self::assertSame(ItemTypeIds::SADDLE, $camel->getInventory()->getItem(HorseInventory::SLOT_EQUIP)->getTypeId());
		self::assertFalse($camel->canHoldInSlot(HorseInventory::SLOT_EQUIP, VanillaItems::DIAMOND_HORSE_ARMOR()));

		$player = $this->player(VanillaItems::AIR(), $at, sneaking: true);
		self::assertTrue($camel->canOpenInventory($player));
		self::assertSame("action.interact.opencontainer", $camel->getInteractButtonText($player));
		self::assertFalse($camel->canOpenInventory($this->player(VanillaItems::AIR(), new Vector3(20, 1, 0.5))), "too far");
		$this->runTicks(1);
		self::assertSame(WindowTypes::HORSE, self::containerType($camel));
		$slots = InventoryManager::createHorseEquipPacket(3, $camel->getInventory())->nbt->getRoot()->getListTag("slots");
		self::assertNotNull($slots);
		self::assertCount(1, $slots, "a saddle slot only");

		//saved like Bedrock's container component; older saves had only the Saddled byte
		$loaded = new Camel($this->loc(4.5, 1, 0.5), $camel->saveNBT());
		self::assertTrue($loaded->isSaddled());
		$old = new Camel($this->loc(8.5, 1, 0.5), CompoundTag::create()->setByte(Camel::TAG_SADDLED, 1));
		self::assertTrue($old->isSaddled());

		//taking the saddle out through the screen stops the steering
		$camel->getInventory()->clear(HorseInventory::SLOT_EQUIP);
		self::assertFalse($camel->canBeSteeredBy($player));
		$shearer = $this->player(VanillaItems::SHEARS(), $at);
		self::assertTrue($loaded->onInteract($shearer, $at));
		self::assertFalse($loaded->isSaddled());
		self::assertSame(1, self::shearsDamage($shearer));

		$baby = new Camel($this->loc(12.5, 1, 0.5));
		$baby->setAge(Animal::BABY_AGE);
		self::assertFalse($baby->canOpenInventory($player), "babies have no inventory");

		//camel husks: no saddle screen while a hostile mob rides it
		$husk = new CamelHusk($this->loc(16.5, 1, 0.5));
		self::assertTrue($husk->canOpenInventory($this->player(VanillaItems::AIR(), new Vector3(16.5, 1, 1.5))));
		$parched = new Parched($this->loc(16.5, 1, 0.5));
		self::assertTrue($parched->mount($husk));
		self::assertFalse($husk->canOpenInventory($this->player(VanillaItems::AIR(), new Vector3(16.5, 1, 1.5))));
		self::assertSame([$parched], array_values($husk->getPassengers()));
	}
}
