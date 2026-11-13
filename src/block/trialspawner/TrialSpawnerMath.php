<?php

/*
 * Ported from VanillaMine's LGPL-3.0-or-later WorldTrialSpawnerHost
 * source revision 4dd2228b47d64c8015c28138c846e6c4acb28345.
 */
declare(strict_types=1);

namespace pocketmine\block\trialspawner;

use pocketmine\item\Item;
use function floor;
use function mt_rand;

/**
 * World-independent helpers for VanillaMine trial spawner calculations.
 * Kept separate from Mob AI so these rules can be tested before entity integration.
 * @internal
 */
final class TrialSpawnerMath{
	private function __construct(){}

	/**
	 * @param array<array{Item, int}> $items
	 */
	public static function pickDispensedItem(array $items) : ?Item{
		$total = 0;
		foreach($items as [, $weight]){
			$total += $weight;
		}
		if($total <= 0){
			return null;
		}
		$roll = mt_rand(0, $total - 1);
		foreach($items as [$item, $weight]){
			$roll -= $weight;
			if($roll < 0){
				return clone $item;
			}
		}
		return null;
	}

	/** Java BlockPos.asLong() of coordinates reduced to 30 x 20 x 30 cells. */
	public static function lowResolutionPosition(int $x, int $y, int $z) : int{
		$lx = (int) floor($x / 30.0);
		$ly = (int) floor($y / 20.0);
		$lz = (int) floor($z / 30.0);
		return (($lx & 0x3ffffff) << 38) | (($lz & 0x3ffffff) << 12) | ($ly & 0xfff);
	}

	/** Add signed 64-bit integers with Java-style wraparound on PHP 64-bit. */
	public static function addLong(int $a, int $b) : int{
		$low = ($a & 0xffffffff) + ($b & 0xffffffff);
		$high = (($a >> 32) + ($b >> 32) + ($low >> 32)) & 0xffffffff;
		return ($high << 32) | ($low & 0xffffffff);
	}
}
