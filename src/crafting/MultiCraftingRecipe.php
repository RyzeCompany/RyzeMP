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

use pocketmine\item\Item;
use pocketmine\player\Player;

/**
 * A crafting table recipe whose logic is hardcoded in the Bedrock client (a "multi recipe", BedrockData
 * special_hardcoded.json). Only its UUID and network ID are sent to the client; the result depends on what is in the
 * crafting grid, so the server computes it from the grid as well.
 *
 * Each non-empty grid slot is consumed once per repetition, so the ingredients of a craft are the grid contents
 * themselves ({@link MultiCraftingRecipe::getIngredientListFor()}).
 *
 * Register implementations with {@link CraftingManager::registerSpecialCraftingRecipe()}.
 */
abstract class MultiCraftingRecipe implements CraftingRecipe{

	/**
	 * Returns the UUID the client knows this hardcoded recipe by.
	 */
	abstract public function getMultiRecipeUuid() : string;

	/**
	 * Returns the results of crafting with the given grid contents, or null if the grid doesn't match this recipe.
	 *
	 * @return Item[]|null
	 * @phpstan-return non-empty-list<Item>|null
	 */
	abstract protected function computeResults(CraftingGrid $grid) : ?array;

	public function matchesCraftingGrid(CraftingGrid $grid) : bool{
		return $this->computeResults($grid) !== null;
	}

	public function getResultsFor(CraftingGrid $grid) : array{
		return $this->computeResults($grid) ?? [];
	}

	/**
	 * Called once a craft with this recipe went through, for results that need more than the items themselves (e.g.
	 * the data of a new map). $player is the crafting player, or null when a crafter block crafted.
	 *
	 * @param Item[] $outputs the items created by the craft
	 * @phpstan-param list<Item> $outputs
	 */
	public function onCraftExecuted(array $outputs, ?Player $player = null) : void{

	}

	/**
	 * Returns the ingredients consumed by one craft with the given grid contents: one of each non-empty slot.
	 *
	 * @return RecipeIngredient[]
	 * @phpstan-return list<RecipeIngredient>
	 */
	public function getIngredientListFor(CraftingGrid $grid) : array{
		$ingredients = [];
		foreach($grid->getContents() as $item){
			$single = clone $item;
			$ingredients[] = new ExactRecipeIngredient($single->setCount(1));
		}
		return $ingredients;
	}
}
