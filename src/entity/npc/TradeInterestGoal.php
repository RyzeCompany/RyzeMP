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

namespace pocketmine\entity\npc;

use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\Mob;
use pocketmine\item\Item;
use pocketmine\player\Player;
use function count;
use function intdiv;

/**
 * Bedrock behavior.trade_interest (villager_v2 and wandering_trader: within_radius 6, interest_time 45,
 * carried_item_switch_time 2, remove_item_time 1, cooldown 2): when a player within 6 blocks holds an item the trader
 * wants for one of its trades, the trader looks at the player and shows what it gives for it in its hand, switching
 * to the next matching trade every 2 seconds. It loses interest after 45 seconds, when the player walks away or puts
 * the item away, and then waits 2 seconds before it can be interested again.
 *
 * Idle traders look for players every {@link TradeInterestGoal::SCAN_INTERVAL} ticks only (a distance check on the
 * world's players); the held item is only compared with the trades for players in range.
 *
 * @internal
 */
final class TradeInterestGoal extends Goal{
	public const RANGE = 6.0;
	public const INTEREST_TICKS = 45 * 20;
	public const SWITCH_TICKS = 2 * 20;
	public const COOLDOWN_TICKS = 2 * 20;
	/** Ticks between two searches for an interesting player while idle. */
	public const SCAN_INTERVAL = 10;
	/** Ticks between two checks of what the player holds while interested. */
	private const RECHECK_INTERVAL = 10;

	private ?Player $player = null;
	/**
	 * Items shown for the player's held item, one per matching trade.
	 * @var Item[]
	 * @phpstan-var list<Item>
	 */
	private array $shown = [];
	private int $shownIndex = 0;
	private int $ticksRunning = 0;
	private int $nextScan = 0;
	private int $nextSwitch = 0;
	private int $nextCheck = 0;
	/** Goal evaluations so far (canStart() calls, every Mob::GOAL_EVALUATION_INTERVAL ticks): the idle timer. */
	private int $evaluations = 0;

	/**
	 * @phpstan-param \Closure(Player) : bool $canBeInterested whether the trader may show trades to the player now
	 * @phpstan-param \Closure(?Item) : void  $showItem        shows the item in the trader's hand (null = empty hand)
	 */
	public function __construct(
		private Mob&Trader $trader,
		private \Closure $canBeInterested,
		private \Closure $showItem
	){}

	public function getFlags() : int{
		return self::FLAG_LOOK;
	}

	/**
	 * The items the trader gives for the held item: the result of each trade (not used up) wanting it as first or
	 * second price, in trade order.
	 *
	 * @param TradeOffer[] $offers
	 * @phpstan-param list<TradeOffer> $offers
	 *
	 * @return Item[]
	 * @phpstan-return list<Item>
	 */
	public static function getShownItems(array $offers, Item $held) : array{
		$result = [];
		if($held->isNull()){
			return $result;
		}
		foreach($offers as $offer){
			if($offer->isDisabled()){
				continue;
			}
			$buyB = $offer->getBuyB();
			if($offer->getBuyA()->equals($held, true, false) || ($buyB !== null && $buyB->equals($held, true, false))){
				$result[] = $offer->getSell();
			}
		}
		return $result;
	}

	public function canStart() : bool{
		++$this->evaluations;
		if($this->evaluations < $this->nextScan){
			return false;
		}
		$this->nextScan = $this->evaluations + intdiv(self::SCAN_INTERVAL, Mob::GOAL_EVALUATION_INTERVAL);
		$shown = [];
		$player = $this->trader->getSensing()->getNearestPlayer(self::RANGE, function(Player $player) use (&$shown) : bool{
			if(!($this->canBeInterested)($player)){
				return false;
			}
			$items = self::getShownItems($this->trader->getTradeOffers(), $player->getInventory()->getItemInHand());
			if(count($items) === 0){
				return false;
			}
			$shown = $items;
			return true;
		});
		if($player === null){
			return false;
		}
		$this->player = $player;
		$this->shown = $shown;
		return true;
	}

	public function canContinue() : bool{
		$player = $this->player;
		if(
			$player === null || $this->ticksRunning >= self::INTEREST_TICKS || !$player->isAlive() || $player->isClosed() ||
			$player->getWorld() !== $this->trader->getWorld() ||
			$player->getLocation()->distanceSquared($this->trader->getLiveLocation()) > self::RANGE * self::RANGE
		){
			return false;
		}
		if($this->ticksRunning >= $this->nextCheck){
			$this->nextCheck = $this->ticksRunning + self::RECHECK_INTERVAL;
			if(!($this->canBeInterested)($player)){
				return false;
			}
			$shown = self::getShownItems($this->trader->getTradeOffers(), $player->getInventory()->getItemInHand());
			if(count($shown) === 0){
				return false;
			}
			if(count($shown) !== count($this->shown)){
				$this->shownIndex = 0;
				$this->shown = $shown;
				($this->showItem)($shown[0]);
				$this->nextSwitch = $this->ticksRunning + self::SWITCH_TICKS;
			}else{
				$this->shown = $shown;
			}
		}
		return true;
	}

	public function start() : void{
		$this->ticksRunning = 0;
		$this->shownIndex = 0;
		$this->nextSwitch = self::SWITCH_TICKS;
		$this->nextCheck = self::RECHECK_INTERVAL;
		if(count($this->shown) > 0){
			($this->showItem)($this->shown[0]);
		}
	}

	public function stop() : void{
		$this->player = null;
		$this->shown = [];
		//remove_item_time is 1 second: the hand is emptied right away, so that no item is left behind when another goal
		//(trading, sleeping...) takes over
		($this->showItem)(null);
		$this->nextScan = $this->evaluations + intdiv(self::COOLDOWN_TICKS, Mob::GOAL_EVALUATION_INTERVAL);
	}

	public function tick() : void{
		++$this->ticksRunning;
		$player = $this->player;
		if($player === null){
			return;
		}
		$this->trader->getLookControl()->setLookAtEntity($player);
		if($this->ticksRunning >= $this->nextSwitch && count($this->shown) > 1){
			$this->nextSwitch = $this->ticksRunning + self::SWITCH_TICKS;
			$this->shownIndex = ($this->shownIndex + 1) % count($this->shown);
			($this->showItem)($this->shown[$this->shownIndex]);
		}
	}

	/**
	 * Player the trader is interested in, or null.
	 */
	public function getPlayer() : ?Player{
		return $this->player;
	}
}
