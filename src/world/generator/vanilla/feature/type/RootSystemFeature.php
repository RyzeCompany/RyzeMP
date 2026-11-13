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
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\feature\Feature;
use pocketmine\world\generator\vanilla\feature\FeatureContext;
use pocketmine\world\generator\vanilla\feature\FeatureLevel;
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use pocketmine\world\generator\vanilla\feature\PlacedFeature;
use pocketmine\world\generator\vanilla\feature\Predicates;
use pocketmine\world\generator\vanilla\WorldgenData;

/**
 * minecraft:root_system (rooted azalea trees, sulfur springs): a tree on top of a column of rooted dirt, with hanging
 * roots around the origin.
 */
final class RootSystemFeature implements Feature{
	/** Direction.from2DDataValue() */
	private const DATA_2D = [Direction::SOUTH, Direction::WEST, Direction::NORTH, Direction::EAST];

	private PlacedFeature $treeFeature;
	private int $requiredVerticalSpaceForTree;
	private int $levelTestDistance;
	private int $maxLevelDeviation;
	private int $rootRadius;
	/** @phpstan-var array<int, true> */
	private array $rootReplaceable;
	/** @phpstan-var \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int */
	private \Closure $rootStateProvider;
	private int $rootPlacementAttempts;
	private int $rootColumnMaxHeight;
	private int $hangingRootRadius;
	private int $hangingRootsVerticalSpan;
	/** @phpstan-var \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int */
	private \Closure $hangingRootStateProvider;
	private int $hangingRootPlacementAttempts;
	private int $allowedVerticalWaterForTree;
	/** @phpstan-var \Closure(FeatureContext, int, int, int) : bool */
	private \Closure $allowedTreePosition;

	/**
	 * @param mixed[] $json
	 */
	public function __construct(FeatureRegistry $registry, array $json){
		$this->treeFeature = $registry->placedRef($json["feature"] ?? null);
		$this->requiredVerticalSpaceForTree = WorldgenData::int($json, "required_vertical_space_for_tree");
		$this->levelTestDistance = WorldgenData::int($json, "level_test_distance");
		$this->maxLevelDeviation = WorldgenData::int($json, "max_level_deviation");
		$this->rootRadius = WorldgenData::int($json, "root_radius");
		$this->rootReplaceable = $registry->blocks->blockSet($json["root_replaceable"] ?? null);
		$this->rootStateProvider = $registry->stateProvider($json["root_state_provider"] ?? null);
		$this->rootPlacementAttempts = WorldgenData::int($json, "root_placement_attempts");
		$this->rootColumnMaxHeight = WorldgenData::int($json, "root_column_max_height");
		$this->hangingRootRadius = WorldgenData::int($json, "hanging_root_radius");
		$this->hangingRootsVerticalSpan = WorldgenData::int($json, "hanging_roots_vertical_span");
		$this->hangingRootStateProvider = $registry->stateProvider($json["hanging_root_state_provider"] ?? null);
		$this->hangingRootPlacementAttempts = WorldgenData::int($json, "hanging_root_placement_attempts");
		$this->allowedVerticalWaterForTree = WorldgenData::int($json, "allowed_vertical_water_for_tree");
		$this->allowedTreePosition = Predicates::block($registry->blocks, $json["allowed_tree_position"] ?? null);
	}

	public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
		if(!$c->blocks->isAir($c->level->getState($x, $y, $z))){
			return false;
		}
		if($this->placeDirtAndTree($c, $x, $y, $z)){
			$this->placeRoots($c, $x, $y, $z);
		}
		return true;
	}

	private function spaceForTree(FeatureContext $c, int $x, int $y, int $z) : bool{
		$blocks = $c->blocks;
		$level = $c->level;
		for($i = 1; $i <= $this->requiredVerticalSpaceForTree; ++$i){
			$state = $level->getState($x, $y + $i, $z);
			if(!$blocks->isAir($state) && !($i + 1 <= $this->allowedVerticalWaterForTree && $blocks->fluidInTag($state, "water"))){
				return false;
			}
		}
		if($this->levelTestDistance > 0){
			foreach(self::DATA_2D as $d){
				$cx = $x + Direction::STEP_X[$d] * $this->levelTestDistance;
				$cz = $z + Direction::STEP_Z[$d] * $this->levelTestDistance;
				if($blocks->isAir($level->getState($cx, $y - $this->maxLevelDeviation, $cz)) || !$blocks->isAir($level->getState($cx, $y + $this->maxLevelDeviation, $cz))){
					return false;
				}
			}
		}
		return true;
	}

	private function placeDirtAndTree(FeatureContext $c, int $x, int $y, int $z) : bool{
		$level = $c->level;
		$blocks = $c->blocks;
		$wy = $y;
		for($i = 0; $i < $this->rootColumnMaxHeight; ++$i){
			++$wy;
			if($level->getHeight(FeatureLevel::WORLD_SURFACE, $x, $z) < $wy){
				return false;
			}
			if(!($this->allowedTreePosition)($c, $x, $wy, $z) || !$this->spaceForTree($c, $x, $wy, $z)){
				continue;
			}
			$below = $level->getState($x, $wy - 1, $z);
			if(($blocks->fluid($below) & 15) === JavaBlocks::FLUID_LAVA || !$blocks->isSolid($below)){
				return false;
			}
			if($this->treeFeature->place($c, $x, $wy, $z)){
				for($dy = $y; $dy < $y + $i; ++$dy){
					$this->placeRootedDirt($c, $x, $dy, $z);
				}
				return true;
			}
		}
		return false;
	}

	private function placeRootedDirt(FeatureContext $c, int $x, int $y, int $z) : void{
		$random = $c->random;
		$level = $c->level;
		for($i = 0; $i < $this->rootPlacementAttempts; ++$i){
			$px = $x + $random->nextBoundedInt($this->rootRadius) - $random->nextBoundedInt($this->rootRadius);
			$pz = $z + $random->nextBoundedInt($this->rootRadius) - $random->nextBoundedInt($this->rootRadius);
			if(isset($this->rootReplaceable[$c->blocks->blockOf[$level->getState($px, $y, $pz)]])){
				$state = ($this->rootStateProvider)($c, $random, $px, $y, $pz);
				if($state !== null){
					$level->setState($px, $y, $pz, $state);
				}
			}
		}
	}

	private function placeRoots(FeatureContext $c, int $x, int $y, int $z) : void{
		$random = $c->random;
		$level = $c->level;
		for($i = 0; $i < $this->hangingRootPlacementAttempts; ++$i){
			$px = $x + $random->nextBoundedInt($this->hangingRootRadius) - $random->nextBoundedInt($this->hangingRootRadius);
			$py = $y + $random->nextBoundedInt($this->hangingRootsVerticalSpan) - $random->nextBoundedInt($this->hangingRootsVerticalSpan);
			$pz = $z + $random->nextBoundedInt($this->hangingRootRadius) - $random->nextBoundedInt($this->hangingRootRadius);
			if(!$level->isEmptyBlock($px, $py, $pz)){
				continue;
			}
			$state = ($this->hangingRootStateProvider)($c, $random, $px, $py, $pz);
			if($state === null || !$c->survival->canSurvive($level, $state, $px, $py, $pz)){
				continue;
			}
			if($c->blocks->isFaceSturdy($level->getState($px, $py + 1, $pz), Direction::DOWN)){
				$level->setState($px, $py, $pz, $state);
			}
		}
	}
}
