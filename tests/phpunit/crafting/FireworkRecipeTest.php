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
use pocketmine\inventory\transaction\CraftingTransaction;
use pocketmine\inventory\transaction\TransactionValidationException;
use pocketmine\item\FireworkRocket;
use pocketmine\item\FireworkRocketExplosion;
use pocketmine\item\FireworkRocketType;
use pocketmine\item\FireworkStar;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use function array_values;

class FireworkRecipeTest extends TestCase{

	/**
	 * @param Item[] $items
	 */
	private function makeGrid(array $items) : CraftingGrid{
		$grid = new class(CraftingGrid::SIZE_BIG) extends CraftingGrid{};
		foreach(array_values($items) as $slot => $item){
			$grid->setItem($slot, $item);
		}
		return $grid;
	}

	private function dye(DyeColor $color) : Item{
		return VanillaItems::DYE()->setColor($color);
	}

	private function star(FireworkRocketExplosion $explosion) : FireworkStar{
		return VanillaItems::FIREWORK_STAR()->setExplosion($explosion);
	}

	public function testThreeGunpowderMakesFlightThreeRockets() : void{
		$grid = $this->makeGrid([VanillaItems::PAPER(), VanillaItems::GUNPOWDER(), VanillaItems::GUNPOWDER(), VanillaItems::GUNPOWDER()]);
		$recipe = new FireworkRecipe();
		self::assertTrue($recipe->matchesCraftingGrid($grid));
		$results = $recipe->getResultsFor($grid);
		self::assertCount(1, $results);
		$rocket = $results[0];
		self::assertInstanceOf(FireworkRocket::class, $rocket);
		self::assertSame(3, $rocket->getFlightTimeMultiplier());
		self::assertSame(3, $rocket->getCount());
		self::assertSame([], $rocket->getExplosions());

		//every slot is consumed once per craft
		CraftingTransaction::matchIngredients(array_values($grid->getContents()), $recipe->getIngredientListFor($grid), 1);
		$this->addToAssertionCount(1);
	}

	public function testRocketWithStars() : void{
		$red = new FireworkRocketExplosion(FireworkRocketType::STAR, [DyeColor::RED]);
		$blue = new FireworkRocketExplosion(FireworkRocketType::BURST, [DyeColor::BLUE], trail: true);
		$rocket = FireworkRecipe::computeResult([VanillaItems::GUNPOWDER(), $this->star($red), VanillaItems::PAPER(), $this->star($blue)]);
		self::assertInstanceOf(FireworkRocket::class, $rocket);
		self::assertSame(1, $rocket->getFlightTimeMultiplier());
		$explosions = $rocket->getExplosions();
		self::assertCount(2, $explosions);
		self::assertSame(FireworkRocketType::STAR, $explosions[0]->getType());
		self::assertSame(FireworkRocketType::BURST, $explosions[1]->getType());
		self::assertTrue($explosions[1]->getTrail());
	}

	public function testStarWithShapeTrailAndTwinkle() : void{
		$star = FireworkRecipe::computeResult([
			VanillaItems::GUNPOWDER(),
			$this->dye(DyeColor::RED),
			$this->dye(DyeColor::LIME),
			VanillaItems::FIRE_CHARGE(),
			VanillaItems::DIAMOND(),
			VanillaItems::GLOWSTONE_DUST()
		]);
		self::assertInstanceOf(FireworkStar::class, $star);
		$explosion = $star->getExplosion();
		self::assertSame(FireworkRocketType::LARGE_BALL, $explosion->getType());
		self::assertSame([DyeColor::RED, DyeColor::LIME], $explosion->getColors());
		self::assertSame([], $explosion->getFadeColors());
		self::assertTrue($explosion->getTrail());
		self::assertTrue($explosion->willTwinkle());
		self::assertSame(1, $star->getCount());
	}

	public function testStarShapes() : void{
		$shapes = [
			FireworkRocketType::STAR->name => VanillaItems::GOLD_NUGGET(),
			FireworkRocketType::BURST->name => VanillaItems::FEATHER(),
			FireworkRocketType::CREEPER->name => VanillaBlocks::MOB_HEAD()->asItem(),
		];
		foreach($shapes as $name => $shapeItem){
			$star = FireworkRecipe::computeResult([VanillaItems::GUNPOWDER(), $this->dye(DyeColor::WHITE), $shapeItem]);
			self::assertInstanceOf(FireworkStar::class, $star);
			self::assertSame($name, $star->getExplosion()->getType()->name);
		}
		$plain = FireworkRecipe::computeResult([VanillaItems::GUNPOWDER(), $this->dye(DyeColor::WHITE)]);
		self::assertInstanceOf(FireworkStar::class, $plain);
		self::assertSame(FireworkRocketType::SMALL_BALL, $plain->getExplosion()->getType());
	}

	public function testFadeColours() : void{
		$base = $this->star(new FireworkRocketExplosion(FireworkRocketType::CREEPER, [DyeColor::GREEN], twinkle: true));
		$star = FireworkRecipe::computeResult([$base, $this->dye(DyeColor::YELLOW), $this->dye(DyeColor::ORANGE)]);
		self::assertInstanceOf(FireworkStar::class, $star);
		$explosion = $star->getExplosion();
		self::assertSame(FireworkRocketType::CREEPER, $explosion->getType());
		self::assertSame([DyeColor::GREEN], $explosion->getColors());
		self::assertSame([DyeColor::YELLOW, DyeColor::ORANGE], $explosion->getFadeColors());
		self::assertTrue($explosion->willTwinkle());

		$grid = $this->makeGrid([$base, $this->dye(DyeColor::YELLOW)]);
		$recipe = new FireworkRecipe();
		CraftingTransaction::matchIngredients(array_values($grid->getContents()), $recipe->getIngredientListFor($grid), 1);
		$this->addToAssertionCount(1);
	}

	public function testLegacyDyeItems() : void{
		$star = FireworkRecipe::computeResult([
			VanillaItems::GUNPOWDER(),
			VanillaItems::INK_SAC(),
			$this->dye(DyeColor::RED),
			VanillaItems::COCOA_BEANS(),
			VanillaItems::LAPIS_LAZULI(),
			VanillaItems::BONE_MEAL(),
			VanillaItems::DIAMOND()
		]);
		self::assertInstanceOf(FireworkStar::class, $star);
		self::assertSame([DyeColor::BLACK, DyeColor::RED, DyeColor::BROWN, DyeColor::BLUE, DyeColor::WHITE], $star->getExplosion()->getColors());
		self::assertTrue($star->getExplosion()->getTrail());

		$base = $this->star(new FireworkRocketExplosion(FireworkRocketType::SMALL_BALL, [DyeColor::RED]));
		$grid = $this->makeGrid([$base, VanillaItems::BONE_MEAL(), VanillaItems::INK_SAC()]);
		$recipe = new FireworkRecipe();
		self::assertTrue($recipe->matchesCraftingGrid($grid));
		$faded = $recipe->getResultsFor($grid)[0];
		self::assertInstanceOf(FireworkStar::class, $faded);
		self::assertSame([DyeColor::WHITE, DyeColor::BLACK], $faded->getExplosion()->getFadeColors());
		CraftingTransaction::matchIngredients(array_values($grid->getContents()), $recipe->getIngredientListFor($grid), 1);
	}

	public function testInvalidGridsAreRejected() : void{
		$invalid = [
			"four gunpowder" => [VanillaItems::PAPER(), VanillaItems::GUNPOWDER(), VanillaItems::GUNPOWDER(), VanillaItems::GUNPOWDER(), VanillaItems::GUNPOWDER()],
			"no gunpowder" => [VanillaItems::PAPER()],
			"two papers" => [VanillaItems::PAPER(), VanillaItems::PAPER(), VanillaItems::GUNPOWDER()],
			"rocket with dye" => [VanillaItems::PAPER(), VanillaItems::GUNPOWDER(), $this->dye(DyeColor::RED)],
			"star without dye" => [VanillaItems::GUNPOWDER(), VanillaItems::DIAMOND()],
			"two shapes" => [VanillaItems::GUNPOWDER(), $this->dye(DyeColor::RED), VanillaItems::FEATHER(), VanillaItems::GOLD_NUGGET()],
			"two diamonds" => [VanillaItems::GUNPOWDER(), $this->dye(DyeColor::RED), VanillaItems::DIAMOND(), VanillaItems::DIAMOND()],
			"two gunpowder star" => [VanillaItems::GUNPOWDER(), VanillaItems::GUNPOWDER(), $this->dye(DyeColor::RED)],
			"foreign item" => [VanillaItems::GUNPOWDER(), $this->dye(DyeColor::RED), VanillaItems::STICK()],
			"fade with two stars" => [VanillaItems::FIREWORK_STAR(), VanillaItems::FIREWORK_STAR(), $this->dye(DyeColor::RED)],
			"fade with diamond" => [VanillaItems::FIREWORK_STAR(), $this->dye(DyeColor::RED), VanillaItems::DIAMOND()],
			"star alone" => [VanillaItems::FIREWORK_STAR()],
		];
		$recipe = new FireworkRecipe();
		foreach($invalid as $name => $items){
			self::assertNull(FireworkRecipe::computeResult($items), $name);
			self::assertFalse($recipe->matchesCraftingGrid($this->makeGrid($items)), $name);
		}
	}

	public function testConsumedItemsMustMatchTheGrid() : void{
		$grid = $this->makeGrid([VanillaItems::PAPER(), VanillaItems::GUNPOWDER(), VanillaItems::GUNPOWDER()]);
		$recipe = new FireworkRecipe();
		$this->expectException(TransactionValidationException::class);
		//one gunpowder missing from the consumed inputs
		CraftingTransaction::matchIngredients([VanillaItems::PAPER(), VanillaItems::GUNPOWDER()], $recipe->getIngredientListFor($grid), 1);
	}
}
