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

namespace pocketmine\entity\ai\villager;

use pocketmine\entity\ai\goal\MoveTowardsHomeRestrictionGoal;
use pocketmine\entity\npc\VillagerV2;

/**
 * A villager that strayed out of its village walks back (vanilla behavior.move_towards_dwelling_restriction: priority
 * 11, speed 0.6). The dwelling is the villager's bed and the radius around it (see
 * {@link VillagerV2::getHomeRadius()}); villagers without a bed roam freely. After each leg the goal waits a few
 * seconds, so that a villager that can't get back (boxed in a trading hall) doesn't search paths over and over.
 */
class MoveTowardsDwellingGoal extends MoveTowardsHomeRestrictionGoal{
	public const SPEED = 0.6;
	private const COOLDOWN = 100;

	private int $nextTry = 0;

	public function __construct(
		private VillagerV2 $villager
	){
		parent::__construct($villager, self::SPEED);
	}

	public function canStart() : bool{
		if($this->villager->getAiTick() < $this->nextTry || $this->villager->isSleeping()){
			return false;
		}
		return parent::canStart();
	}

	public function stop() : void{
		parent::stop();
		$this->nextTry = $this->villager->getAiTick() + self::COOLDOWN;
	}
}
