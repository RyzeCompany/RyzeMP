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

use pocketmine\block\utils\AnyFacing;
use pocketmine\block\utils\AnyFacingTrait;
use pocketmine\block\utils\CopperMaterial;
use pocketmine\block\utils\CopperTrait;
use pocketmine\block\utils\PoweredByRedstoneTrait;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\particle\ElectricSparkParticle;
use pocketmine\world\vibration\GameEvent;

final class LightningRod extends Transparent implements AnyFacing, CopperMaterial{
	use CopperTrait;
	use AnyFacingTrait {
		describeBlockOnlyState as describeFacing;
	}
	use PoweredByRedstoneTrait;

	/** How long the rod stays powered after a strike. */
	public const STRUCK_POWER_TICKS = 8;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$this->describeFacing($w);
		$w->bool($this->powered);
	}

	protected function recalculateCollisionBoxes() : array{
		$myAxis = Facing::axis($this->facing);

		$result = AxisAlignedBB::one();
		foreach([Axis::X, Axis::Y, Axis::Z] as $axis){
			if($axis !== $myAxis){
				$result->squash($axis, 6 / 16);
			}
		}

		return [$result];
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$this->facing = $face;
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function isRedstoneSource() : bool{
		return true;
	}

	public function getWeakPower(int $face) : int{
		return $this->powered ? 15 : 0;
	}

	public function getStrongPower(int $face) : int{
		//strongly powers the block it is attached to
		return $this->powered && $face === Facing::opposite($this->facing) ? 15 : 0;
	}

	/**
	 * Called when lightning strikes the rod (see {@link \pocketmine\entity\object\LightningBolt}): emits a redstone
	 * signal of 15 for {@link LightningRod::STRUCK_POWER_TICKS}. Rods at the top of their column attract natural
	 * strikes (see {@link \pocketmine\world\weather\Weather::findLightningRod()}).
	 */
	public function onLightningStrike() : void{
		$world = $this->position->getWorld();
		if(!$this->powered){
			$this->powered = true;
			$world->setBlock($this->position, $this);
			$this->notifyPowerChange();
		}
		$world->scheduleDelayedBlockUpdate($this->position, self::STRUCK_POWER_TICKS);
		$world->addGameEvent($this->position, GameEvent::LIGHTNING_STRIKE);
		$world->addParticle($this->position, new ElectricSparkParticle());
	}

	public function onScheduledUpdate() : void{
		if($this->powered){
			$this->powered = false;
			$this->position->getWorld()->setBlock($this->position, $this);
			$this->notifyPowerChange();
		}
	}

	public function onNearbyBlockChange() : void{
		//scheduled updates are not saved: a rod reloaded while powered must still turn off
		$world = $this->position->getWorld();
		if($this->powered && !$world->isBlockUpdateScheduled($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ())){
			$world->scheduleDelayedBlockUpdate($this->position, self::STRUCK_POWER_TICKS);
		}
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$result = parent::onBreak($item, $player, $returnedItems);
		if($this->powered){
			$this->notifyPowerChange();
		}
		return $result;
	}

	private function notifyPowerChange() : void{
		$redstone = $this->position->getWorld()->getRedstone();
		if($redstone !== null){
			$redstone->updateAround($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ());
			$redstone->updateAroundSide($this->position, Facing::opposite($this->facing));
		}
	}

	public function pausesScheduledUpdatesOutsideTickingChunks() : bool{
		return true; //redstone components only update in ticking chunks, like vanilla block ticks
	}
}
