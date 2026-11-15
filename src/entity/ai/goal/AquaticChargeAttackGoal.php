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

use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\player\Player;
use function sqrt;

/**
 * Vanilla behavior.aquatic_charge_attack (nautilus, zombie nautilus): a swimming mob dashes in a straight line through
 * its attack target, hits it once on the way (attack_reach 0: the bounding boxes touch) with extra knockback, keeps
 * going for charge_overshoot_distance blocks past where the target was, and waits charge_cooldown_time before the next
 * charge. A charge ends after max_charge_distance blocks at most. A target farther than that is first approached at
 * the normal swimming speed (it has to be seen to start the charge).
 *
 * The charge direction is chosen when the charge starts (Java ChargeAttack: a fixed velocity), so a target that moves
 * away is missed. All the work is per-tick arithmetic on the running goal; nothing is searched.
 */
class AquaticChargeAttackGoal extends Goal{
	/** A charge never lasts longer than this, whatever blocks the way. */
	private const MAX_CHARGE_TICKS = 100;

	private bool $charging = false;
	private bool $hit = false;
	private int $chargeTicks = 0;
	private int $nextChargeTick = 0;
	private float $startX = 0.0;
	private float $startY = 0.0;
	private float $startZ = 0.0;
	private float $destX = 0.0;
	private float $destY = 0.0;
	private float $destZ = 0.0;

	/**
	 * @param float $chargeSpeed   blocks per tick during the charge
	 * @param float $approachSpeed blocks per tick while swimming towards a target out of charge range
	 * @param float $knockback     knockback strength of the hit (the normal 0.4 included)
	 * @param float $followRange   how far a target is approached
	 *
	 * @phpstan-param \Closure(float, float, float, float) : void $swimTowards moves the mob towards x, y, z at a speed in blocks per tick
	 * @phpstan-param \Closure() : void $stopSwimming
	 * @phpstan-param (\Closure() : bool)|null $condition
	 */
	public function __construct(
		protected Mob $mob,
		protected \Closure $swimTowards,
		protected \Closure $stopSwimming,
		protected float $chargeSpeed,
		protected float $approachSpeed,
		protected float $damage,
		protected float $knockback,
		protected float $maxChargeDistance = 16.0,
		protected float $overshoot = 1.5,
		protected int $cooldownTicks = 80,
		protected float $followRange = 32.0,
		protected ?\Closure $condition = null
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public function canStart() : bool{
		return $this->mob->getAiTick() >= $this->nextChargeTick && ($this->condition === null || ($this->condition)()) &&
			$this->mob->getSensing()->isValidTarget($this->mob->getAttackTarget(), $this->followRange);
	}

	public function canContinue() : bool{
		if($this->condition !== null && !($this->condition)()){
			return false;
		}
		if(!$this->charging){
			return $this->mob->getSensing()->isValidTarget($this->mob->getAttackTarget(), $this->followRange);
		}
		$loc = $this->mob->getLiveLocation();
		$fromStart = ($loc->x - $this->startX) ** 2 + ($loc->y - $this->startY) ** 2 + ($loc->z - $this->startZ) ** 2;
		$toDest = ($loc->x - $this->destX) ** 2 + ($loc->y - $this->destY) ** 2 + ($loc->z - $this->destZ) ** 2;
		return $this->chargeTicks < self::MAX_CHARGE_TICKS && $fromStart < $this->maxChargeDistance ** 2 && $toDest > 0.25;
	}

	public function start() : void{
		$this->charging = false;
		$this->hit = false;
		$this->chargeTicks = 0;
	}

	public function stop() : void{
		($this->stopSwimming)();
		if($this->charging){
			$this->nextChargeTick = $this->mob->getAiTick() + $this->cooldownTicks;
		}
		$this->charging = false;
	}

	/**
	 * Returns whether the mob is dashing at its target (as opposed to swimming closer or not attacking).
	 */
	public function isCharging() : bool{
		return $this->charging;
	}

	public function tick() : void{
		$target = $this->mob->getAttackTarget();
		if($this->charging){
			++$this->chargeTicks;
			($this->swimTowards)($this->destX, $this->destY, $this->destZ, $this->chargeSpeed);
			if($target !== null){
				$this->mob->getLookControl()->setLookAtEntity($target, 30.0, 30.0);
				if(!$this->hit && $this->mob->getBoundingBox()->intersectsWith($target->getBoundingBox())){
					$this->hit = true;
					$this->hitTarget($target);
				}
			}
			return;
		}
		if($target === null){
			return;
		}
		$loc = $this->mob->getLiveLocation();
		$targetLoc = Mob::getLiveLocationOf($target) ?? $target->getLocation();
		$tx = $targetLoc->x;
		$ty = $targetLoc->y + $target->getSize()->getHeight() * 0.5;
		$tz = $targetLoc->z;
		$this->mob->getLookControl()->setLookAtEntity($target, 30.0, 30.0);
		$dx = $tx - $loc->x;
		$dy = $ty - ($loc->y + $this->mob->getSize()->getHeight() * 0.5);
		$dz = $tz - $loc->z;
		$distance = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
		if($distance > $this->maxChargeDistance - $this->overshoot || !$this->mob->getSensing()->canSee($target)){
			($this->swimTowards)($tx, $ty, $tz, $this->approachSpeed);
			return;
		}
		//the charge: straight through where the target is now, and a little further
		$this->charging = true;
		$this->startX = $loc->x;
		$this->startY = $loc->y;
		$this->startZ = $loc->z;
		$scale = $distance > 0.001 ? ($distance + $this->overshoot) / $distance : 0.0;
		$this->destX = $loc->x + $dx * $scale;
		$this->destY = $loc->y + $dy * $scale;
		$this->destZ = $loc->z + $dz * $scale;
		($this->swimTowards)($this->destX, $this->destY, $this->destZ, $this->chargeSpeed);
	}

	private function hitTarget(Living $target) : void{
		$damage = $target instanceof Player ? Mob::scaleDamageForDifficulty($this->damage, $this->mob->getWorld()->getDifficulty()) : $this->damage;
		if($damage <= 0){
			return;
		}
		$target->attack(new EntityDamageByEntityEvent($this->mob, $target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $damage, [], $this->knockback));
	}
}
