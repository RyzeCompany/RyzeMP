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

use pocketmine\block\utils\StaticSupportTrait;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use function mt_rand;

final class ChorusPlant extends Flowable{
	use StaticSupportTrait {
		onNearbyBlockChange as onNearbyBlockChangeSupport;
	}

	/**
	 * facing => facing. Since Bedrock 1.26.60 these are block states (minecraft:connection_*), so they are stored
	 * with the block and recomputed when a neighbour changes.
	 *
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	protected array $connections = [];

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facingFlags($this->connections);
	}

	public function hasConnection(int $facing) : bool{
		return isset($this->connections[$facing]);
	}

	/** @return $this */
	public function setConnection(int $facing, bool $connected) : self{
		if($connected){
			$this->connections[$facing] = $facing;
		}else{
			unset($this->connections[$facing]);
		}
		$this->collisionBoxes = null;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		$bb = AxisAlignedBB::one();
		foreach(Facing::ALL as $facing){
			if(!isset($this->connections[$facing])){
				$bb->trim($facing, 2 / 16);
			}
		}

		return [$bb];
	}

	/**
	 * Recomputes the connections from the neighbouring blocks. Returns true if anything changed.
	 */
	private function recalculateConnections() : bool{
		$changed = false;
		foreach(Facing::ALL as $facing){
			$block = $this->getSide($facing);
			$connect = match($block->getTypeId()){
				BlockTypeIds::END_STONE, BlockTypeIds::CHORUS_FLOWER, $this->getTypeId() => true,
				default => false
			};
			if($connect !== isset($this->connections[$facing])){
				$this->setConnection($facing, $connect);
				$changed = true;
			}
		}
		return $changed;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$this->recalculateConnections();
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onNearbyBlockChange() : void{
		if($this->recalculateConnections()){
			$this->position->getWorld()->setBlock($this->position, $this);
		}
		$this->onNearbyBlockChangeSupport();
	}

	private function canBeSupportedBy(Block $block) : bool{
		return $block->hasSameTypeId($this) || $block->getTypeId() === BlockTypeIds::END_STONE;
	}

	private function canBeSupportedAt(Block $block) : bool{
		$position = $block->position;
		$world = $position->getWorld();

		$down = $world->getBlock($position->down());
		$verticalAir = $down->getTypeId() === BlockTypeIds::AIR || $world->getBlock($position->up())->getTypeId() === BlockTypeIds::AIR;

		foreach($position->sidesAroundAxis(Axis::Y) as $sidePosition){
			$block = $world->getBlock($sidePosition);

			if($block->getTypeId() === BlockTypeIds::CHORUS_PLANT){
				if(!$verticalAir){
					return false;
				}

				if($this->canBeSupportedBy($block->getSide(Facing::DOWN))){
					return true;
				}
			}
		}

		return $this->canBeSupportedBy($down);
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		if(mt_rand(0, 1) === 1){
			return [VanillaItems::CHORUS_FRUIT()];
		}

		return [];
	}
}
