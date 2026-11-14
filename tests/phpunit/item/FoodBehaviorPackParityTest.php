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

namespace pocketmine\item;

use PHPUnit\Framework\TestCase;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\entity\effect\StringToEffectParser;
use pocketmine\entity\FoodSource;
use pocketmine\utils\Utils;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use function array_keys;
use function file_get_contents;
use function getenv;
use function glob;
use function implode;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function json_decode;
use function ksort;
use function mt_srand;
use function sort;
use function spl_object_id;
use function sprintf;
use function strlen;
use const JSON_THROW_ON_ERROR;

/**
 * Checks every food item against the minecraft:food component of the vanilla behaviour pack items.
 * bp_food_1.26.50.4.json holds that component of every behavior_pack/items/*.json that has one; set BEDROCK_SAMPLES_BP
 * to a behavior_pack directory to check that the fixture still matches it.
 *
 * @phpstan-type FoodEffect array{name: string, duration: int, amplifier: int, chance: float}
 * @phpstan-type FoodData array{
 *     nutrition: int,
 *     saturation_modifier: string|float,
 *     can_always_eat: bool,
 *     effects: list<FoodEffect>,
 *     remove_effects?: list<string>,
 *     on_use_action?: string
 * }
 */
class FoodBehaviorPackParityTest extends TestCase{

	private const FIXTURE = __DIR__ . "/bp_food_1.26.50.4.json";

	/** Pre-flattening identifiers used by the behaviour pack files => current item IDs */
	private const ALIASES = [
		"minecraft:appleEnchanted" => "minecraft:enchanted_golden_apple",
		"minecraft:clownfish" => "minecraft:tropical_fish",
		"minecraft:cooked_fish" => "minecraft:cooked_cod",
		"minecraft:fish" => "minecraft:cod",
		"minecraft:melon" => "minecraft:melon_slice",
		"minecraft:muttonCooked" => "minecraft:cooked_mutton",
		"minecraft:muttonRaw" => "minecraft:mutton",
	];

	private const SATURATION_MODIFIERS = [
		"poor" => 0.1,
		"low" => 0.3,
		"normal" => 0.6,
		"good" => 0.8,
		"max" => 1.0,
		"supernatural" => 1.2,
	];

	/** The effects of these on_use_action values come from the item's own data (suspicious stew) */
	private const DATA_DRIVEN_EFFECTS = ["suspicious_stew_effect"];

	private const CHANCE_SAMPLES = 4000;
	private const CHANCE_TOLERANCE = 0.03;

	/**
	 * @phpstan-return array<string, FoodData>
	 */
	private static function loadFixture() : array{
		$raw = file_get_contents(self::FIXTURE);
		if($raw === false){
			throw new \RuntimeException("Missing " . self::FIXTURE);
		}
		/** @phpstan-var array<string, FoodData> $data */
		$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		return $data;
	}

	/**
	 * @phpstan-return \Generator<string, array{string, FoodData}>
	 */
	public static function foodProvider() : \Generator{
		foreach(Utils::stringifyKeys(self::loadFixture()) as $id => $food){
			yield $id => [$id, $food];
		}
	}

	private static function saturationModifier(string|float $modifier) : float{
		if(is_float($modifier)){
			return $modifier;
		}
		return self::SATURATION_MODIFIERS[$modifier] ?? throw new \InvalidArgumentException("Unknown saturation modifier $modifier");
	}

	/**
	 * @phpstan-param FoodData $food
	 * @dataProvider foodProvider
	 */
	public function testFoodMatchesBehaviorPack(string $id, array $food) : void{
		$item = GlobalItemDataHandlers::getDeserializer()->deserializeType(new SavedItemData(self::ALIASES[$id] ?? $id));
		self::assertInstanceOf(FoodSource::class, $item, "$id is not a food");

		$nutrition = $food["nutrition"];
		self::assertSame($nutrition, $item->getFoodRestore(), "$id nutrition");
		$saturation = $nutrition * self::saturationModifier($food["saturation_modifier"]) * 2;
		self::assertEqualsWithDelta($saturation, $item->getSaturationRestore(), 0.0001, "$id saturation");
		self::assertSame(!$food["can_always_eat"], $item->requiresHunger(), "$id can_always_eat");

		if(isset($food["on_use_action"]) && in_array($food["on_use_action"], self::DATA_DRIVEN_EFFECTS, true)){
			return;
		}

		$expected = []; //effect object ID => behaviour pack effect
		$random = false;
		foreach($food["effects"] as $effect){
			$type = StringToEffectParser::getInstance()->parse($effect["name"]);
			self::assertNotNull($type, "$id: unknown effect {$effect["name"]}");
			$expected[spl_object_id($type)] = $effect;
			$random = $random || $effect["chance"] < 1.0;
		}

		$samples = $random ? self::CHANCE_SAMPLES : 1;
		mt_srand(0x5eed);
		$seen = [];
		for($i = 0; $i < $samples; $i++){
			foreach($item->getAdditionalEffects() as $instance){
				$key = spl_object_id($instance->getType());
				self::assertArrayHasKey($key, $expected, "$id gives an effect the behaviour pack doesn't list");
				$effectName = $expected[$key]["name"];
				self::assertSame($expected[$key]["duration"] * 20, $instance->getDuration(), "$id $effectName duration");
				self::assertSame($expected[$key]["amplifier"], $instance->getAmplifier(), "$id $effectName amplifier");
				$seen[$key] = ($seen[$key] ?? 0) + 1;
			}
		}
		mt_srand();
		foreach($expected as $key => $effect){
			$rate = ($seen[$key] ?? 0) / $samples;
			self::assertEqualsWithDelta($effect["chance"], $rate, $random ? self::CHANCE_TOLERANCE : 0.0, "$id {$effect["name"]} chance");
		}
	}

	/**
	 * Food items the behaviour pack doesn't know would escape the check above.
	 */
	public function testEveryFoodItemIsCovered() : void{
		$known = [];
		foreach(Utils::stringifyKeys(self::loadFixture()) as $id => $food){
			$known[self::ALIASES[$id] ?? $id] = true;
		}
		$missing = [];
		foreach(VanillaItems::getAll() as $item){
			if(!$item instanceof FoodSource){
				continue;
			}
			$name = GlobalItemDataHandlers::getSerializer()->serializeType($item)->getName();
			if(!isset($known[$name])){
				$missing[] = $name;
			}
		}
		sort($missing);
		self::assertSame([], $missing, "Food items missing from the behaviour pack fixture: " . implode(", ", $missing));
	}

	public function testFixtureMatchesBehaviorPack() : void{
		$dir = getenv("BEDROCK_SAMPLES_BP");
		if($dir === false || $dir === ""){
			self::markTestSkipped("Set BEDROCK_SAMPLES_BP to a behavior_pack directory to check the fixture");
		}
		$files = glob($dir . "/items/*.json");
		self::assertNotFalse($files);
		self::assertNotEmpty($files, "No item files in $dir/items");

		$extracted = [];
		foreach($files as $file){
			$raw = file_get_contents($file);
			self::assertNotFalse($raw);
			/**
			 * @phpstan-var array{"minecraft:item": array{
			 *     description: array{identifier: string},
			 *     components: array{"minecraft:food"?: array{
			 *         nutrition: int,
			 *         saturation_modifier: string|float,
			 *         can_always_eat?: bool,
			 *         effects?: list<array{name: string, duration: int, amplifier?: int, chance?: float|int}>,
			 *         remove_effects?: list<string>,
			 *         on_use_action?: string
			 *     }}
			 * }} $json
			 */
			$json = json_decode(self::stripComments($raw), true, 512, JSON_THROW_ON_ERROR);
			$food = $json["minecraft:item"]["components"]["minecraft:food"] ?? null;
			if(!is_array($food)){
				continue;
			}
			$effects = [];
			foreach($food["effects"] ?? [] as $effect){
				$effects[] = [
					"name" => $effect["name"],
					"duration" => $effect["duration"],
					"amplifier" => $effect["amplifier"] ?? 0,
					"chance" => (float) ($effect["chance"] ?? 1.0),
				];
			}
			$entry = [
				"nutrition" => $food["nutrition"],
				"saturation_modifier" => is_int($food["saturation_modifier"]) ? (float) $food["saturation_modifier"] : $food["saturation_modifier"],
				"can_always_eat" => $food["can_always_eat"] ?? false,
				"effects" => $effects,
			];
			if(isset($food["remove_effects"])){
				$entry["remove_effects"] = $food["remove_effects"];
			}
			if(isset($food["on_use_action"])){
				$entry["on_use_action"] = $food["on_use_action"];
			}
			$extracted[$json["minecraft:item"]["description"]["identifier"]] = $entry;
		}
		ksort($extracted);

		$fixture = self::loadFixture();
		self::assertSame(array_keys($fixture), array_keys($extracted), "Food items differ from the fixture");
		foreach(Utils::stringifyKeys($extracted) as $id => $entry){
			self::assertEquals($fixture[$id], $entry, sprintf("%s differs from the fixture: regenerate %s", $id, self::FIXTURE));
		}
	}

	/**
	 * The behaviour pack files contain // comments, which json_decode() rejects.
	 */
	private static function stripComments(string $raw) : string{
		$result = "";
		$inString = false;
		$length = strlen($raw);
		for($i = 0; $i < $length; $i++){
			$c = $raw[$i];
			if($inString){
				$result .= $c;
				if($c === "\\" && $i + 1 < $length){
					$result .= $raw[++$i];
				}elseif($c === "\""){
					$inString = false;
				}
			}elseif($c === "\""){
				$inString = true;
				$result .= $c;
			}elseif($c === "/" && $i + 1 < $length && $raw[$i + 1] === "/"){
				while($i < $length && $raw[$i] !== "\n"){
					$i++;
				}
				$result .= "\n";
			}else{
				$result .= $c;
			}
		}
		return $result;
	}
}
