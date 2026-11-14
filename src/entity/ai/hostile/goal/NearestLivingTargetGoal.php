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

namespace pocketmine\entity\ai\hostile\goal;

use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\NearestAttackableTargetGoal;
use pocketmine\entity\ai\sensing\NearbyPlayers;
use pocketmine\entity\ai\sensing\Sensing;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\math\AxisAlignedBB;
use pocketmine\player\Player;
use function count;
use function intdiv;
use function max;
use function min;
use function mt_rand;
use function sqrt;

/**
 * Targets the nearest living entity in range accepted by a filter (vanilla behavior.nearest_attackable_target with
 * entity filters other than "player": endermen hunting endermites, zoglins attacking everything, piglins hunting
 * hoglins...). Players are only accepted when they can be targeted (survival or adventure). Targets are followed as far
 * as {@link NearestAttackableTargetGoal} follows them, and invisible ones are only noticed from closer. The filter is only
 * checked again on a target already chosen when $persistTicks is given (drowned dropping a player who left the water).
 */
class NearestLivingTargetGoal extends Goal{
	private ?Living $target = null;
	private int $unseenTicks = 0;
	private float $keepRange = 0.0;
	private int $reselectTicks = 0;
	/** Server tick since which the filter turns the target down, -1 while it accepts it. */
	private int $rejectedSinceTick = -1;

	/**
	 * @param string|null   $requiredClass    a class the filter only accepts instances of (e.g. endermen hunting endermites):
	 *                                        the search is skipped while the world has no entity of this class
	 * @param int           $forgetTicks      ticks an unseen target is remembered (Bedrock must_see_forget_duration)
	 * @param bool          $reselect         whether a closer target replaces the current one (Bedrock reselect_targets)
	 * @param float|null    $maxDistance      how far targets are followed when farther than $range (Bedrock max_dist)
	 * @param int|null      $persistTicks     when given, the filter is checked again on the current target on every goal
	 *                                        evaluation, and a target it turns down stays targeted this many ticks (Bedrock
	 *                                        reevaluate_description with persist_time); null: never checked again (Bedrock
	 *                                        default, like Java)
	 * @param string[]|null $candidateClasses classes the filter only accepts instances of (e.g. players and the wither
	 *                                        family for piglins): entities of other classes are passed over without looking at
	 *                                        them, and the search is skipped while the world has none of them
	 * @param \Closure|null $filterCondition  a part of the filter that doesn't depend on the entity (e.g. taking part in a
	 *                                        raid): while it is false the filter accepts nobody, and the search is skipped
	 *
	 * @phpstan-param \Closure(Living) : bool $filter
	 * @phpstan-param class-string<Living>|null $requiredClass
	 * @phpstan-param list<class-string<Entity>>|null $candidateClasses
	 * @phpstan-param (\Closure() : bool)|null $filterCondition
	 */
	public function __construct(
		protected Mob $mob,
		protected \Closure $filter,
		protected float $range = 16.0,
		protected bool $mustSee = true,
		protected int $interval = 10,
		protected bool $playersOnly = false,
		protected ?string $requiredClass = null,
		protected int $forgetTicks = NearestAttackableTargetGoal::DEFAULT_FORGET_TICKS,
		protected bool $reselect = false,
		protected ?float $maxDistance = null,
		protected ?int $persistTicks = null,
		protected ?array $candidateClasses = null,
		protected ?\Closure $filterCondition = null
	){}

	/**
	 * Targets the nearest targetable player accepted by the filter.
	 *
	 * @phpstan-param \Closure(Player) : bool $filter
	 */
	public static function players(Mob $mob, \Closure $filter, float $range = 16.0, bool $mustSee = true, int $interval = 10, int $forgetTicks = NearestAttackableTargetGoal::DEFAULT_FORGET_TICKS, bool $reselect = false, ?float $maxDistance = null, ?int $persistTicks = null) : self{
		return new self($mob, fn(Living $l) => $l instanceof Player && $filter($l), $range, $mustSee, $interval, true, null, $forgetTicks, $reselect, $maxDistance, $persistTicks);
	}

	public function getFlags() : int{
		return self::FLAG_TARGET;
	}

	public function canStart() : bool{
		$evaluations = intdiv($this->interval, Mob::GOAL_EVALUATION_INTERVAL);
		if($evaluations > 1 && mt_rand(0, $evaluations - 1) !== 0){
			return false;
		}
		$this->target = $this->findTarget();
		return $this->target !== null;
	}

	protected function findTarget() : ?Living{
		return $this->findNearest();
	}

	/**
	 * Returns the nearest entity the mob notices and the filter accepts, other than $current and at most
	 * $maxDistanceSquared away (squared distance) when given.
	 */
	private function findNearest(?Living $current = null, ?float $maxDistanceSquared = null) : ?Living{
		if($this->requiredClass !== null && !$this->mob->getWorld()->hasEntitiesOfClass($this->requiredClass)){
			//the filter would turn down every candidate (the checks before it have no side effects)
			return null;
		}
		if($this->filterCondition !== null && !($this->filterCondition)()){
			return null; //the same
		}
		$classes = null;
		if($this->candidateClasses !== null && !$this->playersOnly){
			//the same: the filter turns down the entities of other classes, so only the classes the world has any of
			//(cached counts) are looked at, and none at all when it has none of them
			$world = $this->mob->getWorld();
			$classes = [];
			foreach($this->candidateClasses as $class){
				if($world->hasEntitiesOfClass($class)){
					$classes[] = $class;
				}
			}
			if(count($classes) === 0){
				return null;
			}
		}
		$loc = $this->mob->getLiveLocation();
		$r = $this->range;
		$best = null;
		$bestDist = $r * $r;
		$h = $r;
		if($maxDistanceSquared !== null && $maxDistanceSquared < $bestDist){
			//only entities that close are candidates: a smaller search box (a little larger than the distance, so that
			//the boxes of all of them still intersect it), $r still decides how close invisible ones are noticed
			$bestDist = $maxDistanceSquared;
			$h = min($r, sqrt($maxDistanceSquared) + 0.001);
		}
		$sensing = $this->mob->getSensing();
		$world = $this->mob->getWorld();
		if($this->playersOnly){
			$candidates = $world->getPlayers();
		}else{
			$box = new AxisAlignedBB($loc->x - $h, $loc->y - $h, $loc->z - $h, $loc->x + $h, $loc->y + $h, $loc->z + $h);
			//when players are the only candidates the world has, the players in the box (in the order of the box search)
			//are all that is looked at below
			$candidates = ($classes === [Player::class] ? NearbyPlayers::inBox($world, $box, $this->mob) : null) ?? $world->getNearbyEntities($box, $this->mob);
		}
		foreach($candidates as $entity){
			if(!$entity instanceof Living || $entity === $current){
				continue;
			}
			if($classes !== null){
				$candidate = false;
				foreach($classes as $class){
					if($entity instanceof $class){
						$candidate = true;
						break;
					}
				}
				if(!$candidate){
					continue;
				}
			}
			$live = Mob::getLiveLocationOf($entity);
			if($live !== null){
				//the live location, and the distance before isAlive(), isTargetablePlayer() and isNeverTargeted() (no side
				//effects): drowned scan every player of the world, crowds have many candidates farther than the nearest
				$d = $live->distanceSquared($loc);
				if($d > $bestDist || !$entity->isAlive() || ($entity instanceof Player && !Sensing::isTargetablePlayer($entity)) || Sensing::isNeverTargeted($entity)){
					continue;
				}
			}else{
				//a class overriding getLocation(): the original order
				if(Sensing::isNeverTargeted($entity) || !$entity->isAlive() || ($entity instanceof Player && !Sensing::isTargetablePlayer($entity))){
					continue;
				}
				$d = $entity->getLocation()->distanceSquared($loc);
				if($d > $bestDist){
					continue;
				}
			}
			if(!($this->filter)($entity) || !Sensing::canDetect($this->mob, $entity, $r, $d) || ($this->mustSee && !$sensing->canSee($entity))){
				continue;
			}
			$best = $entity;
			$bestDist = $d;
		}
		return $best;
	}

	public function canContinue() : bool{
		$target = $this->mob->getAttackTarget();
		if($target === null || $target !== $this->target || !$this->mob->getSensing()->isValidTarget($target, $this->keepRange)){
			return false;
		}
		if($this->mustSee){
			if($this->mob->getSensing()->canSee($target)){
				$this->unseenTicks = 0;
			}elseif(++$this->unseenTicks > $this->forgetTicks){
				return false;
			}
		}
		//both on goal evaluation ticks only: within the AI budget, with fresh line of sight results
		$evaluation = ($this->persistTicks !== null || $this->reselect) && $this->mob->getSensing()->isEvaluationTick();
		if($evaluation && $this->persistTicks !== null){
			if(($this->filter)($target)){
				$this->rejectedSinceTick = -1;
			}else{
				$now = $this->mob->getAiTick();
				if($this->rejectedSinceTick === -1){
					$this->rejectedSinceTick = $now;
				}
				if($now - $this->rejectedSinceTick >= $this->persistTicks){
					return false;
				}
			}
		}
		if($evaluation && $this->reselect && ($this->reselectTicks -= Mob::GOAL_EVALUATION_INTERVAL) <= 0){
			$this->reselectTicks = max($this->reselectTicks + $this->interval, 0);
			$this->reselectCloser($target);
		}
		return true;
	}

	/**
	 * Switches to the nearest target if it is closer than the current one (Bedrock reselect_targets). Only closer
	 * entities are looked at.
	 */
	private function reselectCloser(Living $current) : void{
		$loc = $this->mob->getLiveLocation();
		$currentDistance = (Mob::getLiveLocationOf($current) ?? $current->getLocation())->distanceSquared($loc);
		$closer = $this->findNearest($current, $currentDistance);
		if($closer !== null && (Mob::getLiveLocationOf($closer) ?? $closer->getLocation())->distanceSquared($loc) < $currentDistance){
			$this->target = $closer;
			$this->unseenTicks = 0;
			$this->rejectedSinceTick = -1;
			$this->mob->setAttackTarget($closer);
		}
	}

	public function start() : void{
		$this->mob->setAttackTarget($this->target);
		$this->unseenTicks = 0;
		$this->rejectedSinceTick = -1;
		$this->reselectTicks = $this->interval;
		$this->keepRange = max($this->maxDistance ?? $this->range, $this->mob->getFollowRange());
	}

	public function stop() : void{
		if($this->mob->getAttackTarget() === $this->target){
			$this->mob->setAttackTarget(null);
		}
		$this->target = null;
	}
}
