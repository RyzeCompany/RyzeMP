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
use pocketmine\block\utils\BannerPatternLayer;
use pocketmine\block\utils\BannerPatternType;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\utils\MobHeadType;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\DyeColorIdMap;
use pocketmine\inventory\transaction\CraftingTransaction;
use pocketmine\inventory\transaction\TransactionValidationException;
use pocketmine\item\Banner;
use pocketmine\item\Durable;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\FilledMap;
use pocketmine\item\Item;
use pocketmine\item\Shield;
use pocketmine\item\VanillaItems;
use pocketmine\item\WrittenBook;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\recipe\MultiRecipe;
use function array_values;
use function strtolower;

class GridMultiRecipesTest extends TestCase{

	/**
	 * @param Item[] $items slot => item
	 * @phpstan-param array<int, Item> $items
	 */
	private static function grid(array $items, int $size = CraftingGrid::SIZE_BIG) : CraftingGrid{
		$grid = new class($size) extends CraftingGrid{};
		foreach($items as $slot => $item){
			$grid->setItem($slot, $item);
		}
		return $grid;
	}

	private static function banner(DyeColor $color, BannerPatternLayer ...$patterns) : Banner{
		$banner = VanillaBlocks::BANNER()->asItem();
		self::assertInstanceOf(Banner::class, $banner);
		return $banner->setColor($color)->setPatterns(array_values($patterns));
	}

	private static function assertConsumes(MultiCraftingRecipe $recipe, CraftingGrid $grid, Item ...$consumed) : void{
		CraftingTransaction::matchIngredients(array_values($consumed), $recipe->getIngredientListFor($grid), 1);
	}

	public function testUuidsMatchTheProtocol() : void{
		self::assertSame(strtolower(MultiRecipe::TYPE_REPAIR_ITEM), RepairItemRecipe::MULTI_RECIPE_UUID);
		self::assertSame(strtolower(MultiRecipe::TYPE_BOOK_CLONING), BookCloningRecipe::MULTI_RECIPE_UUID);
		self::assertSame(strtolower(MultiRecipe::TYPE_BANNER_DUPLICATE), BannerDuplicateRecipe::MULTI_RECIPE_UUID);
		self::assertSame(strtolower(MultiRecipe::TYPE_BANNER_ADD_PATTERN), BannerAddPatternRecipe::MULTI_RECIPE_UUID);
		self::assertSame(strtolower(MultiRecipe::TYPE_MAP_CLONING), MapCraftingRecipe::CLONING);
		self::assertSame(strtolower(MultiRecipe::TYPE_MAP_EXTENDING), MapCraftingRecipe::EXTENDING);
		self::assertSame(strtolower(MultiRecipe::TYPE_MAP_UPGRADING), MapCraftingRecipe::UPGRADING);
	}

	public function testRepairAddsDurabilityPlusFivePercent() : void{
		$a = VanillaItems::DIAMOND_SWORD();
		$a->setDamage(1500);
		$b = VanillaItems::DIAMOND_SWORD();
		$b->setDamage(1400);
		$b->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 3));
		$b->addEnchantment(new EnchantmentInstance(VanillaEnchantments::VANISHING(), 1));
		$b->setCustomName("Sword");
		$max = $a->getMaxDurability();

		$recipe = new RepairItemRecipe();
		$grid = self::grid([0 => $a, 4 => $b]);
		self::assertTrue($recipe->matchesCraftingGrid($grid));
		$result = $recipe->getResultsFor($grid)[0];
		self::assertInstanceOf(Durable::class, $result);
		self::assertSame(VanillaItems::DIAMOND_SWORD()->getTypeId(), $result->getTypeId());
		self::assertSame(1, $result->getCount());
		$remaining = ($max - 1500) + ($max - 1400) + (int) ($max * 5 / 100);
		self::assertSame($max - $remaining, $result->getDamage());
		self::assertFalse($result->hasEnchantment(VanillaEnchantments::SHARPNESS()), "enchantments are lost");
		self::assertSame(1, $result->getEnchantmentLevel(VanillaEnchantments::VANISHING()), "curses carry over");
		self::assertFalse($result->hasCustomName());
		self::assertConsumes($recipe, $grid, $a, $b);

		//capped at full durability
		$capped = RepairItemRecipe::computeResult([VanillaItems::DIAMOND_SWORD()->setDamage(10), VanillaItems::DIAMOND_SWORD()]);
		self::assertNotNull($capped);
		self::assertSame(0, $capped->getDamage());
	}

	public function testRepairRejectsMixedOrSingleItems() : void{
		self::assertNull(RepairItemRecipe::computeResult([VanillaItems::DIAMOND_SWORD(), VanillaItems::IRON_SWORD()]));
		self::assertNull(RepairItemRecipe::computeResult([VanillaItems::DIAMOND_SWORD()]));
		self::assertNull(RepairItemRecipe::computeResult([VanillaItems::DIAMOND_SWORD(), VanillaItems::DIAMOND_SWORD(), VanillaItems::DIAMOND_SWORD()]));
		self::assertNull(RepairItemRecipe::computeResult([VanillaItems::STICK(), VanillaItems::STICK()]));
	}

	public function testBookCloningKeepsTheOriginal() : void{
		$book = VanillaItems::WRITTEN_BOOK()->setTitle("Story")->setAuthor("Steve");
		$quill1 = VanillaItems::WRITABLE_BOOK();
		$quill2 = VanillaItems::WRITABLE_BOOK();
		$recipe = new BookCloningRecipe();
		$grid = self::grid([0 => $book, 1 => $quill1, 2 => $quill2]);
		self::assertTrue($recipe->matchesCraftingGrid($grid));
		$result = $recipe->getResultsFor($grid)[0];
		self::assertInstanceOf(WrittenBook::class, $result);
		self::assertSame(2, $result->getCount());
		self::assertSame(WrittenBook::GENERATION_COPY, $result->getGeneration());
		self::assertSame("Story", $result->getTitle());
		self::assertConsumes($recipe, $grid, $quill1, $quill2);

		$this->expectException(TransactionValidationException::class);
		self::assertConsumes($recipe, $grid, $book, $quill1, $quill2);
	}

	public function testBookCloningGenerations() : void{
		$copy = BookCloningRecipe::computeResult([VanillaItems::WRITTEN_BOOK()->setGeneration(WrittenBook::GENERATION_COPY), VanillaItems::WRITABLE_BOOK()]);
		self::assertNotNull($copy);
		self::assertSame(WrittenBook::GENERATION_COPY_OF_COPY, $copy->getGeneration());
		self::assertNull(BookCloningRecipe::computeResult([VanillaItems::WRITTEN_BOOK()->setGeneration(WrittenBook::GENERATION_COPY_OF_COPY), VanillaItems::WRITABLE_BOOK()]));
		self::assertNull(BookCloningRecipe::computeResult([VanillaItems::WRITTEN_BOOK()]));
		self::assertNull(BookCloningRecipe::computeResult([VanillaItems::WRITTEN_BOOK(), VanillaItems::WRITTEN_BOOK(), VanillaItems::WRITABLE_BOOK()]));
		self::assertNull(BookCloningRecipe::computeResult([VanillaItems::WRITTEN_BOOK(), VanillaItems::PAPER()]));
	}

	public function testBannerDuplicate() : void{
		$layer = new BannerPatternLayer(BannerPatternType::CREEPER, DyeColor::BLACK);
		$patterned = self::banner(DyeColor::RED, $layer);
		$blank = self::banner(DyeColor::RED);
		$recipe = new BannerDuplicateRecipe();
		$grid = self::grid([0 => $patterned, 1 => $blank]);
		self::assertTrue($recipe->matchesCraftingGrid($grid));
		$result = $recipe->getResultsFor($grid)[0];
		self::assertInstanceOf(Banner::class, $result);
		self::assertSame(2, $result->getCount());
		self::assertEquals([$layer], $result->getPatterns());
		self::assertConsumes($recipe, $grid, $patterned, $blank);

		self::assertNull(BannerDuplicateRecipe::computeResult([$blank, $patterned]), "the patterned banner must come first");
		self::assertNull(BannerDuplicateRecipe::computeResult([$patterned, self::banner(DyeColor::BLUE)]), "colours must match");
		self::assertNull(BannerDuplicateRecipe::computeResult([$patterned, $patterned]));
		self::assertNull(BannerDuplicateRecipe::computeResult([$patterned, $blank, $blank]));
	}

	public function testBannerShapedPattern() : void{
		$dye = VanillaItems::DYE()->setColor(DyeColor::BLUE);
		$banner = self::banner(DyeColor::WHITE);
		//"stripe_bottom": the bottom row
		$result = BannerAddPatternRecipe::computeResult([0 => $banner, 6 => $dye, 7 => $dye, 8 => $dye], 3);
		self::assertNotNull($result);
		self::assertSame(1, $result->getCount());
		self::assertEquals([new BannerPatternLayer(BannerPatternType::STRIPE_BOTTOM, DyeColor::BLUE)], $result->getPatterns());

		//"border": the outer ring, banner in the middle
		$ring = [];
		foreach([0, 1, 2, 3, 5, 6, 7, 8] as $slot){
			$ring[$slot] = $dye;
		}
		$ring[4] = $banner;
		$border = BannerAddPatternRecipe::computeResult($ring, 3);
		self::assertNotNull($border);
		self::assertSame(BannerPatternType::BORDER, $border->getPatterns()[0]->getType());

		//mixed dye colours, unknown arrangement, small grid
		self::assertNull(BannerAddPatternRecipe::computeResult([0 => $banner, 6 => $dye, 7 => $dye, 8 => VanillaItems::DYE()->setColor(DyeColor::RED)], 3));
		self::assertNull(BannerAddPatternRecipe::computeResult([0 => $banner, 6 => $dye, 8 => $dye], 3));
		self::assertNull(BannerAddPatternRecipe::computeResult([0 => $banner, 3 => $dye], 2));
	}

	public function testBannerShapelessPatternAndLayerLimit() : void{
		$head = VanillaBlocks::MOB_HEAD()->setMobHeadType(MobHeadType::CREEPER)->asItem();
		$banner = self::banner(DyeColor::WHITE);
		$result = BannerAddPatternRecipe::computeResult([0 => $banner, 1 => VanillaItems::INK_SAC(), 2 => $head], 2);
		self::assertNotNull($result);
		self::assertEquals([new BannerPatternLayer(BannerPatternType::CREEPER, DyeColor::BLACK)], $result->getPatterns());

		$bricks = BannerAddPatternRecipe::computeResult([0 => $banner, 1 => VanillaItems::DYE()->setColor(DyeColor::RED), 2 => VanillaBlocks::BRICKS()->asItem()], 3);
		self::assertNotNull($bricks);
		self::assertSame(BannerPatternType::BRICKS, $bricks->getPatterns()[0]->getType());

		self::assertNull(BannerAddPatternRecipe::computeResult([0 => $banner, 2 => $head], 3), "a dye is needed");
		self::assertNull(BannerAddPatternRecipe::computeResult([0 => $banner, 1 => VanillaItems::INK_SAC(), 2 => VanillaItems::STICK()], 3));

		$layer = new BannerPatternLayer(BannerPatternType::CROSS, DyeColor::RED);
		$full = self::banner(DyeColor::WHITE, $layer, $layer, $layer, $layer, $layer, $layer);
		self::assertNull(BannerAddPatternRecipe::computeResult([0 => $full, 1 => VanillaItems::INK_SAC(), 2 => $head], 3), "6 patterns at most");
	}

	public function testShieldDecoration() : void{
		$layer = new BannerPatternLayer(BannerPatternType::SKULL, DyeColor::YELLOW);
		$shield = VanillaItems::SHIELD();
		$shield->setDamage(20);
		$banner = self::banner(DyeColor::GREEN, $layer);
		$recipe = new ShieldDecorationRecipe();
		$grid = self::grid([0 => $banner, 1 => $shield], CraftingGrid::SIZE_SMALL);
		self::assertTrue($recipe->matchesCraftingGrid($grid));
		$result = $recipe->getResultsFor($grid)[0];
		self::assertInstanceOf(Shield::class, $result);
		self::assertSame(DyeColor::GREEN, $result->getBaseColor());
		self::assertEquals([$layer], $result->getPatterns());
		self::assertSame(20, $result->getDamage());
		self::assertConsumes($recipe, $grid, $banner, $shield);

		self::assertNull(ShieldDecorationRecipe::computeResult([$result, $banner]), "a decorated shield can't take another banner");
		self::assertNull(ShieldDecorationRecipe::computeResult([$shield, $banner, $banner]));
		self::assertNull(ShieldDecorationRecipe::computeResult([$shield]));

		//a blank banner only gives the base colour, and the shield can still be decorated afterwards
		$plain = ShieldDecorationRecipe::computeResult([$shield, self::banner(DyeColor::RED)]);
		self::assertNotNull($plain);
		self::assertSame(DyeColor::RED, $plain->getBaseColor());
		self::assertNotNull(ShieldDecorationRecipe::computeResult([$plain, $banner]));
	}

	public function testShieldNbtLikeBedrock() : void{
		$shield = VanillaItems::SHIELD()
			->setBaseColor(DyeColor::GREEN)
			->setPatterns([new BannerPatternLayer(BannerPatternType::SKULL, DyeColor::YELLOW)]);
		$tag = $shield->getNamedTag();
		$colorIds = DyeColorIdMap::getInstance();
		self::assertSame($colorIds->toInvertedId(DyeColor::GREEN), $tag->getInt(Shield::TAG_BASE));
		$patterns = $tag->getListTag(Shield::TAG_PATTERNS, CompoundTag::class);
		self::assertNotNull($patterns);
		self::assertSame("sku", $patterns->get(0)->getString("Pattern"));
		self::assertSame($colorIds->toInvertedId(DyeColor::YELLOW), $patterns->get(0)->getInt("Color"));

		$copy = VanillaItems::SHIELD()->setNamedTag($tag);
		self::assertInstanceOf(Shield::class, $copy);
		self::assertSame(DyeColor::GREEN, $copy->getBaseColor());
		self::assertEquals($shield->getPatterns(), $copy->getPatterns());
		self::assertTrue($copy->equalsExact($shield));

		self::assertFalse(VanillaItems::SHIELD()->getNamedTag()->getTag(Shield::TAG_BASE) !== null);
	}

	private static function map() : FilledMap{
		return VanillaItems::FILLED_MAP()->setMapId(5)->setScale(1);
	}

	public function testMapCloning() : void{
		$result = MapCraftingRecipe::computeResult(MapCraftingRecipe::CLONING, [self::map(), VanillaItems::EMPTY_MAP(), VanillaItems::EMPTY_MAP()], 3);
		self::assertNotNull($result);
		self::assertSame([5, 3], [$result->getMapId(), $result->getCount()]);
		self::assertNull(MapCraftingRecipe::computeResult(MapCraftingRecipe::CLONING, [self::map()], 3));
		self::assertNull(MapCraftingRecipe::computeResult(MapCraftingRecipe::CLONING, [self::map(), self::map(), VanillaItems::EMPTY_MAP()], 3));
		self::assertNull(MapCraftingRecipe::computeResult(MapCraftingRecipe::CLONING, [self::map(), VanillaItems::EMPTY_MAP(), VanillaItems::PAPER()], 3));
	}

	public function testMapUpgrading() : void{
		$result = MapCraftingRecipe::computeResult(MapCraftingRecipe::UPGRADING, [self::map(), VanillaItems::COMPASS()], 2);
		self::assertNotNull($result);
		self::assertTrue($result->displaysPlayers());
		self::assertSame(FilledMap::TYPE_LOCATOR, $result->getMapType());
		self::assertNull(MapCraftingRecipe::computeResult(MapCraftingRecipe::UPGRADING, [$result, VanillaItems::COMPASS()], 2), "already shows players");
	}

	public function testMapExtending() : void{
		$items = [];
		for($slot = 0; $slot < 9; ++$slot){
			$items[$slot] = $slot === 4 ? self::map() : VanillaItems::PAPER();
		}
		$result = MapCraftingRecipe::computeResult(MapCraftingRecipe::EXTENDING, $items, 3, 9);
		self::assertNotNull($result);
		self::assertSame([9, 2, 1], [$result->getMapId(), $result->getScale(), $result->getCount()]);

		$locked = $items;
		$locked[4] = self::map()->setMapType(FilledMap::TYPE_LOCKED);
		self::assertNull(MapCraftingRecipe::computeResult(MapCraftingRecipe::EXTENDING, $locked, 3, 9));
		$maxScale = $items;
		$maxScale[4] = self::map()->setScale(4);
		self::assertNull(MapCraftingRecipe::computeResult(MapCraftingRecipe::EXTENDING, $maxScale, 3, 9));
		$missing = $items;
		unset($missing[0]);
		self::assertNull(MapCraftingRecipe::computeResult(MapCraftingRecipe::EXTENDING, $missing, 3, 9));
	}
}
