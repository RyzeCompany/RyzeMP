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

use PHPUnit\Framework\TestCase;
use pocketmine\block\utils\DyeColor;
use pocketmine\item\DyedBundle;
use pocketmine\item\VanillaItems;

class UserDataShapelessRecipeTest extends TestCase{

	public function testDyeingBundleKeepsContents() : void{
		$recipe = new UserDataShapelessRecipe(
			[new MetaWildcardRecipeIngredient("minecraft:bundle"), new MetaWildcardRecipeIngredient("minecraft:red_dye")],
			[VanillaItems::DYED_BUNDLE()->setColor(DyeColor::RED)],
			ShapelessRecipeType::CRAFTING
		);

		$bundle = VanillaItems::BUNDLE();
		$bundle->addItem(VanillaItems::DIAMOND()->setCount(10));

		$grid = new class(CraftingGrid::SIZE_SMALL) extends CraftingGrid{};
		$grid->setItem(0, VanillaItems::DYE()->setColor(DyeColor::RED));
		$grid->setItem(3, $bundle);

		self::assertTrue($recipe->matchesCraftingGrid($grid));
		$results = $recipe->getResultsFor($grid);
		self::assertCount(1, $results);
		$result = $results[0];
		self::assertInstanceOf(DyedBundle::class, $result);
		self::assertSame(DyeColor::RED, $result->getColor());
		self::assertSame(10, $result->getContentsWeight());

		//the recipe's own result must not be modified
		$plain = $recipe->getResults()[0];
		self::assertInstanceOf(DyedBundle::class, $plain);
		self::assertTrue($plain->isEmpty());
	}
}
