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
use pocketmine\world\dimension\Dimension;
use pocketmine\world\World;
use function ceil;
use function floor;
use function max;
use function mt_rand;

/**
 * A mob burning in the sun runs for the shade (vanilla behavior.flee_sun of skeletons, strays, bogged, parched and
 * drowned; Java FleeSunGoal): by day, when it is on fire under the open sky with nothing on its head, it picks one of 10
 * random spots up to 10 blocks away horizontally and 3 vertically that is out of the sun, and walks there.
 *
 * Only burning mobs look for a spot (a few flag checks otherwise), and a mob which found none waits a second before
 * looking again.
 */
final class FleeSunGoal extends Goal{
	/** Tries, and the farthest spot horizontally and vertically (vanilla Java getHidePos()) */
	private const TRIES = 10;
	private const HORIZONTAL = 10;
	private const VERTICAL = 3;
	/** Ticks a mob which found no shade waits before looking again */
	private const RETRY_TICKS = 20;

	private float $x = 0.0;
	private float $y = 0.0;
	private float $z = 0.0;
	private int $retryAt = 0;

	public function __construct(
		private Mob $mob,
		private float $speedMultiplier = 1.0
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		$mob = $this->mob;
		//a mob with a target keeps fighting while it burns (vanilla canUse())
		if(!$mob->isOnFire() || $mob->getAiTick() < $this->retryAt || $mob->getAttackTarget() !== null){
			return false;
		}
		$world = $mob->getWorld();
		if(!self::isDay($world) || !$mob->getArmorInventory()->getHelmet()->isNull()){
			return false;
		}
		$loc = $mob->getLiveLocation();
		$eye = (int) floor($mob->getEyeHeight());
		$bx = (int) floor($loc->x);
		$by = (int) floor($loc->y + 0.01);
		$bz = (int) floor($loc->z);
		if(!self::seesSky($world, $bx, $by + $eye, $bz)){
			return false;
		}
		$headBlocks = max(0, (int) ceil($mob->getSize()->getHeight()) - 1);
		for($try = 0; $try < self::TRIES; ++$try){
			$x = $bx + mt_rand(-self::HORIZONTAL, self::HORIZONTAL - 1);
			$y = $by + mt_rand(-self::VERTICAL, self::VERTICAL - 1);
			$z = $bz + mt_rand(-self::HORIZONTAL, self::HORIZONTAL - 1);
			if(self::seesSky($world, $x, $y + $eye, $z)){
				continue;
			}
			//settle on the ground below the picked spot
			for($down = 0; $down < self::VERTICAL && !PathBlockTypes::isStandable($world, $x, $y, $z, $headBlocks, false); ++$down){
				--$y;
			}
			if(PathBlockTypes::isStandable($world, $x, $y, $z, $headBlocks, false) && !self::seesSky($world, $x, $y + $eye, $z)){
				$this->x = $x + 0.5;
				$this->y = $y;
				$this->z = $z + 0.5;
				return true;
			}
		}
		$this->retryAt = $mob->getAiTick() + self::RETRY_TICKS;
		return false;
	}

	public function canContinue() : bool{
		return !$this->mob->getNavigation()->isDone();
	}

	public function start() : void{
		$this->mob->getNavigation()->moveTo($this->x, $this->y, $this->z, $this->speedMultiplier);
	}

	public function stop() : void{
		$this->mob->getNavigation()->stop();
	}

	/**
	 * Whether it is day in the world for the undead (vanilla isBrightOutside(): the overworld, sky light reduced by less
	 * than 4).
	 */
	public static function isDay(World $world) : bool{
		return $world->getDimension() === Dimension::OVERWORLD && $world->getSkyLightReduction() < 4;
	}

	/**
	 * Whether the block is under the open sky (full sky light, the light the sun burns the undead in).
	 */
	public static function seesSky(World $world, int $x, int $y, int $z) : bool{
		return $world->getPotentialBlockSkyLightAt($x, $y, $z) >= 15;
	}
}
