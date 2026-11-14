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

use pocketmine\data\SavedDataLoadingException;
use pocketmine\item\Item;
use pocketmine\nbt\tag\CompoundTag;
use function max;

/**
 * One trade of a villager or wandering trader: the items wanted (buyA, optional buyB), the item given, how many times
 * it can be used before restocking, and the experience it gives the trader (and whether the player gets some).
 * The network ID ({@link TradeOffer::getNetworkId()}) is what the client sends in CraftRecipe requests to make this
 * trade; it's unique for the server session.
 *
 * Serialized like vanilla Bedrock "Recipes" entries (buyA/buyB/sell items, buyCountA/B, maxUses, uses, tier,
 * traderExp, rewardExp, priceMultiplierA/B, demand, netId).
 *
 * Demand rises when the trade is used a lot between two restocks and makes the first price higher (see
 * {@link TradePrices}): at each restock it becomes demand + uses - (maxUses - uses), never below 0
 * (https://minecraft.wiki/w/Trading#Demand; vanilla Java MerchantOffer.updateDemand()).
 */
final class TradeOffer{
	/** First network ID of trades, well above the crafting recipe IDs. */
	private const NETWORK_ID_OFFSET = 1_000_000;
	private static int $nextNetworkId = self::NETWORK_ID_OFFSET;

	private int $networkId;

	public function __construct(
		private Item $buyA,
		private ?Item $buyB,
		private Item $sell,
		private int $maxUses,
		private int $tier,
		private int $traderExp,
		private bool $rewardExp = true,
		private int $uses = 0,
		private float $priceMultiplier = 0.05,
		private float $priceMultiplierB = 0.0,
		private int $demand = 0
	){
		$this->networkId = self::$nextNetworkId++;
		$this->demand = max(0, $demand);
	}

	public function getNetworkId() : int{ return $this->networkId; }

	public function getBuyA() : Item{ return clone $this->buyA; }

	public function getBuyB() : ?Item{ return $this->buyB !== null ? clone $this->buyB : null; }

	public function getSell() : Item{ return clone $this->sell; }

	public function getMaxUses() : int{ return $this->maxUses; }

	public function getUses() : int{ return $this->uses; }

	public function setUses(int $uses) : void{ $this->uses = $uses; }

	public function isDisabled() : bool{ return $this->uses >= $this->maxUses; }

	/** Trader level needed for this trade, 0 (novice) to 4 (master). */
	public function getTier() : int{ return $this->tier; }

	public function getTraderExp() : int{ return $this->traderExp; }

	public function givesPlayerExp() : bool{ return $this->rewardExp; }

	/** How much reputation (and demand) changes the first price (see {@link TradePrices}). */
	public function getPriceMultiplier() : float{ return $this->priceMultiplier; }

	/** Price multiplier of the second price (vanilla priceMultiplierB, e.g. the book of an enchanted book trade). */
	public function getPriceMultiplierB() : float{ return $this->priceMultiplierB; }

	/** How much the trade was in demand at the last restocks (0 = not raised). */
	public function getDemand() : int{ return $this->demand; }

	public function setDemand(int $demand) : void{ $this->demand = max(0, $demand); }

	/**
	 * Restocks the trade: updates the demand from the uses since the last restock, then resets the uses.
	 */
	public function restock() : void{
		$this->setDemand($this->demand + $this->uses - ($this->maxUses - $this->uses));
		$this->uses = 0;
	}

	/**
	 * @param int|null $buyACount first price shown to the player, already including demand and discounts (see
	 *                            {@link TradePrices}); the saved price and demand if null. The demand sent with a shown
	 *                            price is 0, so that it is not counted twice.
	 */
	public function toNbt(?int $buyACount = null) : CompoundTag{
		$buyA = $buyACount !== null ? (clone $this->buyA)->setCount($buyACount) : $this->buyA;
		$nbt = CompoundTag::create()
			->setTag("buyA", $buyA->nbtSerialize())
			->setInt("buyCountA", $buyA->getCount())
			->setTag("sell", $this->sell->nbtSerialize())
			->setInt("maxUses", $this->maxUses)
			->setInt("uses", $this->uses)
			->setInt("tier", $this->tier)
			->setInt("traderExp", $this->traderExp)
			->setByte("rewardExp", $this->rewardExp ? 1 : 0)
			->setFloat("priceMultiplierA", $this->priceMultiplier)
			->setFloat("priceMultiplierB", $this->priceMultiplierB)
			->setInt("demand", $buyACount !== null ? 0 : $this->demand)
			->setInt("netId", $this->networkId);
		if($this->buyB !== null){
			$nbt->setTag("buyB", $this->buyB->nbtSerialize());
			$nbt->setInt("buyCountB", $this->buyB->getCount());
		}else{
			$nbt->setInt("buyCountB", 0);
		}
		return $nbt;
	}

	/**
	 * @throws SavedDataLoadingException
	 */
	public static function fromNbt(CompoundTag $nbt) : self{
		$buyA = $nbt->getCompoundTag("buyA") ?? throw new SavedDataLoadingException("Missing buyA");
		$sell = $nbt->getCompoundTag("sell") ?? throw new SavedDataLoadingException("Missing sell");
		$buyB = $nbt->getCompoundTag("buyB");
		return new self(
			Item::nbtDeserialize($buyA),
			$buyB !== null ? Item::nbtDeserialize($buyB) : null,
			Item::nbtDeserialize($sell),
			$nbt->getInt("maxUses", 12),
			$nbt->getInt("tier", 0),
			$nbt->getInt("traderExp", 0),
			$nbt->getByte("rewardExp", 1) !== 0,
			$nbt->getInt("uses", 0),
			$nbt->getFloat("priceMultiplierA", 0.05),
			$nbt->getFloat("priceMultiplierB", 0.0),
			$nbt->getInt("demand", 0)
		);
	}
}
