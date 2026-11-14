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

namespace pocketmine\entity\projectile;

use pocketmine\block\Block;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\object\EndCrystal;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\ProjectileHitBlockEvent;
use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\math\Facing;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\world\particle\WindExplosionParticle;
use pocketmine\world\sound\WindChargeBurstSound;
use pocketmine\world\WindBurst;
use function max;

/**
 * Wind charge thrown by a player. Flies straight (no gravity), deals 1 damage on a direct hit and bursts on impact,
 * pushing every nearby entity away, the thrower included, and operating doors, trapdoors, fence gates, buttons,
 * levers, bells and candles, without damaging blocks. Entities launched by the burst only take fall damage for the
 * distance they fall below it.
 *
 * Vanilla (wind_charge_projectile.json): explode power 1.2, knockback_scaling 1.22, negates_fall_damage,
 * toggles_blocks, particle wind_burst and sound wind_charge.burst; end crystals and other wind charges are ignored
 * (ignored_entities), and hitting the charge sends it back (reflect_on_hurt), except within 0.5 s of being thrown or
 * reflected (reflect_immunity). A charge hitting a block bursts a quarter block in front of the face (Java
 * AbstractWindCharge.onHitBlock()).
 */
class WindCharge extends Throwable{
	public const BURST_RADIUS = 1.2;
	public const BURST_KNOCKBACK_MULTIPLIER = 1.22;
	/** Wind charges don't fall, so make sure a stray one doesn't fly forever. */
	private const MAX_LIFETIME_TICKS = 200;
	/** reflect_immunity 0.5 s */
	private const REFLECT_IMMUNITY_TICKS = 10;
	/** Java AbstractWindCharge.onHitBlock(): the burst happens this far in front of the block face. */
	private const BLOCK_HIT_OFFSET = 0.25;

	public static function getNetworkTypeId() : string{ return EntityIds::WIND_CHARGE_PROJECTILE; }

	protected bool $burst = false;
	/** Value of ticksLived until which the charge can't be reflected. */
	private int $reflectImmuneUntil;

	public function __construct(Location $location, ?Entity $shootingEntity, ?CompoundTag $nbt = null){
		parent::__construct($location, $shootingEntity, $nbt);
		$this->setBaseDamage(1);
		$this->reflectImmuneUntil = $this->getReflectImmunityTicks();
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.3125, 0.3125); }

	protected function getInitialDragMultiplier() : float{ return 0.0; }

	protected function getInitialGravity() : float{ return 0.0; }

	protected function getLiquidInertia() : float{ return 1.0; }

	/**
	 * Ticks during which the charge can't be reflected after being thrown or reflected (reflect_immunity).
	 */
	protected function getReflectImmunityTicks() : int{
		return self::REFLECT_IMMUNITY_TICKS;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->ticksLived >= self::MAX_LIFETIME_TICKS && !$this->isFlaggedForDespawn()){
			$this->flagForDespawn();
		}
		return $hasUpdate;
	}

	public function canCollideWith(Entity $entity) : bool{
		//ignored_entities: ender_crystal (and the other wind charges, which are not living anyway)
		return !$entity instanceof EndCrystal && parent::canCollideWith($entity);
	}

	public function attack(EntityDamageEvent $source) : void{
		if($source instanceof EntityDamageByEntityEvent && ($damager = $source->getDamager()) instanceof Living){
			$source->cancel();
			if($this->ticksLived >= $this->reflectImmuneUntil && !$this->burst){
				$this->reflect($damager);
			}
			return;
		}
		parent::attack($source);
	}

	/**
	 * Sends the charge where the entity is looking, at its current speed (at least 0.7), and makes it the owner
	 * (reflect_on_hurt).
	 */
	protected function reflect(Living $by) : void{
		$this->setMotion($by->getDirectionVector()->multiply(max(0.7, $this->motion->length())));
		$this->setOwningEntity($by);
		$this->reflectImmuneUntil = $this->ticksLived + $this->getReflectImmunityTicks();
	}

	/**
	 * Returns where the charge bursts for the given hit.
	 */
	protected function getBurstCenter(ProjectileHitEvent $event) : Vector3{
		$result = $event->getRayTraceResult();
		$center = $result->getHitVector();
		if($event instanceof ProjectileHitBlockEvent){
			[$x, $y, $z] = Facing::OFFSET[$result->getHitFace()] ?? [0, 0, 0];
			$center = $center->add($x * self::BLOCK_HIT_OFFSET, $y * self::BLOCK_HIT_OFFSET, $z * self::BLOCK_HIT_OFFSET);
		}
		return $center;
	}

	protected function onHit(ProjectileHitEvent $event) : void{
		if($this->burst){
			return;
		}
		$this->burst = true;
		WindBurst::apply(
			$this->getWorld(),
			$this->getBurstCenter($event),
			self::BURST_RADIUS,
			self::BURST_KNOCKBACK_MULTIPLIER,
			true,
			null,
			new WindChargeBurstSound(),
			new WindExplosionParticle(),
			true
		);
	}

	protected function onHitBlock(Block $blockHit, RayTraceResult $hitResult) : void{
		parent::onHitBlock($blockHit, $hitResult);
		$this->flagForDespawn();
	}
}
