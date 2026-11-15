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

namespace pocketmine\entity\ai\hostile\goal;

use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\hostile\FreeMoveControl;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\ai\navigation\RandomPos;
use pocketmine\entity\Mob;
use pocketmine\math\Vector3;
use function abs;
use function floor;
use function intdiv;
use function max;
use function mt_rand;

/**
 * Moves a flying or swimming mob to random points nearby from time to time (vanilla behavior.float_wander,
 * random_swim, vex random move). Points are picked in the medium the mob moves in: open air for flyers, water for
 * swimmers.
 */
class FreeWanderGoal extends Goal{
	public const MEDIUM_AIR = 0;
	public const MEDIUM_WATER = 1;
	/** Anything that doesn't block movement (noclip vexes pick points inside blocks too). */
	public const MEDIUM_ANY = 2;

	private int $timeout = 0;

	/**
	 * @phpstan-param (\Closure() : ?Vector3)|null $anchor centre of the wandering area (default: the mob itself)
	 */
	public function __construct(
		protected Mob $mob,
		protected FreeMoveControl $control,
		protected float $speed,
		protected int $interval = 60,
		protected int $horizontalRange = 8,
		protected int $verticalRange = 4,
		protected int $medium = self::MEDIUM_AIR,
		protected ?\Closure $anchor = null
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		if($this->control->hasWanted()){
			return false;
		}
		return mt_rand(0, max(1, intdiv($this->interval, Mob::GOAL_EVALUATION_INTERVAL)) - 1) === 0;
	}

	public function canContinue() : bool{
		return $this->control->hasWanted() && --$this->timeout > 0;
	}

	public function start() : void{
		$this->timeout = 200;
		$pos = $this->pickPoint();
		if($pos !== null){
			$this->control->setWantedPosition($pos->x, $pos->y, $pos->z, $this->speed);
		}
	}

	public function stop() : void{
		$this->control->stop();
	}

	protected function pickPoint() : ?Vector3{
		$centre = $this->anchor !== null ? ($this->anchor)() : null;
		$centre ??= $this->mob->getLiveLocation();
		$world = $this->mob->getWorld();
		//points stay within the home restriction, and lean towards home (see RandomPos)
		$hasHome = $this->mob->getHomeRadius() > 0;
		$restricted = $hasHome && RandomPos::isHomeRestricted($this->mob, $this->horizontalRange);
		$baseX = (int) floor($centre->x);
		$baseZ = (int) floor($centre->z);
		for($try = 0; $try < 10; ++$try){
			if($hasHome){
				[$dx, $dz] = RandomPos::offset($this->mob, $baseX, $baseZ, $this->horizontalRange, null);
				$x = $centre->x + $dx;
				$y = $centre->y + mt_rand(-$this->verticalRange, $this->verticalRange);
				$z = $centre->z + $dz;
				if($restricted && !$this->mob->isWithinHome($x, $y, $z)){
					continue;
				}
			}else{
				//no home: plain random points, no array per try
				$x = $centre->x + mt_rand(-$this->horizontalRange, $this->horizontalRange);
				$y = $centre->y + mt_rand(-$this->verticalRange, $this->verticalRange);
				$z = $centre->z + mt_rand(-$this->horizontalRange, $this->horizontalRange);
			}
			$types = PathBlockTypes::at($world, (int) floor($x), (int) floor($y), (int) floor($z));
			$ok = match($this->medium){
				self::MEDIUM_WATER => ($types & PathBlockTypes::WATER) !== 0,
				self::MEDIUM_ANY => true,
				default => ($types & PathBlockTypes::PASSABLE) !== 0 && ($types & (PathBlockTypes::WATER | PathBlockTypes::DANGER)) === 0
			};
			if($ok && $world->isInWorld((int) floor($x), (int) floor($y), (int) floor($z)) && ($this->medium === self::MEDIUM_ANY || $this->isLineClear($x, $y, $z))){
				return new Vector3($x, $y, $z);
			}
		}
		return null;
	}

	/**
	 * Returns whether the straight line from the mob to the point only crosses blocks of the mob's medium (sampled
	 * every block).
	 */
	protected function isLineClear(float $x, float $y, float $z) : bool{
		$loc = $this->mob->getLiveLocation();
		$world = $this->mob->getWorld();
		$dx = $x - $loc->x;
		$dy = $y - $loc->y;
		$dz = $z - $loc->z;
		$steps = (int) max(abs($dx), abs($dy), abs($dz));
		for($i = 1; $i <= $steps; ++$i){
			$f = $i / $steps;
			$types = PathBlockTypes::at($world, (int) floor($loc->x + $dx * $f), (int) floor($loc->y + $dy * $f), (int) floor($loc->z + $dz * $f));
			if(($types & PathBlockTypes::PASSABLE) === 0 || ($this->medium === self::MEDIUM_WATER) !== (($types & PathBlockTypes::WATER) !== 0)){
				return false;
			}
		}
		return true;
	}
}
