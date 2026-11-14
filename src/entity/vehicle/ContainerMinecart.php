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

use pocketmine\block\tile\Chest as TileChest;
use pocketmine\block\tile\LootTableContainerTrait;
use pocketmine\block\utils\ItemTransfer;
use pocketmine\data\bedrock\item\SavedItemStackData;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\inventory\CallbackInventoryListener;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\MinecartInventory;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\player\Player;
use function floor;

/**
 * A minecart with an inventory (chest or hopper). Like container blocks, it can hold a loot table ("LootTable" and
 * "LootTableSeed", e.g. the chest minecarts of mineshafts) which fills it the first time it is opened, emptied by a
 * hopper or broken. Its contents are dropped when it is destroyed.
 */
abstract class ContainerMinecart extends AbstractMinecart{
	use LootTableContainerTrait;

	public const TAG_CHEST_ITEMS = "ChestItems"; //TAG_List<TAG_Compound> (Bedrock)
	public const TAG_ITEMS = "Items"; //TAG_List<TAG_Compound> (read only, Java and older data)

	/** Players farther than this from the cart get its window closed. */
	public const MAX_VIEW_DISTANCE = 8.0;

	protected MinecartInventory $inventory;
	/** @phpstan-var array{int, int, int}|null */
	private ?array $lastNotifiedPos = null;

	public function __construct(Location $location, ?CompoundTag $nbt = null){
		$this->inventory = new MinecartInventory($this, $this->getInventorySize());
		parent::__construct($location, $nbt);
		$this->inventory->getListeners()->add(CallbackInventoryListener::onAnyChange(function(Inventory $unused) : void{
			$this->onContentsChanged();
		}));
	}

	/**
	 * Number of slots of the inventory.
	 */
	abstract public function getInventorySize() : int;

	/**
	 * Returns the inventory, filling it from its loot table first if it has one.
	 */
	public function getInventory() : MinecartInventory{
		$this->unpackLootTable();
		return $this->inventory;
	}

	/**
	 * Returns the inventory without filling it from the loot table.
	 */
	public function getRealInventory() : MinecartInventory{
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

	public function getDetectorRailComparatorOutput() : int{
		return TileChest::calculateComparatorOutput($this->getInventory());
	}

	/**
	 * Vanilla AbstractMinecartContainer.applyNaturalSlowdown(): carts keep 98% of their speed, +0.1% per comparator
	 * level of emptiness (an empty cart rolls the furthest).
	 */
	protected function getSlowdownFactor(bool $inWater) : float{
		$keep = 0.98;
		if($this->getLootTable() === null){
			$keep += (15 - TileChest::calculateComparatorOutput($this->inventory)) * 0.001;
		}
		return $inWater ? $keep * 0.95 : $keep;
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		if($player->isSpectator()){
			return false;
		}
		$this->unpackLootTable($player);
		return $player->setCurrentWindow($this->inventory);
	}

	protected function onContentsChanged() : void{
		$this->lastNotifiedPos = null;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		foreach($this->inventory->getViewers() as $viewer){
			if($viewer->getWorld() !== $this->getWorld() || $viewer->getLocation()->distance($this->location) > self::MAX_VIEW_DISTANCE){
				$viewer->removeCurrentWindow();
			}
		}
		$this->notifyHoppers();
		return $hasUpdate;
	}

	/**
	 * Wakes up the hoppers around the cart (they sleep until something changes) when it enters a block or its contents
	 * changed, so that hoppers under the rails empty it and hoppers pointing at it fill it.
	 */
	private function notifyHoppers() : void{
		$pos = [(int) floor($this->location->x), (int) floor($this->location->y), (int) floor($this->location->z)];
		if($pos === $this->lastNotifiedPos && $this->ticksLived % 20 !== 0){
			return;
		}
		$this->lastNotifiedPos = $pos;
		ItemTransfer::notifyContainerChanged($this->getWorld(), $pos[0], $pos[1], $pos[2]);
	}

	protected function onDeath() : void{
		parent::onDeath();
		$cause = $this->getLastDamageCause();
		$this->unpackLootTable($cause instanceof EntityDamageByEntityEvent ? $cause->getDamager() : null);
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
