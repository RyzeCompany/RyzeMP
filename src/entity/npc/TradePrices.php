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

use pocketmine\entity\effect\VanillaEffects;
use pocketmine\player\Player;
use function floor;
use function max;
use function min;

/**
 * Price changes of trades for a particular player (https://minecraft.wiki/w/Trading#Sale_prices), only on the first
 * price of each trade:
 *
 * - Reputation (villagers, see {@link Gossips}): minus floor(price multiplier x reputation). Bedrock rule: a negative
 *   reputation doesn't raise prices, it only cancels positive gossip. Curing a zombie villager gives major + minor
 *   positive gossip, e.g. 5 x 20 + 25 = 125 reputation: -6 for a 0.05 multiplier trade.
 * - Hero of the Village (villagers and, in Bedrock, wandering traders): minus 30% of the base price plus 6.25% per
 *   level above I, rounded down but at least 1. Bedrock only gives Hero of the Village I.
 *
 * - Demand (see {@link TradeOffer::getDemand()}): plus floor(base price x price multiplier x demand).
 *
 * Bedrock formula: min(max(floor(p x (m x max(0, d) + 1)) - floor(m x max(0, r)) - hero discount, 1), stack size).
 */
final class TradePrices{
	public const HERO_DISCOUNT = 0.3;
	public const HERO_DISCOUNT_PER_LEVEL = 0.0625;

	private function __construct(){
		//NOOP
	}

	/**
	 * Price after the Hero of the Village discount of the given level (0 = no effect).
	 */
	public static function applyHeroDiscount(int $price, int $heroLevel, int $maxStackSize = 64) : int{
		return self::getPrice($price, 0.0, 0, $heroLevel, $maxStackSize);
	}

	/**
	 * First price of a trade for a player with the given reputation and Hero of the Village level (0 = none), when the
	 * trade has the given demand.
	 */
	public static function getPrice(int $basePrice, float $priceMultiplier, int $reputation, int $heroLevel, int $maxStackSize = 64, int $demand = 0) : int{
		if($basePrice <= 1 && $demand <= 0){
			return $basePrice;
		}
		$price = (int) floor($basePrice * ($priceMultiplier * max(0, $demand) + 1)) - (int) floor($priceMultiplier * max(0, $reputation));
		if($heroLevel > 0){
			$price -= max(1, (int) floor($basePrice * (self::HERO_DISCOUNT + self::HERO_DISCOUNT_PER_LEVEL * ($heroLevel - 1))));
		}
		return min(max($price, 1), $maxStackSize);
	}

	/**
	 * Number of items of the first price the player pays for one trade with the trader.
	 */
	public static function getBuyACount(TradeOffer $offer, ?Player $player, ?Trader $trader = null) : int{
		$buyA = $offer->getBuyA();
		if($player === null){
			return self::getPrice($buyA->getCount(), $offer->getPriceMultiplier(), 0, 0, $buyA->getMaxStackSize(), $offer->getDemand());
		}
		$hero = $player->getEffects()->get(VanillaEffects::VILLAGE_HERO());
		$reputation = $trader !== null ? $trader->getPlayerReputation($player) : 0;
		return self::getPrice($buyA->getCount(), $offer->getPriceMultiplier(), $reputation, $hero !== null ? $hero->getEffectLevel() : 0, $buyA->getMaxStackSize(), $offer->getDemand());
	}

	/**
	 * First price of a trade as sent to the player's client (UpdateTrade): like {@link TradePrices::getBuyACount()}
	 * but without the Hero of the Village discount, which the Bedrock client applies itself from the player's effects.
	 * BDS 1.26.60 sends the same offers (buyCountA, demand, priceMultiplierA) with and without the effect.
	 */
	public static function getClientBuyACount(TradeOffer $offer, ?Player $player, ?Trader $trader = null) : int{
		$buyA = $offer->getBuyA();
		$reputation = $player !== null && $trader !== null ? $trader->getPlayerReputation($player) : 0;
		return self::getPrice($buyA->getCount(), $offer->getPriceMultiplier(), $reputation, 0, $buyA->getMaxStackSize(), $offer->getDemand());
	}
}
