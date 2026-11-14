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
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\sound\RedstonePowerOffSound;
use pocketmine\world\sound\RedstonePowerOnSound;
use pocketmine\world\sound\TripwireAttachSound;
use pocketmine\world\sound\TripwireDetachSound;
use pocketmine\world\vibration\GameEvent;

/**
 * Hooks facing each other, up to {@link TripwireHook::MAX_DISTANCE} blocks apart, with an unbroken line of tripwire
 * between them, are attached. An entity on any wire of the line powers both hooks: they emit a signal of 15 around
 * them and strongly power the block they hang on.
 */
class TripwireHook extends Flowable implements HorizontalFacing{
	use HorizontalFacingTrait;

	/** Farthest distance between two attached hooks. */
	public const MAX_DISTANCE = 41;
	/** Ticks between checks of a line, after a wire changed. */
	public const RECHECK_TICKS = 10;

	protected bool $connected = false;
	protected bool $powered = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacing($this->facing);
		$w->bool($this->connected);
		$w->bool($this->powered);
	}

	public function isConnected() : bool{ return $this->connected; }

	/** @return $this */
	public function setConnected(bool $connected) : self{
		$this->connected = $connected;
		return $this;
	}

	public function isPowered() : bool{ return $this->powered; }

	/** @return $this */
	public function setPowered(bool $powered) : self{
		$this->powered = $powered;
		return $this;
	}

	private function canBeSupportedAt(Block $block, int $facing) : bool{
		return $block->getAdjacentSupportType(Facing::opposite($facing)) === SupportType::FULL;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if(Facing::axis($face) !== Axis::Y && $this->canBeSupportedAt($blockReplace, $face)){
			$this->facing = $face;
			return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
		}
		return false;
	}

	public function onPostPlace() : void{
		parent::onPostPlace();
		$this->updateLine(false);
	}

	public function onNearbyBlockChange() : void{
		if(!$this->canBeSupportedAt($this, $this->facing)){
			$this->position->getWorld()->useBreakOn($this->position);
		}
	}

	public function onScheduledUpdate() : void{
		$this->updateLine(false);
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$result = parent::onBreak($item, $player, $returnedItems);
		if($this->connected || $this->powered){
			$this->updateLine(true);
		}
		if($this->powered){
			$this->notifyNeighbours($this->position, $this->facing);
		}
		return $result;
	}

	public function isRedstoneSource() : bool{
		return true;
	}

	public function getWeakPower(int $face) : int{
		return $this->powered ? 15 : 0;
	}

	public function getStrongPower(int $face) : int{
		return $this->powered && $face === Facing::opposite($this->facing) ? 15 : 0;
	}

	/**
	 * Recalculates the line starting at this hook: whether it is attached to a hook facing it, whether any wire is
	 * triggered, and updates both hooks and the wires in between.
	 *
	 * @param bool          $removed        whether this hook is being removed
	 * @param int           $sourceDistance distance of the wire which changed (it may already be gone), or -1
	 * @param Tripwire|null $sourceWire     state of that wire to use instead of the one in the world
	 */
	public function updateLine(bool $removed, int $sourceDistance = -1, ?Tripwire $sourceWire = null) : void{
		$world = $this->position->getWorld();
		$facing = $this->facing;
		$wasConnected = $this->connected;
		$wasPowered = $this->powered;

		$connected = !$removed;
		$powered = false;
		$otherDistance = 0;
		/** @var Tripwire[] $wires */
		$wires = [];
		for($i = 1; $i <= self::MAX_DISTANCE; ++$i){
			$pos = $this->position->getSide($facing, $i);
			if(!$world->isInWorld($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){
				$connected = false;
				break;
			}
			$block = $world->getBlock($pos);
			if($block instanceof TripwireHook){
				if($block->getFacing() === Facing::opposite($facing)){
					$otherDistance = $i;
				}
				break;
			}
			if($i === $sourceDistance && $sourceWire !== null){
				$block = $sourceWire;
			}
			if(!$block instanceof Tripwire){
				$connected = false;
				continue;
			}
			$armed = !$block->isDisarmed();
			$powered = $powered || ($armed && $block->isTriggered());
			$wires[$i] = $block;
			if($i === $sourceDistance){
				$world->scheduleDelayedBlockUpdate($this->position, self::RECHECK_TICKS);
				$connected = $connected && $armed;
			}
		}
		$connected = $connected && $otherDistance > 1;
		$powered = $powered && $connected;

		if($otherDistance > 0){
			$otherPos = $this->position->getSide($facing, $otherDistance);
			$other = clone $world->getBlock($otherPos);
			if($other instanceof TripwireHook){
				$otherWasConnected = $other->connected;
				$otherWasPowered = $other->powered;
				if($otherWasConnected !== $connected || $otherWasPowered !== $powered){
					$other->connected = $connected;
					$other->powered = $powered;
					$world->setBlock($otherPos, $other);
					$this->notifyNeighbours($otherPos, $other->facing);
				}
				self::playStateSounds($other, $connected, $powered, $otherWasConnected, $otherWasPowered);
			}
		}

		if(!$removed){
			self::playStateSounds($this, $connected, $powered, $wasConnected, $wasPowered);
			if($wasConnected !== $connected || $wasPowered !== $powered){
				$this->connected = $connected;
				$this->powered = $powered;
				$world->setBlock($this->position, $this);
				$this->notifyNeighbours($this->position, $facing);
			}
		}

		if($wasConnected !== $connected){
			for($i = 1; $i < $otherDistance; ++$i){
				if(!isset($wires[$i])){
					continue;
				}
				$wirePos = $this->position->getSide($facing, $i);
				$wire = $world->getBlock($wirePos); //the source wire may be gone
				if($wire instanceof Tripwire && $wire->isConnected() !== $connected){
					$world->setBlock($wirePos, (clone $wire)->setConnected($connected));
				}
			}
		}
	}

	private static function playStateSounds(TripwireHook $hook, bool $connected, bool $powered, bool $wasConnected, bool $wasPowered) : void{
		$world = $hook->position->getWorld();
		$pos = $hook->position->add(0.5, 0.5, 0.5);
		if($connected && !$wasConnected){
			$world->addSound($pos, new TripwireAttachSound());
			$world->addGameEvent($hook->position, GameEvent::BLOCK_ATTACH);
		}elseif(!$connected && $wasConnected){
			$world->addSound($pos, new TripwireDetachSound());
			$world->addGameEvent($hook->position, GameEvent::BLOCK_DETACH);
		}elseif($powered && !$wasPowered){
			$world->addSound($pos, new RedstonePowerOnSound());
			$world->addGameEvent($hook->position, GameEvent::BLOCK_ACTIVATE);
		}elseif(!$powered && $wasPowered){
			$world->addSound($pos, new RedstonePowerOffSound());
			$world->addGameEvent($hook->position, GameEvent::BLOCK_DEACTIVATE);
		}
	}

	private function notifyNeighbours(Vector3 $pos, int $facing) : void{
		$redstone = $this->position->getWorld()->getRedstone();
		if($redstone !== null){
			$redstone->updateAround($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
			$redstone->updateAroundSide($pos, Facing::opposite($facing));
		}
	}

	public function pausesScheduledUpdatesOutsideTickingChunks() : bool{
		return true; //redstone components only update in ticking chunks, like vanilla block ticks
	}
}
