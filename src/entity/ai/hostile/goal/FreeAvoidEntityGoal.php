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
use pocketmine\entity\Entity;
use pocketmine\entity\Mob;
use function atan2;
use function cos;
use function floor;
use function mt_rand;
use function sin;
use const M_PI;

/**
 * Swims (or flies) away from a dangerous entity that comes closer than $distance (vanilla behavior.avoid_mob_type of a
 * free moving mob: guardians keeping away from players within 8 blocks while they are guardian_passive). Picks a point
 * of the mob's medium (water) 4 to 8 blocks away, roughly opposite the danger, and moves to it in a straight line.
 */
class FreeAvoidEntityGoal extends Goal{
	private const TRIES = 6;

	private ?Entity $danger = null;

	/**
	 * @phpstan-param \Closure() : ?Entity $finder returns the entity to flee from, if any (only called while the goal
	 *                                     may start)
	 * @param float $speed blocks per tick
	 */
	public function __construct(
		protected Mob $mob,
		protected FreeMoveControl $control,
		protected \Closure $finder,
		protected float $distance,
		protected float $speed
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		$danger = ($this->finder)();
		if($danger === null || (Mob::getLiveLocationOf($danger) ?? $danger->getLocation())->distanceSquared($this->mob->getLiveLocation()) > $this->distance ** 2){
			return false;
		}
		$this->danger = $danger;
		return true;
	}

	public function canContinue() : bool{
		return $this->danger !== null && !$this->danger->isClosed() && $this->control->hasWanted() && ($this->finder)() !== null;
	}

	public function start() : void{
		$danger = $this->danger;
		if($danger === null){
			return;
		}
		$loc = $this->mob->getLiveLocation();
		$from = $danger->getLocation();
		$away = atan2($loc->z - $from->z, $loc->x - $from->x);
		$world = $this->mob->getWorld();
		for($i = 0; $i < self::TRIES; ++$i){
			//straight away first, then up to 60 degrees off
			$angle = $away + ($i === 0 ? 0 : mt_rand(-60, 60) * M_PI / 180);
			$length = mt_rand(4, 8);
			$x = $loc->x + cos($angle) * $length;
			$y = $loc->y + ($i === 0 ? 0 : mt_rand(-2, 2));
			$z = $loc->z + sin($angle) * $length;
			$bx = (int) floor($x);
			$by = (int) floor($y);
			$bz = (int) floor($z);
			if($world->isInWorld($bx, $by, $bz) && (PathBlockTypes::at($world, $bx, $by, $bz) & PathBlockTypes::WATER) !== 0){
				$this->control->setWantedPosition($x, $y, $z, $this->speed);
				return;
			}
		}
		$this->danger = null;
	}

	public function stop() : void{
		$this->danger = null;
		$this->control->stop();
	}
}
