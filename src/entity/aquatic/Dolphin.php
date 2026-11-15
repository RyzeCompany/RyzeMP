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

namespace pocketmine\entity\aquatic;

use pocketmine\entity\Ageable;
use pocketmine\entity\AgeLockTrait;
use pocketmine\entity\ai\control\MoveControl;
use pocketmine\entity\ai\control\SwimmingMoveControl;
use pocketmine\entity\ai\goal\AvoidEntityGoal;
use pocketmine\entity\ai\goal\FindTreasureGoal;
use pocketmine\entity\ai\goal\FlockingGoal;
use pocketmine\entity\ai\goal\FollowParentGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\MoveToLiquidGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RandomBreachGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomSwimGoal;
use pocketmine\entity\ai\goal\SwimWithEntityGoal;
use pocketmine\entity\ai\SwimmingMobTrait;
use pocketmine\entity\animal\Animal;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Guardian;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use function intdiv;
use function mt_rand;

/**
 * Bedrock values (entities/dolphin.json): 10 health, 0.9x0.6, underwater speed 0.15, attack 3 against whoever hurts
 * it, angry for 25 seconds and the dolphins within 16 blocks join in (dolphin_angry), breathes air (240 seconds of air)
 * and swims up to breathe, keeps away from guardians, leaps out of the water now and then (random_breach), swims in
 * pods (flocking) and now and then along with a swimming player (swim_with_entity), dries out on land unless it rains
 * on it (2 minutes, then 1 damage per hurt cooldown). Stranded on land, it walks to the nearest water within 15 blocks
 * (move_to_water). Fed raw cod or salmon (bribeable), it leads the way to the nearest ocean ruin, shipwreck or buried
 * treasure ({@link FindTreasureGoal}).
 * 10% of the spawned dolphins are babies (dolphin_baby: scale 0.65, grow up in 20 minutes or faster when fed fish,
 * panic when hurt, follow the adults, no attack, no loot, no XP).
 * Loot (loot_tables/entities/dolphin.json): 0-1 raw cod (+0-1 per Looting level, cooked when burning); XP 1-3.
 */
class Dolphin extends Mob implements Ageable{
	use SwimmingMobTrait;
	use AgeLockTrait;

	public const TAG_MOISTNESS = "Moistness"; //TAG_Int
	public const TAG_GOT_FISH = "GotFish"; //TAG_Byte
	private const MAX_AIR_TICKS = 4800;
	/** Living's air supply tag. */
	private const TAG_AIR = "Air"; //TAG_Short
	private const MAX_MOISTNESS = 2400;

	/** minecraft:entity_spawned: 10 in 100 spawn as babies (entity_born). */
	public const SPAWN_BABY_CHANCE = 10;
	/** dolphin_baby minecraft:ageable duration (1200 seconds). */
	public const BABY_GROW_TICKS = 24000;
	public const BABY_SCALE = 0.65;
	/** dolphin_angry minecraft:angry duration (25 seconds) and broadcast_range. */
	public const ANGER_TICKS = 500;
	public const ANGER_BROADCAST_RANGE = 16.0;
	/** minecraft:bribeable bribe_cooldown (default 2 seconds). */
	public const BRIBE_COOLDOWN_TICKS = 40;
	/** behavior.avoid_mob_type max_dist of guardians and elder guardians. */
	public const GUARDIAN_AVOID_DISTANCE = 8.0;

	private int $moistness = self::MAX_MOISTNESS;
	/** Like {@link Animal::getAge()}: below 0 for a baby (ticks left before it grows up), 0 for an adult. */
	private int $age = 0;
	/** Whether it was fed fish and hasn't led the way to a treasure yet. */
	private bool $gotFish = false;
	private int $bribeCooldownEndTick = 0;
	private ?FlockingGoal $flocking = null;

	public static function getNetworkTypeId() : string{ return EntityIds::DOLPHIN; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.6, 0.9, 0.3);
	}

	public function canBeLeashed() : bool{
		return true;
	}

	public function getName() : string{
		return "Dolphin";
	}

	protected function getSwimSpeed() : float{
		return 0.15;
	}

	/**
	 * dolphin_swimming_navigation can_walk false: in water it doesn't swim ashore after its targets; dolphin_on_land
	 * can_walk true: stranded, it walks (to the water, move_to_water).
	 */
	protected function createMoveControl() : MoveControl{
		return new SwimmingMoveControl($this, $this->getSwimSpeed(), canWalk: true, leavesWater: false);
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(10);
		$this->setMovementSpeed(0.1);
		$this->setMaxAirSupplyTicks(self::MAX_AIR_TICKS);
		$this->setFollowRange(48);
		parent::initEntity($nbt);
		if($nbt->getTag(self::TAG_AIR) === null){
			//a new dolphin has its whole air supply (breathable total_supply 240), not the default 15 seconds
			$this->setAirSupplyTicks(self::MAX_AIR_TICKS);
		}
		$this->moistness = $nbt->getInt(self::TAG_MOISTNESS, self::MAX_MOISTNESS);
		$this->gotFish = $nbt->getByte(self::TAG_GOT_FISH, 0) !== 0;
		$age = $nbt->getInt(Animal::TAG_AGE, 0);
		if($nbt->getByte(Animal::TAG_IS_BABY, 0) !== 0 && $age >= 0){
			$age = -self::BABY_GROW_TICKS;
		}
		$this->setAge($age < 0 ? $age : 0);
		$this->loadAgeLock($nbt);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_MOISTNESS, $this->moistness);
		$nbt->setByte(self::TAG_GOT_FISH, $this->gotFish ? 1 : 0);
		$nbt->setInt(Animal::TAG_AGE, $this->age);
		$nbt->setByte(Animal::TAG_IS_BABY, $this->isBaby() ? 1 : 0);
		$this->saveAgeLock($nbt);
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(1, new class($this) extends Goal{
			public function __construct(private Dolphin $dolphin){}

			public function getFlags() : int{ return self::FLAG_MOVE; }

			public function canStart() : bool{
				return $this->dolphin->isInWater() && $this->dolphin->getAirSupplyTicks() < 140;
			}

			public function canContinue() : bool{
				return $this->dolphin->isUnderwater() && $this->dolphin->getAirSupplyTicks() < 300;
			}

			public function tick() : void{
				$pos = $this->dolphin->getPosition();
				$this->dolphin->getNavigation()->moveTo($pos->x, $pos->y + 3, $pos->z, 1.0);
			}
		});
		//on land (dolphin_on_land: navigation can_walk): move_to_water, straight to the nearest water
		$this->goalSelector->addGoal(1, new MoveToLiquidGoal($this, MoveToLiquidGoal::WATER, 1.0, 15, 5));
		//dolphin_baby: panic 1.25 when hurt (adults have no panic, not even when burning)
		$this->goalSelector->addGoal(1, new class($this, 1.25) extends PanicGoal{
			protected function shouldPanic() : bool{
				return $this->mob instanceof Dolphin && $this->mob->isBaby() && parent::shouldPanic();
			}
		});
		//avoid_mob_type: guardians have no strength, so probability_per_strength doesn't apply (always avoided)
		//(no search in worlds without guardians)
		$this->goalSelector->addGoal(2, new AvoidEntityGoal($this, fn(Entity $e) => $e instanceof Guardian, self::GUARDIAN_AVOID_DISTANCE, condition: fn() => $this->getWorld()->hasEntitiesOfClass(Guardian::class)));
		$this->goalSelector->addGoal(2, new FindTreasureGoal($this, 2.0));
		//dolphin_adult: melee_box_attack (babies don't attack)
		$this->goalSelector->addGoal(2, new class($this, 1.2, 3.0, 48.0) extends MeleeAttackGoal{
			public function canStart() : bool{
				return $this->mob instanceof Dolphin && !$this->mob->isBaby() && parent::canStart();
			}

			public function canContinue() : bool{
				return $this->mob instanceof Dolphin && !$this->mob->isBaby() && parent::canContinue();
			}
		});
		$this->goalSelector->addGoal(4, new SwimWithEntityGoal($this));
		$this->goalSelector->addGoal(4, new FollowParentGoal($this, 1.1));
		$this->goalSelector->addGoal(5, $this->getFlockingGoal());
		//random_breach (Bedrock priority 6, after random_swim 5): ahead of the wandering here, which never pauses
		$this->goalSelector->addGoal(5, new RandomBreachGoal($this, 50, 6, 40, 0.6));
		//random_swim: always swimming (interval 0), 20 blocks around
		$this->goalSelector->addGoal(6, new RandomSwimGoal($this, 1.0, 0, 20, 7));
		$this->goalSelector->addGoal(7, new LookAtPlayerGoal($this, 6.0));
		$this->goalSelector->addGoal(8, new RandomLookAroundGoal($this));
		//dolphin_angry: 25 seconds, the dolphins within 16 blocks join in (broadcast_anger)
		$this->targetSelector->addGoal(1, new HurtByTargetGoal($this, 48.0, [], self::ANGER_BROADCAST_RANGE, null, self::ANGER_TICKS));
	}

	/**
	 * @internal Returns the goal that keeps this dolphin in its pod (minecraft:flocking: influence radius 6, pods of
	 * 4 to 8, 10% loner chance, cohesion threshold 6.5, also out of water).
	 */
	public function getFlockingGoal() : FlockingGoal{
		return $this->flocking ??= new FlockingGoal($this, 6.0, 4, 8, 0.1, 6.5, false);
	}

	protected function isAngryWhenTargeting() : bool{
		//on_target_acquired adds dolphin_angry (minecraft:angry)
		return true;
	}

	public function canBreathe() : bool{
		return !$this->isUnderwater();
	}

	public function getMoistness() : int{
		return $this->moistness;
	}

	public function isBaby() : bool{
		return $this->age < 0;
	}

	/**
	 * Below 0 for a baby: minus the ticks left before it grows up; 0 for an adult.
	 */
	public function getAge() : int{
		return $this->age;
	}

	public function setAge(int $age) : void{
		$wasBaby = $this->isBaby();
		$this->age = $age < 0 ? $age : 0;
		if($wasBaby !== $this->isBaby()){
			$this->setScale($this->isBaby() ? self::BABY_SCALE : 1.0);
			$this->networkPropertiesDirty = true;
		}
	}

	/**
	 * Makes a baby grow by the given number of ticks (not while a golden dandelion locks its age).
	 */
	public function ageUp(int $ticks) : void{
		if($this->age < 0 && !$this->isAgeLocked()){
			$this->setAge($this->age + $ticks);
		}
	}

	public function getSpawnBabyChance() : int{
		return self::SPAWN_BABY_CHANCE;
	}

	public function makeSpawnBaby() : bool{
		$this->setAge(-self::BABY_GROW_TICKS);
		return true;
	}

	protected function restartGrowth() : void{
		$this->setAge(-self::BABY_GROW_TICKS);
	}

	/**
	 * Returns whether it was fed fish and will lead the way to a treasure.
	 */
	public function hasGotFish() : bool{
		return $this->gotFish;
	}

	public function setGotFish(bool $gotFish) : void{
		$this->gotFish = $gotFish;
	}

	/**
	 * Returns whether the item is a bribe_items / feed_items fish (raw cod or salmon).
	 */
	public static function isFish(Item $item) : bool{
		$id = $item->getTypeId();
		return $id === ItemTypeIds::RAW_FISH || $id === ItemTypeIds::RAW_SALMON;
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if($this->tryUseGoldenDandelion($player, $item)){
			return true;
		}
		if(!self::isFish($item)){
			return false;
		}
		if($this->isBaby()){
			if($this->isAgeLocked()){
				return false;
			}
			//feed_items growth (default 0.1) is a fraction of the whole growing time
			$this->consumeHeldItem($player);
			$this->ageUp(intdiv(self::BABY_GROW_TICKS, 10));
			$this->broadcastActorEvent(ActorEvent::BABY_ANIMAL_FEED);
			return true;
		}
		$now = $this->server->getTick();
		if($now < $this->bribeCooldownEndTick){
			return false;
		}
		//bribeable: the fish makes it lead the way to a treasure (find_underwater_treasure)
		$this->bribeCooldownEndTick = $now + self::BRIBE_COOLDOWN_TICKS;
		$this->consumeHeldItem($player);
		$this->gotFish = true;
		$this->playMobSound(LevelSoundEvent::EAT);
		return true;
	}

	private function broadcastActorEvent(int $event) : void{
		NetworkBroadcastUtils::broadcastPackets($this->getViewers(), [ActorEventPacket::create($this->id, $event, 0, null)]);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->isAlive()){
			if($this->age < 0 && !$this->ageLocked){
				$this->setAge($this->age + $tickDiff);
			}
			if($this->ageLockTicks > 0){
				$this->tickAgeLock($tickDiff);
			}
			//rain keeps it moist too (checked once a second)
			if($this->isInWater() || (($this->server->getTick() + $this->id) % 20 === 0 && $this->isInRain())){
				$this->moistness = self::MAX_MOISTNESS;
			}else{
				$this->moistness -= $tickDiff;
				//dolphin_dried: damage_over_time 1 with time_between_hurt 0, i.e. whenever the hurt cooldown allows
				if($this->moistness <= 0 && $this->attackTime <= 0){
					$this->attack(new EntityDamageEvent($this, EntityDamageEvent::CAUSE_SUFFOCATION, 1));
				}
			}
		}
		return $hasUpdate;
	}

	public function getXpDropAmount() : int{
		return !$this->isBaby() && $this->wasRecentlyHitByPlayer() ? mt_rand(1, 3) : 0;
	}

	public function getDrops() : array{
		if($this->isBaby()){
			return [];
		}
		$count = mt_rand(0, 1) + $this->lootingBonus();
		return $count > 0 ? [($this->isOnFire() ? VanillaItems::COOKED_FISH() : VanillaItems::RAW_FISH())->setCount($count)] : [];
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::BABY, $this->isBaby());
	}

	protected function onDispose() : void{
		$this->flocking?->dispose();
		parent::onDispose();
	}
}
