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

use pocketmine\block\utils\ComparatorReadable;
use pocketmine\block\utils\DetectorRailActivator;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\player\Player;
use function count;
use function max;

/**
 * Powered while a minecart ({@link DetectorRailActivator}) is on it: weak power of 15 around it and strong power into
 * the block below. Other entities (players, mobs, items) don't activate it, like in vanilla. A comparator reads the
 * fill level of container minecarts on it.
 */
class DetectorRail extends StraightOnlyRail implements ComparatorReadable{
	/** Ticks between checks while a minecart is on the rail. */
	public const RECHECK_TICKS = 20;

	protected bool $activated = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		parent::describeBlockOnlyState($w);
		$w->bool($this->activated);
	}

	public function isActivated() : bool{ return $this->activated; }

	/** @return $this */
	public function setActivated(bool $activated) : self{
		$this->activated = $activated;
		return $this;
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	public function onEntityInside(Entity $entity) : bool{
		if(!$this->activated && $entity instanceof DetectorRailActivator){
			$this->checkActivators();
		}
		return true;
	}

	public function onScheduledUpdate() : void{
		if($this->activated){
			$this->checkActivators();
		}
	}

	/**
	 * @return DetectorRailActivator[]
	 */
	private function getActivators() : array{
		$box = new AxisAlignedBB(
			$this->position->x + 0.2,
			$this->position->y,
			$this->position->z + 0.2,
			$this->position->x + 0.8,
			$this->position->y + 0.8,
			$this->position->z + 0.8
		);
		$result = [];
		foreach($this->position->getWorld()->getNearbyEntities($box) as $entity){
			if($entity instanceof DetectorRailActivator){
				$result[] = $entity;
			}
		}
		return $result;
	}

	/**
	 * Updates the activated state from the minecarts on the rail.
	 */
	public function checkActivators() : void{
		$world = $this->position->getWorld();
		$activated = count($this->getActivators()) > 0;
		if($activated !== $this->activated){
			$this->activated = $activated;
			$world->setBlock($this->position, $this);
			$this->notifyOutputChange();
		}elseif($activated){
			//the contents of a container minecart may have changed
			$world->getRedstone()?->updateComparatorsReading($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ());
		}
		if($activated){
			$world->scheduleDelayedBlockUpdate($this->position, self::RECHECK_TICKS);
		}
	}

	private function notifyOutputChange() : void{
		$redstone = $this->position->getWorld()->getRedstone();
		if($redstone !== null){
			$x = $this->position->getFloorX();
			$y = $this->position->getFloorY();
			$z = $this->position->getFloorZ();
			$redstone->updateAround($x, $y, $z);
			$redstone->updateAround($x, $y - 1, $z);
			$redstone->updateComparatorsReading($x, $y, $z);
		}
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$result = parent::onBreak($item, $player, $returnedItems);
		if($this->activated){
			$this->notifyOutputChange();
		}
		return $result;
	}

	public function isRedstoneSource() : bool{
		return true;
	}

	public function getWeakPower(int $face) : int{
		return $this->activated ? 15 : 0;
	}

	public function getStrongPower(int $face) : int{
		return $this->activated && $face === Facing::DOWN ? 15 : 0;
	}

	public function getComparatorOutput() : int{
		if(!$this->activated){
			return 0;
		}
		$output = 0;
		foreach($this->getActivators() as $activator){
			$output = max($output, $activator->getDetectorRailComparatorOutput());
		}
		return $output;
	}

	public function pausesScheduledUpdatesOutsideTickingChunks() : bool{
		return true; //redstone components only update in ticking chunks, like vanilla block ticks
	}
}
