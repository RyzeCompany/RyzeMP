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
 * Generates generated/block/utils/WaterloggableBlockNames.php: the Bedrock blocks which can hold water in the liquid
 * layer of the subchunk (waterlogging).
 *
 * Usage: php tools/generate-waterloggable-blocks.php --data <vanilla worldgen data dir> [--behavior-pack <dir>]
 *   [--output <file>]
 *
 * Sources, in order:
 * 1. Java: every Java block state holding water that isn't water itself (the WATERLOGGED blocks, plus kelp, seagrass
 *    and bubble columns), mapped to Bedrock with the Geyser mappings of the vanilla worldgen data (the same data the
 *    vanilla generator uses to write water into the liquid layer). Double slabs are left out: their Java states with
 *    waterlogged=true can't exist.
 * 2. Bedrock only: the blocks that Bedrock can waterlog and Java can't, from the Minecraft Wiki "Waterlogging" page
 *    (Bedrock column "Source"), see BEDROCK_ONLY. Uncertain entries are left out (see LEFT_OUT).
 * 3. The data-driven blocks of the Bedrock behavior pack: minecraft:liquid_detection with can_contain_liquid for water
 *    adds the block, or removes it when false.
 */

declare(strict_types=1);

namespace pocketmine\tools\generate_waterloggable_blocks;

use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use function array_keys;
use function count;
use function dirname;
use function fwrite;
use function getopt;
use function glob;
use function implode;
use function is_array;
use function is_bool;
use function is_dir;
use function is_string;
use function json_decode;
use function ksort;
use function mkdir;
use function preg_match;
use function str_contains;
use function str_ends_with;
use const JSON_THROW_ON_ERROR;
use const PHP_EOL;
use const STDERR;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Bedrock-only waterloggable blocks (Minecraft Wiki "Waterlogging": Bedrock column "Source" or "Flowing and source",
 * Java column "No" or "Pops off"), as patterns over the Bedrock block names.
 */
const BEDROCK_ONLY = [
	'/^minecraft:(chipped_|damaged_)?anvil$/',
	'/^minecraft:(flowering_)?azalea$/',
	'/^minecraft:(standing|wall)_banner$/',
	'/^minecraft:beacon$/',
	'/^minecraft:bed$/',
	'/^minecraft:bell$/',
	'/^minecraft:brewing_stand$/',
	'/_button$/',
	'/^minecraft:cake$/',
	'/candle_cake$/',
	'/^minecraft:(white|orange|magenta|light_blue|yellow|lime|pink|gray|light_gray|cyan|purple|blue|brown|green|red|black)_carpet$/',
	'/^minecraft:web$/',
	'/^minecraft:composter$/',
	'/^minecraft:deadbush$/',
	'/_door$/',
	'/^minecraft:dragon_egg$/',
	'/shulker_box$/',
	'/^minecraft:enchanting_table$/',
	'/^minecraft:end_portal_frame$/',
	'/^minecraft:end_rod$/',
	'/fence_gate$/',
	'/^minecraft:flower_pot$/',
	'/^minecraft:(glow_)?frame$/',
	'/^minecraft:grindstone$/',
	'/^minecraft:hard_([a-z_]+_stained_)?glass_pane$/',
	'/^minecraft:(skeleton_skull|wither_skeleton_skull|zombie_head|player_head|creeper_head|dragon_head|piglin_head)$/',
	'/^minecraft:hopper$/',
	'/^minecraft:lectern$/',
	'/^minecraft:lever$/',
	'/^minecraft:(mob|trial)_spawner$/',
	'/_pressure_plate$/',
	'/^minecraft:(un)?powered_(comparator|repeater)$/',
	'/^minecraft:daylight_detector(_inverted)?$/',
	'/^minecraft:stonecutter_block$/',
	'/^minecraft:trip_wire$/',
	'/^minecraft:tripwire_hook$/',
	'/^minecraft:turtle_egg$/',
	'/^minecraft:underwater_torch$/',
	'/^minecraft:vine$/',
	'/^minecraft:(compound_creator|element_constructor|lab_table|material_reducer)$/',
];

/**
 * Blocks the wiki lists for Bedrock but which are left out until checked against a real client, with the reason. Not
 * used by the script: kept here so that the next person knows they were considered.
 */
const LEFT_OUT = [
	"minecraft:cactus" => "Bedrock can waterlog it but it can't be placed in water",
	"minecraft:piston" => "piston heads don't waterlog automatically; moving blocks would have to move the water",
	"minecraft:sticky_piston" => "same as piston",
	"minecraft:moss_carpet" => "unclear whether the wiki's Carpets include moss carpets",
	"minecraft:pale_moss_carpet" => "unclear whether the wiki's Carpets include moss carpets",
	"minecraft:border_block" => "Education Edition block, not checked",
];

$options = getopt("", ["data:", "behavior-pack:", "output:"]);
$dataDir = $options["data"] ?? null;
if(!is_string($dataDir) || !is_dir($dataDir)){
	fwrite(STDERR, "Usage: php tools/generate-waterloggable-blocks.php --data <vanilla worldgen data dir> [--behavior-pack <dir>] [--output <file>]" . PHP_EOL);
	exit(1);
}
$behaviorPack = $options["behavior-pack"] ?? null;
$output = $options["output"] ?? dirname(__DIR__) . '/generated/block/utils/WaterloggableBlockNames.php';
if(!is_string($output) || ($behaviorPack !== null && !is_string($behaviorPack))){
	fwrite(STDERR, "Options can only be given once" . PHP_EOL);
	exit(1);
}

/** @var true[] $names Bedrock name => true */
$names = [];

//1. Java blocks holding water, through the Geyser mappings
$java = JavaBlocks::get($dataDir);
$serializer = GlobalBlockStateHandlers::getSerializer();
$unmapped = [];
for($state = 0, $count = $java->stateCount(); $state < $count; ++$state){
	if(!$java->pmNeedsWater($state)){
		continue;
	}
	$pm = $java->toPm($state);
	if($pm === $java->pmAirState()){
		$unmapped[$java->blockName($state)] = true;
		continue;
	}
	$name = $serializer->serialize($pm)->getName();
	if(!str_contains($name, "double_slab")){
		$names[$name] = true;
	}
}
$fromJava = count($names);

//2. Bedrock-only blocks
$bedrockOnly = 0;
foreach((new \ReflectionClass(BlockTypeNames::class))->getConstants() as $name){
	if(!is_string($name) || isset($names[$name])){
		continue;
	}
	foreach(BEDROCK_ONLY as $pattern){
		if(preg_match($pattern, $name) === 1){
			$names[$name] = true;
			++$bedrockOnly;
			break;
		}
	}
}

//3. data-driven blocks of the behavior pack
$dataDriven = [];
if($behaviorPack !== null){
	$files = glob($behaviorPack . "/blocks/*.json");
	if($files === false || count($files) === 0){
		fwrite(STDERR, "No block definitions in $behaviorPack/blocks" . PHP_EOL);
		exit(1);
	}
	foreach($files as $file){
		$json = json_decode(Filesystem::fileGetContents($file), true, flags: JSON_THROW_ON_ERROR);
		$block = is_array($json) ? ($json["minecraft:block"] ?? null) : null;
		if(!is_array($block) || !is_array($block["description"] ?? null) || !is_string($block["description"]["identifier"] ?? null)){
			continue;
		}
		$name = $block["description"]["identifier"];
		$components = $block["components"] ?? null;
		$detection = is_array($components) ? ($components["minecraft:liquid_detection"] ?? null) : null;
		$rules = is_array($detection) ? ($detection["detection_rules"] ?? null) : null;
		if(!is_array($rules)){
			continue;
		}
		foreach($rules as $rule){
			if(is_array($rule) && ($rule["liquid_type"] ?? null) === "water" && is_bool($rule["can_contain_liquid"] ?? null)){
				$dataDriven[$name] = $rule["can_contain_liquid"];
			}
		}
	}
	foreach(Utils::stringifyKeys($dataDriven) as $name => $canContain){
		if($canContain){
			$names[$name] = true;
		}else{
			unset($names[$name]);
		}
	}
}

ksort($names);

$lines = [];
foreach(array_keys($names) as $name){
	if(str_ends_with($name, "double_slab")){
		throw new \AssertionError("Double slabs can't hold water: $name");
	}
	if(preg_match('/^[a-z0-9_]+:[a-z0-9_.]+$/', $name) !== 1){
		throw new \AssertionError("Unexpected block name $name");
	}
	$lines[] = "\t\t\"$name\" => true,";
}

$header = Filesystem::fileGetContents(dirname(__DIR__) . '/build/codegen/templates/header.php');
$php = $header . <<<PHP

namespace pocketmine\block\utils;

/**
 * Bedrock blocks which can hold water in the liquid layer of the subchunk (waterlogging), by block name.
 * This class is generated by tools/generate-waterloggable-blocks.php. Do not edit it manually.
 *
 * @internal
 */
final class WaterloggableBlockNames{

	private function __construct(){
		//NOOP
	}

	public const NAMES = [

PHP;
$php .= implode("\n", $lines) . "\n\t];\n}\n";
if(!is_dir(dirname($output))){
	mkdir(dirname($output), 0777, true);
}
Filesystem::safeFilePutContents($output, $php);

echo "Wrote " . count($names) . " block names to $output ($fromJava from Java, $bedrockOnly Bedrock-only, " . count($dataDriven) . " data-driven rules)" . PHP_EOL;
if(count($unmapped) > 0){
	echo "Java blocks PocketMine doesn't know (left out): " . implode(", ", array_keys($unmapped)) . PHP_EOL;
}
