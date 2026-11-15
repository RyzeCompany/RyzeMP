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
use pocketmine\event\entity\EntityDamageEvent;
use function in_array;

/**
 * Runs away at a higher speed after being hurt or while burning (vanilla behavior.panic). Any damage counts by default
 * (Bedrock damage_sources "all": falls, cactus, berry bushes, explosions, suffocation, hits...); $damageCauses limits it
 * to some causes and $ignoreMobDamage leaves out damage dealt by mobs and players (Bedrock ignore_mob_damage).
 */
class PanicGoal extends Goal{
	private const PANIC_TICKS = 100;

	/**
	 * Fire-like damage causes (Bedrock campfire, fire, fire_tick, freezing, lightning, lava, magma, soul_campfire and
	 * temperature: the panic of adult wolves, polar bears and aggressive pandas).
	 */
	public const FIRE_CAUSES = [
		EntityDamageEvent::CAUSE_FIRE,
		EntityDamageEvent::CAUSE_FIRE_TICK,
		EntityDamageEvent::CAUSE_LAVA,
		EntityDamageEvent::CAUSE_FREEZE,
		EntityDamageEvent::CAUSE_LIGHTNING,
		EntityDamageEvent::CAUSE_MAGMA,
		EntityDamageEvent::CAUSE_CAMPFIRE
	];

	/**
	 * @param int[]|null $damageCauses    causes ({@link EntityDamageEvent} CAUSE_* constants) of the damage that makes the
	 *                                    mob panic, null for all
	 * @param bool       $ignoreMobDamage whether damage dealt by mobs and players (hits, projectiles) doesn't count
	 *
	 * @phpstan-param (\Closure() : bool)|null $onlyWhen extra condition (e.g. only babies panic when hit); burning
	 * always makes the mob panic
	 * @phpstan-param list<int>|null $damageCauses
	 */
	public function __construct(
		protected Mob $mob,
		protected float $speedMultiplier,
		protected ?\Closure $onlyWhen = null,
		protected ?array $damageCauses = null,
		protected bool $ignoreMobDamage = false
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	protected function shouldPanic() : bool{
		if($this->mob->isOnFire()){
			return true;
		}
		$now = $this->mob->getAiTick();
		if($now - $this->mob->getLastHurtTick() >= self::PANIC_TICKS || ($this->onlyWhen !== null && !($this->onlyWhen)())){
			return false;
		}
		return (!$this->ignoreMobDamage || !$this->mob->wasLastHurtByMob()) &&
			($this->damageCauses === null || in_array($this->mob->getLastHurtCause(), $this->damageCauses, true));
	}

	public function canStart() : bool{
		return $this->shouldPanic();
	}

	public function canContinue() : bool{
		return !$this->mob->getNavigation()->isDone() || $this->shouldPanic();
	}

	public function start() : void{
		$this->pickTarget();
	}

	private function pickTarget() : void{
		//away from the attacker when hurt by one (not from an old attacker after a fall)
		$attacker = $this->mob->wasLastHurtByMob() ? $this->mob->getLastAttacker() : null;
		$pos = $this->mob->findWanderPos(5, 4, $attacker?->getPosition());
		if($pos !== null){
			$this->mob->getNavigation()->moveTo($pos->x, $pos->y, $pos->z, $this->speedMultiplier);
		}
	}

	public function tick() : void{
		if($this->mob->getNavigation()->isDone() && $this->shouldPanic()){
			$this->pickTarget();
		}
	}

	public function stop() : void{
		$this->mob->getNavigation()->stop();
	}
}
