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

use pocketmine\block\inventory\HopperInventory;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\World;
use function max;

class Hopper extends Spawnable implements ComparatorReadableContainer, Nameable{

	use ComparatorReadableContainerTrait;
	use ContainerTrait;
	use NameableTrait;

	private const TAG_TRANSFER_COOLDOWN = "TransferCooldown";

	private HopperInventory $inventory;
	/** Server tick from which the hopper may move items again. */
	private int $nextTransferTick = 0;

	public function __construct(World $world, Vector3 $pos){
		parent::__construct($world, $pos);
		$this->inventory = new HopperInventory($this->position);
		$this->addComparatorInventoryListener();
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->loadItems($nbt);
		$this->loadName($nbt);

		$cooldown = max(0, $nbt->getInt(self::TAG_TRANSFER_COOLDOWN, 0));
		$world = $this->position->getWorld();
		$this->nextTransferTick = $world->getServer()->getTick() + $cooldown;
		//hoppers only tick when something wakes them up; a hopper loaded from disk must look around once
		$world->scheduleDelayedBlockUpdate($this->position, max(1, $cooldown));
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->saveItems($nbt);
		$this->saveName($nbt);

		$nbt->setInt(self::TAG_TRANSFER_COOLDOWN, $this->getTransferCooldown());
	}

	/**
	 * Returns the server tick from which the hopper may move items again.
	 */
	public function getNextTransferTick() : int{
		return $this->nextTransferTick;
	}

	public function setNextTransferTick(int $tick) : void{
		$this->nextTransferTick = $tick;
	}

	/**
	 * Returns the number of ticks left before the hopper may move items again.
	 */
	public function getTransferCooldown() : int{
		return max(0, $this->nextTransferTick - $this->position->getWorld()->getServer()->getTick());
	}

	public function close() : void{
		if(!$this->closed){
			$this->inventory->removeAllViewers();

			parent::close();
		}
	}

	public function getDefaultName() : string{
		return "Hopper";
	}

	public function getInventory() : HopperInventory{
		return $this->inventory;
	}

	public function getRealInventory() : HopperInventory{
		return $this->inventory;
	}
}
