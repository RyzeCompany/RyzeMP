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
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\navigation\Path;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\ai\navigation\PathNavigator;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\CaveSpider;
use pocketmine\entity\Zombie;
use pocketmine\utils\Random;
use pocketmine\world\World;
use function ceil;
use function count;
use function floor;
use function max;
use function min;
use function sqrt;

/**
 * The straight walk ahead of a path (PathNavigator::walkStraightAhead()) reads the columns from their chunks and skips
 * the samples covering the columns of the sample before: it must pick the same waypoint as the original sampling,
 * which read every column of every sample through PathBlockTypes::at().
 */
final class StraightWalkEquivalenceTest extends MobTestCase{

	private const STRAIGHT_LOOKAHEAD = 6;
	private const STRAIGHT_MARGIN = 0.3;

	private function terrain(int $seed) : void{
		$random = new Random($seed);
		/** @var Block[] $palette */
		$palette = [
			VanillaBlocks::STONE(), VanillaBlocks::OAK_SLAB(), VanillaBlocks::WATER(), VanillaBlocks::OAK_FENCE(),
			VanillaBlocks::TALL_GRASS(), VanillaBlocks::FIRE(), VanillaBlocks::OAK_TRAPDOOR(), VanillaBlocks::CACTUS(),
		];
		for($x = -20; $x <= 20; ++$x){
			for($z = -20; $z <= 20; ++$z){
				if($random->nextBoundedInt(14) === 0){
					$this->world->setBlockAt($x, 0, $z, VanillaBlocks::AIR(), false);
				}
				if($random->nextBoundedInt(9) === 0){
					$this->world->setBlockAt($x, 1, $z, $palette[$random->nextBoundedInt(count($palette))], false);
				}
			}
		}
	}

	/**
	 * The original walkStraightAhead() after its early exits: the farthest waypoint reachable in a straight line, or
	 * $index.
	 *
	 * @phpstan-param list<array{int, int, int}> $nodes
	 */
	private static function referenceTarget(Mob $mob, array $nodes, int $index, int $far, float $x, float $z, int $y, float $offset, int $headBlocks, int $danger) : int{
		$world = $mob->getWorld();
		$radius = $mob->getSize()->getWidth() / 2 + self::STRAIGHT_MARGIN;
		$columns = [];
		for($k = $far; $k > $index; --$k){
			$toX = $nodes[$k][0] + $offset;
			$toZ = $nodes[$k][2] + $offset;
			$dx = $toX - $x;
			$dz = $toZ - $z;
			$steps = max(1, (int) ceil(sqrt($dx * $dx + $dz * $dz) * 2));
			$open = true;
			for($s = 0; $s <= $steps && $open; ++$s){
				$px = $x + $dx * $s / $steps;
				$pz = $z + $dz * $s / $steps;
				for($cx = (int) floor($px - $radius + 0.000001), $cxMax = (int) floor($px + $radius - 0.000001); $cx <= $cxMax && $open; ++$cx){
					for($cz = (int) floor($pz - $radius + 0.000001), $czMax = (int) floor($pz + $radius - 0.000001); $cz <= $czMax && $open; ++$cz){
						$open = $columns[$cx][$cz] ??= self::referenceOpenColumn($world, $cx, $y, $cz, $headBlocks, $danger);
					}
				}
			}
			if($open){
				return $k;
			}
		}
		return $index;
	}

	private static function referenceOpenColumn(World $world, int $x, int $y, int $z, int $headBlocks, int $danger) : bool{
		$feet = PathBlockTypes::at($world, $x, $y, $z);
		if(($feet & (PathBlockTypes::PASSABLE | PathBlockTypes::LOW)) === 0 || ($feet & ($danger | PathBlockTypes::WATER | PathBlockTypes::SIGHT_BY_SHAPE | PathBlockTypes::CLOSED_DOOR)) !== 0){
			return false;
		}
		for($i = 1; $i <= $headBlocks; ++$i){
			$head = PathBlockTypes::at($world, $x, $y + $i, $z);
			if(($head & PathBlockTypes::PASSABLE) === 0 || ($head & $danger) !== 0){
				return false;
			}
		}
		if(($feet & PathBlockTypes::LOW) !== 0){
			return true;
		}
		$below = PathBlockTypes::at($world, $x, $y - 1, $z);
		return ($below & PathBlockTypes::FLOOR) !== 0 && ($below & ($danger | PathBlockTypes::WATER)) === 0;
	}

	public function testSameWaypoints() : void{
		$this->terrain(5);
		$random = new Random(6);
		$moved = 0;
		foreach([new Zombie(new Location(0.5, 1, 0.5, $this->world, 0, 0)), new CaveSpider(new Location(0.5, 1, 0.5, $this->world, 0, 0))] as $mob){
			$this->runTicks(2);
			self::assertTrue($mob->isOnGround());
			$navigator = $mob->getNavigation();
			$walkStraightAhead = new \ReflectionMethod(PathNavigator::class, "walkStraightAhead");
			$walk = function(Path $path, float $x, float $z) use ($walkStraightAhead, $navigator) : void{
				$node = $path->getCurrent();
				if($node === null){
					throw new \LogicException("no waypoint");
				}
				$walkStraightAhead->invoke($navigator, $path, $node, $x, $z, $node[1]);
			};
			$headBlocks = max(0, (int) ceil($mob->getSize()->getHeight()) - 1);
			//straight walks avoid what paths only cross at a cost too (fire for cave spiders)
			$options = $navigator->getOptions();
			$danger = $options->getDangerMask() | $options->getCostlyMask();
			$offset = $navigator->getNodeOffset();
			for($i = 0; $i < 400; ++$i){
				//a random walk on the floor row, diagonal and straight steps mixed
				$x = $random->nextRange(-14, 14);
				$z = $random->nextRange(-14, 14);
				$nodes = [[$x, 1, $z]];
				for($n = 0; $n < 10; ++$n){
					$x += $random->nextRange(-1, 1);
					$z += $random->nextRange(-1, 1);
					$nodes[] = [$x, 1, $z];
				}
				$index = 1;
				$fromX = $nodes[0][0] + $offset + ($random->nextFloat() - 0.5) * 0.6;
				$fromZ = $nodes[0][2] + $offset + ($random->nextFloat() - 0.5) * 0.6;
				$last = min($index + self::STRAIGHT_LOOKAHEAD, count($nodes) - 1);
				//waypoints in a straight line (from the one before) are left as they are
				$straight = true;
				for($k = $index; $k < $last; ++$k){
					if($nodes[$k + 1][0] - $nodes[$k][0] !== $nodes[$index][0] - $nodes[$index - 1][0] || $nodes[$k + 1][2] - $nodes[$k][2] !== $nodes[$index][2] - $nodes[$index - 1][2]){
						$straight = false;
						break;
					}
				}
				$expected = $straight ? $index : self::referenceTarget($mob, $nodes, $index, $last, $fromX, $fromZ, 1, $offset, $headBlocks, $danger);
				$path = new Path($nodes, true, $x, 1, $z);
				$path->setIndex($index);
				$walk($path, $fromX, $fromZ);
				self::assertSame($expected, $path->getIndex(), "path " . $i);
				if($path->getIndex() !== $index){
					++$moved;
				}
			}
		}
		self::assertGreaterThan(50, $moved, "the straight walk skipped waypoints in many paths");
	}
}
