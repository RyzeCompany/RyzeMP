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

use pocketmine\block\inventory\CrafterInventory;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\World;
use function min;

/**
 * Block entity of crafters ("Crafter").
 *
 * Saved data: Items (slots 0-8) and disabled_slots (TAG_Short bit mask, bit N = slot N), per the Minecraft Wiki
 * "Bedrock Edition level format/Block entity format" and GeyserMC CrafterInventoryTranslator.
 */
class Crafter extends Spawnable implements ComparatorReadableContainer, Nameable{
	use NameableTrait{
		addAdditionalSpawnData as addNameSpawnData;
	}
	use ComparatorReadableContainerTrait{
		getComparatorOutput as getFullnessComparatorOutput;
	}
	use ContainerTrait;

	public const TAG_DISABLED_SLOTS = "disabled_slots";

	protected CrafterInventory $inventory;

	/** Server tick at which a pending craft happens, null if none (not saved, like other scheduled updates) */
	private ?int $craftAtTick = null;
	/** Server tick at which the "crafting" block state goes back off */
	private int $craftingUntilTick = 0;

	public function getCraftAtTick() : ?int{ return $this->craftAtTick; }

	public function setCraftAtTick(?int $tick) : void{ $this->craftAtTick = $tick; }

	public function getCraftingUntilTick() : int{ return $this->craftingUntilTick; }

	public function setCraftingUntilTick(int $tick) : void{ $this->craftingUntilTick = $tick; }

	public function __construct(World $world, Vector3 $pos){
		parent::__construct($world, $pos);
		$this->inventory = new CrafterInventory($this->position);
		$this->addComparatorInventoryListener();
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->loadName($nbt);
		$this->loadItems($nbt);
		$this->inventory->setDisabledSlotsMask($nbt->getShort(self::TAG_DISABLED_SLOTS, 0));
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->saveName($nbt);
		$this->saveItems($nbt);
		$nbt->setShort(self::TAG_DISABLED_SLOTS, $this->inventory->getDisabledSlotsMask());
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$this->addNameSpawnData($nbt);
		$nbt->setShort(self::TAG_DISABLED_SLOTS, $this->inventory->getDisabledSlotsMask());
	}

	public function close() : void{
		if(!$this->closed){
			$this->inventory->removeAllViewers();
			parent::close();
		}
	}

	public function getInventory() : CrafterInventory{
		return $this->inventory;
	}

	public function getRealInventory() : CrafterInventory{
		return $this->inventory;
	}

	public function getDefaultName() : string{
		return "Crafter";
	}

	/**
	 * Vanilla: the number of slots which are disabled or hold an item, whatever the stack sizes.
	 */
	public function getComparatorOutput() : int{
		$count = 0;
		for($slot = 0; $slot < CrafterInventory::SIZE; ++$slot){
			if($this->inventory->isSlotDisabled($slot) || !$this->inventory->isSlotEmpty($slot)){
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Toggles a slot and tells clients and comparators. Returns false if the slot could not be changed (it holds an
	 * item).
	 */
	public function setSlotDisabled(int $slot, bool $disabled) : bool{
		if($this->inventory->isSlotDisabled($slot) === $disabled){
			return true;
		}
		if(!$this->inventory->setSlotDisabled($slot, $disabled)){
			return false;
		}
		$world = $this->position->getWorld();
		//resend the block entity data with the new mask
		$world->setBlock($this->position, $world->getBlock($this->position));
		$world->getRedstone()?->updateComparatorsReading($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ());
		return true;
	}

	/**
	 * Returns the slot a hopper or dropper puts the given item into, or null if no enabled slot can take it.
	 * Vanilla: the first empty enabled slot, otherwise the matching stack with the lowest count (earliest on ties).
	 */
	public function getInsertSlotFor(Item $item) : ?int{
		$best = null;
		$bestCount = 0;
		$maxStack = min($this->inventory->getMaxStackSize(), $item->getMaxStackSize());
		for($slot = 0; $slot < CrafterInventory::SIZE; ++$slot){
			if($this->inventory->isSlotDisabled($slot)){
				continue;
			}
			$existing = $this->inventory->getItem($slot);
			if($existing->isNull()){
				return $slot;
			}
			if($existing->getCount() < $maxStack && $existing->canStackWith($item) && ($best === null || $existing->getCount() < $bestCount)){
				$best = $slot;
				$bestCount = $existing->getCount();
			}
		}
		return $best;
	}
}
