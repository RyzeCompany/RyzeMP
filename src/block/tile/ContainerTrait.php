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

use pocketmine\data\bedrock\item\SavedItemStackData;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\loot\LootContext;
use pocketmine\loot\LootTables;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\Tag;
use pocketmine\nbt\UnexpectedTagTypeException;
use pocketmine\utils\Utils;
use pocketmine\world\Position;
use pocketmine\world\generator\InvalidGeneratorOptionsException;

/**
 * This trait implements most methods in the {@link Container} interface. It should only be used by Tiles.
 */
trait ContainerTrait{
	/** @var string|null */
	private $lock = null;

	/** @var array<string, Tag> Unopened loot data preserved alongside the saved inventory. */
	private array $lootTableNBT = [];

	abstract public function getRealInventory() : Inventory;

	protected function loadItems(CompoundTag $tag) : void{
		$this->lootTableNBT = [];
		if(($lootTable = $tag->getTag(Container::TAG_LOOT_TABLE)) instanceof StringTag && $lootTable->getValue() !== ""){
			$this->lootTableNBT[Container::TAG_LOOT_TABLE] = clone $lootTable;
			foreach([Container::TAG_LOOT_TABLE_SEED, Container::TAG_LOOT_TABLE_SEED_LONG] as $key){
				if(($seed = $tag->getTag($key)) !== null){
					$this->lootTableNBT[$key] = clone $seed;
				}
			}
		}
		try{
			$inventoryTag = $tag->getListTag(Container::TAG_ITEMS, CompoundTag::class);
		}catch(UnexpectedTagTypeException){
			//preserve the old behaviour of not throwing on wrong types
			$inventoryTag = null;
		}
		if($inventoryTag !== null){
			$inventory = $this->getRealInventory();
			$listeners = $inventory->getListeners()->toArray();
			$inventory->getListeners()->remove(...$listeners); //prevent any events being fired by initialization

			$newContents = [];
			$errorLogContext = "Container (" . $this->getPosition() . ")";
			foreach($inventoryTag as $itemNBT){
				$slotId = $itemNBT->getByte(SavedItemStackData::TAG_SLOT);
				$newContents[$slotId] = Item::safeNbtDeserialize($itemNBT, "$errorLogContext slot $slotId");
			}
			$inventory->setContents($newContents);

			$inventory->getListeners()->add(...$listeners);
		}

		if(($lockTag = $tag->getTag(Container::TAG_LOCK)) instanceof StringTag){
			$this->lock = $lockTag->getValue();
		}
	}

	protected function saveItems(CompoundTag $tag) : void{
		$items = [];
		foreach($this->getRealInventory()->getContents() as $slot => $item){
			$items[] = $item->nbtSerialize($slot);
		}

		$tag->setTag(Container::TAG_ITEMS, new ListTag($items, NBT::TAG_Compound));
		foreach(Utils::stringifyKeys($this->lootTableNBT) as $key => $value){
			$tag->setTag($key, clone $value);
		}

		if($this->lock !== null){
			$tag->setString(Container::TAG_LOCK, $this->lock);
		}
	}

	protected function unpackWorldgenLoot() : void{
		$table = $this->lootTableNBT[Container::TAG_LOOT_TABLE] ?? null;
		if(!$table instanceof StringTag){
			return;
		}
		$position = $this->getPosition();
		$world = $position->getWorld();
		try{
			$loot = LootTables::forWorld($world)?->get($table->getValue());
			if($loot === null){
				return;
			}
			$seed = $this->lootTableNBT[Container::TAG_LOOT_TABLE_SEED_LONG] ?? $this->lootTableNBT[Container::TAG_LOOT_TABLE_SEED] ?? null;
			$context = LootContext::seeded($seed instanceof LongTag || $seed instanceof IntTag ? $seed->getValue() : 0);
			$context->world = $world;
			$context->origin = $position->add(0.5, 0.5, 0.5);
			$inventory = $this->getRealInventory();
			$contents = $loot->rollInventory($inventory, $context);
			$this->lootTableNBT = []; // Inventory listeners may access the same container.
			$inventory->setContents($contents);
		}catch(InvalidGeneratorOptionsException|\RuntimeException|\JsonException $e){
			$world->getLogger()->warning("Unable to fill generated container at $position: " . $e->getMessage());
		}
	}

	/**
	 * @see Container::canOpenWith()
	 */
	public function canOpenWith(string $key) : bool{
		return $this->lock === null || $this->lock === $key;
	}

	/**
	 * @see Position::asPosition()
	 */
	abstract protected function getPosition() : Position;

	/**
	 * @see Tile::onBlockDestroyedHook()
	 */
	protected function onBlockDestroyedHook() : void{
		$this->unpackWorldgenLoot();
		$inv = $this->getRealInventory();
		$pos = $this->getPosition();

		$world = $pos->getWorld();
		$dropPos = $pos->add(0.5, 0.5, 0.5);
		foreach($inv->getContents() as $k => $item){
			$world->dropItem($dropPos, $item);
		}
		$inv->clearAll();
	}
}
