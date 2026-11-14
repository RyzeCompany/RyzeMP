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
use pocketmine\entity\ai\goal\NearestAttackableEntityGoal;
use pocketmine\entity\ai\goal\NearestAttackableTargetGoal;
use pocketmine\entity\ai\hostile\goal\FreeMeleeAttackGoal;
use pocketmine\entity\ai\hostile\goal\FreeWanderGoal;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\npc\WanderingTrader;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\player\Player;
use function intdiv;
use function mt_rand;

/**
 * Bedrock values (vex.json): 14 health, 0.4x0.8, attack 3, iron sword, fire immune, flies through blocks. Charges at
 * its target (players, iron golems, wandering traders and adult villagers within 70 blocks, or its evoker's target),
 * otherwise floats around its evoker. Vexes summoned by an evoker start taking 1 damage per second after
 * 30-119 seconds (minecraft:damage_over_time).
 */
class Vex extends FlyingMonster{
	use HeldItemsTrait;

	public const TAG_LIFE_TICKS = "LifeTicks"; //TAG_Int (PocketMine)

	public const ATTACK_DAMAGE = 3.0;
	public const SPEED = 0.25;
	public const TARGET_RANGE = 70.0;

	/** Ticks before the vex starts hurting itself, -1 for vexes that live forever. */
	private int $lifeTicks = -1;
	private FreeMeleeAttackGoal $chargeGoal;

	/**
	 * Last mob target search that found nothing, per evoker: server tick and position of the vex that searched.
	 * @phpstan-var \WeakMap<Evoker, array{int, float|int, float|int, float|int}>|null
	 */
	private static ?\WeakMap $missedSearches = null;

	public static function getNetworkTypeId() : string{ return EntityIds::VEX; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.8, 0.4, 0.6);
	}

	public function getName() : string{
		return "Vex";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(14);
		$this->setMovementSpeed(1.0);
		parent::initEntity($nbt);
		$this->keepMovement = true; //no collisions: vexes fly through blocks
		$this->lifeTicks = $nbt->getInt(self::TAG_LIFE_TICKS, -1);
		$this->loadHeldItems($nbt);
		if($nbt->getListTag("Mainhand") === null){
			$this->setMainHandItem(VanillaItems::IRON_SWORD());
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_LIFE_TICKS, $this->lifeTicks);
		$this->saveHeldItems($nbt);
		return $nbt;
	}

	protected function getFlightAcceleration() : float{
		return 0.2;
	}

	protected function registerGoals() : void{
		$this->chargeGoal = new FreeMeleeAttackGoal($this, $this->flight, self::SPEED * 1.6, self::ATTACK_DAMAGE, 0.0, 20, 70.0);
		$this->goalSelector->addGoal(4, $this->chargeGoal);
		$this->goalSelector->addGoal(8, new FreeWanderGoal($this, $this->flight, self::SPEED, 21, 7, 3, FreeWanderGoal::MEDIUM_ANY, $this->getWanderCentre(...)));

		$this->targetSelector->addGoal(1, new HurtByTargetGoal($this));
		//players, iron golems, wandering traders and adult villagers within 70 blocks (players from the world's player list,
		//the others only looked for while the world has some, the vex has no target and its evoker none to share)
		$this->targetSelector->addGoal(3, new NearestAttackableTargetGoal($this, self::TARGET_RANGE, true, 10));
		//the search interval (10 ticks on average) is drawn here, so that the vexes of one evoker can share a search that
		//found nothing: they fly next to each other, and the 70-block box spans about 100 chunks
		$mobTargets = new class($this, fn(Living $e) => !$e instanceof Player && self::isVexTarget($e), self::TARGET_RANGE, true, Mob::GOAL_EVALUATION_INTERVAL, $this->canSearchMobTargets(...), verticalRange: self::TARGET_RANGE, candidateClasses: [IronGolem::class, WanderingTrader::class, VillagerV2::class]) extends NearestAttackableEntityGoal{
			/** @phpstan-var (\Closure() : bool)|null */
			public ?\Closure $hasSharedMiss = null;
			/** @phpstan-var (\Closure() : void)|null */
			public ?\Closure $shareMiss = null;

			public function canStart() : bool{
				//(the condition first: no random number drawn while there is nothing to look for)
				if($this->condition === null || !($this->condition)() || mt_rand(0, intdiv(10, Mob::GOAL_EVALUATION_INTERVAL) - 1) !== 0){
					return false;
				}
				if($this->hasSharedMiss !== null && ($this->hasSharedMiss)()){
					return false;
				}
				if(parent::canStart()){
					return true;
				}
				if($this->shareMiss !== null){
					($this->shareMiss)();
				}
				return false;
			}
		};
		$mobTargets->hasSharedMiss = $this->hasSharedMissedSearch(...);
		$mobTargets->shareMiss = $this->shareMissedSearch(...);
		$this->targetSelector->addGoal(3, $mobTargets);
	}

	/**
	 * Whether another vex of the same evoker, close to this one, looked for mob targets in the last 10 ticks and found
	 * none (it would find what this one finds, but for a few blocks at the edges of the range).
	 */
	private function hasSharedMissedSearch() : bool{
		$owner = $this->getOwningEntity();
		if(!$owner instanceof Evoker || self::$missedSearches === null || !isset(self::$missedSearches[$owner])){
			return false;
		}
		[$tick, $x, $y, $z] = self::$missedSearches[$owner];
		$loc = $this->getLiveLocation();
		return $this->server->getTick() - $tick < 10 && ($loc->x - $x) ** 2 + ($loc->y - $y) ** 2 + ($loc->z - $z) ** 2 < 16;
	}

	private function shareMissedSearch() : void{
		$owner = $this->getOwningEntity();
		if($owner instanceof Evoker){
			$loc = $this->getLiveLocation();
			self::$missedSearches ??= new \WeakMap();
			self::$missedSearches[$owner] = [$this->server->getTick(), $loc->x, $loc->y, $loc->z];
		}
	}

	private function canSearchMobTargets() : bool{
		$world = $this->getWorld();
		if(!$world->hasEntitiesOfClass(VillagerV2::class) && !$world->hasEntitiesOfClass(IronGolem::class) && !$world->hasEntitiesOfClass(WanderingTrader::class)){
			return false;
		}
		if($this->getAttackTarget() !== null){
			return false;
		}
		//the evoker's target is taken over at the end of this tick
		$owner = $this->getOwningEntity();
		return !$owner instanceof Evoker || $owner->getAttackTarget() === null;
	}

	/**
	 * Whether a vex attacks the entity on its own (vex.json nearest_attackable_target).
	 */
	public static function isVexTarget(Living $entity) : bool{
		return $entity instanceof Player || $entity instanceof IronGolem || $entity instanceof WanderingTrader || ($entity instanceof VillagerV2 && !$entity->isBaby());
	}

	/**
	 * Vexes stay around their evoker (vanilla: around the evoker's position when summoned).
	 */
	private function getWanderCentre() : ?Vector3{
		$owner = $this->getOwningEntity();
		return $owner !== null && $owner->isAlive() ? $owner->getPosition()->add(0, 1, 0) : null;
	}

	/**
	 * Makes the vex start hurting itself after 30-119 seconds, like vexes summoned by an evoker.
	 */
	public function setLimitedLife(bool $limited) : void{
		$this->lifeTicks = $limited ? mt_rand(600, 2380) : -1;
	}

	public function isCharging() : bool{
		return $this->goalSelector->isRunning($this->chargeGoal);
	}

	public function isFireProof() : bool{
		return true;
	}

	public function canBreathe() : bool{
		return true;
	}

	public function attack(EntityDamageEvent $source) : void{
		if($source->getCause() === EntityDamageEvent::CAUSE_SUFFOCATION){
			$source->cancel();
			return;
		}
		parent::attack($source);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$wasCharging = $this->isCharging();
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->closed || !$this->isAlive()){
			return $hasUpdate;
		}
		if($wasCharging !== $this->isCharging()){
			$this->networkPropertiesDirty = true;
		}
		if($this->lifeTicks >= 0){
			$this->lifeTicks -= $tickDiff;
			if($this->lifeTicks < 0){
				$this->lifeTicks = 20;
				$this->attack(new EntityDamageEvent($this, EntityDamageEvent::CAUSE_MAGIC, 1));
			}
		}
		//take the evoker's target
		$owner = $this->getOwningEntity();
		if($owner instanceof Evoker && $this->getAttackTarget() === null && ($target = $owner->getAttackTarget()) !== null){
			$this->setAttackTarget($target);
		}
		return $hasUpdate;
	}

	public function getDrops() : array{
		return $this->getHeldItemDrops();
	}

	public function getXpDropAmount() : int{
		return $this->equipmentXp(5);
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::VEX_SPAWN_EGG();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		//flag 43 is ActorFlags::CHARGING (vex charge pose), named CHARGE_ATTACK in BedrockProtocol
		$properties->setGenericFlag(EntityMetadataFlags::CHARGE_ATTACK, isset($this->chargeGoal) && $this->isCharging());
	}

	protected function destroyCycles() : void{
		unset($this->chargeGoal);
		parent::destroyCycles();
	}

	protected function makesFlapVibrations() : bool{
		return true;
	}
}
