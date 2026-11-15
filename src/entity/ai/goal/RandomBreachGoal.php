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

use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\Mob;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use function abs;
use function cos;
use function deg2rad;
use function floor;
use function intdiv;
use function max;
use function mt_rand;
use function sin;

/**
 * Leaps out of the water (Bedrock behavior.random_breach, dolphins): about once every $interval ticks, when the mob
 * swims just below the surface with open water and air above it for $xzDistance blocks ahead (Java DolphinJumpGoal),
 * it jumps forward with its jump power (Bedrock jump.static) and dives back in. It can't breach again for $cooldownTicks
 * after that. The surface check only runs when the interval roll succeeds.
 */
class RandomBreachGoal extends Goal{
	/** Forward push of the leap (Java DolphinJumpGoal). */
	public const FORWARD_SPEED = 0.6;
	/** The leap ends at the latest after this many ticks (it normally ends when the mob is back in the water). */
	private const MAX_TICKS = 60;

	private int $cooldownEndTick = 0;
	private int $ticks = 0;
	private bool $leftWater = false;

	/**
	 * @param int   $interval      average ticks between two breaches (Bedrock interval)
	 * @param int   $xzDistance    blocks of open water ahead needed (Bedrock xz_dist)
	 * @param int   $cooldownTicks ticks after a breach before the next one (Bedrock cooldown_time)
	 * @param float $jumpPower     upwards speed of the leap (Bedrock jump.static jump_power)
	 */
	public function __construct(
		protected Mob $mob,
		protected int $interval = 50,
		protected int $xzDistance = 6,
		protected int $cooldownTicks = 40,
		protected float $jumpPower = 0.6
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_JUMP;
	}

	public function isInterruptible() : bool{
		return false;
	}

	/**
	 * Returns whether the mob is leaping right now.
	 */
	public function isBreaching() : bool{
		return $this->ticks > 0;
	}

	/**
	 * Lets the mob breach again right away (tests).
	 * @internal
	 */
	public function resetCooldown() : void{
		$this->cooldownEndTick = 0;
	}

	public function canStart() : bool{
		if($this->mob->getAiTick() < $this->cooldownEndTick || mt_rand(0, max(1, intdiv($this->interval, Mob::GOAL_EVALUATION_INTERVAL)) - 1) !== 0){
			return false;
		}
		return $this->canBreachHere();
	}

	/**
	 * Whether the mob is in water just below the surface, with water at its level and two blocks of air above it for
	 * $xzDistance blocks in the direction it faces.
	 */
	public function canBreachHere() : bool{
		if(!$this->mob->isInWater() || $this->mob->getControllingPassenger() !== null){
			return false;
		}
		[$stepX, $stepZ] = $this->getFacingStep();
		$loc = $this->mob->getLiveLocation();
		$world = $this->mob->getWorld();
		$x = (int) floor($loc->x);
		$y = (int) floor($loc->y);
		$z = (int) floor($loc->z);
		for($i = 0; $i <= $this->xzDistance; ++$i){
			$bx = $x + $stepX * $i;
			$bz = $z + $stepZ * $i;
			$water = PathBlockTypes::at($world, $bx, $y, $bz);
			if(($water & PathBlockTypes::WATER) === 0 || ($water & PathBlockTypes::PASSABLE) === 0 ||
				!self::isOpenAir(PathBlockTypes::at($world, $bx, $y + 1, $bz)) ||
				!self::isOpenAir(PathBlockTypes::at($world, $bx, $y + 2, $bz))
			){
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether the path flags are those of a block the mob can leap through: passable, without water or lava.
	 */
	private static function isOpenAir(int $flags) : bool{
		return ($flags & PathBlockTypes::PASSABLE) !== 0 && ($flags & (PathBlockTypes::WATER | PathBlockTypes::LAVA | PathBlockTypes::FLOOR)) === 0;
	}

	/**
	 * The horizontal direction the mob faces, rounded to one of the four sides (Java getMotionDirection).
	 *
	 * @return int[]
	 * @phpstan-return array{int, int}
	 */
	private function getFacingStep() : array{
		$yaw = deg2rad($this->mob->getLiveLocation()->yaw);
		$dx = -sin($yaw);
		$dz = cos($yaw);
		return abs($dx) > abs($dz) ? [$dx > 0 ? 1 : -1, 0] : [0, $dz > 0 ? 1 : -1];
	}

	public function start() : void{
		[$stepX, $stepZ] = $this->getFacingStep();
		$this->mob->getNavigation()->stop();
		$this->mob->getMoveControl()->stop();
		$this->mob->setMotion($this->mob->getMotion()->addVector(new Vector3($stepX * self::FORWARD_SPEED, $this->jumpPower, $stepZ * self::FORWARD_SPEED)));
		$this->ticks = 1;
		$this->leftWater = false;
		$this->mob->playMobSound(LevelSoundEvent::JUMP);
	}

	public function canContinue() : bool{
		if($this->ticks >= self::MAX_TICKS || ($this->ticks > 2 && $this->mob->isOnGround())){
			return false;
		}
		//over once it is back in the water after leaving it
		return !$this->leftWater || !$this->mob->isInWater();
	}

	public function tick() : void{
		++$this->ticks;
		if(!$this->leftWater && !$this->mob->isInWater()){
			$this->leftWater = true;
		}
	}

	public function stop() : void{
		$this->ticks = 0;
		$this->cooldownEndTick = $this->mob->getAiTick() + $this->cooldownTicks;
	}
}
