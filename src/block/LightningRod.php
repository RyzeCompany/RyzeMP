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
use pocketmine\block\utils\PoweredByRedstone;
use pocketmine\block\utils\PoweredByRedstoneTrait;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;

final class LightningRod extends Transparent implements AnyFacing, CopperMaterial, PoweredByRedstone{
	use CopperTrait;
	use AnyFacingTrait {
		describeBlockOnlyState as describeFacing;
	}
	use PoweredByRedstoneTrait;

	/** Bedrock: the strike powers a lightning rod for 8 game ticks. */
	public const STRUCK_POWER_TICKS = 8;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$this->describeFacing($w);
		$w->bool($this->powered);
	}

	public function isRedstoneSource() : bool{ return true; }

	public function getWeakPower(int $face) : int{
		return $this->powered ? 15 : 0;
	}

	public function getStrongPower(int $face) : int{
		return $this->powered && $face === Facing::opposite($this->facing) ? 15 : 0;
	}

	/**
	 * Activates an actual serializable blockstate, not only a particle.
	 * Updating the block notifies its neighbours through World::setBlock().
	 */
	public function onLightningStrike() : void{
		$world = $this->position->getWorld();
		if(!$this->powered){
			$this->powered = true;
			$world->setBlock($this->position, $this);
		}
		//Repeated strikes extend the pulse, not shorten it.
		$world->scheduleDelayedBlockUpdate($this->position, self::STRUCK_POWER_TICKS, true);
	}

	public function onScheduledUpdate() : void{
		if($this->powered){
			$this->powered = false;
			$this->position->getWorld()->setBlock($this->position, $this);
		}
	}

	/**
	 * WorldData persists the powered blockstate, but not delayed block ticks.
	 * Reading a powered rod after world reload re-arms its pending unpower tick.
	 */
	public function readStateFromWorld() : Block{
		$block = parent::readStateFromWorld();
		if($block === $this && $this->powered){
			$world = $this->position->getWorld();
			if(!$world->isBlockUpdateScheduled($this->position)){
				$world->scheduleDelayedBlockUpdate($this->position, self::STRUCK_POWER_TICKS);
			}
		}
		return $block;
	}

	public function onNearbyBlockChange() : void{
		//If the powered state was restored from LevelDB, the transient tick queue is empty.
		$world = $this->position->getWorld();
		if($this->powered && !$world->isBlockUpdateScheduled($this->position)){
			$world->scheduleDelayedBlockUpdate($this->position, self::STRUCK_POWER_TICKS);
		}
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
}
