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
use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\inventory\CreativeInventory;
use pocketmine\item\FireworkRocketExplosion;
use pocketmine\item\FireworkRocketType;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use function array_values;
use function count;
use function sort;

/**
 * RecipeUnlockIndex against a plain scan of the crafting manager: recipe IDs, contexts, the recipes of each context
 * and the recipes every creative item unlocks.
 */
final class RecipeUnlockIndexTest extends TestCase{
	private static ?CraftingManager $manager = null;
	/** @phpstan-var list<array{CraftingRecipe|FurnaceRecipe, ?RecipeUnlocking}>|null */
	private static ?array $bookRecipes = null;

	private static function manager() : CraftingManager{
		return self::$manager ??= CraftingManagerFromDataHelper::make(BedrockDataFiles::RECIPES);
	}

	/**
	 * Crafting and furnace recipes in the order the index numbers them, with their unlocking data.
	 *
	 * @return array{CraftingRecipe|FurnaceRecipe, ?RecipeUnlocking}[]
	 * @phpstan-return list<array{CraftingRecipe|FurnaceRecipe, ?RecipeUnlocking}>
	 */
	private static function bookRecipes() : array{
		return self::$bookRecipes ??= self::collectBookRecipes();
	}

	/**
	 * @return array{CraftingRecipe|FurnaceRecipe, ?RecipeUnlocking}[]
	 * @phpstan-return list<array{CraftingRecipe|FurnaceRecipe, ?RecipeUnlocking}>
	 */
	private static function collectBookRecipes() : array{
		$manager = self::manager();
		$recipes = [];
		foreach($manager->getCraftingRecipeIndex() as $recipe){
			if($recipe instanceof ShapedRecipe || $recipe instanceof ShapelessRecipe){
				$recipes[] = [$recipe, $manager->getRecipeUnlocking($recipe)];
			}
		}
		foreach(FurnaceType::cases() as $furnaceType){
			foreach($manager->getFurnaceRecipeManager($furnaceType)->getAll() as $recipe){
				$recipes[] = [$recipe, $manager->getRecipeUnlocking($recipe)];
			}
		}
		return $recipes;
	}

	public function testRecipeIdsAndContexts() : void{
		$index = RecipeUnlockIndex::get(self::manager());
		$seen = [];
		$byContext = [];
		foreach(self::bookRecipes() as [$recipe, $unlocking]){
			$id = $index->getRecipeId($recipe);
			self::assertNotNull($id);
			self::assertArrayNotHasKey($id, $seen, "recipe IDs are unique");
			$seen[$id] = true;
			self::assertTrue($index->isKnownRecipe($id));
			self::assertSame($unlocking?->getContext(), $index->getContext($id), $id);
			if($unlocking !== null){
				$byContext[$unlocking->getContext()][] = $id;
			}
		}
		self::assertGreaterThan(1000, count($seen));
		self::assertFalse($index->isKnownRecipe("not_a_recipe_0123456789"));
		self::assertNull($index->getContext("not_a_recipe_0123456789"));
		foreach([RecipeUnlocking::CONTEXT_NONE, RecipeUnlocking::CONTEXT_ALWAYS_UNLOCKED, RecipeUnlocking::CONTEXT_PLAYER_IN_WATER, RecipeUnlocking::CONTEXT_PLAYER_HAS_MANY_ITEMS, 4, -1, 255] as $context){
			self::assertSame($byContext[$context] ?? [], $index->getContextRecipes($context), "context $context");
			self::assertSame($byContext[$context] ?? [], $index->getContextRecipes($context), "context $context, cached");
		}
		foreach(self::manager()->getSmithingRecipes() as $recipe){
			self::assertFalse($recipe instanceof CraftingRecipe || $recipe instanceof FurnaceRecipe);
		}
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	private static function scanUnlockedBy(RecipeUnlockIndex $index, Item $item) : array{
		$probe = RecipeUnlockIndex::unlockProbe($item);
		$found = [];
		foreach(self::bookRecipes() as [$recipe, $unlocking]){
			if($unlocking === null){
				continue;
			}
			foreach($unlocking->getUnlockingIngredients() as $ingredient){
				if($ingredient->accepts($probe)){
					$id = $index->getRecipeId($recipe);
					self::assertNotNull($id);
					$found[$id] = $id;
					break;
				}
			}
		}
		return array_values($found);
	}

	public function testRecipesUnlockedByEveryCreativeItem() : void{
		$index = RecipeUnlockIndex::get(self::manager());
		$unlocking = 0;
		foreach(CreativeInventory::getInstance()->getAll() as $item){
			$expected = self::scanUnlockedBy($index, $item);
			$actual = $index->getRecipesUnlockedBy($item);
			//the index keeps the order of the recipes for each item name, the scan the order of the recipes
			$sortedActual = $actual;
			$sortedExpected = $expected;
			sort($sortedActual);
			sort($sortedExpected);
			self::assertSame($sortedExpected, $sortedActual, (string) $item);
			self::assertSame($actual, $index->getRecipesUnlockedBy((clone $item)->setCustomName("renamed")), "NBT and count don't matter: $item");
			if(count($actual) > 0){
				$unlocking++;
			}
		}
		self::assertGreaterThan(100, $unlocking);
	}

	/**
	 * Firework rockets and stars can't lose their NBT (clearNamedTag() throws): the recipe book threw on every
	 * inventory change that held one. They unlock what a fresh item of their type unlocks.
	 */
	public function testFireworksDontThrow() : void{
		$index = RecipeUnlockIndex::get(self::manager());
		$explosion = new FireworkRocketExplosion(FireworkRocketType::BURST, [DyeColor::GREEN]);
		$star = VanillaItems::FIREWORK_STAR()->setExplosion($explosion);
		$rocket = VanillaItems::FIREWORK_ROCKET()->setFlightTimeMultiplier(3)->setExplosions([$explosion]);
		foreach([$star, $rocket, VanillaItems::FIREWORK_ROCKET()] as $item){
			$probe = RecipeUnlockIndex::unlockProbe($item);
			self::assertSame($item->getTypeId(), $probe->getTypeId());
			self::assertSame(1, $probe->getCount());
			$expected = [];
			foreach(self::bookRecipes() as [$recipe, $unlocking]){
				foreach($unlocking?->getUnlockingIngredients() ?? [] as $ingredient){
					if($ingredient->accepts($probe)){
						$id = $index->getRecipeId($recipe);
						self::assertNotNull($id);
						$expected[$id] = $id;
						break;
					}
				}
			}
			$actual = $index->getRecipesUnlockedBy($item);
			sort($actual);
			$expected = array_values($expected);
			sort($expected);
			self::assertSame($expected, $actual, (string) $item);
		}
	}
}
