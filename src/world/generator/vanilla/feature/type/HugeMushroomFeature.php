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
use pocketmine\world\generator\vanilla\WorldgenData;

/**
 * minecraft:huge_brown_mushroom and minecraft:huge_red_mushroom (AbstractHugeMushroomFeature).
 */
final class HugeMushroomFeature implements Feature{
	/** @phpstan-var \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int */
	private \Closure $capProvider;
	/** @phpstan-var \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int */
	private \Closure $stemProvider;
	private int $foliageRadius;
	/** @phpstan-var \Closure(FeatureContext, int, int, int) : bool */
	private \Closure $canPlaceOn;
	/** @phpstan-var array<int, true> */
	private array $replaceable;
	/** @phpstan-var array<int, true> */
	private array $leaves;

	/**
	 * @param mixed[] $json
	 */
	public function __construct(FeatureRegistry $registry, array $json, private readonly bool $red){
		$this->capProvider = $registry->stateProvider($json["cap_provider"] ?? null);
		$this->stemProvider = $registry->stateProvider($json["stem_provider"] ?? null);
		$this->foliageRadius = WorldgenData::int($json, "foliage_radius", 2);
		$this->canPlaceOn = Predicates::block($registry->blocks, $json["can_place_on"] ?? null);
		$this->replaceable = $registry->blocks->tag("minecraft:replaceable_by_mushrooms");
		$this->leaves = $registry->blocks->tag("minecraft:leaves");
	}

	public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
		$random = $c->random;
		$treeHeight = $random->nextBoundedInt(3) + 4;
		if($random->nextBoundedInt(12) === 0){
			$treeHeight *= 2;
		}
		if(!$this->isValidPosition($c, $x, $y, $z, $treeHeight)){
			return false;
		}
		$this->makeCap($c, $x, $y, $z, $treeHeight);
		for($dy = 0; $dy < $treeHeight; ++$dy){
			$state = ($this->stemProvider)($c, $random, $x, $y, $z) ?? $c->blocks->air;
			$this->placeMushroomBlock($c, $x, $y + $dy, $z, $state);
		}
		return true;
	}

	private function radiusForHeight(int $dy) : int{
		if($this->red){
			//called with treeHeight = -1 by vanilla: only the stem column is checked
			return 0;
		}
		return $dy <= 3 ? 0 : $this->foliageRadius;
	}

	private function isValidPosition(FeatureContext $c, int $x, int $y, int $z, int $treeHeight) : bool{
		$level = $c->level;
		if($y < $level->minY + 1 || $y + $treeHeight + 1 > $level->maxY - 1){
			return false;
		}
		if(!($this->canPlaceOn)($c, $x, $y - 1, $z)){
			return false;
		}
		$blocks = $c->blocks;
		for($dy = 0; $dy <= $treeHeight; ++$dy){
			$r = $this->radiusForHeight($dy);
			for($dx = -$r; $dx <= $r; ++$dx){
				for($dz = -$r; $dz <= $r; ++$dz){
					$state = $level->getState($x + $dx, $y + $dy, $z + $dz);
					if(!$blocks->isAir($state) && !isset($this->leaves[$blocks->blockOf[$state]])){
						return false;
					}
				}
			}
		}
		return true;
	}

	private function placeMushroomBlock(FeatureContext $c, int $x, int $y, int $z, int $state) : void{
		$current = $c->level->getState($x, $y, $z);
		if($c->blocks->isAir($current) || isset($this->replaceable[$c->blocks->blockOf[$current]])){
			$c->level->setState($x, $y, $z, $state);
		}
	}

	private function makeCap(FeatureContext $c, int $x, int $y, int $z, int $treeHeight) : void{
		$blocks = $c->blocks;
		$random = $c->random;
		if(!$this->red){
			$r = $this->foliageRadius;
			for($dx = -$r; $dx <= $r; ++$dx){
				for($dz = -$r; $dz <= $r; ++$dz){
					$minX = $dx === -$r;
					$maxX = $dx === $r;
					$minZ = $dz === -$r;
					$maxZ = $dz === $r;
					$xEdge = $minX || $maxX;
					$zEdge = $minZ || $maxZ;
					if($xEdge && $zEdge){
						continue;
					}
					$west = $minX || ($zEdge && $dx === 1 - $r);
					$east = $maxX || ($zEdge && $dx === $r - 1);
					$north = $minZ || ($xEdge && $dz === 1 - $r);
					$south = $maxZ || ($xEdge && $dz === $r - 1);
					$state = ($this->capProvider)($c, $random, $x, $y, $z) ?? $blocks->air;
					if($blocks->hasProperty($state, "west") && $blocks->hasProperty($state, "east") && $blocks->hasProperty($state, "north") && $blocks->hasProperty($state, "south")){
						$state = $blocks->withBool($state, "west", $west);
						$state = $blocks->withBool($state, "east", $east);
						$state = $blocks->withBool($state, "north", $north);
						$state = $blocks->withBool($state, "south", $south);
					}
					$this->placeMushroomBlock($c, $x + $dx, $y + $treeHeight, $z + $dz, $state);
				}
			}
			return;
		}
		for($dy = $treeHeight - 3; $dy <= $treeHeight; ++$dy){
			$radius = $dy < $treeHeight ? $this->foliageRadius : $this->foliageRadius - 1;
			$center = $this->foliageRadius - 2;
			for($dx = -$radius; $dx <= $radius; ++$dx){
				for($dz = -$radius; $dz <= $radius; ++$dz){
					$xEdge = $dx === -$radius || $dx === $radius;
					$zEdge = $dz === -$radius || $dz === $radius;
					if($dy < $treeHeight && $xEdge === $zEdge){
						continue;
					}
					$state = ($this->capProvider)($c, $random, $x, $y, $z) ?? $blocks->air;
					if($blocks->hasProperty($state, "west") && $blocks->hasProperty($state, "east") && $blocks->hasProperty($state, "north") && $blocks->hasProperty($state, "south") && $blocks->hasProperty($state, "up")){
						$state = $blocks->withBool($state, "up", $dy >= $treeHeight - 1);
						$state = $blocks->withBool($state, "west", $dx < -$center);
						$state = $blocks->withBool($state, "east", $dx > $center);
						$state = $blocks->withBool($state, "north", $dz < -$center);
						$state = $blocks->withBool($state, "south", $dz > $center);
					}
					$this->placeMushroomBlock($c, $x + $dx, $y + $dy, $z + $dz, $state);
				}
			}
		}
	}
}
