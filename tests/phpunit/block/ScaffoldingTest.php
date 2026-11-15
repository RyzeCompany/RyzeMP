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

use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\object\FallingBlock;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\Zombie;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\redstone\RedstoneTestCase;
use function array_filter;
use function array_map;
use function array_sum;
use function array_values;

/**
 * Scaffolding placement, stability, falling and collapsing (Bedrock rules).
 */
class ScaffoldingTest extends RedstoneTestCase{

	protected function setUp() : void{
		parent::setUp();
		$this->floor(-8, -8, 12, 8);
	}

	/**
	 * Uses scaffolding on the given face of the given block, like a player would.
	 */
	private function use(int $x, int $y, int $z, int $face, int $count = 64) : bool{
		$item = VanillaBlocks::SCAFFOLDING()->asItem()->setCount($count);
		return $this->world->useItemOn(new Vector3($x, $y, $z), $item, $face, new Vector3(0.5, 0.5, 0.5));
	}

	private function useConsumes(int $x, int $y, int $z, int $face) : Item{
		$item = VanillaBlocks::SCAFFOLDING()->asItem()->setCount(10);
		$this->world->useItemOn(new Vector3($x, $y, $z), $item, $face, new Vector3(0.5, 0.5, 0.5));
		return $item;
	}

	private function scaffolding(int $x, int $y, int $z) : Scaffolding{
		$block = $this->get($x, $y, $z);
		self::assertInstanceOf(Scaffolding::class, $block, "($x, $y, $z)");
		return $block;
	}

	private function assertNoScaffolding(int $x, int $y, int $z) : void{
		self::assertNotInstanceOf(Scaffolding::class, $this->get($x, $y, $z), "($x, $y, $z)");
	}

	/**
	 * Builds a column of scaffolding on the floor, from y=1 to y=$height.
	 */
	private function column(int $x, int $z, int $height) : void{
		for($y = 1; $y <= $height; ++$y){
			self::assertTrue($this->use($x, $y - 1, $z, Facing::UP), "column ($x, $y, $z)");
		}
		$this->runTicks(2);
	}

	/**
	 * @phpstan-template T of Entity
	 * @phpstan-param class-string<T> $class
	 * @return Entity[]
	 * @phpstan-return list<T>
	 */
	private function entitiesOf(string $class) : array{
		return array_values(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof $class && !$e->isClosed() && !$e->isFlaggedForDespawn()));
	}

	private function droppedScaffolding() : int{
		return array_sum(array_map(
			fn(ItemEntity $e) => $e->getItem()->getCount(),
			array_filter($this->entitiesOf(ItemEntity::class), fn(ItemEntity $e) => $e->getItem()->equals(VanillaBlocks::SCAFFOLDING()->asItem(), false, false))
		));
	}

	public function testPlacedOnTheGroundIsStable() : void{
		self::assertTrue($this->use(0, 0, 0, Facing::UP));
		$placed = $this->scaffolding(0, 1, 0);
		self::assertSame(0, $placed->getStability());
		self::assertTrue($placed->isStabilityCheck(), "checked one tick after being placed");

		$this->runTicks(2);
		$placed = $this->scaffolding(0, 1, 0);
		self::assertSame(0, $placed->getStability());
		self::assertFalse($placed->isStabilityCheck());
	}

	public function testItemIsConsumed() : void{
		self::assertSame(9, $this->useConsumes(0, 0, 0, Facing::UP)->getCount());
		//a column extension uses one item too
		self::assertSame(9, $this->useConsumes(0, 0, 0, Facing::UP)->getCount());
		$this->scaffolding(0, 2, 0);
	}

	public function testTopFacePlacesAbove() : void{
		$this->column(0, 0, 1);
		self::assertTrue($this->use(0, 1, 0, Facing::UP));
		self::assertSame(0, $this->scaffolding(0, 2, 0)->getStability());
		$this->assertNoScaffolding(1, 1, 0);
		$this->assertNoScaffolding(-1, 1, 0);
		$this->assertNoScaffolding(0, 1, 1);
		$this->assertNoScaffolding(0, 1, -1);
	}

	public function testSideFacePlacesNextToIt() : void{
		//the player's trace: clicking a side of scaffolding put the new one 28 blocks up, on top of the column
		$this->column(0, 0, 4);
		self::assertTrue($this->use(0, 4, 0, Facing::EAST));
		self::assertSame(1, $this->scaffolding(1, 4, 0)->getStability());
		$this->assertNoScaffolding(0, 5, 0);

		self::assertTrue($this->use(1, 4, 0, Facing::SOUTH));
		self::assertSame(2, $this->scaffolding(1, 4, 1)->getStability());
	}

	public function testPlacingIntoScaffoldingGoesOnTopOfItsColumn() : void{
		$this->column(1, 0, 3);
		//clicking the side of a block whose neighbour is scaffolding
		$this->set(0, 1, 0, VanillaBlocks::STONE());
		self::assertTrue($this->use(0, 1, 0, Facing::EAST));
		$this->scaffolding(1, 4, 0);
		$this->assertNoScaffolding(1, 5, 0);
		$this->assertNoScaffolding(2, 1, 0);

		//clicking the side of scaffolding whose neighbour is scaffolding
		$this->column(2, 0, 1);
		self::assertTrue($this->use(2, 1, 0, Facing::WEST));
		$this->scaffolding(1, 5, 0);

		//clicking the floor through the scaffolding standing on it
		self::assertTrue($this->use(1, 0, 0, Facing::UP));
		$this->scaffolding(1, 6, 0);
	}

	public function testBottomFacePlacesOnTopOfTheColumn() : void{
		$this->column(0, 0, 4);
		self::assertTrue($this->use(0, 4, 0, Facing::EAST));
		$this->runTicks(2);
		//the overhang at (1, 4, 0) has air below it
		self::assertTrue($this->use(1, 4, 0, Facing::DOWN));
		$this->scaffolding(1, 5, 0);
		$this->assertNoScaffolding(1, 3, 0);
	}

	public function testColumnStopsAtTheTopOfTheWorld() : void{
		$maxY = $this->world->getMaxY();
		$this->set(0, $maxY - 2, 0, VanillaBlocks::STONE());
		$this->set(0, $maxY - 1, 0, VanillaBlocks::SCAFFOLDING());
		self::assertFalse($this->use(0, $maxY - 2, 0, Facing::UP), "the column already reaches the top");
	}

	public function testOverhangReachesSixBlocksAndTheSeventhFalls() : void{
		$this->column(0, 0, 3);
		for($x = 1; $x <= 6; ++$x){
			self::assertTrue($this->use($x - 1, 3, 0, Facing::EAST), "x=$x");
			self::assertSame($x, $this->scaffolding($x, 3, 0)->getStability());
		}
		$this->runTicks(2);
		for($x = 1; $x <= 6; ++$x){
			self::assertSame($x, $this->scaffolding($x, 3, 0)->getStability(), "x=$x stays");
		}

		//the 7th can be placed, then falls like sand
		self::assertTrue($this->use(6, 3, 0, Facing::EAST));
		self::assertSame(Scaffolding::MAX_STABILITY, $this->scaffolding(7, 3, 0)->getStability());
		$this->runTicks(2);
		$this->assertNoScaffolding(7, 3, 0);
		self::assertCount(1, $this->entitiesOf(FallingBlock::class));

		//and lands on the floor, starting a new supported column
		$this->runTicks(40);
		self::assertCount(0, $this->entitiesOf(FallingBlock::class));
		self::assertSame(0, $this->scaffolding(7, 1, 0)->getStability());
		self::assertSame(0, $this->droppedScaffolding());
	}

	public function testPlacedInMidAirFalls() : void{
		$this->set(5, 6, 5, VanillaBlocks::STONE());
		self::assertTrue($this->use(5, 6, 5, Facing::EAST));
		$this->runTicks(40);
		$this->assertNoScaffolding(6, 6, 5);
		self::assertSame(0, $this->scaffolding(6, 1, 5)->getStability());
	}

	public function testCantBePlacedWhereItCanNeitherStandNorFall() : void{
		$this->set(3, 1, 3, VanillaBlocks::OAK_FENCE());
		self::assertFalse($this->use(3, 1, 3, Facing::UP));
		$this->assertNoScaffolding(3, 2, 3);

		//but it can next to a scaffolding that supports it
		$this->column(4, 3, 2);
		self::assertTrue($this->use(3, 1, 3, Facing::UP));
		self::assertSame(1, $this->scaffolding(3, 2, 3)->getStability());
	}

	public function testCantBePlacedInLava() : void{
		$this->set(2, 1, 2, VanillaBlocks::LAVA());
		self::assertFalse($this->use(2, 0, 2, Facing::UP));
		self::assertInstanceOf(Lava::class, $this->get(2, 1, 2));
	}

	public function testCanBePlacedInsideEntities() : void{
		$zombie = new Zombie(Location::fromObject(new Vector3(2.5, 1, 2.5), $this->world));
		$zombie->spawnToAll();
		$stone = VanillaBlocks::STONE()->asItem();
		self::assertFalse($this->world->useItemOn(new Vector3(2, 0, 2), $stone, Facing::UP), "a full block can't");
		self::assertTrue($this->use(2, 0, 2, Facing::UP));
		$this->scaffolding(2, 1, 2);
	}

	public function testBreakingTheBottomBreaksTheColumnAndItsOverhang() : void{
		$this->column(0, 0, 5);
		for($x = 1; $x <= 3; ++$x){
			self::assertTrue($this->use($x - 1, 5, 0, Facing::EAST));
		}
		$this->runTicks(2);

		$this->breakBlock(0, 1, 0);
		$this->runTicks(40);
		for($y = 1; $y <= 5; ++$y){
			$this->assertNoScaffolding(0, $y, 0);
		}
		for($x = 1; $x <= 3; ++$x){
			$this->assertNoScaffolding($x, 5, 0);
		}
		//nothing falls: everything drops as items (the broken one included)
		self::assertCount(0, $this->entitiesOf(FallingBlock::class));
		self::assertSame(8, $this->droppedScaffolding());
		for($x = 0; $x <= 3; ++$x){
			for($y = 1; $y <= 5; ++$y){
				$this->assertNoScaffolding($x, $y, 0);
			}
		}
	}

	public function testOverhangLosingItsColumnBreaksInsteadOfFalling() : void{
		//the player's trace: breaking a block of a column made the overhang above fall and land on the ground
		$this->column(0, 0, 4);
		for($z = 1; $z <= 4; ++$z){
			self::assertTrue($this->use(0, 4, $z - 1, Facing::SOUTH));
		}
		$this->runTicks(2);

		$this->breakBlock(0, 3, 0);
		$this->runTicks(40);
		for($z = 0; $z <= 4; ++$z){
			$this->assertNoScaffolding(0, 4, $z);
		}
		self::assertCount(0, $this->entitiesOf(FallingBlock::class));
		self::assertSame(6, $this->droppedScaffolding());
		//the rest of the column is still there
		$this->scaffolding(0, 1, 0);
		$this->scaffolding(0, 2, 0);
		for($z = 1; $z <= 4; ++$z){
			$this->assertNoScaffolding(0, 1, $z);
		}
	}

	public function testOverhangHeldByAnotherColumnStays() : void{
		$this->column(0, 0, 3);
		$this->column(4, 0, 3);
		for($x = 1; $x <= 3; ++$x){
			self::assertTrue($this->use($x - 1, 3, 0, Facing::EAST));
		}
		$this->runTicks(2);
		self::assertSame(1, $this->scaffolding(1, 3, 0)->getStability());
		self::assertSame(1, $this->scaffolding(3, 3, 0)->getStability());

		//the column at x=4 supports the overhang from the other side
		$this->breakBlock(0, 1, 0);
		$this->runTicks(60);
		$this->assertNoScaffolding(0, 2, 0);
		//the top of the broken column now hangs from the overhang
		self::assertSame(4, $this->scaffolding(0, 3, 0)->getStability());
		self::assertSame(3, $this->scaffolding(1, 3, 0)->getStability());
		self::assertSame(2, $this->scaffolding(2, 3, 0)->getStability());
		self::assertSame(1, $this->scaffolding(3, 3, 0)->getStability());
		self::assertCount(0, $this->entitiesOf(FallingBlock::class));
	}

	public function testFallingIntoLavaDestroysIt() : void{
		$this->set(6, 1, 6, VanillaBlocks::LAVA());
		$this->set(5, 6, 6, VanillaBlocks::STONE());
		self::assertTrue($this->use(5, 6, 6, Facing::EAST));
		$this->runTicks(40);
		self::assertCount(0, $this->entitiesOf(FallingBlock::class));
		self::assertInstanceOf(Lava::class, $this->get(6, 1, 6));
		self::assertSame(0, $this->droppedScaffolding());
		for($y = 1; $y <= 6; ++$y){
			$this->assertNoScaffolding(6, $y, 6);
		}
	}

	public function testClimbingResetsFallDistance() : void{
		$this->column(0, 0, 3);
		$zombie = new Zombie(Location::fromObject(new Vector3(0.5, 2.2, 0.5), $this->world));
		$zombie->setFallDistance(5.0);
		$this->scaffolding(0, 2, 0)->onEntityInside($zombie);
		self::assertSame(0.0, $zombie->getFallDistance());

		//standing on top of it doesn't
		$zombie->teleport(new Vector3(0.5, 4, 0.5));
		$zombie->setFallDistance(5.0);
		$this->scaffolding(0, 3, 0)->onEntityInside($zombie);
		self::assertSame(5.0, $zombie->getFallDistance());
		$zombie->close();
	}

	public function testCollidesOnlyWithTheTopPlanks() : void{
		$this->column(0, 0, 1);
		$boxes = $this->scaffolding(0, 1, 0)->getCollisionBoxes();
		self::assertCount(1, $boxes);
		self::assertEqualsWithDelta(1 + 14 / 16, $boxes[0]->minY, 0.0001);
		self::assertEqualsWithDelta(2.0, $boxes[0]->maxY, 0.0001);
		self::assertTrue($this->scaffolding(0, 1, 0)->canClimb());
	}
}
