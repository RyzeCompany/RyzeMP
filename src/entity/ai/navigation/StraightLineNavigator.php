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
use pocketmine\entity\ai\FairTickBudget;
use pocketmine\entity\Entity;
use pocketmine\entity\Mob;
use pocketmine\utils\Utils;
use function abs;
use function array_key_first;
use function ceil;
use function count;
use function floor;
use function max;
use function min;
use function sqrt;

/**
 * Navigation of flying and swimming mobs, with a {@link \pocketmine\entity\ai\control\FlyingMoveControl} or
 * {@link \pocketmine\entity\ai\control\SwimmingMoveControl}. They move in a straight line to the target while the way
 * is clear; with a medium ({@link StraightLineNavigator::MEDIUM_AIR} for flyers, Bedrock navigation.fly / hover;
 * {@link StraightLineNavigator::MEDIUM_WATER} for swimmers, navigation.generic can_swim) a blocked straight segment
 * starts a 3D path search ({@link Path3DFinder}, taken from the path search budget of {@link AiBudget}), and the mob
 * follows the path, skipping the nodes it can see past. The straight segment is checked once per target, every
 * {@link StraightLineNavigator::FOLLOW_CHECK_TICKS} ticks while following an entity, and when the mob stops making
 * progress (along the path: moving at all, a detour leads away from the target first); a mob that is still stuck after
 * a search, or has no way there, gives up (the goals pick another target) and doesn't search for blocks near it again
 * for {@link StraightLineNavigator::FAILED_RETRY_TICKS} ticks, longer after more failures in a row, and nor do mobs
 * like it starting nearby for a while. A partial path (the target can't be reached) takes the mob as close as it gets:
 * a fish stays in the water, a bee outside the leaves.
 *
 * Searches only run while the move control moves in three dimensions (a swimmer in water, a flyer without gravity);
 * otherwise, and without a medium (bats fluttering about, happy ghasts too wide for a cheap search), the mob just goes
 * straight and a mob that makes no progress towards a fixed target for a while gives up.
 */
class StraightLineNavigator extends PathNavigator{
	public const MEDIUM_NONE = 0;
	public const MEDIUM_AIR = Path3DFinder::MEDIUM_AIR;
	public const MEDIUM_WATER = Path3DFinder::MEDIUM_WATER;

	private const STUCK_CHECK_TICKS = 40;
	/** Ticks between two checks of the straight way to a followed entity. */
	public const FOLLOW_CHECK_TICKS = 10;
	/**
	 * Ticks during which a block that couldn't be reached, and its 4x4x4 region, isn't searched for again, after the
	 * first failure in a row: four times as long after the second and after the third and later ones (40, 160, 640).
	 */
	public const FAILED_RETRY_TICKS = 40;
	/** Most failures in a row that make the mob wait longer (see FAILED_RETRY_TICKS). */
	private const MAX_FAILED_BACKOFF = 2;
	/** Regions of failed targets each mob remembers (the flowers round a bee behind glass). */
	private const MAX_FAILED_REGIONS = 4;
	/**
	 * Ticks during which a failed search is shared: other mobs of the same medium and size, starting in the same 8x8x8
	 * region, don't search for blocks near it either (bees of a hive and the flowers behind glass).
	 */
	private const SHARED_FAILED_TICKS = 160;
	private const MAX_SHARED_FAILURES = 512;
	/** Nodes ahead the mob tries to go straight to. */
	private const LOOKAHEAD = 4;
	private const WAYPOINT_REACH_SQ = 0.25;
	private const WAYPOINT_TIMEOUT_TICKS = 100;
	/** Squared distance from the target set last within which a new moveTo() target only moves it. */
	private const TARGET_SLIDE_SQ = 2.0 * 2.0;
	/** 3D path searches (of all flying and swimming mobs) per tick. */
	public const MAX_SEARCHES_PER_TICK = 2;
	/** Most nodes a 3D search visits (fewer if the path search budget allows fewer). */
	public const MAX_NODES = 400;

	private static ?FairTickBudget $searchBudget = null;
	/**
	 * Failed searches of all mobs: key (see sharedFailureKey()) => tick until which they count.
	 * @var int[]
	 * @phpstan-var array<string, int>
	 */
	private static array $sharedFailures = [];

	private bool $hasTarget = false;
	private float $targetX = 0.0;
	private float $targetY = 0.0;
	private float $targetZ = 0.0;
	private float $acceptDistance = 0.0;
	private float $speed = 1.0;
	private ?Entity $followed = null;
	/** The target when it was set: moveTo() calls for points close to it keep the path (see TARGET_SLIDE_SQ). */
	private float $anchorX = 0.0;
	private float $anchorY = 0.0;
	private float $anchorZ = 0.0;

	private int $stuckCheckTick = 0;
	private float $stuckDistanceSq = 0.0;
	private float $stuckX = 0.0;
	private float $stuckY = 0.0;
	private float $stuckZ = 0.0;

	private ?Path3DFinder $finder3d = null;
	/** Path followed towards the target, null while going straight. */
	private ?Path $route = null;
	private int $waypointSince = 0;
	private ?Path $waypointRoute = null;
	private int $waypointIndex = -1;
	private float $waypointX = 0.0;
	private float $waypointY = 0.0;
	private float $waypointZ = 0.0;
	private bool $checkPending = false;
	private bool $searchPending = false;
	private int $searchWaitingSince = -1;
	private bool $searchedSinceStuck = false;
	private int $followCheckTick = 0;
	/**
	 * The last few 4x4x4 regions of targets that couldn't be reached (see regionKey()) => tick until which they aren't
	 * searched for again.
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $failedRegions = [];
	/** Failed searches in a row (see FAILED_RETRY_TICKS). */
	private int $failStreak = 0;

	public function __construct(
		private Mob $owner,
		private int $medium = self::MEDIUM_NONE
	){
		parent::__construct($owner);
	}

	public function getMedium() : int{
		return $this->medium;
	}

	public function getPath() : ?Path{
		return $this->route;
	}

	public function isDone() : bool{
		return !$this->hasTarget;
	}

	public function stop() : void{
		$this->clearTarget();
		$this->owner->getMoveControl()->stop();
	}

	public function dispose() : void{
		$this->clearTarget();
		parent::dispose();
	}

	private function clearTarget() : void{
		$this->hasTarget = false;
		$this->followed = null;
		$this->route = null;
		$this->waypointRoute = null;
		$this->checkPending = false;
		$this->searchPending = false;
	}

	public function setSpeedMultiplier(float $speedMultiplier) : void{
		$this->speed = $speedMultiplier;
	}

	/**
	 * Moves to the given point (not the block: flying and swimming targets are positions, usually block centres).
	 * Returns false if the mob found no way to this block (or one near it) a moment ago.
	 */
	public function moveTo(float $x, float $y, float $z, float $speedMultiplier, float $acceptDistance = 0.0) : bool{
		if($this->hasTarget && $this->followed === null && ($x - $this->anchorX) ** 2 + ($y - $this->anchorY) ** 2 + ($z - $this->anchorZ) ** 2 <= self::TARGET_SLIDE_SQ){
			//asked again by a goal, the same point or one close to it (a goal following something with moveTo()): keep the
			//path or the straight way checked
			$this->targetX = $x;
			$this->targetY = $y;
			$this->targetZ = $z;
			$this->speed = $speedMultiplier;
			$this->acceptDistance = $acceptDistance;
			return true;
		}
		if($this->hasFailed($x, $y, $z)){
			return false;
		}
		$this->followed = null;
		$this->setTarget($x, $y, $z, $speedMultiplier, $acceptDistance);
		return true;
	}

	public function moveToEntity(Entity $entity, float $speedMultiplier, float $acceptDistance = 1.0) : bool{
		if($this->followed !== $entity || !$this->hasTarget){
			$pos = $entity->getPosition();
			$y = $pos->y + $entity->getSize()->getHeight() / 2;
			if($this->hasFailed($pos->x, $y, $pos->z)){
				return false;
			}
			$this->setTarget($pos->x, $y, $pos->z, $speedMultiplier, $acceptDistance);
		}
		$this->followed = $entity;
		$this->speed = $speedMultiplier;
		$this->acceptDistance = $acceptDistance;
		return true;
	}

	private function hasFailed(float $x, float $y, float $z) : bool{
		return $this->medium !== self::MEDIUM_NONE && count($this->failedRegions) !== 0 &&
			($this->failedRegions[$this->regionKey($x, $y, $z)] ?? -1) > $this->owner->getAiTick();
	}

	/**
	 * Returns the key of the 4x4x4 region of a target.
	 */
	private function regionKey(float $x, float $y, float $z) : int{
		return ((((int) floor($x) >> 2) & 0x7fffff) << 33) | ((($this->targetRow($y) >> 2) & 0x3ff) << 23) | (((int) floor($z) >> 2) & 0x7fffff);
	}

	private function targetRow(float $y) : int{
		return Path3DFinder::nodeRow($y, $this->owner->getSize()->getHeight());
	}

	private function setTarget(float $x, float $y, float $z, float $speedMultiplier, float $acceptDistance) : void{
		$this->hasTarget = true;
		$this->targetX = $this->anchorX = $x;
		$this->targetY = $this->anchorY = $y;
		$this->targetZ = $this->anchorZ = $z;
		$this->speed = $speedMultiplier;
		$this->acceptDistance = $acceptDistance;
		$now = $this->owner->getAiTick();
		$this->resetStuckCheck($now);
		$this->route = null;
		$this->checkPending = $this->medium !== self::MEDIUM_NONE;
		$this->searchPending = false;
		$this->searchedSinceStuck = false;
		$this->followCheckTick = $now;
	}

	/**
	 * Starts a new window of the stuck check from where the mob is now.
	 */
	private function resetStuckCheck(int $now) : void{
		$this->stuckCheckTick = $now;
		$this->stuckDistanceSq = $this->distanceSquaredToTarget();
		$loc = $this->owner->getLiveLocation();
		$this->stuckX = $loc->x;
		$this->stuckY = $loc->y;
		$this->stuckZ = $loc->z;
	}

	/**
	 * Leaves the path and goes straight to the target: the stuck check starts again, it measured progress along the
	 * path (a detour usually leads away from the target first).
	 */
	private function leaveRoute(int $now) : void{
		if($this->route !== null){
			$this->route = null;
			$this->resetStuckCheck($now);
		}
	}

	private function distanceSquaredToTarget() : float{
		$loc = $this->owner->getLiveLocation();
		return ($this->targetX - $loc->x) ** 2 + ($this->targetY - $loc->y) ** 2 + ($this->targetZ - $loc->z) ** 2;
	}

	public function tick() : void{
		if(!$this->hasTarget){
			return;
		}
		$followed = $this->followed;
		if($followed !== null){
			if($followed->isClosed() || $followed->getWorld() !== $this->owner->getWorld()){
				$this->stop();
				return;
			}
			$pos = $followed->getPosition();
			$this->targetX = $pos->x;
			$this->targetY = $pos->y + $followed->getSize()->getHeight() / 2;
			$this->targetZ = $pos->z;
		}
		$owner = $this->owner;
		//distanceSquaredToTarget() written out (every tick for every swimming and flying mob with a target)
		$loc = $owner->getLiveLocation();
		$distSq = ($this->targetX - $loc->x) ** 2 + ($this->targetY - $loc->y) ** 2 + ($this->targetZ - $loc->z) ** 2;
		$reach = $this->acceptDistance + 0.5;
		if($distSq <= $reach * $reach){
			$this->failStreak = 0;
			$this->stop();
			return;
		}
		$now = $owner->getAiTick();
		$searching = $this->medium !== self::MEDIUM_NONE && $owner->getMoveControl()->isThreeDimensional();
		if($searching){
			if($this->followed !== null || $this->checkPending || $this->searchPending){
				$this->tickSearch($now);
				if(!$this->hasTarget){
					return;
				}
			}else{
				//tickSearch() with nothing to check nor search (a wandering fish going straight): the request isn't waiting
				$this->searchWaitingSince = -1;
			}
		}else{
			//out of its medium (a stranded swimmer, a flyer that landed): straight on
			$this->leaveRoute($now);
		}

		$route = $this->route;
		if($route !== null && !$this->followRoute($route, $now)){
			return;
		}
		if($this->route === null){
			$this->owner->getMoveControl()->setWantedPosition($this->targetX, $this->targetY, $this->targetZ, $this->speed);
		}

		if($now - $this->stuckCheckTick >= self::STUCK_CHECK_TICKS){
			$loc = $this->owner->getLiveLocation();
			if($followed === null && $this->route === null){
				$progress = $distSq <= $this->stuckDistanceSq - 0.25;
			}else{
				//followed entities move, and a path round a wall leads away from the target first: a mob that hardly moved
				//is stuck (not checked without a medium; a path that doesn't lead on also times out, see followRoute())
				$progress = $this->medium === self::MEDIUM_NONE || ($loc->x - $this->stuckX) ** 2 + ($loc->y - $this->stuckY) ** 2 + ($loc->z - $this->stuckZ) ** 2 >= 0.25;
			}
			$this->stuckCheckTick = $now;
			$this->stuckDistanceSq = $distSq;
			$this->stuckX = $loc->x;
			$this->stuckY = $loc->y;
			$this->stuckZ = $loc->z;
			if($progress){
				$this->searchedSinceStuck = false;
			}else{
				$this->onStuck($searching, $now);
			}
		}
	}

	/**
	 * No progress for a while: search for a way once (the straight way or the path is blocked), then give up.
	 */
	private function onStuck(bool $searching, int $now) : void{
		if($searching && !$this->searchedSinceStuck && !$this->hasFailed($this->targetX, $this->targetY, $this->targetZ)){
			$this->searchedSinceStuck = true;
			$this->searchPending = true;
			return;
		}
		if($this->medium !== self::MEDIUM_NONE){
			$this->markFailed($now);
		}
		$this->stop();
	}

	/**
	 * Remembers that the target can't be reached, for longer after each failure in a row (see FAILED_RETRY_TICKS), and
	 * with $share for the other mobs searching from nearby too.
	 */
	private function markFailed(int $now, bool $share = false) : void{
		$key = $this->regionKey($this->targetX, $this->targetY, $this->targetZ);
		unset($this->failedRegions[$key]);
		if(count($this->failedRegions) >= self::MAX_FAILED_REGIONS){
			foreach($this->failedRegions as $old => $until){
				if($until <= $now){
					unset($this->failedRegions[$old]);
				}
			}
			if(count($this->failedRegions) >= self::MAX_FAILED_REGIONS){
				unset($this->failedRegions[array_key_first($this->failedRegions)]);
			}
		}
		$this->failedRegions[$key] = $now + (self::FAILED_RETRY_TICKS << (2 * min($this->failStreak, self::MAX_FAILED_BACKOFF)));
		++$this->failStreak;
		if($share){
			if(count(self::$sharedFailures) >= self::MAX_SHARED_FAILURES){
				foreach(Utils::stringifyKeys(self::$sharedFailures) as $sharedKey => $until){
					if($until <= $now){
						unset(self::$sharedFailures[$sharedKey]);
					}
				}
				if(count(self::$sharedFailures) >= self::MAX_SHARED_FAILURES){
					self::$sharedFailures = [];
				}
			}
			self::$sharedFailures[$this->sharedFailureKey()] = $now + self::SHARED_FAILED_TICKS;
		}
	}

	/**
	 * Returns the key of a failed search shared between mobs: the world, the medium, the mob's size, the 4x4x4 region of
	 * the target (the flowers of a greenhouse: one failed search for all of them) and the 8x8x8 region the mob starts in.
	 */
	private function sharedFailureKey() : string{
		$owner = $this->owner;
		$loc = $owner->getLiveLocation();
		$size = $owner->getSize();
		$height = $size->getHeight();
		return $owner->getWorld()->getId() . ":" . $this->medium . ":" . PathFinder::footprintOf($size->getWidth()) . ":" . (int) ceil($height) . ":" .
			((int) floor($this->targetX) >> 2) . ":" . ($this->targetRow($this->targetY) >> 2) . ":" . ((int) floor($this->targetZ) >> 2) . ":" .
			((int) floor($loc->x) >> 3) . ":" . (Path3DFinder::nodeRow($loc->y, $height) >> 3) . ":" . ((int) floor($loc->z) >> 3);
	}

	/**
	 * Returns whether another mob searched from nearby for the same target a moment ago and found no way.
	 */
	private function hasSharedFailure(int $now) : bool{
		$until = self::$sharedFailures[$this->sharedFailureKey()] ?? null;
		return $until !== null && $until > $now;
	}

	private function tickSearch(int $now) : void{
		$route = $this->route;
		if($this->followed !== null && $now - $this->followCheckTick >= self::FOLLOW_CHECK_TICKS){
			$this->followCheckTick = $now;
			//going straight: is the way still clear? On a path: has the entity moved away from where it leads?
			if($route === null || abs($route->getTargetX() - (int) floor($this->targetX)) + abs($route->getTargetY() - $this->targetRow($this->targetY)) + abs($route->getTargetZ() - (int) floor($this->targetZ)) > 2){
				$this->checkPending = true;
			}
		}
		if($this->checkPending){
			$this->checkPending = false;
			$loc = $this->owner->getLiveLocation();
			if($this->isClearToTarget($loc->x, $loc->y, $loc->z)){
				$this->leaveRoute($now);
				$this->searchPending = false;
			}elseif(!$this->hasFailed($this->targetX, $this->targetY, $this->targetZ)){
				$this->searchPending = true;
			}
		}
		if(!$this->searchPending){
			$this->searchWaitingSince = -1;
			return;
		}
		if($this->searchWaitingSince < 0 && $this->hasSharedFailure($now)){
			//another mob found no way from here a moment ago
			$this->markFailed($now);
			$this->stop();
			return;
		}
		//3D searches cost more than walking ones (no native search): a few per tick, and each one from the path search budget
		$budget = self::$searchBudget ??= new FairTickBudget(self::MAX_SEARCHES_PER_TICK);
		$granted = $budget->tryTake($now, $this->searchWaitingSince);
		if($granted && !AiBudget::tryPathSearch($now, $this->searchWaitingSince)){
			//no walking search left: the 3D unit isn't used, this search keeps its place in the queue of both budgets
			$budget->refund($now, $this->searchWaitingSince);
			$granted = false;
		}
		if(!$granted){
			//no budget left this tick: keep going the way it goes and ask again on the next tick
			if($this->searchWaitingSince < 0){
				$this->searchWaitingSince = $now;
			}
			return;
		}
		$this->searchWaitingSince = -1;
		$this->searchPending = false;
		$this->search($now);
	}

	private function getFinder() : Path3DFinder{
		$owner = $this->owner;
		$size = $owner->getSize();
		$footprint = PathFinder::footprintOf($size->getWidth());
		if($this->finder3d === null){
			$this->finder3d = new Path3DFinder($owner->getWorld(), $this->medium, max(0, (int) ceil($size->getHeight()) - 1), $footprint, min(AiBudget::getMaxPathNodes(), self::MAX_NODES));
		}else{
			$this->finder3d->setWorld($owner->getWorld());
			$this->finder3d->setFootprint($footprint);
			$this->finder3d->setMaxNodes(min(AiBudget::getMaxPathNodes(), self::MAX_NODES));
		}
		return $this->finder3d;
	}

	private function isClear(float $fromX, float $fromY, float $fromZ, float $toX, float $toY, float $toZ) : bool{
		$size = $this->owner->getSize();
		return $this->getFinder()->isClear($fromX, $fromY, $fromZ, $toX, $toY, $toZ, $size->getWidth() / 2, $size->getHeight());
	}

	/**
	 * Returns whether the straight way from the given position to where the mob stops near the target (its accept
	 * distance) is clear: a target in a block (a hive, a flower pot) or an entity against a wall doesn't need a search.
	 */
	private function isClearToTarget(float $fromX, float $fromY, float $fromZ) : bool{
		$dx = $this->targetX - $fromX;
		$dy = $this->targetY - $fromY;
		$dz = $this->targetZ - $fromZ;
		$length = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
		$keep = $length > 0.0 ? max(0.0, $length - $this->acceptDistance - 0.4) / $length : 0.0;
		return $this->isClear($fromX, $fromY, $fromZ, $fromX + $dx * $keep, $fromY + $dy * $keep, $fromZ + $dz * $keep);
	}

	private function search(int $now) : void{
		$owner = $this->owner;
		$loc = $owner->getLiveLocation();
		$size = $owner->getSize();
		$height = $size->getHeight();
		$footprint = PathFinder::footprintOf($size->getWidth());
		$finder = $this->getFinder();
		$sx = PathFinder::nodeCoordinate($loc->x, $footprint);
		$sy = Path3DFinder::nodeRow($loc->y, $height);
		$sz = PathFinder::nodeCoordinate($loc->z, $footprint);
		$target = $finder->resolveTarget(PathFinder::nodeCoordinate($this->targetX, $footprint), $this->targetRow($this->targetY), PathFinder::nodeCoordinate($this->targetZ, $footprint), $sx, $sy, $sz);
		if($target === null){
			//out of its medium (a swimmer's target on land): straight on, as far as it gets (see onStuck())
			$this->leaveRoute($now);
			return;
		}
		$path = $finder->findPath($sx, $sy, $sz, $target[0], $target[1], $target[2], $this->acceptDistance);
		if($path === null || ($path->getLength() <= 1 && !$path->reachesTarget())){
			//no way to go at all (or none found within the node budget: a detour much longer than the straight way, see
			//Path3DFinder; like vanilla's capped search, the mob doesn't get there)
			$this->markFailed($now, true);
			$this->stop();
			return;
		}
		if(!$path->reachesTarget()){
			//as close as it gets, without searching for the same block again and again
			$this->markFailed($now, true);
		}
		$path->setIndex(1); //node 0 is where it is
		$this->route = $path;
		$this->resetStuckCheck($now);
		$this->waypointSince = $now;
		$this->skipAhead($path);
	}

	/**
	 * @return float[]
	 * @phpstan-return array{float, float, float}
	 */
	private function waypoint(Path $path, int $index) : array{
		$node = $path->getNode($index) ?? [0, 0, 0];
		$offset = PathFinder::footprintOf($this->owner->getSize()->getWidth()) * 0.5;
		return [$node[0] + $offset, Path3DFinder::nodeY($node[1], $this->owner->getSize()->getHeight()), $node[2] + $offset];
	}

	/**
	 * Goes on to the farthest node ahead (of the next few) the mob can go straight to.
	 */
	private function skipAhead(Path $path) : void{
		$index = $path->getIndex();
		$far = min($index + self::LOOKAHEAD, $path->getLength() - 1);
		if($far <= $index){
			return;
		}
		$loc = $this->owner->getLiveLocation();
		foreach([$far, $far - 2] as $candidate){
			if($candidate > $index){
				[$x, $y, $z] = $this->waypoint($path, $candidate);
				if($this->isClear($loc->x, $loc->y, $loc->z, $x, $y, $z)){
					$path->setIndex($candidate);
					return;
				}
			}
		}
	}

	/**
	 * Works out where the mob goes for the current node of the path, once per node (not every tick).
	 */
	private function updateWaypoint(Path $route) : void{
		$index = $route->getIndex();
		if($index !== $this->waypointIndex || $route !== $this->waypointRoute){
			$this->waypointIndex = $index;
			$this->waypointRoute = $route;
			[$this->waypointX, $this->waypointY, $this->waypointZ] = $this->waypoint($route, $index);
		}
	}

	/**
	 * Moves along the path. Returns false if the navigation stopped.
	 */
	private function followRoute(Path $route, int $now) : bool{
		$loc = $this->owner->getLiveLocation();
		if(!$route->isDone()){
			$this->updateWaypoint($route);
			if(($this->waypointX - $loc->x) ** 2 + ($this->waypointY - $loc->y) ** 2 + ($this->waypointZ - $loc->z) ** 2 < self::WAYPOINT_REACH_SQ){
				$route->advance();
				$this->waypointSince = $now;
				if(!$route->isDone()){
					$this->skipAhead($route);
				}
			}elseif($now - $this->waypointSince > self::WAYPOINT_TIMEOUT_TICKS){
				$this->waypointSince = $now;
				$this->onStuck(true, $now);
				return $this->hasTarget;
			}
		}
		if($route->isDone()){
			if(!$route->reachesTarget()){
				//the end of a partial path: as close as it gets
				$this->stop();
				return false;
			}
			//the last bit straight to the point
			$this->leaveRoute($now);
			return true;
		}
		$this->updateWaypoint($route);
		$this->owner->getMoveControl()->setWantedPosition($this->waypointX, $this->waypointY, $this->waypointZ, $this->speed);
		return true;
	}
}
