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

namespace pocketmine\world\redstone;

use pocketmine\block\BlockTypeIds;
use pocketmine\block\Crops;
use pocketmine\block\Dispenser;
use pocketmine\block\Liquid;
use pocketmine\block\Seagrass;
use pocketmine\block\ShulkerBox;
use pocketmine\block\tile\Container;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\object\PrimedTNT;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\projectile\SmallFireball;
use pocketmine\entity\projectile\Snowball;
use pocketmine\entity\projectile\SplashPotion;
use pocketmine\entity\Zombie;
use pocketmine\inventory\Inventory;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\PotionType;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use function array_filter;
use function array_values;

class DispenserTest extends RedstoneTestCase{

	private function inventoryAt(int $x, int $y, int $z) : Inventory{
		$tile = $this->world->getTileAt($x, $y, $z);
		self::assertInstanceOf(Container::class, $tile);
		return $tile->getInventory();
	}

	private static function countItems(Inventory $inventory) : int{
		$count = 0;
		foreach($inventory->getContents() as $item){
			$count += $item->getCount();
		}
		return $count;
	}

	/**
	 * @phpstan-template T of Entity
	 * @phpstan-param class-string<T> $class
	 * @phpstan-return list<T>
	 */
	private function entitiesOf(string $class) : array{
		return array_values(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof $class && !$e->isFlaggedForDespawn()));
	}

	/**
	 * Places a dispenser (or dropper) at 0,1,0 facing east with the given items, and a redstone block trigger position
	 * at 0,1,1 (empty until fire() is called).
	 */
	private function setUpDispenser(Dispenser $block, Item ...$items) : Inventory{
		$this->floor(-2, -2, 4, 2);
		$this->set(0, 1, 0, $block->setFacing(Facing::EAST));
		$this->runTicks(1);
		$inventory = $this->inventoryAt(0, 1, 0);
		$inventory->addItem(...$items);
		return $inventory;
	}

	/**
	 * Powers the dispenser with a redstone block and waits for it to fire, then removes the power.
	 */
	private function fire() : void{
		$this->set(0, 1, 1, VanillaBlocks::REDSTONE());
		$this->runTicks(Dispenser::DISPENSE_DELAY_TICKS + 1);
		$this->set(0, 1, 1, VanillaBlocks::AIR());
		$this->runTicks(1);
	}

	public function testFiresOnRisingEdgeAfterDelay() : void{
		$inventory = $this->setUpDispenser(VanillaBlocks::DROPPER(), VanillaItems::DIAMOND()->setCount(10));
		$this->set(0, 1, 1, VanillaBlocks::REDSTONE());
		//the dispenser notices the power in the next tick, then waits 4 ticks
		$this->runTicks(Dispenser::DISPENSE_DELAY_TICKS);
		self::assertSame(10, self::countItems($inventory), "nothing happens before the delay");
		$block = $this->get(0, 1, 0);
		self::assertInstanceOf(Dispenser::class, $block);
		self::assertTrue($block->isTriggered());
		$this->runTicks(1);
		self::assertSame(9, self::countItems($inventory));

		$this->runTicks(20);
		self::assertSame(9, self::countItems($inventory), "staying powered does not fire again");

		$this->set(0, 1, 1, VanillaBlocks::AIR());
		$this->runTicks(1);
		$this->fire();
		self::assertSame(8, self::countItems($inventory));
		self::assertCount(2, $this->entitiesOf(ItemEntity::class));
	}

	/**
	 * Block updates scheduled for other reasons (World::addTile() schedules one when a chunk with the tile loads or is
	 * generated) must not make an unpowered dispenser fire.
	 */
	public function testUnrelatedBlockUpdateDoesNotFire() : void{
		$inventory = $this->setUpDispenser(VanillaBlocks::DISPENSER(), VanillaItems::ARROW()->setCount(5));
		$this->world->scheduleDelayedBlockUpdate($this->get(0, 1, 0)->getPosition(), 1);
		$this->runTicks(3);
		self::assertSame(5, self::countItems($inventory));
		self::assertCount(0, $this->entitiesOf(Arrow::class));
		//a rising edge while an unrelated update is pending still fires after the delay
		$this->world->scheduleDelayedBlockUpdate($this->get(0, 1, 0)->getPosition(), 1);
		$this->fire();
		self::assertSame(4, self::countItems($inventory));
	}

	public function testEmptyDispenserDoesNothing() : void{
		$this->setUpDispenser(VanillaBlocks::DISPENSER());
		$this->fire();
		self::assertCount(0, $this->world->getEntities());
	}

	public function testDropperPushesIntoContainer() : void{
		$inventory = $this->setUpDispenser(VanillaBlocks::DROPPER(), VanillaItems::DIAMOND()->setCount(3));
		$this->set(1, 1, 0, VanillaBlocks::CHEST());
		$this->runTicks(1);
		$this->fire();
		self::assertSame(2, self::countItems($inventory));
		self::assertSame(1, self::countItems($this->inventoryAt(1, 1, 0)));
		self::assertCount(0, $this->entitiesOf(ItemEntity::class), "nothing is dropped when the item goes into the container");
	}

	public function testDropperFacingFullContainerKeepsItem() : void{
		$inventory = $this->setUpDispenser(VanillaBlocks::DROPPER(), VanillaItems::DIAMOND());
		$this->set(1, 1, 0, VanillaBlocks::HOPPER()->setFacing(Facing::DOWN));
		$this->set(1, 0, 0, VanillaBlocks::STONE());
		$this->runTicks(1);
		$hopper = $this->inventoryAt(1, 1, 0);
		for($slot = 0; $slot < $hopper->getSize(); ++$slot){
			$hopper->setItem($slot, VanillaItems::STICK()->setCount(64));
		}
		$this->fire();
		self::assertSame(1, self::countItems($inventory));
		self::assertCount(0, $this->entitiesOf(ItemEntity::class));
	}

	public function testDispenserShootsArrow() : void{
		$inventory = $this->setUpDispenser(VanillaBlocks::DISPENSER(), VanillaItems::ARROW()->setCount(2));
		$this->fire();
		self::assertSame(1, self::countItems($inventory));
		$arrows = $this->entitiesOf(Arrow::class);
		self::assertCount(1, $arrows);
	}

	public function testDispenserThrowsProjectiles() : void{
		$this->setUpDispenser(
			VanillaBlocks::DISPENSER(),
			VanillaItems::SNOWBALL(),
			VanillaItems::SPLASH_POTION()->setType(PotionType::HEALING),
			VanillaItems::FIRE_CHARGE()
		);
		$this->fire();
		$this->fire();
		$this->fire();
		self::assertCount(1, $this->entitiesOf(Snowball::class));
		$potions = $this->entitiesOf(SplashPotion::class);
		self::assertCount(1, $potions);
		self::assertSame(PotionType::HEALING, $potions[0]->getPotionType());
		self::assertCount(1, $this->entitiesOf(SmallFireball::class));
	}

	public function testDispenserDropsOtherItems() : void{
		$this->setUpDispenser(VanillaBlocks::DISPENSER(), VanillaItems::DIAMOND());
		$this->fire();
		$items = $this->entitiesOf(ItemEntity::class);
		self::assertCount(1, $items);
		self::assertTrue($items[0]->getItem()->equals(VanillaItems::DIAMOND()));
		self::assertGreaterThan(0, $items[0]->getMotion()->x, "items fly out of the front");
	}

	public function testBuckets() : void{
		$inventory = $this->setUpDispenser(VanillaBlocks::DISPENSER(), VanillaItems::WATER_BUCKET());
		$this->fire();
		$water = $this->get(1, 1, 0);
		self::assertInstanceOf(Liquid::class, $water);
		self::assertSame(BlockTypeIds::WATER, $water->getTypeId());
		self::assertTrue($inventory->getItem(0)->equals(VanillaItems::BUCKET()), "the water bucket becomes an empty bucket");

		$this->set(1, 1, 0, VanillaBlocks::WATER());
		$this->runTicks(1);
		$this->fire();
		self::assertSame(BlockTypeIds::AIR, $this->get(1, 1, 0)->getTypeId(), "the empty bucket picks up the water source");
		self::assertTrue($inventory->getItem(0)->equals(VanillaItems::WATER_BUCKET()));
	}

	public function testEmptyBucketStackKeepsFilledBucketInside() : void{
		$inventory = $this->setUpDispenser(VanillaBlocks::DISPENSER(), VanillaItems::BUCKET()->setCount(3));
		$this->set(1, 1, 0, VanillaBlocks::LAVA());
		$this->runTicks(1);
		$this->fire();
		self::assertSame(2, $inventory->getItem(0)->getCount());
		self::assertTrue($inventory->getItem(1)->equals(VanillaItems::LAVA_BUCKET()));
	}

	public function testBoneMealGrowsCrops() : void{
		$this->setUpDispenser(VanillaBlocks::DISPENSER(), VanillaItems::BONE_MEAL()->setCount(5));
		$this->set(1, 0, 0, VanillaBlocks::FARMLAND());
		$this->set(1, 1, 0, VanillaBlocks::WHEAT());
		$this->runTicks(1);
		$this->fire();
		$wheat = $this->get(1, 1, 0);
		self::assertInstanceOf(Crops::class, $wheat);
		self::assertGreaterThan(0, $wheat->getAge());
		self::assertSame(4, self::countItems($this->inventoryAt(0, 1, 0)));
	}

	public function testBoneMealOnStoneDoesNothing() : void{
		$inventory = $this->setUpDispenser(VanillaBlocks::DISPENSER(), VanillaItems::BONE_MEAL());
		$this->set(1, 1, 0, VanillaBlocks::STONE());
		$this->runTicks(1);
		$this->fire();
		self::assertSame(1, self::countItems($inventory));
		self::assertCount(0, $this->world->getEntities());
	}

	public function testBoneMealGrowsSeagrassUnderWater() : void{
		$inventory = $this->setUpDispenser(VanillaBlocks::DISPENSER(), VanillaItems::BONE_MEAL()->setCount(3));
		//a pool 2 deep in front of the dispenser, walled in
		for($x = 0; $x <= 4; ++$x){
			for($z = -2; $z <= 2; ++$z){
				$inside = $x >= 1 && $x <= 3 && $z >= -1 && $z <= 1;
				for($y = 1; $y <= 2; ++$y){
					if(($x === 0 && $z === 0 && $y === 1) || ($x === 0 && $z === 1 && $y === 1)){
						continue;
					}
					$this->world->setBlockAt($x, $y, $z, $inside ? VanillaBlocks::WATER() : VanillaBlocks::STONE(), false);
				}
			}
		}
		$this->runTicks(1);
		$this->fire();
		self::assertSame(2, self::countItems($inventory));
		$seagrass = 0;
		for($x = 1; $x <= 3; ++$x){
			for($z = -1; $z <= 1; ++$z){
				if($this->get($x, 1, $z) instanceof Seagrass){
					++$seagrass;
				}
			}
		}
		self::assertGreaterThan(0, $seagrass);
	}

	public function testFlintAndSteel() : void{
		$inventory = $this->setUpDispenser(VanillaBlocks::DISPENSER(), VanillaItems::FLINT_AND_STEEL());
		$this->fire();
		self::assertSame(BlockTypeIds::FIRE, $this->get(1, 1, 0)->getTypeId());
		$tool = $inventory->getItem(0);
		self::assertInstanceOf(Durable::class, $tool);
		self::assertSame(1, $tool->getDamage());

		$this->set(1, 1, 0, VanillaBlocks::TNT());
		$this->runTicks(1);
		$this->fire();
		self::assertSame(BlockTypeIds::AIR, $this->get(1, 1, 0)->getTypeId());
		self::assertCount(1, $this->entitiesOf(PrimedTNT::class), "TNT in front is ignited");
	}

	public function testTntIsPrimed() : void{
		$inventory = $this->setUpDispenser(VanillaBlocks::DISPENSER(), VanillaBlocks::TNT()->asItem());
		$this->fire();
		self::assertSame(0, self::countItems($inventory));
		self::assertCount(1, $this->entitiesOf(PrimedTNT::class));
	}

	public function testShulkerBoxIsPlaced() : void{
		$this->setUpDispenser(VanillaBlocks::DISPENSER(), VanillaBlocks::SHULKER_BOX()->asItem());
		$this->fire();
		$box = $this->get(1, 1, 0);
		self::assertInstanceOf(ShulkerBox::class, $box);
		self::assertSame(Facing::EAST, $box->getFacing());
	}

	public function testArmorIsEquipped() : void{
		$this->setUpDispenser(VanillaBlocks::DISPENSER(), VanillaItems::IRON_HELMET());
		$zombie = new Zombie(Location::fromObject((new \pocketmine\math\Vector3(1.5, 1, 0.5)), $this->world));
		$zombie->spawnToAll();
		$this->fire();
		self::assertTrue($zombie->getArmorInventory()->getHelmet()->equals(VanillaItems::IRON_HELMET(), false, false));
		self::assertCount(0, $this->entitiesOf(ItemEntity::class));
	}

	public function testArmorWithoutTargetIsDropped() : void{
		$this->setUpDispenser(VanillaBlocks::DISPENSER(), VanillaItems::IRON_HELMET());
		$this->fire();
		self::assertCount(1, $this->entitiesOf(ItemEntity::class));
	}

	public function testGlassBottleFillsFromWater() : void{
		$inventory = $this->setUpDispenser(VanillaBlocks::DISPENSER(), VanillaItems::GLASS_BOTTLE());
		$this->set(1, 1, 0, VanillaBlocks::WATER());
		$this->runTicks(1);
		$this->fire();
		self::assertSame(ItemTypeIds::POTION, $inventory->getItem(0)->getTypeId());
	}

	public function testComparatorReadsDispenser() : void{
		$this->setUpDispenser(VanillaBlocks::DROPPER(), VanillaItems::DIAMOND()->setCount(64));
		$tile = $this->world->getTileAt(0, 1, 0);
		self::assertInstanceOf(\pocketmine\block\tile\ComparatorReadableContainer::class, $tile);
		self::assertSame(2, $tile->getComparatorOutput()); //1 + floor(14 * 1/9)
		self::assertCount(1, $tile->getInventory()->getContents());
	}
}
