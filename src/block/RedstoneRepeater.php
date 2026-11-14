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
use pocketmine\block\utils\StaticSupportTrait;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\redstone\RedstoneEngine;

class RedstoneRepeater extends Flowable implements PoweredByRedstone, HorizontalFacing{
	use HorizontalFacingTrait;
	use PoweredByRedstoneTrait;
	use StaticSupportTrait;

	public const MIN_DELAY = 1;
	public const MAX_DELAY = 4;

	protected int $delay = self::MIN_DELAY;

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

	public function isRedstoneSource() : bool{
		return true;
	}

	/**
	 * The repeater takes its input from the block on its facing side and outputs on the opposite side.
	 */
	public function getOutputFace() : int{
		return Facing::opposite($this->facing);
	}

	public function getWeakPower(int $face) : int{
		return $this->powered && $face === $this->getOutputFace() ? 15 : 0;
	}

	public function getStrongPower(int $face) : int{
		return $this->getWeakPower($face);
	}

	public function connectsToRedstoneWire(int $face) : bool{
		return Facing::axis($face) === Facing::axis($this->facing);
	}

	/**
	 * Returns the number of game ticks between an input change and the output change.
	 */
	public function getDelayTicks() : int{
		return $this->delay * RedstoneEngine::REDSTONE_TICK;
	}

	private function isInputPowered(RedstoneEngine $redstone) : bool{
		$pos = $this->position;
		return $redstone->getPowerFrom($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), $this->facing) > 0;
	}

	/**
	 * A repeater is locked when a powered repeater or comparator points into one of its sides.
	 */
	public function isLocked() : bool{
		foreach([Facing::rotateY($this->facing, true), Facing::rotateY($this->facing, false)] as $side){
			$block = $this->getSide($side);
			if(($block instanceof RedstoneRepeater || $block instanceof RedstoneComparator) && $block->getWeakPower(Facing::opposite($side)) > 0){
				return true;
			}
		}
		return false;
	}

	public function onNearbyBlockChange() : void{
		$world = $this->position->getWorld();
		if(!$this->canBeSupportedAt($this)){
			$world->useBreakOn($this->position);
			return;
		}
		$redstone = $world->getRedstone();
		if($redstone === null || $this->isLocked()){
			return;
		}
		$pos = $this->position;
		if($this->isInputPowered($redstone) !== $this->powered && !$world->isBlockUpdateScheduled($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){
			$world->scheduleDelayedBlockUpdate($pos, $this->getDelayTicks());
		}
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$redstone = $world->getRedstone();
		if($redstone === null || $this->isLocked()){
			return;
		}
		$shouldPower = $this->isInputPowered($redstone);
		if($this->powered && !$shouldPower){
			$this->setPoweredAndUpdate(false);
		}elseif(!$this->powered){
			$this->setPoweredAndUpdate(true);
			if(!$shouldPower){
				//pulses shorter than the delay are extended to the delay
				$world->scheduleDelayedBlockUpdate($this->position, $this->getDelayTicks());
			}
		}
	}

	private function setPoweredAndUpdate(bool $powered) : void{
		$this->powered = $powered;
		$world = $this->position->getWorld();
		$world->setBlock($this->position, $this);
		$world->getRedstone()?->updateAroundSide($this->position, $this->getOutputFace());
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$result = parent::onBreak($item, $player, $returnedItems);
		if($this->powered){
			$this->position->getWorld()->getRedstone()?->updateAroundSide($this->position, $this->getOutputFace());
		}
		return $result;
	}

	public function pausesScheduledUpdatesOutsideTickingChunks() : bool{
		return true; //redstone components only update in ticking chunks, like vanilla block ticks
	}
}
