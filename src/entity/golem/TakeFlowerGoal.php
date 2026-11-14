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

namespace pocketmine\entity\golem;

use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\Mob;
use pocketmine\world\World;
use function abs;
use function count;
use function mt_rand;

/**
 * Walks up to an iron golem holding out a poppy (see {@link IronGolemOfferFlowerGoal}) and takes it (vanilla
 * behavior.take_flower: baby villagers, priority 9, and copper golems without a flower, priority 4; daytime only).
 * Defaults of the behaviour: golems within 6 blocks horizontally and 2 vertically, walks at 0.5 until 2 blocks away,
 * then waits a random time (at most 20 seconds) and takes the flower: the golem withdraws it.
 *
 * Golems register here while they offer a flower, so that the takers don't look for golems at all while none does
 * (one count() per goal evaluation).
 */
class TakeFlowerGoal extends Goal{
	public const SPEED = 0.5;
	private const SEARCH_HORIZONTAL = 6.0;
	private const SEARCH_VERTICAL = 2.0;
	private const MIN_DISTANCE = 2.0;
	/** Longest wait next to the golem (vanilla max_wait_time 20 s). */
	public const MAX_WAIT_TICKS = 400;
	private const REPATH_INTERVAL = 10;

	/**
	 * Golems offering a flower, by entity ID.
	 * @var IronGolem[]
	 * @phpstan-var array<int, IronGolem>
	 */
	private static array $offering = [];

	private ?IronGolem $golem = null;
	private int $waitTicks = 0;
	private int $ticks = 0;

	/**
	 * @phpstan-param (\Closure() : bool)|null $condition extra condition for the mob to take a flower
	 * @phpstan-param (\Closure() : void)|null $onTake    called when the mob took the flower
	 */
	public function __construct(
		private Mob $mob,
		private ?\Closure $condition = null,
		private ?\Closure $onTake = null
	){}

	/**
	 * @internal Called by {@link IronGolem::setOfferingFlower()}.
	 */
	public static function setOffering(IronGolem $golem, bool $offering) : void{
		if($offering){
			self::$offering[$golem->getId()] = $golem;
		}else{
			unset(self::$offering[$golem->getId()]);
		}
	}

	/**
	 * @internal
	 * @return IronGolem[]
	 * @phpstan-return array<int, IronGolem>
	 */
	public static function getOfferingGolems() : array{
		return self::$offering;
	}

	/**
	 * Whether it is day for take_flower and offer_flower (vanilla is_daytime).
	 */
	public static function isDaytime(World $world) : bool{
		$time = $world->getTimeOfDay();
		return $time < World::TIME_NIGHT || $time >= World::TIME_SUNRISE;
	}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public function canStart() : bool{
		if(count(self::$offering) === 0 || $this->mob->getAttackTarget() !== null){
			return false;
		}
		$world = $this->mob->getWorld();
		if(!self::isDaytime($world) || ($this->condition !== null && !($this->condition)())){
			return false;
		}
		$this->golem = $this->findGolem($world);
		return $this->golem !== null;
	}

	private function findGolem(World $world) : ?IronGolem{
		$loc = $this->mob->getLiveLocation();
		$best = null;
		$bestDistance = 0.0;
		foreach(self::$offering as $golem){
			if($golem->isClosed() || !$golem->isOfferingFlower() || $golem->getWorld() !== $world){
				continue;
			}
			$pos = $golem->getPosition();
			if(abs($pos->x - $loc->x) > self::SEARCH_HORIZONTAL || abs($pos->z - $loc->z) > self::SEARCH_HORIZONTAL || abs($pos->y - $loc->y) > self::SEARCH_VERTICAL){
				continue;
			}
			$distance = $pos->distanceSquared($loc);
			if($best === null || $distance < $bestDistance){
				$best = $golem;
				$bestDistance = $distance;
			}
		}
		return $best;
	}

	public function canContinue() : bool{
		$golem = $this->golem;
		return
			$golem !== null && !$golem->isClosed() && $golem->isAlive() && $golem->isOfferingFlower() &&
			$this->ticks <= self::MAX_WAIT_TICKS * 2 && $this->mob->getAttackTarget() === null &&
			self::isDaytime($this->mob->getWorld());
	}

	public function start() : void{
		$this->ticks = 0;
		$this->waitTicks = mt_rand(0, self::MAX_WAIT_TICKS);
	}

	public function stop() : void{
		$this->golem = null;
		$this->mob->getNavigation()->stop();
	}

	public function tick() : void{
		$golem = $this->golem;
		if($golem === null){
			return;
		}
		++$this->ticks;
		$pos = $golem->getPosition();
		$this->mob->getLookControl()->setLookAt($pos->x, $pos->y + $golem->getEyeHeight(), $pos->z);
		if($pos->distanceSquared($this->mob->getLiveLocation()) > self::MIN_DISTANCE * self::MIN_DISTANCE){
			if($this->ticks % self::REPATH_INTERVAL === 1){
				$this->mob->getNavigation()->moveToEntity($golem, self::SPEED, self::MIN_DISTANCE);
			}
			return;
		}
		$this->mob->getNavigation()->stop();
		if(--$this->waitTicks <= 0){
			$this->takeFlower($golem);
		}
	}

	/**
	 * Takes the flower from the golem right away.
	 */
	public function takeFlower(IronGolem $golem) : void{
		if(!$golem->isOfferingFlower()){
			return;
		}
		$golem->setOfferingFlower(false);
		if($this->onTake !== null){
			($this->onTake)();
		}
	}
}
