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

use pocketmine\block\utils\DyeColor;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\animal\Sheep;
use pocketmine\entity\monster\Evoker;
use pocketmine\math\AxisAlignedBB;
use pocketmine\world\GameRules;

/**
 * The evoker's "wololo" spell (vanilla behavior.send_event of evocation_illager.json): when it has no target, the
 * evoker picks a blue sheep within 16 blocks, casts for 3 seconds (orange particles, #FFB38033) and after 2 seconds
 * turns the sheep red (prepare.wololo sound). 5 seconds of cooldown after each cast. Not cast when the mobgriefing
 * game rule is off (Java EvokerWololoSpellGoal).
 */
class EvokerWololoGoal extends Goal{
	public const SPELL_WOLOLO = 2;

	public const CAST_TICKS = 60;
	public const EVENT_DELAY_TICKS = 40;
	public const COOLDOWN_TICKS = 100;
	public const RANGE = 16.0;

	private ?Sheep $sheep = null;
	private int $castTicks = 0;
	private bool $done = false;
	private int $readyTick = 0;

	public function __construct(
		protected Evoker $evoker
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public function canStart() : bool{
		if($this->evoker->getAttackTarget() !== null || $this->evoker->isCasting() || $this->evoker->getAiTick() < $this->readyTick || !GameRules::allowsMobGriefing($this->evoker->getWorld())){
			return false;
		}
		$this->sheep = $this->findBlueSheep();
		return $this->sheep !== null;
	}

	public function canContinue() : bool{
		return $this->castTicks > 0 && $this->evoker->getAttackTarget() === null;
	}

	private function findBlueSheep() : ?Sheep{
		if(!$this->evoker->getWorld()->hasEntitiesOfClass(Sheep::class)){
			//asked on every goal evaluation of an idle evoker: no search while the world has no sheep (cached counts)
			return null;
		}
		$loc = $this->evoker->getLiveLocation();
		$r = self::RANGE;
		$best = null;
		$bestDist = $r * $r;
		foreach($this->evoker->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - 4, $loc->z - $r, $loc->x + $r, $loc->y + 4, $loc->z + $r), $this->evoker) as $entity){
			if($entity instanceof Sheep && $entity->isAlive() && $entity->getColor() === DyeColor::BLUE){
				$d = $entity->getLocation()->distanceSquared($loc);
				if($d <= $bestDist){
					$best = $entity;
					$bestDist = $d;
				}
			}
		}
		return $best;
	}

	public function start() : void{
		$this->castTicks = self::CAST_TICKS;
		$this->done = false;
		$this->readyTick = $this->evoker->getAiTick() + self::CAST_TICKS + self::COOLDOWN_TICKS;
		$this->evoker->getNavigation()->stop();
		$this->evoker->startCasting(self::SPELL_WOLOLO);
	}

	public function stop() : void{
		$this->castTicks = 0;
		$this->sheep = null;
		$this->evoker->stopCasting();
	}

	public function tick() : void{
		$sheep = $this->sheep;
		if($sheep !== null && !$sheep->isClosed()){
			$this->evoker->getLookControl()->setLookAtEntity($sheep, 30.0, 30.0);
		}
		--$this->castTicks;
		if(!$this->done && self::CAST_TICKS - $this->castTicks >= self::EVENT_DELAY_TICKS){
			$this->done = true;
			$this->evoker->castWololo($sheep);
		}
	}
}
