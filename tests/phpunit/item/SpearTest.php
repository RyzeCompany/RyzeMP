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

namespace pocketmine\item;

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;

class SpearTest extends TestCase{

	/**
	 * Bedrock values from https://minecraft.wiki/w/Spear
	 * @return \Generator<string, array{Spear, int, int, int, int, float}>
	 */
	public static function statsProvider() : \Generator{
		//spear, jab damage, durability, enchantability, jab cooldown ticks, charge multiplier
		yield "wooden" => [VanillaItems::WOODEN_SPEAR(), 2, 60, 15, 13, 0.7];
		yield "stone" => [VanillaItems::STONE_SPEAR(), 3, 132, 5, 15, 0.82];
		yield "copper" => [VanillaItems::COPPER_SPEAR(), 3, 191, 13, 17, 0.82];
		yield "iron" => [VanillaItems::IRON_SPEAR(), 4, 251, 14, 19, 0.95];
		yield "golden" => [VanillaItems::GOLDEN_SPEAR(), 2, 33, 22, 19, 0.7];
		yield "diamond" => [VanillaItems::DIAMOND_SPEAR(), 5, 1562, 10, 21, 1.075];
		yield "netherite" => [VanillaItems::NETHERITE_SPEAR(), 6, 2032, 15, 23, 1.2];
	}

	/**
	 * @dataProvider statsProvider
	 */
	public function testStats(Spear $spear, int $damage, int $durability, int $enchantability, int $cooldown, float $multiplier) : void{
		self::assertSame($damage, $spear->getAttackPoints());
		self::assertSame($durability, $spear->getMaxDurability());
		self::assertSame($enchantability, $spear->getEnchantability());
		self::assertSame($cooldown, $spear->getJabCooldownTicks());
		self::assertEqualsWithDelta($multiplier, $spear->getChargeDamageMultiplier(), 0.0001);
		self::assertSame(1, $spear->getMaxStackSize());
		self::assertSame(ItemCooldownTags::SPEAR, $spear->getCooldownTag());
	}

	public function testRepairMaterials() : void{
		self::assertTrue(VanillaItems::WOODEN_SPEAR()->isValidRepairMaterial(VanillaBlocks::SPRUCE_PLANKS()->asItem()));
		self::assertTrue(VanillaItems::STONE_SPEAR()->isValidRepairMaterial(VanillaBlocks::COBBLESTONE()->asItem()));
		self::assertTrue(VanillaItems::STONE_SPEAR()->isValidRepairMaterial(VanillaBlocks::COBBLED_DEEPSLATE()->asItem()));
		self::assertFalse(VanillaItems::STONE_SPEAR()->isValidRepairMaterial(VanillaBlocks::STONE()->asItem()));
		self::assertTrue(VanillaItems::COPPER_SPEAR()->isValidRepairMaterial(VanillaItems::COPPER_INGOT()));
		self::assertTrue(VanillaItems::IRON_SPEAR()->isValidRepairMaterial(VanillaItems::IRON_INGOT()));
		self::assertTrue(VanillaItems::GOLDEN_SPEAR()->isValidRepairMaterial(VanillaItems::GOLD_INGOT()));
		self::assertTrue(VanillaItems::DIAMOND_SPEAR()->isValidRepairMaterial(VanillaItems::DIAMOND()));
		self::assertTrue(VanillaItems::NETHERITE_SPEAR()->isValidRepairMaterial(VanillaItems::NETHERITE_INGOT()));
		self::assertFalse(VanillaItems::NETHERITE_SPEAR()->isValidRepairMaterial(VanillaItems::DIAMOND()));
	}

	public function testChargeStages() : void{
		$spear = VanillaItems::IRON_SPEAR(); //0.6 s delay, 2.5 / 4.25 / 4.5 s stages
		self::assertSame(Spear::STAGE_NONE, $spear->getChargeStage(0));
		self::assertSame(Spear::STAGE_NONE, $spear->getChargeStage(11));
		self::assertSame(Spear::STAGE_ENGAGED, $spear->getChargeStage(12));
		self::assertSame(Spear::STAGE_ENGAGED, $spear->getChargeStage(61));
		self::assertSame(Spear::STAGE_TIRED, $spear->getChargeStage(62));
		self::assertSame(Spear::STAGE_DISENGAGED, $spear->getChargeStage(12 + 50 + 85));
		self::assertSame(Spear::STAGE_ENDED, $spear->getChargeStage(12 + 50 + 85 + 90));
	}

	public function testChargeDamage() : void{
		$spear = VanillaItems::DIAMOND_SPEAR();
		self::assertSame(0.0, $spear->calculateChargeDamage(4.0));
		self::assertSame(0.0, $spear->calculateChargeDamage(-10.0));
		self::assertEqualsWithDelta(1.075 * 10, $spear->calculateChargeDamage(10.0), 0.0001);

		self::assertTrue(Spear::canChargeKnockBack(Spear::STAGE_ENGAGED, 6.0));
		self::assertTrue(Spear::canChargeKnockBack(Spear::STAGE_TIRED, 6.0));
		self::assertFalse(Spear::canChargeKnockBack(Spear::STAGE_DISENGAGED, 6.0));
		self::assertFalse(Spear::canChargeKnockBack(Spear::STAGE_ENGAGED, 4.8));
	}

	public function testLungeImpulse() : void{
		$spear = VanillaItems::WOODEN_SPEAR();
		self::assertSame(0.0, $spear->getLungeImpulse());
		$spear->addEnchantment(new EnchantmentInstance(VanillaEnchantments::LUNGE(), 3));
		self::assertEqualsWithDelta(0.458 * 3, $spear->getLungeImpulse(), 0.0001);
	}
}
