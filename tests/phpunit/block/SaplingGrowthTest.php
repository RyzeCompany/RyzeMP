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

use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\world\redstone\RedstoneTestCase;

/**
 * Saplings growing into trees, in particular the trees that need a 2x2 square of saplings.
 */
class SaplingGrowthTest extends RedstoneTestCase{

	private function dirtFloor() : void{
		for($x = -12; $x <= 12; ++$x){
			for($z = -12; $z <= 12; ++$z){
				$this->world->setBlockAt($x, 0, $z, VanillaBlocks::GRASS(), false);
			}
		}
	}

	private function plantSquare(Sapling $sapling) : void{
		foreach([[0, 0], [1, 0], [0, 1], [1, 1]] as [$x, $z]){
			$this->set($x, 1, $z, $sapling);
		}
	}

	/**
	 * Uses bone meal until the sapling is gone (each use advances it one stage with a 45% chance) or a use is refused.
	 */
	private function boneMeal(int $x, int $y, int $z) : void{
		for($i = 0; $i < 100 && $this->get($x, $y, $z) instanceof Sapling; ++$i){
			if(!$this->get($x, $y, $z)->onInteract(VanillaItems::BONE_MEAL(), 1, new Vector3(0.5, 0.5, 0.5))){
				return;
			}
		}
	}

	/**
	 * Returns the height of the trunk column at x, z (number of log blocks above y = 0).
	 */
	private function trunkHeight(int $x, int $z, Block $log) : int{
		$height = 0;
		while($this->get($x, 1 + $height, $z)->hasSameTypeId($log)){
			++$height;
		}
		return $height;
	}

	/**
	 * @return \Generator<string, array{\Closure() : Sapling, \Closure() : Block, \Closure() : Block, int}>
	 */
	public static function twoByTwoProvider() : \Generator{
		yield "dark oak" => [fn() => VanillaBlocks::DARK_OAK_SAPLING(), fn() => VanillaBlocks::DARK_OAK_LOG(), fn() => VanillaBlocks::DARK_OAK_LEAVES(), 5];
		yield "pale oak" => [fn() => VanillaBlocks::PALE_OAK_SAPLING(), fn() => VanillaBlocks::PALE_OAK_LOG(), fn() => VanillaBlocks::PALE_OAK_LEAVES(), 6];
		yield "giant spruce" => [fn() => VanillaBlocks::SPRUCE_SAPLING(), fn() => VanillaBlocks::SPRUCE_LOG(), fn() => VanillaBlocks::SPRUCE_LEAVES(), 12];
		yield "large jungle" => [fn() => VanillaBlocks::JUNGLE_SAPLING(), fn() => VanillaBlocks::JUNGLE_LOG(), fn() => VanillaBlocks::JUNGLE_LEAVES(), 10];
	}

	/**
	 * @dataProvider twoByTwoProvider
	 * @phpstan-param \Closure() : Sapling $sapling
	 * @phpstan-param \Closure() : Block $log
	 * @phpstan-param \Closure() : Block $leaves
	 */
	public function testTwoByTwoSaplingsGrowTwoByTwoTree(\Closure $sapling, \Closure $log, \Closure $leaves, int $minHeight) : void{
		$this->dirtFloor();
		$this->plantSquare($sapling());
		//bone meal on any of the four saplings grows the same tree
		$this->boneMeal(1, 1, 1);

		$heights = [];
		foreach([[0, 0], [1, 0], [0, 1], [1, 1]] as [$x, $z]){
			$heights[] = $height = $this->trunkHeight($x, $z, $log());
			self::assertGreaterThanOrEqual($minHeight, $height, "trunk at $x $z");
			self::assertNotInstanceOf(Sapling::class, $this->get($x, 1, $z));
		}

		//there are leaves of the right kind somewhere above the trunk
		$foundLeaves = false;
		$top = 1 + $heights[0];
		for($y = $top - 3; $y <= $top + 3 && !$foundLeaves; ++$y){
			for($x = -4; $x <= 5 && !$foundLeaves; ++$x){
				for($z = -4; $z <= 5 && !$foundLeaves; ++$z){
					$foundLeaves = $this->get($x, $y, $z)->hasSameTypeId($leaves());
				}
			}
		}
		self::assertTrue($foundLeaves);
	}

	public function testSingleDarkOakSaplingDoesNotGrow() : void{
		$this->dirtFloor();
		$this->set(0, 1, 0, VanillaBlocks::DARK_OAK_SAPLING());
		$this->boneMeal(0, 1, 0);
		self::assertInstanceOf(Sapling::class, $this->get(0, 1, 0));

		//three saplings aren't a square either
		$this->set(1, 1, 0, VanillaBlocks::DARK_OAK_SAPLING());
		$this->set(0, 1, 1, VanillaBlocks::DARK_OAK_SAPLING());
		$this->boneMeal(0, 1, 0);
		self::assertInstanceOf(Sapling::class, $this->get(0, 1, 0));
	}

	public function testSingleSpruceSaplingGrowsNormalSpruce() : void{
		$this->dirtFloor();
		$this->set(0, 1, 0, VanillaBlocks::SPRUCE_SAPLING());
		$this->boneMeal(0, 1, 0);
		self::assertGreaterThan(0, $this->trunkHeight(0, 0, VanillaBlocks::SPRUCE_LOG()));
		foreach([[1, 0], [0, 1], [1, 1]] as [$x, $z]){
			self::assertSame(0, $this->trunkHeight($x, $z, VanillaBlocks::SPRUCE_LOG()));
		}
	}

	public function testMixedSquareGrowsNormalTree() : void{
		$this->dirtFloor();
		$this->plantSquare(VanillaBlocks::JUNGLE_SAPLING());
		$this->set(1, 1, 1, VanillaBlocks::OAK_SAPLING());
		$this->boneMeal(0, 1, 0);
		self::assertGreaterThan(0, $this->trunkHeight(0, 0, VanillaBlocks::JUNGLE_LOG()));
		self::assertSame(0, $this->trunkHeight(1, 0, VanillaBlocks::JUNGLE_LOG()));
		self::assertSame(0, $this->trunkHeight(0, 1, VanillaBlocks::JUNGLE_LOG()));
	}
}
