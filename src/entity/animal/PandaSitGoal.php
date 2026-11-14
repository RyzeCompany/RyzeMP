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

namespace pocketmine\entity\animal;

use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\Mob;
use function mt_getrandmax;
use function mt_rand;

/**
 * Bedrock behavior.random_sitting of pandas: once the cooldown is over (30 s, lazy pandas 25 s), a panda standing on
 * the ground sits down with start_chance per tick (0.01, lazy 0.02), stays seated at least min_sit_time (10 s, lazy
 * 15 s) and then gets up with stop_chance per tick (0.3, lazy 0.2).
 *
 * @internal
 */
final class PandaSitGoal extends Goal{
	private int $cooldownUntil = 0;
	private int $sitTicks = 0;
	private bool $standUp = false;

	public function __construct(private Panda $panda){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_JUMP;
	}

	private function isLazy() : bool{
		return $this->panda->getPersonality() === Panda::GENE_LAZY;
	}

	public function canStart() : bool{
		$panda = $this->panda;
		if($panda->getAiTick() < $this->cooldownUntil || !$panda->isOnGround() || $panda->isInWater() || !$panda->canPerformAction() || $panda->isRiding()){
			return false;
		}
		//start_chance per tick, asked once per goal evaluation
		return mt_rand() / mt_getrandmax() < ($this->isLazy() ? 0.02 : 0.01) * Mob::GOAL_EVALUATION_INTERVAL;
	}

	public function canContinue() : bool{
		return !$this->standUp && $this->panda->isSitting() && !$this->panda->isInWater();
	}

	public function start() : void{
		$this->sitTicks = 0;
		$this->standUp = false;
		$this->panda->sitDown();
	}

	public function stop() : void{
		$this->panda->setSitting(false);
		$this->cooldownUntil = $this->panda->getAiTick() + ($this->isLazy() ? 500 : 600);
	}

	public function tick() : void{
		$lazy = $this->isLazy();
		if(++$this->sitTicks >= ($lazy ? 300 : 200) && mt_rand() / mt_getrandmax() < ($lazy ? 0.2 : 0.3)){
			$this->standUp = true;
		}
	}
}
