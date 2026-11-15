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

namespace pocketmine\entity\ai\villager;

use pocketmine\block\Door;
use pocketmine\block\IronDoor;
use pocketmine\entity\ai\navigation\Path;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\Mob;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\World;
use function count;

/**
 * Opens the doors on a mob's path and closes them behind it (vanilla InteractWithDoor of villagers, and
 * minecraft:annotation.open_door of piglins, piglin brutes, copper golems...): a closed door (not iron) in the current
 * or next node of the path opens when the mob is within 2 blocks of it; doors it opened close again once it is more
 * than 2 blocks away, unless another mob which opens doors is in the doorway. Paths only go through doors for mobs
 * whose path finder can open them (see {@link \pocketmine\entity\ai\navigation\PathFinder::setCanOpenDoors()}).
 *
 * The blocks of the path are read through the state flags of {@link PathBlockTypes} (an array lookup), the door
 * itself only when one is there.
 */
final class DoorInteraction{
	private const REACH = 2.0;

	/** @phpstan-var array<int, Vector3> block hash => lower half of a door the mob opened */
	private array $opened = [];
	/** Path whose nodes {@link DoorInteraction::$doorNodes} are about */
	private ?Path $path = null;
	/** @phpstan-var array<int, bool> node index => whether a door mobs open is in the node (feet or head block) */
	private array $doorNodes = [];

	public function __construct(
		private Mob $mob
	){}

	/**
	 * Whether doors the mob opened are still waiting to be closed behind it.
	 */
	public function hasOpenedDoors() : bool{
		return count($this->opened) > 0;
	}

	/**
	 * Returns the door at the position (either half), or null.
	 */
	private static function openableDoorAt(World $world, int $x, int $y, int $z) : ?Door{
		if(!$world->isInWorld($x, $y, $z) || (PathBlockTypes::at($world, $x, $y, $z) & PathBlockTypes::OPENABLE_DOOR) === 0){
			return null;
		}
		$block = $world->getBlockAt($x, $y, $z);
		if(!$block instanceof Door || $block instanceof IronDoor){
			return null;
		}
		if($block->isTop()){
			$below = $world->getBlockAt($x, $y - 1, $z);
			return $below instanceof Door && !$below instanceof IronDoor ? $below : null;
		}
		return $block;
	}

	private function distanceSquared(Vector3 $block) : float{
		$loc = $this->mob->getLiveLocation();
		$dx = $loc->x - $block->x - 0.5;
		$dy = $loc->y - $block->y;
		$dz = $loc->z - $block->z - 0.5;
		return $dx * $dx + $dy * $dy + $dz * $dz;
	}

	/**
	 * Returns whether a node of the path from $from to $to has a door mobs open in it (feet or head block). Each node is
	 * looked at once per path: doors put on the path later make the mob search a new one.
	 */
	private function hasDoorNode(World $world, Path $path, int $from, int $to) : bool{
		if($path !== $this->path){
			$this->path = $path;
			$this->doorNodes = [];
		}
		$found = false;
		for($i = $from; $i <= $to; ++$i){
			$node = $path->getNode($i);
			if($node !== null && ($this->doorNodes[$i] ??= ((PathBlockTypes::at($world, $node[0], $node[1], $node[2]) | PathBlockTypes::at($world, $node[0], $node[1] + 1, $node[2])) & PathBlockTypes::OPENABLE_DOOR) !== 0)){
				$found = true;
			}
		}
		return $found;
	}

	/**
	 * Whether the mob has something to do: a door in the next few nodes of its path, or doors to close behind it.
	 */
	public function hasWork() : bool{
		if(count($this->opened) > 0){
			return true;
		}
		$path = $this->mob->getNavigation()->getPath();
		if($path === null || $path->isDone()){
			return false;
		}
		$index = $path->getIndex();
		return $this->hasDoorNode($this->mob->getWorld(), $path, $index - 1, $index + 2);
	}

	public function tick() : void{
		$world = $this->mob->getWorld();
		$path = $this->mob->getNavigation()->getPath();
		if($path !== null && !$path->isDone()){
			$index = $path->getIndex();
			for($i = $index - 1; $i <= $index + 1; ++$i){
				$node = $path->getNode($i);
				if($node === null || !$this->hasDoorNode($world, $path, $i, $i)){
					continue;
				}
				[$x, $y, $z] = $node;
				$door = self::openableDoorAt($world, $x, $y, $z) ?? self::openableDoorAt($world, $x, $y + 1, $z);
				if($door === null){
					continue;
				}
				$pos = $door->getPosition();
				if(!$door->isOpen() && $this->distanceSquared($pos) <= self::REACH ** 2){
					$door->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0.5, 0.5, 0.5));
					$this->opened[World::blockHash($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())] = $pos->floor();
				}
			}
		}
		if(count($this->opened) > 0){
			$this->closeDoorsBehind();
		}
	}

	private function closeDoorsBehind() : void{
		$world = $this->mob->getWorld();
		foreach($this->opened as $hash => $pos){
			if($this->distanceSquared($pos) <= self::REACH ** 2){
				continue;
			}
			unset($this->opened[$hash]);
			$door = self::openableDoorAt($world, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
			if($door === null || !$door->isOpen() || $this->isDoorwayUsed($world, $pos)){
				continue;
			}
			$door->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0.5, 0.5, 0.5));
		}
	}

	/**
	 * Whether another mob which opens doors (a villager, a piglin...) stands in or right next to the doorway.
	 */
	private function isDoorwayUsed(World $world, Vector3 $pos) : bool{
		$box = new AxisAlignedBB($pos->x - 1, $pos->y, $pos->z - 1, $pos->x + 2, $pos->y + 2, $pos->z + 2);
		foreach($world->getNearbyEntities($box, $this->mob) as $entity){
			if($entity instanceof Mob && $entity->isAlive() && $entity->getNavigation()->getPathFinder()->canOpenDoors()){
				return true;
			}
		}
		return false;
	}
}
