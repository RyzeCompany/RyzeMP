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
use function count;

/**
 * Copying a banner (client-hardcoded multi recipe): a banner with patterns and a blank banner of the same base colour
 * make two banners with those patterns. On Bedrock the patterned banner must come before the blank one in the grid
 * (to the left of it or above it, Minecraft Wiki), and the recipe shows two banners as its result, so both inputs are
 * used up.
 */
final class BannerDuplicateRecipe extends MultiCraftingRecipe{
	/** UUID the client uses for this multi recipe (BedrockProtocol MultiRecipe::TYPE_BANNER_DUPLICATE) */
	public const MULTI_RECIPE_UUID = "b5c5d105-75a2-4076-af2b-923ea2bf4bf0";

	public function getMultiRecipeUuid() : string{
		return self::MULTI_RECIPE_UUID;
	}

	public function getIngredientList() : array{
		//representative only: the real ingredients depend on the grid (see getIngredientListFor())
		$banner = new ExactRecipeIngredient(VanillaBlocks::BANNER()->asItem());
		return [$banner, $banner];
	}

	protected function computeResults(CraftingGrid $grid) : ?array{
		$result = self::computeResult($grid->getContents());
		return $result !== null ? [$result] : null;
	}

	/**
	 * Returns the copies made from the given grid contents (slot => item, in grid order), or null if they aren't a
	 * patterned banner followed by a blank banner of the same colour.
	 *
	 * @internal exposed for tests
	 * @param Item[] $items
	 */
	public static function computeResult(array $items) : ?Banner{
		$patterned = null;
		$blank = null;
		foreach($items as $item){
			if($item->isNull()){
				continue;
			}
			if(!$item instanceof Banner){
				return null;
			}
			if(count($item->getPatterns()) > 0){
				if($patterned !== null){
					return null;
				}
				$patterned = $item;
			}else{
				if($blank !== null || $patterned === null){
					return null;
				}
				$blank = $item;
			}
		}
		if($patterned === null || $blank === null || $patterned->getColor() !== $blank->getColor()){
			return null;
		}
		$result = clone $patterned;
		$result->setCount(2);
		return $result;
	}
}
