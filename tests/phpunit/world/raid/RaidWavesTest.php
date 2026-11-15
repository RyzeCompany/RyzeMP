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

namespace pocketmine\world\raid;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\world\World;

final class RaidWavesTest extends TestCase{

	public function testWaveCountByDifficulty() : void{
		self::assertSame(0, RaidWaves::getWaveCount(World::DIFFICULTY_PEACEFUL));
		self::assertSame(3, RaidWaves::getWaveCount(World::DIFFICULTY_EASY));
		self::assertSame(5, RaidWaves::getWaveCount(World::DIFFICULTY_NORMAL));
		self::assertSame(7, RaidWaves::getWaveCount(World::DIFFICULTY_HARD));
	}

	/**
	 * Bedrock totals: 13 mobs on Easy, 26 on Normal, 45 on Hard.
	 */
	public function testTotals() : void{
		$sizes = [];
		for($wave = 1; $wave <= 7; ++$wave){
			$sizes[] = RaidWaves::getWaveSize($wave);
		}
		self::assertSame([4, 5, 4, 6, 7, 8, 11], $sizes);
		foreach([World::DIFFICULTY_EASY => 13, World::DIFFICULTY_NORMAL => 26, World::DIFFICULTY_HARD => 45] as $difficulty => $total){
			$sum = 0;
			for($wave = 1; $wave <= RaidWaves::getWaveCount($difficulty); ++$wave){
				$sum += RaidWaves::getWaveSize($wave);
			}
			self::assertSame($total, $sum, "difficulty $difficulty");
		}
	}

	public function testComposition() : void{
		$count = function(int $wave, string $type, ?string $rider = null) : int{
			$n = 0;
			foreach(RaidWaves::getWave($wave) as [$t, $r, $c]){
				if($t === $type && $r === $rider){
					$n += $c;
				}
			}
			return $n;
		};
		self::assertSame(4, $count(1, EntityIds::PILLAGER));
		self::assertSame(2, $count(2, EntityIds::VINDICATOR));
		self::assertSame(1, $count(3, EntityIds::RAVAGER));
		self::assertSame(3, $count(4, EntityIds::WITCH));
		self::assertSame(1, $count(5, EntityIds::EVOCATION_ILLAGER));
		self::assertSame(1, $count(5, EntityIds::RAVAGER, EntityIds::PILLAGER));
		self::assertSame(5, $count(6, EntityIds::PILLAGER));
		self::assertSame(6, $count(7, EntityIds::VINDICATOR));
		self::assertSame(2, $count(7, EntityIds::EVOCATION_ILLAGER));
		self::assertSame(1, $count(7, EntityIds::RAVAGER, EntityIds::EVOCATION_ILLAGER));
		self::assertSame(1, $count(7, EntityIds::RAVAGER, EntityIds::PILLAGER));
		//riders only ride ravagers, only in waves 5 and 7
		for($wave = 1; $wave <= 7; ++$wave){
			foreach(RaidWaves::getWave($wave) as [$type, $rider]){
				if($rider !== null){
					self::assertSame(EntityIds::RAVAGER, $type);
					self::assertContains($wave, [5, 7]);
				}
			}
		}
	}

	public function testNoEighthWave() : void{
		$this->expectException(\InvalidArgumentException::class);
		RaidWaves::getWave(8);
	}
}
