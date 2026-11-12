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
use function array_keys;
use function file_exists;
use function is_array;
use function is_dir;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function str_starts_with;
use function substr;
use const JSON_THROW_ON_ERROR;

/**
 * Read-only access to the vanilla worldgen data files (Java Edition data, © Mojang) in a local cache directory.
 * The data is NOT shipped with PocketMine-MP; see {@link VanillaDataFetcher} for how the cache is filled.
 *
 * Layout (as written by the Java Edition data generator, see VanillaDataFetcher::EXTRACTED_PATHS):
 *   <root>/data/minecraft/worldgen/{noise,density_function,noise_settings,material_rule,material_condition,biome,...}
 *   <root>/reports/biome_parameters/minecraft/<dimension>.json   (multi-noise biome parameter points)
 *   <root>/multi_noise_biome_tree.json   (overworld biome search tree built from the parameters)
 *   <root>/version.json   (version stamp, see VanillaDataFetcher)
 */
final class WorldgenData{
	/** @phpstan-var array<string, mixed[]|string|int|float|bool|null> */
	private array $cache = [];
	/** @phpstan-var array<string, self> */
	private static array $shared = [];
	/** @phpstan-var array<string, list<string>> */
	private array $multiNoiseBiomes = [];

	public function __construct(
		private string $root
	){
		if(!is_dir($root . "/data/minecraft/worldgen")){
			throw new \RuntimeException("Vanilla worldgen data not found in $root (run the vanilla data fetcher first)");
		}
	}

	/**
	 * Returns a shared instance for the given directory: the generators of a world's dimensions (and of several
	 * worlds) in the same thread then parse each file only once.
	 */
	public static function shared(string $root) : self{
		return self::$shared[$root] ??= new self($root);
	}

	public function getRoot() : string{
		return $this->root;
	}

	public static function stripNamespace(string $id) : string{
		return str_starts_with($id, "minecraft:") ? substr($id, 10) : $id;
	}

	/**
	 * @return mixed[]|string|int|float|bool|null
	 */
	public function read(string $relativePath) : mixed{
		if(!isset($this->cache[$relativePath])){
			$decoded = $this->decode($relativePath);
			$this->cache[$relativePath] = $decoded;
		}
		return $this->cache[$relativePath];
	}

	/**
	 * Reads a file without keeping it in the cache: for the big biome files (~25 MB decoded) that are only read to
	 * build biome trees and lists, which callers keep themselves. Every thread that generates holds a WorldgenData.
	 * @return mixed[]|string|int|float|bool|null
	 */
	private function readUncached(string $relativePath) : mixed{
		return $this->cache[$relativePath] ?? $this->decode($relativePath);
	}

	/** @return mixed[] */
	private function readUncachedObject(string $relativePath) : array{
		$data = $this->readUncached($relativePath);
		if(!is_array($data)){
			throw new \RuntimeException("Expected a JSON object in $relativePath");
		}
		return $data;
	}

	/** @return mixed[]|string|int|float|bool|null */
	private function decode(string $relativePath) : mixed{
		$path = $this->root . "/" . $relativePath;
		if(!file_exists($path)){
			throw new \RuntimeException("Missing vanilla data file $path");
		}
		/** @phpstan-var mixed[]|string|int|float|bool|null $decoded */
		$decoded = json_decode(Filesystem::fileGetContents($path), true, 512, JSON_THROW_ON_ERROR);
		return $decoded;
	}

	/**
	 * Biomes of a dimension's multi-noise biome source in their first-appearance order in the biome parameters.
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function multiNoiseBiomes(string $dimension) : array{
		if(!isset($this->multiNoiseBiomes[$dimension])){
			$biomes = [];
			foreach(self::array($this->biomeParameters($dimension), "biomes") as $entry){
				if(is_array($entry) && is_string($entry["biome"] ?? null) && !isset($biomes[$entry["biome"]])){
					$biomes[$entry["biome"]] = true;
				}
			}
			$this->multiNoiseBiomes[$dimension] = array_keys($biomes);
		}
		return $this->multiNoiseBiomes[$dimension];
	}

	/**
	 * @return mixed[]
	 */
	public function readObject(string $relativePath) : array{
		$data = $this->read($relativePath);
		if(!is_array($data)){
			throw new \RuntimeException("Expected a JSON object in $relativePath");
		}
		return $data;
	}

	/** @return mixed[]|string|int|float|bool|null */
	public function densityFunction(string $id) : mixed{
		return $this->read("data/minecraft/worldgen/density_function/" . self::stripNamespace($id) . ".json");
	}

	/** @return mixed[] */
	public function noise(string $id) : array{
		return $this->readObject("data/minecraft/worldgen/noise/" . self::stripNamespace($id) . ".json");
	}

	/** @return mixed[] */
	public function noiseSettings(string $id) : array{
		return $this->readObject("data/minecraft/worldgen/noise_settings/" . self::stripNamespace($id) . ".json");
	}

	/**
	 * Search trees of the multi-noise biome sources by dimension (written by VanillaDataFetcher, also read by the
	 * native terrain core), or null if the cache has none.
	 * @return mixed[]|null
	 */
	public function biomeTree() : ?array{
		return file_exists($this->root . "/" . "multi_noise_biome_tree.json") ? $this->readUncachedObject("multi_noise_biome_tree.json") : null;
	}

	/** @return mixed[] */
	public function biomeParameters(string $dimension) : array{
		return $this->readUncachedObject("reports/biome_parameters/minecraft/" . self::stripNamespace($dimension) . ".json");
	}

	/** @return mixed[]|string|int|float|bool|null */
	public function materialRule(string $id) : mixed{
		return $this->read("data/minecraft/worldgen/material_rule/" . self::stripNamespace($id) . ".json");
	}

	/** @return mixed[]|string|int|float|bool|null */
	public function materialCondition(string $id) : mixed{
		return $this->read("data/minecraft/worldgen/material_condition/" . self::stripNamespace($id) . ".json");
	}

	/** @return mixed[] */
	public function biome(string $id) : array{
		return $this->readObject("data/minecraft/worldgen/biome/" . self::stripNamespace($id) . ".json");
	}

	/**
	 * Whether the cache has the carver definitions (worldgen/carver, 26.3+; older caches lack them).
	 */
	public function hasCarvers() : bool{
		return is_dir($this->root . "/data/minecraft/worldgen/carver");
	}

	/** @return mixed[] */
	public function carver(string $id) : array{
		return $this->readObject("data/minecraft/worldgen/carver/" . self::stripNamespace($id) . ".json");
	}

	/**
	 * Block ids of a block tag ("minecraft:uncarvable"), nested tags resolved, or null if the cache does not have it.
	 *
	 * @return string[]|null
	 * @phpstan-return list<string>|null
	 */
	public function blockTag(string $id, int $depth = 0) : ?array{
		$path = "data/minecraft/tags/block/" . self::stripNamespace($id) . ".json";
		if(!file_exists($this->root . "/" . $path)){
			return null;
		}
		if($depth > 16){
			throw new \RuntimeException("Block tag $id is nested too deeply");
		}
		$blocks = [];
		foreach(self::array($this->readObject($path), "values") as $value){
			$required = true;
			if(is_array($value)){
				$required = (bool) ($value["required"] ?? true);
				$value = $value["id"] ?? null;
			}
			if(!is_string($value)){
				throw new \RuntimeException("Invalid entry in block tag $id");
			}
			if(str_starts_with($value, "#")){
				$nested = $this->blockTag(substr($value, 1), $depth + 1);
				if($nested === null && $required){
					throw new \RuntimeException("Block tag $id references the missing tag $value");
				}
				foreach($nested ?? [] as $block){
					$blocks[] = $block;
				}
			}else{
				$blocks[] = "minecraft:" . self::stripNamespace($value);
			}
		}
		return $blocks;
	}

	// ---- typed accessors for decoded JSON (phpstan level 9 friendly) ----

	/**
	 * @param mixed[] $obj
	 */
	public static function float(array $obj, string $key, ?float $default = null) : float{
		$v = $obj[$key] ?? null;
		if(is_float($v) || is_int($v)){
			return (float) $v;
		}
		if($v === null && $default !== null){
			return $default;
		}
		throw new \RuntimeException("Expected number for \"$key\"");
	}

	/**
	 * @param mixed[] $obj
	 */
	public static function int(array $obj, string $key, ?int $default = null) : int{
		$v = $obj[$key] ?? null;
		if(is_int($v)){
			return $v;
		}
		if($v === null && $default !== null){
			return $default;
		}
		throw new \RuntimeException("Expected integer for \"$key\"");
	}

	/**
	 * @param mixed[] $obj
	 */
	public static function string(array $obj, string $key, ?string $default = null) : string{
		$v = $obj[$key] ?? null;
		if(is_string($v)){
			return $v;
		}
		if($v === null && $default !== null){
			return $default;
		}
		throw new \RuntimeException("Expected string for \"$key\"");
	}

	/**
	 * @param mixed[] $obj
	 * @return mixed[]
	 */
	public static function array(array $obj, string $key) : array{
		$v = $obj[$key] ?? null;
		if(is_array($v)){
			return $v;
		}
		throw new \RuntimeException("Expected array for \"$key\"");
	}

	/**
	 * @param mixed[] $obj
	 * @return float[]
	 * @phpstan-return list<float>
	 */
	public static function floatList(array $obj, string $key) : array{
		$result = [];
		foreach(self::array($obj, $key) as $v){
			if(!is_float($v) && !is_int($v)){
				throw new \RuntimeException("Expected number list for \"$key\"");
			}
			$result[] = (float) $v;
		}
		return $result;
	}
}
