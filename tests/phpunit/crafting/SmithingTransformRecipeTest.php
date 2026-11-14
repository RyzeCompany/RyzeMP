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
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\VanillaItems;

class SmithingTransformRecipeTest extends TestCase{

	private function netheriteSwordRecipe() : SmithingTransformRecipe{
		return new SmithingTransformRecipe(
			new ExactRecipeIngredient(VanillaItems::DIAMOND_SWORD()),
			new ExactRecipeIngredient(VanillaItems::NETHERITE_INGOT()),
			new ExactRecipeIngredient(VanillaItems::NETHERITE_UPGRADE_SMITHING_TEMPLATE()),
			VanillaItems::NETHERITE_SWORD()
		);
	}

	public function testUpgradeKeepsEnchantments() : void{
		$sword = VanillaItems::DIAMOND_SWORD()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 3));
		$result = $this->netheriteSwordRecipe()->getResultFor([
			VanillaItems::NETHERITE_UPGRADE_SMITHING_TEMPLATE(),
			$sword,
			VanillaItems::NETHERITE_INGOT()
		]);
		self::assertNotNull($result);
		self::assertTrue($result->equals(VanillaItems::NETHERITE_SWORD(), checkCompound: false));
		self::assertSame(3, $result->getEnchantmentLevel(VanillaEnchantments::SHARPNESS()));
	}

	public function testRejectsWrongTemplateAndAddition() : void{
		$dirt = VanillaBlocks::DIRT()->asItem();
		self::assertNull($this->netheriteSwordRecipe()->getResultFor([VanillaItems::DIAMOND_SWORD(), $dirt, $dirt]));
	}

	public function testRejectsMissingTemplate() : void{
		self::assertNull($this->netheriteSwordRecipe()->getResultFor([
			VanillaItems::DIAMOND_SWORD(),
			VanillaItems::NETHERITE_INGOT(),
			VanillaItems::NETHERITE_INGOT()
		]));
	}

	public function testRejectsTwoBaseItems() : void{
		self::assertNull($this->netheriteSwordRecipe()->getResultFor([
			VanillaItems::DIAMOND_SWORD(),
			VanillaItems::DIAMOND_SWORD(),
			VanillaItems::NETHERITE_INGOT()
		]));
	}
}
