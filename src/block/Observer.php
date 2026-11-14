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
use pocketmine\block\utils\PoweredByRedstoneTrait;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\redstone\RedstoneEngine;
use function abs;

/**
 * Watches the block in front of it (its facing) and, one redstone tick after that block's state changes, emits a
 * one redstone tick pulse of strong power out of its back.
 *
 * Changes are reported by the redstone engine ({@link RedstoneEngine::notifyObservers()}), which looks at the six
 * neighbours of each changed block. Two observers facing each other make a clock, paced by the scheduled updates.
 */
class Observer extends Opaque implements AnyFacing{
	use AnyFacingTrait;
	use PoweredByRedstoneTrait;

	public const DELAY_TICKS = RedstoneEngine::REDSTONE_TICK;
	public const PULSE_TICKS = RedstoneEngine::REDSTONE_TICK;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facing($this->facing);
		$w->bool($this->powered);
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			//the face looks the same way as the player, the output (back) faces the player
			$this->facing = $player->getHorizontalFacing();
			$pos = $player->getPosition();
			if(abs($pos->x - $this->position->x - 0.5) < 2 && abs($pos->z - $this->position->z - 0.5) < 2){
				$eyeY = $player->getEyePos()->y;
				if($eyeY - $this->position->y > 2){
					$this->facing = Facing::DOWN;
				}elseif($this->position->y - $eyeY > 0){
					$this->facing = Facing::UP;
				}
			}
		}else{
			$this->facing = Facing::opposite($face);
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	/**
	 * Returns the side out of which the observer emits its pulse.
	 */
	public function getOutputFace() : int{
		return Facing::opposite($this->facing);
	}

	public function isRedstoneSource() : bool{
		return true;
	}

	public function isRedstoneConductor() : bool{
		return false;
	}

	public function getWeakPower(int $face) : int{
		return $this->powered && $face === $this->getOutputFace() ? 15 : 0;
	}

	public function getStrongPower(int $face) : int{
		return $this->getWeakPower($face);
	}

	public function connectsToRedstoneWire(int $face) : bool{
		return $face === $this->getOutputFace();
	}

	/**
	 * Called by the redstone engine when the watched block changes.
	 */
	public function onObservedBlockChange() : void{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		//a change seen while pulsing (or waiting to) is ignored, like vanilla (a powered observer doesn't react) and BDS:
		//two observers facing each other turn on 2 and 4 ticks after each other, a pulse every 6 ticks
		if(!$world->isBlockUpdateScheduled($x, $y, $z)){
			$world->scheduleDelayedBlockUpdate($this->position, self::DELAY_TICKS);
		}
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$redstone = $world->getRedstone();
		if($redstone === null){
			return;
		}
		$this->powered = !$this->powered;
		$world->setBlock($this->position, $this);
		$redstone->updateAroundSide($this->position, $this->getOutputFace());
		if($this->powered){
			$world->scheduleDelayedBlockUpdate($this->position, self::PULSE_TICKS);
		}
	}

	public function onNearbyBlockChange() : void{
		//scheduled updates are not saved: an observer reloaded while powered must still turn off
		if($this->powered){
			$world = $this->position->getWorld();
			if(!$world->isBlockUpdateScheduled($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ())){
				$world->scheduleDelayedBlockUpdate($this->position, self::PULSE_TICKS);
			}
		}
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$result = parent::onBreak($item, $player, $returnedItems);
		$redstone = $this->position->getWorld()->getRedstone();
		if($redstone !== null){
			$redstone->consumeObserverRetrigger($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ());
			if($this->powered){
				$redstone->updateAroundSide($this->position, $this->getOutputFace());
			}
		}
		return $result;
	}

	public function pausesScheduledUpdatesOutsideTickingChunks() : bool{
		return true; //redstone components only update in ticking chunks, like vanilla block ticks
	}
}
