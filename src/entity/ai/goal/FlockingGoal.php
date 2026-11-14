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
use pocketmine\entity\Mob;
use pocketmine\math\AxisAlignedBB;
use pocketmine\utils\Utils;
use function cos;
use function count;
use function floor;
use function mt_rand;
use function sin;

/**
 * Schools of fish and pods of dolphins (Bedrock minecraft:flocking), as leaders and followers like Java
 * AbstractSchoolingFish / FollowFlockLeaderGoal: a mob without a school looks for one every 5 seconds or so
 * (staggered, one entity query per search), joins the biggest school nearby that still has room (high_flock_limit
 * members), otherwise leads the mobs without a school around it. A school smaller than low_flock_limit keeps looking
 * and merges into a bigger one. With loner_chance the mob skips a search and stays alone for a while.
 *
 * The leader wanders with its usual goals; each follower swims to its own place around it, cohesion_threshold blocks
 * from the leader in a direction of its own (spread by the golden angle, a little above or below), so that the members
 * keep apart from each other (the separation) instead of piling up on the leader and pushing each other all the time;
 * it catches up faster beyond influence_radius. A follower leaves the school when the leader dies, is more than 11
 * blocks away (Java) or, with in_water, when it is out of the water. Bedrock steers every member with weighted
 * cohesion/separation/goal forces; the leader model gives the same look (tight schools that wander together) at the
 * cost of one neighbour query per few seconds per lone mob and one path update per follower every 10 ticks.
 */
class FlockingGoal extends Goal{
	/** Range in which a mob looks for a school (Java 8 blocks). */
	public const SEARCH_RADIUS = 8.0;
	/** Squared distance from its leader at which a follower leaves the school (Java 11 blocks). */
	private const LEAVE_DISTANCE_SQ = 121.0;
	/** Ticks between two searches of a mob without a (big enough) school: Java reducedTickDelay(200 + rand) = 100-110. */
	public const SEARCH_INTERVAL = 100;
	private const SEARCH_JITTER = 10;
	/** Ticks between two path updates towards the leader (Java). */
	private const REPATH_TICKS = 10;
	/** Speed multiplier of a follower farther than influence_radius from its leader. */
	private const CATCH_UP_SPEED = 1.25;
	/** Angle between the places of two followers that joined one after the other (golden angle, in radians). */
	private const SLOT_ANGLE = 2.399963229728653;
	/** Height of the places around the leader, alternately above and below it. */
	private const SLOT_HEIGHT = 0.4;
	/** Distance from its place at which a follower stops. */
	private const SLOT_ACCEPT_DISTANCE = 0.3;

	/**
	 * @var \WeakMap|null mob => its flocking goal, to find the schools of the neighbours
	 * @phpstan-var \WeakMap<Mob, FlockingGoal>|null
	 */
	private static ?\WeakMap $goals = null;

	private ?FlockingGoal $leader = null;
	/**
	 * @var FlockingGoal[] entity ID => follower
	 * @phpstan-var array<int, FlockingGoal>
	 */
	private array $followers = [];
	private int $nextSearchTick = -1;
	private int $repathDelay = 0;
	private float $influenceRadiusSq;
	/** Place of this follower relative to its leader (set when it joins). */
	private float $slotX = 0.0;
	private float $slotY = 0.0;
	private float $slotZ = 0.0;
	/** Places handed out by this leader, for the direction of the next one. */
	private int $slotsGiven = 0;

	/**
	 * @param float         $influenceRadius   Bedrock influence_radius: followers farther away than this catch up faster
	 * @param int           $lowFlockLimit     schools smaller than this look for a bigger school to merge into
	 * @param int           $highFlockLimit    most members of a school, leader included
	 * @param float         $lonerChance       chance that a search is skipped (the mob stays alone until the next one)
	 * @param float         $cohesionThreshold distance a follower keeps from its leader
	 * @param bool          $inWater           whether the mob only flocks in water
	 * @param \Closure|null $canFlockWith      whether the mob may school with another mob of its class (Bedrock
	 *                                         match_variants), any mob of the same class if null
	 * @phpstan-param (\Closure(Mob) : bool)|null $canFlockWith
	 */
	public function __construct(
		protected Mob $mob,
		protected float $influenceRadius = 3.0,
		protected int $lowFlockLimit = 4,
		protected int $highFlockLimit = 8,
		protected float $lonerChance = 0.1,
		protected float $cohesionThreshold = 1.95,
		protected bool $inWater = true,
		protected ?\Closure $canFlockWith = null
	){
		$this->influenceRadiusSq = $influenceRadius ** 2;
		self::$goals ??= new \WeakMap();
		self::$goals[$mob] = $this;
	}

	/**
	 * Returns the flocking goal of a mob, if it has one.
	 */
	public static function of(Mob $mob) : ?FlockingGoal{
		return self::$goals[$mob] ?? null;
	}

	public function getMob() : Mob{
		return $this->mob;
	}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	/**
	 * Returns the leader of this mob's school, null if the mob leads it or has none.
	 */
	public function getLeader() : ?Mob{
		return $this->leader?->mob;
	}

	public function isFollower() : bool{
		return $this->leader !== null;
	}

	/**
	 * Returns the number of mobs in the school this mob leads (itself included): 1 for a follower or a lone mob.
	 */
	public function getSchoolSize() : int{
		foreach($this->followers as $id => $follower){
			if($follower->leader !== $this || $follower->mob->isClosed() || !$follower->mob->isAlive()){
				unset($this->followers[$id]);
			}
		}
		return 1 + count($this->followers);
	}

	/**
	 * Makes the mob follow the given leader (spawned herds, tests). Returns false if they can't school together.
	 */
	public function follow(FlockingGoal $leader) : bool{
		if($leader === $this || $leader->leader !== null || !$this->canSchoolWith($leader)){
			return false;
		}
		$this->join($leader);
		return true;
	}

	/**
	 * Leaves the school: a follower leaves its leader, a leader lets its followers go (they look for a new school).
	 */
	public function leaveSchool() : void{
		if($this->leader !== null){
			unset($this->leader->followers[$this->mob->getId()]);
			$this->leader = null;
		}
		foreach($this->followers as $follower){
			if($follower->leader === $this){
				$follower->leader = null;
			}
		}
		$this->followers = [];
	}

	/**
	 * Leaves the school and forgets the mob (when it is closed): the goal refers to its mob, so the entry would only be
	 * freed with the WeakMap by PHP 8.4 and later.
	 */
	public function dispose() : void{
		$this->leaveSchool();
		if(self::$goals !== null){
			unset(self::$goals[$this->mob]);
		}
	}

	private function join(FlockingGoal $leader) : void{
		if($this->leader !== null){
			unset($this->leader->followers[$this->mob->getId()]);
		}
		$this->leader = $leader;
		$leader->followers[$this->mob->getId()] = $this;
		//its own place around the leader: the golden angle spreads the places of a school evenly
		$slot = $leader->slotsGiven++;
		$angle = $slot * self::SLOT_ANGLE;
		$this->slotX = cos($angle) * $this->cohesionThreshold;
		$this->slotZ = sin($angle) * $this->cohesionThreshold;
		$this->slotY = ($slot & 1) === 0 ? self::SLOT_HEIGHT : -self::SLOT_HEIGHT;
	}

	private function canSchoolWith(FlockingGoal $other) : bool{
		$mob = $other->mob;
		if($mob::class !== $this->mob::class || $mob->isClosed() || !$mob->isAlive() || ($this->inWater && !$mob->isInWater())){
			return false;
		}
		return $this->canFlockWith === null || ($this->canFlockWith)($mob);
	}

	/**
	 * Checks that the leader is still there and close enough; otherwise the mob leaves the school.
	 */
	private function hasValidLeader() : bool{
		$leader = $this->leader;
		if($leader === null){
			return false;
		}
		$leaderMob = $leader->mob;
		if(
			$leader->leader !== null || $leaderMob->isClosed() || !$leaderMob->isAlive() || $leaderMob->getWorld() !== $this->mob->getWorld() ||
			($this->inWater && !$this->mob->isInWater()) ||
			$leaderMob->getLiveLocation()->distanceSquared($this->mob->getLiveLocation()) > self::LEAVE_DISTANCE_SQ
		){
			unset($leader->followers[$this->mob->getId()]);
			$this->leader = null;
			return false;
		}
		return true;
	}

	public function canStart() : bool{
		if($this->leader !== null){
			return $this->hasValidLeader();
		}
		$now = $this->mob->getAiTick();
		if($this->nextSearchTick < 0){
			//first check soon after spawning (herds group up quickly), staggered
			$this->nextSearchTick = $now + mt_rand(0, self::SEARCH_JITTER * 2);
			return false;
		}
		if($now < $this->nextSearchTick){
			return false;
		}
		$this->nextSearchTick = $now + self::SEARCH_INTERVAL + mt_rand(0, self::SEARCH_JITTER);
		if(($this->inWater && !$this->mob->isInWater()) || (count($this->followers) !== 0 && $this->getSchoolSize() >= $this->lowFlockLimit)){
			return false;
		}
		if($this->lonerChance > 0 && Utils::getRandomFloat() < $this->lonerChance){
			return false;
		}
		$this->search();
		return $this->leader !== null;
	}

	/**
	 * Joins the biggest school nearby with room for this mob (and its own small school), or leads the lone mobs around.
	 */
	private function search() : void{
		$size = $this->getSchoolSize();
		$loc = $this->mob->getLiveLocation();
		$r = self::SEARCH_RADIUS;
		$best = null;
		$bestSize = $size > 1 ? $size - 1 : 1;
		$loners = [];
		foreach($this->mob->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - $r, $loc->z - $r, $loc->x + $r, $loc->y + $r, $loc->z + $r), $this->mob) as $entity){
			if(!$entity instanceof Mob || $entity::class !== $this->mob::class){
				continue;
			}
			$other = self::$goals[$entity] ?? null;
			if($other === null || $other === $this || $other->leader !== null || !$this->canSchoolWith($other)){
				continue;
			}
			$otherSize = count($other->followers) !== 0 ? $other->getSchoolSize() : 1;
			if($otherSize > 1){
				if($otherSize > $bestSize && $otherSize + $size <= $other->highFlockLimit){
					$best = $other;
					$bestSize = $otherSize;
				}
			}elseif($size === 1){
				$loners[] = $other;
			}
		}
		if($best !== null){
			foreach($this->followers as $follower){
				$follower->join($best);
			}
			$this->followers = [];
			$this->join($best);
		}elseif(count($loners) !== 0){
			foreach($loners as $loner){
				if(1 + count($this->followers) >= $this->highFlockLimit){
					break;
				}
				$loner->join($this);
			}
		}
	}

	public function canContinue() : bool{
		//checked every tick: the leader is fully checked with each path update (see tick())
		return $this->leader !== null && !$this->leader->mob->isClosed();
	}

	public function start() : void{
		$this->repathDelay = 0;
	}

	public function stop() : void{
		$this->mob->getNavigation()->stop();
	}

	public function tick() : void{
		if(--$this->repathDelay > 0 || !$this->hasValidLeader() || $this->leader === null){
			return;
		}
		$this->repathDelay = self::REPATH_TICKS;
		$leader = $this->leader->mob;
		$leaderLoc = $leader->getLiveLocation();
		$far = $leaderLoc->distanceSquared($this->mob->getLiveLocation()) > $this->influenceRadiusSq;
		//to its place around where the leader is now (Java pathToLeader() every 10 ticks): a fixed point, nothing to
		//update on the ticks between; a follower at its place stops there until the next update
		$x = $leaderLoc->x + $this->slotX;
		$y = $leaderLoc->y + $this->slotY;
		$z = $leaderLoc->z + $this->slotZ;
		$world = $leader->getWorld();
		$wanted = PathBlockTypes::PASSABLE | (PathBlockTypes::at($world, (int) floor($leaderLoc->x), (int) floor($leaderLoc->y + 0.01), (int) floor($leaderLoc->z)) & PathBlockTypes::WATER);
		if((PathBlockTypes::at($world, (int) floor($x), (int) floor($y + 0.01), (int) floor($z)) & $wanted) !== $wanted){
			//its place is in a wall or out of the water (the leader swims along the edge of the pool): close to the leader
			//instead, not stuck against the wall searching for a way to a place it can't reach
			$this->mob->getNavigation()->moveTo($leaderLoc->x, $leaderLoc->y, $leaderLoc->z, $far ? self::CATCH_UP_SPEED : 1.0, $this->cohesionThreshold);
			return;
		}
		$this->mob->getNavigation()->moveTo($x, $y, $z, $far ? self::CATCH_UP_SPEED : 1.0, self::SLOT_ACCEPT_DISTANCE);
	}
}
