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
use pocketmine\entity\ai\navigation\RandomPos;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\Spear;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\sound\SpearHitSound;
use function cos;
use function floor;
use function max;
use function mt_rand;
use function sin;
use function sqrt;
use const M_PI;

/**
 * Spear charges of mobs holding a spear (vanilla behavior.use_kinetic_weapon of zombies, husks and zombie villagers;
 * Java SpearUseGoal): the mob walks up to its target until it is within $approachDistance, lowers the spear and charges
 * at it; once next to it (or its path ended) it runs 6-7 blocks away from the target and charges again, until the
 * spear's charge is over. Then it raises the spear and retreats 9-11 blocks away (at most 5 seconds), and the goal
 * ends. While the spear is lowered every living thing it touches is hit like by a player's charge (see {@link Spear}),
 * with the mob values of Bedrock: half the reach (weapon_reach_multiplier 0.5), a fifth of the minimum speeds
 * (weapon_min_speed_multiplier 0.2), and the mob's attack damage added to the speed damage (Java KineticWeapon).
 *
 * A mob riding another mob steers it (hijack_mount_navigation): the mount's own movement goals pause while the goal
 * runs, and the mount's speed decides the hits.
 *
 * Only runs while the mob has an attack target and holds a spear in its main hand.
 */
class KineticWeaponGoal extends Goal{
	/** Bedrock weapon_reach_multiplier: mobs reach half as far as players. */
	public const REACH_MULTIPLIER = 0.5;
	/** Bedrock weapon_min_speed_multiplier (Java mob action factor): mobs need a fifth of the speeds players need. */
	public const MIN_SPEED_MULTIPLIER = 0.2;
	/** Spear kinetic_weapon reach max (Bedrock items: 4.5 for every spear) and hitbox_margin. */
	private const SPEAR_REACH = 4.5;
	private const HITBOX_MARGIN = 0.25;
	/** Bedrock spear dismount_conditions min_speed (blocks/second, before the mob multiplier). */
	private const DISMOUNT_MIN_SPEED = 11.0;
	/** Longest retreat after the charge (Java SpearUseGoal MAX_FLEEING_TIME). */
	private const MAX_RETREAT_TICKS = 100;
	private const REPATH_TICKS = 5;

	private const STATE_APPROACH = 0;
	private const STATE_CHARGE = 1;
	private const STATE_RETREAT = 2;
	private const STATE_DONE = 3;

	private int $state = self::STATE_APPROACH;
	/** Ticks the spear has been lowered. */
	private int $useTicks = 0;
	private int $retreatTicks = 0;
	private int $repathDelay = 0;
	/** Where the mob runs to between two charges, or after the charge. */
	private ?Vector3 $awayPos = null;
	/** AI tick of the last hit on each entity (by ID): an entity is hit at most once every 10 ticks. */
	private int $lastHitTarget = -1;
	private int $lastHitTick = -1;
	/** The mount whose movement goals are paused while the mob steers it. */
	private ?Mob $hijackedMount = null;

	/**
	 * @param float $baseDamage                the mob's attack damage, added to the speed damage
	 * @param float $approachDistance          blocks to the target from which the charge starts (Bedrock approach_distance)
	 * @param int[] $reposition                min and max blocks from the target the mob runs to between charges
	 * @param int[] $cooldown                  min and max blocks from the target the mob retreats to after the charge
	 * @param float $speedMultiplier           speed while charging (Bedrock speed_multiplier)
	 * @param float $repositionSpeedMultiplier speed of the approach and of the runs between charges (Bedrock
	 *                                         reposition_speed_multiplier)
	 * @param float $cooldownSpeedMultiplier   speed of the retreat after the charge (Bedrock cooldown_speed_multiplier)
	 * @phpstan-param array{int, int} $reposition
	 * @phpstan-param array{int, int} $cooldown
	 * @phpstan-param \Closure() : Item $mainHandItem returns the item the mob holds
	 * @phpstan-param (\Closure() : bool)|null $condition extra condition (e.g. riding a zombie horse), without side effects
	 * @phpstan-param (\Closure(bool) : void)|null $onUsingChanged called when the mob lowers (true) or raises the spear
	 * @phpstan-param (\Closure() : bool)|null $holdsSpear whether $mainHandItem would return a spear, without copying the
	 *                                                    item (asked on every goal evaluation of a mob with a target);
	 *                                                    null: $mainHandItem is asked
	 */
	public function __construct(
		protected Mob $mob,
		protected \Closure $mainHandItem,
		protected float $baseDamage = 3.0,
		protected float $approachDistance = 10.0,
		protected array $reposition = [6, 7],
		protected array $cooldown = [9, 11],
		protected float $speedMultiplier = 1.0,
		protected float $repositionSpeedMultiplier = 1.0,
		protected float $cooldownSpeedMultiplier = 1.0,
		protected ?\Closure $condition = null,
		protected ?\Closure $onUsingChanged = null,
		protected ?\Closure $holdsSpear = null
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	private function getSpear() : ?Spear{
		$item = ($this->mainHandItem)();
		return $item instanceof Spear ? $item : null;
	}

	public function canStart() : bool{
		//the condition (whether the mob rides its mount) before the spear: the held item is a copy of the item, and of
		//the goals of a mob with and without a mount only one passes its condition
		return $this->mob->getAttackTarget() !== null && ($this->condition === null || ($this->condition)()) &&
			($this->holdsSpear !== null ? ($this->holdsSpear)() : $this->getSpear() !== null);
	}

	public function canContinue() : bool{
		return $this->state !== self::STATE_DONE && $this->canStart();
	}

	public function start() : void{
		$this->state = self::STATE_APPROACH;
		$this->useTicks = 0;
		$this->retreatTicks = 0;
		$this->repathDelay = 0;
		$this->awayPos = null;
		$vehicle = $this->mob->getVehicle();
		if($vehicle instanceof Mob && $vehicle->getControllingPassenger() === $this->mob){
			$this->hijackedMount = $vehicle;
			$vehicle->getGoalSelector()->setFlagDisabled(self::FLAG_MOVE, true);
		}
	}

	public function stop() : void{
		$this->setUsing(false);
		$this->state = self::STATE_DONE;
		$this->getNavigator()->getNavigation()->stop();
		//a closed mount has no goal selector left (Mob::destroyCycles())
		if($this->hijackedMount !== null && !$this->hijackedMount->isClosed()){
			$this->hijackedMount->getGoalSelector()->setFlagDisabled(self::FLAG_MOVE, false);
		}
		$this->hijackedMount = null;
		$this->awayPos = null;
	}

	/**
	 * Whether the spear is lowered (the charge is on).
	 */
	public function isCharging() : bool{
		return $this->state === self::STATE_CHARGE;
	}

	private function setUsing(bool $using) : void{
		if($using !== ($this->state === self::STATE_CHARGE) && $this->onUsingChanged !== null){
			($this->onUsingChanged)($using);
		}
	}

	/**
	 * The mob that walks: the hijacked mount, or the mob itself.
	 */
	private function getNavigator() : Mob{
		$mount = $this->hijackedMount;
		return $mount !== null && !$mount->isClosed() && $this->mob->getVehicle() === $mount ? $mount : $this->mob;
	}

	public function tick() : void{
		$target = $this->mob->getAttackTarget();
		$spear = $this->getSpear();
		if($target === null || $spear === null){
			return;
		}
		$this->mob->getLookControl()->setLookAtEntity($target, 30.0, 30.0);
		$navigator = $this->getNavigator();
		$loc = $navigator->getLiveLocation();
		$targetLoc = Mob::getLiveLocationOf($target) ?? $target->getLocation();
		$distSq = $loc->distanceSquared($targetLoc);
		//riders keep a block more of room (Java SpearUseGoal: +2 blocks for passengers, from the target to the mount)
		$mountDistance = $navigator !== $this->mob ? 2 : 0;

		if($this->state === self::STATE_APPROACH){
			if($distSq > $this->approachDistance ** 2){
				$this->chase($navigator, $target, $this->repositionSpeedMultiplier);
				return;
			}
			$this->setUsing(true);
			$this->state = self::STATE_CHARGE;
			$this->useTicks = 0;
			$this->repathDelay = 0;
		}

		if($this->state === self::STATE_CHARGE){
			[$delay, $engaged, $tired, $disengaged] = $spear->getChargeTimings();
			++$this->useTicks;
			if($this->useTicks > $delay){
				$this->tryHit($spear, $target, $this->useTicks - $delay, $engaged, $tired, $disengaged);
			}
			if($this->useTicks >= $delay + $engaged + $tired + $disengaged){
				//the charge is over: raise the spear and retreat
				$this->setUsing(false);
				$this->state = self::STATE_RETREAT;
				$this->retreatTicks = 0;
				$distance = sqrt($distSq);
				$this->awayPos = $this->findPosAway($navigator, $targetLoc, max(0.0, $this->cooldown[0] + $mountDistance - $distance), max(1.0, $this->cooldown[1] + $mountDistance - $distance));
				if($this->awayPos === null){
					$this->state = self::STATE_DONE;
				}else{
					$navigator->getNavigation()->moveTo($this->awayPos->x + 0.5, $this->awayPos->y, $this->awayPos->z + 0.5, $this->cooldownSpeedMultiplier);
				}
				return;
			}
			$awayPos = $this->awayPos;
			if($awayPos !== null){
				//running off to charge again
				$navigation = $navigator->getNavigation();
				$navigation->moveTo($awayPos->x + 0.5, $awayPos->y, $awayPos->z + 0.5, $this->repositionSpeedMultiplier);
				if($navigation->isDone()){
					$this->awayPos = null;
					$this->repathDelay = 0;
				}
				return;
			}
			$this->chase($navigator, $target, $this->speedMultiplier);
			$reach = self::SPEAR_REACH * self::REACH_MULTIPLIER;
			if($distSq < $reach * $reach || ($this->repathDelay < self::REPATH_TICKS - 1 && $navigator->getNavigation()->isDone())){
				$distance = sqrt($distSq);
				$this->awayPos = $this->findPosAway($navigator, $targetLoc, $this->reposition[0] + $mountDistance - $distance, $this->reposition[1] + $mountDistance - $distance);
			}
			return;
		}

		if($this->state === self::STATE_RETREAT){
			$awayPos = $this->awayPos;
			if($awayPos === null || ++$this->retreatTicks > self::MAX_RETREAT_TICKS || $navigator->getNavigation()->isDone()){
				$this->state = self::STATE_DONE;
				return;
			}
			$navigator->getNavigation()->moveTo($awayPos->x + 0.5, $awayPos->y, $awayPos->z + 0.5, $this->cooldownSpeedMultiplier);
		}
	}

	private function chase(Mob $navigator, Living $target, float $speed) : void{
		if(--$this->repathDelay <= 0){
			$this->repathDelay = self::REPATH_TICKS;
			$navigator->getNavigation()->moveToEntity($target, $speed, 0.0);
		}
	}

	/**
	 * Returns a standable block between $min and $max blocks farther from the target than the mob is now (Java
	 * LandRandomPos.getPosAway()), or null.
	 */
	private function findPosAway(Mob $navigator, Vector3 $from, float $min, float $max) : ?Vector3{
		$loc = $navigator->getLiveLocation();
		$dx = $loc->x - $from->x;
		$dz = $loc->z - $from->z;
		$length = sqrt($dx * $dx + $dz * $dz);
		if($length < 0.01){
			$angle = mt_rand(0, 359) * M_PI / 180;
			$dx = cos($angle);
			$dz = sin($angle);
			$length = 1.0;
		}
		$distance = max(1.0, $min + ($max - $min) * mt_rand(0, 100) / 100);
		$x = (int) floor($loc->x + $dx / $length * $distance);
		$z = (int) floor($loc->z + $dz / $length * $distance);
		$y = (int) floor($loc->y + 0.01);
		$world = $navigator->getWorld();
		for($i = 0; $i <= 14; ++$i){
			$dy = ($i & 1) === 1 ? ($i + 1) >> 1 : -($i >> 1); //0, 1, -1, 2, -2... 7, -7
			if(PathBlockTypes::isStandable($world, $x, $y + $dy, $z, 1, false, 1)){
				return new Vector3($x, $y + $dy, $z);
			}
		}
		return RandomPos::getLandPos($navigator, (int) max(1, floor($distance)), 7, $from);
	}

	/**
	 * Hits the target if the lowered spear touches it, with the damage, knockback and dismount of the charge stage
	 * ($ticks since the spear's activation delay ended; Bedrock kinetic_weapon conditions: damage until the end of
	 * the charge, knockback until the end of the tired stage, dismount during the engaged stage).
	 */
	private function tryHit(Spear $spear, Living $target, int $ticks, int $engaged, int $tired, int $disengaged) : void{
		$mob = $this->mob;
		$aiTick = $mob->getAiTick();
		if($this->lastHitTarget === $target->getId() && $aiTick - $this->lastHitTick < Spear::CHARGE_HIT_INTERVAL_TICKS){
			return;
		}
		if(!$target->isAlive()){
			return;
		}
		$eye = $mob->getEyePos();
		$box = $target->getBoundingBox();
		$center = new Vector3(($box->minX + $box->maxX) / 2, ($box->minY + $box->maxY) / 2, ($box->minZ + $box->maxZ) / 2);
		$direction = $center->subtractVector($eye);
		$length = $direction->length();
		if($length < 0.0001){
			return;
		}
		$direction = $direction->divide($length);
		$end = $eye->addVector($direction->multiply(self::SPEAR_REACH * self::REACH_MULTIPLIER));
		if($box->expandedCopy(self::HITBOX_MARGIN, self::HITBOX_MARGIN, self::HITBOX_MARGIN)->calculateIntercept($eye, $end) === null && !$box->isVectorInside($eye)){
			return;
		}

		//speeds along the spear in blocks/second: a rider moves with its mount
		$attackerSpeed = $this->getNavigator()->getMotion()->dot($direction) * 20;
		$targetSpeed = ($target->getVehicle() ?? $target)->getMotion()->dot($direction) * 20;
		$relativeSpeed = max(0.0, $attackerSpeed - $targetSpeed);
		$factor = self::MIN_SPEED_MULTIPLIER;
		$dealsDamage = $ticks <= $engaged + $tired + $disengaged && $relativeSpeed >= Spear::CHARGE_MIN_DAMAGE_SPEED * $factor;
		$dealsKnockback = $ticks <= $engaged + $tired && $attackerSpeed >= Spear::CHARGE_MIN_KNOCKBACK_SPEED * $factor;
		$dealsDismount = $ticks <= $engaged && $attackerSpeed >= self::DISMOUNT_MIN_SPEED * $factor;
		if(!$dealsDamage && !$dealsKnockback && !$dealsDismount){
			return;
		}
		$this->lastHitTarget = $target->getId();
		$this->lastHitTick = $aiTick;

		//Java KineticWeapon: the mob's attack damage plus the speed damage
		$damage = $dealsDamage ? $this->baseDamage + floor($relativeSpeed * $spear->getChargeDamageMultiplier()) : 0.0;
		if($target instanceof Player){
			$damage = Mob::scaleDamageForDifficulty($damage, $mob->getWorld()->getDifficulty());
		}
		$ev = new EntityDamageByEntityEvent($mob, $target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $damage, [], $dealsKnockback ? Living::DEFAULT_KNOCKBACK_FORCE : 0.0);
		$target->attack($ev);
		if($ev->isCancelled()){
			return;
		}
		$mob->broadcastSound(new SpearHitSound($spear->getTier()));
		if($dealsDismount){
			$target->dismount(true);
		}
	}
}
