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
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\item\FilledMap;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\types\recipe\MultiRecipe;
use function strtolower;

class CartographyMapRecipeTest extends TestCase{

	private static function map() : FilledMap{
		return VanillaItems::FILLED_MAP()->setMapId(5)->setScale(1)->setCount(3);
	}

	public function testUuidsMatchTheProtocol() : void{
		self::assertSame(strtolower(MultiRecipe::TYPE_MAP_EXTENDING_CARTOGRAPHY), CartographyMapRecipe::EXTENDING);
		self::assertSame(strtolower(MultiRecipe::TYPE_MAP_CLONING_CARTOGRAPHY), CartographyMapRecipe::CLONING);
		self::assertSame(strtolower(MultiRecipe::TYPE_MAP_UPGRADING_CARTOGRAPHY), CartographyMapRecipe::UPGRADING);
		self::assertSame(strtolower(MultiRecipe::TYPE_MAP_LOCKING_CARTOGRAPHY), CartographyMapRecipe::LOCKING);
	}

	public function testExtending() : void{
		$recipe = new CartographyMapRecipe(CartographyMapRecipe::EXTENDING);
		$result = $recipe->getResultFor(self::map(), VanillaItems::PAPER(), 9);
		self::assertInstanceOf(FilledMap::class, $result);
		self::assertSame([9, 2, 1], [$result->getMapId(), $result->getScale(), $result->getCount()]);
		self::assertTrue($recipe->createsNewMap());

		self::assertNull($recipe->getResultFor(self::map()->setScale(4), VanillaItems::PAPER(), 9), "scale 4 is the biggest");
		self::assertNull($recipe->getResultFor(self::map()->setMapType(FilledMap::TYPE_LOCKED), VanillaItems::PAPER(), 9), "locked maps can't be zoomed out");
		self::assertNull($recipe->getResultFor(self::map(), VanillaItems::COMPASS(), 9));
		self::assertNull($recipe->getResultFor(VanillaItems::PAPER(), VanillaItems::PAPER(), 9));
	}

	public function testCloning() : void{
		$recipe = new CartographyMapRecipe(CartographyMapRecipe::CLONING);
		$result = $recipe->getResultFor(self::map(), VanillaItems::EMPTY_MAP());
		self::assertInstanceOf(FilledMap::class, $result);
		self::assertSame([5, 2], [$result->getMapId(), $result->getCount()]);
		self::assertFalse($recipe->createsNewMap());
		self::assertNull($recipe->getResultFor(self::map(), VanillaItems::PAPER()));
	}

	public function testUpgrading() : void{
		$recipe = new CartographyMapRecipe(CartographyMapRecipe::UPGRADING);
		$result = $recipe->getResultFor(self::map(), VanillaItems::COMPASS());
		self::assertInstanceOf(FilledMap::class, $result);
		self::assertTrue($result->displaysPlayers());
		self::assertSame(FilledMap::TYPE_LOCATOR, $result->getMapType());
		self::assertSame(5, $result->getMapId());
		self::assertNull($recipe->getResultFor($result, VanillaItems::COMPASS()), "already shows players");
	}

	public function testLocking() : void{
		$recipe = new CartographyMapRecipe(CartographyMapRecipe::LOCKING);
		$result = $recipe->getResultFor(self::map(), VanillaBlocks::GLASS_PANE()->asItem(), 11);
		self::assertInstanceOf(FilledMap::class, $result);
		self::assertSame([11, FilledMap::TYPE_LOCKED, 1], [$result->getMapId(), $result->getMapType(), $result->getCount()]);
		self::assertNull($recipe->getResultFor(self::map(), VanillaBlocks::STAINED_GLASS_PANE()->setColor(DyeColor::RED)->asItem(), 11), "stained glass panes don't work");
		self::assertNull($recipe->getResultFor($result, VanillaBlocks::GLASS_PANE()->asItem(), 12), "already locked");
	}

	public function testRegisteredAsSpecialRecipes() : void{
		$found = [];
		foreach(CraftingManagerFromDataHelper::make(BedrockDataFiles::RECIPES)->getSpecialCraftingRecipes() as $recipe){
			if($recipe instanceof CartographyMapRecipe){
				$found[] = $recipe->getUuid();
			}
		}
		self::assertSame([CartographyMapRecipe::EXTENDING, CartographyMapRecipe::CLONING, CartographyMapRecipe::UPGRADING, CartographyMapRecipe::LOCKING], $found);
	}
}
