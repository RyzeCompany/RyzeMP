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

/**
 * Generates generated/crafting/FurnaceExperienceTable.php: the experience stored by furnaces, blast furnaces and
 * smokers for each smelted item, by Bedrock result item name.
 *
 * Usage: php tools/generate-furnace-experience.php (--jar <Java server jar> | --recipes <dir of Java recipe JSONs>)
 *   [--output <file>]
 *
 * BedrockData has no experience values, so they come from the "experience" field of the Java smelting, blasting and
 * smoking recipes (data/minecraft/recipe/*.json), which are the same as Bedrock's (per wiki). Every Java recipe with
 * the same result has the same value, so the table is keyed by result; the script fails if that ever changes. Java
 * result names are mapped to Bedrock item names (JAVA_TO_BEDROCK), and every furnace result of the BedrockData
 * recipes must have a value.
 */

declare(strict_types=1);

namespace pocketmine\tools\generate_furnace_experience;

use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use function array_keys;
use function basename;
use function count;
use function dirname;
use function fwrite;
use function getopt;
use function glob;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function ksort;
use function mkdir;
use function preg_match;
use function str_ends_with;
use function str_starts_with;
use function var_export;
use const JSON_THROW_ON_ERROR;
use const PHP_EOL;
use const STDERR;

require dirname(__DIR__) . '/vendor/autoload.php';

/** Java result names whose Bedrock item name differs. */
const JAVA_TO_BEDROCK = [
	"minecraft:terracotta" => "minecraft:hardened_clay",
	"minecraft:light_gray_glazed_terracotta" => "minecraft:silver_glazed_terracotta",
	"minecraft:nether_brick" => "minecraft:netherbrick",
];

/** Recipe types of the furnace, blast furnace and smoker (campfires give no experience). */
const RECIPE_TYPES = ["minecraft:smelting", "minecraft:blasting", "minecraft:smoking"];

$options = getopt("", ["jar:", "recipes:", "output:"]);
$jar = $options["jar"] ?? null;
$recipesDir = $options["recipes"] ?? null;
$output = $options["output"] ?? dirname(__DIR__) . '/generated/crafting/FurnaceExperienceTable.php';
if((!is_string($jar) || !is_file($jar)) && (!is_string($recipesDir) || !is_dir($recipesDir))){
	fwrite(STDERR, "Usage: php tools/generate-furnace-experience.php (--jar <Java server jar> | --recipes <dir of Java recipe JSONs>) [--output <file>]" . PHP_EOL);
	exit(1);
}
if(!is_string($output)){
	fwrite(STDERR, "Options can only be given once" . PHP_EOL);
	exit(1);
}

/** @var string[] $sources file name => JSON */
$sources = [];
if(is_string($jar) && is_file($jar)){
	$zip = new \ZipArchive();
	if($zip->open($jar) !== true){
		fwrite(STDERR, "Can't open $jar" . PHP_EOL);
		exit(1);
	}
	for($i = 0; $i < $zip->numFiles; ++$i){
		$name = $zip->getNameIndex($i);
		if($name !== false && str_starts_with($name, "data/minecraft/recipe/") && str_ends_with($name, ".json")){
			$contents = $zip->getFromIndex($i);
			if($contents !== false){
				$sources[basename($name)] = $contents;
			}
		}
	}
	$zip->close();
}elseif(is_string($recipesDir)){
	$files = glob($recipesDir . "/*.json");
	foreach($files === false ? [] : $files as $file){
		$sources[basename($file)] = Filesystem::fileGetContents($file);
	}
}
if(count($sources) === 0){
	fwrite(STDERR, "No recipes found" . PHP_EOL);
	exit(1);
}

/** @var float[] $experience Bedrock result name => experience */
$experience = [];
/** @var string[] $origin Bedrock result name => recipe file, for error messages */
$origin = [];
foreach(Utils::stringifyKeys($sources) as $file => $contents){
	$json = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
	if(!is_array($json) || !in_array($json["type"] ?? null, RECIPE_TYPES, true)){
		continue;
	}
	$result = $json["result"] ?? null;
	$resultName = is_array($result) ? ($result["id"] ?? null) : $result;
	$value = $json["experience"] ?? 0.0;
	if(!is_string($resultName) || (!is_float($value) && !is_int($value))){
		throw new \RuntimeException("Unexpected recipe format in $file");
	}
	$name = JAVA_TO_BEDROCK[$resultName] ?? $resultName;
	$value = (float) $value;
	if(isset($experience[$name]) && $experience[$name] !== $value){
		throw new \RuntimeException("Different experience for $name: {$experience[$name]} in {$origin[$name]}, $value in $file");
	}
	$experience[$name] = $value;
	$origin[$name] = $file;
}
ksort($experience);

//every furnace result of the Bedrock recipes must have a value
$missing = [];
$bedrockRecipes = json_decode(Filesystem::fileGetContents(BedrockDataFiles::RECIPES . '/shapeless_crafting.json'), true, flags: JSON_THROW_ON_ERROR);
if(!is_array($bedrockRecipes)){
	throw new \RuntimeException("Unexpected BedrockData recipe format");
}
foreach($bedrockRecipes as $recipe){
	if(!is_array($recipe) || !in_array($recipe["block"] ?? null, ["furnace", "blast_furnace", "smoker"], true) || !is_array($recipe["output"] ?? null)){
		continue;
	}
	foreach($recipe["output"] as $out){
		$name = is_array($out) ? ($out["name"] ?? null) : $out;
		if(is_string($name) && !isset($experience[$name])){
			$missing[$name] = true;
		}
	}
}
if(count($missing) > 0){
	fwrite(STDERR, "Bedrock furnace results without experience (add them to JAVA_TO_BEDROCK): " . implode(", ", array_keys($missing)) . PHP_EOL);
	exit(1);
}

$lines = [];
foreach(Utils::stringifyKeys($experience) as $name => $value){
	if(preg_match('/^[a-z0-9_]+:[a-z0-9_.]+$/', $name) !== 1){
		throw new \AssertionError("Unexpected item name $name");
	}
	$lines[] = "\t\t\"$name\" => " . var_export($value, true) . ",";
}

$header = Filesystem::fileGetContents(dirname(__DIR__) . '/build/codegen/templates/header.php');
$php = $header . <<<PHP

namespace pocketmine\crafting;

/**
 * Experience stored by furnaces, blast furnaces and smokers for each item they smelt, by Bedrock result item name.
 * This class is generated by tools/generate-furnace-experience.php. Do not edit it manually.
 *
 * @internal
 */
final class FurnaceExperienceTable{

	private function __construct(){
		//NOOP
	}

	public const EXPERIENCE = [

PHP;
$php .= implode("\n", $lines) . "\n\t];\n}\n";
if(!is_dir(dirname($output))){
	mkdir(dirname($output), 0777, true);
}
Filesystem::safeFilePutContents($output, $php);

echo "Wrote " . count($experience) . " experience values to $output" . PHP_EOL;
