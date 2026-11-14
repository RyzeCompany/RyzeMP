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

use pocketmine\block\inventory\ChestInventory;
use pocketmine\block\inventory\DoubleChestInventory;
use pocketmine\inventory\CallbackInventoryListener;
use pocketmine\inventory\Inventory;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function abs;

class Chest extends Spawnable implements ComparatorReadableContainer, Nameable{
	use NameableTrait {
		addAdditionalSpawnData as addNameSpawnData;
	}
	use ComparatorReadableContainerTrait;
	use ContainerTrait {
		onBlockDestroyedHook as containerTraitBlockDestroyedHook;
	}
	use LootTableContainerTrait;

	public const TAG_PAIRX = "pairx";
	public const TAG_PAIRZ = "pairz";
	public const TAG_PAIR_LEAD = "pairlead";

	protected ChestInventory $inventory;
	protected ?DoubleChestInventory $doubleInventory = null;

	private ?int $pairX = null;
	private ?int $pairZ = null;

	public function __construct(World $world, Vector3 $pos){
		parent::__construct($world, $pos);
		$this->inventory = new ChestInventory($this->position);
		$this->addComparatorInventoryListener();
		$this->addPairComparatorInventoryListener();
	}

	/**
	 * A comparator at either half of a double chest reads both halves, so it must also be updated when the other
	 * half's contents change.
	 */
	private function addPairComparatorInventoryListener() : void{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$this->inventory->getListeners()->add(CallbackInventoryListener::onAnyChange(
			static function(Inventory $unused) use ($world, $x, $y, $z) : void{
				if($world->isLoaded() && ($tile = $world->getTileAt($x, $y, $z)) instanceof Chest && $tile->pairX !== null && $tile->pairZ !== null){
					$world->getRedstone()?->updateComparatorsReading($tile->pairX, $y, $tile->pairZ);
				}
			}
		));
	}

	/**
	 * Updates the comparators reading this chest and the chest at the given position after the pairing changed (the
	 * output of both halves depends on the size of the inventory).
	 */
	private function updatePairComparators(?int $otherX, ?int $otherZ) : void{
		$world = $this->position->getWorld();
		if(!$world->isLoaded() || ($redstone = $world->getRedstone()) === null){
			return;
		}
		$y = $this->position->getFloorY();
		$redstone->updateComparatorsReading($this->position->getFloorX(), $y, $this->position->getFloorZ());
		if($otherX !== null && $otherZ !== null){
			$redstone->updateComparatorsReading($otherX, $y, $otherZ);
		}
	}

	public function readSaveData(CompoundTag $nbt) : void{
		if(($pairXTag = $nbt->getTag(self::TAG_PAIRX)) instanceof IntTag && ($pairZTag = $nbt->getTag(self::TAG_PAIRZ)) instanceof IntTag){
			$pairX = $pairXTag->getValue();
			$pairZ = $pairZTag->getValue();
			if(
				($this->position->x === $pairX && abs($this->position->z - $pairZ) === 1) ||
				($this->position->z === $pairZ && abs($this->position->x - $pairX) === 1)
			){
				$this->pairX = $pairX;
				$this->pairZ = $pairZ;
			}else{
				$this->pairX = $this->pairZ = null;
			}
		}
		$this->loadName($nbt);
		$this->loadItems($nbt);
		$this->loadLootTable($nbt);
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		if($this->isPaired()){
			$nbt->setInt(self::TAG_PAIRX, $this->pairX);
			$nbt->setInt(self::TAG_PAIRZ, $this->pairZ);
		}
		$this->saveName($nbt);
		$this->saveItems($nbt);
		$this->saveLootTable($nbt);
	}

	public function getCleanedNBT() : ?CompoundTag{
		$tag = parent::getCleanedNBT();
		if($tag !== null){
			//TODO: replace this with a purpose flag on writeSaveData()
			$tag->removeTag(self::TAG_PAIRX, self::TAG_PAIRZ);
		}
		return $tag;
	}

	public function close() : void{
		if(!$this->closed){
			$this->inventory->removeAllViewers();

			if($this->doubleInventory !== null){
				if($this->isPaired() && $this->position->getWorld()->isChunkLoaded($this->pairX >> Chunk::COORD_BIT_SIZE, $this->pairZ >> Chunk::COORD_BIT_SIZE)){
					$this->doubleInventory->removeAllViewers();
					if(($pair = $this->getPair()) !== null){
						$pair->doubleInventory = null;
					}
				}
				$this->doubleInventory = null;
			}

			parent::close();
		}
	}

	protected function onBlockDestroyedHook() : void{
		$this->unpackLootTable();
		$this->unpair();
		$this->containerTraitBlockDestroyedHook();
	}

	/**
	 * Returns the inventory of the chest (both halves for a double chest), filling it from its loot table first if it
	 * has one (see {@link LootTableContainerTrait}).
	 */
	public function getInventory() : ChestInventory|DoubleChestInventory{
		if($this->isPaired() && $this->doubleInventory === null){
			$this->checkPairing();
		}
		$this->unpackLootTable();
		if($this->doubleInventory !== null){
			$this->getPair()?->unpackLootTable();
		}
		return $this->doubleInventory instanceof DoubleChestInventory ? $this->doubleInventory : $this->inventory;
	}

	public function getRealInventory() : ChestInventory{
		return $this->inventory;
	}

	protected function checkPairing() : void{
		if($this->isPaired() && !$this->position->getWorld()->isInLoadedTerrain(new Vector3($this->pairX, $this->position->y, $this->pairZ))){
			//paired to a tile in an unloaded chunk
			$this->doubleInventory = null;

		}elseif(($pair = $this->getPair()) instanceof Chest){
			if(!$pair->isPaired()){
				$pair->createPair($this);
				$pair->checkPairing();
			}
			if($this->doubleInventory === null){
				if($pair->doubleInventory !== null){
					$this->doubleInventory = $pair->doubleInventory;
				}else{
					if(($pair->position->x + ($pair->position->z << 15)) > ($this->position->x + ($this->position->z << 15))){ //Order them correctly
						$this->doubleInventory = $pair->doubleInventory = new DoubleChestInventory($pair->inventory, $this->inventory);
					}else{
						$this->doubleInventory = $pair->doubleInventory = new DoubleChestInventory($this->inventory, $pair->inventory);
					}
				}
			}
		}else{
			$this->doubleInventory = null;
			$this->pairX = $this->pairZ = null;
		}
	}

	public function getDefaultName() : string{
		return "Chest";
	}

	public function isPaired() : bool{
		return $this->pairX !== null && $this->pairZ !== null;
	}

	public function getPair() : ?Chest{
		if($this->isPaired()){
			$tile = $this->position->getWorld()->getTileAt($this->pairX, $this->position->y, $this->pairZ);
			if($tile instanceof Chest){
				return $tile;
			}
		}

		return null;
	}

	public function pairWith(Chest $tile) : bool{
		if($this->isPaired() || $tile->isPaired()){
			return false;
		}

		$this->createPair($tile);

		$this->clearSpawnCompoundCache();
		$tile->clearSpawnCompoundCache();
		$this->checkPairing();
		$this->updatePairComparators($this->pairX, $this->pairZ);

		return true;
	}

	private function createPair(Chest $tile) : void{
		$this->pairX = $tile->getPosition()->x;
		$this->pairZ = $tile->getPosition()->z;

		$tile->pairX = $this->getPosition()->x;
		$tile->pairZ = $this->getPosition()->z;
	}

	public function unpair() : bool{
		if(!$this->isPaired()){
			return false;
		}

		$tile = $this->getPair();
		$oldPairX = $this->pairX;
		$oldPairZ = $this->pairZ;
		$this->pairX = $this->pairZ = null;

		$this->clearSpawnCompoundCache();

		if($tile instanceof Chest){
			$tile->pairX = $tile->pairZ = null;
			$tile->checkPairing();
			$tile->clearSpawnCompoundCache();
		}
		$this->checkPairing();
		$this->updatePairComparators($oldPairX, $oldPairZ);

		return true;
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		if($this->isPaired()){
			$nbt->setInt(self::TAG_PAIRX, $this->pairX);
			$nbt->setInt(self::TAG_PAIRZ, $this->pairZ);
		}

		$this->addNameSpawnData($nbt);
	}
}
