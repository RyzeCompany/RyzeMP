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
use pocketmine\math\Vector3;
use function cos;
use function deg2rad;
use function sin;

class ShieldTest extends TestCase{

	public function testStats() : void{
		$shield = VanillaItems::SHIELD();
		self::assertSame(337, $shield->getMaxDurability());
		self::assertSame(1, $shield->getMaxStackSize());
		self::assertSame(ItemCooldownTags::SHIELD, $shield->getCooldownTag());
		self::assertTrue($shield->isValidRepairMaterial(VanillaBlocks::OAK_PLANKS()->asItem()));
		self::assertTrue($shield->isValidRepairMaterial(VanillaBlocks::CHERRY_PLANKS()->asItem()));
		self::assertFalse($shield->isValidRepairMaterial(VanillaItems::IRON_INGOT()));
	}

	public function testDurabilityCost() : void{
		self::assertSame(0, Shield::getDurabilityCost(0));
		self::assertSame(0, Shield::getDurabilityCost(2.9));
		self::assertSame(4, Shield::getDurabilityCost(3));
		self::assertSame(5, Shield::getDurabilityCost(4.5));
		self::assertSame(10, Shield::getDurabilityCost(9));
	}

	/**
	 * @return \Generator<string, array{float, Vector3, bool}>
	 */
	public static function frontalProvider() : \Generator{
		//yaw 0 faces +Z, yaw 90 faces -X, yaw 180 faces -Z, yaw 270 faces +X (same as Entity::getDirectionVector())
		yield "yaw 0, attacker in front" => [0.0, new Vector3(0, 0, 3), true];
		yield "yaw 0, attacker behind" => [0.0, new Vector3(0, 0, -3), false];
		yield "yaw 0, attacker diagonally in front" => [0.0, new Vector3(2, 0, 1), true];
		yield "yaw 0, attacker diagonally behind" => [0.0, new Vector3(2, 0, -1), false];
		yield "yaw 0, attacker exactly to the side" => [0.0, new Vector3(3, 0, 0), false];
		yield "yaw 90, attacker at -X" => [90.0, new Vector3(-3, 0, 0), true];
		yield "yaw 90, attacker at +X" => [90.0, new Vector3(3, 0, 0), false];
		yield "yaw 180, attacker at -Z" => [180.0, new Vector3(0, 0, -3), true];
		yield "yaw 270, attacker at +X" => [270.0, new Vector3(3, 0, 0), true];
		yield "height is ignored" => [0.0, new Vector3(0, 10, 0.5), true];
		yield "attacker at the same spot" => [0.0, new Vector3(0, 1, 0), false];
	}

	/**
	 * @dataProvider frontalProvider
	 */
	public function testAttackFromFront(float $yaw, Vector3 $attackerOffset, bool $expected) : void{
		$defender = new Vector3(100.5, 64, -20.5);
		self::assertSame($expected, Shield::isAttackFromFront($yaw, $defender, $defender->addVector($attackerOffset)));
	}

	public function testFacingMatchesDirectionVector() : void{
		//the shield's notion of "front" must match where the entity looks
		//(same formula as Entity::getDirectionVector() with pitch 0)
		foreach([0.0, 45.0, 123.0, 200.0, 359.0] as $yaw){
			$position = new Vector3(0, 0, 0);
			$inFront = new Vector3(-sin(deg2rad($yaw)) * 2, 0, cos(deg2rad($yaw)) * 2);
			self::assertTrue(Shield::isAttackFromFront($yaw, $position, $inFront), "yaw $yaw");
			self::assertFalse(Shield::isAttackFromFront($yaw, $position, $inFront->multiply(-1)), "yaw $yaw, behind");
		}
	}
}
