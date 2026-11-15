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
use pocketmine\entity\monster\Evoker;
use function mt_rand;

/**
 * The evoker's spells (vanilla behavior.summon_entity of evocation_illager.json):
 * - fangs: 2 seconds of casting, fangs after 1 second (a double circle around the evoker when the target is within
 *   3 blocks, otherwise a line of 16 towards the target); 5 seconds cooldown;
 * - vexes: 5 seconds of casting, then 3 vexes (at most 8 within 16 blocks); 17 seconds cooldown.
 */
class EvokerCastGoal extends Goal{
	public const SPELL_FANGS = 0;
	public const SPELL_VEX = 1;

	public const FANGS_CAST_TICKS = 40;
	public const FANGS_DELAY_TICKS = 20;
	public const FANGS_COOLDOWN_TICKS = 100;
	public const VEX_CAST_TICKS = 100;
	public const VEX_DELAY_TICKS = 100;
	public const VEX_COOLDOWN_TICKS = 340;

	private int $spell = self::SPELL_FANGS;
	private int $castTicks = 0;
	private bool $done = false;
	private int $fangsReadyTick = 0;
	private int $vexReadyTick = 0;

	public function __construct(
		protected Evoker $evoker
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public function canStart() : bool{
		$target = $this->evoker->getAttackTarget();
		if($target === null || !$this->evoker->getSensing()->isValidTarget($target, 24.0) || !$this->evoker->getSensing()->canSee($target)){
			return false;
		}
		$now = $this->evoker->getAiTick();
		return $now >= $this->fangsReadyTick || ($now >= $this->vexReadyTick && $this->evoker->canSummonVexes());
	}

	public function canContinue() : bool{
		return $this->castTicks > 0;
	}

	public function isInterruptible() : bool{
		return false;
	}

	public function start() : void{
		$now = $this->evoker->getAiTick();
		$vexReady = $now >= $this->vexReadyTick && $this->evoker->canSummonVexes();
		$fangsReady = $now >= $this->fangsReadyTick;
		//summon_choices weights: fangs 3 + 3, vexes 1
		$this->spell = $vexReady && (!$fangsReady || mt_rand(0, 6) === 0) ? self::SPELL_VEX : self::SPELL_FANGS;
		if($this->spell === self::SPELL_VEX){
			$this->castTicks = self::VEX_CAST_TICKS;
			$this->vexReadyTick = $now + self::VEX_CAST_TICKS + self::VEX_COOLDOWN_TICKS;
		}else{
			$this->castTicks = self::FANGS_CAST_TICKS;
			$this->fangsReadyTick = $now + self::FANGS_CAST_TICKS + self::FANGS_COOLDOWN_TICKS;
		}
		$this->done = false;
		$this->evoker->getNavigation()->stop();
		$this->evoker->startCasting($this->spell);
	}

	public function stop() : void{
		$this->castTicks = 0;
		$this->evoker->stopCasting();
	}

	public function getSpell() : int{
		return $this->spell;
	}

	public function tick() : void{
		$target = $this->evoker->getAttackTarget();
		if($target !== null){
			$this->evoker->getLookControl()->setLookAtEntity($target, 30.0, 30.0);
		}
		--$this->castTicks;
		$elapsed = ($this->spell === self::SPELL_VEX ? self::VEX_CAST_TICKS : self::FANGS_CAST_TICKS) - $this->castTicks;
		$delay = $this->spell === self::SPELL_VEX ? self::VEX_DELAY_TICKS : self::FANGS_DELAY_TICKS;
		if(!$this->done && $elapsed >= $delay){
			$this->done = true;
			if($this->spell === self::SPELL_VEX){
				$this->evoker->summonVexes();
			}elseif($target !== null){
				$this->evoker->summonFangs($target);
			}
		}
	}
}
