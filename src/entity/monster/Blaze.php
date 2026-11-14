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
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\NearestAttackableTargetGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\hostile\Aim;
use pocketmine\entity\ai\hostile\goal\BlazeAttackGoal;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\projectile\SmallFireball;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use function max;
use function mt_rand;
use function sqrt;

/**
 * Bedrock values (blaze.json): 20 health, 0.5x1.8, movement 0.23, follow range 48, fire immune, no fall damage, hurt by
 * water and rain. Melee 6 within 2 blocks, otherwise bursts of three small fireballs (see {@link BlazeAttackGoal}). Falls
 * slowly and rises towards targets above it (Java Blaze).
 * Loot: 0-1 blaze rod (+0-1 per Looting level) when killed by a player. Experience 10.
 */
class Blaze extends Monster{
	public const MELEE_DAMAGE = 6.0;
	public const FOLLOW_RANGE = 48.0;
	public const FIREBALL_SPEED = 1.3;

	private bool $charged = false;

	public static function getNetworkTypeId() : string{ return EntityIds::BLAZE; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.8, 0.5, 1.5);
	}

	protected function getFreezeDamage() : float{
		return 5.0; //vanilla freeze_hurts_extra_types
	}

	public function getName() : string{
		return "Blaze";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(20);
		$this->setMovementSpeed(0.23);
		$this->setFollowRange(self::FOLLOW_RANGE);
		parent::initEntity($nbt);
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(3, new BlazeAttackGoal($this));
		$this->goalSelector->addGoal(4, new RandomStrollGoal($this, 1.0));
		$this->goalSelector->addGoal(5, new LookAtPlayerGoal($this, 8.0));
		$this->goalSelector->addGoal(6, new RandomLookAroundGoal($this));

		$this->targetSelector->addGoal(1, new HurtByTargetGoal($this, self::FOLLOW_RANGE));
		$this->targetSelector->addGoal(2, new NearestAttackableTargetGoal($this, self::FOLLOW_RANGE, true, 10));
	}

	public function isCharged() : bool{
		return $this->charged;
	}

	public function setCharged(bool $charged) : void{
		if($this->charged !== $charged){
			$this->charged = $charged;
			$this->networkPropertiesDirty = true;
		}
	}

	/**
	 * Shoots a small fireball at the target, spread more the farther it is (Java: sqrt(sqrt(distance)) * 0.5).
	 */
	public function shootFireball(Living $target, float $distSq) : void{
		$world = $this->getWorld();
		$origin = new Vector3($this->location->x, $this->location->y + $this->size->getHeight() / 2 + 0.5, $this->location->z);
		//Java adds gaussian * sqrt(distance) * 0.5 to the unnormalized direction: 0.5 / sqrt(distance) once normalized
		$spread = 0.5 / max(1.0, sqrt(sqrt($distSq)));
		$motion = Aim::motionTowards($origin, Aim::targetPoint($target, 0.5), self::FIREBALL_SPEED, $spread);
		if($motion === null){
			return;
		}
		$fireball = new SmallFireball(Aim::facing($origin, $world, $motion), $this);
		$fireball->setMotion($motion);
		$fireball->spawnToAll();
		$this->playMobSound(LevelSoundEvent::SHOOT);
	}

	protected function tryChangeMovement() : void{
		parent::tryChangeMovement();
		if(!$this->isAlive()){
			return;
		}
		$my = $this->motion->y;
		if(!$this->onGround && $my < 0){
			$my *= 0.6;
		}
		$target = $this->getAttackTarget();
		if($target !== null && $target->getEyePos()->y > $this->getEyePos()->y + 1.0){
			//rise towards targets above
			$my += (0.3 - $my) * 0.3;
		}
		if($my !== $this->motion->y){
			$this->motion = $this->motion->withComponents(null, $my, null);
		}
	}

	public function hasMovementUpdate() : bool{
		return parent::hasMovementUpdate() || ($this->isAlive() && $this->attackTarget !== null);
	}

	protected function calculateFallDamage(float $fallDistance) : float{
		return 0;
	}

	public function isFireProof() : bool{
		return true;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		//Mob::entityBaseTick() set the AI tick to this tick's (the server tick) unless the blaze was closed or dead by then
		$tick = $this->closed ? $this->server->getTick() : $this->getAiTick();
		if($this->isAlive() && ($tick + $this->id) % 10 === 0 && $this->isInContactWithWater()){
			//hurt_on_condition: in contact with water (rain included)
			$this->attack(new EntityDamageEvent($this, EntityDamageEvent::CAUSE_DROWNING, 1));
		}
		return $hasUpdate;
	}

	public function getDrops() : array{
		if(!$this->wasRecentlyHitByPlayer()){
			return [];
		}
		return [VanillaItems::BLAZE_ROD()->setCount(mt_rand(0, 1) + $this->lootingBonus())];
	}

	public function getXpDropAmount() : int{
		return $this->wasRecentlyHitByPlayer() ? 10 : 0;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::BLAZE_SPAWN_EGG();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::CHARGED, $this->charged);
	}
}
