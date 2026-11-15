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
use pocketmine\entity\monster\Ghast;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityPreExplodeEvent;
use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\world\Explosion;
use pocketmine\world\Position;

/**
 * Ghast fireball (minecraft:fireball). Flies in a straight line at a constant speed (Bedrock fireball.json: power 1.6,
 * no gravity, inertia 1) and explodes on impact (power 1, sets fires, 6 fire damage to the entity hit, which fire
 * immune mobs ignore - except a ghast hit by a deflected fireball).
 *
 * Hitting it (melee or a projectile) deflects it: it flies where the attacker is looking and the attacker becomes its
 * owner, so a deflected fireball that hits a ghast kills it (see {@link \pocketmine\entity\monster\Ghast}).
 */
class LargeFireball extends Throwable implements NeverSavedWithChunkEntity{
	public const EXPLOSION_POWER = 1.0;
	public const IMPACT_DAMAGE = 6;
	/** Launch speed in blocks per tick (fireball.json projectile power), kept for the whole flight. */
	public const SPEED = 1.6;
	/** Heading length sent to clients as the fireball power metadata. */
	public const ACCELERATION = 0.1;
	private const MAX_LIFETIME_TICKS = 600;

	private Vector3 $acceleration;
	private bool $exploded = false;
	private bool $deflected = false;

	public static function getNetworkTypeId() : string{ return EntityIds::FIREBALL; }

	public function __construct(Location $location, ?Entity $shootingEntity, Vector3 $direction, ?CompoundTag $nbt = null){
		$this->acceleration = self::headingAcceleration($direction);
		parent::__construct($location, $shootingEntity, $nbt);
		$this->setBaseDamage(self::IMPACT_DAMAGE);
	}

	private static function headingAcceleration(Vector3 $direction) : Vector3{
		$len = $direction->length();
		return $len > 0 ? $direction->multiply(self::ACCELERATION / $len) : Vector3::zero();
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(1.0, 1.0); }

	protected function getInitialDragMultiplier() : float{ return 0.0; }

	protected function getInitialGravity() : float{ return 0.0; }

	protected function getLiquidInertia() : float{ return 1.0; }

	public function isFireProof() : bool{
		return true;
	}

	public function getAcceleration() : Vector3{
		return $this->acceleration;
	}

	/**
	 * Whether a player or mob hit the fireball back.
	 */
	public function isDeflected() : bool{
		return $this->deflected;
	}

	protected function tryChangeMovement() : void{
		//inertia 1, no gravity: the motion never changes
	}

	public function hasMovementUpdate() : bool{
		return !$this->exploded && !$this->isFlaggedForDespawn();
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->ticksLived >= self::MAX_LIFETIME_TICKS && !$this->isFlaggedForDespawn()){
			$this->flagForDespawn();
		}
		return $hasUpdate;
	}

	/**
	 * Sends the fireball back where the attacker is looking (vanilla reflect_on_hurt).
	 */
	public function deflect(Entity $by) : void{
		$direction = $by->getDirectionVector();
		$this->acceleration = self::headingAcceleration($direction);
		$speed = $this->motion->length();
		$this->setMotion($direction->multiply($speed > 0.5 ? $speed : self::SPEED));
		$this->setOwningEntity($by);
		$this->deflected = true;
	}

	public function attack(EntityDamageEvent $source) : void{
		if($source instanceof EntityDamageByEntityEvent && !$this->exploded){
			$damager = $source->getDamager();
			if($damager instanceof Living){
				$source->cancel();
				$this->deflect($damager);
				return;
			}
		}
		parent::attack($source);
	}

	protected function onHit(ProjectileHitEvent $event) : void{
		$this->explode();
	}

	protected function onHitEntity(Entity $entityHit, RayTraceResult $hitResult) : void{
		if($entityHit->isFireProof() && !($this->deflected && $entityHit instanceof Ghast)){
			//vanilla: the impact is fire damage, which fire immune mobs ignore (the explosion still hurts them)
			$this->flagForDespawn();
			return;
		}
		parent::onHitEntity($entityHit, $hitResult);
	}

	protected function onHitBlock(Block $blockHit, RayTraceResult $hitResult) : void{
		parent::onHitBlock($blockHit, $hitResult);
		$this->flagForDespawn();
	}

	public function explode() : void{
		if($this->exploded){
			return;
		}
		$this->exploded = true;
		$this->flagForDespawn();
		$ev = new EntityPreExplodeEvent($this, self::EXPLOSION_POWER);
		$ev->setIncendiary(true);
		$ev->call();
		if($ev->isCancelled()){
			return;
		}
		$explosion = new Explosion(Position::fromObject($this->location, $this->getWorld()), $ev->getRadius(), $this, $ev->getFireChance());
		if($ev->isBlockBreaking()){
			$explosion->explodeA();
		}
		$explosion->explodeB();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setFloat(EntityMetadataProperties::FIREBALL_POWER_X, $this->acceleration->x);
		$properties->setFloat(EntityMetadataProperties::FIREBALL_POWER_Y, $this->acceleration->y);
		$properties->setFloat(EntityMetadataProperties::FIREBALL_POWER_Z, $this->acceleration->z);
	}
}
