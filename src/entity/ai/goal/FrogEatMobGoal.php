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

use pocketmine\entity\animal\Frog;
use pocketmine\entity\Living;

/**
 * A frog eats its target (vanilla behavior.eat_mob of frog.json): it hops to it at 2x, and once within 1.75 blocks it
 * shoots its tongue (EAT_MOB flag, tongue sound), pulls the mob in and swallows it 0.3 seconds later (see
 * {@link Frog::eatMob()}).
 */
class FrogEatMobGoal extends Goal{
	public const RUN_SPEED = 2.0;
	public const REACH = 1.75;
	public const EAT_TICKS = 6;
	public const PULL_FORCE = 0.75;

	private ?Living $target = null;
	private int $eatTicks = 0;
	private int $repathTick = 0;

	public function __construct(
		protected Frog $frog
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public function canStart() : bool{
		$target = $this->frog->getAttackTarget();
		if($target === null || !Frog::isFood($target) || !$target->isAlive() || $target->isClosed()){
			return false;
		}
		$this->target = $target;
		return true;
	}

	public function canContinue() : bool{
		$target = $this->target;
		return $target !== null && ($this->eatTicks > 0 || ($target->isAlive() && !$target->isClosed() && $target->getWorld() === $this->frog->getWorld() && $this->frog->getAttackTarget() === $target));
	}

	public function start() : void{
		$this->eatTicks = 0;
		$this->repathTick = 0;
	}

	public function stop() : void{
		$this->target = null;
		$this->eatTicks = 0;
		$this->frog->setEatingMob(false);
		$this->frog->getNavigation()->stop();
	}

	public function tick() : void{
		$target = $this->target;
		if($target === null){
			return;
		}
		$frog = $this->frog;
		if($this->eatTicks > 0){
			if(--$this->eatTicks <= 0){
				$frog->eatMob($target);
				$this->target = null;
			}
			return;
		}
		$frog->getLookControl()->setLookAtEntity($target, 30.0, 30.0);
		$loc = $frog->getLiveLocation();
		$tLoc = $target->getLocation();
		if($tLoc->distanceSquared($loc) <= self::REACH * self::REACH){
			$frog->getNavigation()->stop();
			$this->eatTicks = self::EAT_TICKS;
			$frog->setEatingMob(true);
			//pull_in_force: the tongue drags the mob towards the frog
			$pull = $loc->subtractVector($tLoc);
			if($pull->lengthSquared() > 0.0001){
				$target->setMotion($pull->normalize()->multiply(self::PULL_FORCE * 0.5));
			}
			return;
		}
		$now = $frog->getAiTick();
		if($now >= $this->repathTick){
			$this->repathTick = $now + 10;
			$frog->getNavigation()->moveToEntity($target, self::RUN_SPEED, 0.5);
		}
	}
}
