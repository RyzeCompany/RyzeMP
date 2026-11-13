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

use pocketmine\block\tile\Comparator;
use pocketmine\block\utils\AnalogRedstoneSignalEmitter;
use pocketmine\block\utils\AnalogRedstoneSignalEmitterTrait;
use pocketmine\block\utils\HorizontalFacing;
use pocketmine\block\utils\HorizontalFacingTrait;
use pocketmine\block\utils\PoweredByRedstone;
use pocketmine\block\utils\PoweredByRedstoneTrait;
use pocketmine\block\utils\RedstonePowerSource;
use pocketmine\block\utils\RedstoneSignal;
use pocketmine\block\utils\RedstoneTickingComponent;
use pocketmine\block\utils\StaticSupportTrait;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\redstone\ComparatorInput;
use function assert;
use function max;
use function min;

class RedstoneComparator extends Flowable implements AnalogRedstoneSignalEmitter, PoweredByRedstone, HorizontalFacing, RedstonePowerSource, RedstoneTickingComponent{
	use HorizontalFacingTrait;
	use AnalogRedstoneSignalEmitterTrait;
	use PoweredByRedstoneTrait;
	use StaticSupportTrait;

	protected bool $isSubtractMode = false;

	public function getWeakPower(int $face) : int{
		return $face === $this->facing ? $this->signalStrength : 0;
	}

	public function getStrongPower(int $face) : int{
		return $this->getWeakPower($face);
	}

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacing($this->facing);
		$w->bool($this->isSubtractMode);
		$w->bool($this->powered);
	}

	public function readStateFromWorld() : Block{
		parent::readStateFromWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		if($tile instanceof Comparator){
			$this->signalStrength = max(0, min(15, $tile->getSignalStrength()));
		}

		return $this;
	}

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		assert($tile instanceof Comparator);
		$tile->setSignalStrength($this->signalStrength);
	}

	public function isSubtractMode() : bool{
		return $this->isSubtractMode;
	}

	/** @return $this */
	public function setSubtractMode(bool $isSubtractMode) : self{
		$this->isSubtractMode = $isSubtractMode;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->trim(Facing::UP, 7 / 8)];
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			$this->facing = Facing::opposite($player->getHorizontalFacing());
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$this->isSubtractMode = !$this->isSubtractMode;
		$this->position->getWorld()->setBlock($this->position, $this);
		return true;
	}

	private function canBeSupportedAt(Block $block) : bool{
		return $block->getAdjacentSupportType(Facing::DOWN) !== SupportType::NONE;
	}

	private function calculateOutput() : int{
		$rear = Facing::opposite($this->facing);
		$input = RedstoneSignal::getPowerFromSide($this, $rear);
		$world = $this->position->getWorld();
		$pos = $this->position->getSide($rear);
		$block = RedstoneSignal::getBlockAt($world, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
		if($block !== null){
			$override = ComparatorInput::getOverride($block);
			if($override !== null){
				$input = $override;
			}elseif($input < 15 && RedstoneSignal::isConductor($block)){
				$pos = $pos->getSide($rear);
				$block = RedstoneSignal::getBlockAt($world, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
				if($block !== null && ($override = ComparatorInput::getOverride($block)) !== null){
					$input = $override;
				}
			}
		}
		$sidePower = 0;
		foreach(Facing::HORIZONTAL as $side){
			if($side === $rear || $side === $this->facing){
				continue;
			}
			$pos = $this->position->getSide($side);
			$source = RedstoneSignal::getBlockAt($world, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
			// Lateral inputs accept dust, redstone blocks and diodes, not arbitrary powered supports.
			if($source instanceof RedstoneWire){
				$sidePower = max($sidePower, $source->getOutputSignalStrength());
			}elseif($source instanceof Redstone || $source instanceof self || $source instanceof RedstoneRepeater){
				$sidePower = max($sidePower, $source->getWeakPower(Facing::opposite($side)));
			}
		}
		return $this->isSubtractMode ? max(0, $input - $sidePower) : ($input >= $sidePower ? $input : 0);
	}

	public function onNearbyBlockChange() : void{
		if(!$this->canBeSupportedAt($this)){
			$this->position->getWorld()->useBreakOn($this->position);
		}elseif($this->signalStrength !== $this->calculateOutput() || $this->powered !== ($this->signalStrength > 0)){
			$this->position->getWorld()->getRedstoneCircuit()->schedule($this, 2, 0);
		}
	}

	public function onRedstoneUpdate(int $targetPower) : void{
		if(!$this->canBeSupportedAt($this)){
			$this->position->getWorld()->useBreakOn($this->position);
			return;
		}
		$power = RedstoneSignal::filterChange($this, $this->signalStrength, $this->calculateOutput());
		if($power !== $this->signalStrength || $this->powered !== ($power > 0)){
			$this->signalStrength = $power;
			$this->powered = $power > 0;
			$this->position->getWorld()->setBlock($this->position, $this);
		}
	}
}

