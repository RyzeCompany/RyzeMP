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

use pocketmine\entity\animal\TameableAnimal;

/**
 * A tamed animal ordered to sit stays where it is (vanilla behavior.stay_while_sitting).
 */
class SitWhenOrderedGoal extends Goal{

	public function __construct(
		protected TameableAnimal $animal
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_JUMP;
	}

	public function canStart() : bool{
		return $this->animal->isSitting() && !$this->animal->isInWater();
	}

	public function canContinue() : bool{
		return $this->animal->isSitting();
	}

	public function start() : void{
		$this->animal->getNavigation()->stop();
	}
}
