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

use function count;

/**
 * A shapeless crafting recipe whose result keeps the data (NBT) of the input matched by the first ingredient, e.g.
 * dyeing a bundle or a shulker box keeps its contents. Bedrock sends these as "user data shapeless" recipes
 * (BedrockData recipes/shapeless_shulker_box.json), and the client copies the data in its own prediction too.
 */
class UserDataShapelessRecipe extends ShapelessRecipe{

	public function getResultsFor(CraftingGrid $grid) : array{
		$results = $this->getResults();
		$ingredients = $this->getIngredientList();
		if(count($ingredients) === 0){
			return $results;
		}
		$source = null;
		foreach($grid->getContents() as $item){
			if($ingredients[0]->accepts($item)){
				$source = $item;
				break;
			}
		}
		if($source !== null && $source->hasNamedTag()){
			foreach($results as $result){
				$result->setNamedTag($source->getNamedTag());
			}
		}
		return $results;
	}
}
