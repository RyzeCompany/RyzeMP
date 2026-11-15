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

use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\player\Player;

/**
 * The item a trader shows in its hand while a player holding something it buys is near ({@link TradeInterestGoal}).
 * Only shown to clients (MobEquipment); it is not saved and never dropped.
 *
 * @internal
 */
trait TradeItemDisplayTrait{
	private ?Item $shownTradeItem = null;

	/**
	 * Item shown in the trader's hand, or null if its hand is empty.
	 */
	public function getShownTradeItem() : ?Item{
		return $this->shownTradeItem !== null ? clone $this->shownTradeItem : null;
	}

	private function setShownTradeItem(?Item $item) : void{
		if($item === null && $this->shownTradeItem === null){
			return;
		}
		$this->shownTradeItem = $item !== null ? (clone $item)->setCount(1) : null;
		$this->sendShownTradeItem($this->getViewers());
	}

	/**
	 * @param Player[] $players
	 */
	private function sendShownTradeItem(array $players) : void{
		$item = $this->shownTradeItem ?? VanillaItems::AIR();
		foreach($players as $player){
			$session = $player->getNetworkSession();
			$session->sendDataPacket(MobEquipmentPacket::create(
				$this->getId(),
				ItemStackWrapper::legacy($session->getTypeConverter()->coreItemStackToNet($item)),
				0,
				0,
				ContainerIds::INVENTORY
			));
		}
	}

	protected function sendSpawnPacket(Player $player) : void{
		parent::sendSpawnPacket($player);
		if($this->shownTradeItem !== null){
			$this->sendShownTradeItem([$player]);
		}
	}
}
