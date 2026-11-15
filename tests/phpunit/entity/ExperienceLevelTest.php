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

namespace pocketmine\entity;

use PHPUnit\Framework\TestCase;
use pocketmine\entity\utils\ExperienceUtils;
use pocketmine\utils\Binary;
use function floor;

final class ExperienceLevelTest extends TestCase{

	/**
	 * @return \Generator<int, array{int, float}, void, void>
	 */
	public static function pointsProvider() : \Generator{
		yield [0, 0.0];
		yield [2, 2 / 7]; //3 smelted iron (furnace_xp): the bar shows 2/7, not 0.317
		yield [3, 3 / 7];
		yield [7, 1.0];
		yield [8, 1 + 1 / 9];
		yield [352, 16.0];
		yield [353, 16 + 1 / 42];
		yield [1408, 30 + 13 / 112];
	}

	/**
	 * @dataProvider pointsProvider
	 */
	public function testProgressIsLinearInPoints(int $xp, float $expected) : void{
		self::assertEqualsWithDelta($expected, ExperienceUtils::getLevelFromXp($xp), 1e-9);
	}

	public function testRoundTrip() : void{
		for($level = 0; $level <= 60; ++$level){
			$reach = ExperienceUtils::getXpToReachLevel($level);
			self::assertSame((float) $level, ExperienceUtils::getLevelFromXp($reach), "level $level");
			$complete = ExperienceUtils::getXpToCompleteLevel($level);
			for($points = 0; $points < $complete; ++$points){
				$value = ExperienceUtils::getLevelFromXp($reach + $points);
				self::assertSame($level, (int) $value, "level $level + $points points");
				//what ExperienceManager::getRemainderXp() reads back, also after saving as a 32-bit float
				$progress = $value - $level;
				$progress32 = Binary::readLFloat(Binary::writeLFloat($progress));
				self::assertSame($points, (int) floor($complete * $progress + 0.0001), "level $level + $points points");
				self::assertSame($points, (int) floor($complete * $progress32 + 0.0001), "level $level + $points points (float32)");
			}
		}
	}
}
