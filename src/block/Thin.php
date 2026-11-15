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

use pocketmine\block\utils\HorizontalConnections;
use pocketmine\block\utils\HorizontalConnectionsTrait;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use function count;

/**
 * Thin blocks behave like glass panes. They connect to full-cube blocks horizontally adjacent to them if possible.
 */
class Thin extends Transparent implements HorizontalConnections{
	use HorizontalConnectionsTrait;

	/**
	 * Since 1.26.50 the connections are real block states (minecraft:connection_*), see Fence.
	 */
	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacingFlags($this->connections);
	}

	protected function canConnectTo(Block $block, int $facing) : bool{
		return $block instanceof Thin || $block instanceof Wall || $block->getSupportType(Facing::opposite($facing)) === SupportType::FULL;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$this->recalculateConnections();
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onNearbyBlockChange() : void{
		if($this->recalculateConnections()){
			$this->position->getWorld()->setBlock($this->position, $this);
		}
	}

	protected function recalculateCollisionBoxes() : array{
		$inset = 7 / 16;

		$bbs = [];

		if(isset($this->connections[Facing::WEST]) || isset($this->connections[Facing::EAST])){
			$bb = AxisAlignedBB::one()->squash(Axis::Z, $inset);

			if(!isset($this->connections[Facing::WEST])){
				$bb->trim(Facing::WEST, $inset);
			}elseif(!isset($this->connections[Facing::EAST])){
				$bb->trim(Facing::EAST, $inset);
			}
			$bbs[] = $bb;
		}

		if(isset($this->connections[Facing::NORTH]) || isset($this->connections[Facing::SOUTH])){
			$bb = AxisAlignedBB::one()->squash(Axis::X, $inset);

			if(!isset($this->connections[Facing::NORTH])){
				$bb->trim(Facing::NORTH, $inset);
			}elseif(!isset($this->connections[Facing::SOUTH])){
				$bb->trim(Facing::SOUTH, $inset);
			}
			$bbs[] = $bb;
		}

		if(count($bbs) === 0){
			//centre post AABB (only needed if not connected on any axis - other BBs overlapping will do this if any connections are made)
			return [
				AxisAlignedBB::one()->contract($inset, 0, $inset)
			];
		}

		return $bbs;
	}

	/**
	 * The post in the middle supports torches, candles and lanterns on top and hanging lanterns below (Java
	 * canSupportCenter).
	 */
	public function getSupportType(int $facing) : SupportType{
		return Facing::axis($facing) === Axis::Y ? SupportType::CENTER : SupportType::NONE;
	}
}
