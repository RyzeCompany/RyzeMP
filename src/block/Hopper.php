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

namespace pocketmine\block;

use pocketmine\block\tile\Hopper as TileHopper;
use pocketmine\block\utils\ItemTransfer;
use pocketmine\block\utils\ItemTransferContainer;
use pocketmine\block\utils\PoweredByRedstone;
use pocketmine\block\utils\PoweredByRedstoneTrait;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\object\ItemEntity;
use pocketmine\event\block\BlockItemPickupEvent;
use pocketmine\event\inventory\HopperTransferItemEvent;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\World;
use function max;
use function min;

class Hopper extends Transparent implements PoweredByRedstone{
	use PoweredByRedstoneTrait;

	private int $facing = Facing::DOWN;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facingExcept($this->facing, Facing::UP);
		$w->bool($this->powered);
	}

	public function getFacing() : int{ return $this->facing; }

	/** @return $this */
	public function setFacing(int $facing) : self{
		if($facing === Facing::UP){
			throw new \InvalidArgumentException("Hopper may not face upward");
		}
		$this->facing = $facing;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		$result = [
			AxisAlignedBB::one()->trim(Facing::UP, 6 / 16) //the empty area around the bottom is currently considered solid
		];

		foreach(Facing::HORIZONTAL as $f){ //add the frame parts around the bowl
			$result[] = AxisAlignedBB::one()->trim($f, 14 / 16);
		}
		return $result;
	}

	public function getSupportType(int $facing) : SupportType{
		return match($facing){
			Facing::UP => SupportType::FULL,
			Facing::DOWN => $this->facing === Facing::DOWN ? SupportType::CENTER : SupportType::NONE,
			default => SupportType::NONE
		};
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$this->facing = $face === Facing::DOWN ? Facing::DOWN : Facing::opposite($face);

		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player !== null){
			$tile = $this->position->getWorld()->getTile($this->position);
			if($tile instanceof TileHopper){ //TODO: find a way to have inventories open on click without this boilerplate in every block
				$player->setCurrentWindow($tile->getInventory());
			}
			return true;
		}
		return false;
	}

	public function onPostPlace() : void{
		$this->wakeUp();
	}

	/**
	 * Schedules a transfer attempt as soon as the cooldown allows. Hoppers are not ticked while idle: they are woken up
	 * when an inventory they use changes, when an item lands above them, when a neighbour changes or when they are
	 * unlocked.
	 */
	public function wakeUp() : void{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		if(self::$updating === World::blockHash($x, $y, $z) || $world->isBlockUpdateScheduled($x, $y, $z)){
			return;
		}
		$tile = $world->getTileAt($x, $y, $z);
		$delay = $tile instanceof TileHopper ? $tile->getTransferCooldown() : 0;
		$world->scheduleDelayedBlockUpdate($this->position, max(1, $delay));
	}

	/** Block hash of the hopper currently moving items: it decides by itself when to look again. */
	private static ?int $updating = null;

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if(!$tile instanceof TileHopper || $this->powered){
			return; //locked hoppers sleep until they are unpowered
		}
		$cooldown = $tile->getTransferCooldown();
		if($cooldown > 0){
			//woken up early (e.g. a duplicate scheduled update)
			$world->scheduleDelayedBlockUpdate($this->position, $cooldown);
			return;
		}

		self::$updating = World::blockHash($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ());
		try{
			//Bedrock: pushes are processed before pulls, in the same tick
			$moved = $this->push($tile);
			if($this->pull($tile)){
				$moved = true;
			}
		}finally{
			self::$updating = null;
		}
		if($moved){
			$tile->setNextTransferTick($world->getServer()->getTick() + ItemTransfer::HOPPER_COOLDOWN_TICKS);
			if(!ItemTransfer::isEmpty($tile->getInventory()) || $this->hasSomethingToPull()){
				$world->scheduleDelayedBlockUpdate($this->position, ItemTransfer::HOPPER_COOLDOWN_TICKS);
			}
		}
	}

	/**
	 * Returns whether there may be something to pull from above (an idle empty hopper is woken up when that changes).
	 */
	private function hasSomethingToPull() : bool{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$source = ItemTransfer::getContainerAt($world, $x, $y + 1, $z);
		if($source !== null){
			return !ItemTransfer::isEmpty($source->getInventory());
		}
		if(ItemTransfer::isBlockTypeAt($world, $x, $y + 1, $z, BlockTypeIds::COMPOSTER)){
			return true;
		}
		$minecart = ItemTransfer::getEntityContainerAt($world, $x, $y + 1, $z);
		if($minecart !== null){
			return !ItemTransfer::isEmpty($minecart->getInventory());
		}
		foreach($world->getNearbyEntities($this->getCollectionArea()) as $entity){
			if($entity instanceof ItemEntity && !$entity->isFlaggedForDespawn()){
				return true;
			}
		}
		return false;
	}

	/**
	 * Area in which item entities are collected: the bowl and the whole block space above the hopper.
	 */
	public function getCollectionArea() : AxisAlignedBB{
		return new AxisAlignedBB(
			$this->position->x, $this->position->y + 0.5, $this->position->z,
			$this->position->x + 1, $this->position->y + 2, $this->position->z + 1
		);
	}

	/**
	 * Moves one item into the container the hopper faces.
	 */
	private function push(TileHopper $tile) : bool{
		$inventory = $tile->getInventory();
		[$dx, $dy, $dz] = Facing::OFFSET[$this->facing];
		$world = $this->position->getWorld();
		$tx = $this->position->getFloorX() + $dx;
		$ty = $this->position->getFloorY() + $dy;
		$tz = $this->position->getFloorZ() + $dz;
		if(!ItemTransfer::canReceiveItems($world, $tx, $ty, $tz)){
			//a chest or hopper minecart in front
			$minecart = ItemTransfer::getEntityContainerAt($world, $tx, $ty, $tz);
			if($minecart === null){
				return false;
			}
			foreach($inventory->getContents() as $slot => $item){
				if(ItemTransfer::insertOneIntoEntity($minecart, $item, fn(Inventory $destination, int $destinationSlot, Item $moved) : bool => $this->allowInventoryTransfer($inventory, $slot, $destination, $destinationSlot, $moved))){
					$inventory->setItem($slot, $item->setCount($item->getCount() - 1));
					return true;
				}
			}
			return false;
		}
		$targetFace = Facing::opposite($this->facing);
		foreach($inventory->getContents() as $slot => $item){
			if(ItemTransfer::insertOne($world, $tx, $ty, $tz, $targetFace, $item, fn(Inventory $destination, int $destinationSlot, Item $moved) : bool => $this->allowInventoryTransfer($inventory, $slot, $destination, $destinationSlot, $moved))){
				$inventory->setItem($slot, $item->setCount($item->getCount() - 1));
				return true;
			}
		}
		return false;
	}

	/**
	 * Takes one item from the container above, or collects an item entity above the hopper.
	 */
	private function pull(TileHopper $tile) : bool{
		$inventory = $tile->getInventory();
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$hopperSlots = ItemTransfer::getInsertSlots($tile, $inventory, Facing::UP, VanillaItems::AIR());

		$source = ItemTransfer::getContainerAt($world, $x, $y + 1, $z);
		if($source !== null){
			if($source instanceof ItemTransferContainer && $source->getKind() === ItemTransferContainer::JUKEBOX && $world->isBlockPowered($this->position)){
				//a playing jukebox powers (locks) the hopper below, but this hopper may not have seen the change yet
				return false;
			}
			$sourceInventory = $source->getInventory();
			foreach(ItemTransfer::getExtractSlots($source, $sourceInventory, Facing::DOWN) as $slot){
				$item = $sourceInventory->getItem($slot);
				if(!$item->isNull() && ItemTransfer::addOneToSlots($inventory, $hopperSlots, $item, fn(Inventory $destination, int $destinationSlot, Item $moved) : bool => $this->allowInventoryTransfer($sourceInventory, $slot, $destination, $destinationSlot, $moved))){
					$sourceInventory->setItem($slot, $item->setCount($item->getCount() - 1));
					return true;
				}
			}
			return false;
		}

		$above = ItemTransfer::isBlockTypeAt($world, $x, $y + 1, $z, BlockTypeIds::COMPOSTER) ? $world->getBlockAt($x, $y + 1, $z) : null;
		if($above instanceof Composter){
			$boneMeal = $inventory->canAddItem(VanillaItems::BONE_MEAL()) ? ItemTransfer::extractFromComposter($above) : null;
			if($boneMeal !== null){
				$inventory->addItem($boneMeal);
				return true;
			}
			return false;
		}

		$minecart = ItemTransfer::getEntityContainerAt($world, $x, $y + 1, $z);
		if($minecart !== null){
			//a chest or hopper minecart on a rail above
			$sourceInventory = $minecart->getInventory();
			foreach($sourceInventory->getContents() as $slot => $item){
				if(ItemTransfer::addOneToSlots($inventory, $hopperSlots, $item, fn(Inventory $destination, int $destinationSlot, Item $moved) : bool => $this->allowInventoryTransfer($sourceInventory, $slot, $destination, $destinationSlot, $moved))){
					$sourceInventory->setItem($slot, $item->setCount($item->getCount() - 1));
					return true;
				}
			}
			return false;
		}

		return $this->collectItemEntity($inventory);
	}

	/**
	 * Called only for real inventory-to-inventory moves. A cancelled transfer
	 * must not modify either inventory or consume the hopper cooldown.
	 */
	private function allowInventoryTransfer(Inventory $source, int $sourceSlot, Inventory $destination, int $destinationSlot, Item $item) : bool{
		if(!HopperTransferItemEvent::hasHandlers()){
			return true;
		}
		$event = new HopperTransferItemEvent($this, $source, $sourceSlot, $destination, $destinationSlot, $item);
		$event->call();
		return !$event->isCancelled();
	}

	private function collectItemEntity(Inventory $inventory) : bool{
		$world = $this->position->getWorld();
		$bb = $this->getCollectionArea();
		foreach($world->getNearbyEntities($bb) as $entity){
			if(!$entity instanceof ItemEntity || $entity->isFlaggedForDespawn() || $entity->isClosed()){
				continue;
			}
			$pos = $entity->getPosition();
			if(!$bb->isVectorInside($pos)){
				continue;
			}
			$stack = $entity->getItem();
			$count = min($stack->getCount(), $inventory->getAddableItemQuantity($stack));
			if($count <= 0){
				continue;
			}

			$ev = new BlockItemPickupEvent($this, $entity, (clone $stack)->setCount($count), $inventory);
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

	/**
	 * A powered hopper is locked (toggle_bit).
	 */
	public function onNearbyBlockChange() : void{
		$world = $this->position->getWorld();
		$powered = $world->isBlockPowered($this->position);
		if($powered !== $this->powered){
			$this->powered = $powered;
			$world->setBlock($this->position, $this);
		}
		if(!$powered){
			//a container may have been placed above or in front
			$this->wakeUp();
		}
	}
}
