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
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use pocketmine\world\generator\vanilla\feature\IntProvider;
use pocketmine\world\generator\vanilla\feature\JavaHashSet;
use pocketmine\world\generator\vanilla\feature\PlacedFeature;
use pocketmine\world\generator\vanilla\feature\Providers;
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\WorldgenData;
use function count;

/**
 * minecraft:vegetation_patch and minecraft:waterlogged_vegetation_patch (moss, clay with dripleaves, sculk...).
 */
final class VegetationPatchFeature implements Feature{
	/** @phpstan-var array<int, true> */
	private array $replaceable;
	/** @phpstan-var \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int */
	private \Closure $groundState;
	private PlacedFeature $vegetation;
	/** direction towards the surface the patch rests on (DOWN for floor, UP for ceiling) */
	private int $inwards;
	private IntProvider $depth;
	private float $extraBottomBlockChance;
	private int $verticalRange;
	private float $vegetationChance;
	private IntProvider $xzRadius;
	private float $extraEdgeColumnChance;

	/**
	 * @param mixed[] $json
	 */
	public function __construct(FeatureRegistry $registry, array $json, private readonly bool $waterlogged){
		$this->replaceable = $registry->blocks->blockSet($json["replaceable"] ?? null);
		$this->groundState = $registry->stateProvider($json["ground_state"] ?? null);
		$this->vegetation = $registry->placedRef($json["vegetation_feature"] ?? null);
		$this->inwards = WorldgenData::string($json, "surface") === "ceiling" ? Direction::UP : Direction::DOWN;
		$this->depth = Providers::int($json["depth"] ?? null);
		$this->extraBottomBlockChance = FloatMath::fround(WorldgenData::float($json, "extra_bottom_block_chance"));
		$this->verticalRange = WorldgenData::int($json, "vertical_range");
		$this->vegetationChance = FloatMath::fround(WorldgenData::float($json, "vegetation_chance"));
		$this->xzRadius = Providers::int($json["xz_radius"] ?? null);
		$this->extraEdgeColumnChance = FloatMath::fround(WorldgenData::float($json, "extra_edge_column_chance"));
	}

	public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
		$random = $c->random;
		$xRadius = $this->xzRadius->sample($random) + 1;
		$zRadius = $this->xzRadius->sample($random) + 1;
		$surface = $this->placeGroundPatch($c, $x, $y, $z, $xRadius, $zRadius);
		if($this->waterlogged){
			$water = [];
			foreach(JavaHashSet::iterationOrder($surface) as [$sx, $sy, $sz]){
				if(!$this->isExposed($c, $sx, $sy, $sz)){
					$water[JavaHashSet::key($sx, $sy, $sz)] = [$sx, $sy, $sz];
				}
			}
			$waterState = $c->blocks->water;
			foreach(JavaHashSet::iterationOrder($water) as [$sx, $sy, $sz]){
				$c->level->setState($sx, $sy, $sz, $waterState);
			}
			$surface = $water;
		}
		$this->distributeVegetation($c, $surface);
		return count($surface) > 0;
	}

	/**
	 * @phpstan-return array<int, array{int, int, int}>
	 */
	private function placeGroundPatch(FeatureContext $c, int $ox, int $oy, int $oz, int $xRadius, int $zRadius) : array{
		$random = $c->random;
		$level = $c->level;
		$blocks = $c->blocks;
		$inwards = $this->inwards;
		$iy = Direction::STEP_Y[$inwards];
		$outwards = Direction::OPPOSITE[$inwards];
		$surface = [];
		for($dx = -$xRadius; $dx <= $xRadius; ++$dx){
			$isXEdge = $dx === -$xRadius || $dx === $xRadius;
			for($dz = -$zRadius; $dz <= $zRadius; ++$dz){
				$isZEdge = $dz === -$zRadius || $dz === $zRadius;
				$isEdge = $isXEdge || $isZEdge;
				$isCorner = $isXEdge && $isZEdge;
				if($isCorner){
					continue;
				}
				if($isEdge && ($this->extraEdgeColumnChance === 0.0 || $random->nextFloat() > $this->extraEdgeColumnChance)){
					continue;
				}
				$px = $ox + $dx;
				$py = $oy;
				$pz = $oz + $dz;
				for($offset = 0; $blocks->isAir($level->getState($px, $py, $pz)) && $offset < $this->verticalRange; ++$offset){
					$py += $iy;
				}
				for($offset = 0; !$blocks->isAir($level->getState($px, $py, $pz)) && $offset < $this->verticalRange; ++$offset){
					$py -= $iy;
				}
				$by = $py + $iy;
				$below = $level->getState($px, $by, $pz);
				if(!$blocks->isAir($level->getState($px, $py, $pz)) || !$blocks->isFaceSturdy($below, $outwards)){
					continue;
				}
				$depth = $this->depth->sample($random) + ($this->extraBottomBlockChance > 0.0 && $random->nextFloat() < $this->extraBottomBlockChance ? 1 : 0);
				if($this->placeGround($c, $px, $by, $pz, $depth)){
					$surface[JavaHashSet::key($px, $by, $pz)] = [$px, $by, $pz];
				}
			}
		}
		return $surface;
	}

	private function placeGround(FeatureContext $c, int $x, int $y, int $z, int $depth) : bool{
		$level = $c->level;
		$blocks = $c->blocks;
		$iy = Direction::STEP_Y[$this->inwards];
		for($i = 0; $i < $depth; ++$i){
			$state = ($this->groundState)($c, $c->random, $x, $y, $z) ?? $level->getState($x, $y, $z);
			$current = $level->getState($x, $y, $z);
			if($blocks->blockOf[$state] === $blocks->blockOf[$current]){
				continue;
			}
			if(!isset($this->replaceable[$blocks->blockOf[$current]])){
				return $i !== 0;
			}
			$level->setState($x, $y, $z, $state);
			$y += $iy;
		}
		return true;
	}

	/**
	 * @param int[][] $surface
	 * @phpstan-param array<int, array{int, int, int}> $surface
	 */
	/**
	 * @param int[][] $surface
	 * @phpstan-param array<int, array{int, int, int}> $surface
	 */
	private function distributeVegetation(FeatureContext $c, array $surface) : void{
		$random = $c->random;
		foreach(JavaHashSet::iterationOrder($surface) as [$x, $y, $z]){
			if($this->vegetationChance > 0.0 && $random->nextFloat() < $this->vegetationChance){
				$this->placeVegetation($c, $x, $y, $z);
			}
		}
	}

	private function placeVegetation(FeatureContext $c, int $x, int $y, int $z) : void{
		$oy = -Direction::STEP_Y[$this->inwards];
		if(!$this->waterlogged){
			$this->vegetation->place($c, $x, $y + $oy, $z);
			return;
		}
		//WaterloggedVegetationPatchFeature: the vegetation goes one block lower, and gets waterlogged
		if($this->vegetation->place($c, $x, $y - 1 + $oy, $z)){
			$blocks = $c->blocks;
			$placed = $c->level->getState($x, $y, $z);
			if($blocks->hasProperty($placed, "waterlogged") && !$blocks->getBool($placed, "waterlogged")){
				$c->level->setState($x, $y, $z, $blocks->withBool($placed, "waterlogged", true));
			}
		}
	}

	private function isExposed(FeatureContext $c, int $x, int $y, int $z) : bool{
		foreach([Direction::NORTH, Direction::EAST, Direction::SOUTH, Direction::WEST, Direction::DOWN] as $d){
			$state = $c->level->getState($x + Direction::STEP_X[$d], $y + Direction::STEP_Y[$d], $z + Direction::STEP_Z[$d]);
			if(!$c->blocks->isFaceSturdy($state, Direction::OPPOSITE[$d])){
				return true;
			}
		}
		return false;
	}
}
