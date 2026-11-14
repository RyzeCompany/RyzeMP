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

namespace pocketmine\block\dispenser;

use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\AiBudget;
use pocketmine\entity\animal\Donkey;
use pocketmine\entity\animal\HappyGhast;
use pocketmine\entity\animal\Horse;
use pocketmine\entity\animal\Llama;
use pocketmine\entity\animal\Pig;
use pocketmine\entity\animal\Wolf;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\object\ItemEntity;
use pocketmine\inventory\HorseInventory;
use pocketmine\inventory\SimpleInventory;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\redstone\RedstoneTestCase;
use Ramsey\Uuid\Uuid;
use function array_filter;
use function count;

/**
 * Dispensers putting gear on animals (B1-07): saddles, horse and wolf armor, harnesses, carpets and chests.
 */
final class AnimalEquipmentDispenseTest extends RedstoneTestCase{

	protected function getPocketmineYml() : array{
		return [
			"redstone" => ["enabled" => true, "max-wire-updates-per-tick" => 1_000_000],
			"mobs" => ["enabled" => true, "spawning" => ["enabled" => false]]
		];
	}

	protected function setUp() : void{
		parent::setUp();
		AiBudget::configure(true, 100000, 100000, 400, requireNearbyPlayer: false);
		$this->floor(-8, -8, 8, 8);
	}

	private function dispense(Item $item) : ?Item{
		$context = new DispenseContext($this->world, new Vector3(0, 1, -1), Facing::SOUTH, new SimpleInventory(9));
		return DispenseBehaviorRegistry::getInstance()->get($item)->dispense($context, $item);
	}

	private function loc() : Location{
		return new Location(0.5, 1, 0.5, $this->world, 0, 0);
	}

	private function owner() : Player{
		$player = $this->createMock(Player::class);
		$player->method("getUniqueId")->willReturn(Uuid::uuid4());
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	private function dropped(int $typeId) : int{
		return count(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof ItemEntity && !$e->isFlaggedForDespawn() && $e->getItem()->getTypeId() === $typeId));
	}

	public function testSaddlesAPig() : void{
		$pig = new Pig($this->loc());
		$pig->spawnToAll();
		$result = $this->dispense(VanillaItems::SADDLE()->setCount(1));
		self::assertNotNull($result);
		self::assertTrue($result->isNull(), "the saddle left the dispenser");
		self::assertTrue($pig->isSaddled());
		self::assertSame(0, $this->dropped(ItemTypeIds::SADDLE));

		//only into an empty slot: a second saddle is dropped
		$this->dispense(VanillaItems::SADDLE());
		self::assertSame(1, $this->dropped(ItemTypeIds::SADDLE));
		$pig->close();
	}

	public function testBabyPigTakesNoSaddle() : void{
		$pig = new Pig($this->loc());
		$pig->makeSpawnBaby();
		$pig->spawnToAll();
		$this->dispense(VanillaItems::SADDLE());
		self::assertFalse($pig->isSaddled());
		self::assertSame(1, $this->dropped(ItemTypeIds::SADDLE));
		$pig->close();
	}

	public function testRefusesAnUntamedHorse() : void{
		$horse = new Horse($this->loc());
		$horse->spawnToAll();
		$this->dispense(VanillaItems::SADDLE());
		self::assertFalse($horse->isSaddled());
		self::assertSame(1, $this->dropped(ItemTypeIds::SADDLE));

		$horse->setOwner($this->owner());
		$this->dispense(VanillaItems::SADDLE());
		self::assertTrue($horse->isSaddled());
		$this->dispense(VanillaItems::DIAMOND_HORSE_ARMOR());
		self::assertSame(ItemTypeIds::DIAMOND_HORSE_ARMOR, $horse->getHorseArmor()->getTypeId());
		$horse->close();
	}

	public function testWolfArmorOnTamedWolfOnly() : void{
		$wolf = new Wolf($this->loc());
		$wolf->spawnToAll();
		$this->dispense(VanillaItems::WOLF_ARMOR());
		self::assertNull($wolf->getWolfArmor(), "wild wolves take no armor");
		self::assertSame(1, $this->dropped(ItemTypeIds::WOLF_ARMOR));

		$wolf->setOwner($this->owner());
		$armor = VanillaItems::WOLF_ARMOR();
		$armor->setCustomColor(DyeColor::RED->getRgbValue());
		$result = $this->dispense($armor);
		self::assertNotNull($result);
		self::assertTrue($result->isNull());
		$worn = $wolf->getWolfArmor();
		self::assertNotNull($worn);
		self::assertSame(ItemTypeIds::WOLF_ARMOR, $worn->getTypeId());
		self::assertTrue($worn->equals($armor), "the dyed armor keeps its colour");
		$wolf->close();
	}

	public function testHarnessOnHappyGhast() : void{
		$ghast = new HappyGhast(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$ghast->spawnToAll();
		$this->dispense(VanillaItems::HARNESS()->setColor(DyeColor::BLUE));
		$harness = $ghast->getHarness();
		self::assertNotNull($harness);
		self::assertSame(ItemTypeIds::HARNESS, $harness->getTypeId());
		$ghast->close();
	}

	public function testCarpetAndChestOnTamedLlama() : void{
		$llama = new Llama($this->loc());
		$llama->spawnToAll();
		$carpet = VanillaBlocks::CARPET()->setColor(DyeColor::RED)->asItem();
		$this->dispense($carpet);
		self::assertTrue($llama->getCarpet()->isNull(), "untamed llamas take no carpet");

		$llama->setOwner($this->owner());
		$this->dispense($carpet);
		self::assertSame($carpet->getTypeId(), $llama->getCarpet()->getTypeId());
		self::assertFalse($llama->isChested());
		$this->dispense(VanillaBlocks::CHEST()->asItem());
		self::assertTrue($llama->isChested());
		$llama->close();
	}

	public function testChestOnTamedDonkey() : void{
		$donkey = new Donkey($this->loc());
		$donkey->spawnToAll();
		$this->dispense(VanillaBlocks::CHEST()->asItem());
		self::assertFalse($donkey->isChested());
		$donkey->setOwner($this->owner());
		$this->dispense(VanillaBlocks::CHEST()->asItem());
		self::assertTrue($donkey->isChested());
		//a chested donkey's slot 1 is a chest slot, not an armor slot: horse armor is dropped
		$this->dispense(VanillaItems::IRON_HORSE_ARMOR());
		self::assertTrue($donkey->getInventory()->getItem(HorseInventory::SLOT_ARMOR)->isNull());
		self::assertSame(1, $this->dropped(ItemTypeIds::IRON_HORSE_ARMOR));
		$donkey->close();
	}
}
