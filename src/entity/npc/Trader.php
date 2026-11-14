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

use pocketmine\entity\Living;
use pocketmine\player\Player;

/**
 * An entity players can trade with (villagers, wandering traders) through {@link \pocketmine\inventory\TradeInventory}.
 *
 * @phpstan-require-extends Living
 */
interface Trader{
	/**
	 * Returns the trades offered to players right now (all tiers up to the trader's level).
	 *
	 * @return TradeOffer[]
	 * @phpstan-return list<TradeOffer>
	 */
	public function getTradeOffers() : array;

	/**
	 * Returns the trader level shown in the trading screen: 0 (novice) to 4 (master).
	 */
	public function getTradeTier() : int;

	/**
	 * Experience needed for each level ("TierExpRequirements"), e.g. [0, 10, 70, 150, 250].
	 *
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	public function getTierExpRequirements() : array;

	/**
	 * Name shown at the top of the trading screen (a translation key such as "entity.villager.farmer" works).
	 */
	public function getTradeDisplayName() : string;

	/**
	 * Whether the player can trade with it now (an adult with trades, not already trading with someone else...).
	 */
	public function canTradeWith(Player $player) : bool;

	public function setTradingPlayer(?Player $player) : void;

	/**
	 * Reputation of the player with this trader, which lowers prices when positive (see {@link TradePrices}).
	 */
	public function getPlayerReputation(Player $player) : int;

	/**
	 * Called after the player made a trade $times times.
	 */
	public function onTrade(Player $player, TradeOffer $offer, int $times) : void;
}
