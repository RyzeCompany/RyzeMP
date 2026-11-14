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

namespace pocketmine\entity\ai\hostile;

use pocketmine\entity\ai\control\MoveControl;
use pocketmine\entity\Mob;
use pocketmine\math\Vector3;
use function atan2;
use function sqrt;
use const M_PI;

/**
 * Steers a mob that moves freely in three dimensions (flying mobs, mobs swimming in water) straight towards a wanted
 * position. Unlike {@link MoveControl}, it produces the full 3D motion of the mob: the current motion is blended
 * towards the wanted velocity by an acceleration factor every tick, which gives the floaty movement of ghasts, vexes,
 * phantoms and guardians. There is no pathfinding: the goals pick reachable points (vanilla flying and swimming mobs
 * mostly move in straight lines too).
 */
final class FreeMoveControl{
	private bool $active = false;
	private float $wantedX = 0.0;
	private float $wantedY = 0.0;
	private float $wantedZ = 0.0;
	/** Wanted speed in blocks per tick. */
	private float $speed = 0.0;

	public function __construct(
		private Mob $mob,
		/** How fast the motion turns towards the wanted velocity (0-1 per tick). */
		private float $acceleration = 0.1,
		/** Whether the body turns to face where the mob moves. */
		private bool $faceMovement = true
	){}

	/**
	 * @param float $speed blocks per tick
	 */
	public function setWantedPosition(float $x, float $y, float $z, float $speed) : void{
		$this->active = true;
		$this->wantedX = $x;
		$this->wantedY = $y;
		$this->wantedZ = $z;
		$this->speed = $speed;
	}

	public function stop() : void{
		$this->active = false;
	}

	public function hasWanted() : bool{
		return $this->active;
	}

	public function getWantedX() : float{ return $this->wantedX; }

	public function getWantedY() : float{ return $this->wantedY; }

	public function getWantedZ() : float{ return $this->wantedZ; }

	public function setAcceleration(float $acceleration) : void{
		$this->acceleration = $acceleration;
	}

	/**
	 * Returns the squared distance from the mob to the wanted position.
	 */
	public function distanceSquaredToWanted() : float{
		$loc = $this->mob->getLiveLocation();
		return ($this->wantedX - $loc->x) ** 2 + ($this->wantedY - $loc->y) ** 2 + ($this->wantedZ - $loc->z) ** 2;
	}

	/**
	 * Returns the mob's motion for this tick: the current motion steered towards the wanted position, or slowed down
	 * by $idleDrag when there is nowhere to go.
	 */
	public function computeMotion(Vector3 $motion, float $idleDrag = 0.91) : Vector3{
		if(!$this->active){
			return new Vector3($motion->x * $idleDrag, $motion->y * $idleDrag, $motion->z * $idleDrag);
		}
		$loc = $this->mob->getLiveLocation();
		$dx = $this->wantedX - $loc->x;
		$dy = $this->wantedY - $loc->y;
		$dz = $this->wantedZ - $loc->z;
		$dist = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
		if($dist < 0.25){
			$this->active = false;
			return new Vector3($motion->x * $idleDrag, $motion->y * $idleDrag, $motion->z * $idleDrag);
		}
		$speed = $dist < $this->speed ? $dist : $this->speed;
		$a = $this->acceleration;
		$vx = $dx / $dist * $speed;
		$vy = $dy / $dist * $speed;
		$vz = $dz / $dist * $speed;
		if($this->faceMovement && ($dx * $dx + $dz * $dz) > 0.0001){
			$this->mob->setBodyYaw(MoveControl::rotlerp($loc->yaw, atan2($dz, $dx) / M_PI * 180 - 90, 20.0));
		}
		return new Vector3(
			$motion->x + ($vx - $motion->x) * $a,
			$motion->y + ($vy - $motion->y) * $a,
			$motion->z + ($vz - $motion->z) * $a
		);
	}
}
