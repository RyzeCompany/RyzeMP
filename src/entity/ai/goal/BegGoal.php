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

use pocketmine\entity\animal\Wolf;
use pocketmine\player\Player;
use function mt_rand;

/**
 * A wolf looks at the nearest player within 8 blocks holding a bone or meat (main hand or off hand) with its head
 * tilted (vanilla behavior.beg: look_distance 8, look_time 20-40 ticks; the INTERESTED flag, see
 * {@link Wolf::setInterested()}), as long as the player keeps holding it.
 *
 * Looking for a player is one pass over the world's players on goal evaluations (see
 * {@link \pocketmine\entity\ai\sensing\Sensing::getNearestPlayer()}), most of them skipped by their distance.
 */
class BegGoal extends Goal{
	public const LOOK_DISTANCE = 8.0;
	public const MIN_LOOK_TICKS = 20;
	public const MAX_LOOK_TICKS = 40;

	private ?Player $player = null;
	private int $ticksLeft = 0;
	/** @phpstan-var \Closure(Player) : bool */
	private \Closure $holdsBegItem;

	public function __construct(
		protected Wolf $wolf,
		protected float $range = self::LOOK_DISTANCE
	){
		$this->holdsBegItem = fn(Player $player) : bool => $this->isHoldingBegItem($player);
	}

	public function getFlags() : int{
		return self::FLAG_LOOK;
	}

	private function isHoldingBegItem(Player $player) : bool{
		return Wolf::isBegItem($player->getInventory()->getItemInHand()) || Wolf::isBegItem($player->getOffHandInventory()->getItem(0));
	}

	public function canStart() : bool{
		$this->player = $this->wolf->getSensing()->getNearestPlayer($this->range, $this->holdsBegItem);
		return $this->player !== null;
	}

	public function canContinue() : bool{
		$player = $this->player;
		return $this->ticksLeft > 0 && $player !== null && $player->isAlive() && !$player->isClosed() &&
			$player->getWorld() === $this->wolf->getWorld() &&
			$player->getLocation()->distanceSquared($this->wolf->getLiveLocation()) <= $this->range * $this->range &&
			//the held items again on goal evaluations only (copies of the items)
			(!$this->wolf->getSensing()->isEvaluationTick() || $this->isHoldingBegItem($player));
	}

	public function start() : void{
		$this->wolf->setInterested(true);
		$this->ticksLeft = mt_rand(self::MIN_LOOK_TICKS, self::MAX_LOOK_TICKS);
	}

	public function stop() : void{
		$this->wolf->setInterested(false);
		$this->player = null;
	}

	public function tick() : void{
		--$this->ticksLeft;
		if($this->player !== null){
			$this->wolf->getLookControl()->setLookAtEntity($this->player);
		}
	}
}
