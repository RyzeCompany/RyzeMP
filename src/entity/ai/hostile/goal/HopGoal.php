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
use pocketmine\entity\ai\hostile\HopControl;
use pocketmine\entity\Mob;
use pocketmine\math\Vector3;
use function atan2;
use function mt_rand;
use const M_PI;

/**
 * AI of hopping mobs (vanilla slime_attack, slime_random_direction, slime_keep_on_jumping): hop towards the attack
 * target (or a luring point, e.g. a player holding the food of a sulfur cube), otherwise hop in a random direction
 * changed every 2-5 seconds.
 */
class HopGoal extends Goal{
	private int $changeDirectionTicks = 0;

	/**
	 * @param bool $aggressiveLure whether hopping towards the luring point uses the short jump delay of a chase
	 *                             (vanilla sulfur cubes: on_tempt_start / on_pickup_item_start -> with_target)
	 *
	 * @phpstan-param (\Closure() : ?Vector3)|null $lure
	 */
	public function __construct(
		protected Mob $mob,
		protected HopControl $control,
		protected ?\Closure $lure = null,
		protected bool $aggressiveLure = false
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_JUMP;
	}

	public function canStart() : bool{
		return true;
	}

	public function canContinue() : bool{
		return true;
	}

	public function tick() : void{
		$loc = $this->mob->getLiveLocation();
		$target = $this->mob->getAttackTarget();
		$point = null;
		$aggressive = false;
		if($target !== null && $this->mob->getSensing()->isValidTarget($target, 32.0)){
			$point = Mob::getLiveLocationOf($target) ?? $target->getLocation();
			$aggressive = true;
			$this->mob->getLookControl()->setLookAtEntity($target, 10.0, 10.0);
		}elseif($this->lure !== null){
			$point = ($this->lure)();
			$aggressive = $point !== null && $this->aggressiveLure;
		}
		if($point !== null){
			$this->control->setDirection(atan2($point->z - $loc->z, $point->x - $loc->x) / M_PI * 180 - 90, $aggressive);
		}elseif(--$this->changeDirectionTicks <= 0){
			$this->changeDirectionTicks = 40 + mt_rand(0, 59);
			$this->control->setDirection(mt_rand(0, 359), false);
		}
		$this->control->setWantsToMove(true);
	}

	public function stop() : void{
		$this->control->setWantsToMove(false);
	}
}
