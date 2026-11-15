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

use pocketmine\entity\Mob;
use pocketmine\utils\Utils;
use function cos;
use function mt_rand;
use function sin;
use const M_PI;

/**
 * Looks in a random direction for a moment while idle (vanilla behavior.random_look_around).
 */
class RandomLookAroundGoal extends Goal{
	private float $relX = 0.0;
	private float $relZ = 0.0;
	private int $ticksLeft = 0;

	public function __construct(
		protected Mob $mob
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public function canStart() : bool{
		return Utils::getRandomFloat() < 0.02 * Mob::GOAL_EVALUATION_INTERVAL; //vanilla: 2% per tick
	}

	public function canContinue() : bool{
		return $this->ticksLeft > 0;
	}

	public function start() : void{
		$angle = M_PI * 2 * Utils::getRandomFloat();
		$this->relX = cos($angle);
		$this->relZ = sin($angle);
		$this->ticksLeft = 20 + mt_rand(0, 19);
	}

	public function tick() : void{
		--$this->ticksLeft;
		$loc = $this->mob->getLiveLocation();
		$this->mob->getLookControl()->setLookAt($loc->x + $this->relX, $loc->y + $this->mob->getEyeHeight(), $loc->z + $this->relZ);
	}
}
