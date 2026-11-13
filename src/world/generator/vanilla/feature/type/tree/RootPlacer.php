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

namespace pocketmine\world\generator\vanilla\feature\type\tree;

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\feature\FeatureContext;
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use pocketmine\world\generator\vanilla\feature\IntProvider;
use pocketmine\world\generator\vanilla\feature\Providers;
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\WorldgenData;
use function abs;
use function count;
use function is_array;

/**
 * minecraft:mangrove_root_placer (the only root placer of Java 26.3).
 */
final class RootPlacer{
	public IntProvider $trunkOffsetY;
	/** @phpstan-var \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int */
	private \Closure $rootProvider;
	/** @phpstan-var (\Closure(FeatureContext, FeatureRandom, int, int, int) : ?int)|null */
	private ?\Closure $aboveRootProvider = null;
	private float $aboveRootChance = 0.0;
	/** @phpstan-var array<int, true> */
	private array $canGrowThrough;
	/** @phpstan-var array<int, true> */
	private array $muddyRootsIn;
	/** @phpstan-var \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int */
	private \Closure $muddyRootsProvider;
	private int $maxRootWidth;
	private int $maxRootLength;
	private float $randomSkewChance;

	/**
	 * @param mixed[] $json
	 */
	public function __construct(FeatureRegistry $registry, array $json){
		$type = WorldgenData::stripNamespace(WorldgenData::string($json, "type"));
		if($type !== "mangrove_root_placer"){
			throw new \RuntimeException("Unknown root placer $type");
		}
		$this->trunkOffsetY = Providers::int($json["trunk_offset_y"] ?? null);
		$this->rootProvider = $registry->stateProvider($json["root_provider"] ?? null);
		if(is_array($json["above_root_placement"] ?? null)){
			$above = $json["above_root_placement"];
			$this->aboveRootProvider = $registry->stateProvider($above["above_root_provider"] ?? null);
			$this->aboveRootChance = FloatMath::fround(WorldgenData::float($above, "above_root_placement_chance"));
		}
		$placement = WorldgenData::array($json, "mangrove_root_placement");
		$this->canGrowThrough = $registry->blocks->blockSet($placement["can_grow_through"] ?? null);
		$this->muddyRootsIn = $registry->blocks->blockSet($placement["muddy_roots_in"] ?? null);
		$this->muddyRootsProvider = $registry->stateProvider($placement["muddy_roots_provider"] ?? null);
		$this->maxRootWidth = WorldgenData::int($placement, "max_root_width");
		$this->maxRootLength = WorldgenData::int($placement, "max_root_length");
		$this->randomSkewChance = FloatMath::fround(WorldgenData::float($placement, "random_skew_chance"));
	}

	private function canPlaceRoot(TreeFeature $t, int $x, int $y, int $z) : bool{
		return $t->validTreePos($x, $y, $z) || isset($this->canGrowThrough[$t->blocks->blockOf[$t->c->level->getState($x, $y, $z)]]);
	}

	public function placeRoots(TreeFeature $t, int $ox, int $oy, int $oz, int $tx, int $ty, int $tz) : bool{
		for($y = $oy; $y < $ty; ++$y){
			if(!$this->canPlaceRoot($t, $ox, $y, $oz)){
				return false;
			}
		}
		$positions = [[$tx, $ty - 1, $tz]];
		foreach(Direction::PLANE_HORIZONTAL as $dir){
			$px = $tx + Direction::STEP_X[$dir];
			$pz = $tz + Direction::STEP_Z[$dir];
			$inDirection = [];
			if(!$this->simulateRoots($t, $px, $ty, $pz, $dir, $tx, $ty, $tz, $inDirection, 0)){
				return false;
			}
			foreach($inDirection as $p){
				$positions[] = $p;
			}
			$positions[] = [$px, $ty, $pz];
		}
		foreach($positions as [$x, $y, $z]){
			$this->placeRoot($t, $x, $y, $z);
		}
		return true;
	}

	/**
	 * @param int[][] $positions
	 * @phpstan-param list<array{int, int, int}> $positions
	 */
	private function simulateRoots(TreeFeature $t, int $x, int $y, int $z, int $dir, int $ox, int $oy, int $oz, array &$positions, int $layer) : bool{
		if($layer === $this->maxRootLength || count($positions) > $this->maxRootLength){
			return false;
		}
		foreach($this->potentialRootPositions($t->c->random, $x, $y, $z, $dir, $ox, $oy, $oz) as [$px, $py, $pz]){
			if($this->canPlaceRoot($t, $px, $py, $pz)){
				$positions[] = [$px, $py, $pz];
				if(!$this->simulateRoots($t, $px, $py, $pz, $dir, $ox, $oy, $oz, $positions, $layer + 1)){
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * @phpstan-return list<array{int, int, int}>
	 */
	private function potentialRootPositions(FeatureRandom $random, int $x, int $y, int $z, int $dir, int $ox, int $oy, int $oz) : array{
		$below = [$x, $y - 1, $z];
		$nx = $x + Direction::STEP_X[$dir];
		$nz = $z + Direction::STEP_Z[$dir];
		$width = (int) FloatMath::fround(FloatMath::fround((float) abs($ox - $x) + (float) abs($oy - $y)) + (float) abs($oz - $z));
		if($width > $this->maxRootWidth - 3 && $width <= $this->maxRootWidth){
			return $random->nextFloat() < $this->randomSkewChance ? [$below, [$nx, $y - 1, $nz]] : [$below];
		}
		if($width > $this->maxRootWidth){
			return [$below];
		}
		if($random->nextFloat() < $this->randomSkewChance){
			return [$below];
		}
		return $random->nextBoolean() ? [[$nx, $y, $nz]] : [$below];
	}

	private function placeRoot(TreeFeature $t, int $x, int $y, int $z) : void{
		$c = $t->c;
		$blocks = $t->blocks;
		if(isset($this->muddyRootsIn[$blocks->blockOf[$c->level->getState($x, $y, $z)]])){
			$state = ($this->muddyRootsProvider)($c, $c->random, $x, $y, $z) ?? $c->level->getState($x, $y, $z);
			$t->setRoot($x, $y, $z, $this->waterlogged($t, $x, $y, $z, $state));
			return;
		}
		if(!$this->canPlaceRoot($t, $x, $y, $z)){
			return;
		}
		$state = ($this->rootProvider)($c, $c->random, $x, $y, $z) ?? $c->level->getState($x, $y, $z);
		$t->setRoot($x, $y, $z, $this->waterlogged($t, $x, $y, $z, $state));
		if($this->aboveRootProvider !== null && $c->random->nextFloat() < $this->aboveRootChance && $blocks->isAir($c->level->getState($x, $y + 1, $z))){
			$above = ($this->aboveRootProvider)($c, $c->random, $x, $y + 1, $z) ?? $c->level->getState($x, $y + 1, $z);
			$t->setRoot($x, $y + 1, $z, $this->waterlogged($t, $x, $y + 1, $z, $above));
		}
	}

	private function waterlogged(TreeFeature $t, int $x, int $y, int $z, int $state) : int{
		$blocks = $t->blocks;
		if(!$blocks->hasProperty($state, "waterlogged")){
			return $state;
		}
		return $blocks->withBool($state, "waterlogged", $blocks->isWater($t->c->level->getState($x, $y, $z)));
	}
}
