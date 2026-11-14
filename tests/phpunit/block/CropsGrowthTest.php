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

namespace pocketmine\block;

use pocketmine\block\utils\DirtType;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Chicken;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Zombie;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\GameRules;
use function mt_srand;

/**
 * Farming rules: dry farmland under crops, trampling, sugar cane, cocoa, nether wart, mycelium, cactus flowers and
 * dirt paths.
 */
final class CropsGrowthTest extends MobTestCase{

	protected function tearDown() : void{
		mt_srand();
		parent::tearDown();
	}

	private function dryFarmland(int $x, int $z) : void{
		$this->set($x, 0, $z, VanillaBlocks::FARMLAND()->setWetness(0));
	}

	public function testDryFarmlandKeepsCrops() : void{
		$this->dryFarmland(0, 0);
		$this->set(0, 1, 0, VanillaBlocks::WHEAT());
		$this->dryFarmland(4, 0);
		$this->set(4, 1, 0, VanillaBlocks::MELON_STEM());
		$this->dryFarmland(8, 0);

		for($i = 0; $i < 5; ++$i){
			$this->get(0, 0, 0)->onRandomTick();
			$this->get(4, 0, 0)->onRandomTick();
		}
		$this->get(8, 0, 0)->onRandomTick();

		self::assertInstanceOf(Farmland::class, $this->get(0, 0, 0));
		self::assertInstanceOf(Wheat::class, $this->get(0, 1, 0));
		self::assertInstanceOf(Farmland::class, $this->get(4, 0, 0));
		self::assertInstanceOf(Dirt::class, $this->get(8, 0, 0), "bare dry farmland turns to dirt");
	}

	private function landOnFarmland(Living $entity) : bool{
		$this->dryFarmland(0, 0);
		$entity->setFallDistance(20);
		$farmland = $this->get(0, 0, 0);
		$farmland->onEntityLand($entity);
		$trampled = !$this->get(0, 0, 0) instanceof Farmland;
		$entity->close();
		return $trampled;
	}

	public function testTrampling() : void{
		$at = fn() => new Location(0.5, 1, 0.5, $this->world, 0, 0);

		self::assertFalse($this->landOnFarmland(new Chicken($at())), "chickens are too small");

		$baby = new Zombie($at());
		$baby->setBaby(true);
		self::assertFalse($this->landOnFarmland($baby), "baby zombies are too small");

		self::assertTrue($this->landOnFarmland(new Zombie($at())));
		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		self::assertFalse($this->landOnFarmland(new Zombie($at())), "mobs need mobGriefing");

		//players trample regardless of mobGriefing
		$player = $this->createMock(Player::class);
		$player->method("getSize")->willReturn(new EntitySizeInfo(1.8, 0.6));
		//keep the mock from running Player::onDispose() when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		self::assertTrue((new \ReflectionMethod(Farmland::class, "canTrample"))->invoke(null, $player));
	}

	/**
	 * Sand with water next to it at y=0, cane from y=1.
	 */
	private function caneColumn(int $height) : void{
		$this->set(0, 0, 0, VanillaBlocks::SAND());
		$this->set(1, 0, 0, VanillaBlocks::WATER());
		$this->set(1, -1, 0, VanillaBlocks::STONE());
		for($y = 1; $y <= $height; ++$y){
			$this->set(0, $y, 0, VanillaBlocks::SUGARCANE());
		}
	}

	public function testSugarcaneGrowsOneBlockFromTheTop() : void{
		$this->caneColumn(1);
		for($i = 0; $i < Sugarcane::MAX_AGE; ++$i){
			$this->get(0, 1, 0)->onRandomTick();
		}
		self::assertSame(BlockTypeIds::AIR, $this->get(0, 2, 0)->getTypeId());
		$this->get(0, 1, 0)->onRandomTick();
		self::assertInstanceOf(Sugarcane::class, $this->get(0, 2, 0));
		self::assertSame(BlockTypeIds::AIR, $this->get(0, 3, 0)->getTypeId(), "one cane per growth");

		//the bottom cane no longer grows: the top one does
		for($i = 0; $i < 40; ++$i){
			$this->get(0, 1, 0)->onRandomTick();
		}
		self::assertSame(BlockTypeIds::AIR, $this->get(0, 3, 0)->getTypeId());
		for($i = 0; $i < 16; ++$i){
			$this->get(0, 2, 0)->onRandomTick();
		}
		self::assertInstanceOf(Sugarcane::class, $this->get(0, 3, 0));

		//full height: no more growth
		for($i = 0; $i < 40; ++$i){
			$this->get(0, 3, 0)->onRandomTick();
		}
		self::assertSame(BlockTypeIds::AIR, $this->get(0, 4, 0)->getTypeId());
	}

	public function testMatureCocoaDropsThree() : void{
		$cocoa = VanillaBlocks::COCOA_POD()->setAge(CocoaBlock::MAX_AGE);
		for($i = 0; $i < 20; ++$i){
			self::assertSame(3, $cocoa->getDropsForCompatibleTool(VanillaItems::AIR())[0]->getCount());
		}
	}

	public function testNetherWartGrowthChance() : void{
		mt_srand(42);
		$this->set(0, 0, 0, VanillaBlocks::SOUL_SAND());
		$grown = 0;
		$rolls = 20000;
		for($i = 0; $i < $rolls; ++$i){
			$this->set(0, 1, 0, VanillaBlocks::NETHER_WART());
			$this->get(0, 1, 0)->onRandomTick();
			$block = $this->get(0, 1, 0);
			self::assertInstanceOf(NetherWartPlant::class, $block);
			if($block->getAge() > 0){
				++$grown;
			}
		}
		//1 in 10 (1 in 11 would be ~1818)
		self::assertGreaterThan(1900, $grown);
		self::assertLessThan(2100, $grown);
	}

	public function testMyceliumNeedsLightAndDiesCovered() : void{
		$this->set(0, 0, 0, VanillaBlocks::MYCELIUM());
		$this->set(1, 0, 0, VanillaBlocks::DIRT());
		//the test world has no light: no spreading
		for($i = 0; $i < 200; ++$i){
			$this->get(0, 0, 0)->onRandomTick();
		}
		self::assertInstanceOf(Dirt::class, $this->get(1, 0, 0));
		self::assertInstanceOf(Mycelium::class, $this->get(0, 0, 0));

		//a single snow layer and glass keep it alive
		$this->set(0, 1, 0, VanillaBlocks::SNOW_LAYER());
		$this->get(0, 0, 0)->onRandomTick();
		self::assertInstanceOf(Mycelium::class, $this->get(0, 0, 0));
		$this->set(0, 1, 0, VanillaBlocks::GLASS());
		$this->get(0, 0, 0)->onRandomTick();
		self::assertInstanceOf(Mycelium::class, $this->get(0, 0, 0));

		$this->set(0, 1, 0, VanillaBlocks::STONE());
		$this->get(0, 0, 0)->onRandomTick();
		self::assertInstanceOf(Dirt::class, $this->get(0, 0, 0), "covered by stone");

		$this->set(0, 0, 0, VanillaBlocks::MYCELIUM());
		$this->set(0, 1, 0, VanillaBlocks::WATER());
		$this->get(0, 0, 0)->onRandomTick();
		self::assertInstanceOf(Dirt::class, $this->get(0, 0, 0), "under water");
	}

	private function cactusAt(int $age) : void{
		$this->set(0, 0, 0, VanillaBlocks::SAND());
		$this->set(0, 1, 0, VanillaBlocks::CACTUS()->setAge($age));
		$this->set(0, 2, 0, VanillaBlocks::AIR());
	}

	public function testCactusFlowerAtAgeEightWithoutReset() : void{
		mt_srand(7);
		$flowered = false;
		for($i = 0; $i < 500 && !$flowered; ++$i){
			$this->cactusAt(8);
			$this->get(0, 1, 0)->onRandomTick();
			$cactus = $this->get(0, 1, 0);
			self::assertInstanceOf(Cactus::class, $cactus);
			self::assertSame(9, $cactus->getAge(), "the age keeps counting");
			$flowered = $this->get(0, 2, 0)->getTypeId() === BlockTypeIds::CACTUS_FLOWER;
		}
		self::assertTrue($flowered);

		//no flower at any other age
		for($i = 0; $i < 300; ++$i){
			$this->cactusAt(9);
			$this->get(0, 1, 0)->onRandomTick();
			self::assertSame(BlockTypeIds::AIR, $this->get(0, 2, 0)->getTypeId());
		}
	}

	public function testFullHeightCactusStopsAgeing() : void{
		$this->set(0, 0, 0, VanillaBlocks::SAND());
		$this->set(0, 1, 0, VanillaBlocks::CACTUS());
		$this->set(0, 2, 0, VanillaBlocks::CACTUS());
		$this->set(0, 3, 0, VanillaBlocks::CACTUS()->setAge(Cactus::MAX_AGE));
		$this->get(0, 3, 0)->onRandomTick();
		$top = $this->get(0, 3, 0);
		self::assertInstanceOf(Cactus::class, $top);
		self::assertSame(Cactus::MAX_AGE, $top->getAge());
		self::assertSame(BlockTypeIds::AIR, $this->get(0, 4, 0)->getTypeId());
	}

	public function testShovelPathsAndHoeOnPath() : void{
		$click = new Vector3(0.5, 1, 0.5);
		$sources = [
			VanillaBlocks::DIRT(),
			VanillaBlocks::DIRT()->setDirtType(DirtType::COARSE),
			VanillaBlocks::DIRT()->setDirtType(DirtType::ROOTED),
			VanillaBlocks::PODZOL(),
			VanillaBlocks::MYCELIUM(),
		];
		foreach($sources as $i => $source){
			$this->set($i, 0, 0, $source);
			self::assertTrue($this->get($i, 0, 0)->onInteract(VanillaItems::WOODEN_SHOVEL(), Facing::UP, $click), $source->getName());
			self::assertInstanceOf(GrassPath::class, $this->get($i, 0, 0), $source->getName());
		}

		//needs air above
		$this->set(0, 0, 2, VanillaBlocks::PODZOL());
		$this->set(0, 1, 2, VanillaBlocks::STONE());
		self::assertFalse($this->get(0, 0, 2)->onInteract(VanillaItems::WOODEN_SHOVEL(), Facing::UP, $click));
		self::assertInstanceOf(Podzol::class, $this->get(0, 0, 2));

		self::assertTrue($this->get(0, 0, 0)->onInteract(VanillaItems::WOODEN_HOE(), Facing::UP, $click));
		self::assertInstanceOf(Farmland::class, $this->get(0, 0, 0));
	}
}
