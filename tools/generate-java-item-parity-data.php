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
 * Generates tests/phpunit/item/java_item_data.json, used by ItemDataParityTest: the max stack size and max damage of
 * every Java item, from the item component reports of the Java server.
 *
 * Usage: php tools/generate-java-item-parity-data.php --reports <dir> [--output <file>]
 *
 * Make the reports with the Java server jar:
 *   java -DbundlerMainClass=net.minecraft.data.Main -jar server.jar --reports --output <dir>
 * and give <dir>/reports/minecraft/components/item.
 */

declare(strict_types=1);

namespace pocketmine\tools\generate_java_item_parity_data;

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
use function json_encode;
use function ksort;
use const JSON_THROW_ON_ERROR;
use const PHP_EOL;
use const STDERR;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt("", ["reports:", "output:"]);
$reports = $options["reports"] ?? null;
$output = $options["output"] ?? dirname(__DIR__) . '/tests/phpunit/item/java_item_data.json';
if(!is_string($reports) || !is_dir($reports) || !is_string($output)){
	fwrite(STDERR, "Usage: php tools/generate-java-item-parity-data.php --reports <dir> [--output <file>]" . PHP_EOL);
	exit(1);
}

/**
 * Java item name => [max stack size, max damage or null]
 * @var array{0: int, 1: int|null}[] $items
 * @phpstan-var array<string, array{0: int, 1: int|null}> $items
 */
$items = [];
$files = glob($reports . "/*.json");
foreach($files === false ? [] : $files as $file){
	$json = json_decode(Filesystem::fileGetContents($file), true, flags: JSON_THROW_ON_ERROR);
	$components = is_array($json) ? ($json["components"] ?? null) : null;
	if(!is_array($components)){
		throw new \RuntimeException("Unexpected report format in $file");
	}
	$stack = $components["minecraft:max_stack_size"] ?? 64;
	$damage = $components["minecraft:max_damage"] ?? null;
	if(!is_int($stack) || ($damage !== null && !is_int($damage))){
		throw new \RuntimeException("Unexpected component values in $file");
	}
	$items["minecraft:" . basename($file, ".json")] = [$stack, $damage];
}
if(count($items) === 0){
	fwrite(STDERR, "No item reports in $reports" . PHP_EOL);
	exit(1);
}
ksort($items);

$lines = [];
foreach(Utils::stringifyKeys($items) as $name => $data){
	$lines[] = json_encode($name, JSON_THROW_ON_ERROR) . ":" . json_encode($data, JSON_THROW_ON_ERROR);
}
Filesystem::safeFilePutContents($output, "{\n" . implode(",\n", $lines) . "\n}\n");

echo "Wrote " . count($items) . " items to $output" . PHP_EOL;
