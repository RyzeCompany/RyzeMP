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
 * Bundles the Bedrock container loot tables (behavior pack loot_tables/{chests,dispensers,barrels,pots}/**.json) into
 * resources/bedrock_loot_tables.json, converted to the Java loot table format with Java item ids (see
 * {@link \pocketmine\loot\bedrock\BedrockLootConverter}), keyed by the Java id of each table (see
 * {@link \pocketmine\loot\LootTables::toJavaId()}). {@link \pocketmine\loot\LootTables} rolls them instead of the Java
 * Edition tables of the same name.
 *
 * Item ids are resolved with PocketMine's item id upgrader (legacy names and data values) and the GeyserMC Java ->
 * Bedrock item mappings of the vanilla data cache, so the cache is needed.
 *
 * Usage: php tools/generate-bedrock-loot-tables.php <behavior_pack dir> <vanilla data cache dir> [output.json]
 */

declare(strict_types=1);

namespace pocketmine\tools\generate_bedrock_loot_tables;

use pocketmine\data\bedrock\item\upgrade\ItemIdMetaUpgrader;
use pocketmine\loot\bedrock\BedrockLootConverter;
use pocketmine\loot\bedrock\BedrockLootTables;
use pocketmine\loot\LootData;
use pocketmine\loot\LootTables;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use function count;
use function dirname;
use function file_put_contents;
use function fwrite;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function sort;
use function str_ends_with;
use function str_replace;
use function strlen;
use function substr;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const STDERR;

require dirname(__DIR__) . '/vendor/autoload.php';

/** Bedrock item (name:data) => Java id, where the upgrader and the mappings don't give a single answer. */
const OVERRIDES = [
	"minecraft:potion:0" => "minecraft:potion",
	"minecraft:splash_potion:0" => "minecraft:splash_potion",
	"minecraft:lingering_potion:0" => "minecraft:lingering_potion",
	"minecraft:arrow:0" => "minecraft:arrow",
	"minecraft:suspicious_stew:0" => "minecraft:suspicious_stew",
	"minecraft:goat_horn:0" => "minecraft:goat_horn",
	"minecraft:ominous_bottle:0" => "minecraft:ominous_bottle",
	"minecraft:book:0" => "minecraft:book",
	"minecraft:map:0" => "minecraft:map",
	"minecraft:empty_map:0" => "minecraft:map",
	"minecraft:sign:1" => "minecraft:spruce_sign", //village_taiga_house: legacy sign item with the spruce data value
];

if(count($argv) < 3 || !is_dir($argv[1] . "/loot_tables/chests") || !LootData::isAvailable($argv[2])){
	fwrite(STDERR, "Usage: php " . $argv[0] . " <behavior_pack dir> <vanilla data cache dir> [output.json]\n");
	exit(1);
}
$output = $argv[3] ?? dirname(__DIR__) . '/resources/' . BedrockLootTables::FILE;

/**
 * @return mixed[]
 */
function readJson(string $path) : array{
	$json = json_decode(BedrockLootConverter::stripComments(Filesystem::fileGetContents($path)), true, 512, JSON_THROW_ON_ERROR);
	if(!is_array($json)){
		throw new \RuntimeException("$path is not a JSON object");
	}
	return $json;
}

$javaItems = readJson($argv[2] . "/" . LootData::JAVA_ITEMS_FILE)["items"] ?? [];
if(!is_array($javaItems)){
	throw new \RuntimeException("Invalid " . LootData::JAVA_ITEMS_FILE);
}
/** @phpstan-var array<string, list<string>> $bedrockToJava */
$bedrockToJava = [];
foreach(Utils::promoteKeys(readJson($argv[2] . "/" . LootData::GEYSER_ITEMS_FILE)) as $javaId => $mapping){
	if(is_array($mapping) && is_string($mapping["bedrock_identifier"] ?? null)){
		$data = is_int($mapping["bedrock_data"] ?? null) ? $mapping["bedrock_data"] : 0;
		$bedrockToJava[$mapping["bedrock_identifier"] . ":" . $data][] = (string) $javaId;
	}
}

$idMetaUpgrader = GlobalItemDataHandlers::getUpgrader()->getIdMetaUpgrader();
$errors = [];

$resolve = function(string $name, int $data) use ($idMetaUpgrader, $javaItems, $bedrockToJava, &$errors) : ?string{
	if(isset(OVERRIDES["$name:$data"])){
		return OVERRIDES["$name:$data"];
	}
	[$id, $meta] = upgrade($idMetaUpgrader, $name, $data);
	//the mappings first: some ids mean different items (Bedrock minecraft:snow is Java's snow_block)
	$candidates = $bedrockToJava["$id:$meta"] ?? [];
	if(count($candidates) === 1){
		return $candidates[0];
	}
	if(in_array($id, $candidates, true) || (count($candidates) === 0 && $meta === 0 && isset($javaItems[$id]))){
		return $id;
	}
	sort($candidates);
	$errors[] = "$name:$data (upgraded to $id:$meta): " . (count($candidates) === 0 ? "no Java item" : "several Java items: " . implode(", ", $candidates));
	return null;
};

/**
 * @return mixed[]
 * @phpstan-return array{string, int}
 */
function upgrade(ItemIdMetaUpgrader $upgrader, string $name, int $data) : array{
	//the schemas use the legacy ids with their original case (minecraft:appleEnchanted...)
	foreach([$name, ...LEGACY_CASE[$name] ?? []] as $candidate){
		[$id, $meta] = $upgrader->upgrade($candidate, $data);
		if($id !== $candidate || $meta !== $data){
			return [$id, $meta];
		}
	}
	return [$name, $data];
}

const LEGACY_CASE = [
	"minecraft:appleenchanted" => ["minecraft:appleEnchanted"],
];

$tables = [];
foreach(["chests" => "minecraft:chest", "dispensers" => "minecraft:chest", "barrels" => "minecraft:chest", "pots" => "minecraft:chest"] as $dir => $type){
	$base = $argv[1] . "/loot_tables/" . $dir;
	if(!is_dir($base)){
		continue;
	}
	$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
	foreach($files as $file){
		if(!$file instanceof \SplFileInfo || !str_ends_with($file->getFilename(), ".json")){
			continue;
		}
		$path = str_replace("\\", "/", $file->getPathname());
		$bedrockPath = "loot_tables/" . $dir . substr($path, strlen(str_replace("\\", "/", $base)));
		$converter = new BedrockLootConverter($resolve, function(string $message) use ($bedrockPath, &$errors) : void{
			$errors[] = "$bedrockPath: $message";
		});
		$tables[LootTables::toJavaId($bedrockPath)] = $converter->table(readJson($path), $type);
	}
}
ksort($tables);

if(count($errors) > 0){
	fwrite(STDERR, "Conversion problems:\n- " . implode("\n- ", $errors) . "\n");
	exit(1);
}

$lines = [];
foreach(Utils::stringifyKeys($tables) as $id => $table){
	$lines[] = json_encode($id, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . ":" . json_encode($table, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}
file_put_contents($output, "{\n" . implode(",\n", $lines) . "\n}\n");
echo "Wrote " . count($tables) . " tables to $output\n";
