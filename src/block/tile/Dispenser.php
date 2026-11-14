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

use pocketmine\block\inventory\DispenserInventory;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\World;

/**
 * Block entity of dispensers ("Dispenser") and, through {@link Dropper}, droppers.
 */
class Dispenser extends Spawnable implements ComparatorReadableContainer, Nameable{
	use NameableTrait;
	use ComparatorReadableContainerTrait;
	use ContainerTrait {
		onBlockDestroyedHook as containerTraitBlockDestroyedHook;
	}
	use LootTableContainerTrait;

	protected DispenserInventory $inventory;
	/** Server tick at which a dispense requested by a redstone rising edge is due, or -1 (not saved). */
	private int $pendingDispenseTick = -1;

	public function __construct(World $world, Vector3 $pos){
		parent::__construct($world, $pos);
		$this->inventory = $this->createInventory();
		$this->addComparatorInventoryListener();
	}

	protected function createInventory() : DispenserInventory{
		return new DispenserInventory($this->position);
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
	public function getInventory() : DispenserInventory{
		$this->unpackLootTable();
		return $this->inventory;
	}

	protected function onBlockDestroyedHook() : void{
		$this->unpackLootTable();
		$this->containerTraitBlockDestroyedHook();
	}

	public function getRealInventory() : DispenserInventory{
		return $this->inventory;
	}

	/**
	 * Server tick at which the block should dispense (set when it gets powered), or -1 if no dispense is pending.
	 * Block updates are also scheduled for other reasons (e.g. when the tile is added to the world as its chunk loads),
	 * so the block only dispenses when one was requested.
	 */
	public function getPendingDispenseTick() : int{
		return $this->pendingDispenseTick;
	}

	public function setPendingDispenseTick(int $tick) : void{
		$this->pendingDispenseTick = $tick;
	}

	public function getDefaultName() : string{
		return "Dispenser";
	}
}
