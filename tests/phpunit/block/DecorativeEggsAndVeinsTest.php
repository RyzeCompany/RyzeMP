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

use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\world\redstone\RedstoneTestCase;

/**
 * Decorative blocks without their mobs: turtle and sniffer eggs, frogspawn, sculk vein.
 */
class DecorativeEggsAndVeinsTest extends RedstoneTestCase{

	public function testTurtleEggDropsOnlyWithSilkTouch() : void{
		$eggs = VanillaBlocks::TURTLE_EGG()->setCount(3)->setCracks(1);
		self::assertCount(0, $eggs->getDrops(VanillaItems::DIAMOND_PICKAXE()));
		$silk = VanillaItems::DIAMOND_PICKAXE()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SILK_TOUCH()));
		$drops = $eggs->getDrops($silk);
		self::assertCount(1, $drops);
		self::assertSame(3, $drops[0]->getCount());
	}

	public function testTurtleEggsStackWhenPlaced() : void{
		$this->floor(0, 0, 0, 0);
		$this->set(0, 1, 0, VanillaBlocks::TURTLE_EGG()->setCount(4));
		$click = new Vector3(0.5, 0.5, 0.5);
		self::assertFalse(VanillaBlocks::TURTLE_EGG()->canBePlacedAt($this->get(0, 1, 0), $click, 1, true), "already 4 eggs");
		$this->set(0, 1, 0, VanillaBlocks::TURTLE_EGG()->setCount(2));
		self::assertTrue(VanillaBlocks::TURTLE_EGG()->canBePlacedAt($this->get(0, 1, 0), $click, 1, true));
	}

	public function testSculkVeinDropsOnlyWithSilkTouch() : void{
		$vein = VanillaBlocks::SCULK_VEIN();
		self::assertCount(0, $vein->getDrops(VanillaItems::DIAMOND_HOE()));
		$silk = VanillaItems::DIAMOND_HOE()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SILK_TOUCH()));
		self::assertCount(1, $vein->getDrops($silk));
	}

	public function testFrogSpawnFloatsOnWaterSources() : void{
		$this->floor(0, 0, 1, 0);
		$this->set(0, 1, 0, VanillaBlocks::WATER());
		$this->set(1, 1, 0, VanillaBlocks::STONE());
		$click = new Vector3(0.5, 0.5, 0.5);
		self::assertTrue(VanillaBlocks::FROG_SPAWN()->canBePlacedAt($this->get(0, 2, 0), $click, 1, false));
		self::assertFalse(VanillaBlocks::FROG_SPAWN()->canBePlacedAt($this->get(1, 2, 0), $click, 1, false));
		self::assertCount(0, VanillaBlocks::FROG_SPAWN()->getDrops(VanillaItems::AIR()));
	}
}
