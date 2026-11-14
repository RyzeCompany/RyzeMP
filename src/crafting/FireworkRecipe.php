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

namespace pocketmine\crafting;

use pocketmine\block\MobHead;
use pocketmine\item\FireworkRocketExplosion;
use pocketmine\item\FireworkRocketType;
use pocketmine\item\FireworkStar;
use pocketmine\item\Item;
use pocketmine\item\ItemBlock;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use function count;

/**
 * Vanilla's hardcoded firework crafting (one client multi recipe covering the three Java recipes):
 * - rocket: 1 paper + 1-3 gunpowder (flight duration) + any number of firework stars -> 3 rockets
 * - star: 1 gunpowder + 1-8 dyes + at most one shape item (fire charge = large ball, gold nugget = star,
 *   any head = creeper, feather = burst), at most one diamond (trail) and one glowstone dust (twinkle)
 * - fade: 1 firework star + 1-8 dyes -> the same star with those dyes as fade colours
 * Grid positions don't matter.
 */
final class FireworkRecipe extends MultiCraftingRecipe{
	/** UUID the client uses for the firework multi recipe (BedrockData special_hardcoded.json) */
	public const MULTI_RECIPE_UUID = "00000000-0000-0000-0000-000000000002";

	private const MAX_GUNPOWDER = 3;
	private const ROCKET_COUNT = 3;

	public function getMultiRecipeUuid() : string{
		return self::MULTI_RECIPE_UUID;
	}

	public function getIngredientList() : array{
		//representative only: the real ingredients depend on the grid (see getIngredientListFor())
		return [new ExactRecipeIngredient(VanillaItems::PAPER()), new ExactRecipeIngredient(VanillaItems::GUNPOWDER())];
	}

	protected function computeResults(CraftingGrid $grid) : ?array{
		$result = self::computeResult($grid->getContents());
		return $result !== null ? [$result] : null;
	}

	private static function shapeOf(Item $item) : ?FireworkRocketType{
		return match($item->getTypeId()){
			ItemTypeIds::FIRE_CHARGE => FireworkRocketType::LARGE_BALL,
			ItemTypeIds::GOLD_NUGGET => FireworkRocketType::STAR,
			ItemTypeIds::FEATHER => FireworkRocketType::BURST,
			default => $item instanceof ItemBlock && $item->getBlock() instanceof MobHead ? FireworkRocketType::CREEPER : null
		};
	}

	/**
	 * Returns the crafted item for the given grid contents (empty slots excluded), or null if they make no firework
	 * recipe.
	 *
	 * @param Item[] $items
	 */
	public static function computeResult(array $items) : ?Item{
		$paper = 0;
		$gunpowder = 0;
		$diamonds = 0;
		$glowstone = 0;
		$shape = null;
		$shapeItems = 0;
		$dyes = [];
		/** @var FireworkStar[] $stars */
		$stars = [];
		foreach($items as $item){
			if($item->isNull()){
				continue;
			}
			if(($dyeColor = DyeItems::colorOf($item)) !== null){
				$dyes[] = $dyeColor;
			}elseif($item instanceof FireworkStar){
				$stars[] = $item;
			}elseif(($itemShape = self::shapeOf($item)) !== null){
				$shape = $itemShape;
				$shapeItems++;
			}else{
				switch($item->getTypeId()){
					case ItemTypeIds::PAPER: $paper++; break;
					case ItemTypeIds::GUNPOWDER: $gunpowder++; break;
					case ItemTypeIds::DIAMOND: $diamonds++; break;
					case ItemTypeIds::GLOWSTONE_DUST: $glowstone++; break;
					default: return null;
				}
			}
		}

		if($paper === 1){
			//rocket
			if($gunpowder < 1 || $gunpowder > self::MAX_GUNPOWDER || count($dyes) > 0 || $shapeItems > 0 || $diamonds > 0 || $glowstone > 0){
				return null;
			}
			$explosions = [];
			foreach($stars as $star){
				$explosions[] = $star->getExplosion();
			}
			return VanillaItems::FIREWORK_ROCKET()
				->setFlightTimeMultiplier($gunpowder)
				->setExplosions($explosions)
				->setCount(self::ROCKET_COUNT);
		}
		if($paper !== 0 || count($dyes) === 0){
			return null;
		}

		if($gunpowder === 1){
			//new star
			if(count($stars) > 0 || $shapeItems > 1 || $diamonds > 1 || $glowstone > 1){
				return null;
			}
			return VanillaItems::FIREWORK_STAR()->setExplosion(new FireworkRocketExplosion(
				$shape ?? FireworkRocketType::SMALL_BALL,
				$dyes,
				[],
				twinkle: $glowstone === 1,
				trail: $diamonds === 1
			));
		}

		if($gunpowder === 0 && count($stars) === 1 && $shapeItems === 0 && $diamonds === 0 && $glowstone === 0){
			//fade colours
			$star = clone $stars[0];
			$explosion = $star->getExplosion();
			return $star->setCount(1)->setExplosion(new FireworkRocketExplosion(
				$explosion->getType(),
				$explosion->getColors(),
				$dyes,
				$explosion->willTwinkle(),
				$explosion->getTrail()
			));
		}

		return null;
	}
}
