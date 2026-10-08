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
use pocketmine\block\utils\HopperTransferHelper;
use pocketmine\block\Hopper as HopperBlock;
use pocketmine\entity\object\ItemEntity;
use pocketmine\inventory\Inventory;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\World;

class Hopper extends Spawnable implements Container, Nameable{

	use ContainerTrait;
	use NameableTrait;

	private const TAG_TRANSFER_COOLDOWN = "TransferCooldown";
	public const TRANSFER_INTERVAL_TICKS = 8;

	private HopperInventory $inventory;
	private int $transferCooldown = 0;

	public function __construct(World $world, Vector3 $pos){
		parent::__construct($world, $pos);
		$this->inventory = new HopperInventory($this->position);
		$world->scheduleDelayedBlockUpdate($pos, self::TRANSFER_INTERVAL_TICKS);
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->loadItems($nbt);
		$this->loadName($nbt);

		$this->transferCooldown = $nbt->getInt(self::TAG_TRANSFER_COOLDOWN, 0);
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->saveItems($nbt);
		$this->saveName($nbt);

		$nbt->setInt(self::TAG_TRANSFER_COOLDOWN, $this->transferCooldown);
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

	/**
	 * Runs on the server thread only. At most one push and one pull per interval.
	 * Container inventory rules are implemented by HopperTransferItemEvent listeners.
	 */
	public function tickHopper(HopperBlock $block) : void{
		if($this->closed || $block->isPowered()){
			return;
		}
		if($this->transferCooldown > 0){
			$this->transferCooldown = max(0, $this->transferCooldown - self::TRANSFER_INTERVAL_TICKS);
		}
		if($this->transferCooldown > 0){
			return;
		}

		$world = $this->position->getWorld();
		$pushed = false;
		$target = $block->getSide($block->getFacing())->getPosition();
		if($world->isChunkLoaded($target->getFloorX() >> 4, $target->getFloorZ() >> 4)){
			$targetTile = $world->getTile($target);
			if($targetTile instanceof Container){
				$pushed = HopperTransferHelper::transfer($block, $this->inventory, $targetTile->getInventory());
			}
		}
		$pulled = false;
		$above = $block->getSide(Facing::UP)->getPosition();
		if($world->isChunkLoaded($above->getFloorX() >> 4, $above->getFloorZ() >> 4)){
			$aboveTile = $world->getTile($above);
			if($aboveTile instanceof Container){
				$pulled = HopperTransferHelper::transfer($block, $aboveTile->getInventory(), $this->inventory);
			}else{
				$pulled = $this->pickupNearbyItems();
			}
		}
		if($pushed || $pulled){
			$this->transferCooldown = self::TRANSFER_INTERVAL_TICKS;
		}
	}

	private function pickupNearbyItems() : bool{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$box = new AxisAlignedBB($x, $y, $z, $x + 1, $y + 1.5, $z + 1);
		foreach($world->getNearbyEntities($box) as $entity){
			if(!$entity instanceof ItemEntity || $entity->isFlaggedForDespawn()){
				continue;
			}
			$item = $entity->getItem();
			if($item->isNull() || $this->inventory->getAddableItemQuantity($item) < 1){
				continue;
			}
			$one = clone $item;
			$one->setCount(1);
			if($this->inventory->addItem($one) !== []){
				continue;
			}
			if($item->getCount() <= 1){
				$entity->flagForDespawn();
			}else{
				$entity->setStackSize($item->getCount() - 1);
			}
			return true;
		}
		return false;
	}
}
