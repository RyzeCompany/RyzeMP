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
use pocketmine\item\Bucket;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use function mt_rand;

/**
 * Bedrock values: 10 health, movement 0.25, 0.9x1.3, milked with a bucket, bred with wheat. Climate variant from the
 * biome (temperate, warm, cold) and sound variant (default, moody), see {@link FarmAnimalVariantTrait}. Carries a baby
 * zombie (seat 1.15 up, 1.0 for calves) and walks to its rider's target (behavior.mount_pathing, 1.5x).
 * Loot: 0-2 leather, 1-3 beef (cooked when burning), +0-1 each per Looting level.
 */
class Cow extends Animal{
	use FarmAnimalVariantTrait;

	public const TAG_CLIMATE_VARIANT = "ClimateVariant"; //TAG_String
	public const TAG_SOUND_VARIANT = "SoundVariant"; //TAG_Int

	public const SOUND_VARIANTS = ["default", "moody"];

	public static function getNetworkTypeId() : string{ return EntityIds::COW; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.3, 0.9, 1.2);
	}

	public function getName() : string{
		return "Cow";
	}

	public function getSpawnBabyChance() : int{
		return 5; //entity_spawned: 95 adult / 5 baby
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(10);
		$this->setMovementSpeed(0.25);
		parent::initEntity($nbt);
		$this->initFarmVariants($nbt);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$this->saveFarmVariants($nbt);
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		$this->goalSelector->addGoal(1, new PanicGoal($this, 1.25));
		$this->goalSelector->addGoal(2, new MountPathingGoal($this, 1.5));
		$this->goalSelector->addGoal(3, new BreedGoal($this, 1.0));
		$this->goalSelector->addGoal(4, new TemptGoal($this, 1.25, fn(Item $item) => $this->isBreedingItem($item)));
		$this->goalSelector->addGoal(5, new FollowParentGoal($this, 1.1));
		$this->goalSelector->addGoal(6, new RandomStrollGoal($this, 0.8));
		$this->goalSelector->addGoal(7, new LookAtPlayerGoal($this, 6.0));
		$this->goalSelector->addGoal(9, new RandomLookAroundGoal($this));
	}

	public function isBreedingItem(Item $item) : bool{
		return $item->getTypeId() === ItemTypeIds::WHEAT;
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		$baby = new Cow($location);
		$this->inheritFarmVariants($baby, $partner);
		return $baby;
	}

	public function getRideableSeats() : array{
		return [RideableSeat::at(0, $this->isBaby() ? 1.0 : 1.15, 0)];
	}

	public function canBeRiddenBy(Entity $entity) : bool{
		return $entity instanceof Zombie && $entity->isBaby(); //family baby_undead, calves too
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if($item instanceof Bucket && $item->getTypeId() === ItemTypeIds::BUCKET && !$this->isBaby()){
			$this->exchangeHeldItem($player, VanillaItems::MILK_BUCKET());
			$this->playMobSound(LevelSoundEvent::MILK);
			return true;
		}
		return parent::onInteractWithItem($player, $item);
	}

	protected function getAdultDrops() : array{
		return [
			VanillaItems::LEATHER()->setCount(mt_rand(0, 2) + $this->lootingBonus()),
			$this->cookedIfBurning(VanillaItems::RAW_BEEF(), VanillaItems::STEAK())->setCount(mt_rand(1, 3) + $this->lootingBonus())
		];
	}
}
