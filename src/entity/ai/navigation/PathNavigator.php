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

namespace pocketmine\entity\ai\navigation;

use pocketmine\entity\ai\AiBudget;
use pocketmine\entity\Entity;
use pocketmine\entity\Mob;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\World;
use function abs;
use function array_column;
use function array_slice;
use function ceil;
use function count;
use function floor;
use function max;
use function min;
use function sqrt;

/**
 * Plans paths for a walking mob and feeds their waypoints to its {@link \pocketmine\entity\ai\control\MoveControl}.
 *
 * Path searches are rationed by {@link AiBudget}: a request that doesn't get budget stays pending and is retried on
 * the next ticks. Following a moving entity reuses the current path until the entity has moved away from its end,
 * and failed searches to the same block are not retried for a while.
 *
 * Like vanilla PathNavigation, the mob looks for a new path when a block near the rest of its path changes (at most
 * every {@link PathNavigator::RECOMPUTE_TICKS} ticks, see {@link PathRecomputeWatch}), gives up a waypoint it doesn't
 * reach in three times the time it should take, and gives up the path when it hardly moved for
 * {@link PathNavigator::STUCK_CHECK_TICKS} ticks. Mobs wider than a block walk in the middle of their footprint (see
 * {@link PathFinder}; not with the native search of chunkutils2 older than 0.3.11), and the mob walks straight to a
 * waypoint further on when nothing is in the way, instead of zig-zagging from block to block.
 */
class PathNavigator{
	/** Ticks between two progress checks (vanilla PathNavigation STUCK_CHECK_INTERVAL). */
	private const STUCK_CHECK_TICKS = 100;
	private const FAILED_RETRY_TICKS = 40;
	private const ENTITY_REPATH_TICKS = 10;
	private const SAFE_STEP_RECHECK_TICKS = 10;
	/** Ticks between two searches started by block changes near the path (vanilla MAX_TIME_RECOMPUTE). */
	private const RECOMPUTE_TICKS = 20;
	/** Farthest waypoint ahead the mob walks straight to. */
	private const STRAIGHT_LOOKAHEAD = 6;
	/** Room a straight walk keeps between the mob and walls, holes and dangers, in blocks. */
	private const STRAIGHT_MARGIN = 0.3;

	private PathFinder $finder;
	private NavigationOptions $options;
	/** Danger mask of the options, the one the path finder applies */
	private int $dangerMask = PathBlockTypes::DANGER;
	private ?Path $path = null;
	private float $speedMultiplier = 1.0;

	/** @phpstan-var array{int, int, int, float}|null */
	private ?array $pending = null;
	/** Tick on which the pending search was first refused by the budget, -1 if it wasn't (see AiBudget). */
	private int $searchWaitingSince = -1;

	private ?Entity $followed = null;
	private int $lastRepathTick = -1000;

	private int $failedX = 0;
	private int $failedY = 0;
	private int $failedZ = 0;
	private int $failedUntil = -1;

	private int $stuckCheckTick = 0;
	private float $stuckX = 0.0;
	private float $stuckZ = 0.0;

	/** Waypoint the timeout runs for, ticks spent on it and the ticks it should take (vanilla timeoutLimit) */
	private int $timeoutIndex = -1;
	private int $timeoutTicks = 0;
	private float $timeoutLimit = 0.0;

	/** Last block checked by isSafeStep(), its result and until when it is reused */
	private int $stepX = 0;
	private int $stepY = 0;
	private int $stepZ = 0;
	private bool $stepSafe = false;
	private int $stepCheckedUntil = -1;
	/** Free blocks the mob needs above its feet */
	private int $headBlocks;

	/** Footprint of the mob for its path, and the offset from a node to where the mob stands on it */
	private int $footprint = 1;
	private float $nodeOffset = 0.5;
	/** Accept distance of the current path, for the searches after block changes */
	private float $pathAccept = 0.0;
	/** A block near the path changed: search again */
	private bool $recomputeRequested = false;
	/** Watch of the world the path is registered in, and the blocks the path cares about */
	private ?PathRecomputeWatch $watch = null;
	private int $boundsMinX = 0;
	private int $boundsMinY = 0;
	private int $boundsMinZ = 0;
	private int $boundsMaxX = 0;
	private int $boundsMaxY = 0;
	private int $boundsMaxZ = 0;
	/** Waypoint index for which the straight walk was last looked for */
	private int $straightIndex = -1;
	/** Waypoint index for which canCutCorner() was last worked out, and its result */
	private int $cutCornerIndex = -1;
	private bool $cutCorner = false;

	/**
	 * @param bool                   $canSwim    false keeps the paths out of water whatever the options say
	 * @param bool                   $avoidWater true makes the paths avoid water whatever the options say
	 * @param NavigationOptions|null $options    what the paths may go through, the mob's vanilla navigation by default
	 *                                           (see {@link NavigationOptions::forEntity()})
	 */
	public function __construct(
		private Mob $mob,
		private int $maxDrop = 3,
		bool $canSwim = true,
		bool $avoidWater = false,
		?NavigationOptions $options = null
	){
		$this->headBlocks = max(0, (int) ceil($mob->getSize()->getHeight()) - 1);
		$this->finder = new PathFinder($mob->getWorld(), $this->headBlocks, $maxDrop, $canSwim, $avoidWater, AiBudget::getMaxPathNodes());
		//a copy: the caller's options may be shared by several mobs
		$options = $options !== null ? clone $options : NavigationOptions::forEntity($mob::getNetworkTypeId());
		if(!$canSwim){
			$options->setCanPathOverWater(false)->setAmphibious(false)->setCanFloat(false);
		}
		if($avoidWater){
			$options->setAvoidWater(true);
		}
		$this->setOptions($options);
	}

	public function getPathFinder() : PathFinder{
		return $this->finder;
	}

	/**
	 * Returns a copy of what the mob's paths may go through: changes to it apply once passed to
	 * {@link PathNavigator::setOptions()}.
	 */
	public function getOptions() : NavigationOptions{
		return clone $this->options;
	}

	/**
	 * Sets what the mob's paths may go through (applied from the next search on). The navigator keeps a copy: later
	 * changes to $options apply once passed here again.
	 */
	public function setOptions(NavigationOptions $options) : void{
		$this->options = clone $options;
		//straight walks don't cut through what paths only cross at a cost (fire for vindicators): a path through it
		//is followed node by node
		$this->dangerMask = $options->getDangerMask() | $options->getCostlyMask();
		$this->finder->applyOptions($this->options);
	}

	/**
	 * Returns the highest drop the mob's paths, and its straight steps towards a target, go down.
	 */
	public function getMaxDrop() : int{
		return $this->maxDrop;
	}

	public function getPath() : ?Path{
		return $this->path;
	}

	/**
	 * Returns the offset from a node of the current path to the point the mob walks to: half its footprint (0.5, the
	 * middle of the block, for mobs narrower than a block).
	 */
	public function getNodeOffset() : float{
		return $this->nodeOffset;
	}

	/**
	 * Whether the navigator has nothing to do (no path being followed and no search pending).
	 */
	public function isDone() : bool{
		return $this->path === null && $this->pending === null;
	}

	public function stop() : void{
		$this->clearPath();
		$this->pending = null;
		$this->followed = null;
		$this->mob->getMoveControl()->stop();
	}

	/**
	 * @internal Called when the mob is removed: forgets the path without touching the mob.
	 */
	public function dispose() : void{
		$this->clearPath();
		$this->pending = null;
		$this->followed = null;
	}

	public function setSpeedMultiplier(float $speedMultiplier) : void{
		$this->speedMultiplier = $speedMultiplier;
	}

	/**
	 * Returns whether the mob, walking from where it stands into the block column of the given point, would neither
	 * drop further than its paths do nor end up in or on something that hurts it (fire, lava, magma, cactus...): what a
	 * mob going straight at a target close by, without a path, checks before each step so that it doesn't follow a
	 * target knocked off a cliff.
	 *
	 * The drop is the navigator's max drop, the same with or without a target. Bedrock: a number of blocks per mob,
	 * minecraft:preferred_path max_fall_blocks, 3 unless set (warden 20, villagers and iron golems 1). Java instead lets
	 * a mob with a target drop further the more health it has (Mob.getMaxFallDistance(): 12 blocks for a zombie at full
	 * health on normal).
	 *
	 * A wall in the way is safe (the mob bumps into it), a block it can step onto is safe unless something dangerous is
	 * on top of it, and so are water and unloaded chunks, as for paths. The result for a block is reused for a few ticks.
	 */
	public function isSafeStep(float $x, float $z) : bool{
		$loc = $this->mob->getLiveLocation();
		$bx = (int) floor($x);
		$by = (int) floor($loc->y + 0.01);
		$bz = (int) floor($z);
		$now = $this->mob->getAiTick();
		if($now <= $this->stepCheckedUntil && $bx === $this->stepX && $by === $this->stepY && $bz === $this->stepZ){
			return $this->stepSafe;
		}
		$this->stepX = $bx;
		$this->stepY = $by;
		$this->stepZ = $bz;
		$this->stepCheckedUntil = $now + self::SAFE_STEP_RECHECK_TICKS;
		return $this->stepSafe = self::isSafeColumn($this->mob->getWorld(), $bx, $by, $bz, $this->maxDrop, $this->headBlocks);
	}

	/**
	 * Returns whether a mob with its feet in block row $y, needing $headBlocks free blocks above its feet, can step into
	 * the column ($x, $z): not into or onto something dangerous (the blocks the path finder avoids: fire, lava, magma,
	 * berry bushes...), not dropping more than $maxDrop blocks, nor out of the bottom of the world (an island of a void
	 * world).
	 *
	 * A block in its feet row is one it steps onto, or bumps into if it is a wall: its legs and head then go into the
	 * rows above. That is also how it walks along a floor lower than a full block (soul sand, dirt path, farmland,
	 * slabs...), whose row is its feet row.
	 */
	public static function isSafeColumn(World $world, int $x, int $y, int $z, int $maxDrop, int $headBlocks) : bool{
		//the blocks of the column from its chunk, looked up once
		$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		$minY = $world->getMinY();
		$maxY = $world->getMaxY();
		$feet = PathBlockTypes::inColumn($world, $chunk, $minY, $maxY, $x, $y, $z);
		if(($feet & PathBlockTypes::DANGER) !== 0){
			return false;
		}
		if(($feet & PathBlockTypes::PASSABLE) === 0){
			if(($feet & PathBlockTypes::TALL) !== 0){
				return true; //a fence or a wall: it bumps into it
			}
			for($i = 1; $i <= $headBlocks + 1; ++$i){
				$above = PathBlockTypes::inColumn($world, $chunk, $minY, $maxY, $x, $y + $i, $z);
				if(($above & PathBlockTypes::DANGER) !== 0){
					return false;
				}
				if(($above & PathBlockTypes::PASSABLE) === 0){
					return true; //too high to step onto: it bumps into it
				}
			}
			return true;
		}
		for($i = 1; $i <= $headBlocks; ++$i){
			$head = PathBlockTypes::inColumn($world, $chunk, $minY, $maxY, $x, $y + $i, $z);
			if(($head & PathBlockTypes::DANGER) !== 0){
				return false;
			}
			if(($head & PathBlockTypes::PASSABLE) === 0){
				return true; //too low to walk into: it bumps into it
			}
		}
		if(($feet & PathBlockTypes::WATER) !== 0){
			return true;
		}
		for($d = 1; $d <= $maxDrop + 1; ++$d){
			if($y - $d < $minY){
				//the bottom of the world is no floor here (paths see it as one): it falls into the void
				return false;
			}
			$below = PathBlockTypes::inColumn($world, $chunk, $minY, $maxY, $x, $y - $d, $z);
			if(($below & PathBlockTypes::DANGER) !== 0){
				return false;
			}
			if(($below & PathBlockTypes::WATER) !== 0){
				return true;
			}
			if(($below & PathBlockTypes::PASSABLE) === 0){
				//lands on it: on top of a full block (a drop of $d - 1 blocks), inside a low one (a drop of $d)
				return ($below & PathBlockTypes::LOW) === 0 || $d <= $maxDrop;
			}
		}
		return false;
	}

	/**
	 * Works out the footprint of the mob from its current width (babies are narrower) and returns it (1 with older native
	 * searches, see {@link PathFinder::getEffectiveFootprint()}).
	 */
	private function updateFootprint() : int{
		$footprint = $this->finder->getEffectiveFootprint(PathFinder::footprintOf($this->mob->getSize()->getWidth()));
		if($footprint !== $this->footprint){
			$this->footprint = $footprint;
			$this->finder->setFootprint($footprint);
		}
		return $footprint;
	}

	/**
	 * Requests a path to the given position. Returns false if a recent search to the same block failed.
	 */
	public function moveTo(float $x, float $y, float $z, float $speedMultiplier, float $acceptDistance = 0.0) : bool{
		$footprint = $this->updateFootprint();
		$bx = PathFinder::nodeCoordinate($x, $footprint);
		$by = (int) floor($y);
		$bz = PathFinder::nodeCoordinate($z, $footprint);
		$this->speedMultiplier = $speedMultiplier;
		$this->followed = null;
		if($this->path !== null && $this->path->getTargetX() === $bx && $this->path->getTargetY() === $by && $this->path->getTargetZ() === $bz && !$this->path->isDone()){
			return true;
		}
		if($this->failedUntil > $this->mob->getAiTick() && $bx === $this->failedX && $by === $this->failedY && $bz === $this->failedZ){
			return false;
		}
		$this->pending = [$bx, $by, $bz, $acceptDistance];
		return true;
	}

	/**
	 * Requests a path to an entity, repathing when it moves away from the end of the current path.
	 */
	public function moveToEntity(Entity $entity, float $speedMultiplier, float $acceptDistance = 1.0) : bool{
		$this->speedMultiplier = $speedMultiplier;
		$footprint = $this->updateFootprint();
		//asked every few ticks by every chasing mob: no copy of the location
		$pos = Mob::getLiveLocationOf($entity) ?? $entity->getLocation();
		$bx = PathFinder::nodeCoordinate($pos->x, $footprint);
		$by = (int) floor($pos->y);
		$bz = PathFinder::nodeCoordinate($pos->z, $footprint);
		$now = $this->mob->getAiTick();
		if($this->followed === $entity && ($this->path !== null || $this->pending !== null)){
			$path = $this->path;
			if($path !== null && abs($path->getTargetX() - $bx) + abs($path->getTargetY() - $by) + abs($path->getTargetZ() - $bz) <= 1){
				return true; //target hasn't moved enough
			}
			if($now - $this->lastRepathTick < self::ENTITY_REPATH_TICKS){
				return true;
			}
		}
		if($this->failedUntil > $now && $bx === $this->failedX && $by === $this->failedY && $bz === $this->failedZ){
			return false;
		}
		$this->followed = $entity;
		$this->pending = [$bx, $by, $bz, $acceptDistance];
		return true;
	}

	/**
	 * Returns whether a waypoint within horizontal reach of the mob counts as reached, given its height above the mob's
	 * feet (negative when below). Vanilla: within one block up or down. A waypoint at the bottom of a drop is only
	 * reached once the mob has dropped down to it, not while it still stands on the edge above.
	 *
	 * Only asked for waypoints a block or more above or below: those within a block always count as reached.
	 */
	protected function isWaypointReachedAtHeight(float $dy) : bool{
		return abs($dy) < 1.0;
	}

	/**
	 * @internal Called by {@link PathRecomputeWatch} when a block changed in a chunk the path crosses: the mob looks for
	 * a new path if the block is in or next to the rest of the path.
	 *
	 * @param int $newFlags     {@link PathBlockTypes} flags of the block now
	 * @param int $changedFlags flags that changed
	 */
	public function onBlockChanged(World $world, int $x, int $y, int $z, int $newFlags, int $changedFlags) : void{
		$path = $this->path;
		if($path === null || $this->recomputeRequested || $world !== $this->mob->getWorld() ||
			$x < $this->boundsMinX || $x > $this->boundsMaxX || $y < $this->boundsMinY || $y > $this->boundsMaxY || $z < $this->boundsMinZ || $z > $this->boundsMaxZ
		){
			return;
		}
		if(($newFlags & PathBlockTypes::OPENABLE_DOOR) !== 0 && $this->finder->passesDoors() &&
			($changedFlags & ~(PathBlockTypes::CLOSED_DOOR | PathBlockTypes::TALL | PathBlockTypes::PASSABLE)) === 0
		){
			return; //a door opened or closed: the same for mobs which open or break doors (villagers going through)
		}
		$footprint = $this->footprint;
		$headBlocks = $this->headBlocks;
		for($i = max(0, $path->getIndex() - 1), $count = $path->getLength(); $i < $count; ++$i){
			$node = $path->getNode($i);
			if($node !== null &&
				$x >= $node[0] - 1 && $x <= $node[0] + $footprint && $z >= $node[2] - 1 && $z <= $node[2] + $footprint &&
				$y >= $node[1] - 1 && $y <= $node[1] + $headBlocks + 1
			){
				$this->recomputeRequested = true;
				return;
			}
		}
	}

	/**
	 * @internal Whether a block change near the path asked for a new search that hasn't run yet (for tests).
	 */
	public function isRecomputePending() : bool{
		return $this->recomputeRequested;
	}

	private function clearPath() : void{
		$this->path = null;
		$this->recomputeRequested = false;
		if($this->watch !== null){
			$this->watch->unregister($this);
			$this->watch = null;
		}
	}

	private function setPath(Path $path, float $accept, int $now, float $x, float $z) : void{
		$this->path = $path;
		$this->pathAccept = $accept;
		$this->recomputeRequested = false;
		$this->stuckCheckTick = $now;
		$this->stuckX = $x;
		$this->stuckZ = $z;
		$this->timeoutIndex = -1;
		$this->timeoutTicks = 0;
		$this->straightIndex = -1;
		$this->cutCornerIndex = -1;

		$nodes = $path->getNodes();
		if(!isset($nodes[0])){
			return;
		}
		//the blocks the nodes stand on, their feet and head rows, and the blocks around them (see onBlockChanged()); the
		//lowest and highest coordinates of the nodes are worked out natively, not node by node
		$xs = array_column($nodes, 0);
		$ys = array_column($nodes, 1);
		$zs = array_column($nodes, 2);
		$this->boundsMinX = min($xs) - 1;
		$this->boundsMinY = min($ys) - 1;
		$this->boundsMinZ = min($zs) - 1;
		$this->boundsMaxX = max($xs) + $this->footprint;
		$this->boundsMaxY = max($ys) + $this->headBlocks + 1;
		$this->boundsMaxZ = max($zs) + $this->footprint;
		$watch = $this->mob->getWorld()->getPathRecomputeWatch();
		if($this->watch !== null && $this->watch !== $watch){
			$this->watch->unregister($this);
		}
		$this->watch = $watch;
		$watch->register($this, $this->boundsMinX, $this->boundsMinZ, $this->boundsMaxX, $this->boundsMaxZ);
	}

	public function tick() : void{
		if($this->path === null && $this->pending === null){
			//nothing to do (most mobs on most ticks). No recompute can be asked for without a path (clearPath()), and a
			//later request starts waiting afresh, as below
			$this->searchWaitingSince = -1;
			return;
		}
		$mob = $this->mob;
		$now = $mob->getAiTick();
		if($this->recomputeRequested && $this->pending === null && $now - $this->lastRepathTick >= self::RECOMPUTE_TICKS){
			//vanilla recomputePath(): the same target, or where the followed entity is now
			$this->recomputeRequested = false;
			$path = $this->path;
			$followed = $this->followed;
			if($path !== null){
				if($followed !== null && !$followed->isClosed()){
					$pos = Mob::getLiveLocationOf($followed) ?? $followed->getLocation();
					$this->pending = [PathFinder::nodeCoordinate($pos->x, $this->footprint), (int) floor($pos->y), PathFinder::nodeCoordinate($pos->z, $this->footprint), $this->pathAccept];
				}else{
					$this->pending = [$path->getTargetX(), $path->getTargetY(), $path->getTargetZ(), $this->pathAccept];
				}
			}
		}
		if($this->pending === null){
			//nothing asked (or the request was dropped): a later request starts waiting afresh
			$this->searchWaitingSince = -1;
		}elseif(!AiBudget::tryPathSearch($now, $this->searchWaitingSince)){
			//no budget left: the request keeps its place in the queue (a new target replacing it keeps it too)
			if($this->searchWaitingSince < 0){
				$this->searchWaitingSince = $now;
			}
		}else{
			$this->searchWaitingSince = -1;
			[$tx, $ty, $tz, $accept] = $this->pending;
			$this->pending = null;
			$this->lastRepathTick = $now;
			$loc = $mob->getLiveLocation();
			$footprint = $this->updateFootprint();
			$this->nodeOffset = $footprint * 0.5;
			$this->finder->setWorld($mob->getWorld());
			$path = $this->finder->findPath(PathFinder::nodeCoordinate($loc->x, $footprint), (int) floor($loc->y + 0.01), PathFinder::nodeCoordinate($loc->z, $footprint), $tx, $ty, $tz, $accept);
			if($path !== null && $this->options->avoidsSun()){
				$path = $this->trimSunlitPath($path);
			}
			if($path === null || $path->getLength() <= 1 && !$path->reachesTarget()){
				$this->failedX = $tx;
				$this->failedY = $ty;
				$this->failedZ = $tz;
				$this->failedUntil = $now + self::FAILED_RETRY_TICKS;
				$this->clearPath();
				$this->followed = null;
				if(!$this->climbTowards($tx, $ty, $tz)){
					$mob->getMoveControl()->stop();
				}
				return;
			}
			if(!$path->reachesTarget()){
				//partial path: go as close as possible but don't hammer the search for the same target
				$this->failedX = $tx;
				$this->failedY = $ty;
				$this->failedZ = $tz;
				$this->failedUntil = $now + self::FAILED_RETRY_TICKS;
			}
			$path->setIndex(1); //node 0 is where we stand
			$this->setPath($path, $accept, $now, $loc->x, $loc->z);
		}

		$path = $this->path;
		if($path === null){
			return;
		}
		$loc = $mob->getLiveLocation();
		$width = $mob->getSize()->getWidth();
		//vanilla maxDistanceToWaypoint, along X and along Z (a square around the waypoint, like Java followThePath())
		$reach = $width > 0.75 ? $width / 2 : 0.75 - $width / 2;
		$offset = $this->nodeOffset;
		$feetRow = (int) floor($loc->y + 0.01);

		//advance past the waypoints we've reached (also skipping one ahead when already past it). The nodes of a path
		//never change: they are read from the array, with the index kept here and written back to the path (every tick
		//for every mob following a path: no method call per node)
		$nodes = $path->getNodes();
		$index = $path->getIndex();
		$node = null;
		while(($node = $nodes[$index] ?? null) !== null){
			$dx = $node[0] + $offset - $loc->x;
			$dz = $node[2] + $offset - $loc->z;
			$distSq = $dx * $dx + $dz * $dz;
			if(abs($dx) < $reach && abs($dz) < $reach){
				if(abs($node[1] - $loc->y) < 1.0){
					++$index;
					continue;
				}
				//an overriding class may look at the path: it sees the index as it is
				$path->setIndex($index);
				if($this->isWaypointReachedAtHeight($node[1] - $loc->y)){
					$index = $path->getIndex() + 1;
					continue;
				}
				$index = $path->getIndex();
			}
			$next = $nodes[$index + 1] ?? null;
			if($next !== null && $next[1] === $node[1] && $feetRow === $node[1]){
				$nx = $next[0] + $offset - $loc->x;
				$nz = $next[2] + $offset - $loc->z;
				$nextSq = $nx * $nx + $nz * $nz;
				if(abs($nx) < $reach && abs($nz) < $reach){
					$index += 2;
					continue;
				}
				//vanilla shouldTargetNextNodeInDirection(): already between this waypoint and the next one, close to it,
				//go on to the next (not at doors, nor next to what it avoids: vanilla canCutCorner())
				if($distSq < 4.0 && ($nextSq < $distSq || $distSq < 0.5) && $dx * $nx + $dz * $nz < 0.0 && $this->canCutCorner($index, $node)){
					++$index;
					continue;
				}
			}
			break;
		}
		$path->setIndex($index);

		//$node is the current waypoint (the loop only ends on it, or on the end of the path)
		if($node === null){
			$this->clearPath();
			$this->followed = null;
			if($path->reachesTarget() || !$this->climbTowards($path->getTargetX(), $path->getTargetY(), $path->getTargetZ())){
				$mob->getMoveControl()->stop();
			}
			return;
		}
		if($index !== $this->straightIndex){
			$this->walkStraightAhead($path, $node, $loc->x, $loc->z, $feetRow);
			$index = $this->straightIndex = $path->getIndex();
			$node = $nodes[$index] ?? $node;
		}

		$mob->getMoveControl()->setWantedPosition($node[0] + $offset, $node[1], $node[2] + $offset, $this->speedMultiplier);

		//vanilla doStuckDetection(): a waypoint not reached in three times the time it takes is given up. Java's
		//timeoutTimer keeps running from waypoint to waypoint (reset on a new path) against the limit of the current
		//waypoint, worked out from where the mob is when it becomes the current one: with the waypoints this navigator
		//moves on to when the mob is already close to them (straight walks, corners cut), that limit is a few ticks and
		//golems and spiders gave up corridors halfway (bot path_golem_wide_door, path_spider_corridor), so the time
		//counts from the waypoint it is on
		if($index !== $this->timeoutIndex){
			$this->timeoutIndex = $index;
			$this->timeoutTicks = 0;
			$speed = $mob->getMovementSpeed() * $this->speedMultiplier;
			$tx = $node[0] + $offset - $loc->x;
			$ty = $node[1] - $loc->y;
			$tz = $node[2] + $offset - $loc->z;
			$this->timeoutLimit = $speed > 0 ? sqrt($tx * $tx + $ty * $ty + $tz * $tz) / $speed * 20 : 0.0;
		}elseif(++$this->timeoutTicks > $this->timeoutLimit * 3 && $this->timeoutLimit > 0){
			$this->stop();
			return;
		}

		//and a mob which hardly moved for a while gives up its path (the distance grows with the speed)
		if($now - $this->stuckCheckTick >= self::STUCK_CHECK_TICKS){
			$moved = ($loc->x - $this->stuckX) ** 2 + ($loc->z - $this->stuckZ) ** 2;
			$this->stuckCheckTick = $now;
			$this->stuckX = $loc->x;
			$this->stuckZ = $loc->z;
			$speed = $mob->getMovementSpeed() * $this->speedMultiplier;
			$threshold = ($speed >= 1.0 ? $speed : $speed * $speed) * self::STUCK_CHECK_TICKS * 0.25;
			if($moved < $threshold * $threshold){
				$this->stop();
			}
		}
	}

	/**
	 * Called when no path takes a mob further towards a target above it: if the mob climbs (minecraft:can_climb) and
	 * stands on a ladder, it walks towards the target, which presses it against the ladder and makes it climb (see
	 * {@link Mob::tryChangeMovement()}), and takes it over the top. Paths don't go up ladders: like vanilla, a mob gets
	 * up a ladder by walking into it at the end of the path closest to its target. Returns whether it does.
	 */
	private function climbTowards(int $tx, int $ty, int $tz) : bool{
		$mob = $this->mob;
		$loc = $mob->getLiveLocation();
		if(!$mob->canClimb() || $ty <= $loc->y ||
			(PathBlockTypes::at($mob->getWorld(), (int) floor($loc->x), (int) floor($loc->y), (int) floor($loc->z)) & PathBlockTypes::CLIMBABLE) === 0
		){
			return false;
		}
		$mob->getMoveControl()->setWantedPosition($tx + $this->nodeOffset, $loc->y, $tz + $this->nodeOffset, $this->speedMultiplier);
		return true;
	}

	/**
	 * Vanilla navigation avoid_sun (Java GroundPathNavigation.trimPath() with avoidSun, which RestrictSunGoal turns on
	 * by day for mobs with nothing on their head): by day, a mob out of the sun (and out of water) stops its new path
	 * before the first node under the open sky, so skeletons wander in the shade of a tree or a cave and don't walk out
	 * into the sun. A mob already in the sun keeps its whole path (it is burning anyway, see
	 * {@link \pocketmine\entity\ai\goal\FleeSunGoal}). Once per search, only for mobs with avoid_sun.
	 */
	private function trimSunlitPath(Path $path) : Path{
		$mob = $this->mob;
		$world = $mob->getWorld();
		if($world->getDimension() !== Dimension::OVERWORLD || $world->getSkyLightReduction() >= 4 || $mob->isInWater() ||
			!$mob->getArmorInventory()->getHelmet()->isNull()
		){
			return $path;
		}
		$loc = $mob->getLiveLocation();
		$eye = (int) floor($mob->getEyeHeight());
		$offset = $this->nodeOffset;
		if($world->getPotentialBlockSkyLightAt((int) floor($loc->x), (int) floor($loc->y + $mob->getEyeHeight()), (int) floor($loc->z)) >= 15){
			return $path;
		}
		$nodes = $path->getNodes();
		//sky light read straight from the chunks (nodes follow each other: mostly the same chunk), not block by block
		//through the world
		$minY = $world->getMinY();
		$maxY = $world->getMaxY();
		$chunkX = $chunkZ = null;
		$chunk = null;
		foreach($nodes as $i => [$x, $y, $z]){
			//the middle of the footprint: the block a mob narrower than a block stands in
			$bx = (int) floor($x + $offset);
			$by = $y + $eye;
			$bz = (int) floor($z + $offset);
			if($by >= $maxY){
				$sunlit = true;
			}elseif($by < $minY){
				$sunlit = false;
			}else{
				if($bx >> Chunk::COORD_BIT_SIZE !== $chunkX || $bz >> Chunk::COORD_BIT_SIZE !== $chunkZ){
					$chunkX = $bx >> Chunk::COORD_BIT_SIZE;
					$chunkZ = $bz >> Chunk::COORD_BIT_SIZE;
					$chunk = $world->getChunk($chunkX, $chunkZ);
					if($chunk !== null && $chunk->isLightPopulated() !== true){
						$chunk = null; //no light worked out yet: no sun (as World::getPotentialBlockSkyLightAt())
					}
				}
				$sunlit = $chunk !== null && $chunk->getSubChunk($by >> Chunk::COORD_BIT_SIZE)->getBlockSkyLightArray()->get($bx & Chunk::COORD_MASK, $by & Chunk::COORD_MASK, $bz & Chunk::COORD_MASK) >= 15;
			}
			if($sunlit){
				return new Path(array_slice($nodes, 0, $i), false, $path->getTargetX(), $path->getTargetY(), $path->getTargetZ());
			}
		}
		return $path;
	}

	/**
	 * Vanilla canCutCorner(): the mob doesn't go on to the next waypoint before reaching this one when this one is a
	 * door, or next to something it avoids. Read from the world once per waypoint, when the mob gets close to it.
	 *
	 * @phpstan-param array{int, int, int} $node
	 */
	private function canCutCorner(int $index, array $node) : bool{
		if($index !== $this->cutCornerIndex){
			[$x, $y, $z] = $node;
			$this->cutCornerIndex = $index;
			$this->finder->setWorld($this->mob->getWorld());
			$this->cutCorner = (PathBlockTypes::at($this->mob->getWorld(), $x, $y, $z) & (PathBlockTypes::SIGHT_BY_SHAPE | PathBlockTypes::CLOSED_DOOR)) === 0 &&
				!$this->finder->isNearDanger($x, $y, $z);
		}
		return $this->cutCorner;
	}

	/**
	 * Path smoothing: when the waypoints ahead turn (diagonal and straight steps mixed, like most paths to a point that
	 * isn't straight ahead), the mob walks straight to the farthest of the next {@link PathNavigator::STRAIGHT_LOOKAHEAD}
	 * waypoints on its level it can reach in a straight line (see {@link PathNavigator::canWalkStraight()}). Checked
	 * once per waypoint, on the ground only.
	 *
	 * @phpstan-param array{int, int, int} $node
	 */
	private function walkStraightAhead(Path $path, array $node, float $x, float $z, int $feetRow) : void{
		if($feetRow !== $node[1] || !$this->mob->isOnGround()){
			return;
		}
		$index = $path->getIndex();
		$nodes = $path->getNodes(); //the nodes of a path never change
		$last = min($index + self::STRAIGHT_LOOKAHEAD, count($nodes) - 1);
		$far = $index;
		while($far < $last && ($n = $nodes[$far + 1] ?? null) !== null && $n[1] === $node[1]){
			++$far;
		}
		if($far === $index){
			return;
		}
		//waypoints in a straight line (from the one before) need nothing
		$previous = $nodes[$index - 1] ?? $node;
		$stepX = $node[0] - $previous[0];
		$stepZ = $node[2] - $previous[2];
		$straight = true;
		for($k = $index; $k < $far; ++$k){
			$a = $nodes[$k] ?? null;
			$b = $nodes[$k + 1] ?? null;
			if($a === null || $b === null || $b[0] - $a[0] !== $stepX || $b[2] - $a[2] !== $stepZ){
				$straight = false;
				break;
			}
		}
		if($straight){
			return;
		}
		$offset = $this->nodeOffset;
		/** @phpstan-var array<int, array<int, bool>> $columns */
		$columns = [];
		$world = $this->mob->getWorld();
		$radius = $this->mob->getSize()->getWidth() / 2 + self::STRAIGHT_MARGIN;
		for($k = $far; $k > $index; --$k){
			$target = $nodes[$k] ?? null;
			if($target !== null && $this->canWalkStraight($world, $x, $z, $node[1], $target[0] + $offset, $target[2] + $offset, $radius, $columns)){
				$path->setIndex($k);
				return;
			}
		}
	}

	/**
	 * Returns whether the mob can walk in a straight line on row $y from (fromX, fromZ) to (toX, toZ): every column its
	 * box sweeps over, and {@link PathNavigator::STRAIGHT_MARGIN} around it ($radius: half its width plus that margin),
	 * has room for it, a floor and nothing it avoids, nor water or a door, trapdoor or fence gate.
	 *
	 * @phpstan-param array<int, array<int, bool>> $columns columns already checked
	 */
	private function canWalkStraight(World $world, float $fromX, float $fromZ, int $y, float $toX, float $toZ, float $radius, array &$columns) : bool{
		$dx = $toX - $fromX;
		$dz = $toZ - $fromZ;
		$steps = max(1, (int) ceil(sqrt($dx * $dx + $dz * $dz) * 2));
		//the blocks of a column are read from its chunk, looked up once for the columns of the same chunk
		$minY = $world->getMinY();
		$maxY = $world->getMaxY();
		$chunk = null;
		$chunkX = $chunkZ = null;
		//most mobs on most rows: the floor, feet and head blocks of every column are in one subchunk. The columns a step
		//sweeps over are then checked with one call when they are in one chunk (isOpenColumn() for each column otherwise:
		//the same checks of the same columns in the same order, see PathBlockTypes::hasWalkRoom())
		$danger = $this->dangerMask;
		$headBlocks = $this->headBlocks;
		$oneSubChunk = $headBlocks <= 1 && $y > $minY && $y + 1 < $maxY && ($y - 1) >> SubChunk::COORD_BIT_SIZE === ($y + 1) >> SubChunk::COORD_BIT_SIZE;
		//consecutive samples mostly cover the same columns: those were all checked (and open) already
		$lastMinX = $lastMaxX = $lastMinZ = $lastMaxZ = null;
		for($s = 0; $s <= $steps; ++$s){
			$px = $fromX + $dx * $s / $steps;
			$pz = $fromZ + $dz * $s / $steps;
			//(int) floor() without the calls: the cast rounds towards zero, one less below zero unless already whole
			$v = $px - $radius + 0.000001;
			$cxMin = (int) $v;
			if($cxMin > $v){
				--$cxMin;
			}
			$v = $px + $radius - 0.000001;
			$cxMax = (int) $v;
			if($cxMax > $v){
				--$cxMax;
			}
			$v = $pz - $radius + 0.000001;
			$czMin = (int) $v;
			if($czMin > $v){
				--$czMin;
			}
			$v = $pz + $radius - 0.000001;
			$czMax = (int) $v;
			if($czMax > $v){
				--$czMax;
			}
			if($cxMin === $lastMinX && $cxMax === $lastMaxX && $czMin === $lastMinZ && $czMax === $lastMaxZ){
				continue;
			}
			$lastMinX = $cxMin;
			$lastMaxX = $cxMax;
			$lastMinZ = $czMin;
			$lastMaxZ = $czMax;
			if($oneSubChunk && $cxMin >> Chunk::COORD_BIT_SIZE === $cxMax >> Chunk::COORD_BIT_SIZE && $czMin >> Chunk::COORD_BIT_SIZE === $czMax >> Chunk::COORD_BIT_SIZE){
				if($cxMin >> Chunk::COORD_BIT_SIZE !== $chunkX || $czMin >> Chunk::COORD_BIT_SIZE !== $chunkZ){
					$chunkX = $cxMin >> Chunk::COORD_BIT_SIZE;
					$chunkZ = $czMin >> Chunk::COORD_BIT_SIZE;
					$chunk = $world->getChunk($chunkX, $chunkZ);
				}
				if($chunk !== null){
					if(!PathBlockTypes::hasWalkRoom($world, $chunk, $y, $cxMin, $cxMax, $czMin, $czMax, $danger, $headBlocks, $columns)){
						return false;
					}
					continue;
				}
			}
			for($cx = $cxMin; $cx <= $cxMax; ++$cx){
				for($cz = $czMin; $cz <= $czMax; ++$cz){
					$open = $columns[$cx][$cz] ?? null;
					if($open === null){
						if($cx >> Chunk::COORD_BIT_SIZE !== $chunkX || $cz >> Chunk::COORD_BIT_SIZE !== $chunkZ){
							$chunkX = $cx >> Chunk::COORD_BIT_SIZE;
							$chunkZ = $cz >> Chunk::COORD_BIT_SIZE;
							$chunk = $world->getChunk($chunkX, $chunkZ);
						}
						$open = $columns[$cx][$cz] = $this->isOpenColumn($world, $chunk, $minY, $maxY, $cx, $y, $cz);
					}
					if(!$open){
						return false;
					}
				}
			}
		}
		return true;
	}

	/**
	 * Column ($x, $z) of {@link PathNavigator::canWalkStraight()}, given the chunk holding it (null: not loaded) and the
	 * world's height limits.
	 */
	private function isOpenColumn(World $world, ?Chunk $chunk, int $minY, int $maxY, int $x, int $y, int $z) : bool{
		$danger = $this->dangerMask;
		$headBlocks = $this->headBlocks;
		if($headBlocks <= 1 && $chunk !== null && $y > $minY && $y + 1 < $maxY && ($y - 1) >> SubChunk::COORD_BIT_SIZE === ($y + 1) >> SubChunk::COORD_BIT_SIZE){
			//most columns of most mobs: the floor, feet and head blocks are in one subchunk, read with one call. The same
			//answer as the checks below: reading the head and floor blocks up front (and the block above the feet of a mob
			//without head blocks) changes nothing, the reads are pure
			$below = $feet = $head = 0;
			PathBlockTypes::threeInSubChunk($world, $chunk, $x, $y, $z, $below, $feet, $head);
			return ($feet & (PathBlockTypes::PASSABLE | PathBlockTypes::LOW)) !== 0 &&
				($feet & ($danger | PathBlockTypes::WATER | PathBlockTypes::SIGHT_BY_SHAPE | PathBlockTypes::CLOSED_DOOR)) === 0 &&
				($headBlocks === 0 || (($head & PathBlockTypes::PASSABLE) !== 0 && ($head & $danger) === 0)) &&
				(($feet & PathBlockTypes::LOW) !== 0 || (($below & PathBlockTypes::FLOOR) !== 0 && ($below & ($danger | PathBlockTypes::WATER)) === 0));
		}
		$feet = PathBlockTypes::inColumn($world, $chunk, $minY, $maxY, $x, $y, $z);
		if(($feet & (PathBlockTypes::PASSABLE | PathBlockTypes::LOW)) === 0 || ($feet & ($danger | PathBlockTypes::WATER | PathBlockTypes::SIGHT_BY_SHAPE | PathBlockTypes::CLOSED_DOOR)) !== 0){
			return false;
		}
		for($i = 1; $i <= $this->headBlocks; ++$i){
			$head = PathBlockTypes::inColumn($world, $chunk, $minY, $maxY, $x, $y + $i, $z);
			if(($head & PathBlockTypes::PASSABLE) === 0 || ($head & $danger) !== 0){
				return false;
			}
		}
		if(($feet & PathBlockTypes::LOW) !== 0){
			return true;
		}
		$below = PathBlockTypes::inColumn($world, $chunk, $minY, $maxY, $x, $y - 1, $z);
		return ($below & PathBlockTypes::FLOOR) !== 0 && ($below & ($danger | PathBlockTypes::WATER)) === 0;
	}
}
