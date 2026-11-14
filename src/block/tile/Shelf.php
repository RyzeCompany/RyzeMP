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

namespace pocketmine\block\tile;

use pocketmine\block\Shelf as BlockShelf;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\data\bedrock\item\SavedItemStackData;
use pocketmine\inventory\CallbackInventoryListener;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\SimpleInventory;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\UnexpectedTagTypeException;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\world\World;

/**
 * Block entity of the wooden shelves (Bedrock ID "Shelf"). Holds three item stacks that the client renders on
 * the front of the shelf. Unlike other containers, the position of an entry in the Items list (not a Slot tag)
 * determines which slot it occupies, so empty slots are written as empty item entries.
 */
class Shelf extends Spawnable implements Container{
	use ContainerTrait;

	public const SLOTS = 3;

	private SimpleInventory $inventory;

	public function __construct(World $world, Vector3 $pos){
		parent::__construct($world, $pos);
		$this->inventory = new SimpleInventory(self::SLOTS);
		$this->inventory->getListeners()->add(CallbackInventoryListener::onAnyChange(
			static function(Inventory $unused) use ($world, $pos) : void{
				//re-send the block and its tile data so viewers see the new items
				$block = $world->getBlock($pos);
				if($block instanceof BlockShelf){
					$world->setBlock($pos, $block);
				}
			})
		);
	}

	public function getInventory() : SimpleInventory{
		return $this->inventory;
	}

	public function getRealInventory() : SimpleInventory{
		return $this->inventory;
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->loadItems($nbt);
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->saveItems($nbt);
	}

	protected function loadItems(CompoundTag $tag) : void{
		try{
			$inventoryTag = $tag->getListTag(Container::TAG_ITEMS, CompoundTag::class);
		}catch(UnexpectedTagTypeException){
			$inventoryTag = null;
		}
		if($inventoryTag !== null){
			$inventory = $this->getRealInventory();
			$listeners = $inventory->getListeners()->toArray();
			$inventory->getListeners()->remove(...$listeners); //prevent any events being fired by initialization

			$newContents = [];
			foreach($inventoryTag as $slot => $itemNBT){
				if($slot >= self::SLOTS){
					break;
				}
				if($itemNBT->getByte(SavedItemStackData::TAG_COUNT, 0) === 0){
					continue;
				}
				$newContents[$slot] = Item::safeNbtDeserialize($itemNBT, "Shelf ($this->position) slot $slot");
			}
			$inventory->setContents($newContents);

			$inventory->getListeners()->add(...$listeners);
		}

		if(($lockTag = $tag->getTag(Container::TAG_LOCK)) instanceof StringTag){
			$this->lock = $lockTag->getValue();
		}
	}

	private static function emptyItemTag() : CompoundTag{
		return CompoundTag::create()
			->setByte(SavedItemStackData::TAG_COUNT, 0)
			->setShort(SavedItemData::TAG_DAMAGE, 0)
			->setString(SavedItemData::TAG_NAME, "")
			->setByte(SavedItemStackData::TAG_WAS_PICKED_UP, 0);
	}

	protected function saveItems(CompoundTag $tag) : void{
		$items = [];
		foreach($this->getRealInventory()->getContents(true) as $item){
			$items[] = $item->isNull() ? self::emptyItemTag() : $item->nbtSerialize();
		}
		$tag->setTag(Container::TAG_ITEMS, new ListTag($items, NBT::TAG_Compound));

		if($this->lock !== null){
			$tag->setString(Container::TAG_LOCK, $this->lock);
		}
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$translator = TypeConverter::getInstance()->getItemTranslator();
		$items = [];
		foreach($this->getRealInventory()->getContents(true) as $item){
			$items[] = $item->isNull() ? self::emptyItemTag() : $translator->toNetworkNbt($item);
		}
		$nbt->setTag(Container::TAG_ITEMS, new ListTag($items, NBT::TAG_Compound));
	}
}
