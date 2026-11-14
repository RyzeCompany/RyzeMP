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

use pocketmine\block\tile\DecoratedPot as TileDecoratedPot;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\PotterySherd;
use pocketmine\item\PotterySherdType;

/**
 * Vanilla's special decorated pot recipe: 4 bricks or pottery sherds in a diamond shape, each one becoming the pattern
 * on one side of the pot (top = back, left = left, right = right, bottom = front).
 * Clients know it as a multi recipe with a fixed UUID; the plain 4-brick pot also has a normal shaped recipe.
 */
final class DecoratedPotRecipe implements CraftingRecipe{
	/** UUID the client uses for this hardcoded multi recipe (from BedrockData special_hardcoded.json) */
	public const MULTI_RECIPE_UUID = "685a742a-c42e-4a4e-88ea-5eb83fc98e5b";

	private const SHERDS_TAG = "minecraft:decorated_pot_sherds";

	/** grid positions [x, y] of the back, left, right and front sides */
	private const SIDE_POSITIONS = [
		TileDecoratedPot::SIDE_BACK => [1, 0],
		TileDecoratedPot::SIDE_LEFT => [0, 1],
		TileDecoratedPot::SIDE_RIGHT => [2, 1],
		TileDecoratedPot::SIDE_FRONT => [1, 2],
	];

	public function getIngredientList() : array{
		$ingredient = new TagWildcardRecipeIngredient(self::SHERDS_TAG);
		return [$ingredient, $ingredient, $ingredient, $ingredient];
	}

	private static function isSherdOrBrick(Item $item) : bool{
		return $item instanceof PotterySherd || $item->getTypeId() === ItemTypeIds::BRICK;
	}

	public function matchesCraftingGrid(CraftingGrid $grid) : bool{
		if($grid->getGridWidth() < 3 || $grid->getRecipeWidth() !== 3 || $grid->getRecipeHeight() !== 3){
			return false;
		}
		for($y = 0; $y < 3; ++$y){
			for($x = 0; $x < 3; ++$x){
				$item = $grid->getIngredient($x, $y);
				$isSide = ($x === 1) !== ($y === 1);
				if($isSide ? !self::isSherdOrBrick($item) : !$item->isNull()){
					return false;
				}
			}
		}
		return true;
	}

	public function getResultsFor(CraftingGrid $grid) : array{
		$sherds = [null, null, null, null];
		if($grid->getRecipeWidth() === 3 && $grid->getRecipeHeight() === 3){
			foreach(self::SIDE_POSITIONS as $side => [$x, $y]){
				$item = $grid->getIngredient($x, $y);
				$sherds[$side] = $item instanceof PotterySherd ? $item->getSherdType() : null;
			}
		}
		return [self::createPot($sherds)];
	}

	/**
	 * @param (PotterySherdType|null)[] $sherds
	 */
	public static function createPot(array $sherds) : Item{
		return VanillaBlocks::DECORATED_POT()->asItemWithSherds($sherds);
	}
}
