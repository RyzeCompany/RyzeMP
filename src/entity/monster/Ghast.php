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

use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\NearestAttackableTargetGoal;
use pocketmine\entity\ai\hostile\goal\FreeWanderGoal;
use pocketmine\entity\ai\hostile\goal\GhastShootGoal;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\projectile\LargeFireball;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use function mt_rand;

/**
 * Bedrock values (ghast.json): 10 health, 4x4, flies, fire immune, no fall damage, targets players within 28 blocks
 * and shoots fireballs at them from up to 64 blocks (see {@link GhastShootGoal} and {@link LargeFireball}). A fireball
 * deflected back by a player kills it.
 * Loot: 0-1 ghast tear, 0-2 gunpowder (+0-1 each per Looting level), and the "Tears" music disc when killed by a
 * player with a fireball. Experience 5.
 */
class Ghast extends FlyingMonster{
	public const FLY_SPEED = 0.15;

	private bool $charging = false;
	/** Set while taking the hit of a deflected fireball, whose fire damage a ghast doesn't ignore. */
	private bool $hitByDeflectedFireball = false;

	public static function getNetworkTypeId() : string{ return EntityIds::GHAST; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(4.0, 4.0, 2.6);
	}

	public function getName() : string{
		return "Ghast";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(10);
		$this->setMovementSpeed(0.03);
		$this->setFollowRange(64);
		parent::initEntity($nbt);
	}

	protected function getFlightAcceleration() : float{
		return 0.05;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(1, new GhastShootGoal($this));
		$this->goalSelector->addGoal(2, new FreeWanderGoal($this, $this->flight, self::FLY_SPEED, 30, 16, 8));

		$this->targetSelector->addGoal(1, new HurtByTargetGoal($this, 64.0));
		$this->targetSelector->addGoal(2, new NearestAttackableTargetGoal($this, 28.0, true, 10));
	}

	public function isFireProof() : bool{
		return !$this->hitByDeflectedFireball;
	}

	public function isCharging() : bool{
		return $this->charging;
	}

	public function setCharging(bool $charging) : void{
		if($this->charging !== $charging){
			$this->charging = $charging;
			$this->networkPropertiesDirty = true;
		}
	}

	public function warn() : void{
		$this->playMobSound(LevelSoundEvent::WARN);
	}

	/**
	 * Shoots a fireball at the target from 4 blocks in front of the ghast's face (Java). Returns the fireball.
	 */
	public function shootFireball(Living $target) : LargeFireball{
		$world = $this->getWorld();
		$view = $this->getDirectionVector();
		$origin = new Vector3(
			$this->location->x + $view->x * 4,
			$this->location->y + $this->size->getHeight() / 2 + 0.5,
			$this->location->z + $view->z * 4
		);
		$tLoc = $target->getLocation();
		$direction = new Vector3($tLoc->x - $origin->x, $tLoc->y + $target->getSize()->getHeight() / 2 - $origin->y, $tLoc->z - $origin->z);
		$fireball = new LargeFireball(Location::fromObject($origin, $world, $this->location->yaw, $this->location->pitch), $this, $direction);
		$fireball->setMotion($direction->normalize()->multiply(LargeFireball::SPEED));
		$fireball->spawnToAll();
		$this->playMobSound(LevelSoundEvent::SHOOT);
		return $fireball;
	}

	/**
	 * Whether the damage is a fireball sent back by a player.
	 */
	public static function isDeflectedFireball(EntityDamageEvent $source) : bool{
		return $source instanceof EntityDamageByChildEntityEvent && $source->getChild() instanceof LargeFireball && $source->getDamager() instanceof Player;
	}

	public function attack(EntityDamageEvent $source) : void{
		if($source instanceof EntityDamageByChildEntityEvent && ($fireball = $source->getChild()) instanceof LargeFireball && $fireball->isDeflected()){
			if(self::isDeflectedFireball($source)){
				//vanilla: a ghast hit by its own fireball sent back by a player dies
				$source->setBaseDamage(1000);
			}
			//the fire damage of a fireball hit back at it is not ignored
			$this->hitByDeflectedFireball = true;
			try{
				parent::attack($source);
			}finally{
				$this->hitByDeflectedFireball = false;
			}
			return;
		}
		parent::attack($source);
	}

	public function getDrops() : array{
		$drops = [
			VanillaItems::GHAST_TEAR()->setCount(mt_rand(0, 1) + $this->lootingBonus()),
			VanillaItems::GUNPOWDER()->setCount(mt_rand(0, 2) + $this->lootingBonus())
		];
		$cause = $this->lastDamageCause;
		if($cause !== null && self::isDeflectedFireball($cause)){
			$drops[] = VanillaItems::RECORD_TEARS();
		}
		return $drops;
	}

	public function getXpDropAmount() : int{
		return $this->wasRecentlyHitByPlayer() ? 5 : 0;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::GHAST_SPAWN_EGG();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::CHARGED, $this->charging);
	}
}
