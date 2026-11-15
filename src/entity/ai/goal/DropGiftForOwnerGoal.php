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

use pocketmine\entity\animal\Cat;
use pocketmine\player\Player;
use pocketmine\world\World;
use function abs;

/**
 * Morning gift of a cat which slept with its owner (vanilla behavior.drop_item_for of cat.json): between sunrise and
 * one minute later (time_of_day 0.75-0.8, ticks 0-1200), with the owner within 5 blocks, the cat walks up to them and
 * makes one drop attempt: 70% of the time it drops an item of the cat_gift loot table. Either way it is done until it
 * sleeps with its owner again.
 */
class DropGiftForOwnerGoal extends Goal{
	/** Time of day window (ticks): Bedrock time_of_day 0.74999-0.8. */
	public const MIN_TIME = 0;
	public const MAX_TIME = 1200;
	/** target_range */
	public const RANGE = 5.0;
	public const GIVE_UP_TICKS = 200;

	private ?Player $owner = null;
	private int $giveUpTick = 0;
	private int $repathTick = 0;

	public function __construct(
		protected Cat $cat
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public static function isGiftTime(World $world) : bool{
		$time = $world->getTimeOfDay();
		return $time >= self::MIN_TIME && $time <= self::MAX_TIME;
	}

	public function canStart() : bool{
		$cat = $this->cat;
		if(!$cat->hasGiftForOwner() || !$cat->isTamed() || $cat->isSitting() || !self::isGiftTime($cat->getWorld())){
			return false;
		}
		$owner = $cat->getOwner();
		if($owner === null || !$owner->isAlive() || $owner->isSleeping()){
			return false;
		}
		$o = $owner->getLocation();
		$c = $cat->getLiveLocation();
		if(abs($o->x - $c->x) > self::RANGE || abs($o->y - $c->y) > self::RANGE || abs($o->z - $c->z) > self::RANGE){
			return false;
		}
		$this->owner = $owner;
		return true;
	}

	public function canContinue() : bool{
		$owner = $this->owner;
		return $owner !== null && $this->cat->hasGiftForOwner() && $owner->isAlive() && $owner->getWorld() === $this->cat->getWorld() && $this->cat->getAiTick() < $this->giveUpTick;
	}

	public function start() : void{
		$this->giveUpTick = $this->cat->getAiTick() + self::GIVE_UP_TICKS;
		$this->repathTick = 0;
	}

	public function stop() : void{
		$this->owner = null;
		$this->cat->getNavigation()->stop();
	}

	public function tick() : void{
		$owner = $this->owner;
		if($owner === null){
			return;
		}
		$cat = $this->cat;
		$cat->getLookControl()->setLookAtEntity($owner, 10.0, 40.0);
		if($owner->getLocation()->distanceSquared($cat->getLiveLocation()) <= 4.0){
			$cat->getNavigation()->stop();
			$cat->offerGift($owner);
			return;
		}
		$now = $cat->getAiTick();
		if($cat->getNavigation()->isDone() && $now >= $this->repathTick){
			$this->repathTick = $now + 20;
			$cat->getNavigation()->moveToEntity($owner, 1.0, 1.0);
		}
	}
}
