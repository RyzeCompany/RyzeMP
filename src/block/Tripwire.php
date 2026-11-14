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
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\item\Shears;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;

class Tripwire extends Flowable implements HorizontalConnections{
	use HorizontalConnectionsTrait;

	protected bool $triggered = false;
	protected bool $suspended = false; //unclear usage, makes hitbox bigger if set
	protected bool $connected = false;
	protected bool $disarmed = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->bool($this->triggered);
		$w->bool($this->suspended);
		$w->bool($this->connected);
		$w->bool($this->disarmed);
		//1.26.50: the visual connections to neighbouring wire/hooks are block states (minecraft:connection_*)
		$w->horizontalFacingFlags($this->connections);
	}

	public function isTriggered() : bool{ return $this->triggered; }

	/** @return $this */
	public function setTriggered(bool $triggered) : self{
		$this->triggered = $triggered;
		return $this;
	}

	public function isSuspended() : bool{ return $this->suspended; }

	/** @return $this */
	public function setSuspended(bool $suspended) : self{
		$this->suspended = $suspended;
		return $this;
	}

	public function isConnected() : bool{ return $this->connected; }

	/** @return $this */
	public function setConnected(bool $connected) : self{
		$this->connected = $connected;
		return $this;
	}

	public function isDisarmed() : bool{ return $this->disarmed; }

	/** @return $this */
	public function setDisarmed(bool $disarmed) : self{
		$this->disarmed = $disarmed;
		return $this;
	}

	protected function canConnectTo(Block $block, int $facing) : bool{
		return $block instanceof Tripwire || ($block instanceof TripwireHook && $block->getFacing() === Facing::opposite($facing));
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$this->recalculateConnections();
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onNearbyBlockChange() : void{
		parent::onNearbyBlockChange();
		if($this->recalculateConnections()){
			$this->position->getWorld()->setBlock($this->position, $this);
		}
	}

	public function onPostPlace() : void{
		parent::onPostPlace();
		$this->updateHooks($this);
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		//cutting the wire with shears disarms it: the hooks detach without a pulse
		$removed = (clone $this)->setTriggered(true);
		if($item instanceof Shears){
			$removed->setDisarmed(true);
		}
		$result = parent::onBreak($item, $player, $returnedItems);
		$this->updateHooks($removed);
		return $result;
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	public function onEntityInside(Entity $entity) : bool{
		if(!$this->triggered && !($entity instanceof Player && $entity->isSpectator())){
			$this->checkEntities();
		}
		return true;
	}

	public function onScheduledUpdate() : void{
		if($this->triggered){
			$this->checkEntities();
		}
	}

	/**
	 * Returns the area entities must touch to trigger the wire.
	 */
	protected function getTriggerBox() : AxisAlignedBB{
		$height = $this->connected ? 2.5 / 16 : 0.5;
		return new AxisAlignedBB(
			$this->position->x,
			$this->position->y,
			$this->position->z,
			$this->position->x + 1,
			$this->position->y + $height,
			$this->position->z + 1
		);
	}

	private function checkEntities() : void{
		$world = $this->position->getWorld();
		$triggered = false;
		foreach($world->getNearbyEntities($this->getTriggerBox()) as $entity){
			//spectators don't trigger blocks (vanilla)
			if(!($entity instanceof Player && $entity->isSpectator())){
				$triggered = true;
				break;
			}
		}
		if($triggered !== $this->triggered){
			$this->triggered = $triggered;
			$world->setBlock($this->position, $this);
			$this->updateHooks($this);
		}
		if($triggered){
			$world->scheduleDelayedBlockUpdate($this->position, TripwireHook::RECHECK_TICKS);
		}
	}

	/**
	 * Tells the hooks at the ends of the line this wire belongs to that the wire changed.
	 *
	 * @param Tripwire $state the state of this wire to use (it may have been removed from the world)
	 */
	private function updateHooks(Tripwire $state) : void{
		$world = $this->position->getWorld();
		foreach([Facing::SOUTH, Facing::WEST] as $direction){
			for($i = 1; $i <= TripwireHook::MAX_DISTANCE; ++$i){
				$pos = $this->position->getSide($direction, $i);
				if(!$world->isInWorld($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){
					break;
				}
				$block = $world->getBlock($pos);
				if($block instanceof TripwireHook){
					if($block->getFacing() === Facing::opposite($direction)){
						$block->updateLine(false, $i, $state);
					}
					break;
				}
				if(!$block instanceof Tripwire){
					break;
				}
			}
		}
	}

	public function asItem() : Item{
		return VanillaItems::STRING();
	}

	public function pausesScheduledUpdatesOutsideTickingChunks() : bool{
		return true; //redstone components only update in ticking chunks, like vanilla block ticks
	}
}
