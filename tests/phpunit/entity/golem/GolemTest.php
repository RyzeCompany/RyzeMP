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

namespace pocketmine\entity\golem;

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\CopperChest;
use pocketmine\block\CopperGolemStatue;
use pocketmine\block\tile\Chest as TileChest;
use pocketmine\block\utils\CopperOxidation;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Blaze;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\projectile\Snowball;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\Inventory;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use function array_filter;
use function array_values;

final class GolemTest extends MobTestCase{

	/**
	 * @phpstan-param array<string, Block> $blocks "x:y:z" => block
	 * @phpstan-return \Closure(int, int, int) : Block
	 */
	private static function blocks(array $blocks) : \Closure{
		return fn(int $x, int $y, int $z) : Block => $blocks["$x:$y:$z"] ?? VanillaBlocks::AIR();
	}

	/**
	 * @phpstan-template T of Entity
	 * @phpstan-param class-string<T> $class
	 * @phpstan-return list<T>
	 */
	private function entitiesOf(string $class) : array{
		return array_values(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof $class && !$e->isClosed() && !$e->isFlaggedForDespawn()));
	}

	public function testIronGolemPatternInBothDirections() : void{
		foreach([[1, 0], [0, 1]] as [$dx, $dz]){
			$blocks = [
				"0:3:0" => VanillaBlocks::CARVED_PUMPKIN(),
				"0:2:0" => VanillaBlocks::IRON(),
				"$dx:2:$dz" => VanillaBlocks::IRON(),
				-$dx . ":2:" . -$dz => VanillaBlocks::IRON(),
				"0:1:0" => VanillaBlocks::IRON()
			];
			$match = GolemConstruction::findPattern(self::blocks($blocks), 0, 3, 0);
			self::assertNotNull($match);
			self::assertSame(GolemConstruction::IRON_GOLEM, $match[0]);
			self::assertCount(5, $match[1]);
			self::assertTrue($match[2]->equals(new Vector3(0, 1, 0)));

			//a block beside the legs breaks the pattern
			$blocks["$dx:1:$dz"] = VanillaBlocks::DIRT();
			self::assertNull(GolemConstruction::findPattern(self::blocks($blocks), 0, 3, 0));
		}
	}

	public function testPatternsNeedTheHeadAndTheRightBody() : void{
		$snow = ["0:2:0" => VanillaBlocks::SNOW(), "0:1:0" => VanillaBlocks::SNOW()];
		self::assertNull(GolemConstruction::findPattern(self::blocks($snow), 0, 3, 0), "no head");
		self::assertSame(GolemConstruction::SNOW_GOLEM, GolemConstruction::findPattern(self::blocks($snow + ["0:3:0" => VanillaBlocks::LIT_PUMPKIN()]), 0, 3, 0)[0] ?? null);
		self::assertNull(GolemConstruction::findPattern(self::blocks(["0:3:0" => VanillaBlocks::PUMPKIN()] + $snow), 0, 3, 0), "uncarved pumpkin");
		self::assertNull(GolemConstruction::findPattern(self::blocks(["0:3:0" => VanillaBlocks::CARVED_PUMPKIN(), "0:2:0" => VanillaBlocks::CUT_COPPER()]), 0, 3, 0), "cut copper");
		self::assertNull(GolemConstruction::findPattern(self::blocks(["0:3:0" => VanillaBlocks::CARVED_PUMPKIN(), "0:2:0" => VanillaBlocks::IRON(), "0:1:0" => VanillaBlocks::IRON()]), 0, 3, 0), "iron without arms");
	}

	public function testPlacingThePumpkinBuildsASnowGolem() : void{
		$this->set(0, 1, 0, VanillaBlocks::SNOW());
		$this->set(0, 2, 0, VanillaBlocks::SNOW());
		$this->set(0, 3, 0, VanillaBlocks::CARVED_PUMPKIN());
		$this->get(0, 3, 0)->onPostPlace();
		$golems = $this->entitiesOf(SnowGolem::class);
		self::assertCount(1, $golems);
		self::assertEqualsWithDelta(1.05, $golems[0]->getPosition()->y, 0.001);
		for($y = 1; $y <= 3; ++$y){
			self::assertSame(BlockTypeIds::AIR, $this->get(0, $y, 0)->getTypeId());
		}
	}

	public function testBuiltIronGolemIsPlayerCreated() : void{
		$this->set(0, 1, 0, VanillaBlocks::IRON());
		$this->set(-1, 2, 0, VanillaBlocks::IRON());
		$this->set(0, 2, 0, VanillaBlocks::IRON());
		$this->set(1, 2, 0, VanillaBlocks::IRON());
		$this->set(0, 3, 0, VanillaBlocks::CARVED_PUMPKIN());
		$golem = GolemConstruction::trySpawnGolem($this->world, new Vector3(0, 3, 0));
		self::assertInstanceOf(IronGolem::class, $golem);
		self::assertTrue($golem->isPlayerCreated());
		self::assertSame(BlockTypeIds::AIR, $this->get(1, 2, 0)->getTypeId());
	}

	public function testCopperGolemLeavesACopperChest() : void{
		$this->set(0, 1, 0, VanillaBlocks::COPPER()->setOxidation(CopperOxidation::WEATHERED)->setWaxed(true));
		$this->set(0, 2, 0, VanillaBlocks::CARVED_PUMPKIN()->setFacing(Facing::EAST));
		$golem = GolemConstruction::trySpawnGolem($this->world, new Vector3(0, 2, 0));
		self::assertInstanceOf(CopperGolem::class, $golem);
		self::assertSame(CopperOxidation::WEATHERED->value, $golem->getOxidationLevel());
		self::assertEqualsWithDelta(2.05, $golem->getPosition()->y, 0.001);
		$chest = $this->get(0, 1, 0);
		self::assertInstanceOf(CopperChest::class, $chest);
		self::assertSame(CopperOxidation::WEATHERED, $chest->getOxidation());
		self::assertTrue($chest->isWaxed());
		self::assertSame(Facing::EAST, $chest->getFacing());
		self::assertSame(BlockTypeIds::AIR, $this->get(0, 2, 0)->getTypeId());
	}

	private function chestInventory(int $x, int $y, int $z) : Inventory{
		$tile = $this->world->getTile(new Vector3($x, $y, $z));
		self::assertInstanceOf(TileChest::class, $tile);
		return $tile->getRealInventory();
	}

	private static function itemCount(Inventory $inventory, int $typeId) : int{
		$count = 0;
		foreach($inventory->getContents() as $item){
			if($item->getTypeId() === $typeId){
				$count += $item->getCount();
			}
		}
		return $count;
	}

	public function testInventoryHelpers() : void{
		$this->set(0, 1, 0, VanillaBlocks::CHEST());
		$inventory = $this->chestInventory(0, 1, 0);
		self::assertTrue(CopperGolemTransportItemsGoal::acceptsItem($inventory, VanillaItems::DIAMOND()), "empty chest");
		$inventory->setItem(5, VanillaItems::STICK()->setCount(60));
		self::assertFalse(CopperGolemTransportItemsGoal::acceptsItem($inventory, VanillaItems::DIAMOND()));
		self::assertTrue(CopperGolemTransportItemsGoal::acceptsItem($inventory, VanillaItems::STICK()));
		$left = CopperGolemTransportItemsGoal::addItems($inventory, VanillaItems::STICK()->setCount(10));
		self::assertTrue($left->isNull());
		self::assertSame(10, $inventory->getItem(0)->getCount(), "first empty slot comes first");

		$taken = CopperGolemTransportItemsGoal::takeItems($inventory);
		self::assertNotNull($taken);
		self::assertSame(10, $taken->getCount());
		$taken = CopperGolemTransportItemsGoal::takeItems($inventory);
		self::assertNotNull($taken);
		self::assertSame(16, $taken->getCount(), "at most 16 at a time");
		self::assertSame(44, $inventory->getItem(5)->getCount());
	}

	public function testCopperGolemMovesItemsFromCopperChestToChest() : void{
		$this->set(4, 1, 0, VanillaBlocks::COPPER_CHEST());
		$this->set(-4, 1, 0, VanillaBlocks::CHEST());
		$this->set(0, 1, 5, VanillaBlocks::CHEST());
		$this->chestInventory(4, 1, 0)->setItem(3, VanillaBlocks::COBBLESTONE()->asItem()->setCount(20));
		$this->chestInventory(0, 1, 5)->setItem(0, VanillaItems::DIAMOND());
		$golem = new CopperGolem(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$golem->spawnToAll();

		$cobblestone = VanillaBlocks::COBBLESTONE()->asItem()->getTypeId();
		$destination = $this->chestInventory(-4, 1, 0);
		for($i = 0; $i < 2000 && self::itemCount($destination, $cobblestone) < 20; ++$i){
			$this->runTicks(1);
		}
		self::assertSame(20, self::itemCount($destination, $cobblestone), "all items moved in two trips");
		self::assertSame(0, self::itemCount($this->chestInventory(4, 1, 0), $cobblestone));
		self::assertSame(0, self::itemCount($this->chestInventory(0, 1, 5), $cobblestone), "chest with other items skipped");
		$golem->close();
	}

	public function testOxidizedCopperGolemTurnsIntoStatueAndBack() : void{
		$golem = new CopperGolem(new Location(0.5, 1, 0.5, $this->world, 90, 0));
		$golem->setOxidationLevel(3);
		$golem->setMainHandItem(VanillaItems::STICK());
		self::assertTrue($golem->turnToStatue());
		self::assertTrue($golem->isFlaggedForDespawn());
		$statue = $this->get(0, 1, 0);
		self::assertInstanceOf(CopperGolemStatue::class, $statue);
		self::assertSame(CopperOxidation::OXIDIZED, $statue->getOxidation());
		self::assertSame(Facing::WEST, $statue->getFacing());

		//scrape it back to unoxidized, then once more to wake the golem up
		$axe = VanillaItems::IRON_AXE();
		for($i = 0; $i < 3; ++$i){
			self::assertTrue($this->get(0, 1, 0)->onInteract($axe, Facing::UP, new Vector3(0.5, 0.5, 0.5)));
		}
		self::assertSame(CopperOxidation::NONE, $this->getStatue()->getOxidation());
		self::assertTrue($this->get(0, 1, 0)->onInteract($axe, Facing::UP, new Vector3(0.5, 0.5, 0.5)));
		self::assertSame(BlockTypeIds::AIR, $this->get(0, 1, 0)->getTypeId());
		$golems = $this->entitiesOf(CopperGolem::class);
		self::assertCount(1, $golems);
		self::assertSame(0, $golems[0]->getOxidationLevel());
	}

	private function getStatue() : CopperGolemStatue{
		$statue = $this->get(0, 1, 0);
		self::assertInstanceOf(CopperGolemStatue::class, $statue);
		return $statue;
	}

	public function testIronGolemDefendsHurtVillager() : void{
		$villager = new VillagerV2(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$zombie = new Zombie(new Location(2.5, 1, 0.5, $this->world, 0, 0));
		$golem = new IronGolem(new Location(8.5, 1, 0.5, $this->world, 0, 0));
		$far = new IronGolem(new Location(-20.5, 1, 0.5, $this->world, 0, 0));
		$villager->attack(new EntityDamageByEntityEvent($zombie, $villager, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		self::assertSame($zombie, $golem->getAttackTarget());
		self::assertNull($far->getAttackTarget(), "out of range");
		foreach([$villager, $zombie, $golem, $far] as $entity){
			$entity->close();
		}
	}

	public function testIronGolemOffersFlower() : void{
		$villager = new VillagerV2(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$golem = new IronGolem(new Location(3.5, 1, 0.5, $this->world, 0, 0));
		$goal = new IronGolemOfferFlowerGoal($golem);
		$goal->start();
		self::assertTrue($golem->isOfferingFlower());
		$goal->stop();
		self::assertFalse($golem->isOfferingFlower());
		$villager->close();
		$golem->close();
	}

	public function testSnowballHurtsOnlyBlazes() : void{
		$blaze = new Blaze(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$zombie = new Zombie(new Location(4.5, 1, 0.5, $this->world, 0, 0));
		foreach([$blaze, $zombie] as $target){
			$health = $target->getHealth();
			$snowball = new Snowball(new Location($target->getPosition()->x, 3, 0.5, $this->world, 0, 0), null);
			$hit = new RayTraceResult($target->getBoundingBox(), Facing::UP, $target->getPosition());
			(fn() => $this->onHitEntity($target, $hit))->call($snowball);
			$snowball->close();
			self::assertEqualsWithDelta($target instanceof Blaze ? $health - Snowball::BLAZE_DAMAGE : $health, $target->getHealth(), 0.001);
			$target->close();
		}
	}

	public function testCopperGolemSaveRoundTrip() : void{
		$golem = new CopperGolem(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$golem->setOxidationLevel(2);
		$golem->setWaxed(true);
		$golem->setMainHandItem(VanillaItems::STICK()->setCount(7));
		$nbt = $golem->saveNBT();
		$golem->close();
		$copy = new CopperGolem(new Location(0.5, 1, 0.5, $this->world, 0, 0), $nbt);
		self::assertSame(2, $copy->getOxidationLevel());
		self::assertTrue($copy->isWaxed());
		self::assertSame(7, $copy->getMainHandItem()->getCount());
		self::assertGreaterThanOrEqual(CopperGolem::OXIDATION_MIN_TICKS, $nbt->getInt(CopperGolem::TAG_OXIDATION_TIMER));
		$copy->close();
	}
}
