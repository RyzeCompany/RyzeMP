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

use pocketmine\world\generator\vanilla\feature\Feature;
use pocketmine\world\generator\vanilla\feature\FeatureContext;
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use pocketmine\world\generator\vanilla\feature\Predicates;

/**
 * minecraft:lake (lava lakes in 26.3; water lakes freeze in cold biomes).
 */
final class LakeFeature implements Feature{
	/** @phpstan-var \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int */
	private \Closure $fluid;
	/** @phpstan-var \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int */
	private \Closure $barrier;
	/** @phpstan-var \Closure(FeatureContext, int, int, int) : bool */
	private \Closure $canPlaceFeature;
	/** @phpstan-var \Closure(FeatureContext, int, int, int) : bool */
	private \Closure $canReplaceWithAirOrFluid;
	/** @phpstan-var \Closure(FeatureContext, int, int, int) : bool */
	private \Closure $canReplaceWithBarrier;

	/**
	 * @param mixed[] $json
	 */
	public function __construct(FeatureRegistry $registry, array $json){
		$this->fluid = $registry->stateProvider($json["fluid"] ?? null);
		$this->barrier = $registry->stateProvider($json["barrier"] ?? null);
		$this->canPlaceFeature = Predicates::block($registry->blocks, $json["can_place_feature"] ?? null);
		$this->canReplaceWithAirOrFluid = Predicates::block($registry->blocks, $json["can_replace_with_air_or_fluid"] ?? null);
		$this->canReplaceWithBarrier = Predicates::block($registry->blocks, $json["can_replace_with_barrier"] ?? null);
	}

	/**
	 * @param bool[] $grid
	 */
	private static function isEdge(array $grid, int $xx, int $zz, int $yy) : bool{
		if($grid[($xx * 16 + $zz) * 8 + $yy]){
			return false;
		}
		return ($xx < 15 && $grid[(($xx + 1) * 16 + $zz) * 8 + $yy]) ||
			($xx > 0 && $grid[(($xx - 1) * 16 + $zz) * 8 + $yy]) ||
			($zz < 15 && $grid[($xx * 16 + $zz + 1) * 8 + $yy]) ||
			($zz > 0 && $grid[($xx * 16 + ($zz - 1)) * 8 + $yy]) ||
			($yy < 7 && $grid[($xx * 16 + $zz) * 8 + $yy + 1]) ||
			($yy > 0 && $grid[($xx * 16 + $zz) * 8 + ($yy - 1)]);
	}

	public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
		$level = $c->level;
		$blocks = $c->blocks;
		$random = $c->random;
		if($y <= $level->minY + 4){
			return false;
		}
		$ox = $x - 8;
		$oy = $y - 4;
		$oz = $z - 8;
		$grid = [];
		for($i = 0; $i < 2048; ++$i){
			$grid[] = false;
		}
		$spots = $random->nextBoundedInt(4) + 4;
		for($i = 0; $i < $spots; ++$i){
			$xr = $random->nextDouble() * 6.0 + 3.0;
			$yr = $random->nextDouble() * 4.0 + 2.0;
			$zr = $random->nextDouble() * 6.0 + 3.0;
			$xp = $random->nextDouble() * (16.0 - $xr - 2.0) + 1.0 + $xr / 2.0;
			$yp = $random->nextDouble() * (8.0 - $yr - 4.0) + 2.0 + $yr / 2.0;
			$zp = $random->nextDouble() * (16.0 - $zr - 2.0) + 1.0 + $zr / 2.0;
			for($xx = 1; $xx < 15; ++$xx){
				for($zz = 1; $zz < 15; ++$zz){
					for($yy = 1; $yy < 7; ++$yy){
						$xd = ($xx - $xp) / ($xr / 2.0);
						$yd = ($yy - $yp) / ($yr / 2.0);
						$zd = ($zz - $zp) / ($zr / 2.0);
						if($xd * $xd + $yd * $yd + $zd * $zd < 1.0){
							$grid[($xx * 16 + $zz) * 8 + $yy] = true;
						}
					}
				}
			}
		}
		$fluid = ($this->fluid)($c, $random, $ox, $oy, $oz) ?? $blocks->water;
		for($xx = 0; $xx < 16; ++$xx){
			for($zz = 0; $zz < 16; ++$zz){
				for($yy = 0; $yy < 8; ++$yy){
					if(!self::isEdge($grid, $xx, $zz, $yy)){
						continue;
					}
					$state = $level->getState($ox + $xx, $oy + $yy, $oz + $zz);
					$block = $blocks->blockOf[$state];
					if($yy >= 4 && ($block === $blocks->waterBlock || $block === $blocks->lavaBlock)){
						return false;
					}
					if($yy < 4 && !$blocks->isSolid($state) && $state !== $fluid){
						return false;
					}
					if(!($this->canPlaceFeature)($c, $ox + $xx, $oy + $yy, $oz + $zz)){
						return false;
					}
				}
			}
		}
		$air = $blocks->caveAir;
		for($xx = 0; $xx < 16; ++$xx){
			for($zz = 0; $zz < 16; ++$zz){
				for($yy = 0; $yy < 8; ++$yy){
					if(!$grid[($xx * 16 + $zz) * 8 + $yy]){
						continue;
					}
					if(!($this->canReplaceWithAirOrFluid)($c, $ox + $xx, $oy + $yy, $oz + $zz)){
						continue;
					}
					$level->setState($ox + $xx, $oy + $yy, $oz + $zz, $yy >= 4 ? $air : $fluid);
				}
			}
		}
		$barrier = ($this->barrier)($c, $random, $ox, $oy, $oz) ?? $air;
		if(!$blocks->isAir($barrier)){
			for($xx = 0; $xx < 16; ++$xx){
				for($zz = 0; $zz < 16; ++$zz){
					for($yy = 0; $yy < 8; ++$yy){
						if(!self::isEdge($grid, $xx, $zz, $yy)){
							continue;
						}
						if($yy >= 4 && $random->nextBoundedInt(2) === 0){
							continue;
						}
						$px = $ox + $xx;
						$py = $oy + $yy;
						$pz = $oz + $zz;
						if($blocks->isSolid($level->getState($px, $py, $pz)) && ($this->canReplaceWithBarrier)($c, $px, $py, $pz)){
							$level->setState($px, $py, $pz, $barrier);
						}
					}
				}
			}
		}
		if($blocks->isWater($fluid)){
			for($xx = 0; $xx < 16; ++$xx){
				for($zz = 0; $zz < 16; ++$zz){
					$px = $ox + $xx;
					$py = $oy + 4;
					$pz = $oz + $zz;
					if($c->registry->climate()->shouldFreeze($c, $px, $py, $pz, false) && ($this->canReplaceWithAirOrFluid)($c, $px, $py, $pz)){
						$level->setState($px, $py, $pz, $blocks->defaultOf("ice"));
					}
				}
			}
		}
		return true;
	}
}
