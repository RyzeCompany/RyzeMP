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

use pocketmine\entity\utils\MobBiomeInfo;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\world\World;
use function in_array;

/**
 * Climate variant of cows, pigs, chickens and thrown eggs (values of the actor property minecraft:climate_variant, in
 * definition order). Farm animals take it from the biome they spawn in (biome tags
 * spawns_warm_variant_farm_animals / spawns_cold_variant_farm_animals), babies from a parent; chickens lay the egg of
 * their climate (egg, brown egg, blue egg) and a thrown brown or blue egg hatches a warm or cold chick.
 */
enum ClimateVariant : string{
	case TEMPERATE = "temperate";
	case WARM = "warm";
	case COLD = "cold";

	public const WARM_BIOME_TAG = "spawns_warm_variant_farm_animals";
	public const COLD_BIOME_TAG = "spawns_cold_variant_farm_animals";

	/**
	 * Climate of the biome tags: warm first, then cold (vanilla entity_spawned first_valid of cow, pig and chicken).
	 *
	 * @param string[] $tags
	 * @phpstan-param list<string> $tags
	 */
	public static function fromBiomeTags(array $tags) : self{
		if(in_array(self::WARM_BIOME_TAG, $tags, true)){
			return self::WARM;
		}
		return in_array(self::COLD_BIOME_TAG, $tags, true) ? self::COLD : self::TEMPERATE;
	}

	public static function forBiome(World $world, Vector3 $pos) : self{
		return self::fromBiomeTags(MobBiomeInfo::getTags($world, $pos));
	}

	/**
	 * Climate of an egg item: brown eggs are warm, blue eggs cold, the others temperate.
	 */
	public static function fromEggItem(Item $item) : self{
		return match($item->getTypeId()){
			ItemTypeIds::BROWN_EGG => self::WARM,
			ItemTypeIds::BLUE_EGG => self::COLD,
			default => self::TEMPERATE
		};
	}

	/**
	 * Egg laid by a chicken of this climate (vanilla chicken_adult spawn_entity).
	 */
	public function getEggItem() : Item{
		return match($this){
			self::TEMPERATE => VanillaItems::EGG(),
			self::WARM => VanillaItems::BROWN_EGG(),
			self::COLD => VanillaItems::BLUE_EGG()
		};
	}
}
