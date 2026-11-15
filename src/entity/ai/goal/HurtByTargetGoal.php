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
use pocketmine\math\AxisAlignedBB;
use function max;

/**
 * Targets whoever hurt the mob (vanilla behavior.hurt_by_target), or whoever hurt a mob of its group that alerted it
 * (see {@link Mob::alertTo()}). The target is kept within $range blocks, or the mob's follow range
 * ({@link Mob::getFollowRange()}) if larger.
 *
 * Optionally the mob alerts the mobs of its group around it, which then target the attacker too (Bedrock broadcast_anger:
 * dolphins 16 blocks, silverfish and bees 20, pandas and polar bears 41), and stays angry only for a while after the last
 * hit (Bedrock minecraft:angry duration).
 */
class HurtByTargetGoal extends Goal{
	private int $handledAttackTick = -1;
	private int $handledAlertTick = -1000;
	private ?Living $target = null;
	/** Whether the current target comes from an alert (alerted mobs don't alert others). */
	private bool $alerted = false;
	private float $keepRange = 0.0;
	/** Server tick at which the anger ends ($angerTicks > 0), refreshed by every new hit of the target. */
	private int $angerEndTick = 0;

	/**
	 * @param string[]      $ignoredAttackers classes of attackers the mob doesn't retaliate against (e.g. shulkers ignore
	 *                                        other shulkers, like vanilla's HurtByTargetGoal(this, Shulker.class))
	 * @param float         $alertRange       how far around the mob the mobs of its group are alerted when it is hurt (0:
	 *                                        nobody), only those without a target (Bedrock broadcast_range)
	 * @param \Closure|null $alertFilter      which nearby mobs are alerted (default: mobs of the same type; Bedrock
	 *                                        broadcast_targets / broadcast_filters)
	 * @param int           $angerTicks       ticks the target is kept after the last hit or alert (0: as long as it is
	 *                                        valid; Bedrock minecraft:angry duration)
	 *
	 * @phpstan-param list<class-string<Living>> $ignoredAttackers
	 * @phpstan-param (\Closure(Mob) : bool)|null $alertFilter
	 */
	public function __construct(
		protected Mob $mob,
		protected float $range = 32.0,
		protected array $ignoredAttackers = [],
		protected float $alertRange = 0.0,
		protected ?\Closure $alertFilter = null,
		protected int $angerTicks = 0
	){}

	public function getFlags() : int{
		return self::FLAG_TARGET;
	}

	private function getKeepRange() : float{
		return max($this->range, $this->mob->getFollowRange());
	}

	private function isIgnored(Living $attacker) : bool{
		foreach($this->ignoredAttackers as $class){
			if($attacker instanceof $class){
				return true;
			}
		}
		return false;
	}

	public function canStart() : bool{
		$tick = $this->mob->getLastAttackedTick();
		if($tick !== $this->handledAttackTick){
			$attacker = $this->mob->getLastAttacker();
			if($attacker !== null && $this->mob->getSensing()->isValidTarget($attacker, $this->getKeepRange())){
				if($this->isIgnored($attacker)){
					$this->handledAttackTick = $tick;
				}else{
					$this->target = $attacker;
					$this->alerted = false;
					return true;
				}
			}
		}
		$alertTick = $this->mob->getAlertTick();
		if($alertTick !== $this->handledAlertTick){
			//alerts are acted on once, right away
			$this->handledAlertTick = $alertTick;
			$alertTarget = $this->mob->getAlertTarget();
			if($alertTarget !== null && !$this->isIgnored($alertTarget) && $this->mob->getSensing()->isValidTarget($alertTarget, $this->getKeepRange())){
				$this->target = $alertTarget;
				$this->alerted = true;
				return true;
			}
		}
		return false;
	}

	public function canContinue() : bool{
		$target = $this->mob->getAttackTarget();
		if($target === null || $target !== $this->target || !$this->mob->getSensing()->isValidTarget($target, $this->keepRange)){
			return false;
		}
		if($this->angerTicks > 0){
			$tick = $this->mob->getLastAttackedTick();
			if($tick !== $this->handledAttackTick && $this->mob->getLastAttacker() === $target){
				//hit again by the target: angry for longer
				$this->handledAttackTick = $tick;
				$this->angerEndTick = $tick + $this->angerTicks;
			}
			return $this->mob->getAiTick() < $this->angerEndTick;
		}
		return true;
	}

	public function start() : void{
		$this->handledAttackTick = $this->mob->getLastAttackedTick();
		$this->keepRange = $this->getKeepRange();
		$this->angerEndTick = $this->mob->getAiTick() + $this->angerTicks;
		$this->mob->setAttackTarget($this->target);
		if(!$this->alerted && $this->alertRange > 0 && $this->target !== null){
			$this->alertOthers($this->target);
		}
	}

	/**
	 * Alerts the mobs of the group around the mob that have no target (one search per hit).
	 */
	private function alertOthers(Living $attacker) : void{
		$loc = $this->mob->getLiveLocation();
		$r = $this->alertRange;
		$type = $this->mob::getNetworkTypeId();
		foreach($this->mob->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - $r, $loc->z - $r, $loc->x + $r, $loc->y + $r, $loc->z + $r), $this->mob) as $entity){
			if(!$entity instanceof Mob || $entity === $attacker || !$entity->isAlive() || $entity->getAttackTarget() !== null ||
				(Mob::getLiveLocationOf($entity) ?? $entity->getLocation())->distanceSquared($loc) > $r * $r
			){
				continue;
			}
			if($this->alertFilter !== null ? ($this->alertFilter)($entity) : $entity::getNetworkTypeId() === $type){
				$entity->alertTo($attacker);
			}
		}
	}

	public function stop() : void{
		if($this->mob->getAttackTarget() === $this->target){
			$this->mob->setAttackTarget(null);
		}
		$this->target = null;
	}
}
