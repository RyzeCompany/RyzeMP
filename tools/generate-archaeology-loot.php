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
 * Generates generated/block/utils/ArchaeologyLootTables.php: the loot of suspicious sand and gravel, from the
 * brushable block loot tables of the Bedrock behavior pack (loot_tables/entities/*brushable_block*.json).
 *
 * Usage: php tools/generate-archaeology-loot.php --behavior-pack <dir> [--output <file>]
 *
 * Every table has one pool rolled once; each entry is an item with a weight, and optionally a random_aux_value
 * function (the suspicious stew of desert wells). Tables are keyed like the Java archaeology tables
 * ("desert_pyramid", "trail_ruins_common"...), see LootTables::BEDROCK_NAMES.
 */

declare(strict_types=1);

namespace pocketmine\tools\generate_archaeology_loot;

use pocketmine\loot\LootTables;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use function basename;
use function count;
use function dirname;
use function fwrite;
use function getopt;
use function glob;
use function implode;
use function is_array;
use function is_dir;
use function is_int;
use function is_string;
use function json_decode;
use function ksort;
use function mkdir;
use function preg_match;
use function str_starts_with;
use function strlen;
use function substr;
use function var_export;
use const JSON_THROW_ON_ERROR;
use const PHP_EOL;
use const STDERR;

require dirname(__DIR__) . '/vendor/autoload.php';

const ARCHAEOLOGY_PREFIX = "minecraft:archaeology/";

$options = getopt("", ["behavior-pack:", "output:"]);
$behaviorPack = $options["behavior-pack"] ?? null;
$output = $options["output"] ?? dirname(__DIR__) . '/generated/block/utils/ArchaeologyLootTables.php';
if(!is_string($behaviorPack) || !is_string($output)){
	fwrite(STDERR, "Usage: php tools/generate-archaeology-loot.php --behavior-pack <dir> [--output <file>]" . PHP_EOL);
	exit(1);
}

$files = glob($behaviorPack . '/loot_tables/entities/*brushable_block*.json');
if($files === false || count($files) === 0){
	fwrite(STDERR, "No brushable block loot tables found in $behaviorPack/loot_tables/entities" . PHP_EOL);
	exit(1);
}

/** @var string[][] $tables table key => PHP lines of the entries */
$tables = [];
foreach($files as $file){
	$javaId = LootTables::toJavaId("loot_tables/entities/" . basename($file));
	$json = json_decode(Filesystem::fileGetContents($file), true, flags: JSON_THROW_ON_ERROR);
	if(!is_array($json)){
		throw new \RuntimeException("$file is not a JSON object");
	}
	$pools = $json["pools"] ?? [];
	if(!is_array($pools) || count($pools) === 0){
		continue; //empty_brushable_block
	}
	if(!str_starts_with($javaId, ARCHAEOLOGY_PREFIX)){
		throw new \RuntimeException("$file has loot but no archaeology table name in LootTables::BEDROCK_NAMES");
	}
	if(count($pools) !== 1){
		throw new \RuntimeException("$file: expected one pool, got " . count($pools));
	}
	$pool = $pools[0];
	if(!is_array($pool) || ($pool["rolls"] ?? null) !== ["min" => 1, "max" => 1] || !is_array($pool["entries"] ?? null)){
		throw new \RuntimeException("$file: expected one pool rolled once");
	}
	$lines = [];
	foreach($pool["entries"] as $entry){
		if(!is_array($entry) || ($entry["type"] ?? null) !== "item" || !is_string($entry["name"] ?? null) || !is_int($entry["weight"] ?? null)){
			throw new \RuntimeException("$file: unexpected entry " . var_export($entry, true));
		}
		$name = $entry["name"];
		if(preg_match('/^minecraft:[a-z0-9_]+$/', $name) !== 1){
			throw new \RuntimeException("$file: unexpected item name $name");
		}
		$auxMin = 0;
		$auxMax = 0;
		$functions = $entry["functions"] ?? [];
		if(!is_array($functions)){
			throw new \RuntimeException("$file: functions of $name is not a list");
		}
		foreach($functions as $function){
			$values = is_array($function) ? ($function["values"] ?? null) : null;
			if(!is_array($function) || ($function["function"] ?? null) !== "random_aux_value" || !is_array($values) || !is_int($values["min"] ?? null) || !is_int($values["max"] ?? null)){
				throw new \RuntimeException("$file: unsupported function " . var_export($function, true));
			}
			$auxMin = $values["min"];
			$auxMax = $values["max"];
		}
		$lines[] = "\t\t\t[\"$name\", " . $entry["weight"] . ", $auxMin, $auxMax],";
	}
	$tables[substr($javaId, strlen(ARCHAEOLOGY_PREFIX))] = $lines;
}
ksort($tables);

$body = [];
foreach(Utils::stringifyKeys($tables) as $key => $lines){
	$body[] = "\t\t\"$key\" => [\n" . implode("\n", $lines) . "\n\t\t],";
}

$header = Filesystem::fileGetContents(dirname(__DIR__) . '/build/codegen/templates/header.php');
$php = $header . <<<PHP

namespace pocketmine\block\utils;

/**
 * Loot of suspicious sand and gravel: the Bedrock brushable block loot tables, keyed like the Java archaeology tables.
 * Each table rolls one entry: [Bedrock item name, weight, minimum aux value, maximum aux value].
 * This class is generated by tools/generate-archaeology-loot.php. Do not edit it manually.
 *
 * @internal
 */
final class ArchaeologyLootTables{

	private function __construct(){
		//NOOP
	}

	public const TABLES = [

PHP;
$php .= implode("\n", $body) . "\n\t];\n}\n";
if(!is_dir(dirname($output))){
	mkdir(dirname($output), 0777, true);
}
Filesystem::safeFilePutContents($output, $php);

echo "Wrote " . count($tables) . " archaeology loot tables to $output" . PHP_EOL;
