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
 * Bundles the Bedrock mob equipment loot tables (behavior pack loot_tables/entities/*_equipment.json, *_gear*.json
 * and armor_set_*.json, used by the minecraft:equipment component) into resources/bedrock_equipment_tables.json,
 * read by {@link \pocketmine\loot\bedrock\BedrockEquipmentTables}.
 *
 * Usage: php tools/generate-bedrock-equipment-tables.php <behavior_pack dir> [output.json]
 */

declare(strict_types=1);

namespace pocketmine\tools\generate_bedrock_equipment_tables;

use pocketmine\loot\bedrock\BedrockEquipmentTables;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use function basename;
use function count;
use function dirname;
use function file_put_contents;
use function fwrite;
use function glob;
use function implode;
use function is_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function ksort;
use function preg_match;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const STDERR;

require dirname(__DIR__) . '/vendor/autoload.php';

if(count($argv) < 2 || !is_dir($argv[1] . "/loot_tables/entities")){
	fwrite(STDERR, "Usage: php " . $argv[0] . " <behavior_pack dir> [output.json]\n");
	exit(1);
}
$output = $argv[2] ?? dirname(__DIR__) . '/resources/' . BedrockEquipmentTables::FILE;

$tables = [];
$paths = glob($argv[1] . "/loot_tables/entities/*.json");
foreach($paths !== false ? $paths : [] as $path){
	$file = basename($path);
	if(preg_match('/(_equipment|_gear(_[a-z]+)?)\.json$|^armor_set_[a-z]+\.json$/', $file) !== 1){
		continue;
	}
	$table = json_decode(Filesystem::fileGetContents($path), true, 512, JSON_THROW_ON_ERROR);
	if(!is_array($table)){
		fwrite(STDERR, "Skipping $file: not a JSON object\n");
		continue;
	}
	$tables["loot_tables/entities/" . $file] = $table;
}
ksort($tables);

$lines = [];
foreach(Utils::stringifyKeys($tables) as $name => $table){
	$lines[] = json_encode($name, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . ":" . json_encode($table, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}
file_put_contents($output, "{\n" . implode(",\n", $lines) . "\n}\n");
echo "Wrote " . count($tables) . " tables to $output\n";
