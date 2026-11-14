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

use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\world\particle\HugeExplodeParticle;
use function sqrt;

/**
 * Shulker bullet (minecraft:shulker_bullet): homes in on its target, deals 4 damage and gives 10 seconds of
 * Levitation (vanilla shulker_bullet.json). Hitting it destroys it. Without a target it falls.
 */
class ShulkerBullet extends Throwable implements NeverSavedWithChunkEntity{
	public const DAMAGE = 4;
	public const LEVITATION_TICKS = 200;
	public const SPEED = 0.35;
	private const MAX_LIFETIME_TICKS = 400;

	private ?int $homingTargetId = null;

	public static function getNetworkTypeId() : string{ return EntityIds::SHULKER_BULLET; }

	public function __construct(Location $location, ?Entity $shootingEntity, ?Entity $target, ?CompoundTag $nbt = null){
		parent::__construct($location, $shootingEntity, $nbt);
		$this->homingTargetId = $target?->getId();
		$this->setBaseDamage(self::DAMAGE);
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.3125, 0.3125); }

	protected function getInitialDragMultiplier() : float{ return 0.0; }

	protected function getInitialGravity() : float{ return 0.05; }

	public function getHomingTarget() : ?Entity{
		if($this->homingTargetId === null){
			return null;
		}
		$target = $this->getWorld()->getEntity($this->homingTargetId);
		return $target !== null && $target->isAlive() ? $target : null;
	}

	protected function tryChangeMovement() : void{
		$target = $this->getHomingTarget();
		if($target === null){
			$this->setHasGravity(true);
			parent::tryChangeMovement();
			return;
		}
		$this->setHasGravity(false);
		$tLoc = $target->getLocation();
		$dx = $tLoc->x - $this->location->x;
		$dy = $tLoc->y + $target->getSize()->getHeight() * 0.5 - $this->location->y;
		$dz = $tLoc->z - $this->location->z;
		$len = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
		if($len < 0.0001){
			return;
		}
		$wanted = new Vector3($dx / $len * self::SPEED, $dy / $len * self::SPEED, $dz / $len * self::SPEED);
		//turn smoothly, like the vanilla bullet zig-zagging after its target
		$this->motion = $this->motion->addVector($wanted->subtractVector($this->motion)->multiply(0.2));
	}

	public function hasMovementUpdate() : bool{
		return !$this->isFlaggedForDespawn();
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->ticksLived >= self::MAX_LIFETIME_TICKS && !$this->isFlaggedForDespawn()){
			$this->flagForDespawn();
		}
		return $hasUpdate;
	}

	public function attack(EntityDamageEvent $source) : void{
		if($source instanceof EntityDamageByEntityEvent){
			//destroy_on_hurt
			$source->cancel();
			$this->getWorld()->addParticle($this->location, new HugeExplodeParticle());
			$this->flagForDespawn();
			return;
		}
		parent::attack($source);
	}

	protected function onHitEntity(Entity $entityHit, RayTraceResult $hitResult) : void{
		parent::onHitEntity($entityHit, $hitResult);
		if($entityHit instanceof Living && $entityHit->isAlive()){
			$entityHit->getEffects()->add(new EffectInstance(VanillaEffects::LEVITATION(), self::LEVITATION_TICKS));
		}
		$this->getWorld()->addParticle($this->location, new HugeExplodeParticle());
	}
}
