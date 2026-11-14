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

namespace pocketmine\entity\ai\navigation;

use pocketmine\entity\Entity;
use pocketmine\entity\Mob;
use function floor;

/**
 * Navigation of mobs that climb walls (vanilla Bedrock navigation.climb, Java WallClimberNavigation): when there is
 * no path to the destination, or the path ends short of it (the target is on top of a wall, out of reach of a walking
 * path), the mob walks straight at the destination and climbs whatever is in the way.
 */
class ClimbingPathNavigator extends PathNavigator{
	/** @phpstan-var array{int, int, int}|null */
	private ?array $destination = null;
	private float $climbSpeedMultiplier = 1.0;

	public function __construct(
		private Mob $climber,
		int $maxDrop = 3,
		bool $canSwim = true,
		bool $avoidWater = false,
		?NavigationOptions $options = null
	){
		parent::__construct($climber, $maxDrop, $canSwim, $avoidWater, $options);
	}

	public function moveTo(float $x, float $y, float $z, float $speedMultiplier, float $acceptDistance = 0.0) : bool{
		$this->destination = [(int) floor($x), (int) floor($y), (int) floor($z)];
		$this->climbSpeedMultiplier = $speedMultiplier;
		parent::moveTo($x, $y, $z, $speedMultiplier, $acceptDistance);
		return true; //without a path it climbs straight there
	}

	public function moveToEntity(Entity $entity, float $speedMultiplier, float $acceptDistance = 1.0) : bool{
		$pos = Mob::getLiveLocationOf($entity) ?? $entity->getPosition();
		$this->destination = [(int) floor($pos->x), (int) floor($pos->y), (int) floor($pos->z)];
		$this->climbSpeedMultiplier = $speedMultiplier;
		parent::moveToEntity($entity, $speedMultiplier, $acceptDistance);
		return true;
	}

	public function stop() : void{
		$this->destination = null;
		parent::stop();
	}

	/**
	 * A waypoint below the climber counts as reached too: a spider that climbed the wall next to it, or that stands on
	 * top of that wall right above it (its wide box resting on the edge), can't get any closer to it, and steering at a
	 * point straight below only turned it around on the spot. Walking mobs keep the vanilla rule: at the top of a drop
	 * they would take the waypoint at the bottom as reached before dropping down.
	 */
	protected function isWaypointReachedAtHeight(float $dy) : bool{
		return $dy < 1.0;
	}

	/**
	 * Returns the block the mob climbs straight to once its path is done, if any.
	 *
	 * @return int[]|null
	 * @phpstan-return array{int, int, int}|null
	 */
	public function getClimbDestination() : ?array{
		return $this->destination;
	}

	public function tick() : void{
		if(!$this->isDone() || $this->destination === null){
			$destination = $this->destination;
			parent::tick();
			//stuck on its path (the stuck check stopped the navigation): climb straight on instead, like vanilla
			$this->destination ??= $destination;
			return;
		}
		[$x, $y, $z] = $this->destination;
		$loc = $this->climber->getLiveLocation();
		$width = $this->climber->getSize()->getWidth();
		$dx = $x + 0.5 - $loc->x;
		$dz = $z + 0.5 - $loc->z;
		$horizontalSq = $dx * $dx + $dz * $dz;
		$dy = $y + 0.5 - $loc->y;
		//arrived: close to the centre of the block, or above it (on top of the wall it climbed) and close horizontally
		if($horizontalSq + $dy * $dy < $width * $width || ($loc->y > $y && $horizontalSq + 0.25 < $width * $width)){
			$this->destination = null;
			return;
		}
		$this->climber->getMoveControl()->setWantedPosition($x + 0.5, $y, $z + 0.5, $this->climbSpeedMultiplier);
	}
}
