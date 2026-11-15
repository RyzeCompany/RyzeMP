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
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;

class MaceTest extends TestCase{

	public function testStats() : void{
		$mace = VanillaItems::MACE();
		self::assertSame(501, $mace->getMaxDurability());
		self::assertSame(6, $mace->getAttackPoints());
		self::assertSame(1, $mace->getMaxStackSize());
		self::assertSame(15, $mace->getEnchantability());
		self::assertTrue($mace->isValidRepairMaterial(VanillaItems::BREEZE_ROD()));
		self::assertFalse($mace->isValidRepairMaterial(VanillaItems::BLAZE_ROD()));
	}

	/**
	 * @return \Generator<string, array{float, int, float}>
	 */
	public static function smashDamageProvider() : \Generator{
		yield "no fall" => [0.0, 0, 0.0];
		yield "1.5 blocks is not a smash" => [1.5, 0, 0.0];
		yield "2 blocks: 4 per block" => [2.0, 0, 8.0];
		yield "3 blocks" => [3.0, 0, 12.0];
		yield "5 blocks: 12 + 2 per block above 3" => [5.0, 0, 16.0];
		yield "8 blocks" => [8.0, 0, 22.0];
		yield "10 blocks: 22 + 1 per block above 8" => [10.0, 0, 24.0];
		yield "20 blocks" => [20.0, 0, 34.0];
		yield "Density I, 4 blocks" => [4.0, 1, 14.0 + 2.0];
		yield "Density V, 10 blocks" => [10.0, 5, 24.0 + 25.0];
		yield "Density without a smash" => [1.0, 5, 0.0];
	}

	/**
	 * @dataProvider smashDamageProvider
	 */
	public function testSmashDamageBonus(float $fallDistance, int $density, float $expected) : void{
		self::assertEqualsWithDelta($expected, Mace::calculateSmashDamageBonus($fallDistance, $density), 0.0001);
	}

	public function testBreach() : void{
		$mace = VanillaItems::MACE();
		self::assertSame(0.0, $mace->getArmorEffectivenessReduction());
		$mace->addEnchantment(new EnchantmentInstance(VanillaEnchantments::BREACH(), 4));
		self::assertEqualsWithDelta(0.6, $mace->getArmorEffectivenessReduction(), 0.0001);
	}

	public function testWindBurstMultiplier() : void{
		$mace = VanillaItems::MACE();
		self::assertSame(0.0, $mace->getWindBurstMultiplier());
		foreach([1 => 1.2, 2 => 1.75, 3 => 2.2] as $level => $expected){
			$mace->addEnchantment(new EnchantmentInstance(VanillaEnchantments::WIND_BURST(), $level));
			self::assertEqualsWithDelta($expected, $mace->getWindBurstMultiplier(), 0.0001);
		}
	}

	public function testSmashKnockback() : void{
		self::assertEqualsWithDelta(3.5 * 0.7, Mace::calculateSmashKnockback(0.0, 3.0), 0.0001);
		self::assertEqualsWithDelta(1.5 * 0.7, Mace::calculateSmashKnockback(2.0, 3.0), 0.0001);
		//heavy smash (fell more than 5 blocks) doubles it
		self::assertEqualsWithDelta(1.5 * 0.7 * 2, Mace::calculateSmashKnockback(2.0, 6.0), 0.0001);
		self::assertSame(0.0, Mace::calculateSmashKnockback(3.6, 10.0));
		self::assertEqualsWithDelta(1.5 * 0.7 * 0.5, Mace::calculateSmashKnockback(2.0, 3.0, 0.5), 0.0001);
	}
}
