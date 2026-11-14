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

use pocketmine\block\VanillaBlocks;
use pocketmine\item\Banner;
use pocketmine\item\Item;
use pocketmine\item\Shield;
use pocketmine\item\VanillaItems;
use function count;

/**
 * Decorating a shield (client-hardcoded multi recipe "shield_banner", UUID ...00c8): a shield without patterns and a
 * banner make the shield with the banner's base colour and patterns. Both are used up; the shield keeps its damage,
 * name and enchantments (Java ShieldDecorationRecipe). A shield that already has patterns can't take another banner.
 */
final class ShieldDecorationRecipe extends MultiCraftingRecipe{
	/**
	 * UUID the client uses for this multi recipe. BedrockProtocol doesn't name it; it is the only one of the 14
	 * special_hardcoded.json UUIDs left once the others are matched to the recipe names in BDS (shield_banner_recipeId).
	 */
	public const MULTI_RECIPE_UUID = "00000000-0000-0000-0000-0000000000c8";

	public function getMultiRecipeUuid() : string{
		return self::MULTI_RECIPE_UUID;
	}

	public function getIngredientList() : array{
		//representative only: the real ingredients depend on the grid (see getIngredientListFor())
		return [new ExactRecipeIngredient(VanillaItems::SHIELD()), new ExactRecipeIngredient(VanillaBlocks::BANNER()->asItem())];
	}

	protected function computeResults(CraftingGrid $grid) : ?array{
		$result = self::computeResult($grid->getContents());
		return $result !== null ? [$result] : null;
	}

	/**
	 * Returns the decorated shield for the given grid contents, or null if they aren't one undecorated shield and one
	 * banner.
	 *
	 * @internal exposed for tests
	 * @param Item[] $items
	 */
	public static function computeResult(array $items) : ?Shield{
		$shield = null;
		$banner = null;
		foreach($items as $item){
			if($item->isNull()){
				continue;
			}
			if($item instanceof Shield && $shield === null && count($item->getPatterns()) === 0){
				$shield = $item;
			}elseif($item instanceof Banner && $banner === null){
				$banner = $item;
			}else{
				return null;
			}
		}
		if($shield === null || $banner === null){
			return null;
		}
		$result = clone $shield;
		$result->setCount(1);
		return $result->setBaseColor($banner->getColor())->setPatterns($banner->getPatterns());
	}
}
