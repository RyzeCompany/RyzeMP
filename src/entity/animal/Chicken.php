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

namespace pocketmine\entity\animal;

use pocketmine\entity\ai\goal\BreedGoal;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\FollowParentGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MountPathingGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\TemptGoal;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\riding\RideableSeat;
use pocketmine\entity\Zombie;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use function count;
use function mt_rand;

/**
 * Bedrock values: 4 health, movement 0.25, 0.6x0.8, bred with seeds, lays an egg every 5-10 minutes, falls slowly
 * and takes no fall damage. An adult carries a baby zombie (chicken jockey, seat 0.48 up) and walks to its rider's
 * target (vanilla behavior.mount_pathing, 1.5x). Climate variant from the biome (temperate, warm, cold) and sound variant
 * (default, picky), see {@link FarmAnimalVariantTrait}: it lays an egg, brown egg or blue egg by climate, never while
 * ridden (spawn_entity filter rider_count == 0).
 * Loot: 0-2 feathers, 1 raw chicken (cooked when burning), +0-1 each per Looting level.
 */
class Chicken extends Animal{
	use FarmAnimalVariantTrait;

	public const TAG_EGG_LAY_TIME = "EggLayTime"; //TAG_Int (ticks left)
	public const TAG_CLIMATE_VARIANT = "ClimateVariant"; //TAG_String
	public const TAG_SOUND_VARIANT = "SoundVariant"; //TAG_Int

	public const SOUND_VARIANTS = ["default", "picky"];

	/** Vanilla spawn_entity min/max wait: 300-600 seconds. */
	public const EGG_MIN_TICKS = 6000;
	public const EGG_MAX_TICKS = 12000;

	private int $eggLayTime = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::CHICKEN; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.8, 0.6, 0.6);
	}

	public function getName() : string{
		return "Chicken";
	}

	public function getSpawnBabyChance() : int{
		return 5; //entity_spawned: 95 adult / 5 baby
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(4);
		$this->setMovementSpeed(0.25);
		parent::initEntity($nbt);
		$this->eggLayTime = $nbt->getInt(self::TAG_EGG_LAY_TIME, mt_rand(self::EGG_MIN_TICKS, self::EGG_MAX_TICKS));
		$this->initFarmVariants($nbt);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_EGG_LAY_TIME, $this->eggLayTime);
		$this->saveFarmVariants($nbt);
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		$this->goalSelector->addGoal(1, new PanicGoal($this, 1.5));
		$this->goalSelector->addGoal(2, new MountPathingGoal($this, 1.5));
		$this->goalSelector->addGoal(3, new BreedGoal($this, 1.0));
		$this->goalSelector->addGoal(4, new TemptGoal($this, 1.0, fn(Item $item) => $this->isBreedingItem($item)));
		$this->goalSelector->addGoal(5, new FollowParentGoal($this, 1.1));
		$this->goalSelector->addGoal(6, new RandomStrollGoal($this, 1.0));
		$this->goalSelector->addGoal(7, new LookAtPlayerGoal($this, 6.0));
		$this->goalSelector->addGoal(8, new RandomLookAroundGoal($this));
	}

	public function isBreedingItem(Item $item) : bool{
		$id = $item->getTypeId();
		return $id === ItemTypeIds::WHEAT_SEEDS || $id === ItemTypeIds::BEETROOT_SEEDS || $id === ItemTypeIds::MELON_SEEDS ||
			$id === ItemTypeIds::PUMPKIN_SEEDS || $id === ItemTypeIds::TORCHFLOWER_SEEDS || $id === ItemTypeIds::PITCHER_POD;
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		$baby = new Chicken($location);
		$this->inheritFarmVariants($baby, $partner);
		return $baby;
	}

	public function getRideableSeats() : array{
		return $this->isBaby() ? [] : [RideableSeat::at(0, 0.48, 0)];
	}

	public function canBeRiddenBy(Entity $entity) : bool{
		return !$this->isBaby() && $entity instanceof Zombie && $entity->isBaby();
	}

	public function getEggLayTime() : int{
		return $this->eggLayTime;
	}

	public function setEggLayTime(int $ticks) : void{
		$this->eggLayTime = $ticks;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->isAlive() && !$this->isBaby()){
			$this->eggLayTime -= $tickDiff;
			if($this->eggLayTime <= 0){
				if(count($this->passengers) === 0){
					$this->getWorld()->dropItem($this->location, $this->getClimateVariant()->getEggItem());
					$this->playMobSound(LevelSoundEvent::PLOP);
				}
				$this->eggLayTime = mt_rand(self::EGG_MIN_TICKS, self::EGG_MAX_TICKS);
			}
		}
		return $hasUpdate;
	}

	protected function tryChangeMovement() : void{
		parent::tryChangeMovement();
		//flapping wings: falls slowly
		if(!$this->onGround && $this->motion->y < 0){
			$this->motion = $this->motion->withComponents(null, $this->motion->y * 0.6, null);
		}
	}

	protected function calculateFallDamage(float $fallDistance) : float{
		return 0;
	}

	protected function getAdultDrops() : array{
		return [
			VanillaItems::FEATHER()->setCount(mt_rand(0, 2) + $this->lootingBonus()),
			$this->cookedIfBurning(VanillaItems::RAW_CHICKEN(), VanillaItems::COOKED_CHICKEN())->setCount(1 + $this->lootingBonus())
		];
	}
}
