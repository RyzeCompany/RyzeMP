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
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;

/**
 * Recipes loaded from BedrockData: smelting experience and the shaped recipes whose block ingredients have no states.
 */
final class FurnaceRecipeDataTest extends TestCase{
	private static ?CraftingManager $manager = null;

	private static function manager() : CraftingManager{
		return self::$manager ??= CraftingManagerFromDataHelper::make(BedrockDataFiles::RECIPES);
	}

	private static function experience(FurnaceType $type, Item $input) : float{
		$recipe = self::manager()->getFurnaceRecipeManager($type)->match($input);
		self::assertNotNull($recipe, $input->getName() . " in " . $type->name);
		return $recipe->getExperience();
	}

	public function testSmeltingExperience() : void{
		self::assertSame(0.7, self::experience(FurnaceType::FURNACE, VanillaItems::RAW_IRON()));
		self::assertSame(0.7, self::experience(FurnaceType::BLAST_FURNACE, VanillaItems::RAW_IRON()));
		self::assertSame(1.0, self::experience(FurnaceType::FURNACE, VanillaItems::RAW_GOLD()));
		self::assertSame(2.0, self::experience(FurnaceType::BLAST_FURNACE, VanillaBlocks::ANCIENT_DEBRIS()->asItem()));
		self::assertSame(0.35, self::experience(FurnaceType::SMOKER, VanillaItems::RAW_BEEF()));
		self::assertSame(0.1, self::experience(FurnaceType::FURNACE, VanillaBlocks::COBBLESTONE()->asItem()));
		self::assertSame(0.1, self::experience(FurnaceType::FURNACE, VanillaBlocks::SAND()->asItem()));
		self::assertSame(0.35, self::experience(FurnaceType::FURNACE, VanillaBlocks::CLAY()->asItem()));
		self::assertSame(0.15, self::experience(FurnaceType::FURNACE, VanillaBlocks::OAK_LOG()->asItem()));
		self::assertSame(0.0, self::experience(FurnaceType::CAMPFIRE, VanillaItems::RAW_BEEF()), "campfires give no experience");
	}

	public function testEveryFurnaceRecipeGivesExperience() : void{
		foreach([FurnaceType::FURNACE, FurnaceType::BLAST_FURNACE, FurnaceType::SMOKER] as $type){
			foreach(self::manager()->getFurnaceRecipeManager($type)->getAll() as $recipe){
				self::assertGreaterThan(0.0, $recipe->getExperience(), $recipe->getResult()->getName() . " in " . $type->name);
			}
		}
	}

	/**
	 * purpur_block without block_states in purpur stairs and the spire armor trim duplication
	 */
	public function testRecipesWithStatelessBlockIngredientsLoad() : void{
		$results = [];
		foreach(self::manager()->getShapedRecipes() as $recipes){
			foreach($recipes as $recipe){
				foreach($recipe->getResults() as $result){
					$results[$result->getName()] = true;
				}
			}
		}
		self::assertArrayHasKey(VanillaBlocks::PURPUR_STAIRS()->asItem()->getName(), $results);
		self::assertArrayHasKey(VanillaItems::SPIRE_ARMOR_TRIM_SMITHING_TEMPLATE()->getName(), $results);
	}
}
