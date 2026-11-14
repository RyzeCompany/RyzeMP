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
 * Generates generated/block/utils/NoteBlockInstrumentTable.php: the instrument a note block plays over each block
 * type, from a capture of what BDS sends.
 *
 * Usage: php tools/generate-note-block-instruments.php [--capture <json>] [--output <file>]
 *
 * The capture (default tools/data/bds-1.26.52-note-block-instruments.json) maps Bedrock block names to the instrument
 * id of the NOTE level sound event BDS sent for a note block placed over that block. It was made on BDS 1.26.52 by
 * setting every block name PocketMine knows under a row of note blocks with /setblock, powering the note blocks with
 * redstone blocks and recording the sounds with a client; names /setblock refused (education blocks...) are absent.
 *
 * Block types whose names play different instruments must be the copper blocks, whose trumpet follows the
 * oxidation: they are written as TRUMPET and resolved by NoteBlockInstruments at runtime. Block types without a
 * captured name are left out (Note keeps its older guess for them).
 */

declare(strict_types=1);

namespace pocketmine\tools\generate_note_block_instruments;

use pocketmine\block\BlockTypeIds;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\data\bedrock\NoteInstrumentIdMap;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\sound\NoteInstrument;
use function array_keys;
use function count;
use function dirname;
use function fwrite;
use function getopt;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_int;
use function is_string;
use function json_decode;
use function ksort;
use function mkdir;
use const JSON_THROW_ON_ERROR;
use const PHP_EOL;
use const STDERR;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt("", ["capture:", "output:"]);
$capture = $options["capture"] ?? dirname(__DIR__) . '/tools/data/bds-1.26.52-note-block-instruments.json';
$output = $options["output"] ?? dirname(__DIR__) . '/generated/block/utils/NoteBlockInstrumentTable.php';
if(!is_string($capture) || !is_string($output)){
	fwrite(STDERR, "Usage: php tools/generate-note-block-instruments.php [--capture <json>] [--output <file>]" . PHP_EOL);
	exit(1);
}

$byName = json_decode(Filesystem::fileGetContents($capture), true, flags: JSON_THROW_ON_ERROR);
if(!is_array($byName)){
	throw new \RuntimeException("$capture is not a JSON object");
}

$typeNames = [];
foreach(Utils::stringifyKeys((new \ReflectionClass(BlockTypeIds::class))->getConstants()) as $constName => $typeId){
	if(is_int($typeId)){
		$typeNames[$typeId] = $constName;
	}
}

$idMap = NoteInstrumentIdMap::getInstance();
$serializer = GlobalBlockStateHandlers::getSerializer();
/** @var NoteInstrument[][] $byType type name => instrument name => instrument */
/** @phpstan-var array<string, array<string, NoteInstrument>> $byType */
$byType = [];
foreach(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates() as $state){
	try{
		$name = $serializer->serializeBlock($state)->getName();
	}catch(\Throwable){
		continue;
	}
	$id = $byName[$name] ?? null;
	if(!is_int($id)){
		continue;
	}
	$instrument = $idMap->fromId($id) ?? throw new \RuntimeException("Unknown instrument id $id for $name");
	$typeName = $typeNames[$state->getTypeId()] ?? throw new \RuntimeException("No BlockTypeIds constant for " . $state->getName());
	$byType[$typeName][$instrument->name] = $instrument;
}
ksort($byType);

$trumpets = [NoteInstrument::TRUMPET->name, NoteInstrument::TRUMPET_EXPOSED->name, NoteInstrument::TRUMPET_WEATHERED->name, NoteInstrument::TRUMPET_OXIDIZED->name];
$lines = [];
foreach(Utils::stringifyKeys($byType) as $typeName => $instruments){
	if(count($instruments) === 1){
		$instrument = $instruments[array_keys($instruments)[0]];
	}else{
		foreach(array_keys($instruments) as $instrumentName){
			if(!in_array($instrumentName, $trumpets, true)){
				throw new \RuntimeException("$typeName plays several instruments: " . implode(", ", array_keys($instruments)));
			}
		}
		$instrument = NoteInstrument::TRUMPET;
	}
	$lines[] = "\t\tBlockTypeIds::$typeName => NoteInstrument::" . $instrument->name . ",";
}

$header = Filesystem::fileGetContents(dirname(__DIR__) . '/build/codegen/templates/header.php');
$php = $header . <<<PHP

namespace pocketmine\block\utils;

use pocketmine\block\BlockTypeIds;
use pocketmine\world\sound\NoteInstrument;

/**
 * Instrument played by a note block over each block type, as captured from BDS 1.26.52. Copper blocks are listed as
 * TRUMPET: the trumpet depends on their oxidation.
 * This class is generated by tools/generate-note-block-instruments.php. Do not edit it manually.
 *
 * @internal
 */
final class NoteBlockInstrumentTable{

	private function __construct(){
		//NOOP
	}

	public const BY_TYPE_ID = [

PHP;
$php .= implode("\n", $lines) . "\n\t];\n}\n";
if(!is_dir(dirname($output))){
	mkdir(dirname($output), 0777, true);
}
Filesystem::safeFilePutContents($output, $php);

echo "Wrote " . count($lines) . " note block instruments to $output" . PHP_EOL;
