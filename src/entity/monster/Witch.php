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

use pocketmine\block\Magma;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\NearestAttackableEntityGoal;
use pocketmine\entity\ai\goal\NearestAttackableTargetGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\RangedAttackGoal;
use pocketmine\entity\ai\hostile\Aim;
use pocketmine\entity\effect\InstantEffect;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\golem\SnowGolem;
use pocketmine\entity\Living;
use pocketmine\entity\projectile\SplashPotion;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\PotionType;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use function floor;
use function mt_rand;

/**
 * Bedrock values (witch.json): 26 health, movement 0.25, targets players, snow golems and iron golems within 10 blocks,
 * throws a splash potion every 3 seconds within 10 blocks: Slowness at 8+ blocks, Poison if the target has 8+ health,
 * Weakness (25%) within 3 blocks, Harming otherwise.
 * In a raid, when no enemy is around, it supports the other raiders within 10 blocks (one every 10 seconds at most): a
 * Splash Healing for one with 4 health or less, Splash Regeneration otherwise.
 * Drinks (32 ticks, 25% slower meanwhile) Water Breathing underwater (15%), Fire Resistance when burning, standing on
 * magma or hurt by fire (15%), Healing when hurt (5%), Swiftness when its target is 11+ blocks away (25%).
 * Loot: 1-3 rolls of 0-2 glowstone dust, sugar, spider eye, glass bottle, gunpowder or sticks, 4-8 redstone (+0-1
 * each per Looting level).
 */
class Witch extends Monster implements Raider{
	use RaiderTrait;
	use HeldItemsTrait;

	public const DRINK_TICKS = 32;
	public const ATTACK_RANGE = 10.0;

	/** Ticks before another raider can be picked to support (nearest_prioritized_attackable_target cooldown 10 s) */
	public const SUPPORT_COOLDOWN = 200;

	private int $drinkTicks = 0;
	private ?PotionType $drinking = null;
	/** Server tick from which the witch may pick a raider to support again. */
	private int $nextSupportTick = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::WITCH; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.9, 0.6, 1.62);
	}

	public function getName() : string{
		return "Witch";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(26);
		$this->setMovementSpeed(0.25);
		$this->setFollowRange(64);
		parent::initEntity($nbt);
		$this->loadHeldItems($nbt);
		$this->loadRaiderData($nbt);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$this->saveHeldItems($nbt);
		$this->saveRaiderData($nbt);
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(1, new FloatGoal($this));
		$this->goalSelector->addGoal(2, new class($this, 1.0, 60, 60, self::ATTACK_RANGE, $this->throwPotion(...)) extends RangedAttackGoal{
			public function canStart() : bool{
				return !($this->mob instanceof Witch && $this->mob->isDrinking()) && parent::canStart();
			}
		});
		$this->goalSelector->addGoal(4, new RandomStrollGoal($this, 1.0));
		$this->goalSelector->addGoal(5, new LookAtPlayerGoal($this, 8.0));
		$this->goalSelector->addGoal(5, new RandomLookAroundGoal($this));

		$this->targetSelector->addGoal(1, new HurtByTargetGoal($this));
		//nearest_prioritized_attackable_target: players, snow golems and iron golems within 10 blocks first (players and
		//golems are looked for separately: the world's player list is cheap, and golems only while the world has some)
		$this->targetSelector->addGoal(2, new NearestAttackableTargetGoal($this, self::ATTACK_RANGE, true, 10));
		//(both goals check their condition before their random search interval: an idle witch outside raids draws no
		//random numbers for them, which keeps its other goals' random behaviour, and cost, as it was)
		$this->targetSelector->addGoal(2, new class($this, fn(Living $e) => $e instanceof SnowGolem || $e instanceof IronGolem, self::ATTACK_RANGE, true, 10, $this->hasGolems(...), verticalRange: self::ATTACK_RANGE, candidateClasses: [SnowGolem::class, IronGolem::class]) extends NearestAttackableEntityGoal{
			public function canStart() : bool{
				return $this->condition !== null && ($this->condition)() && parent::canStart();
			}
		});
		//then, while in a raid, the other raiders (not witches) within 10 blocks, to heal them (cooldown 10 seconds)
		$support = new class($this, fn(Living $e) => $e instanceof Raider && !$e instanceof Witch && $e->getRaidId() !== null, self::ATTACK_RANGE, true, 10, fn() : bool => $this->canPickSupportTarget(), verticalRange: self::ATTACK_RANGE) extends NearestAttackableEntityGoal{
			/** @var (\Closure() : void)|null */
			public ?\Closure $onStart = null;

			public function canStart() : bool{
				return $this->condition !== null && ($this->condition)() && parent::canStart();
			}

			public function start() : void{
				parent::start();
				if($this->onStart !== null){
					($this->onStart)();
				}
			}
		};
		$support->onStart = $this->startSupportCooldown(...);
		$this->targetSelector->addGoal(3, $support);
		$this->registerRaiderGoals(3, null, false);
	}

	private function hasGolems() : bool{
		$world = $this->getWorld();
		return $world->hasEntitiesOfClass(IronGolem::class) || $world->hasEntitiesOfClass(SnowGolem::class);
	}

	private function canPickSupportTarget() : bool{
		return $this->getRaidId() !== null && $this->server->getTick() >= $this->nextSupportTick;
	}

	/**
	 * Whether the target is a fellow raider to support (is_raider of witch.json means taking part in a raid, for both
	 * the witch and its target): a raider that hurts a witch outside raids, e.g. a "Johnny" vindicator, is fought back.
	 */
	private function isSupportTarget(Living $target) : bool{
		return $target instanceof Raider && $target->getRaidId() !== null && $this->getRaidId() !== null;
	}

	private function startSupportCooldown() : void{
		$this->nextSupportTick = $this->server->getTick() + self::SUPPORT_COOLDOWN;
	}

	public function isDrinking() : bool{
		return $this->drinking !== null;
	}

	/**
	 * Picks the potion to throw at the target (vanilla shooter "projectiles" filters of witch.json).
	 */
	public function choosePotion(Living $target) : PotionType{
		if($this->isSupportTarget($target)){
			//fellow raiders: Healing when they have 4 health or less, Regeneration otherwise
			return $target->getHealth() <= 4 ? PotionType::HEALING : PotionType::REGENERATION;
		}
		$distSq = $target->getLocation()->distanceSquared($this->location);
		$effects = $target->getEffects();
		if($distSq >= 64 && !$effects->has(VanillaEffects::SLOWNESS())){
			return PotionType::SLOWNESS;
		}
		if($target->getHealth() >= 8 && !$effects->has(VanillaEffects::POISON())){
			return PotionType::POISON;
		}
		if($distSq <= 9 && !$effects->has(VanillaEffects::WEAKNESS()) && mt_rand(0, 3) === 0){
			return PotionType::WEAKNESS;
		}
		return PotionType::HARMING;
	}

	public function throwPotion(Living $target, float $distance) : void{
		if($this->isDrinking()){
			return;
		}
		$world = $this->getWorld();
		$eye = $this->getEyePos();
		$aim = $target->getEyePos()->add($target->getMotion()->x, -1.1, $target->getMotion()->z);
		$motion = Aim::motionTowards($eye, $aim, 0.75, 0.01, 0.2);
		if($motion === null){
			return;
		}
		$potion = new SplashPotion(Aim::facing($eye, $world, $motion), $this, $this->choosePotion($target));
		$potion->setMotion($motion);
		$potion->spawnToAll();
		$this->playMobSound(LevelSoundEvent::THROW);
		if($this->isSupportTarget($target)){
			//lose_target: the support potions are thrown once
			$this->setAttackTarget(null);
		}
	}

	/**
	 * Returns the potion the witch wants to drink now, if any (vanilla behavior.drink_potion of witch.json).
	 */
	protected function pickPotionToDrink() : ?PotionType{
		$effects = $this->getEffects();
		if($this->isUnderwater() && !$effects->has(VanillaEffects::WATER_BREATHING()) && mt_rand(0, 99) < 15){
			return PotionType::WATER_BREATHING;
		}
		if(!$effects->has(VanillaEffects::FIRE_RESISTANCE()) && ($this->isOnFire() || $this->isTakingFireDamage() || $this->isOnHotBlock()) && mt_rand(0, 99) < 15){
			return PotionType::FIRE_RESISTANCE;
		}
		if($this->getHealth() < $this->getMaxHealth() && mt_rand(0, 99) < 5){
			return PotionType::HEALING;
		}
		$target = $this->getAttackTarget();
		if($target !== null && !$effects->has(VanillaEffects::SPEED()) && $target->getLocation()->distanceSquared($this->location) >= 121 && mt_rand(0, 99) < 25){
			return PotionType::SWIFTNESS;
		}
		return null;
	}

	/**
	 * Bedrock on_hot_block filter: standing on a magma block.
	 */
	private function isOnHotBlock() : bool{
		$loc = $this->location;
		return $this->onGround && $this->getWorld()->getBlockAt((int) floor($loc->x), (int) floor($loc->y - 0.01), (int) floor($loc->z)) instanceof Magma;
	}

	/**
	 * Bedrock taking_fire_damage filter: the witch was just hurt by fire or lava.
	 */
	private function isTakingFireDamage() : bool{
		if($this->attackTime <= 0){
			return false;
		}
		$cause = $this->getLastDamageCause()?->getCause();
		return $cause === EntityDamageEvent::CAUSE_FIRE || $cause === EntityDamageEvent::CAUSE_FIRE_TICK || $cause === EntityDamageEvent::CAUSE_LAVA;
	}

	public function startDrinking(PotionType $potion) : void{
		$this->drinking = $potion;
		$this->drinkTicks = self::DRINK_TICKS;
		$this->setMainHandItem(VanillaItems::POTION()->setType($potion));
		$this->setMovementSpeed(0.25 * 0.75);
		$this->networkPropertiesDirty = true;
	}

	private function finishDrinking() : void{
		$potion = $this->drinking;
		$this->drinking = null;
		$this->setMainHandItem(VanillaItems::AIR());
		$this->setMovementSpeed(0.25);
		$this->networkPropertiesDirty = true;
		if($potion === null){
			return;
		}
		foreach($potion->getEffects() as $effect){
			$type = $effect->getType();
			if($type instanceof InstantEffect){
				$type->applyEffect($this, $effect);
			}else{
				$this->getEffects()->add($effect);
			}
		}
		$this->playMobSound(LevelSoundEvent::DRINK);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->closed || !$this->isAlive()){
			return $hasUpdate;
		}
		if($this->drinking !== null){
			$this->drinkTicks -= $tickDiff;
			if($this->drinkTicks <= 0){
				$this->finishDrinking();
			}
			return true;
		}
		//alive and not closed: Mob::entityBaseTick() set the AI tick to this tick's (the server tick)
		if(($this->getAiTick() + $this->id) % 5 === 0 && ($potion = $this->pickPotionToDrink()) !== null){
			$this->startDrinking($potion);
		}
		return $hasUpdate;
	}

	public function getDrops() : array{
		$drops = [];
		$table = [
			VanillaItems::GLOWSTONE_DUST(), VanillaItems::SUGAR(), VanillaItems::SPIDER_EYE(), VanillaItems::GLASS_BOTTLE(),
			VanillaItems::GUNPOWDER(), VanillaItems::STICK(), VanillaItems::STICK()
		];
		for($rolls = mt_rand(1, 3); $rolls > 0; --$rolls){
			$item = clone $table[mt_rand(0, 6)];
			$count = mt_rand(0, 2) + $this->lootingBonus();
			if($count > 0){
				$drops[] = $item->setCount($count);
			}
		}
		$drops[] = VanillaItems::REDSTONE_DUST()->setCount(mt_rand(4, 8) + $this->lootingBonus());
		return $drops;
	}

	/**
	 * Witches in a raid leave villagers alone; they attack iron golems as they always do (Bedrock).
	 */
	public function isRaidVictim(Living $entity) : bool{
		return $entity instanceof IronGolem;
	}

	public function getXpDropAmount() : int{
		return $this->equipmentXp(5);
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::WITCH_SPAWN_EGG();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		//flag 4 is ActorFlags::USINGITEM (drinking animation)
		$properties->setGenericFlag(EntityMetadataFlags::ACTION, $this->isDrinking());
		$this->syncRaiderData($properties);
	}
}
