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

namespace pocketmine\world\mobs\spawn;

use function array_is_list;
use function is_array;
use function is_bool;
use function is_string;

/**
 * Evaluates the "minecraft:biome_filter" of Bedrock spawn rules: Minecraft filters ("all_of", "any_of", "none_of",
 * a list meaning all of them, or a single test) with the tests used by the vanilla rules:
 * - has_biome_tag: the biome has the tag (biome_definitions.json tags such as "monster", "ocean", "spawn_ghast");
 * - is_snow_covered: snow falls in the biome (base temperature below 0.15).
 * Operators: "==" / "equals" (default) and "!=" / "not" / "<>". Unknown tests fail, so a rule using them never
 * spawns rather than spawning everywhere.
 */
final class BiomeFilter{

	private function __construct(){
		//NOOP
	}

	/**
	 * @param array<string, true> $tags biome tags => true
	 */
	public static function test(mixed $filter, array $tags, bool $snowCovered) : bool{
		if($filter === null){
			return true;
		}
		if(!is_array($filter)){
			return false;
		}
		if(array_is_list($filter)){
			foreach($filter as $sub){
				if(!self::test($sub, $tags, $snowCovered)){
					return false;
				}
			}
			return true;
		}
		if(isset($filter["all_of"])){
			foreach(self::asList($filter["all_of"]) as $sub){
				if(!self::test($sub, $tags, $snowCovered)){
					return false;
				}
			}
		}
		if(isset($filter["any_of"])){
			$any = false;
			foreach(self::asList($filter["any_of"]) as $sub){
				if(self::test($sub, $tags, $snowCovered)){
					$any = true;
					break;
				}
			}
			if(!$any){
				return false;
			}
		}
		if(isset($filter["none_of"])){
			foreach(self::asList($filter["none_of"]) as $sub){
				if(self::test($sub, $tags, $snowCovered)){
					return false;
				}
			}
		}
		if(isset($filter["test"])){
			return self::testOne($filter, $tags, $snowCovered);
		}
		return true;
	}

	/**
	 * @return mixed[]
	 * @phpstan-return list<mixed>
	 */
	private static function asList(mixed $value) : array{
		if(is_array($value) && array_is_list($value)){
			return $value;
		}
		return [$value];
	}

	/**
	 * @param mixed[]             $filter
	 * @param array<string, true> $tags
	 */
	private static function testOne(array $filter, array $tags, bool $snowCovered) : bool{
		$operator = $filter["operator"] ?? "==";
		$negate = match($operator){
			"==", "=", "equals" => false,
			"!=", "<>", "not" => true,
			default => null
		};
		if($negate === null){
			return false;
		}
		$value = $filter["value"] ?? true;
		$result = match($filter["test"]){
			"has_biome_tag" => is_string($value) && isset($tags[$value]),
			"is_snow_covered" => $snowCovered === (is_bool($value) ? $value : true),
			default => null
		};
		if($result === null){
			return false;
		}
		return $negate ? !$result : $result;
	}
}
