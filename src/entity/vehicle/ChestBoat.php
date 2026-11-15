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

namespace pocketmine\entity\vehicle;

use pocketmine\block\tile\LootTableContainerTrait;
use pocketmine\data\bedrock\item\SavedItemStackData;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\riding\RideableSeat;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\inventory\ChestBoatInventory;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;
use function count;

/**
 * A boat with chest (vanilla minecraft:chest_boat, every wood and the bamboo chest raft): one seat and a 27-slot chest.
 * Sneak-interacting with it, or the inventory button of its passenger, opens the chest. Like container blocks it can
 * hold a loot table ("LootTable" and "LootTableSeed") which fills it the first time it is opened or broken. Broken, it
 * drops itself and its contents.
 */
class ChestBoat extends Boat{
	use LootTableContainerTrait;

	public const INVENTORY_SIZE = 27;
	public const TAG_CHEST_ITEMS = "ChestItems"; //TAG_List<TAG_Compound> (Bedrock)
	public const TAG_ITEMS = "Items"; //TAG_List<TAG_Compound> (read only, Java and older data)

	/** Players farther than this from the boat get its window closed. */
	public const MAX_VIEW_DISTANCE = 8.0;

	protected ChestBoatInventory $inventory;

	public function __construct(Location $location, ?CompoundTag $nbt = null){
		$this->inventory = new ChestBoatInventory($this);
		parent::__construct($location, $nbt);
	}

	public static function getNetworkTypeId() : string{ return EntityIds::CHEST_BOAT; }

	public function getName() : string{
		return $this->variant->isRaft() ? "Raft with Chest" : "Boat with Chest";
	}

	public function getBoatItem() : Item{
		return $this->variant->getChestBoatItem();
	}

	/**
	 * Returns the inventory, filling it from its loot table first if it has one.
	 */
	public function getInventory() : ChestBoatInventory{
		$this->unpackLootTable();
		return $this->inventory;
	}

	/**
	 * Returns the inventory without filling it from the loot table.
	 */
	public function getRealInventory() : ChestBoatInventory{
		return $this->inventory;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->loadLootTable($nbt);
		$itemsTag = $nbt->getTag(self::TAG_CHEST_ITEMS) ?? $nbt->getTag(self::TAG_ITEMS);
		if($itemsTag instanceof ListTag && $itemsTag->getTagType() === NBT::TAG_Compound){
			$contents = [];
			/** @var CompoundTag $itemTag */
			foreach($itemsTag as $itemTag){
				$slot = $itemTag->getByte(SavedItemStackData::TAG_SLOT, -1);
				$item = Item::safeNbtDeserialize($itemTag, static::class . " slot $slot");
				if($this->inventory->slotExists($slot) && !$item->isNull()){
					$contents[$slot] = $item;
				}
			}
			$this->inventory->setContents($contents);
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$this->saveLootTable($nbt);
		$items = [];
		foreach($this->inventory->getContents() as $slot => $item){
			$items[] = $item->nbtSerialize($slot);
		}
		$nbt->setTag(self::TAG_CHEST_ITEMS, new ListTag($items, NBT::TAG_Compound));
		return $nbt;
	}

	public function getRideableSeats() : array{
		//vanilla chest_boat: a single seat in front of the chest
		$y = $this->variant->isRaft() ? 0.1 : -0.2;
		return [new RideableSeat(new Vector3(0.2, $y, 0), 90.0, null, null, $this->getRiderRotation(0))];
	}

	/**
	 * Whether the player may open the chest now (close enough, not a spectator).
	 */
	public function canOpenInventory(Player $player) : bool{
		return !$player->isSpectator() && $this->isAlive() && !$this->isBroken() &&
			$player->getWorld() === $this->getWorld() &&
			$player->getLocation()->distance($this->location) <= self::MAX_VIEW_DISTANCE;
	}

	/**
	 * Opens the chest to the player (filling it from its loot table first). Returns whether it opened.
	 */
	public function openInventory(Player $player) : bool{
		if(!$this->canOpenInventory($player)){
			return false;
		}
		$this->unpackLootTable($player);
		return $player->setCurrentWindow($this->inventory);
	}

	public function getInteractButtonText(Player $player) : string{
		if($player->isSneaking()){
			return "action.interact.opencontainer";
		}
		return parent::getInteractButtonText($player);
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		if($player->isSneaking() || $player->getVehicle() === $this){
			return $this->openInventory($player);
		}
		return parent::onInteract($player, $clickPos);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		foreach($this->inventory->getViewers() as $viewer){
			if($viewer->getWorld() !== $this->getWorld() || $viewer->getLocation()->distance($this->location) > self::MAX_VIEW_DISTANCE){
				$viewer->removeCurrentWindow();
			}
		}
		return $hasUpdate || count($this->inventory->getViewers()) > 0;
	}

	protected function onDeath() : void{
		parent::onDeath();
		$cause = $this->getLastDamageCause();
		$damager = $cause instanceof EntityDamageByEntityEvent ? $cause->getDamager() : null;
		$this->unpackLootTable($damager instanceof Entity ? $damager : null);
		$world = $this->getWorld();
		$pos = $this->location->add(0, 0.3, 0);
		foreach($this->inventory->getContents() as $item){
			$world->dropItem($pos, $item);
		}
		$this->inventory->clearAll();
		$this->inventory->removeAllViewers();
	}

	protected function onDispose() : void{
		$this->inventory->removeAllViewers();
		parent::onDispose();
	}
}
