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

namespace pocketmine\entity\ai\goal;

use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use function abs;
use function ceil;
use function floor;
use function max;
use function mt_rand;

/**
 * Follows the owner (or another leader given by the callback) when it gets farther than $startDistance, stopping at
 * $stopDistance, and teleports next to it beyond $teleportDistance (vanilla behavior.follow_owner and
 * teleport_to_owner; also used by allays following the player who gave them an item).
 */
class FollowOwnerGoal extends Goal{
	private ?Living $owner = null;
	private int $repathDelay = 0;

	/**
	 * @phpstan-param \Closure() : ?Living $ownerGetter
	 * @param float $teleportDistance 0 to never teleport
	 */
	public function __construct(
		protected Mob $mob,
		protected \Closure $ownerGetter,
		protected float $speedMultiplier = 1.0,
		protected float $startDistance = 10.0,
		protected float $stopDistance = 2.0,
		protected float $teleportDistance = 12.0,
		protected bool $flying = false
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public function canStart() : bool{
		$owner = ($this->ownerGetter)();
		if($owner === null || !$owner->isAlive() || ($owner instanceof Player && $owner->isSpectator())){
			return false;
		}
		if($owner->getLocation()->distanceSquared($this->mob->getLiveLocation()) < $this->startDistance ** 2){
			return false;
		}
		$this->owner = $owner;
		return true;
	}

	public function canContinue() : bool{
		$owner = $this->owner;
		if($owner === null || !$owner->isAlive() || $owner->isClosed() || $owner->getWorld() !== $this->mob->getWorld() || ($this->ownerGetter)() !== $owner){
			return false;
		}
		$distanceSquared = $owner->getLocation()->distanceSquared($this->mob->getLiveLocation());
		if($distanceSquared <= $this->stopDistance ** 2){
			return false;
		}
		//the path ends next to the owner's block, which can be a little farther than the stop distance: don't hold
		//on to the movement flags forever once there
		return !$this->mob->getNavigation()->isDone() || $distanceSquared > ($this->stopDistance + 1) ** 2;
	}

	public function start() : void{
		$this->repathDelay = 0;
	}

	public function stop() : void{
		$this->owner = null;
		$this->mob->getNavigation()->stop();
	}

	public function tick() : void{
		$owner = $this->owner;
		if($owner === null){
			return;
		}
		$this->mob->getLookControl()->setLookAtEntity($owner, 10.0, 40.0);
		if(--$this->repathDelay > 0){
			return;
		}
		$this->repathDelay = 10;
		if($this->teleportDistance > 0 && $owner->getLocation()->distanceSquared($this->mob->getLiveLocation()) >= $this->teleportDistance ** 2){
			$this->teleportToOwner($owner);
			return;
		}
		$this->mob->getNavigation()->moveToEntity($owner, $this->speedMultiplier, $this->stopDistance);
	}

	/**
	 * Teleports to a free spot 2-3 blocks from the owner (vanilla tries 10 random spots).
	 */
	private function teleportToOwner(Living $owner) : void{
		$world = $this->mob->getWorld();
		$pos = $owner->getPosition();
		$headBlocks = max(0, (int) ceil($this->mob->getSize()->getHeight()) - 1);
		for($i = 0; $i < 10; ++$i){
			$x = (int) floor($pos->x) + mt_rand(-3, 3);
			$y = (int) floor($pos->y) + mt_rand(-1, 1);
			$z = (int) floor($pos->z) + mt_rand(-3, 3);
			if(abs($x - $pos->x) < 2 && abs($z - $pos->z) < 2){
				continue;
			}
			if($this->flying ?
				(PathBlockTypes::at($world, $x, $y, $z) & PathBlockTypes::PASSABLE) !== 0 && (PathBlockTypes::at($world, $x, $y, $z) & PathBlockTypes::WATER) === 0 :
				PathBlockTypes::isStandable($world, $x, $y, $z, $headBlocks, false)
			){
				$this->mob->teleport(new Vector3($x + 0.5, $y, $z + 0.5));
				$this->mob->getNavigation()->stop();
				return;
			}
		}
	}
}
