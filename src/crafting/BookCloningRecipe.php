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
use pocketmine\item\VanillaItems;
use pocketmine\item\WritableBook;
use pocketmine\item\WrittenBook;

/**
 * Copying a written book (client-hardcoded multi recipe): a written book and 1-8 book and quills make one copy per
 * book and quill, one generation further ("copy of original", "copy of copy"). Only originals and copies of originals
 * can be copied. The written book itself is not used up and stays in the grid (Minecraft Wiki, Java
 * BookCloningRecipe::getRemainingItems()), so it isn't one of the ingredients.
 */
final class BookCloningRecipe extends MultiCraftingRecipe{
	/** UUID the client uses for this multi recipe (BedrockProtocol MultiRecipe::TYPE_BOOK_CLONING) */
	public const MULTI_RECIPE_UUID = "d1ca6b84-338e-4f2f-9c6b-76cc8b4bd98d";

	public function getMultiRecipeUuid() : string{
		return self::MULTI_RECIPE_UUID;
	}

	public function getIngredientList() : array{
		//representative only: the real ingredients depend on the grid (see getIngredientListFor())
		return [new ExactRecipeIngredient(VanillaItems::WRITABLE_BOOK())];
	}

	protected function computeResults(CraftingGrid $grid) : ?array{
		$result = self::computeResult($grid->getContents());
		return $result !== null ? [$result] : null;
	}

	public function getIngredientListFor(CraftingGrid $grid) : array{
		$ingredients = [];
		foreach($grid->getContents() as $item){
			if($item instanceof WritableBook){
				$ingredients[] = new ExactRecipeIngredient((clone $item)->setCount(1));
			}
		}
		return $ingredients;
	}

	/**
	 * Returns the copies made from the given grid contents, or null if they aren't one copyable written book and at
	 * least one book and quill.
	 *
	 * @internal exposed for tests
	 * @param Item[] $items
	 */
	public static function computeResult(array $items) : ?WrittenBook{
		$source = null;
		$copies = 0;
		foreach($items as $item){
			if($item->isNull()){
				continue;
			}
			if($item instanceof WrittenBook){
				if($source !== null || $item->getGeneration() > WrittenBook::GENERATION_COPY){
					return null;
				}
				$source = $item;
			}elseif($item instanceof WritableBook){
				$copies++;
			}else{
				return null;
			}
		}
		if($source === null || $copies === 0){
			return null;
		}
		$result = clone $source;
		$result->setCount($copies);
		return $result->setGeneration($source->getGeneration() + 1);
	}
}
