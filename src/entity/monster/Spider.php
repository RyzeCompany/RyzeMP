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

use pocketmine\block\Block;
use pocketmine\block\Cobweb;
use pocketmine\entity\ai\goal\AvoidEntityGoal;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\LeapAtTargetGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\MountPathingGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\navigation\ClimbingPathNavigator;
use pocketmine\entity\ai\navigation\PathNavigator;
use pocketmine\entity\animal\Armadillo;
use pocketmine\entity\effect\Effect;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\golem\SnowGolem;
use pocketmine\entity\Living;
use pocketmine\entity\riding\RideableSeat;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use function floor;
use function max;
use function mt_rand;

/**
 * Bedrock values: 16 health, movement 0.3, 1.4x0.9, attack 2, climbs walls, leaps at its target. Neutral in bright
 * places (light 12 or more, vanilla brightness > 0.49) unless attacked. Carries a skeleton (spider jockey, see
 * {@link \pocketmine\entity\riding\Jockeys}) or a baby zombie, and walks to its rider's target (vanilla
 * behavior.mount_pathing). Targets players, snow golems and iron golems, and runs from unrolled armadillos.
 *
 * Bedrock spider_angry: picking a target or being hurt makes it angry for 10 +- 3 seconds, and it only attacks while
 * angry. When the anger ends in a bright place it calms down and drops its target (a spider hit by day attacks for a
 * while, then leaves the player alone); in the dark, where spiders look for players (every 5 seconds on average,
 * attack_interval), it keeps its target and stays angry.
 * Loot: 0-2 string, and 0-1 spider eye when killed by a player, +0-1 each per Looting level.
 */
class Spider extends Monster{
	public const ATTACK_DAMAGE = 2.0;
	public const NEUTRAL_LIGHT = 12;
	/** Bedrock minecraft:angry of spider_angry: duration 10 seconds, duration_delta 3. */
	private const ANGER_MIN_TICKS = 7 * 20;
	private const ANGER_MAX_TICKS = 13 * 20;

	/** Server tick at which the anger ends. */
	private int $angerEndTick = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::SPIDER; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.9, 1.4, 0.65);
	}

	public function getName() : string{
		return "Spider";
	}

	public function isImmuneToEffect(Effect $effect) : bool{
		//vanilla: spiders and cave spiders can't be poisoned
		return $effect === VanillaEffects::POISON() || $effect === VanillaEffects::FATAL_POISON() || parent::isImmuneToEffect($effect);
	}

	protected function isImmuneToBlockSlowdown(Block $block) : bool{
		return $block instanceof Cobweb; //block_movement_slowdown_immunity
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(16);
		$this->setMovementSpeed(0.3);
		parent::initEntity($nbt);
	}

	protected function createNavigation() : PathNavigator{
		//vanilla navigation.climb: walks straight at targets its path can't reach and climbs the walls in the way
		return new ClimbingPathNavigator($this);
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(1, new FloatGoal($this));
		//avoid_mob_type unrolled armadillos within 6 blocks, sprint x1.2 (Java AvoidEntityGoal(Armadillo, 6, 1.0, 1.2));
		//looked for only while an armadillo is within a few blocks more (checked once a second)
		$armadillos = new NearbyEntityCheck($this, [Armadillo::class], fn(Entity $e) : bool => true, 6.0 + MonsterTargets::THREAT_MARGIN, 3.0 + MonsterTargets::THREAT_MARGIN);
		$this->goalSelector->addGoal(2, new AvoidEntityGoal($this, fn(Entity $e) => $e instanceof Armadillo && !$e->isRolledUp(), 6.0, 1.0, 1.2, $armadillos->check(...)));
		//the leap above the melee attack (Java order, Bedrock lists it below): it interrupts the chase while it lasts
		$this->goalSelector->addGoal(2, new LeapAtTargetGoal($this, 0.4, mustBeOnGround: false));
		$this->goalSelector->addGoal(3, new MeleeAttackGoal($this, 1.0, self::ATTACK_DAMAGE, 16.0));
		$this->goalSelector->addGoal(5, new MountPathingGoal($this, 1.25));
		$this->goalSelector->addGoal(6, new RandomStrollGoal($this, 0.8));
		$this->goalSelector->addGoal(7, new LookAtPlayerGoal($this, 6.0));
		$this->goalSelector->addGoal(7, new RandomLookAroundGoal($this));

		$this->targetSelector->addGoal(1, new HurtByTargetGoal($this));
		//spider_hostile (dark only): players, snow golems and iron golems, attack_interval 5 seconds
		$nearby = MonsterTargets::nearbyTargets($this, [IronGolem::class, SnowGolem::class], MonsterTargets::isGolem(...), 16.0);
		$dark = fn() : bool => !$this->isInBrightLight();
		$this->targetSelector->addGoal(2, MonsterTargets::extraTargetGoal($this, MonsterTargets::isGolem(...), $nearby, $dark, 16.0, true, 100));
		$this->targetSelector->addGoal(2, MonsterTargets::playerTargetGoal($this, $nearby, $dark, 16.0, true, 100));
	}

	public function setAttackTarget(?Living $target) : void{
		$previous = $this->attackTarget;
		parent::setAttackTarget($target);
		if($this->attackTarget !== null && $this->attackTarget !== $previous){
			//on_target_acquired: become_angry
			$this->becomeAngry();
		}
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if(!$source->isCancelled() && $source instanceof EntityDamageByEntityEvent && $source->getDamager() instanceof Living && $this->isAlive()){
			$this->becomeAngry();
		}
	}

	private function becomeAngry() : void{
		$this->angerEndTick = $this->server->getTick() + mt_rand(self::ANGER_MIN_TICKS, self::ANGER_MAX_TICKS);
	}

	/**
	 * Returns whether the spider is angry (spider_angry): it only attacks while angry.
	 */
	public function isAngry() : bool{
		return $this->server->getTick() < $this->angerEndTick;
	}

	protected function tickAi(int $currentTick) : void{
		if($this->attackTarget !== null && $currentTick >= $this->angerEndTick){
			//become_calm: in a bright place the spider is neutral and leaves its target alone; in the dark its target
			//search (spider_hostile) takes it again and makes it angry again
			if($this->isInBrightLight()){
				$this->setAttackTarget(null);
			}else{
				$this->becomeAngry();
			}
		}
		parent::tickAi($currentTick);
	}

	public function getRideableSeats() : array{
		$rider = $this->passengers[0] ?? null;
		//vanilla seats: skeletons sit in the middle, bogged and parched (and baby zombies) a bit lower and further back
		return [match(true){
			$rider instanceof Bogged, $rider instanceof Parched => RideableSeat::at(0, 0.325, -0.1),
			$rider instanceof Skeleton, $rider instanceof WitherSkeleton => RideableSeat::at(0, 0.54, 0),
			default => RideableSeat::at(0, 0.54, -0.1)
		}];
	}

	public function canBeRiddenBy(Entity $entity) : bool{
		return $entity instanceof Skeleton || $entity instanceof WitherSkeleton || ($entity instanceof Zombie && $entity->isBaby());
	}

	protected function isAngryWhenTargeting() : bool{
		//spider_angry (minecraft:angry), added when a target is acquired
		return true;
	}

	/**
	 * Spiders don't look for targets in bright places.
	 */
	public function isInBrightLight() : bool{
		return $this->getWorld()->getFullLightAt((int) floor($this->location->x), (int) floor($this->location->y + 0.5), (int) floor($this->location->z)) >= self::NEUTRAL_LIGHT;
	}

	protected function tryChangeMovement() : void{
		parent::tryChangeMovement();
		if($this->isAlive() && $this->isCollidedHorizontally && $this->moveControl->isMoving()){
			//climb the wall in front of it
			$this->motion = $this->motion->withComponents(null, max($this->motion->y, 0.2), null);
			$this->setCanClimbWalls(true);
		}elseif($this->canClimbWalls && $this->onGround){
			$this->setCanClimbWalls(false);
		}
	}

	protected function calculateFallDamage(float $fallDistance) : float{
		return $this->canClimbWalls ? 0 : parent::calculateFallDamage($fallDistance);
	}

	public function getDrops() : array{
		$drops = [VanillaItems::STRING()->setCount(mt_rand(0, 2) + $this->lootingBonus())];
		if($this->wasRecentlyHitByPlayerDirectly()){
			$drops[] = VanillaItems::SPIDER_EYE()->setCount(mt_rand(0, 1) + $this->lootingBonus());
		}
		return $drops;
	}
}
