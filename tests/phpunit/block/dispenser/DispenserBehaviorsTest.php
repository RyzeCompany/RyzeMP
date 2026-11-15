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

use pocketmine\block\Beehive;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Candle;
use pocketmine\block\NetherPortal;
use pocketmine\block\utils\MobHeadType;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\AiBudget;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\animal\Armadillo;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Sheep;
use pocketmine\entity\boss\Wither;
use pocketmine\entity\Entity;
use pocketmine\entity\golem\SnowGolem;
use pocketmine\entity\Location;
use pocketmine\entity\monster\MobEquipment;
use pocketmine\entity\object\ArmorStand;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\Zombie;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\SimpleInventory;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\Potion;
use pocketmine\item\PotionType;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\dimension\NetherPortalRegistry;
use pocketmine\world\redstone\RedstoneTestCase;
use function array_filter;
use function array_values;
use function file_exists;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Dispenser behaviours of tools and equipment: shears, glass bottles, armor, flint and steel, bone meal, brushes,
 * carved pumpkins, wither skulls and armor stands.
 */
final class DispenserBehaviorsTest extends RedstoneTestCase{

	private string $portalsPath = "";

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
		$this->floor(-12, -12, 12, 12);
		$this->portalsPath = sys_get_temp_dir() . "/pm-dispenser-test-portals-" . uniqid() . ".json";
		NetherPortalRegistry::setInstance(new NetherPortalRegistry($this->portalsPath));
	}

	protected function tearDown() : void{
		NetherPortalRegistry::reset();
		if(file_exists($this->portalsPath)){
			unlink($this->portalsPath);
		}
		parent::tearDown();
	}

	/**
	 * Dispenses the item from a dispenser at the given position (no block needed) and returns the new slot contents,
	 * or null if the dispenser failed.
	 */
	private function dispense(Vector3 $dispenser, int $facing, Item $item) : ?Item{
		$context = new DispenseContext($this->world, $dispenser, $facing, new SimpleInventory(9));
		return DispenseBehaviorRegistry::getInstance()->get($item)->dispense($context, $item);
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
	 * @return ItemEntity[]
	 * @phpstan-return list<ItemEntity>
	 */
	private function droppedItems(int $typeId) : array{
		return array_values(array_filter(
			$this->entities(ItemEntity::class),
			fn(ItemEntity $e) => $e->getItem()->getTypeId() === $typeId
		));
	}

	private function droppedCount(int $typeId) : int{
		$count = 0;
		foreach($this->droppedItems($typeId) as $entity){
			$count += $entity->getItem()->getCount();
		}
		return $count;
	}

	public function testShearsShearASheep() : void{
		$sheep = new Sheep(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$sheep->spawnToAll();
		$result = $this->dispense(new Vector3(0, 1, -1), Facing::SOUTH, VanillaItems::SHEARS());
		self::assertNotNull($result);
		self::assertTrue($sheep->isSheared());
		self::assertGreaterThan(0, $this->droppedCount(VanillaBlocks::WOOL()->asItem()->getTypeId()));
		self::assertInstanceOf(Durable::class, $result);
		self::assertSame(1, $result->getDamage());

		//nothing left to shear: the dispenser fails
		self::assertNull($this->dispense(new Vector3(0, 1, -1), Facing::SOUTH, $result));
	}

	public function testShearsCutALeadFirst() : void{
		$sheep = new Sheep(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$sheep->spawnToAll();
		$holder = new Cow(new Location(4.5, 1, 4.5, $this->world, 0, 0));
		$holder->spawnToAll();
		$sheep->setLeashHolder($holder);
		self::assertNotNull($this->dispense(new Vector3(0, 1, -1), Facing::SOUTH, VanillaItems::SHEARS()));
		self::assertFalse($sheep->isLeashed());
		self::assertFalse($sheep->isSheared());
		self::assertSame(1, $this->droppedCount(ItemTypeIds::LEAD));
	}

	public function testShearsHarvestAFullBeehive() : void{
		$this->set(0, 1, 0, VanillaBlocks::BEEHIVE()->setHoneyLevel(Beehive::MAX_HONEY_LEVEL));
		$result = $this->dispense(new Vector3(0, 1, -1), Facing::SOUTH, VanillaItems::SHEARS());
		self::assertInstanceOf(Durable::class, $result);
		self::assertSame(1, $result->getDamage());
		$hive = $this->get(0, 1, 0);
		self::assertInstanceOf(Beehive::class, $hive);
		self::assertSame(0, $hive->getHoneyLevel());
		self::assertSame(3, $this->droppedCount(ItemTypeIds::HONEYCOMB));
	}

	public function testGlassBottleTakesHoneyAndWater() : void{
		$this->set(0, 1, 0, VanillaBlocks::BEE_NEST()->setHoneyLevel(Beehive::MAX_HONEY_LEVEL));
		$result = $this->dispense(new Vector3(0, 1, -1), Facing::SOUTH, VanillaItems::GLASS_BOTTLE());
		self::assertNotNull($result);
		self::assertSame(ItemTypeIds::HONEY_BOTTLE, $result->getTypeId());
		$hive = $this->get(0, 1, 0);
		self::assertInstanceOf(Beehive::class, $hive);
		self::assertSame(0, $hive->getHoneyLevel());

		//flowing water fills a bottle too, and stays
		$this->set(3, 1, 0, VanillaBlocks::WATER()->setDecay(3));
		$result = $this->dispense(new Vector3(3, 1, -1), Facing::SOUTH, VanillaItems::GLASS_BOTTLE());
		self::assertInstanceOf(Potion::class, $result);
		self::assertSame(PotionType::WATER, $result->getType());
		self::assertSame(BlockTypeIds::WATER, $this->get(3, 1, 0)->getTypeId());
	}

	public function testCowRefusesArmor() : void{
		$cow = new Cow(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$cow->spawnToAll();
		$result = $this->dispense(new Vector3(0, 1, -1), Facing::SOUTH, VanillaItems::DIAMOND_CHESTPLATE());
		self::assertNotNull($result);
		self::assertTrue($cow->getArmorInventory()->getChestplate()->isNull());
		self::assertSame(1, $this->droppedCount(ItemTypeIds::DIAMOND_CHESTPLATE), "the chestplate is dropped");
	}

	public function testZombieTakesAHelmetAndBecomesPersistent() : void{
		$zombie = new Zombie(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$zombie->spawnToAll();
		$zombie->getArmorInventory()->clearAll();
		$zombie->setPersistent(false);
		$result = $this->dispense(new Vector3(0, 1, -1), Facing::SOUTH, VanillaItems::IRON_HELMET()->setCount(2));
		self::assertNotNull($result);
		self::assertSame(1, $result->getCount());
		self::assertSame(ItemTypeIds::IRON_HELMET, $zombie->getArmorInventory()->getItem(ArmorInventory::SLOT_HEAD)->getTypeId());
		self::assertTrue($zombie->isPersistent());
		$guaranteed = new \ReflectionMethod($zombie, "isGuaranteedDrop");
		self::assertTrue($guaranteed->invoke($zombie, MobEquipment::SLOT_HEAD));

		//the head slot is taken: the second helmet is dropped
		$this->dispense(new Vector3(0, 1, -1), Facing::SOUTH, $result);
		self::assertSame(1, $this->droppedCount(ItemTypeIds::IRON_HELMET));
	}

	public function testArmorStandIsPlaced() : void{
		$result = $this->dispense(new Vector3(0, 1, -1), Facing::SOUTH, VanillaItems::ARMOR_STAND()->setCount(2));
		self::assertNotNull($result);
		self::assertSame(1, $result->getCount());
		$stands = $this->entities(ArmorStand::class);
		self::assertCount(1, $stands);
		self::assertEqualsWithDelta(0.5, $stands[0]->getPosition()->z, 1e-6);

		//and it wears dispensed armor
		$this->dispense(new Vector3(0, 1, -1), Facing::SOUTH, VanillaItems::GOLDEN_BOOTS());
		self::assertSame(ItemTypeIds::GOLDEN_BOOTS, $stands[0]->getArmorInventory()->getBoots()->getTypeId());
	}

	public function testFlintAndSteelLightsAPortalFrame() : void{
		//an obsidian frame in the x/y plane at z=0: interior x 1..2, y 2..4
		$obsidian = VanillaBlocks::OBSIDIAN();
		for($x = 0; $x <= 3; ++$x){
			$this->set($x, 1, 0, $obsidian);
			$this->set($x, 5, 0, $obsidian);
		}
		for($y = 2; $y <= 4; ++$y){
			$this->set(0, $y, 0, $obsidian);
			$this->set(3, $y, 0, $obsidian);
		}
		//aimed at the middle of the frame: fire couldn't stay there (air below, nothing flammable)
		$result = $this->dispense(new Vector3(1, 3, -1), Facing::SOUTH, VanillaItems::FLINT_AND_STEEL());
		self::assertInstanceOf(Durable::class, $result);
		self::assertSame(1, $result->getDamage());
		for($x = 1; $x <= 2; ++$x){
			for($y = 2; $y <= 4; ++$y){
				self::assertInstanceOf(NetherPortal::class, $this->get($x, $y, 0));
			}
		}
	}

	public function testFlintAndSteelNeedsSomewhereForFire() : void{
		//in mid-air, nothing to burn
		self::assertNull($this->dispense(new Vector3(0, 5, -1), Facing::SOUTH, VanillaItems::FLINT_AND_STEEL()));
		self::assertSame(BlockTypeIds::AIR, $this->get(0, 5, 0)->getTypeId());
		//next to planks
		$this->set(1, 5, 0, VanillaBlocks::OAK_PLANKS());
		self::assertNotNull($this->dispense(new Vector3(0, 5, -1), Facing::SOUTH, VanillaItems::FLINT_AND_STEEL()));
		self::assertSame(BlockTypeIds::FIRE, $this->get(0, 5, 0)->getTypeId());
	}

	public function testFlintAndSteelLightsCandles() : void{
		$this->set(0, 1, 0, VanillaBlocks::CANDLE()->setCount(2));
		self::assertNotNull($this->dispense(new Vector3(0, 1, -1), Facing::SOUTH, VanillaItems::FLINT_AND_STEEL()));
		$candle = $this->get(0, 1, 0);
		self::assertInstanceOf(Candle::class, $candle);
		self::assertTrue($candle->isLit());
		//lit already: nothing to do
		self::assertNull($this->dispense(new Vector3(0, 1, -1), Facing::SOUTH, VanillaItems::FLINT_AND_STEEL()));
	}

	public function testBoneMealGrowsSeagrass() : void{
		$this->set(0, 1, 0, VanillaBlocks::SEAGRASS());
		$this->set(0, 2, 0, VanillaBlocks::WATER());
		$result = $this->dispense(new Vector3(0, 1, -1), Facing::SOUTH, VanillaItems::BONE_MEAL()->setCount(3));
		self::assertNotNull($result);
		self::assertSame(2, $result->getCount());
		self::assertSame(BlockTypeIds::TALL_SEAGRASS, $this->get(0, 1, 0)->getTypeId());
	}

	public function testBrushTakesAScuteFromAnArmadillo() : void{
		$armadillo = new Armadillo(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$armadillo->spawnToAll();
		$result = $this->dispense(new Vector3(0, 1, -1), Facing::SOUTH, VanillaItems::BRUSH());
		self::assertInstanceOf(Durable::class, $result);
		self::assertSame(16, $result->getDamage());
		self::assertSame(1, $this->droppedCount(ItemTypeIds::ARMADILLO_SCUTE));
		//nothing in front
		self::assertNull($this->dispense(new Vector3(5, 1, -1), Facing::SOUTH, VanillaItems::BRUSH()));
	}

	public function testCarvedPumpkinCompletesASnowGolem() : void{
		$this->set(0, 1, 0, VanillaBlocks::SNOW());
		$this->set(0, 2, 0, VanillaBlocks::SNOW());
		$result = $this->dispense(new Vector3(0, 3, -1), Facing::SOUTH, VanillaBlocks::CARVED_PUMPKIN()->asItem());
		self::assertNotNull($result);
		self::assertTrue($result->isNull());
		self::assertCount(1, $this->entities(SnowGolem::class));
		self::assertSame(BlockTypeIds::AIR, $this->get(0, 1, 0)->getTypeId());

		//no golem body: the pumpkin is dropped like any item
		$this->dispense(new Vector3(5, 3, -1), Facing::SOUTH, VanillaBlocks::CARVED_PUMPKIN()->asItem());
		self::assertSame(BlockTypeIds::AIR, $this->get(5, 3, 0)->getTypeId());
		self::assertCount(1, $this->droppedItems(VanillaBlocks::CARVED_PUMPKIN()->asItem()->getTypeId()));
	}

	public function testWitherSkullCompletesAWither() : void{
		$soulSand = VanillaBlocks::SOUL_SAND();
		$skull = VanillaBlocks::MOB_HEAD()->setMobHeadType(MobHeadType::WITHER_SKELETON)->setFacing(Facing::UP);
		$this->set(1, 1, 0, $soulSand);
		for($x = 0; $x <= 2; ++$x){
			$this->set($x, 2, 0, $soulSand);
		}
		$this->set(0, 3, 0, $skull);
		$this->set(1, 3, 0, $skull);
		$result = $this->dispense(new Vector3(2, 3, -1), Facing::SOUTH, VanillaBlocks::MOB_HEAD()->setMobHeadType(MobHeadType::WITHER_SKELETON)->asItem());
		self::assertNotNull($result);
		self::assertTrue($result->isNull());
		self::assertCount(1, $this->entities(Wither::class));
		self::assertSame(BlockTypeIds::AIR, $this->get(1, 2, 0)->getTypeId());
		self::assertCount(0, $this->droppedItems(VanillaBlocks::MOB_HEAD()->asItem()->getTypeId()));
	}
}
