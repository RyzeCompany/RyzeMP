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
 * Generates resources/block_map_colors.json (the base map colour of each block) from a block state dump made from
 * Bedrock Dedicated Server, such as AllayMC data/resources/block_states.json (LGPL-3.0, "mapColor" of every state).
 *
 * Usage: php tools/generate-block-map-colors.php <block_states.json> [output.json]
 *
 * BDS reports a transparent colour for blocks whose map colour comes from the biome tint (grass, leaves, water); those
 * get the fixed vanilla base colours below. Blocks PocketMine knows that are newer than the dump are derived or
 * estimated (see ESTIMATES) and listed under "estimated". Logs have a different colour on the cut face (pillar_axis=y, seen from
 * above) and on the bark; the dump lists one state with the cut-face colour and two with the bark colour.
 */

declare(strict_types=1);

namespace pocketmine\tools\generate_block_map_colors;

use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use function array_keys;
use function count;
use function dirname;
use function fwrite;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function preg_match;
use function sort;
use function strtolower;
use function substr;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const STDERR;

require dirname(__DIR__) . '/vendor/autoload.php';

const GRASS = "7fb238";
const PLANT = "007c00";
const WATER = "4040ff";
/** Blocks that don't show on maps (air, glass, ...): the map shows what is below them */
const NONE = "none";

/** Colours of biome-tinted blocks, by BDS tint method (Grass is special-cased for grass_block) */
const TINT_COLORS = [
	"Grass" => PLANT,
	"DefaultFoliage" => PLANT,
	"BirchFoliage" => PLANT,
	"EvergreenFoliage" => PLANT,
	"DryFoliage" => PLANT,
	"Stem" => PLANT,
	"Water" => WATER,
];

const LIGHT_GRAY = "999999";
const PODZOL = "815631";

/**
 * Blocks added after the dump was made, with colours from the Minecraft Wiki where it has them (poplar planks and log
 * end: COLOR_LIGHT_GRAY, poplar bark: PODZOL) and otherwise the colour of the closest vanilla block.
 */
const ESTIMATES = [
	"minecraft:poplar_button" => LIGHT_GRAY,
	"minecraft:poplar_door" => LIGHT_GRAY,
	"minecraft:poplar_double_slab" => LIGHT_GRAY,
	"minecraft:poplar_fence" => LIGHT_GRAY,
	"minecraft:poplar_fence_gate" => LIGHT_GRAY,
	"minecraft:poplar_hanging_sign" => LIGHT_GRAY,
	"minecraft:poplar_log" => PODZOL,
	"minecraft:poplar_planks" => LIGHT_GRAY,
	"minecraft:poplar_pressure_plate" => LIGHT_GRAY,
	"minecraft:poplar_sapling" => PLANT,
	"minecraft:poplar_shelf" => LIGHT_GRAY,
	"minecraft:poplar_slab" => LIGHT_GRAY,
	"minecraft:poplar_stairs" => LIGHT_GRAY,
	"minecraft:poplar_standing_sign" => LIGHT_GRAY,
	"minecraft:poplar_trapdoor" => LIGHT_GRAY,
	"minecraft:poplar_wall_sign" => LIGHT_GRAY,
	"minecraft:poplar_wood" => PODZOL,
	"minecraft:stripped_poplar_log" => LIGHT_GRAY,
	"minecraft:stripped_poplar_wood" => LIGHT_GRAY,
	"minecraft:red_shrub" => PLANT,
	"minecraft:shelf_mushroom" => "664c33",
	"minecraft:straw_bed" => NONE, //like the other beds
];
const ESTIMATES_PILLAR_AXIS_Y = [
	"minecraft:poplar_log" => LIGHT_GRAY,
];

if(count($argv) < 2){
	fwrite(STDERR, "Usage: php " . $argv[0] . " <block_states.json> [output.json]\n");
	exit(1);
}
$output = $argv[2] ?? dirname(__DIR__) . '/resources/block_map_colors.json';

$states = json_decode(Filesystem::fileGetContents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
if(!is_array($states)){
	throw new \RuntimeException("Expected a list of block states");
}

/** @var int[][] $colorCounts name => colour => number of states */
$colorCounts = [];
/** @var string[] $tintMethods */
$tintMethods = [];
foreach($states as $state){
	if(!is_array($state) || !is_string($state["name"] ?? null) || !is_string($state["mapColor"] ?? null)){
		throw new \RuntimeException("Unexpected block state entry");
	}
	if(preg_match('/^#([0-9a-f]{6})([0-9a-f]{2})$/', strtolower($state["mapColor"]), $matches) !== 1){
		throw new \RuntimeException("Unexpected map colour " . $state["mapColor"]);
	}
	$color = $matches[2] === "00" ? "" : $matches[1];
	$name = $state["name"];
	$colorCounts[$name][$color] = ($colorCounts[$name][$color] ?? 0) + 1;
	$tintMethods[$name] = is_string($state["tintMethod"] ?? null) ? $state["tintMethod"] : "None";
}

$colors = [];
$pillarAxisY = [];
foreach(Utils::stringifyKeys($colorCounts) as $name => $counts){
	$tint = $tintMethods[$name];
	if(count($counts) === 1){
		$color = (string) array_keys($counts)[0];
		if($color === "" && isset(TINT_COLORS[$tint])){
			$color = $name === "minecraft:grass_block" ? GRASS : TINT_COLORS[$tint];
		}
		$colors[$name] = $color !== "" ? $color : NONE;
		continue;
	}
	if(count($counts) !== 2 || substr($name, -4) !== "_log"){
		throw new \RuntimeException("Don't know how to handle the different colours of $name: " . json_encode($counts));
	}
	foreach(Utils::promoteKeys($counts) as $color => $count){
		if($count === 1){
			$pillarAxisY[$name] = (string) $color;
		}else{
			$colors[$name] = (string) $color;
		}
	}
}
//blocks PocketMine knows that are newer than the dump: slabs and stairs take the colour of their full block, the rest
//comes from ESTIMATES
$derived = [];
$serializer = GlobalBlockStateHandlers::getSerializer();
foreach(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates() as $stateId => $block){
	$name = $serializer->serialize($stateId)->getName();
	if(isset($colors[$name]) || isset($derived[$name])){
		continue;
	}
	if(isset(ESTIMATES[$name])){
		$derived[$name] = ESTIMATES[$name];
	}elseif(preg_match('/^(.+?)_(?:double_slab|slab|stairs)$/', $name, $matches) === 1 && isset($colors[$matches[1]])){
		$derived[$name] = $colors[$matches[1]];
	}elseif(substr($name, -7) === "_leaves"){
		$derived[$name] = PLANT;
	}else{
		throw new \RuntimeException("No map colour for $name: add it to ESTIMATES");
	}
}
foreach(ESTIMATES_PILLAR_AXIS_Y as $name => $color){
	$pillarAxisY[$name] = $color;
}
$colors += $derived;
ksort($colors);
ksort($pillarAxisY);
$estimated = array_keys($derived);
sort($estimated);

Filesystem::safeFilePutContents($output, json_encode([
	"source" => "Bedrock Dedicated Server block state dump (mapColor), via AllayMC data/resources/block_states.json; generated by tools/generate-block-map-colors.php",
	"estimated" => $estimated,
	"colors" => $colors,
	"pillar_axis_y" => $pillarAxisY,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo "Wrote " . count($colors) . " block colours (" . count($estimated) . " estimated) and " . count($pillarAxisY) . " log end colours to $output\n";
