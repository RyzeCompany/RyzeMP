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
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\WorldgenData;
use function abs;
use function array_values;
use function count;

/**
 * minecraft:multiface_growth (glow lichen, sculk veins) with the block's spreader (MultifaceSpreader).
 */
final class MultifaceGrowthFeature implements Feature{
	private int $block;
	private int $blockState;
	private int $searchRange;
	private bool $floor;
	private bool $ceiling;
	private bool $wall;
	private float $chanceOfSpreading;
	/** @phpstan-var array<int, true> */
	private array $canBePlacedOn;
	private bool $sculkVein;
	private int $sculk;
	private int $sculkCatalyst;
	private int $movingPiston;

	/**
	 * @param mixed[] $json
	 */
	public function __construct(FeatureRegistry $registry, array $json){
		$blocks = $registry->blocks;
		$name = WorldgenData::string($json, "block");
		$this->block = $blocks->blockIndex($name);
		$this->blockState = $blocks->defaultOf($name);
		$this->searchRange = WorldgenData::int($json, "search_range", 10);
		$this->floor = ($json["can_place_on_floor"] ?? false) === true;
		$this->ceiling = ($json["can_place_on_ceiling"] ?? false) === true;
		$this->wall = ($json["can_place_on_wall"] ?? false) === true;
		$this->chanceOfSpreading = FloatMath::fround(WorldgenData::float($json, "chance_of_spreading", 0.5));
		$this->canBePlacedOn = $blocks->blockSet($json["can_be_placed_on"] ?? null);
		$this->sculkVein = JavaBlocks::namespaced($name) === "minecraft:sculk_vein";
		$this->sculk = $blocks->blockIndex("sculk");
		$this->sculkCatalyst = $blocks->blockIndex("sculk_catalyst");
		$this->movingPiston = $blocks->blockIndex("moving_piston");
	}

	/**
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	private function validDirections() : array{
		$list = [];
		if($this->ceiling){
			$list[] = Direction::UP;
		}
		if($this->floor){
			$list[] = Direction::DOWN;
		}
		if($this->wall){
			foreach(Direction::PLANE_HORIZONTAL as $d){
				$list[] = $d;
			}
		}
		return $list;
	}

	/**
	 * Util.shuffle() of a list.
	 * @param int[] $list
	 * @phpstan-param list<int> $list
	 * @phpstan-return list<int>
	 */
	private static function shuffle(array $list, FeatureRandom $random) : array{
		for($i = count($list); $i > 1; --$i){
			$swapTo = $random->nextBoundedInt($i);
			$tmp = $list[$i - 1];
			$list[$i - 1] = $list[$swapTo];
			$list[$swapTo] = $tmp;
		}
		return array_values($list);
	}

	private function isAirOrWater(FeatureContext $c, int $state) : bool{
		return $c->blocks->isAir($state) || $c->blocks->blockOf[$state] === $c->blocks->waterBlock;
	}

	public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
		$level = $c->level;
		$random = $c->random;
		if(!$this->isAirOrWater($c, $level->getState($x, $y, $z))){
			return false;
		}
		$searchDirections = self::shuffle($this->validDirections(), $random);
		if($this->placeGrowthIfPossible($c, $x, $y, $z, $level->getState($x, $y, $z), $searchDirections)){
			return true;
		}
		foreach($searchDirections as $search){
			$except = [];
			foreach($this->validDirections() as $d){
				if($d !== Direction::OPPOSITE[$search]){
					$except[] = $d;
				}
			}
			$placementDirections = self::shuffle($except, $random);
			for($i = 0; $i < $this->searchRange; ++$i){
				//pos.setWithOffset(origin, searchDirection): always one block from the origin
				$px = $x + Direction::STEP_X[$search];
				$py = $y + Direction::STEP_Y[$search];
				$pz = $z + Direction::STEP_Z[$search];
				$state = $level->getState($px, $py, $pz);
				if(!$this->isAirOrWater($c, $state) && $c->blocks->blockOf[$state] !== $this->block){
					break;
				}
				if($this->placeGrowthIfPossible($c, $px, $py, $pz, $state, $placementDirections)){
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @param int[] $directions
	 */
	private function placeGrowthIfPossible(FeatureContext $c, int $x, int $y, int $z, int $oldState, array $directions) : bool{
		$level = $c->level;
		foreach($directions as $d){
			$neighbour = $level->getState($x + Direction::STEP_X[$d], $y + Direction::STEP_Y[$d], $z + Direction::STEP_Z[$d]);
			if(!isset($this->canBePlacedOn[$c->blocks->blockOf[$neighbour]])){
				continue;
			}
			$newState = $this->getStateForPlacement($c, $oldState, $x, $y, $z, $d);
			if($newState === null){
				return false;
			}
			$level->setState($x, $y, $z, $newState);
			if($c->random->nextFloat() < $this->chanceOfSpreading){
				$this->spreadFromFaceTowardRandomDirection($c, $newState, $x, $y, $z, $d);
			}
			return true;
		}
		return false;
	}

	private function hasFace(FeatureContext $c, int $state, int $direction) : bool{
		return $c->blocks->getProperty($state, Direction::NAMES[$direction]) === "true";
	}

	/**
	 * MultifaceBlock.isValidStateForPlacement()
	 */
	private function isValidStateForPlacement(FeatureContext $c, int $oldState, int $x, int $y, int $z, int $direction) : bool{
		if($c->blocks->blockOf[$oldState] === $this->block && $this->hasFace($c, $oldState, $direction)){
			return false;
		}
		return $c->survival->canAttachTo($c->level, $x, $y, $z, $direction);
	}

	/**
	 * MultifaceBlock.getStateForPlacement(oldState, level, pos, direction)
	 */
	private function getStateForPlacement(FeatureContext $c, int $oldState, int $x, int $y, int $z, int $direction) : ?int{
		if(!$this->isValidStateForPlacement($c, $oldState, $x, $y, $z, $direction)){
			return null;
		}
		$blocks = $c->blocks;
		if($blocks->blockOf[$oldState] === $this->block){
			$state = $oldState;
		}elseif($blocks->isWater($oldState) && $blocks->fluidIsSource($oldState)){
			$state = $blocks->withBool($this->blockState, "waterlogged", true);
		}else{
			$state = $this->blockState;
		}
		return $blocks->withBool($state, Direction::NAMES[$direction], true);
	}

	private function spreadFromFaceTowardRandomDirection(FeatureContext $c, int $state, int $x, int $y, int $z, int $startingFace) : void{
		$directions = self::shuffle([Direction::DOWN, Direction::UP, Direction::NORTH, Direction::SOUTH, Direction::WEST, Direction::EAST], $c->random);
		foreach($directions as $spread){
			if(Direction::AXIS[$spread] === Direction::AXIS[$startingFace]){
				continue;
			}
			$otherValidAsSource = $this->sculkVein && $c->blocks->blockOf[$state] !== $this->block;
			if(!$otherValidAsSource && (!$this->hasFace($c, $state, $startingFace) || $this->hasFace($c, $state, $spread))){
				continue;
			}
			$spreadTypes = $this->sculkVein ? [0, 1, 2] : [0, 1, 2];
			foreach($spreadTypes as $type){
				if($type === 0){
					[$px, $py, $pz, $face] = [$x, $y, $z, $spread];
				}elseif($type === 1){
					[$px, $py, $pz, $face] = [$x + Direction::STEP_X[$spread], $y + Direction::STEP_Y[$spread], $z + Direction::STEP_Z[$spread], $startingFace];
				}else{
					[$px, $py, $pz, $face] = [
						$x + Direction::STEP_X[$spread] + Direction::STEP_X[$startingFace],
						$y + Direction::STEP_Y[$spread] + Direction::STEP_Y[$startingFace],
						$z + Direction::STEP_Z[$spread] + Direction::STEP_Z[$startingFace],
						Direction::OPPOSITE[$spread]
					];
				}
				if($this->canSpreadInto($c, $x, $y, $z, $px, $py, $pz, $face)){
					$existing = $c->level->getState($px, $py, $pz);
					$spreadState = $this->getStateForPlacement($c, $existing, $px, $py, $pz, $face);
					if($spreadState !== null){
						$c->level->setState($px, $py, $pz, $spreadState);
						return;
					}
					break; //this direction is done (Optional.flatMap() gave nothing)
				}
			}
		}
	}

	private function canSpreadInto(FeatureContext $c, int $sx, int $sy, int $sz, int $x, int $y, int $z, int $face) : bool{
		$existing = $c->level->getState($x, $y, $z);
		return $this->stateCanBeReplaced($c, $sx, $sy, $sz, $x, $y, $z, $face, $existing) && $this->isValidStateForPlacement($c, $existing, $x, $y, $z, $face);
	}

	private function stateCanBeReplaced(FeatureContext $c, int $sx, int $sy, int $sz, int $x, int $y, int $z, int $face, int $existing) : bool{
		$blocks = $c->blocks;
		if($this->sculkVein){
			$against = $blocks->blockOf[$c->level->getState($x + Direction::STEP_X[$face], $y + Direction::STEP_Y[$face], $z + Direction::STEP_Z[$face])];
			if($against === $this->sculk || $against === $this->sculkCatalyst || $against === $this->movingPiston){
				return false;
			}
			if(abs($sx - $x) + abs($sy - $y) + abs($sz - $z) === 2){
				$o = Direction::OPPOSITE[$face];
				$n = $c->level->getState($sx + Direction::STEP_X[$o], $sy + Direction::STEP_Y[$o], $sz + Direction::STEP_Z[$o]);
				if($blocks->isFaceSturdy($n, $face)){
					return false;
				}
			}
			$fluid = $blocks->fluid($existing);
			if($fluid !== JavaBlocks::FLUID_NONE && !$blocks->isWater($existing)){
				return false;
			}
			if($blocks->isTagged($existing, "fire")){
				return false;
			}
			if($blocks->isTagged($existing, "replaceable")){
				return true;
			}
		}
		return $blocks->isAir($existing) || $blocks->blockOf[$existing] === $this->block || ($blocks->blockOf[$existing] === $blocks->waterBlock && $blocks->fluidIsSource($existing));
	}
}
