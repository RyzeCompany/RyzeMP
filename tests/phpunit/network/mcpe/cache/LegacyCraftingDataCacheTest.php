<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\cache;

use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\block\VanillaBlocks;
use pocketmine\crafting\CraftingManager;
use pocketmine\crafting\ExactRecipeIngredient;
use pocketmine\crafting\ShapedRecipe;
use pocketmine\crafting\ShapelessRecipe;
use pocketmine\crafting\ShapelessRecipeType;
use pocketmine\crafting\TagWildcardRecipeIngredient;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\CraftingDataPacket;
use pocketmine\network\mcpe\protocol\types\recipe\IntIdMetaItemDescriptor;
use pocketmine\timings\Timings;

final class LegacyCraftingDataCacheTest extends TestCase{

	public function testNativeRecipesKeepTheirServerIndexesAndSerializeFor419() : void{
		Timings::init();
		$manager = new CraftingManager();
		$manager->registerShapelessRecipe(new ShapelessRecipe([new ExactRecipeIngredient(VanillaBlocks::OAK_LOG()->asItem())], [VanillaBlocks::OAK_PLANKS()->asItem()->setCount(4)], ShapelessRecipeType::CRAFTING));
		$manager->registerShapedRecipe(new ShapedRecipe(['PP', 'PP'], ['P' => new ExactRecipeIngredient(VanillaBlocks::OAK_PLANKS()->asItem())], [VanillaBlocks::CRAFTING_TABLE()->asItem()]));
		$cache = CraftingDataCache::getInstance(419)->getCache($manager);
		self::assertCount(2, $cache->recipesWithTypeIds);
		foreach($cache->recipesWithTypeIds as $i => $recipe){
			self::assertSame($i + CraftingDataCache::RECIPE_ID_OFFSET, $recipe->getRecipeNetId());
		}
		$out = new ByteBufferWriter();
		$cache->encode($out, 419);
		$in = new ByteBufferReader($out->getData());
		$copy = new CraftingDataPacket();
		$copy->decode($in, 419);
		self::assertCount(2, $copy->recipesWithTypeIds);
		self::assertSame(0, $in->getUnreadLength());
	}

	public function testLegacyTagsExpandIntoNumericIngredientsAndInvalidateAfterRegistration() : void{
		Timings::init();
		$manager = new CraftingManager();
		$manager->registerShapelessRecipe(new ShapelessRecipe([new TagWildcardRecipeIngredient('minecraft:planks')], [VanillaItems::STICK()], ShapelessRecipeType::CRAFTING));
		$cache = CraftingDataCache::getInstance(419)->getCache($manager);
		self::assertNotEmpty($cache->recipesWithTypeIds);
		foreach($cache->recipesWithTypeIds as $recipe){
			self::assertSame(1, $recipe->getRecipeNetId());
			self::assertInstanceOf(IntIdMetaItemDescriptor::class, $recipe->getInputs()[0]->getDescriptor());
		}
		$manager->registerShapelessRecipe(new ShapelessRecipe([new ExactRecipeIngredient(VanillaItems::DIAMOND())], [VanillaBlocks::DIAMOND()->asItem()], ShapelessRecipeType::CRAFTING));
		$rebuilt = CraftingDataCache::getInstance(419)->getCache($manager);
		self::assertNotSame($cache, $rebuilt);
		self::assertSame(2, $rebuilt->recipesWithTypeIds[array_key_last($rebuilt->recipesWithTypeIds)]->getRecipeNetId());
	}
	public function testUnknownPluginTagsAndUnrepresentableOutputsAreOmittedWithoutRenumbering() : void{
		Timings::init();
		$manager = new CraftingManager();
		$manager->registerShapelessRecipe(new ShapelessRecipe([new TagWildcardRecipeIngredient('plugin:unknown')], [VanillaItems::STICK()], ShapelessRecipeType::CRAFTING));
		$manager->registerShapelessRecipe(new ShapelessRecipe([new ExactRecipeIngredient(VanillaItems::DIAMOND())], [VanillaBlocks::CHERRY_PLANKS()->asItem()], ShapelessRecipeType::CRAFTING));
		$manager->registerShapelessRecipe(new ShapelessRecipe([new ExactRecipeIngredient(VanillaBlocks::OAK_PLANKS()->asItem())], [VanillaItems::STICK()], ShapelessRecipeType::CRAFTING));
		$cache = CraftingDataCache::getInstance(419)->getCache($manager);
		self::assertCount(1, $cache->recipesWithTypeIds);
		self::assertSame(3, $cache->recipesWithTypeIds[0]->getRecipeNetId());
	}

}
