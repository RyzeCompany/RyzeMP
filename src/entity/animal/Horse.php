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

use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use function mt_rand;

/**
 * Bedrock values (entities/horse.json): 15-30 health, movement 0.1125-0.3375, jump strength 0.4-1.0, 1.4x1.6.
 * Coat (VARIANT 0-6: white, creamy, chestnut, brown, black, gray, dark brown) and markings (MARK_VARIANT 0-4).
 * Bred (tamed) with golden apples and golden carrots; with a donkey it makes a mule. Babies take a parent's coat
 * and either parent's markings (mutation_factor: 1/9 chance of a random coat, 1/5 of random markings). Wears horse
 * armor (see {@link AbstractHorse}).
 * Loot (loot_tables/entities/horse.json): 0-2 leather (+0-1 per Looting level); XP 1-3.
 */
class Horse extends AbstractHorse{
	public const TAG_VARIANT = "Variant"; //TAG_Int
	public const TAG_MARK_VARIANT = "MarkVariant"; //TAG_Int

	private int $coat = 0;
	private int $markings = 0;

	public function hasArmorSlot() : bool{
		return true;
	}

	public static function getNetworkTypeId() : string{ return EntityIds::HORSE; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.6, 1.4, 1.52);
	}

	public function getName() : string{
		return "Horse";
	}

	protected function getBabySizeInfo() : ?EntitySizeInfo{
		return new EntitySizeInfo(2.24, 1.96, 2.128); //baby collision box 1.96 x 2.24 at scale 0.5
	}

	public function getSpawnBabyChance() : int{
		return 20; //entity_spawned: 80 adult / 20 baby
	}

	protected function randomStats() : array{
		return [mt_rand(15, 30), self::randomRange(0.1125, 0.3375), self::randomRange(0.4, 1.0)];
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->coat = $nbt->getTag(self::TAG_VARIANT) !== null ? $nbt->getInt(self::TAG_VARIANT) % 7 : mt_rand(0, 6);
		$this->markings = $nbt->getTag(self::TAG_MARK_VARIANT) !== null ? $nbt->getInt(self::TAG_MARK_VARIANT) % 5 : mt_rand(0, 4);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_VARIANT, $this->coat);
		$nbt->setInt(self::TAG_MARK_VARIANT, $this->markings);
		return $nbt;
	}

	public function getCoat() : int{ return $this->coat; }

	public function getMarkings() : int{ return $this->markings; }

	public function setAppearance(int $coat, int $markings) : void{
		$this->coat = $coat;
		$this->markings = $markings;
		$this->networkPropertiesDirty = true;
	}

	protected function blendsMovementAndJump() : bool{
		return true; //parent_centric_attribute_blending: health, movement, jump strength
	}

	protected function breedsWith(Animal $other) : bool{
		return $other instanceof Horse || $other instanceof Donkey;
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		if($partner instanceof Donkey){
			$baby = new Mule($location);
		}else{
			$baby = new Horse($location);
			$other = $partner instanceof Horse ? $partner : $this;
			$coat = mt_rand(0, 8) === 0 ? mt_rand(0, 6) : (mt_rand(0, 1) === 0 ? $this->coat : $other->getCoat());
			$markings = mt_rand(0, 4) === 0 ? mt_rand(0, 4) : (mt_rand(0, 1) === 0 ? $this->markings : $other->getMarkings());
			$baby->setAppearance($coat, $markings);
		}
		$this->inheritStats($baby, $partner);
		return $baby;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->coat);
		$properties->setInt(EntityMetadataProperties::MARK_VARIANT, $this->markings);
	}
}
