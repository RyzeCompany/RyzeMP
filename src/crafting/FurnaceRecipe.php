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

class FurnaceRecipe{

	/**
	 * @param float $experience Experience a furnace stores for each item smelted with this recipe, paid out as orbs
	 *                          when a player takes the result.
	 */
	public function __construct(
		private Item $result,
		private RecipeIngredient $ingredient,
		private float $experience = 0.0
	){
		$this->result = clone $result;
	}

	public function getInput() : RecipeIngredient{
		return $this->ingredient;
	}

	public function getResult() : Item{
		return clone $this->result;
	}

	/**
	 * Returns the experience a furnace stores for each item smelted with this recipe.
	 */
	public function getExperience() : float{
		return $this->experience;
	}
}
