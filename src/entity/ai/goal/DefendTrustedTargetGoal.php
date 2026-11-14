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
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;

/**
 * Attacks whoever hurt an entity the mob trusts (vanilla behavior.defend_trusted_target of foxes bred by players,
 * within_radius 25, must_see false; Java Fox.DefendTrustedTargetGoal). $onDefend runs when the mob takes the attacker
 * as its target (the fox growls and switches to its defending_fox group), $onStop when it gives up the target.
 * $canTarget can rule attackers out (a fox never turns on another player it trusts).
 *
 * The trusted entities are looked up every 10 ticks only (Java: a 1 in 10 chance per tick), and only while the mob has
 * no target of its own. Only hits which happened since the previous look-up count: the damage a trusted entity took
 * before the mob first saw it (a fox just bred or loaded, a player who just came back to the world) is old and ignored
 * (Java: only the last 100 ticks of getLastHurtByMob count).
 */
class DefendTrustedTargetGoal extends Goal{
	/** within_radius of foxes. */
	public const DEFAULT_RANGE = 25.0;
	private const CHECK_INTERVAL = 10;

	/**
	 * Last damage cause of each trusted entity at the last look-up which saw it, and the AI tick of that look-up.
	 * @phpstan-var \WeakMap<Living, array{?EntityDamageEvent, int}>
	 */
	private \WeakMap $seen;
	private ?Living $target = null;
	private int $nextCheck = 0;
	private int $lastCheck = -1;

	/**
	 * @phpstan-param \Closure() : iterable<Living> $trustedEntities the loaded entities the mob trusts
	 * @phpstan-param (\Closure() : void)|null $onDefend
	 * @phpstan-param (\Closure() : void)|null $onStop
	 * @phpstan-param (\Closure(Living) : bool)|null $canTarget
	 */
	public function __construct(
		protected Mob $mob,
		protected \Closure $trustedEntities,
		protected float $range = self::DEFAULT_RANGE,
		protected ?\Closure $onDefend = null,
		protected ?\Closure $onStop = null,
		protected ?\Closure $canTarget = null
	){
		$this->seen = new \WeakMap();
	}

	public function getFlags() : int{
		return self::FLAG_TARGET;
	}

	public function canStart() : bool{
		$mob = $this->mob;
		$now = $mob->getAiTick();
		if($now < $this->nextCheck){
			return false;
		}
		$this->nextCheck = $now + self::CHECK_INTERVAL;
		$previousCheck = $this->lastCheck;
		$this->lastCheck = $now;
		$found = null;
		//every trusted entity is looked at, so each one's damage is known at the next look-up
		foreach(($this->trustedEntities)() as $trusted){
			$event = $trusted->getLastDamageCause();
			$seen = $this->seen[$trusted] ?? null;
			$this->seen[$trusted] = [$event, $now];
			//first look at this entity, or it was away at the previous look-up: what hurt it is old
			if($found !== null || $seen === null || $seen[1] !== $previousCheck || $seen[0] === $event || !$event instanceof EntityDamageByEntityEvent){
				continue;
			}
			//one event is answered once
			$attacker = $event->getDamager();
			if(
				$attacker instanceof Living && $attacker !== $mob && $attacker !== $trusted && $attacker->isAlive() &&
				($this->canTarget === null || ($this->canTarget)($attacker)) &&
				$mob->getSensing()->isValidTarget($attacker, $this->range)
			){
				$found = $attacker;
			}
		}
		if($found === null){
			return false;
		}
		$this->target = $found;
		return true;
	}

	public function canContinue() : bool{
		$target = $this->mob->getAttackTarget();
		return $target !== null && $target === $this->target && $this->mob->getSensing()->isValidTarget($target, $this->range);
	}

	public function start() : void{
		$this->mob->setAttackTarget($this->target);
		if($this->onDefend !== null){
			($this->onDefend)();
		}
	}

	public function stop() : void{
		if($this->mob->getAttackTarget() === $this->target){
			$this->mob->setAttackTarget(null);
		}
		$this->target = null;
		if($this->onStop !== null){
			($this->onStop)();
		}
	}
}
