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

namespace pocketmine\world\generator\vanilla;

use PHPUnit\Framework\TestCase;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\jigsaw\FreeSpace;
use function mt_rand;
use function mt_srand;

/**
 * The gridded free space of the jigsaw assembler answers exactly like the plain list of placed boxes it replaces.
 */
final class FreeSpaceTest extends TestCase{

	private static function randomBox(BoundingBox $outer, int $maxSize) : BoundingBox{
		$x = mt_rand($outer->minX - 8, $outer->maxX + 8);
		$y = mt_rand($outer->minY - 8, $outer->maxY + 8);
		$z = mt_rand($outer->minZ - 8, $outer->maxZ + 8);
		return new BoundingBox($x, $y, $z, $x + mt_rand(0, $maxSize), $y + mt_rand(0, $maxSize), $z + mt_rand(0, $maxSize));
	}

	public function testMatchesPlainList() : void{
		mt_srand(5);
		$outer = new BoundingBox(-90, -40, 17, 150, 100, 300);
		$space = new FreeSpace($outer);
		/** @var BoundingBox[] $taken */
		$taken = [];
		$fits = 0;
		for($i = 0; $i < 3000; ++$i){
			$box = self::randomBox($outer, $i % 7 === 0 ? 60 : 12);
			$expected = $outer->contains($box);
			if($expected){
				foreach($taken as $t){
					if($t->intersects($box)){
						$expected = false;
						break;
					}
				}
			}
			self::assertSame($expected, $space->fits($box), "box $i");
			if($expected){
				$fits++;
				$space->subtract($box);
				$taken[] = $box->copy();
				//the stored copy must not follow later changes of the box
				$box->encapsulatePoint($box->maxX + 30, $box->maxY, $box->maxZ);
			}
		}
		self::assertGreaterThan(100, $fits);
		self::assertLessThan(2900, $fits);
	}
}
