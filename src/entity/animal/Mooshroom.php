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

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Location;
use pocketmine\entity\object\LightningBolt;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\Shears;
use pocketmine\item\SuspiciousStewType;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\particle\HugeExplodeParticle;
use pocketmine\world\sound\ItemBreakSound;
use pocketmine\world\vibration\GameEvent;
use function mt_rand;

/**
 * Bedrock values (entities/mooshroom.json): a cow (10 health, 0.9x1.3, movement 0.25, bred with wheat, milked with a
 * bucket) that is red (VARIANT 0) or brown (1). A bowl gives mushroom stew; a brown mooshroom fed a flower gives one
 * suspicious stew of that flower next (MARK_VARIANT = the flower, -1 for none). Shears turn it into a cow and drop 5
 * mushrooms of its colour. Two mooshrooms of the same colour have a 1/1024 chance of a baby of the other colour.
 * Loot (loot_tables/entities/mooshroom.json): 0-2 leather and 1-3 beef (cooked when burning), +0-1 each per Looting
 * level. Lightning switches the colour (red <-> brown).
 */
class Mooshroom extends Cow{
	public const TAG_VARIANT = "Variant"; //TAG_Int
	public const TAG_STEW_FLOWER = "MarkVariant"; //TAG_Int

	public const VARIANT_RED = 0;
	public const VARIANT_BROWN = 1;

	private int $variant = self::VARIANT_RED;
	private ?SuspiciousStewType $stewFlower = null;

	public static function getNetworkTypeId() : string{ return EntityIds::MOOSHROOM; }

	public function getName() : string{
		return "Mooshroom";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->variant = $nbt->getInt(self::TAG_VARIANT, self::VARIANT_RED) % 2;
		$flower = $nbt->getInt(self::TAG_STEW_FLOWER, -1);
		$this->stewFlower = SuspiciousStewType::cases()[$flower] ?? null;
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_VARIANT, $this->variant);
		$nbt->setInt(self::TAG_STEW_FLOWER, $this->getStewFlowerIndex());
		return $nbt;
	}

	public function getVariant() : int{
		return $this->variant;
	}

	public function setVariant(int $variant) : void{
		$this->variant = $variant;
		$this->networkPropertiesDirty = true;
	}

	private function getStewFlowerIndex() : int{
		foreach(SuspiciousStewType::cases() as $i => $case){
			if($case === $this->stewFlower){
				return $i;
			}
		}
		return -1;
	}

	/**
	 * Suspicious stew kind given by a flower item, or null if it isn't one.
	 */
	public static function stewTypeForFlower(Item $item) : ?SuspiciousStewType{
		$id = $item->getTypeId();
		return match($id){
			VanillaBlocks::POPPY()->asItem()->getTypeId() => SuspiciousStewType::POPPY,
			VanillaBlocks::CORNFLOWER()->asItem()->getTypeId() => SuspiciousStewType::CORNFLOWER,
			VanillaBlocks::RED_TULIP()->asItem()->getTypeId(), VanillaBlocks::ORANGE_TULIP()->asItem()->getTypeId(),
			VanillaBlocks::WHITE_TULIP()->asItem()->getTypeId(), VanillaBlocks::PINK_TULIP()->asItem()->getTypeId() => SuspiciousStewType::TULIP,
			VanillaBlocks::AZURE_BLUET()->asItem()->getTypeId() => SuspiciousStewType::AZURE_BLUET,
			VanillaBlocks::LILY_OF_THE_VALLEY()->asItem()->getTypeId() => SuspiciousStewType::LILY_OF_THE_VALLEY,
			VanillaBlocks::DANDELION()->asItem()->getTypeId() => SuspiciousStewType::DANDELION,
			VanillaBlocks::BLUE_ORCHID()->asItem()->getTypeId() => SuspiciousStewType::BLUE_ORCHID,
			VanillaBlocks::ALLIUM()->asItem()->getTypeId() => SuspiciousStewType::ALLIUM,
			VanillaBlocks::OXEYE_DAISY()->asItem()->getTypeId() => SuspiciousStewType::OXEYE_DAISY,
			VanillaBlocks::WITHER_ROSE()->asItem()->getTypeId() => SuspiciousStewType::WITHER_ROSE,
			VanillaBlocks::TORCHFLOWER()->asItem()->getTypeId() => SuspiciousStewType::TORCHFLOWER,
			VanillaBlocks::OPEN_EYEBLOSSOM()->asItem()->getTypeId() => SuspiciousStewType::OPEN_EYEBLOSSOM,
			VanillaBlocks::CLOSED_EYEBLOSSOM()->asItem()->getTypeId() => SuspiciousStewType::CLOSED_EYEBLOSSOM,
			default => null
		};
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		$baby = new Mooshroom($location);
		if($partner instanceof Mooshroom && $partner->getVariant() !== $this->variant){
			$baby->setVariant(mt_rand(0, 1) === 0 ? $this->variant : $partner->getVariant());
		}else{
			$baby->setVariant(mt_rand(0, 1023) === 0 ? 1 - $this->variant : $this->variant);
		}
		return $baby;
	}

	/**
	 * Vanilla: lightning turns a red mooshroom brown and a brown one red (once per bolt), without hurting it.
	 */
	public function onStruckByLightning(LightningBolt $bolt) : void{
		$this->setVariant($this->variant === self::VARIANT_RED ? self::VARIANT_BROWN : self::VARIANT_RED);
		$this->playMobSound(LevelSoundEvent::CONVERT_MOOSHROOM);
	}

	/**
	 * Turns the mooshroom into a cow, dropping 5 mushrooms. Returns the cow, or null if it can't be sheared now.
	 */
	public function shear() : ?Cow{
		if($this->isBaby() || !$this->isAlive()){
			return null;
		}
		$world = $this->getWorld();
		$cow = new Cow($this->location->asLocation());
		//entity_transformed: the default climate and sound
		$cow->setClimateVariant(ClimateVariant::TEMPERATE);
		$cow->setSoundVariant(0);
		$cow->setHealth($this->getHealth());
		if($this->nameTag !== ""){
			$cow->setNameTag($this->nameTag);
		}
		$cow->setPersistent($this->persistent);
		$cow->spawnToAll();
		$mushroom = $this->variant === self::VARIANT_BROWN ? VanillaBlocks::BROWN_MUSHROOM() : VanillaBlocks::RED_MUSHROOM();
		$world->dropItem($this->location->add(0, 1, 0), $mushroom->asItem()->setCount(5));
		$world->addParticle($this->location->add(0, 0.5, 0), new HugeExplodeParticle());
		$this->playMobSound(LevelSoundEvent::SHEAR);
		$this->flagForDespawn();
		return $cow;
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if($item->getTypeId() === ItemTypeIds::BOWL && !$this->isBaby()){
			if($this->stewFlower !== null){
				$this->exchangeHeldItem($player, VanillaItems::SUSPICIOUS_STEW()->setType($this->stewFlower));
				$this->stewFlower = null;
				$this->networkPropertiesDirty = true;
				$this->playMobSound(LevelSoundEvent::MILK_SUSPICIOUSLY);
			}else{
				$this->exchangeHeldItem($player, VanillaItems::MUSHROOM_STEW());
				$this->playMobSound(LevelSoundEvent::MILK);
			}
			return true;
		}
		if($item instanceof Shears){
			if($this->shear() === null){
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
		//cow_adult: a brown adult takes any flower but the one it was last fed (is_mark_variant != that flower)
		if($this->variant === self::VARIANT_BROWN && !$this->isBaby() && ($flower = self::stewTypeForFlower($item)) !== null && $flower !== $this->stewFlower){
			$this->consumeHeldItem($player);
			$this->stewFlower = $flower;
			$this->networkPropertiesDirty = true;
			$this->playMobSound(LevelSoundEvent::EAT);
			return true;
		}
		return parent::onInteractWithItem($player, $item);
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->variant);
		$properties->setInt(EntityMetadataProperties::MARK_VARIANT, $this->getStewFlowerIndex());
	}
}
