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

use pocketmine\entity\Living;
use pocketmine\entity\Mob;

/**
 * Melee attack with a wind-up (vanilla behavior.delayed_attack, the ravager's): when the target is in reach the attack
 * starts, the hit lands $hitDelay ticks later if the target is still in reach, and the attack ends after
 * $attackDuration ticks. A new attack starts at most every $attackInterval ticks.
 *
 * Clients animate the attack from its start to its end (the DELAYED_ATTACKING flag, query.is_delayed_attacking), so
 * the mob shows it through the $onAttackingChange callback.
 */
class DelayedMeleeAttackGoal extends MeleeAttackGoal{
	/** Ticks since the current attack started, -1 when not attacking. */
	private int $attackTicks = -1;
	private ?Living $attacked = null;

	/**
	 * @param float|null $reachMultiplier see {@link MeleeAttackGoal::__construct()} (Bedrock delayed_attack
	 *                                    reach_multiplier)
	 * @phpstan-param (\Closure(bool) : void)|null $onAttackingChange
	 */
	public function __construct(
		Mob $mob,
		float $speedMultiplier,
		float $damage,
		float $followRange,
		private int $attackDuration,
		private int $hitDelay,
		int $attackInterval,
		private ?\Closure $onAttackingChange = null,
		?float $reachMultiplier = null
	){
		parent::__construct($mob, $speedMultiplier, $damage, $followRange, $attackInterval, reachMultiplier: $reachMultiplier);
	}

	public function isAttacking() : bool{
		return $this->attackTicks >= 0;
	}

	protected function performAttack(Living $target) : void{
		$this->attackTicks = 0;
		$this->attacked = $target;
		if($this->onAttackingChange !== null){
			($this->onAttackingChange)(true);
		}
	}

	private function endAttack() : void{
		if($this->attackTicks >= 0){
			$this->attackTicks = -1;
			$this->attacked = null;
			if($this->onAttackingChange !== null){
				($this->onAttackingChange)(false);
			}
		}
	}

	public function stop() : void{
		$this->endAttack();
		parent::stop();
	}

	public function tick() : void{
		if($this->attackTicks >= 0){
			++$this->attackTicks;
			$target = $this->attacked;
			if($this->attackTicks === $this->hitDelay && $target !== null && $target->isAlive() && !$target->isClosed()){
				$mobLoc = $this->mob->getLiveLocation();
				$targetLoc = Mob::getLiveLocationOf($target) ?? $target->getLocation();
				$dx = $targetLoc->x - $mobLoc->x;
				$dz = $targetLoc->z - $mobLoc->z;
				$dy = $targetLoc->y - $mobLoc->y;
				if($this->isInReach($target, $dx, $dz, $dx * $dx + $dy * $dy + $dz * $dz)){
					$this->mob->attackMelee($target, $this->damage);
				}
			}
			if($this->attackTicks >= $this->attackDuration){
				$this->endAttack();
			}
		}
		parent::tick();
	}
}
