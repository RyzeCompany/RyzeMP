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

namespace pocketmine\entity\ai\raid;

use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Raider;
use pocketmine\math\Vector3;
use function ceil;
use function floor;
use function max;
use function sqrt;

/**
 * A raider with nothing to attack walks to the centre of the raided village (vanilla behavior.move_to_village), in
 * steps of {@link RaidMoveGoal::STEP} blocks so that each path search stays short.
 */
class RaidMoveGoal extends Goal{
	public const STEP = 12;
	/** Distance from the centre at which raiders stop heading there and roam the village. */
	public const ARRIVED_DISTANCE = 10.0;

	private ?Vector3 $step = null;

	public function __construct(
		protected Mob&Raider $mob,
		protected float $speedMultiplier = 1.0
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		$center = $this->mob->getRaidCenter();
		if($center === null || $this->mob->getAttackTarget() !== null || $this->mob->isCelebrating()){
			return false;
		}
		$pos = $this->mob->getLiveLocation();
		if(($pos->x - $center->x) ** 2 + ($pos->z - $center->z) ** 2 <= self::ARRIVED_DISTANCE ** 2){
			return false;
		}
		$this->step = self::stepTowards($this->mob, $center, self::STEP);
		return $this->step !== null;
	}

	public function canContinue() : bool{
		return $this->mob->getRaidCenter() !== null && $this->mob->getAttackTarget() === null && !$this->mob->getNavigation()->isDone();
	}

	public function start() : void{
		if($this->step !== null){
			$this->mob->getNavigation()->moveTo($this->step->x, $this->step->y, $this->step->z, $this->speedMultiplier, 1.0);
		}
	}

	public function stop() : void{
		$this->mob->getNavigation()->stop();
	}

	/**
	 * Returns a block where the mob can stand at most $step blocks from it in the direction of $target (or the target
	 * itself when closer), or null if there is none.
	 */
	public static function stepTowards(Mob $mob, Vector3 $target, int $step) : ?Vector3{
		$pos = $mob->getLiveLocation();
		$dx = $target->x - $pos->x;
		$dz = $target->z - $pos->z;
		$dist = sqrt($dx * $dx + $dz * $dz);
		if($dist < 0.5){
			return null;
		}
		$scale = $dist > $step ? $step / $dist : 1.0;
		$x = (int) floor($pos->x + $dx * $scale);
		$z = (int) floor($pos->z + $dz * $scale);
		$baseY = $dist > $step ? (int) floor($pos->y) : (int) floor($target->y);
		$world = $mob->getWorld();
		$headBlocks = max(0, (int) ceil($mob->getSize()->getHeight()) - 1);
		//prefer the nearest height to the current one: 0, +1, -1, +2, -2...
		for($i = 0; $i <= 16; ++$i){
			$y = $baseY + (($i & 1) === 1 ? ($i + 1) >> 1 : -($i >> 1));
			if($y < $world->getMinY() || $y >= $world->getMaxY()){
				continue;
			}
			if(PathBlockTypes::isStandable($world, $x, $y, $z, $headBlocks, false)){
				return new Vector3($x + 0.5, $y, $z + 0.5);
			}
		}
		return null;
	}
}
