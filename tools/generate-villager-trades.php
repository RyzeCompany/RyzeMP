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
 * Generates generated/entity/npc/trade/VanillaTradeTables.php: the villager and wandering trader trade tables of the
 * Bedrock behavior pack (trading/economy_trades/*.json), used by {@link \pocketmine\entity\npc\trade\TradeTables}.
 *
 * Usage: php tools/generate-villager-trades.php --behavior-pack <dir> [--output <file>]
 *
 * The structure is kept (tiers, groups with num_to_select, trades, "choice" lists, item functions and filters); only
 * the spelling is normalized: quantities become min/max, absent fields are left out. Item names keep their Bedrock
 * spelling with aux values ("minecraft:dye:4"), resolved when a trade is made. Names this server doesn't know are
 * reported (their trades are skipped at runtime).
 */

declare(strict_types=1);

namespace pocketmine\tools\generate_villager_trades;

use pocketmine\entity\npc\trade\TradeTables;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use function array_diff;
use function array_is_list;
use function array_keys;
use function basename;
use function count;
use function dirname;
use function fwrite;
use function getopt;
use function glob;
use function implode;
use function is_array;
use function is_bool;
use function is_dir;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function ksort;
use function mkdir;
use function preg_match;
use function preg_replace;
use function sort;
use function str_repeat;
use function var_export;
use const JSON_THROW_ON_ERROR;
use const PHP_EOL;
use const STDERR;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt("", ["behavior-pack:", "output:"]);
$behaviorPack = $options["behavior-pack"] ?? null;
$output = $options["output"] ?? dirname(__DIR__) . '/generated/entity/npc/trade/VanillaTradeTables.php';
if(!is_string($behaviorPack) || !is_string($output)){
	fwrite(STDERR, "Usage: php tools/generate-villager-trades.php --behavior-pack <dir> [--output <file>]" . PHP_EOL);
	exit(1);
}

$files = glob($behaviorPack . '/trading/economy_trades/*.json');
if($files === false || count($files) === 0){
	fwrite(STDERR, "No trade tables found in $behaviorPack/trading/economy_trades" . PHP_EOL);
	exit(1);
}

/**
 * @param mixed[]  $array
 * @param string[] $allowed
 */
function checkKeys(array $array, array $allowed, string $where) : void{
	$unknown = array_diff(array_keys($array), $allowed);
	if(count($unknown) > 0){
		throw new \RuntimeException("$where: unknown keys " . implode(", ", $unknown));
	}
}

/**
 * @param string[] $names
 * @phpstan-param array<string, true> $names
 * @return mixed[]
 */
function convertEntry(mixed $entry, string $where, array &$names, bool $allowChoice = true) : array{
	if(!is_array($entry)){
		throw new \RuntimeException("$where: entry is not an object");
	}
	if(isset($entry["choice"])){
		checkKeys($entry, ["choice", "filters"], $where);
		if(!$allowChoice || !is_array($entry["choice"]) || !array_is_list($entry["choice"]) || count($entry["choice"]) === 0){
			throw new \RuntimeException("$where: unexpected choice");
		}
		$choices = [];
		foreach($entry["choice"] as $i => $choice){
			$choices[] = convertEntry($choice, "$where choice $i", $names, false);
		}
		$result = ["choice" => $choices];
		if(isset($entry["filters"])){
			$result["filters"] = convertFilter($entry["filters"], $where);
		}
		return $result;
	}
	checkKeys($entry, ["item", "quantity", "price_multiplier", "functions", "filters"], $where);
	$item = $entry["item"] ?? null;
	if(!is_string($item) || preg_match('/^minecraft:[a-z0-9_]+(:\d+)?$/', $item) !== 1){
		throw new \RuntimeException("$where: unexpected item " . var_export($item, true));
	}
	$names[$item] = true;
	$quantity = $entry["quantity"] ?? 1;
	if(is_int($quantity)){
		$min = $max = $quantity;
	}elseif(is_array($quantity) && is_int($quantity["min"] ?? null) && is_int($quantity["max"] ?? null)){
		$min = $quantity["min"];
		$max = $quantity["max"];
	}else{
		throw new \RuntimeException("$where: unexpected quantity " . var_export($quantity, true));
	}
	$result = ["item" => $item, "min" => $min, "max" => $max];
	if(isset($entry["price_multiplier"])){
		$multiplier = $entry["price_multiplier"];
		if(!is_float($multiplier) && !is_int($multiplier)){
			throw new \RuntimeException("$where: unexpected price_multiplier");
		}
		$result["mult"] = (float) $multiplier;
	}
	if(isset($entry["functions"])){
		if(!is_array($entry["functions"]) || !array_is_list($entry["functions"])){
			throw new \RuntimeException("$where: functions is not a list");
		}
		$functions = [];
		foreach($entry["functions"] as $function){
			if(!is_array($function) || !is_string($function["function"] ?? null)){
				throw new \RuntimeException("$where: unexpected function " . var_export($function, true));
			}
			$functions[] = $function;
		}
		$result["functions"] = $functions;
	}
	if(isset($entry["filters"])){
		$result["filters"] = convertFilter($entry["filters"], $where);
	}
	return $result;
}

/**
 * @return mixed[]
 */
function convertFilter(mixed $filter, string $where) : array{
	if(!is_array($filter)){
		throw new \RuntimeException("$where: filters is not an object");
	}
	return $filter;
}

/** @phpstan-var array<string, true> $names */
$names = [];
/** @var string[] $tables table name => PHP code */
$tables = [];
foreach($files as $file){
	$table = basename($file, ".json");
	if(preg_match('/^([a-z_]+)_trades$/', $table, $matches) !== 1){
		throw new \RuntimeException("Unexpected trade table file name $file");
	}
	$table = $matches[1];
	//Mojang's tables have a few // comments
	$json = json_decode((string) preg_replace('~^\s*//.*$~m', '', Filesystem::fileGetContents($file)), true, flags: JSON_THROW_ON_ERROR);
	if(!is_array($json) || !is_array($json["tiers"] ?? null)){
		throw new \RuntimeException("$file has no tiers");
	}
	checkKeys($json, ["format_version", "tiers"], $file);
	$tiers = [];
	foreach(Utils::promoteKeys($json["tiers"]) as $t => $tier){
		if(!is_array($tier) || !is_array($tier["groups"] ?? null)){
			throw new \RuntimeException("$file tier $t: expected groups");
		}
		checkKeys($tier, ["total_exp_required", "groups"], "$file tier $t");
		$exp = $tier["total_exp_required"] ?? 0;
		if(!is_int($exp)){
			throw new \RuntimeException("$file tier $t: unexpected total_exp_required");
		}
		$groups = [];
		foreach(Utils::promoteKeys($tier["groups"]) as $g => $group){
			if(!is_array($group) || !is_array($group["trades"] ?? null)){
				throw new \RuntimeException("$file tier $t group $g: expected trades");
			}
			checkKeys($group, ["num_to_select", "trades"], "$file tier $t group $g");
			//absent = every trade (fisherman master level)
			$select = $group["num_to_select"] ?? -1;
			if(!is_int($select)){
				throw new \RuntimeException("$file tier $t group $g: unexpected num_to_select");
			}
			$trades = [];
			foreach(Utils::promoteKeys($group["trades"]) as $i => $trade){
				$where = "$file tier $t group $g trade $i";
				if(!is_array($trade) || !is_array($trade["wants"] ?? null) || !is_array($trade["gives"] ?? null)){
					throw new \RuntimeException("$where: expected wants and gives");
				}
				checkKeys($trade, ["wants", "gives", "trader_exp", "max_uses", "reward_exp"], $where);
				$wants = [];
				foreach(Utils::promoteKeys($trade["wants"]) as $w => $want){
					$wants[] = convertEntry($want, "$where want $w", $names);
				}
				$gives = [];
				foreach(Utils::promoteKeys($trade["gives"]) as $w => $give){
					$gives[] = convertEntry($give, "$where give $w", $names);
				}
				$traderExp = $trade["trader_exp"] ?? null;
				$maxUses = $trade["max_uses"] ?? null;
				$rewardExp = $trade["reward_exp"] ?? null;
				if(($traderExp !== null && !is_int($traderExp)) || !is_int($maxUses) || ($rewardExp !== null && !is_bool($rewardExp))){
					throw new \RuntimeException("$where: unexpected trader_exp, max_uses or reward_exp");
				}
				$converted = ["wants" => $wants, "gives" => $gives, "max_uses" => $maxUses];
				if($traderExp !== null){
					$converted["trader_exp"] = $traderExp;
				}
				if($rewardExp !== null){
					$converted["reward_exp"] = $rewardExp;
				}
				$trades[] = $converted;
			}
			$groups[] = ["select" => $select, "trades" => $trades];
		}
		$tiers[] = ["exp" => $exp, "groups" => $groups];
	}
	$tables[$table] = $tiers;
}
ksort($tables);

/**
 * Short PHP array syntax; trades and entries on one line each.
 */
function export(mixed $value, int $depth, bool $inline) : string{
	if(!is_array($value)){
		if(is_float($value)){
			$text = var_export($value, true);
			return preg_match('/[.eE]/', $text) === 1 ? $text : $text . ".0";
		}
		return var_export($value, true);
	}
	if(count($value) === 0){
		return "[]";
	}
	$list = array_is_list($value);
	//trades and smaller arrays inline
	$inlineChildren = $inline || isset($value["wants"]) || isset($value["item"]) || isset($value["choice"]) || isset($value["function"]);
	$parts = [];
	foreach(Utils::promoteKeys($value) as $key => $child){
		$parts[] = ($list ? "" : var_export($key, true) . " => ") . export($child, $depth + 1, $inlineChildren);
	}
	if($inlineChildren){
		return "[" . implode(", ", $parts) . "]";
	}
	$indent = str_repeat("\t", $depth + 1);
	return "[\n" . $indent . implode(",\n" . $indent, $parts) . ",\n" . str_repeat("\t", $depth) . "]";
}

$unknown = [];
$known = 0;
$sortedNames = array_keys($names);
sort($sortedNames);
foreach($sortedNames as $name){
	if(TradeTables::resolveItem($name) === null){
		$unknown[] = $name;
	}else{
		++$known;
	}
}

$header = Filesystem::fileGetContents(dirname(__DIR__) . '/build/codegen/templates/header.php');
$body = [];
foreach(Utils::stringifyKeys($tables) as $table => $tiers){
	$body[] = "\t\t" . var_export($table, true) . " => " . export($tiers, 2, false) . ",";
}
$php = $header . <<<PHP

namespace pocketmine\\entity\\npc\\trade;

/**
 * The villager and wandering trader trade tables of the Bedrock behavior pack (trading/economy_trades), keyed by
 * file name without "_trades". Each table is a list of levels: total_exp_required ("exp") and groups, each picking
 * "select" of its trades (-1 = all). A trade has "wants" (one or two entries), "gives" (one entry), "max_uses" and
 * optionally "trader_exp" and "reward_exp". An entry is an item ("minecraft:name" or "minecraft:name:aux") with a
 * quantity range (min, max), optional price multiplier ("mult"), item functions and filters, or a "choice" of such
 * entries.
 * This class is generated by tools/generate-villager-trades.php. Do not edit it manually.
 *
 * @internal
 */
final class VanillaTradeTables{

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

echo "Wrote " . count($tables) . " trade tables ($known known item names) to $output" . PHP_EOL;
if(count($unknown) > 0){
	echo "Item names this server doesn't know (their trades are skipped): " . implode(", ", $unknown) . PHP_EOL;
}
