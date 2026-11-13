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

namespace pocketmine\world\generator\vanilla\feature\type;

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\feature\Feature;
use pocketmine\world\generator\vanilla\feature\FeatureContext;
use pocketmine\world\generator\vanilla\feature\FeatureLevel;
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use pocketmine\world\generator\vanilla\feature\PlacedFeature;
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\WorldgenData;
use function array_slice;
use function array_values;
use function count;

/**
 * minecraft:bamboo, minecraft:coral_tree and minecraft:coral_claw.
 */
final class PlantFeatures{

	private function __construct(){
		//NOOP
	}

	/**
	 * @param int[] $list
	 * @phpstan-param list<int> $list
	 * @phpstan-return list<int>
	 */
	public static function shuffle(array $list, FeatureRandom $random) : array{
		for($i = count($list); $i > 1; --$i){
			$swapTo = $random->nextBoundedInt($i);
			$tmp = $list[$i - 1];
			$list[$i - 1] = $list[$swapTo];
			$list[$swapTo] = $tmp;
		}
		return array_values($list);
	}

	/**
	 * @param mixed[] $json
	 */
	public static function bamboo(FeatureRegistry $registry, array $json) : Feature{
		$blocks = $registry->blocks;
		$trunk = $blocks->with($blocks->withInt($blocks->withInt($blocks->defaultOf("bamboo"), "age", 1), "stage", 0), "leaves", "none");
		$finalLarge = $blocks->withInt($blocks->with($trunk, "leaves", "large"), "stage", 1);
		$topLarge = $blocks->with($trunk, "leaves", "large");
		$topSmall = $blocks->with($trunk, "leaves", "small");
		$podzol = $blocks->defaultOf("podzol");
		$probability = FloatMath::fround(WorldgenData::float($json, "probability"));
		return new class($trunk, $finalLarge, $topLarge, $topSmall, $podzol, $probability, $blocks->defaultOf("bamboo")) implements Feature{
			public function __construct(
				private int $trunk,
				private int $finalLarge,
				private int $topLarge,
				private int $topSmall,
				private int $podzol,
				private float $probability,
				private int $bamboo
			){}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				$level = $c->level;
				$random = $c->random;
				if(!$level->isEmptyBlock($x, $y, $z)){
					return false;
				}
				if($c->survival->canSurvive($level, $this->bamboo, $x, $y, $z)){
					$height = $random->nextBoundedInt(12) + 5;
					if($random->nextFloat() < $this->probability){
						$r = $random->nextBoundedInt(4) + 1;
						for($xx = $x - $r; $xx <= $x + $r; ++$xx){
							for($zz = $z - $r; $zz <= $z + $r; ++$zz){
								$xd = $xx - $x;
								$zd = $zz - $z;
								if($xd * $xd + $zd * $zd > $r * $r){
									continue;
								}
								$py = $level->getHeight(FeatureLevel::WORLD_SURFACE, $xx, $zz) - 1;
								if($c->blocks->isTagged($level->getState($xx, $py, $zz), "beneath_bamboo_podzol_replaceable")){
									$level->setState($xx, $py, $zz, $this->podzol);
								}
							}
						}
					}
					$by = $y;
					for($i = 0; $i < $height && $level->isEmptyBlock($x, $by, $z); ++$i){
						$level->setState($x, $by, $z, $this->trunk);
						++$by;
					}
					if($by - $y >= 3){
						$level->setState($x, $by, $z, $this->finalLarge);
						$level->setState($x, $by - 1, $z, $this->topLarge);
						$level->setState($x, $by - 2, $z, $this->topSmall);
					}
				}
				return true;
			}
		};
	}

	/**
	 * @param mixed[] $json
	 */
	public static function coralTree(FeatureRegistry $registry, array $json) : Feature{
		return new class($registry->placedRef($json["feature"] ?? null)) implements Feature{
			public function __construct(private PlacedFeature $feature){}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				$random = $c->random;
				$trunkHeight = $random->nextBoundedInt(3) + 1;
				for($i = 0; $i < $trunkHeight; ++$i){
					if(!$this->feature->place($c, $x, $y, $z)){
						return true;
					}
					++$y;
				}
				$nBranches = $random->nextBoundedInt(3) + 2;
				$directions = array_slice(PlantFeatures::shuffle(Direction::PLANE_HORIZONTAL, $random), 0, $nBranches);
				foreach($directions as $d){
					$px = $x + Direction::STEP_X[$d];
					$py = $y;
					$pz = $z + Direction::STEP_Z[$d];
					$branchHeight = $random->nextBoundedInt(5) + 2;
					$segment = 0;
					for($j = 0; $j < $branchHeight; ++$j){
						if(!$this->feature->place($c, $px, $py, $pz)){
							break;
						}
						++$segment;
						++$py;
						if($j === 0 || ($segment >= 2 && $random->nextFloat() < 0.25)){
							$px += Direction::STEP_X[$d];
							$pz += Direction::STEP_Z[$d];
							$segment = 0;
						}
					}
				}
				return true;
			}
		};
	}

	/**
	 * @param mixed[] $json
	 */
	public static function coralClaw(FeatureRegistry $registry, array $json) : Feature{
		return new class($registry->placedRef($json["feature"] ?? null)) implements Feature{
			public function __construct(private PlacedFeature $feature){}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				$random = $c->random;
				if(!$this->feature->place($c, $x, $y, $z)){
					return false;
				}
				$claw = Direction::PLANE_HORIZONTAL[$random->nextBoundedInt(4)];
				$nBranches = $random->nextBoundedInt(2) + 2;
				$possible = PlantFeatures::shuffle([$claw, Direction::clockWise($claw), Direction::counterClockWise($claw)], $random);
				foreach(array_slice($possible, 0, $nBranches) as $branch){
					$px = $x;
					$py = $y;
					$pz = $z;
					$sideway = $random->nextBoundedInt(2) + 1;
					$px += Direction::STEP_X[$branch];
					$pz += Direction::STEP_Z[$branch];
					if($branch === $claw){
						$segment = $claw;
						$inway = $random->nextBoundedInt(3) + 2;
					}else{
						++$py;
						$segment = [$branch, Direction::UP][$random->nextBoundedInt(2)];
						$inway = $random->nextBoundedInt(3) + 3;
					}
					for($i = 0; $i < $sideway; ++$i){
						if(!$this->feature->place($c, $px, $py, $pz)){
							break;
						}
						$px += Direction::STEP_X[$segment];
						$py += Direction::STEP_Y[$segment];
						$pz += Direction::STEP_Z[$segment];
					}
					$px -= Direction::STEP_X[$segment];
					$py -= Direction::STEP_Y[$segment];
					$pz -= Direction::STEP_Z[$segment];
					++$py;
					for($i = 0; $i < $inway; ++$i){
						$px += Direction::STEP_X[$claw];
						$pz += Direction::STEP_Z[$claw];
						if(!$this->feature->place($c, $px, $py, $pz)){
							break;
						}
						if($random->nextFloat() < 0.25){
							++$py;
						}
					}
				}
				return true;
			}
		};
	}
}
