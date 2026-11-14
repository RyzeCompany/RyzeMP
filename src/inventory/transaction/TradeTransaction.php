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

namespace pocketmine\inventory\transaction;

use pocketmine\entity\npc\TradeOffer;
use pocketmine\entity\npc\TradePrices;
use pocketmine\entity\npc\Trader;
use pocketmine\inventory\TradeInventory;
use pocketmine\item\Item;
use pocketmine\player\Player;
use function count;
use function mt_rand;

/**
 * Making a trade with a villager or wandering trader: the items consumed from the payment slots must pay for the
 * trade $times times, and the item taken must be what the trade gives. On success the trade's uses go up, the trader
 * gains experience and the player gets 3-6 experience per trade (vanilla).
 */
class TradeTransaction extends InventoryTransaction{

	public function __construct(
		Player $source,
		private readonly Trader $trader,
		private readonly TradeOffer $offer,
		private readonly int $times,
		array $actions = []
	){
		parent::__construct($source, $actions);
	}

	public function getOffer() : TradeOffer{ return $this->offer; }

	public function getTimes() : int{ return $this->times; }

	/**
	 * @param Item[] $items
	 */
	private static function countMatching(array $items, Item $wanted) : int{
		$count = 0;
		foreach($items as $item){
			if($item->canStackWith($wanted)){
				$count += $item->getCount();
			}
		}
		return $count;
	}

	public function validate() : void{
		if(count($this->actions) < 1){
			throw new TransactionValidationException("Transaction must have at least one action to be executable");
		}
		if($this->offer->getUses() + $this->times > $this->offer->getMaxUses()){
			throw new TransactionValidationException("The trade is out of stock");
		}

		$inputs = [];
		$outputs = [];
		$this->matchItems($outputs, $inputs);

		$buyA = $this->offer->getBuyA()->setCount(TradePrices::getBuyACount($this->offer, $this->source, $this->trader));
		$buyB = $this->offer->getBuyB();
		$paidA = self::countMatching($inputs, $buyA);
		$paidB = $buyB !== null ? self::countMatching($inputs, $buyB) : 0;
		if($buyB !== null && $buyB->canStackWith($buyA)){
			//same item twice: both prices are paid from the same pool
			if($paidA < ($buyA->getCount() + $buyB->getCount()) * $this->times){
				throw new TransactionValidationException("Not enough items paid for the trade");
			}
		}elseif($paidA < $buyA->getCount() * $this->times || ($buyB !== null && $paidB < $buyB->getCount() * $this->times)){
			throw new TransactionValidationException("Not enough items paid for the trade");
		}

		$sell = $this->offer->getSell();
		$given = 0;
		foreach($outputs as $output){
			if(!$output->canStackWith($sell)){
				throw new TransactionValidationException("Unexpected item taken from the trade: " . $output);
			}
			$given += $output->getCount();
		}
		if($given !== $sell->getCount() * $this->times){
			throw new TransactionValidationException("Expected " . ($sell->getCount() * $this->times) . " items from the trade, got $given");
		}
	}

	public function execute() : void{
		parent::execute();

		$this->offer->setUses($this->offer->getUses() + $this->times);
		if($this->offer->givesPlayerExp()){
			$xp = 0;
			for($i = 0; $i < $this->times; ++$i){
				$xp += mt_rand(3, 6);
			}
			$this->source->getXpManager()->addXp($xp);
		}
		$this->trader->onTrade($this->source, $this->offer, $this->times);
		$window = $this->source->getCurrentWindow();
		if($window instanceof TradeInventory){
			$this->source->getNetworkSession()->getInvManager()?->syncTrades($window);
		}
	}
}
