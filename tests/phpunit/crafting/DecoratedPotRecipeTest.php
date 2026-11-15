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
use pocketmine\block\tile\DecoratedPot as TileDecoratedPot;
use pocketmine\inventory\transaction\CraftingTransaction;
use pocketmine\item\PotterySherdType;
use pocketmine\item\VanillaItems;
use function array_values;

class DecoratedPotRecipeTest extends TestCase{

	private function makeGrid() : CraftingGrid{
		return new class(CraftingGrid::SIZE_BIG) extends CraftingGrid{};
	}

	public function testSherdsGoToTheRightSides() : void{
		$grid = $this->makeGrid();
		$grid->setItem(1, VanillaItems::POTTERY_SHERD()->setSherdType(PotterySherdType::ANGLER)); //top: back
		$grid->setItem(3, VanillaItems::BRICK()); //left
		$grid->setItem(5, VanillaItems::POTTERY_SHERD()->setSherdType(PotterySherdType::SKULL)); //right
		$grid->setItem(7, VanillaItems::POTTERY_SHERD()->setSherdType(PotterySherdType::HEART)); //bottom: front

		$recipe = new DecoratedPotRecipe();
		self::assertTrue($recipe->matchesCraftingGrid($grid));
		$results = $recipe->getResultsFor($grid);
		self::assertCount(1, $results);
		self::assertSame(
			[PotterySherdType::ANGLER, null, PotterySherdType::SKULL, PotterySherdType::HEART],
			TileDecoratedPot::readSherds($results[0]->getNamedTag())
		);

		CraftingTransaction::matchIngredients(array_values($grid->getContents()), $recipe->getIngredientList(), 1);
		$this->addToAssertionCount(1);
	}

	public function testWrongShapeDoesNotMatch() : void{
		$grid = $this->makeGrid();
		$grid->setItem(1, VanillaItems::BRICK());
		$grid->setItem(3, VanillaItems::BRICK());
		$grid->setItem(5, VanillaItems::BRICK());
		$grid->setItem(4, VanillaItems::BRICK()); //center instead of bottom
		self::assertFalse((new DecoratedPotRecipe())->matchesCraftingGrid($grid));

		$grid->setItem(4, VanillaItems::AIR());
		$grid->setItem(7, VanillaItems::STICK());
		self::assertFalse((new DecoratedPotRecipe())->matchesCraftingGrid($grid));
	}

	public function testSherdIdRoundTrip() : void{
		foreach(PotterySherdType::cases() as $type){
			self::assertSame($type, TileDecoratedPot::idToSherd(TileDecoratedPot::sherdToId($type)));
		}
		self::assertSame("minecraft:brick", TileDecoratedPot::sherdToId(null));
		self::assertNull(TileDecoratedPot::idToSherd("minecraft:brick"));
		self::assertNull(TileDecoratedPot::idToSherd("minecraft:stick"));
	}
}
