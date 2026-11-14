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
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function preg_replace;
use function str_contains;
use function strlen;
use function strpos;
use function substr;
use const JSON_THROW_ON_ERROR;

/**
 * Reads Bedrock spawn rule files (behavior_pack/spawn_rules/*.json). Mojang's files are "JSON with comments": they
 * contain // and /* *\/ comments and sometimes trailing commas, which are removed before decoding.
 */
final class SpawnRuleParser{
	/**
	 * Legacy block names used by some rules => current Bedrock names.
	 */
	private const BLOCK_ALIASES = [
		"minecraft:grass" => "minecraft:grass_block",
	];

	private const DIFFICULTIES = [
		"peaceful" => 0,
		"easy" => 1,
		"normal" => 2,
		"hard" => 3,
	];

	private function __construct(){
		//NOOP
	}

	/**
	 * Removes comments (outside strings) and trailing commas.
	 */
	public static function stripJsonComments(string $json) : string{
		$out = "";
		$length = strlen($json);
		$inString = false;
		for($i = 0; $i < $length; ++$i){
			$c = $json[$i];
			if($inString){
				$out .= $c;
				if($c === "\\" && $i + 1 < $length){
					$out .= $json[++$i];
				}elseif($c === "\""){
					$inString = false;
				}
				continue;
			}
			if($c === "\""){
				$inString = true;
				$out .= $c;
				continue;
			}
			if($c === "/" && $i + 1 < $length && $json[$i + 1] === "/"){
				while($i < $length && $json[$i] !== "\n"){
					++$i;
				}
				$out .= "\n";
				continue;
			}
			if($c === "/" && $i + 1 < $length && $json[$i + 1] === "*"){
				$end = strpos($json, "*/", $i + 2);
				$i = $end === false ? $length : $end + 1;
				continue;
			}
			$out .= $c;
		}
		return preg_replace('/,(\s*[}\]])/', '$1', $out) ?? $out;
	}

	/**
	 * Parses a spawn rule file. Returns its conditions (empty for mobs without natural spawning, e.g. cats or
	 * guardians, whose rule only declares the population type).
	 *
	 * @return SpawnCondition[]
	 * @phpstan-return list<SpawnCondition>
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function parse(string $json) : array{
		try{
			$data = json_decode(self::stripJsonComments($json), true, 64, JSON_THROW_ON_ERROR);
		}catch(\JsonException $e){
			throw new \InvalidArgumentException("Invalid spawn rule JSON: " . $e->getMessage(), 0, $e);
		}
		$rules = is_array($data) ? ($data["minecraft:spawn_rules"] ?? null) : null;
		if(!is_array($rules)){
			throw new \InvalidArgumentException("Missing minecraft:spawn_rules");
		}
		$description = $rules["description"] ?? null;
		$identifier = is_array($description) ? ($description["identifier"] ?? null) : null;
		$population = is_array($description) ? ($description["population_control"] ?? null) : null;
		if(!is_string($identifier) || !is_string($population)){
			throw new \InvalidArgumentException("Missing identifier or population_control");
		}
		$conditions = [];
		foreach(self::asList($rules["conditions"] ?? []) as $condition){
			if(is_array($condition)){
				$conditions[] = self::parseCondition($identifier, $population, $condition);
			}
		}
		return $conditions;
	}

	/**
	 * Returns the population control ("monster", "animal"...) and identifier declared by a spawn rule file.
	 *
	 * @return string[]
	 * @phpstan-return array{string, string}
	 */
	public static function parseDescription(string $json) : array{
		$data = json_decode(self::stripJsonComments($json), true, 64, JSON_THROW_ON_ERROR);
		$description = is_array($data) && is_array($data["minecraft:spawn_rules"] ?? null) ? ($data["minecraft:spawn_rules"]["description"] ?? null) : null;
		if(!is_array($description) || !is_string($description["identifier"] ?? null) || !is_string($description["population_control"] ?? null)){
			throw new \InvalidArgumentException("Missing identifier or population_control");
		}
		return [$description["identifier"], $description["population_control"]];
	}

	/**
	 * @param mixed[] $c
	 */
	private static function parseCondition(string $identifier, string $population, array $c) : SpawnCondition{
		$brightness = self::obj($c, "minecraft:brightness_filter");
		$difficulty = self::obj($c, "minecraft:difficulty_filter");
		$height = self::obj($c, "minecraft:height_filter");
		$distance = self::obj($c, "minecraft:distance_filter");
		$density = self::obj($c, "minecraft:density_limit");
		$weight = self::obj($c, "minecraft:weight");
		$spawnEvent = self::obj($c, "minecraft:spawn_event");

		$herds = [];
		foreach(self::asList($c["minecraft:herd"] ?? []) as $herd){
			if(is_array($herd)){
				$herds[] = new SpawnHerd(
					self::int($herd, "min_size") ?? 1,
					self::int($herd, "max_size") ?? self::int($herd, "min_size") ?? 1,
					self::str($herd, "event"),
					self::int($herd, "event_skip_count") ?? 0,
					self::str($herd, "initial_event"),
					self::int($herd, "initial_event_count") ?? 0
				);
			}
		}

		$permute = [];
		foreach(self::asList($c["minecraft:permute_type"] ?? []) as $entry){
			if(is_array($entry)){
				$type = self::str($entry, "entity_type");
				if($type !== null && str_contains($type, "<")){
					$type = substr($type, 0, (int) strpos($type, "<")); //"minecraft:pillager<event>": the event is not used
				}
				$permute[] = [$type, self::int($entry, "weight") ?? 0];
			}
		}

		return new SpawnCondition(
			identifier: $identifier,
			population: $population,
			onSurface: isset($c["minecraft:spawns_on_surface"]),
			underground: isset($c["minecraft:spawns_underground"]),
			underwater: isset($c["minecraft:spawns_underwater"]),
			lava: isset($c["minecraft:spawns_lava"]),
			weight: $weight !== null ? (self::int($weight, "default") ?? 0) : 0,
			herds: $herds,
			brightnessMin: $brightness !== null ? self::int($brightness, "min") : null,
			brightnessMax: $brightness !== null ? self::int($brightness, "max") : null,
			adjustForWeather: $brightness !== null && ($brightness["adjust_for_weather"] ?? false) === true,
			difficultyMin: self::DIFFICULTIES[$difficulty !== null ? (self::str($difficulty, "min") ?? "peaceful") : "peaceful"] ?? 0,
			difficultyMax: self::DIFFICULTIES[$difficulty !== null ? (self::str($difficulty, "max") ?? "hard") : "hard"] ?? 3,
			heightMin: $height !== null ? self::int($height, "min") : null,
			heightMax: $height !== null ? self::int($height, "max") : null,
			distanceMin: $distance !== null ? self::int($distance, "min") : null,
			distanceMax: $distance !== null ? self::int($distance, "max") : null,
			densitySurface: $density !== null ? self::int($density, "surface") : null,
			densityUnderground: $density !== null ? self::int($density, "underground") : null,
			biomeFilter: $c["minecraft:biome_filter"] ?? null,
			onBlocks: isset($c["minecraft:spawns_on_block_filter"]) ? self::blocks($c["minecraft:spawns_on_block_filter"]) : null,
			preventedBlocks: isset($c["minecraft:spawns_on_block_prevented_filter"]) ? self::blocks($c["minecraft:spawns_on_block_prevented_filter"]) : [],
			permuteTypes: $permute,
			spawnEvent: $spawnEvent !== null ? self::str($spawnEvent, "event") : null,
			disallowBubble: isset($c["minecraft:disallow_spawns_in_bubble"]),
			timed: isset($c["minecraft:delay_filter"]) || isset($c["minecraft:world_age_filter"]) || isset($c["minecraft:mob_event_filter"]) || isset($c["minecraft:player_in_village_filter"])
		);
	}

	/**
	 * @return array<string, true>
	 */
	private static function blocks(mixed $value) : array{
		$blocks = [];
		foreach(self::asList($value) as $entry){
			$name = is_string($entry) ? $entry : (is_array($entry) ? self::str($entry, "name") : null);
			if($name !== null){
				if(!str_contains($name, ":")){
					$name = "minecraft:" . $name;
				}
				$blocks[self::BLOCK_ALIASES[$name] ?? $name] = true;
			}
		}
		return $blocks;
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
	 * @param mixed[] $c
	 * @return mixed[]|null
	 */
	private static function obj(array $c, string $key) : ?array{
		$value = $c[$key] ?? null;
		return is_array($value) ? $value : null;
	}

	/**
	 * @param mixed[] $c
	 */
	private static function int(array $c, string $key) : ?int{
		$value = $c[$key] ?? null;
		return is_int($value) ? $value : (is_float($value) ? (int) $value : null);
	}

	/**
	 * @param mixed[] $c
	 */
	private static function str(array $c, string $key) : ?string{
		$value = $c[$key] ?? null;
		return is_string($value) ? $value : null;
	}
}
