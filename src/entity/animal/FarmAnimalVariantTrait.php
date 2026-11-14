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

use pocketmine\entity\Entity;
use pocketmine\entity\property\EntityPropertyRegistry;
use pocketmine\nbt\tag\CompoundTag;
use function count;
use function mt_rand;

/**
 * Climate and sound variants of cows, pigs and chickens (actor properties minecraft:climate_variant and
 * minecraft:sound_variant, synced to the client). Vanilla events:
 * - entity_spawned: the climate of the biome; adults get a random sound variant, spawned babies keep the default one.
 * - entity_born: the climate of a parent (offspring property_inheritance) and a random sound variant.
 * - entity_transformed (a sheared mooshroom): the defaults.
 *
 * Both are chosen once and saved; nothing runs per tick. The using class defines TAG_CLIMATE_VARIANT,
 * TAG_SOUND_VARIANT and SOUND_VARIANTS. Subclasses whose network type has no such properties (mooshrooms) keep the
 * defaults.
 *
 * @internal
 */
trait FarmAnimalVariantTrait{

	private function hasFarmVariants() : bool{
		return EntityPropertyRegistry::getInstance()->getIndex(static::getNetworkTypeId(), "minecraft:climate_variant") !== null;
	}

	private function initFarmVariants(CompoundTag $nbt) : void{
		if(!$this->hasFarmVariants()){
			return;
		}
		//entity_spawned only fires for new mobs: a saved one (it always has a position) without the tags, e.g. from
		//before variants existed, keeps the property defaults like in vanilla
		$saved = $nbt->getTag(Entity::TAG_POS) !== null;
		$climate = ClimateVariant::tryFrom($nbt->getString(self::TAG_CLIMATE_VARIANT, "")) ?? ($saved ? ClimateVariant::TEMPERATE : ClimateVariant::forBiome($this->getWorld(), $this->location));
		$this->setActorProperty("minecraft:climate_variant", $climate->value);
		$sound = $nbt->getInt(self::TAG_SOUND_VARIANT, -1);
		$this->setActorProperty("minecraft:sound_variant", $sound >= 0 && $sound < count(self::SOUND_VARIANTS) ? $sound : ($saved ? 0 : mt_rand(0, count(self::SOUND_VARIANTS) - 1)));
	}

	private function saveFarmVariants(CompoundTag $nbt) : void{
		if($this->hasFarmVariants()){
			$nbt->setString(self::TAG_CLIMATE_VARIANT, $this->getClimateVariant()->value);
			$nbt->setInt(self::TAG_SOUND_VARIANT, $this->getSoundVariant());
		}
	}

	/**
	 * Gives a baby made by breeding the climate of one of its parents (it already has a random sound variant).
	 */
	private function inheritFarmVariants(Animal $baby, Animal $partner) : void{
		if($baby instanceof self && $baby->hasFarmVariants()){
			$parent = $partner instanceof self && mt_rand(0, 1) === 0 ? $partner : $this;
			$baby->setClimateVariant($parent->getClimateVariant());
		}
	}

	public function getClimateVariant() : ClimateVariant{
		return ClimateVariant::cases()[$this->getActorProperty("minecraft:climate_variant")] ?? ClimateVariant::TEMPERATE;
	}

	public function setClimateVariant(ClimateVariant $climate) : void{
		if($this->hasFarmVariants()){
			$this->setActorProperty("minecraft:climate_variant", $climate->value);
		}
	}

	/**
	 * Returns the index of the sound variant in SOUND_VARIANTS (0: default).
	 */
	public function getSoundVariant() : int{
		return $this->getActorProperty("minecraft:sound_variant");
	}

	public function setSoundVariant(int $soundVariant) : void{
		if($this->hasFarmVariants() && $soundVariant >= 0 && $soundVariant < count(self::SOUND_VARIANTS)){
			$this->setActorProperty("minecraft:sound_variant", $soundVariant);
		}
	}

	public function makeSpawnBaby() : bool{
		if(!parent::makeSpawnBaby()){
			return false;
		}
		//entity_spawned as a baby: no randomize_sound_variant
		$this->setSoundVariant(0);
		return true;
	}
}
