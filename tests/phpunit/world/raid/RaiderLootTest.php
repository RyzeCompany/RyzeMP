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
use pocketmine\item\Durable;
use pocketmine\item\ItemTypeIds;
use pocketmine\world\World;
use function count;
use function mt_srand;

final class RaiderLootTest extends TestCase{
	private const ROLLS = 6000;

	/**
	 * @return int[] [rolls with a non-emerald or an extra emerald stack (bonus), total emeralds, gear items, books]
	 * @phpstan-return array{int, int, int, int}
	 */
	private static function sample(int $difficulty, int $looting = 0) : array{
		mt_srand(1234);
		$bonus = 0;
		$emeralds = 0;
		$gear = 0;
		$books = 0;
		for($i = 0; $i < self::ROLLS; ++$i){
			$drops = RaiderLoot::roll($difficulty, $looting, 0.5);
			$stacks = 0;
			foreach($drops as $item){
				if($item->getTypeId() === ItemTypeIds::EMERALD){
					$emeralds += $item->getCount();
					++$stacks;
				}elseif($item instanceof Durable){
					++$gear;
					$left = 1 - $item->getDamage() / $item->getMaxDurability();
					self::assertGreaterThanOrEqual(0.29, $left);
					self::assertLessThanOrEqual(0.91, $left);
				}else{
					++$books;
				}
			}
			if($stacks > 1 || count($drops) > $stacks){
				++$bonus;
			}
		}
		return [$bonus, $emeralds, $gear, $books];
	}

	public function testPeacefulHasNoBonusRoll() : void{
		[, $emeralds, $gear, $books] = self::sample(World::DIFFICULTY_PEACEFUL);
		self::assertSame(0, $gear);
		self::assertSame(0, $books);
		//only the 0-1 emerald: about half a roll on average
		self::assertEqualsWithDelta(0.5, $emeralds / self::ROLLS, 0.03);
	}

	public function testBonusRates() : void{
		[, , $gearNormal, $booksNormal] = self::sample(World::DIFFICULTY_NORMAL);
		[, , $gearHard, $booksHard] = self::sample(World::DIFFICULTY_HARD);
		//gear: weight 80 of 156; books: 8 of 156
		self::assertEqualsWithDelta(0.65 * 80 / 156, $gearNormal / self::ROLLS, 0.025);
		self::assertEqualsWithDelta(0.8 * 80 / 156, $gearHard / self::ROLLS, 0.025);
		self::assertEqualsWithDelta(0.65 * 8 / 156, $booksNormal / self::ROLLS, 0.01);
		self::assertEqualsWithDelta(0.8 * 8 / 156, $booksHard / self::ROLLS, 0.01);
	}

	public function testEmeraldAverage() : void{
		[, $emeralds] = self::sample(World::DIFFICULTY_NORMAL);
		//0.5 always + 0.65 * (40 * 0.5 + 20 * 2.5 + 8 * 4.5) / 156
		$expected = 0.5 + 0.65 * (40 * 0.5 + 20 * 2.5 + 8 * 4.5) / 156;
		self::assertEqualsWithDelta($expected, $emeralds / self::ROLLS, 0.06);
	}

	public function testLootingAddsEmeralds() : void{
		[, $plain] = self::sample(World::DIFFICULTY_NORMAL);
		[, $looting] = self::sample(World::DIFFICULTY_NORMAL, 3);
		self::assertGreaterThan($plain * 1.5, $looting);
	}
}
