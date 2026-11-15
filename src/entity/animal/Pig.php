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
use pocketmine\entity\monster\MobConversion;
use pocketmine\entity\monster\ZombifiedPiglin;
use pocketmine\entity\object\LightningBolt;
use pocketmine\entity\riding\RideableSeat;
use pocketmine\entity\riding\RiderInput;
use pocketmine\entity\Zombie;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\World;
use function count;
use function mt_rand;

/**
 * Bedrock values: 10 health, movement 0.25, 0.9x0.9, bred with carrots, potatoes and beetroots, also tempted by a
 * carrot on a stick. Can be saddled (shears take the saddle off when nobody rides it); a saddled pig is ridden by
 * players (seat 0.63 up) and steered with a carrot on a stick held in the hand (it walks where the rider looks; using
 * the stick boosts it 1.35x for 3 seconds and costs 2 durability, vanilla boostable component). An unsaddled pig can carry a baby zombie (seat 0.7 up)
 * and walks to its rider's target (behavior.mount_pathing, 1.25x). Climate variant from the biome (temperate, warm,
 * cold) and sound variant (default, big, mini), see {@link FarmAnimalVariantTrait}.
 * Ridden speed (approximate, Java): 0.164 blocks per tick.
 * Loot: 1-3 porkchops (cooked when burning), +0-1 per Looting level, and the saddle.
 * Struck by lightning it becomes a zombified piglin.
 */
class Pig extends Animal{
	use FarmAnimalVariantTrait;

	public const TAG_SADDLED = "Saddled"; //TAG_Byte
	public const TAG_CLIMATE_VARIANT = "ClimateVariant"; //TAG_String
	public const TAG_SOUND_VARIANT = "SoundVariant"; //TAG_Int

	public const SOUND_VARIANTS = ["default", "big", "mini"];

	private bool $saddled = false;

	public static function getNetworkTypeId() : string{ return EntityIds::PIG; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.9, 0.9, 0.7);
	}

	public function getName() : string{
		return "Pig";
	}

	public function getSpawnBabyChance() : int{
		return 5; //entity_spawned: 95 adult / 5 baby
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(10);
		$this->setMovementSpeed(0.25);
		parent::initEntity($nbt);
		$this->saddled = $nbt->getByte(self::TAG_SADDLED, 0) !== 0;
		$this->initFarmVariants($nbt);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_SADDLED, $this->saddled ? 1 : 0);
		$this->saveFarmVariants($nbt);
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(1, new MountPathingGoal($this, 1.25));
		$this->goalSelector->addGoal(2, new FloatGoal($this));
		$this->goalSelector->addGoal(3, new PanicGoal($this, 1.25));
		$this->goalSelector->addGoal(4, new BreedGoal($this, 1.0));
		$this->goalSelector->addGoal(5, new TemptGoal($this, 1.2, fn(Item $item) => $this->isBreedingItem($item) || $item->getTypeId() === ItemTypeIds::CARROT_ON_A_STICK));
		$this->goalSelector->addGoal(6, new FollowParentGoal($this, 1.1));
		$this->goalSelector->addGoal(7, new RandomStrollGoal($this, 1.0));
		$this->goalSelector->addGoal(8, new LookAtPlayerGoal($this, 6.0));
		$this->goalSelector->addGoal(9, new RandomLookAroundGoal($this));
	}

	public function isBreedingItem(Item $item) : bool{
		$id = $item->getTypeId();
		return $id === ItemTypeIds::CARROT || $id === ItemTypeIds::POTATO || $id === ItemTypeIds::BEETROOT;
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		$baby = new Pig($location);
		$this->inheritFarmVariants($baby, $partner);
		return $baby;
	}

	public function isSaddled() : bool{
		return $this->saddled;
	}

	public function setSaddled(bool $saddled) : void{
		$this->saddled = $saddled;
		$this->networkPropertiesDirty = true;
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if($item->getTypeId() === ItemTypeIds::SADDLE && !$this->saddled && !$this->isBaby()){
			$this->consumeHeldItem($player);
			$this->setSaddled(true);
			$this->playMobSound(LevelSoundEvent::SADDLE);
			return true;
		}
		if($this->canShearSaddle($player, $item)){
			$this->setSaddled(false);
			$this->getWorld()->dropItem($this->location->add(0, 1.1, 0), VanillaItems::SADDLE());
			$this->playMobSound(LevelSoundEvent::UNSADDLE);
			$this->damageHeldItem($player);
			$this->getWorld()->addGameEvent($this->location, GameEvent::SHEAR, $player);
			return true;
		}
		if(parent::onInteractWithItem($player, $item)){
			return true;
		}
		return $this->saddled && !$this->isBaby() && count($this->passengers) === 0 && $this->tryRide($player);
	}

	/**
	 * Vanilla: a pig struck by lightning turns into a zombified piglin holding a golden sword (not on Peaceful). The
	 * saddle is dropped.
	 */
	public function onStruckByLightning(LightningBolt $bolt) : void{
		if($this->getWorld()->getDifficulty() === World::DIFFICULTY_PEACEFUL){
			parent::onStruckByLightning($bolt);
			return;
		}
		$zombified = new ZombifiedPiglin($this->location->asLocation());
		$zombified->setBaby($this->isBaby());
		$zombified->setMainHandItem(VanillaItems::GOLDEN_SWORD());
		if($this->saddled){
			$this->getWorld()->dropItem($this->location, VanillaItems::SADDLE());
		}
		MobConversion::convertByLightning($this, $zombified);
	}

	public function getRideableSeats() : array{
		if($this->isBaby()){
			return [];
		}
		return [$this->saddled ? RideableSeat::at(0, 0.63, 0) : RideableSeat::at(0, 0.7, 0)];
	}

	public function canBeRiddenBy(Entity $entity) : bool{
		if($this->isBaby()){
			return false;
		}
		//vanilla family types: a player when saddled, a baby undead otherwise
		return $this->saddled ? $entity instanceof Player : $entity instanceof Zombie && $entity->isBaby();
	}

	/**
	 * Vanilla pig_saddled interaction: shears (not sneaking, nobody riding it) take the saddle off.
	 */
	private function canShearSaddle(Player $player, Item $item) : bool{
		return $this->saddled && $item->getTypeId() === ItemTypeIds::SHEARS && !$player->isSneaking() && count($this->passengers) === 0;
	}

	protected function getItemInteractButtonText(Player $player, Item $item) : ?string{
		if($this->canShearSaddle($player, $item)){
			return "action.interact.removesaddle";
		}
		return $item->getTypeId() === ItemTypeIds::SADDLE && !$this->saddled && !$this->isBaby() ? "action.interact.saddle" : null;
	}

	public function canBeSteeredBy(Player $rider) : bool{
		return $this->saddled && $rider->getInventory()->getItemInHand()->getTypeId() === ItemTypeIds::CARROT_ON_A_STICK;
	}

	protected function getRiddenInput(RiderInput $input) : array{
		return [1.0, 0.0]; //walks where the rider looks
	}

	protected function getRiddenSpeed(Player $rider) : float{
		return 0.164;
	}

	protected function getBoostItemTypeId() : ?int{
		return ItemTypeIds::CARROT_ON_A_STICK;
	}

	protected function getBoostProperties() : array{
		return [60, 2];
	}

	protected function getAdultDrops() : array{
		$drops = [
			$this->cookedIfBurning(VanillaItems::RAW_PORKCHOP(), VanillaItems::COOKED_PORKCHOP())->setCount(mt_rand(1, 3) + $this->lootingBonus())
		];
		if($this->saddled){
			$drops[] = VanillaItems::SADDLE();
		}
		return $drops;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::SADDLED, $this->saddled);
	}
}
