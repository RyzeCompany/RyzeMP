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

use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\DyeColorIdMap;
use pocketmine\entity\ai\goal\BreedGoal;
use pocketmine\entity\ai\goal\EatGrassGoal;
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
use pocketmine\item\Dye;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\Shears;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\sound\ItemBreakSound;
use pocketmine\world\vibration\GameEvent;
use function mt_rand;

/**
 * Bedrock values: 8 health, movement 0.25, 0.9x1.3, bred with wheat. Sheared with shears for 1-3 wool of its
 * colour; eating grass regrows the wool (lambs don't grow faster). Dyed with any dye, but not while sheared (is_dyeable
 * comes back with the wool). Natural colours by the biome's climate (temperate_color, cold_color, warm_color): white,
 * black or brown 81.836%, the other three of white/black/gray/light gray 5% each, brown or black 3%, pink 0.164%.
 * A lamb gets the dye mix of its parents' colours when there is one (red + white = pink...), else a parent's colour
 * (combine_parent_colors). Carries a baby zombie (seat 0.975 up, 0.9 sheared, lambs 0.7 up and 0.3 back) and walks to
 * its rider's target (behavior.mount_pathing, 1.5x).
 * Loot: 1 wool when not sheared, 1-2 mutton (cooked when burning) +0-1 per Looting level.
 */
class Sheep extends Animal{
	public const TAG_COLOR = "Color"; //TAG_Byte
	public const TAG_SHEARED = "Sheared"; //TAG_Byte

	private DyeColor $color = DyeColor::WHITE;
	private bool $sheared = false;

	public static function getNetworkTypeId() : string{ return EntityIds::SHEEP; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.3, 0.9, 1.2);
	}

	public function getName() : string{
		return "Sheep";
	}

	public function getSpawnBabyChance() : int{
		return 5; //entity_spawned: 95 adult / 5 baby
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(8);
		$this->setMovementSpeed(0.25);
		parent::initEntity($nbt);
		$colorTag = $nbt->getTag(self::TAG_COLOR);
		$this->color = $colorTag !== null ?
			(DyeColorIdMap::getInstance()->fromId($nbt->getByte(self::TAG_COLOR)) ?? DyeColor::WHITE) :
			self::randomColorForClimate(ClimateVariant::forBiome($this->getWorld(), $this->location));
		$this->sheared = $nbt->getByte(self::TAG_SHEARED, 0) !== 0;
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_COLOR, DyeColorIdMap::getInstance()->toId($this->color));
		$nbt->setByte(self::TAG_SHEARED, $this->sheared ? 1 : 0);
		return $nbt;
	}

	/**
	 * Vanilla natural colour distribution of spawned sheep in temperate biomes.
	 */
	public static function randomNaturalColor() : DyeColor{
		return self::randomColorForClimate(ClimateVariant::TEMPERATE);
	}

	/**
	 * Vanilla temperate_color / cold_color / warm_color (weights out of 100000).
	 */
	private static function randomColorForClimate(ClimateVariant $climate) : DyeColor{
		[$main, $second, $third, $fourth, $rare] = match($climate){
			ClimateVariant::TEMPERATE => [DyeColor::WHITE, DyeColor::BLACK, DyeColor::LIGHT_GRAY, DyeColor::GRAY, DyeColor::BROWN],
			ClimateVariant::COLD => [DyeColor::BLACK, DyeColor::LIGHT_GRAY, DyeColor::GRAY, DyeColor::WHITE, DyeColor::BROWN],
			ClimateVariant::WARM => [DyeColor::BROWN, DyeColor::GRAY, DyeColor::LIGHT_GRAY, DyeColor::WHITE, DyeColor::BLACK]
		};
		$r = mt_rand(0, 99999);
		return match(true){
			$r < 5000 => $second,
			$r < 10000 => $third,
			$r < 15000 => $fourth,
			$r < 18000 => $rare,
			$r < 18164 => DyeColor::PINK,
			default => $main
		};
	}

	/**
	 * Colour of a lamb (vanilla combine_parent_colors, from the two-dye crafting recipes): the mix of its parents'
	 * colours when two dyes of those colours craft into another one, else one of the parents' colours at random.
	 */
	public static function mixParentColors(DyeColor $a, DyeColor $b) : DyeColor{
		return self::dyeMix($a, $b) ?? self::dyeMix($b, $a) ?? (mt_rand(0, 1) === 0 ? $a : $b);
	}

	private static function dyeMix(DyeColor $a, DyeColor $b) : ?DyeColor{
		return match([$a, $b]){
			[DyeColor::RED, DyeColor::WHITE] => DyeColor::PINK,
			[DyeColor::BLACK, DyeColor::WHITE] => DyeColor::GRAY,
			[DyeColor::GRAY, DyeColor::WHITE] => DyeColor::LIGHT_GRAY,
			[DyeColor::BLUE, DyeColor::WHITE] => DyeColor::LIGHT_BLUE,
			[DyeColor::GREEN, DyeColor::WHITE] => DyeColor::LIME,
			[DyeColor::BLUE, DyeColor::GREEN] => DyeColor::CYAN,
			[DyeColor::BLUE, DyeColor::RED] => DyeColor::PURPLE,
			[DyeColor::RED, DyeColor::YELLOW] => DyeColor::ORANGE,
			[DyeColor::PURPLE, DyeColor::PINK] => DyeColor::MAGENTA,
			default => null
		};
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		$this->goalSelector->addGoal(1, new PanicGoal($this, 1.25));
		$this->goalSelector->addGoal(2, new MountPathingGoal($this, 1.5));
		$this->goalSelector->addGoal(3, new BreedGoal($this, 1.0));
		$this->goalSelector->addGoal(4, new TemptGoal($this, 1.25, fn(Item $item) => $this->isBreedingItem($item)));
		$this->goalSelector->addGoal(5, new FollowParentGoal($this, 1.1));
		$this->goalSelector->addGoal(6, new EatGrassGoal($this));
		$this->goalSelector->addGoal(7, new RandomStrollGoal($this, 0.8));
		$this->goalSelector->addGoal(8, new LookAtPlayerGoal($this, 6.0));
		$this->goalSelector->addGoal(9, new RandomLookAroundGoal($this));
	}

	public function isBreedingItem(Item $item) : bool{
		return $item->getTypeId() === ItemTypeIds::WHEAT;
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		$baby = new Sheep($location);
		$baby->setColor($partner instanceof Sheep ? self::mixParentColors($this->color, $partner->getColor()) : $this->color);
		return $baby;
	}

	public function getRideableSeats() : array{
		if($this->isBaby()){
			return [RideableSeat::at(0, 0.7, -0.3)];
		}
		return [RideableSeat::at(0, $this->sheared ? 0.9 : 0.975, 0)];
	}

	public function canBeRiddenBy(Entity $entity) : bool{
		return $entity instanceof Zombie && $entity->isBaby(); //family baby_undead, lambs too
	}

	public function getColor() : DyeColor{
		return $this->color;
	}

	public function setColor(DyeColor $color) : void{
		$this->color = $color;
		$this->networkPropertiesDirty = true;
	}

	public function isSheared() : bool{
		return $this->sheared;
	}

	public function setSheared(bool $sheared) : void{
		$this->sheared = $sheared;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Called by the eat grass goal when the sheep has eaten.
	 */
	public function onEatGrass() : void{
		//on_eat_block only regrows the wool (and makes it dyeable again): lambs don't grow faster as in Java
		$this->setSheared(false);
	}

	/**
	 * Shears the sheep, dropping 1-3 wool. Returns false if it can't be sheared now.
	 */
	public function shear() : bool{
		if($this->sheared || $this->isBaby() || !$this->isAlive()){
			return false;
		}
		$this->setSheared(true);
		$wool = VanillaBlocks::WOOL()->setColor($this->color)->asItem()->setCount(mt_rand(1, 3));
		$this->getWorld()->dropItem($this->location->add(0, 1, 0), $wool);
		$this->playMobSound(LevelSoundEvent::SHEAR);
		return true;
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if($item instanceof Shears){
			if(!$this->shear()){
				return false;
			}
			$this->getWorld()->addGameEvent($this->location, GameEvent::SHEAR, $player);
			if($player->hasFiniteResources()){
				$item->applyDamage(1);
				if($item->isBroken()){
					$player->broadcastSound(new ItemBreakSound());
				}
				$player->getInventory()->setItemInHand($item);
			}
			return true;
		}
		if($item instanceof Dye){
			//is_dyeable is removed by shearing until the sheep eats grass
			if($item->getColor() === $this->color || $this->sheared){
				return false;
			}
			$this->setColor($item->getColor());
			$this->consumeHeldItem($player);
			return true;
		}
		return parent::onInteractWithItem($player, $item);
	}

	protected function getAdultDrops() : array{
		$drops = [];
		if(!$this->sheared){
			$drops[] = VanillaBlocks::WOOL()->setColor($this->color)->asItem();
		}
		$drops[] = $this->cookedIfBurning(VanillaItems::RAW_MUTTON(), VanillaItems::COOKED_MUTTON())->setCount(mt_rand(1, 2) + $this->lootingBonus());
		return $drops;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setByte(EntityMetadataProperties::COLOR, DyeColorIdMap::getInstance()->toId($this->color));
		$properties->setGenericFlag(EntityMetadataFlags::SHEARED, $this->sheared);
	}
}
