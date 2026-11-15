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

use pocketmine\block\Block;
use pocketmine\block\SweetBerryBush;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\navigation\NavigationOptions;
use pocketmine\entity\ai\navigation\Path;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\ai\navigation\PathFinder;
use pocketmine\entity\ai\reference\ReferencePathFinder;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\utils\Random;
use pocketmine\world\redstone\RedstoneTestCase;
use function count;

/**
 * The optimized A* (per-search caches, inlined block typing, palette checks) and the native one of chunkutils2 must
 * find exactly the same paths as the plain reference search, with every navigation option.
 */
final class PathFinderEquivalenceTest extends RedstoneTestCase{

	protected function getChunkRadius() : int{
		return 2; //x/z -32..47 loaded
	}

	protected function tearDown() : void{
		PathFinder::setNativeEnabled(true);
		parent::tearDown();
	}

	/**
	 * Uneven terrain: a floor at y=0 with random columns of blocks up to y=4, water, lava, slabs, cactus... With
	 * $dangers, also fire, magma, campfires, sweet berry bushes, cobwebs, powder snow, wither roses and trapdoors.
	 */
	private function terrain(int $seed, bool $dangers = false) : void{
		$random = new Random($seed);
		/** @var Block[] $palette */
		$palette = [
			VanillaBlocks::STONE(), VanillaBlocks::STONE(), VanillaBlocks::DIRT(), VanillaBlocks::OAK_SLAB(),
			VanillaBlocks::WATER(), VanillaBlocks::LAVA(), VanillaBlocks::CACTUS(), VanillaBlocks::OAK_FENCE(),
			VanillaBlocks::GLASS(), VanillaBlocks::TALL_GRASS(), VanillaBlocks::SNOW_LAYER(), VanillaBlocks::OAK_LEAVES(),
		];
		if($dangers){
			$palette[] = VanillaBlocks::FIRE();
			$palette[] = VanillaBlocks::MAGMA();
			$palette[] = VanillaBlocks::CAMPFIRE();
			$palette[] = VanillaBlocks::SWEET_BERRY_BUSH();
			$palette[] = VanillaBlocks::SWEET_BERRY_BUSH()->setAge(SweetBerryBush::STAGE_MATURE);
			$palette[] = VanillaBlocks::COBWEB();
			$palette[] = VanillaBlocks::POWDER_SNOW();
			$palette[] = VanillaBlocks::WITHER_ROSE();
			$palette[] = VanillaBlocks::OAK_TRAPDOOR();
		}
		for($x = -24; $x <= 40; ++$x){
			for($z = -24; $z <= 40; ++$z){
				$this->world->setBlockAt($x, 0, $z, $random->nextBoundedInt(12) === 0 ? VanillaBlocks::AIR() : VanillaBlocks::STONE(), false);
				for($y = 1; $y <= 4; ++$y){
					//fewer blocks with dangers, or wide mobs find no room
					$this->world->setBlockAt($x, $y, $z, $random->nextBoundedInt($dangers ? 7 : 5) === 0 ? $palette[$random->nextBoundedInt(count($palette))] : VanillaBlocks::AIR(), false);
				}
			}
		}
	}

	/**
	 * @return list<int>|null
	 */
	private static function describe(?Path $path) : ?array{
		if($path === null){
			return null;
		}
		$flat = [$path->reachesTarget() ? 1 : 0];
		foreach($path->getNodes() as [$x, $y, $z]){
			$flat[] = $x;
			$flat[] = $y;
			$flat[] = $z;
		}
		return $flat;
	}

	/**
	 * Runs the same searches with both finders and checks they agree.
	 *
	 * @return int[] searches done, searches that reached their target
	 * @phpstan-return array{int, int}
	 */
	private function compare(PathFinder $fast, ReferencePathFinder $reference, int $seed, string $label) : array{
		$found = 0;
		$searches = 0;
		$random = new Random($seed);
		for($i = 0; $i < 40; ++$i){
			$sx = $random->nextRange(-20, 36);
			$sz = $random->nextRange(-20, 36);
			$sy = $random->nextRange(1, 4);
			$tx = $sx + $random->nextRange(-16, 16);
			$tz = $sz + $random->nextRange(-16, 16);
			$ty = $random->nextRange(0, 5);
			$accept = $random->nextBoundedInt(3) === 0 ? 2.0 : 0.0;
			$expected = self::describe($reference->findPath($sx, $sy, $sz, $tx, $ty, $tz, $accept));
			$actual = self::describe($fast->findPath($sx, $sy, $sz, $tx, $ty, $tz, $accept));
			self::assertSame($expected, $actual, "$label from $sx $sy $sz to $tx $ty $tz");
			self::assertSame($reference->getLastVisitedCount(), $fast->getLastVisitedCount(), "$label visited from $sx $sy $sz to $tx $ty $tz");
			//isStandable() between searches answers from the last search's caches, as before
			for($j = 0; $j < 10; ++$j){
				$x = $sx + $random->nextRange(-3, 3);
				$y = $random->nextRange(0, 5);
				$z = $sz + $random->nextRange(-3, 3);
				self::assertSame($reference->isStandable($x, $y, $z), $fast->isStandable($x, $y, $z), "$label standable $x $y $z");
			}
			++$searches;
			if($actual !== null && $actual[0] === 1){
				++$found;
			}
		}
		return [$searches, $found];
	}

	/**
	 * Finders without options (every DANGER block avoided, no danger margin), as with chunkutils2 0.3.7 to 0.3.10.
	 *
	 * @return \Generator<int, array{bool}>
	 */
	public static function nativeProvider() : \Generator{
		yield [false];
		yield [true];
	}

	/**
	 * @dataProvider nativeProvider
	 */
	public function testSamePaths(bool $native) : void{
		[$hasNative] = PathFinder::getNativeSupport();
		if($native && !$hasNative){
			self::markTestSkipped("chunkutils2 has no native path finder");
		}
		PathFinder::setNativeEnabled($native);
		$found = 0;
		$searches = 0;
		foreach([[1, 3, true, false], [2, 3, true, true], [1, 1, false, false], [0, 4, true, false]] as [$headBlocks, $maxDrop, $canSwim, $avoidWater]){
			for($round = 0; $round < 6; ++$round){
				$this->terrain(1000 + $round * 7 + $headBlocks);
				$fast = new PathFinder($this->world, $headBlocks, $maxDrop, $canSwim, $avoidWater, 400);
				$reference = new ReferencePathFinder($this->world, $headBlocks, $maxDrop, $canSwim, $avoidWater, 400);
				[$s, $f] = $this->compare($fast, $reference, 77 + $round, "head $headBlocks drop $maxDrop swim $canSwim");
				$searches += $s;
				$found += $f;
			}
		}
		self::assertSame(960, $searches);
		self::assertGreaterThan(100, $found, "the test should find complete paths");
	}

	/**
	 * Mobs with navigation options: footprints, danger masks, lava, danger margins.
	 *
	 * @dataProvider nativeProvider
	 */
	public function testSamePathsWithOptions(bool $native) : void{
		[$hasNative, $hasOptions] = PathFinder::getNativeSupport();
		if($native && (!$hasNative || !$hasOptions)){
			self::markTestSkipped("chunkutils2 has no native path finder with navigation options (0.3.11)");
		}
		PathFinder::setNativeEnabled($native);
		$configs = [
			//footprint, head blocks, max drop, options
			"zombie" => [1, 1, 3, NavigationOptions::forEntity(EntityIds::ZOMBIE)],
			"wolf" => [1, 0, 3, NavigationOptions::forEntity(EntityIds::WOLF)],
			"vindicator" => [1, 1, 3, NavigationOptions::forEntity(EntityIds::VINDICATOR)],
			"cow" => [1, 1, 3, NavigationOptions::forEntity(EntityIds::COW)],
			"strider" => [1, 1, 3, NavigationOptions::forEntity(EntityIds::STRIDER)],
			"goat" => [1, 1, 3, NavigationOptions::forEntity(EntityIds::GOAT)],
			"breeze" => [1, 1, 3, NavigationOptions::forEntity(EntityIds::BREEZE)],
			"spider" => [2, 0, 3, NavigationOptions::forEntity(EntityIds::SPIDER)],
			"iron golem" => [2, 2, 1, NavigationOptions::forEntity(EntityIds::IRON_GOLEM)],
			"wither skeleton" => [1, 2, 3, NavigationOptions::forEntity(EntityIds::WITHER_SKELETON)],
			"3 wide" => [3, 1, 3, new NavigationOptions()],
		];
		$found = 0;
		$searches = 0;
		$round = 0;
		foreach($configs as $label => [$footprint, $headBlocks, $maxDrop, $options]){
			$this->terrain(5000 + $round * 13, true);
			$fast = new PathFinder($this->world, $headBlocks, $maxDrop, true, false, 400);
			$fast->applyOptions($options);
			$fast->setFootprint($footprint);
			$reference = new ReferencePathFinder($this->world, $headBlocks, $maxDrop, $options->canSwim(), $options->avoidsWater(), 400);
			$reference->setFootprint($footprint);
			$reference->setHeuristicWeight(NavigationOptions::HEURISTIC_WEIGHT);
			$reference->setDangerMask($options->getDangerMask());
			$floorMask = PathBlockTypes::FLOOR | ($options->canWalkInLava() ? PathBlockTypes::LAVA : 0);
			$reference->setFloorMask($floorMask);
			if($options->canPathOverLava() && !$options->canWalkInLava()){
				$reference->setLiquid(PathBlockTypes::LAVA, NavigationOptions::LAVA_COST);
			}elseif($options->getCostlyMask() !== 0){
				$reference->setLiquid($options->getCostlyMask(), NavigationOptions::FIRE_COST);
			}
			$reference->setDangerMargin(
				$options->avoidsDamageBlocks() ? PathBlockTypes::DAMAGE_BLOCK : 0,
				($options->getDangerMask() | $options->getCostlyMask()) & PathBlockTypes::NEAR_DANGER & ~$floorMask,
				NavigationOptions::DANGER_MARGIN_COST
			);
			[$s, $f] = $this->compare($fast, $reference, 300 + $round, $label);
			$searches += $s;
			$found += $f;
			++$round;
		}
		self::assertSame(440, $searches);
		self::assertGreaterThan(40, $found, "the test should find complete paths");
	}
}
