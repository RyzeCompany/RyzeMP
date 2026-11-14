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

namespace pocketmine\inventory;

use pocketmine\entity\npc\TradeOffer;
use pocketmine\entity\npc\Trader;
use pocketmine\player\Player;

/**
 * The trading screen of a villager or wandering trader, opened for one player. Its two slots are the payment slots of
 * the screen (UI slots 4 and 5, "trade2_ingredient"); items left there go back to the player when it's closed.
 * Opening it sends the trades with UpdateTradePacket (see {@link \pocketmine\network\mcpe\InventoryManager}).
 */
final class TradeInventory extends SimpleInventory implements TemporaryInventory{
	public const SLOT_BUY_A = 0;
	public const SLOT_BUY_B = 1;

	public function __construct(
		private Trader $trader,
		private Player $player
	){
		parent::__construct(2);
	}

	public function getTrader() : Trader{
		return $this->trader;
	}

	public function getPlayer() : Player{
		return $this->player;
	}

	/**
	 * Returns the trade with the given network ID (the recipe ID of the client's CraftRecipe request), if the trader
	 * offers it.
	 */
	public function getOffer(int $networkId) : ?TradeOffer{
		foreach($this->trader->getTradeOffers() as $offer){
			if($offer->getNetworkId() === $networkId){
				return $offer;
			}
		}
		return null;
	}

	public function onOpen(Player $who) : void{
		parent::onOpen($who);
		$this->trader->setTradingPlayer($who);
	}

	public function onClose(Player $who) : void{
		parent::onClose($who);
		$this->trader->setTradingPlayer(null);
	}
}
