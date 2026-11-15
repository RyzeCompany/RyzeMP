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

namespace pocketmine\entity\monster;

use pocketmine\entity\ai\goal\NearestAttackableEntityGoal;
use pocketmine\entity\ai\goal\NearestAttackableTargetGoal;
use pocketmine\entity\animal\Turtle;
use pocketmine\entity\Entity;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\golem\SnowGolem;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\player\Player;
use pocketmine\world\World;

/**
 * The non-player targets of the skeleton family and of spiders and silverfish (Bedrock nearest_attackable_target
 * entity_types).
 *
 * Those mobs have two target goals at the same priority sharing one {@link NearbyEntityCheck}, and only one of them
 * looks for targets at a time: while none of the other targets is within range, the player-only goal (the world's
 * player list is cheap); while one is, the goal made by {@link MonsterTargets::extraTargetGoal()}, which looks for
 * players and the other targets together, so the nearest of them is picked like in Bedrock. Other targets far away
 * cost nothing; near ones a look at the entity lists of the chunks around the mob once a second.
 *
 * @internal
 */
final class MonsterTargets{
	/**
	 * Blocks added to the range of an avoided entity for the check of whether one is near (refreshed once a second):
	 * about what a running mob covers in that time.
	 */
	public const THREAT_MARGIN = 4.0;

	private function __construct(){
		//NOOP
	}

	/**
	 * Spiders, cave spiders and silverfish: snow golems and iron golems.
	 */
	public static function isGolem(Living $entity) : bool{
		return $entity instanceof IronGolem || $entity instanceof SnowGolem;
	}

	/**
	 * Baby turtles out of water (family baby_turtle with in_water false).
	 */
	public static function isBabyTurtleOnLand(Living $entity) : bool{
		return $entity instanceof Turtle && $entity->isBaby() && !$entity->isInWater();
	}

	/**
	 * Skeletons, strays, bogged and parched: iron golems and baby turtles out of water.
	 */
	public static function isSkeletonTarget(Living $entity) : bool{
		return $entity instanceof IronGolem || self::isBabyTurtleOnLand($entity);
	}

	/**
	 * Wither skeletons: piglins and piglin brutes (family piglin) unless the difficulty is Peaceful, iron golems and baby
	 * turtles out of water.
	 */
	public static function isWitherSkeletonTarget(Living $entity) : bool{
		return (($entity instanceof Piglin || $entity instanceof PiglinBrute) && $entity->getWorld()->getDifficulty() !== World::DIFFICULTY_PEACEFUL) ||
			self::isSkeletonTarget($entity);
	}

	/**
	 * Returns the check of whether a target of the classes accepted by $extra is within $range of the mob (horizontally
	 * and vertically, like the search of {@link MonsterTargets::extraTargetGoal()}), for the two target goals.
	 *
	 * @param string[] $classes
	 * @phpstan-param list<class-string<Living>> $classes
	 * @phpstan-param \Closure(Living) : bool $extra
	 */
	public static function nearbyTargets(Mob $mob, array $classes, \Closure $extra, float $range) : NearbyEntityCheck{
		return new NearbyEntityCheck($mob, $classes, fn(Entity $e) : bool => $e instanceof Living && $extra($e), $range, $range);
	}

	/**
	 * Returns the goal that targets the nearest player or entity accepted by $extra, which only looks for targets while
	 * $nearby finds such an entity (checked before the random search interval). $condition is checked like the condition
	 * of {@link NearestAttackableEntityGoal}, also before reselecting a target. Add it at the priority of the goal made
	 * by {@link MonsterTargets::playerTargetGoal()} with the same $nearby: one of the two looks for targets on each
	 * evaluation, so the search keeps its Bedrock interval. $extra only accepts entities of the classes of $nearby
	 * (made by {@link MonsterTargets::nearbyTargets()} with the same $extra): the search passes over the other entities.
	 *
	 * @phpstan-param \Closure(Living) : bool $extra
	 * @phpstan-param (\Closure() : bool)|null $condition
	 */
	public static function extraTargetGoal(Mob $mob, \Closure $extra, NearbyEntityCheck $nearby, ?\Closure $condition, float $range, bool $mustSee, int $interval, bool $reselect = false) : NearestAttackableEntityGoal{
		return new class($nearby, $mob, fn(Living $e) => $e instanceof Player || $extra($e), $range, $mustSee, $interval, $condition, $reselect) extends NearestAttackableEntityGoal{
			/**
			 * @phpstan-param \Closure(Living) : bool $filter
			 * @phpstan-param (\Closure() : bool)|null $condition
			 */
			public function __construct(private NearbyEntityCheck $nearby, Mob $mob, \Closure $filter, float $range, bool $mustSee, int $interval, ?\Closure $condition, bool $reselect){
				parent::__construct($mob, $filter, $range, $mustSee, $interval, $condition, verticalRange: $range, reselect: $reselect, candidateClasses: [Player::class, ...$nearby->getClasses()]);
			}

			public function canStart() : bool{
				return $this->nearby->check() && parent::canStart();
			}
		};
	}

	/**
	 * Returns the player-only target goal (the world's player list: cheap), which only looks for targets while $nearby
	 * finds none of the other targets.
	 *
	 * @phpstan-param (\Closure() : bool)|null $condition
	 */
	public static function playerTargetGoal(Mob $mob, NearbyEntityCheck $nearby, ?\Closure $condition, float $range, bool $mustSee, int $interval, bool $reselect = false) : NearestAttackableTargetGoal{
		return new class($nearby, $mob, $range, $mustSee, $interval, $condition, $reselect) extends NearestAttackableTargetGoal{
			/**
			 * @phpstan-param (\Closure() : bool)|null $condition
			 */
			public function __construct(private NearbyEntityCheck $nearby, Mob $mob, float $range, bool $mustSee, int $interval, ?\Closure $condition, bool $reselect){
				parent::__construct($mob, $range, $mustSee, $interval, $condition, reselect: $reselect);
			}

			public function canStart() : bool{
				return !$this->nearby->check() && parent::canStart();
			}
		};
	}
}
