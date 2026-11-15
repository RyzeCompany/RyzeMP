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

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\navigation\Path;
use pocketmine\entity\ai\navigation\PathFinder;
use pocketmine\math\Facing;

final class PathFinderTest extends MobTestCase{

	private function finder(int $maxDrop = 3, int $maxNodes = 400) : PathFinder{
		return new PathFinder($this->world, 1, $maxDrop, true, false, $maxNodes);
	}

	/**
	 * @phpstan-return list<string>
	 */
	private function nodeKeys(Path $path) : array{
		$keys = [];
		foreach($path->getNodes() as [$x, $y, $z]){
			$keys[] = "$x:$y:$z";
		}
		return $keys;
	}

	public function testStraightLine() : void{
		$path = $this->finder()->findPath(0, 1, 0, 8, 1, 0);
		self::assertNotNull($path);
		self::assertTrue($path->reachesTarget());
		self::assertSame(9, $path->getLength());
		self::assertSame([8, 1, 0], $path->getEnd());
	}

	public function testDiagonalMovesShortenPaths() : void{
		$path = $this->finder()->findPath(0, 1, 0, 5, 1, 5);
		self::assertNotNull($path);
		self::assertTrue($path->reachesTarget());
		self::assertSame(6, $path->getLength());
	}

	public function testGoesAroundWall() : void{
		//two blocks tall wall at x=4, z=-3..3
		for($z = -3; $z <= 3; ++$z){
			$this->set(4, 1, $z, VanillaBlocks::STONE());
			$this->set(4, 2, $z, VanillaBlocks::STONE());
		}
		$path = $this->finder()->findPath(0, 1, 0, 8, 1, 0);
		self::assertNotNull($path);
		self::assertTrue($path->reachesTarget());
		foreach($path->getNodes() as [$x, $y, $z]){
			self::assertSame(1, $y);
			self::assertFalse($x === 4 && $z >= -3 && $z <= 3, "path goes through the wall at $x:$y:$z");
		}
		self::assertGreaterThan(9, $path->getLength());
	}

	public function testStepsUpOneBlock() : void{
		$this->set(4, 1, 0, VanillaBlocks::STONE());
		for($z = -10; $z <= 10; ++$z){
			if($z !== 0){
				$this->set(4, 1, $z, VanillaBlocks::STONE());
				$this->set(4, 2, $z, VanillaBlocks::STONE());
			}
		}
		$path = $this->finder()->findPath(0, 1, 0, 8, 1, 0);
		self::assertNotNull($path);
		self::assertTrue($path->reachesTarget());
		self::assertContains("4:2:0", $this->nodeKeys($path));
	}

	public function testCannotJumpTwoBlocks() : void{
		//closed ring of 2-tall walls: the target is unreachable
		for($i = -3; $i <= 3; ++$i){
			foreach([[$i, -3], [$i, 3], [-3, $i], [3, $i]] as [$x, $z]){
				$this->set($x, 1, $z, VanillaBlocks::STONE());
				$this->set($x, 2, $z, VanillaBlocks::STONE());
			}
		}
		$path = $this->finder()->findPath(0, 1, 0, 8, 1, 0);
		self::assertTrue($path === null || !$path->reachesTarget());
	}

	public function testFencesCannotBeJumped() : void{
		for($z = -24; $z <= 24; ++$z){
			$this->set(4, 1, $z, VanillaBlocks::OAK_FENCE());
		}
		$path = $this->finder()->findPath(0, 1, 0, 8, 1, 0);
		self::assertTrue($path === null || !$path->reachesTarget());
	}

	public function testDropLimit() : void{
		//pillar 4 blocks high at x=0..1 (stand at y=5), ground at y=1 around
		for($y = 1; $y <= 4; ++$y){
			$this->set(0, $y, 0, VanillaBlocks::STONE());
			$this->set(1, $y, 0, VanillaBlocks::STONE());
		}
		//a 4 block drop is too much with maxDrop 3
		$path = $this->finder(3)->findPath(0, 5, 0, 5, 1, 0);
		self::assertTrue($path === null || !$path->reachesTarget());

		//allowed with maxDrop 4
		$path = $this->finder(4)->findPath(0, 5, 0, 5, 1, 0);
		self::assertNotNull($path);
		self::assertTrue($path->reachesTarget());

		//3 blocks are fine
		$this->set(2, 1, 0, VanillaBlocks::STONE());
		$path = $this->finder(3)->findPath(0, 5, 0, 5, 1, 0);
		self::assertNotNull($path);
		self::assertTrue($path->reachesTarget());
	}

	public function testAvoidsLavaFireAndCactus() : void{
		//strip of lava across, one gap at z=6
		for($z = -10; $z <= 10; ++$z){
			if($z === 6){
				continue;
			}
			$this->set(4, 0, $z, VanillaBlocks::LAVA());
		}
		$this->set(2, 1, 0, VanillaBlocks::FIRE());
		$this->set(2, 1, 1, VanillaBlocks::CACTUS());
		$path = $this->finder()->findPath(0, 1, 0, 8, 1, 0);
		self::assertNotNull($path);
		self::assertTrue($path->reachesTarget());
		foreach($path->getNodes() as [$x, $y, $z]){
			self::assertFalse($x === 4 && $z !== 6, "path crosses lava at $x:$y:$z");
			self::assertFalse($x === 2 && ($z === 0 || $z === 1), "path goes through fire/cactus at $x:$y:$z");
		}
	}

	public function testClosedDoorsBlockOpenDoorsPass() : void{
		//wall with a door at z=0
		for($z = -24; $z <= 24; ++$z){
			if($z !== 0){
				$this->set(4, 1, $z, VanillaBlocks::STONE());
				$this->set(4, 2, $z, VanillaBlocks::STONE());
			}
		}
		$door = VanillaBlocks::OAK_DOOR()->setFacing(Facing::EAST);
		$this->set(4, 1, 0, $door);
		$this->set(4, 2, 0, (clone $door)->setTop(true));
		$path = $this->finder()->findPath(0, 1, 0, 8, 1, 0);
		self::assertTrue($path === null || !$path->reachesTarget(), "closed door should block the path");

		$this->set(4, 1, 0, (clone $door)->setOpen(true));
		$this->set(4, 2, 0, (clone $door)->setTop(true)->setOpen(true));
		$path = $this->finder()->findPath(0, 1, 0, 8, 1, 0);
		self::assertNotNull($path);
		self::assertTrue($path->reachesTarget());
		self::assertContains("4:1:0", $this->nodeKeys($path));
	}

	public function testNodeLimitGivesPartialPath() : void{
		$finder = $this->finder(3, 20);
		$path = $finder->findPath(0, 1, 0, 20, 1, 0);
		self::assertNotNull($path);
		self::assertFalse($path->reachesTarget());
		self::assertLessThanOrEqual(20, $finder->getLastVisitedCount());
		$end = $path->getEnd();
		self::assertNotNull($end);
		self::assertGreaterThan(0, $end[0], "partial path should get closer to the target");
	}

	public function testSwimsAcrossWater() : void{
		for($x = 3; $x <= 5; ++$x){
			for($z = -24; $z <= 24; ++$z){
				$this->set($x, 0, $z, VanillaBlocks::WATER());
			}
		}
		$path = $this->finder()->findPath(0, 1, 0, 8, 1, 0);
		self::assertNotNull($path);
		self::assertTrue($path->reachesTarget());

		$noSwim = new PathFinder($this->world, 1, 3, false);
		$path = $noSwim->findPath(0, 1, 0, 8, 1, 0);
		self::assertTrue($path === null || !$path->reachesTarget());
	}
}
