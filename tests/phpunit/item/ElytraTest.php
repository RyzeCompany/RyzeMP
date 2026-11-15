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
use pocketmine\inventory\ArmorInventory;
use pocketmine\player\Player;

class ElytraTest extends TestCase{

	public function testStats() : void{
		$elytra = VanillaItems::ELYTRA();
		self::assertSame(433, $elytra->getMaxDurability());
		self::assertSame(1, $elytra->getMaxStackSize());
		self::assertSame(0, $elytra->getDefensePoints());
		self::assertSame(ArmorInventory::SLOT_CHEST, $elytra->getArmorSlot());
		self::assertTrue($elytra->isValidRepairMaterial(VanillaItems::PHANTOM_MEMBRANE()));
		self::assertFalse($elytra->isValidRepairMaterial(VanillaItems::LEATHER()));
	}

	public function testNeverBreaks() : void{
		$elytra = VanillaItems::ELYTRA();
		self::assertTrue($elytra->canGlide());

		$elytra->setDamage($elytra->getMaxDurability() - 2);
		self::assertTrue($elytra->canGlide());
		self::assertTrue($elytra->applyDamage(1));
		//1 durability left: unusable but not broken
		self::assertFalse($elytra->canGlide());
		self::assertFalse($elytra->isBroken());
		self::assertFalse($elytra->isNull());

		self::assertFalse($elytra->applyDamage(10));
		self::assertSame($elytra->getMaxDurability() - 1, $elytra->getDamage());
	}

	public function testLargeDamageStopsAtOneDurability() : void{
		$elytra = VanillaItems::ELYTRA();
		$elytra->applyDamage(10000);
		self::assertSame($elytra->getMaxDurability() - 1, $elytra->getDamage());
		self::assertFalse($elytra->isNull());
	}

	public function testKineticDamage() : void{
		//losing less than 0.3 blocks/tick of horizontal speed doesn't hurt
		self::assertSame(0.0, Player::calculateKineticDamage(0.3, 0.0));
		self::assertSame(0.0, Player::calculateKineticDamage(1.0, 0.9));
		//1.5 blocks/tick into a wall: 1.5 * 10 - 3 = 12
		self::assertEqualsWithDelta(12.0, Player::calculateKineticDamage(1.5, 0.0), 0.0001);
		//speeding up never hurts
		self::assertSame(0.0, Player::calculateKineticDamage(0.5, 2.0));
	}
}
