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

namespace pocketmine\entity\ai\goal;

use pocketmine\entity\animal\Cat;
use pocketmine\math\Vector3;
use function abs;
use function mt_rand;

/**
 * Tamed cats like to sit on chests, lit furnaces and the foot of beds (vanilla behavior.ocelot_sit_on_block, Java
 * CatSitOnBlockGoal): every 10-20 seconds a cat which isn't ordered to sit looks for one within 8 blocks (1 up or
 * down) with nothing on top, walks there, hops on and sits for up to a minute. Players can't open a chest a cat sits on.
 */
class SitOnBlockGoal extends Goal{
	public const SEARCH_RANGE = 8;
	public const SEARCH_HEIGHT = 1;
	public const SPEED = 1.0;
	/** Longest time the goal runs (walking there and sitting). */
	public const MAX_TICKS = 1200;

	private ?Vector3 $target = null;
	private int $readyTick;
	private int $ticks = 0;
	private int $repathTick = 0;
	private bool $sitting = false;

	public function __construct(
		protected Cat $cat
	){
		$this->readyTick = mt_rand(200, 400);
	}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_JUMP;
	}

	public function canStart() : bool{
		$cat = $this->cat;
		if(!$cat->isTamed() || $cat->isSitting() || $cat->isSleepingWithOwner() || $cat->getAiTick() < $this->readyTick){
			return false;
		}
		$this->readyTick = $cat->getAiTick() + mt_rand(200, 400);
		$this->target = $this->findBlock();
		return $this->target !== null;
	}

	private function findBlock() : ?Vector3{
		$cat = $this->cat;
		$world = $cat->getWorld();
		$loc = $cat->getLiveLocation();
		$bx = $loc->getFloorX();
		$by = $loc->getFloorY();
		$bz = $loc->getFloorZ();
		$best = null;
		$bestDist = (self::SEARCH_RANGE + 1) ** 2 * 2;
		for($y = -self::SEARCH_HEIGHT; $y <= self::SEARCH_HEIGHT; ++$y){
			for($x = -self::SEARCH_RANGE; $x <= self::SEARCH_RANGE; ++$x){
				for($z = -self::SEARCH_RANGE; $z <= self::SEARCH_RANGE; ++$z){
					$d = $x * $x + $z * $z + $y * $y;
					if($d >= $bestDist || !$world->isInWorld($bx + $x, $by + $y, $bz + $z)){
						continue;
					}
					if(Cat::isSeat($world, $bx + $x, $by + $y, $bz + $z) && !Cat::isCatSittingOn($world, $bx + $x, $by + $y, $bz + $z, $cat)){
						$best = new Vector3($bx + $x, $by + $y, $bz + $z);
						$bestDist = $d;
					}
				}
			}
		}
		return $best;
	}

	public function canContinue() : bool{
		$cat = $this->cat;
		$target = $this->target;
		return $target !== null && $this->ticks <= self::MAX_TICKS && $cat->isTamed() && !$cat->isSitting() &&
			Cat::isSeat($cat->getWorld(), $target->getFloorX(), $target->getFloorY(), $target->getFloorZ());
	}

	public function start() : void{
		$this->ticks = 0;
		$this->repathTick = 0;
		$this->sitting = false;
	}

	public function stop() : void{
		$this->target = null;
		$this->sitting = false;
		$this->cat->setSittingOnBlock(null);
		$this->cat->getNavigation()->stop();
		$this->readyTick = $this->cat->getAiTick() + mt_rand(200, 400);
	}

	public function tick() : void{
		++$this->ticks;
		$target = $this->target;
		if($target === null || $this->sitting){
			return;
		}
		$cat = $this->cat;
		$loc = $cat->getLiveLocation();
		$dx = $target->x + 0.5 - $loc->x;
		$dz = $target->z + 0.5 - $loc->z;
		if($dx * $dx + $dz * $dz <= 2.25 && abs($target->y - $loc->y) < 1.5){
			$cat->getNavigation()->stop();
			$cat->setSittingOnBlock($target);
			$this->sitting = true;
			return;
		}
		$now = $cat->getAiTick();
		if($cat->getNavigation()->isDone() && $now >= $this->repathTick){
			$this->repathTick = $now + 40;
			$cat->getNavigation()->moveTo($target->x + 0.5, $target->y, $target->z + 0.5, self::SPEED, 1.0);
		}
	}
}
