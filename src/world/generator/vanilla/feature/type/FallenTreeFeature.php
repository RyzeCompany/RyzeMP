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
use pocketmine\world\generator\vanilla\feature\Providers;
use pocketmine\world\generator\vanilla\feature\type\tree\TreeDecorator;
use pocketmine\world\generator\vanilla\feature\type\tree\TreeDecoratorContext;
use pocketmine\world\generator\vanilla\feature\type\tree\TreeFeature;
use function count;
use function is_array;

/**
 * minecraft:fallen_tree: a one-block stump and a log lying next to it.
 */
final class FallenTreeFeature implements Feature{
	/** @phpstan-var \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int */
	private \Closure $trunkProvider;
	private IntProvider $logLength;
	/** @var TreeDecorator[] */
	private array $stumpDecorators = [];
	/** @var TreeDecorator[] */
	private array $logDecorators = [];
	/** @phpstan-var array<int, true> */
	private array $replaceableByTrees;

	/**
	 * @param mixed[] $json
	 */
	public function __construct(FeatureRegistry $registry, array $json){
		$this->trunkProvider = $registry->stateProvider($json["trunk_provider"] ?? null);
		$this->logLength = Providers::int($json["log_length"] ?? null);
		foreach(is_array($json["stump_decorators"] ?? null) ? $json["stump_decorators"] : [] as $d){
			$this->stumpDecorators[] = new TreeDecorator($registry, $d);
		}
		foreach(is_array($json["log_decorators"] ?? null) ? $json["log_decorators"] : [] as $d){
			$this->logDecorators[] = new TreeDecorator($registry, $d);
		}
		$this->replaceableByTrees = $registry->blocks->tag("minecraft:replaceable_by_trees");
	}

	public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
		$random = $c->random;
		//stump
		$this->placeLog($c, $x, $y, $z, null);
		$this->decorate($c, [JavaHashSet::key($x, $y, $z) => [$x, $y, $z]], $this->stumpDecorators);

		$direction = Direction::PLANE_HORIZONTAL[$random->nextBoundedInt(4)];
		$logLength = $this->logLength->sample($random) - 2;
		$distance = 2 + $random->nextBoundedInt(2);
		$lx = $x + Direction::STEP_X[$direction] * $distance;
		$ly = $y;
		$lz = $z + Direction::STEP_Z[$direction] * $distance;
		//setGroundHeightForFallenLogStartPos()
		++$ly;
		for($i = 0; $i < 6; ++$i){
			if($this->mayPlaceOn($c, $lx, $ly, $lz)){
				break;
			}
			--$ly;
		}
		if(!$this->canPlaceEntireFallenLog($c, $logLength, $lx, $ly, $lz, $direction)){
			return true;
		}
		$axis = ["x", "y", "z"][Direction::AXIS[$direction]];
		$logs = [];
		for($i = 0; $i < $logLength; ++$i){
			$this->placeLog($c, $lx, $ly, $lz, $axis);
			$logs[JavaHashSet::key($lx, $ly, $lz)] = [$lx, $ly, $lz];
			$lx += Direction::STEP_X[$direction];
			$lz += Direction::STEP_Z[$direction];
		}
		$this->decorate($c, $logs, $this->logDecorators);
		return true;
	}

	private function placeLog(FeatureContext $c, int $x, int $y, int $z, ?string $axis) : void{
		$state = ($this->trunkProvider)($c, $c->random, $x, $y, $z) ?? $c->level->getState($x, $y, $z);
		if($axis !== null){
			$state = $c->blocks->with($state, "axis", $axis);
		}
		$c->level->setState($x, $y, $z, $state);
	}

	/**
	 * @param int[][]         $logs
	 * @param TreeDecorator[] $decorators
	 * @phpstan-param array<int, array{int, int, int}> $logs
	 */
	private function decorate(FeatureContext $c, array $logs, array $decorators) : void{
		if(count($decorators) === 0){
			return;
		}
		$context = new TreeDecoratorContext($c, $c->blocks, null, TreeFeature::sortedByY($logs), [], []);
		foreach($decorators as $decorator){
			$decorator->place($context);
		}
	}

	private function validTreePos(FeatureContext $c, int $x, int $y, int $z) : bool{
		$state = $c->level->getState($x, $y, $z);
		return $c->blocks->isAir($state) || isset($this->replaceableByTrees[$c->blocks->blockOf[$state]]);
	}

	private function isOverSolidGround(FeatureContext $c, int $x, int $y, int $z) : bool{
		return $c->blocks->isFaceSturdy($c->level->getState($x, $y - 1, $z), Direction::UP);
	}

	private function mayPlaceOn(FeatureContext $c, int $x, int $y, int $z) : bool{
		return $this->validTreePos($c, $x, $y, $z) && $this->isOverSolidGround($c, $x, $y, $z);
	}

	private function canPlaceEntireFallenLog(FeatureContext $c, int $logLength, int $x, int $y, int $z, int $direction) : bool{
		$gap = 0;
		for($i = 0; $i < $logLength; ++$i){
			if(!$this->validTreePos($c, $x, $y, $z)){
				return false;
			}
			if(!$this->isOverSolidGround($c, $x, $y, $z)){
				if(++$gap > 2){
					return false;
				}
			}else{
				$gap = 0;
			}
			$x += Direction::STEP_X[$direction];
			$z += Direction::STEP_Z[$direction];
		}
		return true;
	}
}
