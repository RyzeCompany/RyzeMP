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

use pocketmine\entity\Mob;
use pocketmine\entity\object\ItemEntity;
use pocketmine\item\Item;
use pocketmine\world\format\Chunk;
use function floor;
use function intdiv;
use function min;

/**
 * Walks to an item lying on the ground that the mob wants and picks it up (vanilla behavior.pickup_items): the
 * nearest wanted item within $maxDistance blocks, looked for once a second and only while $canSearch allows it (e.g.
 * the mob can pick up loot and mobGriefing is on). Items nobody may pick up yet (pickup delay, including the infinite
 * delay of display items) are ignored. The paths to items are short (an item is a few blocks away): an item the mob
 * can't reach (no such path, or not reached in 5 seconds) is left alone for 30 seconds, and the next search waits 5
 * seconds, so mobs next to items behind walls or glass don't keep searching paths to them.
 *
 * $wantsItem tells whether the mob wants an item (e.g. shareables), $pickUp takes the item when the mob touches it and
 * returns whether it did.
 */
class PickupItemsGoal extends Goal{
	/** Ticks between two searches for items. */
	public const SEARCH_INTERVAL = 20;
	/** The goal gives up on an item it couldn't reach in this many ticks. */
	private const GIVE_UP_TICKS = 100;
	/** Ticks an item the goal gave up on is left alone. */
	private const UNREACHABLE_TICKS = 600;
	/** Ticks before the next search for items after giving up on one. */
	private const SEARCH_DELAY_AFTER_GIVING_UP = 100;
	/**
	 * Nodes a path search to the item may visit: paths of about 20 blocks around obstacles; a search for an item which
	 * can't be reached gives up a few times sooner than one with the mob's usual limit.
	 */
	private const PATH_NODES = 128;
	/** Ticks between two path updates towards the item (and two checks that the mob still wants it). */
	private const REPATH_INTERVAL = 10;

	private ?ItemEntity $target = null;
	/** Goal evaluations left before the next search (counted down in canStart()). */
	private int $searchCooldown = 0;
	private int $ticksRunning = 0;
	/** The target couldn't be reached: the goal stops and leaves it alone for a while. */
	private bool $unreachable = false;
	/** Entity ID of the last item the goal gave up on, and the mob AI tick until which it is ignored. */
	private int $ignoredItemId = -1;
	private int $ignoredUntil = 0;
	/** The usual node limit of the mob's path searches while the goal runs (see {@link PickupItemsGoal::PATH_NODES}). */
	private int $savedMaxNodes = -1;

	/**
	 * @phpstan-param \Closure(Item) : bool $wantsItem
	 * @phpstan-param \Closure(ItemEntity) : bool $pickUp
	 * @phpstan-param \Closure() : bool $canSearch
	 */
	public function __construct(
		protected Mob $mob,
		protected \Closure $wantsItem,
		protected \Closure $pickUp,
		protected \Closure $canSearch,
		protected float $maxDistance = 3.0,
		protected float $speedMultiplier = 1.0
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		if(--$this->searchCooldown > 0){
			return false;
		}
		//asked once per goal evaluation; $canSearch too is only asked once a second
		$this->searchCooldown = intdiv(self::SEARCH_INTERVAL, Mob::GOAL_EVALUATION_INTERVAL);
		if(!($this->canSearch)()){
			return false;
		}
		$this->target = $this->findItem();
		return $this->target !== null;
	}

	/**
	 * Returns the nearest wanted item within $maxDistance blocks: the chunk entity lists around the mob are walked
	 * directly (no bounding box nor result array), item entities first.
	 */
	private function findItem() : ?ItemEntity{
		$loc = $this->mob->getLiveLocation();
		$world = $this->mob->getWorld();
		$r = $this->maxDistance;
		$best = null;
		$bestDistance = $r * $r;
		$ignored = $this->ignoredUntil > $this->mob->getAiTick() ? $this->ignoredItemId : -1;
		$minChunkX = ((int) floor($loc->x - $r)) >> Chunk::COORD_BIT_SIZE;
		$maxChunkX = ((int) floor($loc->x + $r)) >> Chunk::COORD_BIT_SIZE;
		$minChunkZ = ((int) floor($loc->z - $r)) >> Chunk::COORD_BIT_SIZE;
		$maxChunkZ = ((int) floor($loc->z + $r)) >> Chunk::COORD_BIT_SIZE;
		for($chunkX = $minChunkX; $chunkX <= $maxChunkX; ++$chunkX){
			for($chunkZ = $minChunkZ; $chunkZ <= $maxChunkZ; ++$chunkZ){
				foreach($world->getChunkEntities($chunkX, $chunkZ) as $entity){
					if(!$entity instanceof ItemEntity){
						continue;
					}
					//feet position of the item, from its bounding box (getPosition() would copy it)
					$bb = $entity->boundingBox;
					$dx = ($bb->minX + $bb->maxX) * 0.5 - $loc->x;
					$dy = $bb->minY - $loc->y;
					$dz = ($bb->minZ + $bb->maxZ) * 0.5 - $loc->z;
					$distance = $dx * $dx + $dy * $dy + $dz * $dz;
					if($distance <= $bestDistance && $entity->getId() !== $ignored && self::isAvailable($entity) && ($this->wantsItem)($entity->getItem())){
						$best = $entity;
						$bestDistance = $distance;
					}
				}
			}
		}
		return $best;
	}

	/**
	 * Whether a mob may pick the item entity up now: not despawning and with no pickup delay (an infinite delay
	 * ({@link ItemEntity::NEVER_DESPAWN}) marks items nobody may take, like the display items of plugins).
	 */
	public static function isAvailable(ItemEntity $entity) : bool{
		return !$entity->isClosed() && !$entity->isFlaggedForDespawn() && $entity->getPickupDelay() === 0;
	}

	public function canContinue() : bool{
		$target = $this->target;
		if($target === null || $this->unreachable || $this->ticksRunning >= self::GIVE_UP_TICKS || !self::isAvailable($target) || $target->getWorld() !== $this->mob->getWorld()){
			return false;
		}
		//whether the mob may and still wants to take it: asked with the path updates (taking it asks again)
		return $this->ticksRunning % self::REPATH_INTERVAL !== 0 || (($this->canSearch)() && ($this->wantsItem)($target->getItem()));
	}

	public function start() : void{
		$this->ticksRunning = 0;
		$this->unreachable = false;
		$navigation = $this->mob->getNavigation();
		$finder = $navigation->getPathFinder();
		$this->savedMaxNodes = $finder->getMaxNodes();
		$finder->setMaxNodes(min($this->savedMaxNodes, self::PATH_NODES));
		if($this->target !== null && !$navigation->moveToEntity($this->target, $this->speedMultiplier, 0.5)){
			//no path to it was found a moment ago
			$this->unreachable = true;
		}
	}

	public function stop() : void{
		$target = $this->target;
		if($target !== null && ($this->unreachable || $this->ticksRunning >= self::GIVE_UP_TICKS)){
			$this->ignoredItemId = $target->getId();
			$this->ignoredUntil = $this->mob->getAiTick() + self::UNREACHABLE_TICKS;
			$this->searchCooldown = intdiv(self::SEARCH_DELAY_AFTER_GIVING_UP, Mob::GOAL_EVALUATION_INTERVAL);
		}
		$this->target = null;
		$this->unreachable = false;
		$navigation = $this->mob->getNavigation();
		$navigation->stop();
		if($this->savedMaxNodes >= 0){
			$navigation->getPathFinder()->setMaxNodes($this->savedMaxNodes);
			$this->savedMaxNodes = -1;
		}
	}

	public function tick() : void{
		$target = $this->target;
		if($target === null || $this->unreachable){
			return;
		}
		++$this->ticksRunning;
		//touching: the item's box grown by 1 block sideways and half a block up and down meets the mob's
		$item = $target->boundingBox;
		$mob = $this->mob->boundingBox;
		if(
			$item->minX - 1.0 < $mob->maxX && $item->maxX + 1.0 > $mob->minX &&
			$item->minY - 0.5 < $mob->maxY && $item->maxY + 0.5 > $mob->minY &&
			$item->minZ - 1.0 < $mob->maxZ && $item->maxZ + 1.0 > $mob->minZ
		){
			if(($this->canSearch)()){
				($this->pickUp)($target);
			}
			$this->target = null;
			return;
		}
		if($this->ticksRunning % self::REPATH_INTERVAL === 0){
			//the item may have slid or been pushed; no path to it (or at the end of one that doesn't reach it) and
			//none found a moment ago: the item can't be reached
			$navigation = $this->mob->getNavigation();
			if(!$navigation->moveToEntity($target, $this->speedMultiplier, 0.5) && $navigation->isDone()){
				$this->unreachable = true;
			}
		}
	}
}
