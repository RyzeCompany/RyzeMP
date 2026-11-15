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

use pocketmine\block\inventory\StructureBlockInventory;
use pocketmine\block\tile\StructureBlock as TileStructureBlock;
use pocketmine\block\utils\GameMasterBlocks;
use pocketmine\block\utils\StructureBlockType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;

/**
 * Structure block (https://minecraft.wiki/w/Structure_Block). The mode is the block state; the other settings live in
 * the block entity ({@link TileStructureBlock}), which saves and loads the structure templates of the world. Only
 * operators in creative mode can place it or open its screen. In save and load mode, a redstone signal turning on
 * saves or loads the structure. Never drops.
 */
class StructureBlock extends Opaque{
	protected StructureBlockType $mode = StructureBlockType::SAVE;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->enum($this->mode);
	}

	public function getMode() : StructureBlockType{ return $this->mode; }

	/** @return $this */
	public function setMode(StructureBlockType $mode) : self{
		$this->mode = $mode;
		return $this;
	}

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();
		//the block entity's "data" follows the block state, like vanilla keeps the two in sync
		$tile = $this->position->getWorld()->getTile($this->position);
		if($tile instanceof TileStructureBlock){
			$tile->setMode($this->mode);
		}
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null && !GameMasterBlocks::canUse($player)){
			return false;
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player === null || !GameMasterBlocks::canUse($player)){
			return false;
		}
		if($this->position->getWorld()->getTile($this->position) instanceof TileStructureBlock){
			$player->setCurrentWindow(new StructureBlockInventory($this->position));
		}
		return true;
	}

	public function onNearbyBlockChange() : void{
		$world = $this->position->getWorld();
		if($world->getRedstone() === null){
			return;
		}
		$tile = $world->getTile($this->position);
		if($tile instanceof TileStructureBlock){
			$tile->onRedstoneSignal($world->isBlockPowered($this->position));
		}
	}

	/**
	 * Places the next part of a structure being loaded over several ticks.
	 */
	public function onScheduledUpdate() : void{
		$tile = $this->position->getWorld()->getTile($this->position);
		if($tile instanceof TileStructureBlock){
			$tile->continuePlacement();
		}
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	public function isAffectedBySilkTouch() : bool{
		return false;
	}
}
