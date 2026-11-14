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

namespace pocketmine\block;

use PHPUnit\Framework\TestCase;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;

final class BlockDropsParityTest extends TestCase{

	private static function silkTouchPickaxe() : Item{
		return VanillaItems::DIAMOND_PICKAXE()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SILK_TOUCH()));
	}

	public function testBarsAndStatueDropWithAnyPickaxe() : void{
		$pickaxe = VanillaItems::WOODEN_PICKAXE();
		foreach([VanillaBlocks::IRON_BARS(), VanillaBlocks::COPPER_BARS(), VanillaBlocks::COPPER_GOLEM_STATUE()] as $block){
			$drops = $block->getDrops($pickaxe);
			self::assertCount(1, $drops, $block->getName() . " drops with a wooden pickaxe");
			self::assertTrue($drops[0]->equals($block->asItem()), $block->getName());
		}
		//the iron block still needs a stone pickaxe
		self::assertSame([], VanillaBlocks::IRON()->getDrops($pickaxe));
	}

	public function testSculkSensorsNeedSilkTouch() : void{
		$hoe = VanillaItems::DIAMOND_HOE();
		foreach([VanillaBlocks::SCULK_SENSOR(), VanillaBlocks::CALIBRATED_SCULK_SENSOR()] as $block){
			self::assertSame([], $block->getDrops($hoe), $block->getName());
			self::assertSame(5, $block->getXpDropForTool($hoe), $block->getName());
			self::assertSame([], $block->getDrops(VanillaItems::AIR()), $block->getName() . " by hand");

			$silk = self::silkTouchPickaxe();
			$drops = $block->getDrops($silk);
			self::assertCount(1, $drops, $block->getName());
			self::assertTrue($drops[0]->equals($block->asItem()), $block->getName());
			self::assertSame(0, $block->getXpDropForTool($silk));
		}
	}

	public function testBeeNestDropsItselfWithSilkTouch() : void{
		$nest = VanillaBlocks::BEE_NEST();
		self::assertSame([], $nest->getDrops(VanillaItems::DIAMOND_AXE()));
		$drops = $nest->getDrops(self::silkTouchPickaxe());
		self::assertCount(1, $drops);
		self::assertTrue($drops[0]->equals($nest->asItem()));
	}

	public function testNetherGoldOreExperience() : void{
		$ore = VanillaBlocks::NETHER_GOLD_ORE();
		$pickaxe = VanillaItems::WOODEN_PICKAXE();
		$seen = [];
		for($i = 0; $i < 200; ++$i){
			$xp = $ore->getXpDropForTool($pickaxe);
			self::assertContains($xp, [0, 1]);
			$seen[$xp] = true;
		}
		self::assertCount(2, $seen, "0 and 1 both happen");
		self::assertSame(0, $ore->getXpDropForTool(self::silkTouchPickaxe()));
	}
}
