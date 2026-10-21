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

class RedstoneRepeater extends Flowable implements PoweredByRedstone, HorizontalFacing, RedstonePowerSource, RedstoneTickingComponent{
	use HorizontalFacingTrait;
	use PoweredByRedstoneTrait;
	use StaticSupportTrait;

	public const MIN_DELAY = 1;
	public const MAX_DELAY = 4;

	protected int $delay = self::MIN_DELAY;

	public function getWeakPower(int $face) : int{
		return $this->powered && $face === $this->facing ? 15 : 0;
	}

	public function getStrongPower(int $face) : int{
		return $this->getWeakPower($face);
	}

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacing($this->facing);
		$w->boundedIntAuto(self::MIN_DELAY, self::MAX_DELAY, $this->delay);
		$w->bool($this->powered);
	}

	public function getDelay() : int{ return $this->delay; }

	/** @return $this */
	public function setDelay(int $delay) : self{
		if($delay < self::MIN_DELAY || $delay > self::MAX_DELAY){
			throw new \InvalidArgumentException("Delay must be in range " . self::MIN_DELAY . " ... " . self::MAX_DELAY);
		}
		$this->delay = $delay;
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
		if(++$this->delay > self::MAX_DELAY){
			$this->delay = self::MIN_DELAY;
		}
		$this->position->getWorld()->setBlock($this->position, $this);
		return true;
	}

	private function canBeSupportedAt(Block $block) : bool{
		return $block->getAdjacentSupportType(Facing::DOWN) !== SupportType::NONE;
	}

	public function isLocked() : bool{
		foreach(Facing::HORIZONTAL as $side){
			if($side === $this->facing || $side === Facing::opposite($this->facing)){
				continue;
			}
			$pos = $this->position->getSide($side);
			$source = RedstoneSignal::getBlockAt($this->position->getWorld(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
			if(($source instanceof self || $source instanceof RedstoneComparator) && $source->getWeakPower(Facing::opposite($side)) > 0){
				return true;
			}
		}
		return false;
	}

	public function onNearbyBlockChange() : void{
		if(!$this->canBeSupportedAt($this)){
			$this->position->getWorld()->useBreakOn($this->position);
		}elseif(!$this->isLocked()){
			$power = RedstoneSignal::getPowerFromSide($this, Facing::opposite($this->facing)) > 0 ? 15 : 0;
			if($this->powered !== ($power > 0)){
				$this->position->getWorld()->getRedstoneCircuit()->schedule($this, $this->delay * 2, $power);
			}
		}
	}

	public function onRedstoneUpdate(int $targetPower) : void{
		if(!$this->canBeSupportedAt($this)){
			$this->position->getWorld()->useBreakOn($this->position);
			return;
		}
		if($this->isLocked()){
			return;
		}
		if($targetPower === 0 && RedstoneSignal::getPowerFromSide($this, Facing::opposite($this->facing)) > 0){
			return;
		}
		$powered = RedstoneSignal::filterChange($this, $this->powered ? 15 : 0, $targetPower) > 0;
		if($this->powered !== $powered){
			$this->powered = $powered;
			$this->position->getWorld()->setBlock($this->position, $this);
			// Preserve short input pulses for at least the configured delay.
			$this->onNearbyBlockChange();
		}
	}
}

