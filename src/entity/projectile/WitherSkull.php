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
use pocketmine\entity\boss\Wither;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityPreExplodeEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\math\RayTraceResult;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\world\Explosion;
use pocketmine\world\Position;
use pocketmine\world\World;

/**
 * Skull shot by the wither (Bedrock behavior_pack/entities/wither_skull.json): flies straight without gravity, hits for
 * 5/8/12 damage (easy/normal/hard), gives Wither II for 10 s on normal and 40 s on hard, and explodes with power 1.
 * The blue "dangerous" skull ({@link DangerousWitherSkull}) is slower.
 */
class WitherSkull extends Projectile{
	public const SPEED = 1.2;
	public const EXPLOSION_POWER = 1.0;
	/** Health the wither regains when one of its skulls kills (Java WitherSkull.onHitEntity). */
	public const KILL_HEAL = 5.0;
	private const MAX_LIFETIME_TICKS = 200;

	public static function getNetworkTypeId() : string{ return EntityIds::WITHER_SKULL; }

	public function __construct(Location $location, ?Entity $shootingEntity, ?CompoundTag $nbt = null){
		parent::__construct($location, $shootingEntity, $nbt);
		$this->setBaseDamage(self::damageForDifficulty($location->getWorld()->getDifficulty()));
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.3125, 0.3125); }

	protected function getInitialDragMultiplier() : float{ return 0.0; }

	protected function getInitialGravity() : float{ return 0.0; }

	protected function getLiquidInertia() : float{ return 1.0; }

	public function isFireProof() : bool{
		return true;
	}

	public function isDangerous() : bool{
		return false;
	}

	/**
	 * Cap on the blast resistance of the blocks the explosion breaks (vanilla explode max_resistance), null for none.
	 */
	public function getMaxBlastResistance() : ?float{
		return null;
	}

	public static function damageForDifficulty(int $difficulty) : float{
		return match($difficulty){
			World::DIFFICULTY_PEACEFUL => 0.0,
			World::DIFFICULTY_EASY => 5.0,
			World::DIFFICULTY_HARD => 12.0,
			default => 8.0
		};
	}

	/**
	 * Duration of the Wither II effect given on a hit: none on easy, 10 s on normal, 40 s on hard.
	 */
	public static function witherDurationForDifficulty(int $difficulty) : int{
		return match($difficulty){
			World::DIFFICULTY_NORMAL => 200,
			World::DIFFICULTY_HARD => 800,
			default => 0
		};
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->ticksLived >= self::MAX_LIFETIME_TICKS && !$this->isFlaggedForDespawn()){
			$this->flagForDespawn();
		}
		return $hasUpdate;
	}

	public function canCollideWith(Entity $entity) : bool{
		return parent::canCollideWith($entity) && $entity->getId() !== $this->getOwningEntityId();
	}

	protected function onHitEntity(Entity $entityHit, RayTraceResult $hitResult) : void{
		$wasAlive = $entityHit->isAlive();
		parent::onHitEntity($entityHit, $hitResult);
		if($entityHit instanceof Living && $entityHit->isAlive()){
			$duration = self::witherDurationForDifficulty($this->getWorld()->getDifficulty());
			if($duration > 0){
				$entityHit->getEffects()->add(new EffectInstance(VanillaEffects::WITHER(), $duration, 1));
			}
		}elseif($wasAlive && $entityHit instanceof Living){
			//vanilla: the wither heals when one of its skulls kills
			$owner = $this->getOwningEntity();
			if($owner instanceof Wither && $owner->isAlive()){
				$owner->heal(new EntityRegainHealthEvent($owner, self::KILL_HEAL, EntityRegainHealthEvent::CAUSE_MAGIC));
			}
		}
		$this->explode();
	}

	protected function onHitBlock(Block $blockHit, RayTraceResult $hitResult) : void{
		parent::onHitBlock($blockHit, $hitResult);
		$this->explode();
	}

	public function explode() : void{
		if($this->isFlaggedForDespawn()){
			return;
		}
		$this->flagForDespawn();
		$ev = new EntityPreExplodeEvent($this, self::EXPLOSION_POWER);
		$ev->call();
		if($ev->isCancelled()){
			return;
		}
		$explosion = new Explosion(Position::fromObject($this->location, $this->getWorld()), $ev->getRadius(), $this, $ev->getFireChance());
		$explosion->setMaxBlastResistance($this->getMaxBlastResistance());
		if($ev->isBlockBreaking()){
			$explosion->explodeA();
		}
		$explosion->explodeB();
	}
}
