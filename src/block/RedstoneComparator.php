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
use pocketmine\block\tile\ComparatorReadableContainer;
use pocketmine\block\utils\AnalogRedstoneSignalEmitter;
use pocketmine\block\utils\AnalogRedstoneSignalEmitterTrait;
use pocketmine\block\utils\ComparatorReadable;
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
use function assert;
use function max;

class RedstoneComparator extends Flowable implements AnalogRedstoneSignalEmitter, PoweredByRedstone, HorizontalFacing{
	use HorizontalFacingTrait;
	use AnalogRedstoneSignalEmitterTrait;
	use PoweredByRedstoneTrait;
	use StaticSupportTrait;

	protected bool $isSubtractMode = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacing($this->facing);
		$w->bool($this->isSubtractMode);
		$w->bool($this->powered);
	}

	public function readStateFromWorld() : Block{
		parent::readStateFromWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		if($tile instanceof Comparator){
			$this->signalStrength = $tile->getSignalStrength();
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

	public function isRedstoneSource() : bool{
		return true;
	}

	/**
	 * The comparator reads the block on its facing side and outputs on the opposite side.
	 */
	public function getOutputFace() : int{
		return Facing::opposite($this->facing);
	}

	public function getWeakPower(int $face) : int{
		return $face === $this->getOutputFace() ? $this->signalStrength : 0;
	}

	public function getStrongPower(int $face) : int{
		return $this->getWeakPower($face);
	}

	/**
	 * Computes the output of a comparator from its rear and (highest) side inputs.
	 */
	public static function calculateOutput(int $rear, int $side, bool $subtract) : int{
		if($subtract){
			return max(0, $rear - $side);
		}
		return $rear >= $side ? $rear : 0;
	}

	/**
	 * Returns the comparator output of a block (container fullness, cake slices...), or null if it has none.
	 *
	 * @param int|null $comparatorFacing facing of the reading comparator, for the blocks that are only read from one side
	 */
	public static function readComparatorOutput(Block $block, ?int $comparatorFacing = null) : ?int{
		if($block instanceof Shelf && $comparatorFacing !== null){
			return $block->getComparatorOutputFor($comparatorFacing);
		}
		if($block instanceof ComparatorReadable){
			return $block->getComparatorOutput();
		}
		$pos = $block->getPosition();
		if($pos->isValid()){
			$tile = $pos->getWorld()->getTile($pos);
			if($tile instanceof ComparatorReadableContainer){
				return $tile->getComparatorOutput();
			}
		}
		return null;
	}

	private function getRearInput(RedstoneEngine $redstone) : int{
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$power = $redstone->getPowerFrom($x, $y, $z, $this->facing);
		$back = $this->getSide($this->facing);
		$output = self::readComparatorOutput($back, $this->facing);
		if($output !== null){
			return $output;
		}
		if($power < 15 && $back->isRedstoneConductor()){
			//containers can be read through a solid block
			$output = self::readComparatorOutput($back->getSide($this->facing), $this->facing);
			if($output !== null){
				return max($power, $output);
			}
		}
		return $power;
	}

	/**
	 * Side inputs only come from wire (its power level) and from power sources pointing into the side.
	 */
	private function getSideInput() : int{
		$max = 0;
		foreach([Facing::rotateY($this->facing, true), Facing::rotateY($this->facing, false)] as $side){
			$block = $this->getSide($side);
			if($block instanceof RedstoneWire){
				$power = $block->getOutputSignalStrength();
			}elseif($block->isRedstoneSource()){
				$power = $block->getWeakPower(Facing::opposite($side));
			}else{
				continue;
			}
			if($power > $max){
				$max = $power;
			}
		}
		return $max;
	}

	private function computeOutput(RedstoneEngine $redstone) : int{
		return self::calculateOutput($this->getRearInput($redstone), $this->getSideInput(), $this->isSubtractMode);
	}

	public function onNearbyBlockChange() : void{
		$world = $this->position->getWorld();
		if(!$this->canBeSupportedAt($this)){
			$world->useBreakOn($this->position);
			return;
		}
		$redstone = $world->getRedstone();
		if($redstone === null){
			return;
		}
		$output = $this->computeOutput($redstone);
		$pos = $this->position;
		if(($output !== $this->signalStrength || ($output > 0) !== $this->powered) && !$world->isBlockUpdateScheduled($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){
			$world->scheduleDelayedBlockUpdate($pos, RedstoneEngine::REDSTONE_TICK);
		}
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$redstone = $world->getRedstone();
		if($redstone === null){
			return;
		}
		$output = $this->computeOutput($redstone);
		if($output !== $this->signalStrength || ($output > 0) !== $this->powered){
			$this->signalStrength = $output;
			$this->powered = $output > 0;
			$world->setBlock($this->position, $this);
			$redstone->updateAroundSide($this->position, $this->getOutputFace());
		}
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$result = parent::onBreak($item, $player, $returnedItems);
		if($this->signalStrength > 0){
			$this->position->getWorld()->getRedstone()?->updateAroundSide($this->position, $this->getOutputFace());
		}
		return $result;
	}

	public function pausesScheduledUpdatesOutsideTickingChunks() : bool{
		return true; //redstone components only update in ticking chunks, like vanilla block ticks
	}
}
