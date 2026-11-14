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

use pocketmine\entity\ai\control\SwimmingMoveControl;
use pocketmine\entity\ai\goal\AquaticChargeAttackGoal;
use pocketmine\entity\ai\goal\BreedGoal;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\TemptGoal;
use pocketmine\entity\ai\SwimmingMobTrait;
use pocketmine\entity\animal\Animal;
use pocketmine\entity\animal\TameableAnimal;
use pocketmine\entity\effect\Effect;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\riding\MountInventoryHolder;
use pocketmine\entity\riding\RiderInput;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\MobBucket;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use function in_array;

/**
 * Bedrock values (entities/nautilus.json, 1.26.50): 15 health, 0.875x0.95 (babies 0.44x0.5), knockback resistance
 * 0.3, swims (underwater speed 0.07 when not ridden, 0.15 for babies). 10% of the wild ones spawn as babies
 * (entity_spawned). Wild adults are tempted by pufferfish (and their buckets), which tame them with a 1/3 chance;
 * tamed ones and babies are tempted by every fish (or fish bucket, which gives back a water bucket), tamed ones are
 * healed by fish (2 to 12 health) and breed (only tamed ones). Babies and tamed ones only panic when hurt.
 * Wild adults get angry for 20 seconds at whoever hurts them, or at a pufferfish they hunt (nautilus_wild_adult_calm
 * nearest_attackable_target, see {@link NautilusTrait::huntPufferfish()}), and charge it (aquatic_charge_attack, 3
 * damage); while angry they can be neither tamed nor led.
 * Tamed adults take a saddle and nautilus armor and are ridden underwater, see {@link NautilusTrait}.
 * Loot (loot_tables/entities/nautilus.json): nautilus shell, 5% (+1% per Looting level), player (or pet) kills only;
 * and its equipment.
 */
class Nautilus extends TameableAnimal implements MountInventoryHolder{
	use SwimmingMobTrait;
	use NautilusTrait;

	public const TAME_CHANCE = 1 / 3;
	/** minecraft:angry duration of nautilus_wild_adult_angry. */
	public const ANGER_TICKS = 400;
	/** aquatic_charge_attack charge_speed 1.2, in blocks per tick (scaled like the zombie nautilus' 0.96). */
	public const CHARGE_SPEED = 0.375;
	/** aquatic_charge_attack knockback_force 2.0 x the charge speed factor 0.6 of Java's ChargeAttack, plus the normal 0.4. */
	public const CHARGE_KNOCKBACK = 1.6;
	public const SWIM_SPEED = 0.07;
	public const BABY_SWIM_SPEED = 0.15;

	private const FOOD = [
		ItemTypeIds::PUFFERFISH_BUCKET, ItemTypeIds::COD_BUCKET, ItemTypeIds::SALMON_BUCKET, ItemTypeIds::TROPICAL_FISH_BUCKET,
		ItemTypeIds::PUFFERFISH, ItemTypeIds::RAW_FISH, ItemTypeIds::RAW_SALMON, ItemTypeIds::CLOWNFISH, ItemTypeIds::COOKED_FISH, ItemTypeIds::COOKED_SALMON,
	];
	private const TAME_ITEMS = [ItemTypeIds::PUFFERFISH, ItemTypeIds::PUFFERFISH_BUCKET];
	/** Health given back by each food to a tamed nautilus (vanilla healable). */
	private const HEAL_AMOUNTS = [
		ItemTypeIds::PUFFERFISH_BUCKET => 2, ItemTypeIds::COD_BUCKET => 4, ItemTypeIds::SALMON_BUCKET => 4, ItemTypeIds::TROPICAL_FISH_BUCKET => 2,
		ItemTypeIds::RAW_FISH => 4, ItemTypeIds::RAW_SALMON => 4, ItemTypeIds::CLOWNFISH => 2, ItemTypeIds::PUFFERFISH => 2,
		ItemTypeIds::COOKED_FISH => 10, ItemTypeIds::COOKED_SALMON => 12
	];

	public static function getNetworkTypeId() : string{ return EntityIds::NAUTILUS; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.95, 0.875, 0.5);
	}

	public function getName() : string{
		return "Nautilus";
	}

	protected function getBabySizeInfo() : ?EntitySizeInfo{
		return new EntitySizeInfo(1.0, 0.88, 0.526); //baby collision box 0.44 x 0.5 (no scale in the behavior pack, ours is 0.5)
	}

	public function isImmuneToEffect(Effect $effect) : bool{
		return $effect === VanillaEffects::POISON() || parent::isImmuneToEffect($effect); //nautilus.json mob_effect_immunity
	}

	/** Ticks left of nautilus_wild_adult_angry, 0 while calm. */
	private int $angerTicks = 0;

	protected function getSwimSpeed() : float{
		return $this->isBaby() ? self::BABY_SWIM_SPEED : self::SWIM_SPEED;
	}

	public function setAge(int $age) : void{
		parent::setAge($age);
		if(isset($this->moveControl) && $this->moveControl instanceof SwimmingMoveControl){
			//babies swim faster (nautilus_baby underwater_movement 0.15)
			$this->moveControl->setSwimSpeed($this->getSwimSpeed());
		}
	}

	public function getSpawnBabyChance() : int{
		return 10; //entity_spawned: 90 spawn_wild_adult / 10 spawn_wild_baby
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(15);
		$this->setMovementSpeed(0.15);
		parent::initEntity($nbt);
		$this->knockbackResistanceAttr->setValue(0.3);
		$this->initNautilusInventory($nbt);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$this->saveNautilusInventory($nbt);
		return $nbt;
	}

	protected function registerGoals() : void{
		//nautilus_tame and nautilus_baby: panic only; the only attack is the charge of nautilus_wild_adult_angry
		$this->goalSelector->addGoal(1, new PanicGoal($this, 1.6, fn() => $this->isTamed() || $this->isBaby()));
		$this->goalSelector->addGoal(2, new BreedGoal($this, 1.0));
		$this->goalSelector->addGoal(3, new AquaticChargeAttackGoal(
			$this,
			$this->swimTowards(...),
			$this->stopSwimming(...),
			self::CHARGE_SPEED,
			self::SWIM_SPEED,
			3.0,
			self::CHARGE_KNOCKBACK,
			condition: fn() => $this->angerTicks > 0 && $this->isInWater()
		));
		//angry wild adults lose nautilus_wild_adult_calm, and its tempt
		$this->goalSelector->addGoal(4, new TemptGoal($this, 2.0, fn(Item $item) => $this->angerTicks === 0 && $this->isTemptItem($item), onStopTempting: $this->setHomeHere(...)));
		$this->goalSelector->addGoal(6, new RandomStrollGoal($this, 1.5, 40, 10, 7));
		$this->targetSelector->addGoal(1, new HurtByTargetGoal($this, 25.0));
	}

	/**
	 * Wild adults only follow pufferfish, the others any fish.
	 */
	public function isTemptItem(Item $item) : bool{
		return $this->isTamed() || $this->isBaby() ? $this->isBreedingItem($item) : in_array($item->getTypeId(), self::TAME_ITEMS, true);
	}

	/**
	 * Whether the wild adult is angry (nautilus_wild_adult_angry): it charges its target, and can't be tamed or led.
	 */
	public function isAngry() : bool{
		return $this->angerTicks > 0;
	}

	/**
	 * Getting a target makes a calm wild adult angry for 20 seconds (on_target_acquired: become_angry); the anger ends
	 * with the target. Babies and tamed ones may get a target (hurt_by_target) but never get angry.
	 */
	public function setAttackTarget(?Living $target) : void{
		if($target === null){
			//(getAttackTarget() clears a dead target with this call)
			$this->angerTicks = 0;
			parent::setAttackTarget(null);
			return;
		}
		$previous = $this->getAttackTarget();
		parent::setAttackTarget($target);
		$target = $this->getAttackTarget();
		if($target === null){
			$this->angerTicks = 0;
		}elseif($target !== $previous && $this->angerTicks === 0 && !$this->isTamed() && !$this->isBaby()){
			$this->angerTicks = self::ANGER_TICKS;
			if($this->isLeashed()){
				//become_angry removes nautilus_leashable
				$this->unleash(true);
			}
		}
	}

	public function canBeLeashed() : bool{
		return $this->angerTicks === 0;
	}

	protected function canHuntPufferfish() : bool{
		return !$this->isTamed() && !$this->isBaby() && $this->angerTicks === 0 && !$this->isInLove() && $this->getAttackTarget() === null && $this->isInWater();
	}

	protected function onPreyFound(Living $prey) : void{
		$this->setAttackTarget($prey);
	}

	protected function onGrowUp() : void{
		parent::onGrowUp();
		//ageable_grow_up adds nautilus_tame_adult or nautilus_wild_adult_calm: no anger yet, whatever it targeted
		$this->setAttackTarget(null);
	}

	public function isBreedingItem(Item $item) : bool{
		return in_array($item->getTypeId(), self::FOOD, true);
	}

	protected function consumeFood(Player $player, Item $item) : void{
		if($item instanceof MobBucket){
			$this->exchangeHeldItem($player, VanillaItems::WATER_BUCKET());
		}else{
			$this->consumeHeldItem($player);
		}
	}

	protected function onTamedChanged() : void{
		parent::onTamedChanged();
		if($this->isTamed()){
			$this->angerTicks = 0;
			$this->setHomeHere(); //on_tame: set_home_position
		}
	}

	/**
	 * A nautilus never sits.
	 */
	public function setSitting(bool $sitting) : void{
		parent::setSitting(false);
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if(!$this->isTamed() && !$this->isBaby() && in_array($item->getTypeId(), self::TAME_ITEMS, true)){
			if($this->angerTicks > 0){
				return false; //become_angry removes the tameable component
			}
			$this->consumeFood($player, $item);
			$this->tryTame($player, self::TAME_CHANCE);
			return true;
		}
		if($this->interactWithEquipment($player, $item)){
			return true;
		}
		if($this->isTamed() && isset(self::HEAL_AMOUNTS[$item->getTypeId()]) && $this->getHealth() < $this->getMaxHealth()){
			$this->consumeFood($player, $item);
			$this->heal(new EntityRegainHealthEvent($this, self::HEAL_AMOUNTS[$item->getTypeId()], EntityRegainHealthEvent::CAUSE_EATING));
			return true;
		}
		if(parent::onInteractWithItem($player, $item)){
			return true;
		}
		return $this->tryRide($player);
	}

	protected function getItemInteractButtonText(Player $player, Item $item) : ?string{
		return $this->getEquipmentButtonText($player, $item);
	}

	public function getRideableSeats() : array{
		return $this->isTamed() && !$this->isBaby() ? [$this->getPlayerSeat()] : [];
	}

	public function canBeRiddenBy(Entity $entity) : bool{
		return $entity instanceof Player && $this->isTamed() && !$this->isBaby();
	}

	public function isInWaterForRiding() : bool{
		return $this->isInWater() || $this->isUnderwater();
	}

	protected function swimTowards(float $x, float $y, float $z, float $speed) : void{
		$this->moveControl->setWantedPosition($x, $y, $z, $speed / $this->getSwimSpeed());
	}

	protected function stopSwimming() : void{
		$this->moveControl->stop();
	}

	public function handleRiderInput(Player $rider, RiderInput $input) : void{
		parent::handleRiderInput($rider, $input);
		$this->handleDashInput($rider, $input);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->isAlive()){
			$this->tickNautilus($tickDiff);
			if($this->angerTicks > 0 && ($this->angerTicks -= $tickDiff) <= 0){
				//on_calm
				$this->angerTicks = 0;
				$this->setAttackTarget(null);
			}
			$this->huntPufferfish();
		}
		return $hasUpdate;
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		$baby = new Nautilus($location);
		$owner = $this->getOwner();
		if($owner !== null){
			//vanilla entity_born: babies are born tamed
			$baby->setOwner($owner);
		}
		return $baby;
	}

	protected function getAdultDrops() : array{
		$drops = $this->getEquipmentDrops();
		if($this->wasRecentlyHitByPlayer() && Utils::getRandomFloat() < 0.05 + 0.01 * $this->getLootingLevel()){
			$drops[] = VanillaItems::NAUTILUS_SHELL();
		}
		return $drops;
	}

	protected function onDeath() : void{
		$this->closeNautilusInventory();
		parent::onDeath();
	}

	protected function onDispose() : void{
		$this->closeNautilusInventory();
		parent::onDispose();
	}

	protected function isAngryWhenTargeting() : bool{
		//nautilus_wild_adult_calm: on_target_acquired adds nautilus_wild_adult_angry (minecraft:angry)
		return true;
	}

	protected function showsTargetAnger() : bool{
		//tamed and baby nautiluses have no angry group
		return !$this->isTamed() && !$this->isBaby();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$this->syncNautilusData($properties);
	}
}
