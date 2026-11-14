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
use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\event\entity\EntityCombustByEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\RayTraceResult;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\world\GameRules;

/**
 * Small fireball, as shot by blazes and by dispensers firing a fire charge.
 *
 * Vanilla (small_fireball.json, https://minecraft.wiki/w/Fire_Charge): flies at a constant speed with no gravity or
 * drag, deals 5 fire damage and sets the entity hit on fire for 5 seconds (fire immune mobs take neither, so blazes
 * never fight over stray fireballs), or places fire on the face of the block hit unless mobGriefing is off. Hitting
 * it sends it back (reflect_on_hurt).
 */
class SmallFireball extends Throwable{
	private const MAX_LIFETIME_TICKS = 200;
	public const FIRE_SECONDS = 5;

	public static function getNetworkTypeId() : string{ return EntityIds::SMALL_FIREBALL; }

	public function __construct(Location $location, ?Entity $shootingEntity, ?CompoundTag $nbt = null){
		parent::__construct($location, $shootingEntity, $nbt);
		$this->setBaseDamage(5);
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.3125, 0.3125); }

	protected function getInitialDragMultiplier() : float{ return 0.0; }

	protected function getInitialGravity() : float{ return 0.0; }

	protected function getLiquidInertia() : float{ return 1.0; }

	public function isFireProof() : bool{
		return true;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->ticksLived >= self::MAX_LIFETIME_TICKS && !$this->isFlaggedForDespawn()){
			$this->flagForDespawn();
		}
		return $hasUpdate;
	}

	/**
	 * Sends the fireball back where the attacker is looking; the attacker becomes its owner.
	 */
	public function deflect(Entity $by) : void{
		$speed = $this->motion->length();
		$this->setMotion($by->getDirectionVector()->multiply($speed > 0.1 ? $speed : 1.0));
		$this->setOwningEntity($by);
	}

	public function attack(EntityDamageEvent $source) : void{
		if($source instanceof EntityDamageByEntityEvent && !$this->isFlaggedForDespawn()){
			$damager = $source->getDamager();
			if($damager instanceof Living){
				$source->cancel();
				$this->deflect($damager);
				return;
			}
		}
		parent::attack($source);
	}

	protected function onHitEntity(Entity $entityHit, RayTraceResult $hitResult) : void{
		if($entityHit->isFireProof()){
			//vanilla: the impact is fire damage, which fire immune mobs ignore
			$this->flagForDespawn();
			return;
		}
		$ev = new EntityCombustByEntityEvent($this, $entityHit, self::FIRE_SECONDS);
		$ev->call();
		if(!$ev->isCancelled()){
			$entityHit->setOnFire($ev->getDuration());
		}
		parent::onHitEntity($entityHit, $hitResult);
	}

	protected function onHitBlock(Block $blockHit, RayTraceResult $hitResult) : void{
		parent::onHitBlock($blockHit, $hitResult);
		$world = $this->getWorld();
		$firePos = $blockHit->getPosition()->getSide($hitResult->getHitFace());
		if(
			//fire_affected_by_griefing (Java: only when a mob shot it; dispensers and players always set fire)
			(!$this->getOwningEntity() instanceof Mob || GameRules::allowsMobGriefing($world)) &&
			$world->isInWorld($firePos->getFloorX(), $firePos->getFloorY(), $firePos->getFloorZ()) &&
			$world->getBlock($firePos)->getTypeId() === BlockTypeIds::AIR
		){
			$world->setBlock($firePos, VanillaBlocks::FIRE());
		}
		$this->flagForDespawn();
	}
}
