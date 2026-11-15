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

use pocketmine\block\utils\DripstoneThickness;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\Location;
use pocketmine\entity\object\FallingBlock;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\projectile\Trident;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use pocketmine\world\GameRules;
use pocketmine\world\redstone\RedstoneTestCase;
use function array_filter;
use function array_values;

/**
 * Pointed dripstone growth, falling stalactites, stalagmite fall damage and tridents. The world is ticked by hand.
 */
final class PointedDripstoneFallGrowTest extends RedstoneTestCase{
	private const ROOT_Y = 30;

	/**
	 * Builds the given ceiling block (with the given block above it) and a stalactite of the given length below it,
	 * and returns the top block of the stalactite.
	 */
	private function buildStalactite(Block $ceiling, ?Block $above, int $length, ?PointedDripstone $type = null) : PointedDripstone{
		$type ??= VanillaBlocks::POINTED_DRIPSTONE();
		$this->set(0, self::ROOT_Y, 0, $ceiling);
		if($above !== null){
			$this->set(0, self::ROOT_Y + 1, 0, $above);
		}
		for($i = 1; $i <= $length; ++$i){
			$thickness = $i === $length ? DripstoneThickness::TIP : ($i === $length - 1 ? DripstoneThickness::FRUSTUM : DripstoneThickness::BASE);
			$this->set(0, self::ROOT_Y - $i, 0, (clone $type)->setHanging(true)->setThickness($thickness));
		}
		$top = $this->get(0, self::ROOT_Y - 1, 0);
		self::assertInstanceOf(PointedDripstone::class, $top);
		return $top;
	}

	private function dripstoneAt(int $y) : ?PointedDripstone{
		$block = $this->get(0, $y, 0);
		return $block instanceof PointedDripstone ? $block : null;
	}

	/**
	 * @phpstan-template T of \pocketmine\entity\Entity
	 * @phpstan-param class-string<T> $class
	 * @phpstan-return list<T>
	 */
	private function entitiesOf(string $class) : array{
		return array_values(array_filter($this->world->getEntities(), fn($e) => $e instanceof $class && !$e->isClosed() && !$e->isFlaggedForDespawn()));
	}

	/**
	 * Grows the stalactite and runs a tick for the neighbour updates (thickness).
	 */
	private function grow(?PointedDripstone $top, bool $growStalactite) : bool{
		self::assertNotNull($top);
		$grown = $top->growStalactiteOrStalagmite($growStalactite);
		$this->runTicks(1);
		return $grown;
	}

	public function testStalactitesTickRandomly() : void{
		self::assertTrue(VanillaBlocks::POINTED_DRIPSTONE()->setHanging(true)->ticksRandomly());
		self::assertTrue(VanillaBlocks::SULFUR_SPIKE()->setHanging(true)->ticksRandomly());
		self::assertFalse(VanillaBlocks::POINTED_DRIPSTONE()->setHanging(false)->ticksRandomly());
	}

	public function testGrowthNeedsWaterAboveTheDripstoneBlock() : void{
		$top = $this->buildStalactite(VanillaBlocks::DRIPSTONE_BLOCK(), VanillaBlocks::WATER(), 2);
		self::assertTrue($top->canGrow());
		self::assertTrue($this->grow($top, true));
		self::assertSame(DripstoneThickness::TIP, $this->dripstoneAt(self::ROOT_Y - 3)?->getThickness());
		self::assertTrue($this->dripstoneAt(self::ROOT_Y - 3)?->isHanging());
		self::assertSame(DripstoneThickness::FRUSTUM, $this->dripstoneAt(self::ROOT_Y - 2)?->getThickness());
		self::assertSame(DripstoneThickness::BASE, $this->dripstoneAt(self::ROOT_Y - 1)?->getThickness());

		$this->set(0, self::ROOT_Y + 1, 0, VanillaBlocks::AIR());
		self::assertFalse($this->dripstoneAt(self::ROOT_Y - 1)?->canGrow(), "no water above");
		$this->set(0, self::ROOT_Y + 1, 0, VanillaBlocks::WATER()->setDecay(2));
		self::assertFalse($this->dripstoneAt(self::ROOT_Y - 1)?->canGrow(), "flowing water doesn't count");

		$top = $this->buildStalactite(VanillaBlocks::STONE(), VanillaBlocks::WATER(), 1);
		self::assertFalse($top->canGrow(), "stone isn't a dripstone block");
		self::assertFalse($this->grow($top, true));
	}

	public function testOnlyTheTopBlockGrowsTheStalactite() : void{
		$this->buildStalactite(VanillaBlocks::DRIPSTONE_BLOCK(), VanillaBlocks::WATER(), 2);
		self::assertFalse($this->grow($this->dripstoneAt(self::ROOT_Y - 2), true));
		self::assertNull($this->dripstoneAt(self::ROOT_Y - 3));
	}

	public function testGrowthStopsAtTheMaximumLength() : void{
		$top = $this->buildStalactite(VanillaBlocks::DRIPSTONE_BLOCK(), VanillaBlocks::WATER(), 1);
		$grown = 0;
		while($this->grow($top, true)){
			++$grown;
			$top = $this->dripstoneAt(self::ROOT_Y - 1);
			self::assertNotNull($top);
			self::assertLessThan(20, $grown);
		}
		self::assertSame(PointedDripstone::MAX_GROWTH_LENGTH, $grown, "the tip is only searched 6 blocks below the top block");
	}

	public function testStalagmiteGrowsOnTheFloorBelow() : void{
		$top = $this->buildStalactite(VanillaBlocks::DRIPSTONE_BLOCK(), VanillaBlocks::WATER(), 1);
		$this->set(0, 20, 0, VanillaBlocks::STONE());
		self::assertTrue($this->grow($top, false));
		$stalagmite = $this->dripstoneAt(21);
		self::assertNotNull($stalagmite);
		self::assertFalse($stalagmite->isHanging());
		self::assertSame(DripstoneThickness::TIP, $stalagmite->getThickness());

		self::assertTrue($this->grow($top, false), "the stalagmite grows up");
		self::assertSame(DripstoneThickness::TIP, $this->dripstoneAt(22)?->getThickness());
		self::assertSame(DripstoneThickness::FRUSTUM, $this->dripstoneAt(21)?->getThickness());
	}

	public function testStalagmiteNeedsTheFloorWithinTenBlocks() : void{
		$top = $this->buildStalactite(VanillaBlocks::DRIPSTONE_BLOCK(), VanillaBlocks::WATER(), 1);
		$this->set(0, self::ROOT_Y - 3 - PointedDripstone::MAX_STALAGMITE_SEARCH_RANGE, 0, VanillaBlocks::STONE());
		self::assertFalse($this->grow($top, false));

		$this->set(0, 25, 0, VanillaBlocks::WATER());
		$this->set(0, 20, 0, VanillaBlocks::STONE());
		self::assertFalse($this->grow($top, false), "water in the way");
	}

	public function testTipsMerge() : void{
		$top = $this->buildStalactite(VanillaBlocks::DRIPSTONE_BLOCK(), VanillaBlocks::WATER(), 1);
		$this->set(0, self::ROOT_Y - 3, 0, VanillaBlocks::STONE());
		$this->set(0, self::ROOT_Y - 2, 0, VanillaBlocks::POINTED_DRIPSTONE()->setHanging(false));
		self::assertTrue($this->grow($top, true));
		self::assertSame(DripstoneThickness::MERGE, $this->dripstoneAt(self::ROOT_Y - 1)?->getThickness());
		self::assertSame(DripstoneThickness::MERGE, $this->dripstoneAt(self::ROOT_Y - 2)?->getThickness());
		self::assertFalse($this->dripstoneAt(self::ROOT_Y - 2)?->isHanging());
		self::assertFalse($this->grow($this->dripstoneAt(self::ROOT_Y - 1), true), "merged tips don't grow");
	}

	public function testSulfurSpikesGrowFromSulfurWithoutWater() : void{
		$top = $this->buildStalactite(VanillaBlocks::SULFUR(), null, 1, VanillaBlocks::SULFUR_SPIKE());
		self::assertTrue($this->grow($top, true));
		self::assertTrue($this->grow($this->dripstoneAt(self::ROOT_Y - 1), true));
		self::assertFalse($this->grow($this->dripstoneAt(self::ROOT_Y - 1), true), "sulfur spikes stop at 3 blocks");
		self::assertSame(BlockTypeIds::SULFUR_SPIKE, $this->get(0, self::ROOT_Y - 3, 0)->getTypeId());
	}

	public function testStalactiteFallsAndHurts() : void{
		$this->floor(-2, -2, 2, 2);
		$this->buildStalactite(VanillaBlocks::STONE(), null, 3);
		$golem = new IronGolem(Location::fromObject(new Vector3(0.5, self::FLOOR_Y + 1, 0.5), $this->world));
		$golem->spawnToAll();
		$health = $golem->getHealth();

		$this->set(0, self::ROOT_Y, 0, VanillaBlocks::AIR());
		$this->runTicks(1);
		self::assertNotNull($this->dripstoneAt(self::ROOT_Y - 1), "the stalactite falls 2 ticks later");
		$this->runTicks(2);
		self::assertNull($this->dripstoneAt(self::ROOT_Y - 1));
		self::assertNull($this->dripstoneAt(self::ROOT_Y - 3));
		$falling = $this->entitiesOf(FallingBlock::class);
		self::assertCount(3, $falling);
		$damages = [];
		foreach($falling as $entity){
			$block = $entity->getBlock();
			self::assertInstanceOf(PointedDripstone::class, $block);
			$damages[] = $block->getFallDamagePerBlock();
		}
		self::assertEqualsCanonicalizing([0.0, 0.0, 3.0], $damages, "only the tip hurts, by the length of the stalactite");

		$this->runTicks(80);
		self::assertCount(0, $this->entitiesOf(FallingBlock::class));
		self::assertNull($this->dripstoneAt(self::FLOOR_Y + 1), "falling stalactites shatter");
		self::assertEqualsWithDelta($health - PointedDripstone::MAX_STALACTITE_DAMAGE, $golem->getHealth(), 0.001, "a long fall does the maximum damage");
		$items = 0;
		foreach($this->entitiesOf(ItemEntity::class) as $item){
			$items += $item->getItem()->getCount();
		}
		self::assertSame(3, $items, "each block drops itself");
	}

	public function testShortFallDamageIsLengthPerBlock() : void{
		//BDS: a 2-long stalactite whose tip falls 3 blocks to the ground hurts (3 - 1) x 2 = 4
		for($x = -1; $x <= 1; ++$x){
			for($z = -1; $z <= 1; ++$z){
				$this->set($x, self::ROOT_Y - 6, $z, VanillaBlocks::STONE());
			}
		}
		$this->buildStalactite(VanillaBlocks::STONE(), null, 2);
		$golem = new IronGolem(Location::fromObject(new Vector3(0.5, self::ROOT_Y - 5, 0.5), $this->world));
		$golem->spawnToAll();
		$health = $golem->getHealth();
		$this->set(0, self::ROOT_Y, 0, VanillaBlocks::AIR());
		$this->runTicks(40);
		self::assertEqualsWithDelta($health - 4, $golem->getHealth(), 0.001);
	}

	public function testFallingTipWithoutSpawnDataStillHurts() : void{
		//a falling tip loaded from a saved chunk (only its block state) or made by a plugin
		$tip = VanillaBlocks::POINTED_DRIPSTONE()->setHanging(true)->setThickness(DripstoneThickness::TIP);
		self::assertEqualsWithDelta(PointedDripstone::MIN_STALACTITE_DAMAGE_PER_BLOCK, $tip->getFallDamagePerBlock(), 0.0001);
		self::assertEqualsWithDelta(0.0, VanillaBlocks::POINTED_DRIPSTONE()->setHanging(true)->setThickness(DripstoneThickness::BASE)->getFallDamagePerBlock(), 0.0001);
		self::assertEqualsWithDelta(0.0, VanillaBlocks::POINTED_DRIPSTONE()->setHanging(false)->getFallDamagePerBlock(), 0.0001);
	}

	public function testBreakingTheMiddleDropsTheLowerPart() : void{
		$this->floor(-2, -2, 2, 2);
		$this->buildStalactite(VanillaBlocks::STONE(), null, 4);
		$this->breakBlock(0, self::ROOT_Y - 2, 0);
		$this->runTicks(3);
		self::assertNotNull($this->dripstoneAt(self::ROOT_Y - 1), "the upper part stays");
		self::assertSame(DripstoneThickness::TIP, $this->dripstoneAt(self::ROOT_Y - 1)?->getThickness());
		self::assertCount(2, $this->entitiesOf(FallingBlock::class));
	}

	public function testStalagmiteLosingItsFloorBreaks() : void{
		$this->set(0, 10, 0, VanillaBlocks::STONE());
		$this->set(0, 11, 0, VanillaBlocks::POINTED_DRIPSTONE()->setHanging(false));
		$this->set(0, 10, 0, VanillaBlocks::AIR());
		$this->runTicks(1);
		self::assertNull($this->dripstoneAt(11));
		self::assertCount(0, $this->entitiesOf(FallingBlock::class));
	}

	public function testStalagmiteFallDamage() : void{
		//Java: ceil((fallDistance + 2.5 - 3) * 2)
		self::assertEqualsWithDelta(12.0, PointedDripstone::getStalagmiteFallDistance(5.0), 0.0001);
		self::assertEqualsWithDelta(4.0, PointedDripstone::getStalagmiteFallDistance(1.0), 0.0001);

		$this->set(0, 10, 0, VanillaBlocks::STONE());
		$this->set(0, 11, 0, VanillaBlocks::POINTED_DRIPSTONE()->setHanging(false));
		$golem = new IronGolem(Location::fromObject(new Vector3(0.5, 12, 0.5), $this->world));
		$golem->setFallDistance(5.0);
		$this->get(0, 11, 0)->onEntityLand($golem);
		self::assertEqualsWithDelta(12.0, $golem->getFallDistance(), 0.0001);

		$this->set(0, 12, 0, VanillaBlocks::POINTED_DRIPSTONE()->setHanging(false));
		$this->runTicks(1);
		$golem->setFallDistance(5.0);
		$this->get(0, 11, 0)->onEntityLand($golem);
		self::assertEqualsWithDelta(5.0, $golem->getFallDistance(), 0.0001, "only the tip hurts more");
	}

	public function testTridentBreaksIt() : void{
		$this->set(0, 10, 0, VanillaBlocks::STONE());
		$this->set(0, 11, 0, VanillaBlocks::POINTED_DRIPSTONE()->setHanging(false));
		$hit = new RayTraceResult(new AxisAlignedBB(0, 11, 0, 1, 12, 1), 1, new Vector3(0.5, 11.5, 0.5));
		$trident = new Trident(Location::fromObject(new Vector3(0.5, 13, 0.5), $this->world), VanillaItems::TRIDENT(), null);

		$trident->setMotion(new Vector3(0, -0.5, 0));
		$this->get(0, 11, 0)->onProjectileHit($trident, $hit);
		self::assertNotNull($this->dripstoneAt(11), "too slow");

		$this->world->setGameRule(GameRules::PROJECTILES_CAN_BREAK_BLOCKS, false);
		$trident->setMotion(new Vector3(0, -2, 0));
		$this->get(0, 11, 0)->onProjectileHit($trident, $hit);
		self::assertNotNull($this->dripstoneAt(11), "projectilescanbreakblocks off");

		$this->world->setGameRule(GameRules::PROJECTILES_CAN_BREAK_BLOCKS, true);
		$this->get(0, 11, 0)->onProjectileHit($trident, $hit);
		self::assertNull($this->dripstoneAt(11));
	}
}
