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

namespace pocketmine\entity\ai\control;

use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\Mob;
use function floor;
use function sqrt;
use const INF;

/**
 * Move control of swimming mobs (fish, dolphins, axolotls, turtles...): in water the mob moves straight towards the
 * wanted position in three dimensions at its underwater speed (Bedrock "underwater_movement", blocks per tick at
 * multiplier 1) without sinking; out of water it walks like any other mob (amphibious mobs), or stays where it is (fish
 * flop, handled by the mob). Non-amphibious swimmers don't swim ashore after a target either: they rise to the surface
 * (dolphins breathe there) but stop at the edge of the water.
 */
class SwimmingMoveControl extends MoveControl{
	private float $velocityY = 0.0;
	private bool $swimming = false;
	/** Whether the wanted position was in water when it was last checked (see keepInWater()). */
	private bool $wantedInWater = true;
	private float $checkedX = INF;
	private float $checkedY = 0.0;
	private float $checkedZ = 0.0;

	/**
	 * @param bool $canWalk     whether it walks out of water (amphibious mobs, a stranded dolphin); false: it stays where
	 *                          it is unless a rider drives it (Bedrock navigation can_walk false: fish, nautiluses)
	 * @param bool $leavesWater whether it swims out of the water towards a wanted position on land; false: it stops at
	 *                          the edge of the water (non-amphibious swimmers: they never go ashore after a target; a
	 *                          rider still steers it out)
	 */
	public function __construct(
		Mob $mob,
		private float $swimSpeed,
		private bool $canWalk = true,
		private bool $leavesWater = true
	){
		parent::__construct($mob);
	}

	public function getSwimSpeed() : float{ return $this->swimSpeed; }

	public function setSwimSpeed(float $swimSpeed) : void{ $this->swimSpeed = $swimSpeed; }

	public function canWalk() : bool{ return $this->canWalk; }

	public function leavesWater() : bool{ return $this->leavesWater; }

	public function isThreeDimensional() : bool{
		return $this->swimming;
	}

	public function getVelocityY() : float{
		return $this->velocityY;
	}

	public function stop() : void{
		parent::stop();
		$this->velocityY = 0.0;
	}

	public function tick() : void{
		$this->swimming = $this->mob->isInWater();
		if($this->swimming){
			$this->tickThreeDimensional($this->swimSpeed * $this->speedMultiplier, $this->velocityY);
			if(!$this->leavesWater && $this->active){
				$this->keepInWater();
			}
		}elseif($this->canWalk || $this->state === self::STATE_DRIVE){
			$this->velocityY = 0.0;
			parent::tick();
		}else{
			//out of water it doesn't walk towards anything (the goals keep their target for when it's back in water)
			$this->velocityY = 0.0;
			$this->velocityX = $this->velocityZ = 0.0;
			$this->walkSpeed = 0.0;
			$this->active = false;
			$this->arrived = false;
			$this->jump = false;
		}
	}

	/**
	 * Going towards a position out of the water (a target on the shore, above the surface): stops at the edge of the
	 * water instead of swimming out (it may still rise to the surface). Only checked while the wanted position is out of
	 * the water (looked up once per wanted position): wandering and paths stay in the water anyway.
	 */
	private function keepInWater() : void{
		$sameWanted = $this->wantedX === $this->checkedX && $this->wantedY === $this->checkedY && $this->wantedZ === $this->checkedZ;
		if($sameWanted && $this->wantedInWater){
			//the wanted position already found in water (nearly every tick): nothing to do, ridden or not
			return;
		}
		if($this->mob->getControllingPassenger() !== null){
			return; //a rider steers it
		}
		$world = $this->mob->getWorld();
		if(!$sameWanted){
			$this->checkedX = $this->wantedX;
			$this->checkedY = $this->wantedY;
			$this->checkedZ = $this->wantedZ;
			$this->wantedInWater = (PathBlockTypes::at($world, (int) floor($this->wantedX), (int) floor($this->wantedY + 0.01), (int) floor($this->wantedZ)) & PathBlockTypes::WATER) !== 0;
		}
		if($this->wantedInWater){
			return;
		}
		$loc = $this->mob->getLiveLocation();
		$size = $this->mob->getSize();
		$horizontal = sqrt($this->velocityX * $this->velocityX + $this->velocityZ * $this->velocityZ);
		if($horizontal > 0.0){
			$ahead = $size->getWidth() / 2 + 0.3;
			$x = (int) floor($loc->x + $this->velocityX / $horizontal * $ahead);
			$z = (int) floor($loc->z + $this->velocityZ / $horizontal * $ahead);
			if((PathBlockTypes::at($world, $x, (int) floor($loc->y + $size->getHeight() / 2), $z) & PathBlockTypes::WATER) === 0){
				$this->velocityX = $this->velocityZ = 0.0;
			}
		}
	}
}
