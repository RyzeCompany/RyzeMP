<?php

declare(strict_types=1);

namespace pocketmine\crafting;

use PHPUnit\Framework\TestCase;
use pocketmine\block\BlockTypeIds;
use pocketmine\item\ItemBlock;
use pocketmine\world\generator\vanilla\feature\ArchaeologyContent;

final class ArchaeologyRecipeTest extends TestCase{

	public function testBrushAndPlainDecoratedPotHaveUniqueRecipes() : void{
		$manager = new CraftingManager();
		ArchaeologyContent::registerRecipe($manager);
		ArchaeologyContent::registerRecipe($manager); //startup must be idempotent
		$all = [];
		foreach($manager->getShapedRecipes() as $group){
			foreach($group as $recipe){
				$all[] = $recipe;
			}
		}
		self::assertCount(2, $all);
		$potRecipes = [];
		foreach($all as $recipe){
			$output = $recipe->getResults()[0] ?? null;
			if($output instanceof ItemBlock && $output->getBlock()->getTypeId() === BlockTypeIds::DECORATED_POT){
				$potRecipes[] = $recipe;
			}
		}
		self::assertCount(1, $potRecipes);
		self::assertSame(3, $potRecipes[0]->getWidth());
		self::assertSame(3, $potRecipes[0]->getHeight());
		$ingredients = $potRecipes[0]->getIngredientMap();
		self::assertNull($ingredients[0][0]);
		self::assertNotNull($ingredients[0][1]);
		self::assertNotNull($ingredients[1][0]);
		self::assertNull($ingredients[1][1]);
		self::assertNotNull($ingredients[1][2]);
		self::assertNotNull($ingredients[2][1]);
	}
}
