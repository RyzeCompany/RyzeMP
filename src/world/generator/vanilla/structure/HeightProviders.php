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

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\WorldgenData;
use function is_array;
use function is_int;

/**
 * Height providers and vertical anchors of structure data (start_height, nether fossil height).
 */
final class HeightProviders{

	private function __construct(){
		//NOOP
	}

	/**
	 * VerticalAnchor: {"absolute": y}, {"above_bottom": n}, {"below_top": n} or a plain y.
	 * @phpstan-return \Closure(int, int) : int (minY, maxY inclusive) => y
	 */
	public static function anchor(mixed $json) : \Closure{
		if(is_int($json)){
			return fn(int $minY, int $maxY) : int => $json;
		}
		if(!is_array($json)){
			throw new \RuntimeException("Invalid vertical anchor");
		}
		if(isset($json["absolute"])){
			$y = WorldgenData::int($json, "absolute");
			return fn(int $minY, int $maxY) : int => $y;
		}
		if(isset($json["above_bottom"])){
			$offset = WorldgenData::int($json, "above_bottom");
			return fn(int $minY, int $maxY) : int => $minY + $offset;
		}
		if(isset($json["below_top"])){
			$offset = WorldgenData::int($json, "below_top");
			return fn(int $minY, int $maxY) : int => $maxY - $offset;
		}
		throw new \RuntimeException("Invalid vertical anchor");
	}

	/**
	 * HeightProvider: constant (or a plain anchor) and uniform.
	 * @phpstan-return \Closure(LegacyRandom, int, int) : int (random, minY, maxY inclusive) => y
	 */
	public static function parse(mixed $json) : \Closure{
		if(is_array($json) && isset($json["type"])){
			$type = WorldgenData::stripNamespace(WorldgenData::string($json, "type"));
			switch($type){
				case "constant":
					$anchor = self::anchor($json["value"] ?? null);
					return fn(LegacyRandom $random, int $minY, int $maxY) : int => $anchor($minY, $maxY);
				case "uniform":
					$min = self::anchor($json["min_inclusive"] ?? null);
					$max = self::anchor($json["max_inclusive"] ?? null);
					return function(LegacyRandom $random, int $minY, int $maxY) use ($min, $max) : int{
						$a = $min($minY, $maxY);
						$b = $max($minY, $maxY);
						return $a > $b ? $a : StructureRandom::between($random, $a, $b);
					};
				default:
					throw new \RuntimeException("Unsupported height provider $type");
			}
		}
		$anchor = self::anchor($json);
		return fn(LegacyRandom $random, int $minY, int $maxY) : int => $anchor($minY, $maxY);
	}
}
