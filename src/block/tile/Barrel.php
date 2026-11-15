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

use pocketmine\block\inventory\BarrelInventory;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\World;

class Barrel extends Spawnable implements ComparatorReadableContainer, Nameable{
	use NameableTrait;
	use ComparatorReadableContainerTrait;
	use ContainerTrait {
		onBlockDestroyedHook as containerTraitBlockDestroyedHook;
	}
	use LootTableContainerTrait;

	protected BarrelInventory $inventory;

	public function __construct(World $world, Vector3 $pos){
		parent::__construct($world, $pos);
		$this->inventory = new BarrelInventory($this->position);
		$this->addComparatorInventoryListener();
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->loadName($nbt);
		$this->loadItems($nbt);
		$this->loadLootTable($nbt);
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->saveName($nbt);
		$this->saveItems($nbt);
		$this->saveLootTable($nbt);
	}

	public function close() : void{
		if(!$this->closed){
			$this->inventory->removeAllViewers();
			parent::close();
		}
	}

	/**
	 * Returns the inventory, filling it from its loot table first if it has one (see {@link LootTableContainerTrait}).
	 */
	public function getInventory() : BarrelInventory{
		$this->unpackLootTable();
		return $this->inventory;
	}

	protected function onBlockDestroyedHook() : void{
		$this->unpackLootTable();
		$this->containerTraitBlockDestroyedHook();
	}

	public function getRealInventory() : BarrelInventory{
		return $this->inventory;
	}

	public function getDefaultName() : string{
		return "Barrel";
	}
}
