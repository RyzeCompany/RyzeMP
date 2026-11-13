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

use pocketmine\utils\Filesystem;
use function is_array;
use function json_decode;
use const JSON_THROW_ON_ERROR;

/**
 * Reference values computed with misode/deepslate 0.27.2 (MIT) in Node, see deepslate-fixtures.json.
 * The file only contains numbers (no Mojang data).
 */
final class FixtureLoader{
	/** @phpstan-var array<string, mixed>|null */
	private static ?array $fixtures = null;

	private static function raw(string $key) : mixed{
		if(self::$fixtures === null){
			/** @phpstan-var array<string, mixed> $data */
			$data = json_decode(Filesystem::fileGetContents(__DIR__ . "/deepslate-fixtures.json"), true, 512, JSON_THROW_ON_ERROR);
			self::$fixtures = $data;
		}
		if(!isset(self::$fixtures[$key]) || !is_array(self::$fixtures[$key])){
			throw new \RuntimeException("Missing fixture $key");
		}
		return self::$fixtures[$key];
	}

	/**
	 * @phpstan-return list<array{float, float, float, float}>
	 */
	public static function points(string $key) : array{
		/** @phpstan-var list<array{float, float, float, float}> $value */
		$value = self::raw($key);
		return $value;
	}

	/**
	 * @phpstan-return list<string>
	 */
	public static function strings(string $key) : array{
		/** @phpstan-var list<string> $value */
		$value = self::raw($key);
		return $value;
	}

	/**
	 * @phpstan-return list<float>
	 */
	public static function floats(string $key) : array{
		/** @phpstan-var list<float> $value */
		$value = self::raw($key);
		return $value;
	}

	/**
	 * @phpstan-return list<array{int, int}>
	 */
	public static function intPairs(string $key) : array{
		/** @phpstan-var list<array{int, int}> $value */
		$value = self::raw($key);
		return $value;
	}

	/**
	 * @phpstan-return list<array{string, string}>
	 */
	public static function stringPairs(string $key) : array{
		/** @phpstan-var list<array{string, string}> $value */
		$value = self::raw($key);
		return $value;
	}

	/**
	 * @phpstan-return array<string, array{points: list<array{float, float, float}>, values: list<array{float, float}>}>
	 */
	public static function splines() : array{
		/** @phpstan-var array<string, array{points: list<array{float, float, float}>, values: list<array{float, float}>}> $value */
		$value = self::raw("splines");
		return $value;
	}

	/**
	 * Router values: x, y, z, temperature, vegetation, continents, erosion, depth, ridges, final density.
	 * @phpstan-return array<string, array{router: list<list<float>>, biomes: list<array{int, int, int, string}>, chunksNoAquifer: list<array{cx: int, cz: int, md5: string}>}>
	 */
	public static function worldgen() : array{
		/** @phpstan-var array<string, array{router: list<list<float>>, biomes: list<array{int, int, int, string}>, chunksNoAquifer: list<array{cx: int, cz: int, md5: string}>}> $value */
		$value = self::raw("worldgen");
		return $value;
	}
}
