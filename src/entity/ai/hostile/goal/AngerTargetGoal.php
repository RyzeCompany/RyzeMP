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
use pocketmine\entity\Living;
use pocketmine\entity\Mob;

/**
 * Attacks whoever the mob is angry at (vanilla minecraft:angry: zombified piglins, piglins, endermen, silverfish).
 * The anger itself (who, for how long, broadcasting it to the group) is kept by the mob and read through $angerTarget.
 */
class AngerTargetGoal extends Goal{
	private ?Living $target = null;

	/**
	 * @phpstan-param \Closure() : ?Living $angerTarget
	 */
	public function __construct(
		protected Mob $mob,
		protected \Closure $angerTarget,
		protected float $range = 48.0
	){}

	public function getFlags() : int{
		return self::FLAG_TARGET;
	}

	public function canStart() : bool{
		$target = ($this->angerTarget)();
		if($target === null || !$this->mob->getSensing()->isValidTarget($target, $this->range)){
			return false;
		}
		$this->target = $target;
		return true;
	}

	public function canContinue() : bool{
		$target = $this->mob->getAttackTarget();
		return $target !== null && $target === $this->target && ($this->angerTarget)() === $target && $this->mob->getSensing()->isValidTarget($target, $this->range);
	}

	public function start() : void{
		$this->mob->setAttackTarget($this->target);
	}

	public function stop() : void{
		if($this->mob->getAttackTarget() === $this->target){
			$this->mob->setAttackTarget(null);
		}
		$this->target = null;
	}
}
