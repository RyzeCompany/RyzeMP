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

use pocketmine\block\utils\ItemTransfer;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\object\ItemEntity;
use pocketmine\event\entity\EntityItemPickupEvent;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use function floor;
use function min;
use function range;

/**
 * Minecart with hopper (vanilla minecraft:hopper_minecart): 5 slots. While enabled it takes items out of the container
 * above it and picks up item entities around it, one transfer every {@link MinecartHopper::TRANSFER_COOLDOWN_TICKS}.
 * It doesn't push items anywhere: hoppers under the rails empty it. A powered activator rail disables it, an unpowered
 * one enables it again (vanilla rail_sensor events hopper_deactivate / hopper_activate).
 */
class MinecartHopper extends ContainerMinecart{
	public const TAG_ENABLED = "Enabled"; //TAG_Byte

	public const INVENTORY_SIZE = 5;
	/** Ticks between transfers (the Bedrock hopper rate). */
	public const TRANSFER_COOLDOWN_TICKS = ItemTransfer::HOPPER_COOLDOWN_TICKS;

	protected bool $enabled = true;
	private int $transferCooldown = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::HOPPER_MINECART; }

	public function getName() : string{
		return "Minecart with Hopper";
	}

	public function getMinecartType() : MinecartType{
		return MinecartType::HOPPER;
	}

	public function getInventorySize() : int{
		return self::INVENTORY_SIZE;
	}

	public function getCartDrops() : array{
		return [VanillaItems::MINECART(), VanillaBlocks::HOPPER()->asItem()];
	}

	public function isEnabled() : bool{
		return $this->enabled;
	}

	public function setEnabled(bool $enabled) : void{
		$this->enabled = $enabled;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->enabled = $nbt->getByte(self::TAG_ENABLED, 1) !== 0;
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_ENABLED, $this->enabled ? 1 : 0);
		return $nbt;
	}

	protected function onActivatorRail(bool $powered) : void{
		$this->enabled = !$powered;
	}

	protected function tickMinecart() : void{
		parent::tickMinecart();
		if($this->closed || !$this->isAlive() || !$this->enabled){
			return;
		}
		if($this->transferCooldown > 0){
			--$this->transferCooldown;
			return;
		}
		if($this->suckInItems()){
			$this->transferCooldown = self::TRANSFER_COOLDOWN_TICKS;
		}
	}

	/**
	 * Takes one item from the container above, or picks up item entities around the cart (vanilla
	 * MinecartHopper.suckInItems()). Returns whether something was taken.
	 */
	public function suckInItems() : bool{
		$world = $this->getWorld();
		$inventory = $this->getInventory();
		$x = (int) floor($this->location->x);
		$y = (int) floor($this->location->y + 0.5) + 1;
		$z = (int) floor($this->location->z);
		$source = ItemTransfer::getContainerAt($world, $x, $y, $z);
		if($source !== null){
			$sourceInventory = $source->getInventory();
			$slots = range(0, $inventory->getSize() - 1);
			foreach(ItemTransfer::getExtractSlots($source, $sourceInventory, Facing::DOWN) as $slot){
				$item = $sourceInventory->getItem($slot);
				if(!$item->isNull() && ItemTransfer::addOneToSlots($inventory, $slots, $item)){
					$sourceInventory->setItem($slot, $item->setCount($item->getCount() - 1));
					return true;
				}
			}
			return false;
		}

		foreach($world->getNearbyEntities($this->boundingBox->expandedCopy(0.25, 0, 0.25), $this) as $entity){
			if(!$entity instanceof ItemEntity || $entity->isFlaggedForDespawn() || $entity->isClosed()){
				continue;
			}
			$stack = $entity->getItem();
			$count = min($stack->getCount(), $inventory->getAddableItemQuantity($stack));
			if($count <= 0){
				continue;
			}
			$ev = new EntityItemPickupEvent($this, $entity, (clone $stack)->setCount($count), $inventory);
			$ev->call();
			if($ev->isCancelled()){
				continue;
			}
			$target = $ev->getInventory() ?? $inventory;
			$taken = $ev->getItem();
			$leftover = 0;
			foreach($target->addItem($taken) as $notAdded){
				$leftover += $notAdded->getCount();
			}
			$remaining = $stack->getCount() - ($taken->getCount() - $leftover);
			if($remaining <= 0){
				$entity->flagForDespawn();
			}else{
				$entity->setStackSize($remaining);
			}
			return true;
		}
		return false;
	}
}
