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

namespace pocketmine\entity\ai;

use pocketmine\block\Block;
use pocketmine\block\Water;
use pocketmine\entity\ai\control\MoveControl;
use pocketmine\entity\ai\control\SwimmingMoveControl;
use pocketmine\entity\ai\navigation\PathNavigator;
use pocketmine\entity\ai\navigation\RandomPos;
use pocketmine\entity\ai\navigation\StraightLineNavigator;
use pocketmine\event\entity\EntityExtinguishEvent;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\World;
use function count;
use function floor;

/**
 * Mob that lives in water (fish, dolphin, tadpole, nautilus): swimming move control, navigation through the water
 * (straight while the way is clear, a 3D path search around obstacles, see {@link StraightLineNavigator}) and wandering
 * targets in water. Out of water it can't wander nor walk towards its targets (Bedrock navigation.generic can_walk
 * false; fish flop, see the mob), only a rider drives it there. Amphibious mobs (axolotls,
 * turtles, frogs) don't use this: they keep the walking navigation with a {@link SwimmingMoveControl}.
 *
 * A swimming mob moves on most ticks but only enters other blocks every few ticks: while its box covers the same plain
 * water blocks, what was found about them after an earlier movement (the water, no collision box there) is used again
 * instead of being looked up after each movement.
 */
trait SwimmingMobTrait{
	/**
	 * Water blocks around the mob after its last block lookup and the block range they cover (see
	 * getBlocksAroundWithEntityInsideActions()).
	 * @var Block[]|null
	 * @phpstan-var array<Block>|null
	 */
	private ?array $waterAround = null;
	private ?World $waterAroundWorld = null;
	private int $waterAroundMinX = 0;
	private int $waterAroundMinY = 0;
	private int $waterAroundMinZ = 0;
	private int $waterAroundMaxX = 0;
	private int $waterAroundMaxY = 0;
	private int $waterAroundMaxZ = 0;
	/** Whether the last full block intersection check was done with $waterAround (see checkBlockIntersections()). */
	private bool $waterAroundChecked = false;
	/** Whether no block has a collision box in the blocks of $waterAround, null if not looked up yet (see move()). */
	private ?bool $waterAroundFree = null;
	/** The last box found inside the blocks of $waterAround (see isWaterAroundValid()). */
	private ?AxisAlignedBB $waterAroundBox = null;
	/**
	 * The subchunks of the blocks the facts above depend on (the block range and the blocks next to it: collision boxes
	 * reaching in, the liquid surface above) and their block modification counts when the water was found (see
	 * watchWaterAroundSubChunks()).
	 * @var SubChunk[]
	 * @phpstan-var list<SubChunk>
	 */
	private array $waterAroundSubChunks = [];
	/** @phpstan-var list<int> */
	private array $waterAroundCounts = [];
	/** World and range of chunks (X, Z) and subchunk rows of $waterAroundSubChunks; null world: none noted. */
	private ?World $watchWorld = null;
	private int $watchMinCX = 0;
	private int $watchMaxCX = 0;
	private int $watchMinCZ = 0;
	private int $watchMaxCZ = 0;
	private int $watchMinCY = 0;
	private int $watchMaxCY = 0;
	/**
	 * The chunks of these subchunks by chunk hash (World::chunkHash()) when they are more than one (the mob is next to
	 * the edge of its chunk), empty otherwise.
	 * @var Chunk[]
	 * @phpstan-var array<int, Chunk>
	 */
	private array $watchEdgeChunks = [];
	/** Set while move() moves the mob within the blocks of $waterAround, which it has just checked for changes. */
	private bool $waterAroundFresh = false;
	/**
	 * Whether the block modification counts are noted and checked (see watchWaterAroundSubChunks()). Without them only
	 * the block changes that notify the entities around forget the water (fish, see
	 * {@link \pocketmine\entity\aquatic\AbstractFish::initEntity()}).
	 */
	private bool $waterAroundWatched = true;

	/**
	 * Underwater speed in blocks per tick at speed multiplier 1 (Bedrock "underwater_movement").
	 */
	abstract protected function getSwimSpeed() : float;

	protected function createMoveControl() : MoveControl{
		return new SwimmingMoveControl($this, $this->getSwimSpeed(), canWalk: false, leavesWater: false);
	}

	protected function createNavigation() : PathNavigator{
		return new StraightLineNavigator($this, StraightLineNavigator::MEDIUM_WATER);
	}

	public function findWanderPos(int $horizontal, int $vertical, ?Vector3 $awayFrom = null) : ?Vector3{
		return $this->isInWater() ? RandomPos::getWaterPos($this, $horizontal, $vertical, $awayFrom) : null;
	}

	public function canBreathe() : bool{
		return $this->isInWater() || $this->isUnderwater();
	}

	public function canBeMovedByCurrents() : bool{
		return false;
	}

	/**
	 * While the box covers the same blocks, the water found around the mob after its last movement is used again instead
	 * of being looked up after each movement (like for an entity that doesn't move). Only plain water is kept; a block
	 * change nearby clears it.
	 */
	protected function getBlocksAroundWithEntityInsideActions() : array{
		if($this->blocksAround !== null){
			return $this->blocksAround;
		}
		if($this->isWaterAroundValid(true)){
			return $this->blocksAround = $this->waterAround ?? [];
		}
		$blocks = parent::getBlocksAroundWithEntityInsideActions();
		$this->forgetWaterAround();
		$world = $this->location->world;
		if($world === null){
			return $blocks;
		}
		foreach($blocks as $block){
			if($block::class !== Water::class){
				return $blocks;
			}
		}
		$bb = $this->boundingBox;
		$this->waterAround = $blocks;
		$this->waterAroundWorld = $world;
		$this->waterAroundMinX = (int) floor($bb->minX + 0.001);
		$this->waterAroundMinY = (int) floor($bb->minY + 0.001);
		$this->waterAroundMinZ = (int) floor($bb->minZ + 0.001);
		$this->waterAroundMaxX = (int) floor($bb->maxX - 0.001);
		$this->waterAroundMaxY = (int) floor($bb->maxY - 0.001);
		$this->waterAroundMaxZ = (int) floor($bb->maxZ - 0.001);
		if($this->waterAroundWatched){
			$this->watchWaterAroundSubChunks($world);
		}
		return $blocks;
	}

	private function forgetWaterAround() : void{
		$this->waterAround = null;
		$this->waterAroundChecked = false;
		$this->waterAroundFree = null;
		$this->waterAroundBox = null;
	}

	/**
	 * Notes the subchunks of the blocks of $waterAround and of the blocks next to them, and their block modification
	 * counts. Every block change goes through a subchunk and counts there, also the ones that don't notify the entities
	 * around (setBlock() without updates, structures, blocks written straight into a chunk): the water is looked up again
	 * after any of them (see waterAroundUnchanged()), like after every movement without this cache.
	 */
	private function watchWaterAroundSubChunks(World $world) : void{
		$minCX = ($this->waterAroundMinX - 1) >> Chunk::COORD_BIT_SIZE;
		$maxCX = ($this->waterAroundMaxX + 1) >> Chunk::COORD_BIT_SIZE;
		$minCZ = ($this->waterAroundMinZ - 1) >> Chunk::COORD_BIT_SIZE;
		$maxCZ = ($this->waterAroundMaxZ + 1) >> Chunk::COORD_BIT_SIZE;
		$minCY = ($this->waterAroundMinY - 1) >> SubChunk::COORD_BIT_SIZE;
		if($minCY < Chunk::MIN_SUBCHUNK_INDEX){
			$minCY = Chunk::MIN_SUBCHUNK_INDEX;
		}
		$maxCY = ($this->waterAroundMaxY + 1) >> SubChunk::COORD_BIT_SIZE;
		if($maxCY > Chunk::MAX_SUBCHUNK_INDEX){
			$maxCY = Chunk::MAX_SUBCHUNK_INDEX;
		}
		if(
			$world === $this->watchWorld && $minCX === $this->watchMinCX && $maxCX === $this->watchMaxCX && $minCZ === $this->watchMinCZ &&
			$maxCZ === $this->watchMaxCZ && $minCY === $this->watchMinCY && $maxCY === $this->watchMaxCY &&
			(count($this->watchEdgeChunks) === 0 || self::chunksStillLoaded($world, $this->watchEdgeChunks))
		){
			//the same subchunks as for the last water (a single chunk is the mob's, which isn't unloaded while the mob is in
			//it; a chunk replaced tells the mob, see onNearbyBlockChange()): only their counts are taken again
			$counts = [];
			foreach($this->waterAroundSubChunks as $subChunk){
				$counts[] = $subChunk->getBlockModificationCount();
			}
			$this->waterAroundCounts = $counts;
			return;
		}
		$this->watchWorld = null;
		$this->waterAroundSubChunks = $this->waterAroundCounts = $this->watchEdgeChunks = [];
		$edge = $minCX !== $maxCX || $minCZ !== $maxCZ;
		$loaded = $world->getLoadedChunks();
		for($cx = $minCX; $cx <= $maxCX; ++$cx){
			for($cz = $minCZ; $cz <= $maxCZ; ++$cz){
				$hash = World::chunkHash($cx, $cz);
				$chunk = $loaded[$hash] ?? null;
				if($chunk === null){
					//not loaded: no water kept next to it (it could be loaded with other blocks)
					$this->forgetWaterAround();
					return;
				}
				if($edge){
					$this->watchEdgeChunks[$hash] = $chunk;
				}
				for($cy = $minCY; $cy <= $maxCY; ++$cy){
					$subChunk = $chunk->getSubChunk($cy);
					$this->waterAroundSubChunks[] = $subChunk;
					$this->waterAroundCounts[] = $subChunk->getBlockModificationCount();
				}
			}
		}
		$this->watchWorld = $world;
		$this->watchMinCX = $minCX;
		$this->watchMaxCX = $maxCX;
		$this->watchMinCZ = $minCZ;
		$this->watchMaxCZ = $maxCZ;
		$this->watchMinCY = $minCY;
		$this->watchMaxCY = $maxCY;
	}

	/**
	 * Returns whether the given chunks (by chunk hash) are still the world's: a neighbouring chunk of the mob's can be
	 * unloaded (a chunk loaded or replaced tells the entities around it, see onNearbyBlockChange()).
	 *
	 * @param Chunk[] $chunks
	 * @phpstan-param array<int, Chunk> $chunks
	 */
	private static function chunksStillLoaded(World $world, array $chunks) : bool{
		$loaded = $world->getLoadedChunks();
		foreach($chunks as $hash => $chunk){
			if(($loaded[$hash] ?? null) !== $chunk){
				return false;
			}
		}
		return true;
	}

	/**
	 * Returns whether no block was changed in the subchunks noted by watchWaterAroundSubChunks() since, and they are
	 * still in the world.
	 */
	private function waterAroundUnchanged() : bool{
		$subChunks = $this->waterAroundSubChunks;
		foreach($this->waterAroundCounts as $i => $count){
			if($subChunks[$i]->getBlockModificationCount() !== $count){
				return false;
			}
		}
		return count($this->watchEdgeChunks) === 0 || ($this->watchWorld !== null && self::chunksStillLoaded($this->watchWorld, $this->watchEdgeChunks));
	}

	/**
	 * Returns whether the box of the mob covers the blocks of $waterAround (the same block range as
	 * Entity::getBlocksAroundWithEntityInsideActions()).
	 *
	 * @param bool $lookedUpAgain whether an entity without this cache would look the blocks up again at this point (after
	 *                            a movement, see Entity::move()): the blocks are then also checked for changes (see
	 *                            waterAroundUnchanged()), unless move() just did it. Otherwise (no movement since) they
	 *                            are what Entity keeps for an entity that doesn't move.
	 */
	private function isWaterAroundValid(bool $lookedUpAgain) : bool{
		if($this->waterAround === null || $this->waterAroundWorld !== $this->location->world){
			return false;
		}
		$bb = $this->boundingBox;
		if($bb !== $this->waterAroundBox || $this->keepMovement){
			//each movement makes a new box (see move())
			if(
				(int) floor($bb->minX + 0.001) !== $this->waterAroundMinX || (int) floor($bb->minY + 0.001) !== $this->waterAroundMinY ||
				(int) floor($bb->minZ + 0.001) !== $this->waterAroundMinZ || (int) floor($bb->maxX - 0.001) !== $this->waterAroundMaxX ||
				(int) floor($bb->maxY - 0.001) !== $this->waterAroundMaxY || (int) floor($bb->maxZ - 0.001) !== $this->waterAroundMaxZ
			){
				return false;
			}
			$this->waterAroundBox = $bb;
		}
		if($lookedUpAgain && $this->waterAroundWatched && !$this->waterAroundFresh && !$this->waterAroundUnchanged()){
			$this->forgetWaterAround();
			return false;
		}
		return true;
	}

	/**
	 * Inside the same plain water blocks as at the last full check, nothing that check works out changes (touching
	 * water, the water surface, no current pushes these mobs): only what the water does to the mob on every tick
	 * (Water::onEntityInside()) is done again. A swimming mob stays in the same blocks for several ticks.
	 */
	protected function checkBlockIntersections() : void{
		if($this->waterAroundChecked && !$this->onGround && $this->isWaterAroundValid($this->blocksAround === null) && !$this->canBeMovedByCurrents()){
			$this->blocksAround = $this->waterAround;
			$this->resetFallDistance();
			if($this->isOnFire()){
				$this->extinguish(EntityExtinguishEvent::CAUSE_WATER);
			}
			return;
		}
		parent::checkBlockIntersections();
		$this->waterAroundChecked = $this->waterAround !== null && $this->blocksAround === $this->waterAround;
	}

	/**
	 * A swimming mob moves on most ticks, mostly within the same water blocks: while the whole movement stays inside the
	 * blocks of $waterAround and none of them has a collision box (looked up once for these blocks, overhanging boxes of
	 * the blocks next to them included), there is nothing to collide with and the collision lookup is skipped, like for
	 * an entity moving through blocks. Water blocks are plain water: no cobweb or berry bush slows it down there. A block
	 * change next to the mob forgets the blocks (see onNearbyBlockChange() and waterAroundUnchanged()).
	 */
	protected function move(float $dx, float $dy, float $dz) : void{
		if(!$this->keepMovement && $this->ySize === 0.0 && $this->waterAroundFree !== false && $this->isWaterAroundValid(true)){
			$bb = $this->boundingBox;
			//the same block range as the collision lookup of Entity::move() (World::getBlockCollisionBoxes())
			if(
				(int) floor($bb->minX + ($dx < 0 ? $dx : 0.0)) >= $this->waterAroundMinX && (int) floor($bb->maxX + ($dx > 0 ? $dx : 0.0)) <= $this->waterAroundMaxX &&
				(int) floor($bb->minY + ($dy < 0 ? $dy : 0.0)) >= $this->waterAroundMinY && (int) floor($bb->maxY + ($dy > 0 ? $dy : 0.0)) <= $this->waterAroundMaxY &&
				(int) floor($bb->minZ + ($dz < 0 ? $dz : 0.0)) >= $this->waterAroundMinZ && (int) floor($bb->maxZ + ($dz > 0 ? $dz : 0.0)) <= $this->waterAroundMaxZ &&
				($this->waterAroundFree ??= $this->isWaterAroundFree())
			){
				//moved in place without collisions: a new box like any movement (Mob pushing compares the boxes). The blocks
				//were just checked for changes: not again for the block intersections of this movement
				$this->boundingBox = clone $bb;
				$this->keepMovement = true;
				$this->waterAroundFresh = true;
				try{
					parent::move($dx, $dy, $dz);
				}finally{
					$this->keepMovement = false;
					$this->waterAroundFresh = false;
				}
				return;
			}
		}
		parent::move($dx, $dy, $dz);
	}

	private function isWaterAroundFree() : bool{
		$range = new AxisAlignedBB(
			$this->waterAroundMinX, $this->waterAroundMinY, $this->waterAroundMinZ,
			$this->waterAroundMaxX + 0.999, $this->waterAroundMaxY + 0.999, $this->waterAroundMaxZ + 0.999
		);
		return count($this->getWorld()->getBlockCollisionBoxes($range)) === 0;
	}

	public function onNearbyBlockChange() : void{
		$this->forgetWaterAround();
		$this->watchWorld = null; //the chunk may have been replaced
		parent::onNearbyBlockChange();
	}
}
