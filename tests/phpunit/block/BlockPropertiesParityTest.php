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

namespace pocketmine\block;

use PHPUnit\Framework\TestCase;
use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use function array_keys;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function json_decode;
use function sort;
use const JSON_THROW_ON_ERROR;

/**
 * Compares the light level, flame encouragement and flammability of every registered block with Bedrock's block
 * properties table (BedrockData block_properties_table.json, dumped from BDS). Hardness and blast resistance are
 * checked by {@link BlockTest::testBlockBreakInfo()}.
 */
final class BlockPropertiesParityTest extends TestCase{

	/**
	 * Light levels that intentionally differ from the table. The table only has one value per block name, from the
	 * block's base light emission; these blocks emit light from a state-dependent rule in Bedrock too (the wiki and
	 * Java agree on 1).
	 */
	private const LIGHT_EXCEPTIONS = [
		BlockTypeNames::END_PORTAL_FRAME => "light 1 in both editions per the wiki; the table lists 0",
		BlockTypeNames::SCULK_SENSOR => "light 1 in both editions per the wiki; the table lists 0",
		BlockTypeNames::CALIBRATED_SCULK_SENSOR => "light 1 in both editions per the wiki; the table lists 0",
		BlockTypeNames::TRIAL_SPAWNER => "4 or 8 by state (never dark); the table lists 0",
		BlockTypeNames::VAULT => "6 or 12 by state (never dark); the table lists 0",
	];

	/**
	 * Fire odds that intentionally differ from the table.
	 */
	private const FIRE_EXCEPTIONS = [
		BlockTypeNames::SWEET_BERRY_BUSH => "60/100 is set by the block-contact batch, which owns SweetBerryBush",
	];

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function loadTable() : array{
		$table = json_decode(Filesystem::fileGetContents(BedrockDataFiles::BLOCK_PROPERTIES_TABLE_JSON), true, 3, JSON_THROW_ON_ERROR);
		self::assertIsArray($table);
		/** @var array<string, array<string, mixed>> $table */
		return $table;
	}

	/**
	 * @param array<string, array<string, mixed>> $table
	 */
	private static function intProperty(array $table, string $name, string $property) : int{
		$value = $table[$name][$property] ?? null;
		self::assertTrue(is_int($value) || is_float($value), "$property of $name is missing from the table");
		return (int) $value;
	}

	/**
	 * Light levels of all our states, by vanilla block name.
	 *
	 * @return int[][]
	 * @phpstan-return array<string, array<int, true>>
	 */
	private static function collectLightLevels() : array{
		$serializer = GlobalBlockStateHandlers::getSerializer();
		$lights = [];
		foreach(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates() as $block){
			$lights[$serializer->serializeBlock($block)->getName()][$block->getLightLevel()] = true;
		}
		return $lights;
	}

	public function testLightLevels() : void{
		$table = self::loadTable();
		$errors = [];
		foreach(Utils::stringifyKeys(self::collectLightLevels()) as $name => $levels){
			if(isset(self::LIGHT_EXCEPTIONS[$name])){
				continue;
			}
			$expected = self::intProperty($table, $name, "brightness");
			$ours = array_keys($levels);
			if(count($ours) === 1){
				//constant light: must be the table's value
				if($ours[0] !== $expected){
					$errors[] = "$name: expected $expected, got " . $ours[0];
				}
			}elseif(!in_array($expected, $ours, true)){
				//state-dependent light (candles, sea pickles, bulbs...): the table holds one of the states' levels
				sort($ours);
				$errors[] = "$name: expected one state with light $expected, got " . implode(", ", $ours);
			}
		}
		self::assertSame([], $errors, "Light level mismatches:\n" . implode("\n", $errors));
	}

	public function testFireOdds() : void{
		$table = self::loadTable();
		$serializer = GlobalBlockStateHandlers::getSerializer();
		$errors = [];
		$checked = [];
		foreach(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates() as $block){
			$name = $serializer->serializeBlock($block)->getName();
			//several types may share a name (pitcher crop): check one state of each type
			$key = $name . "/" . $block->getTypeId();
			if(isset($checked[$key]) || isset(self::FIRE_EXCEPTIONS[$name]) || !is_array($table[$name] ?? null)){
				continue;
			}
			$checked[$key] = true;
			$expected = self::intProperty($table, $name, "flameEncouragement") . "/" . self::intProperty($table, $name, "flammability");
			$ours = $block->getFlameEncouragement() . "/" . $block->getFlammability();
			if($ours !== $expected){
				$errors[] = "$name (" . $block->getName() . "): expected $expected, got $ours";
			}
		}
		self::assertSame([], $errors, "Flame encouragement/flammability mismatches:\n" . implode("\n", $errors));
	}

	public function testSpotChecks() : void{
		self::assertSame(15, VanillaBlocks::FIRE()->getLightLevel());
		self::assertSame(10, VanillaBlocks::SOUL_FIRE()->getLightLevel());
		self::assertSame(14, VanillaBlocks::TORCH()->getLightLevel());
		self::assertSame(10, VanillaBlocks::SOUL_TORCH()->getLightLevel());
		self::assertSame(7, VanillaBlocks::ENCHANTING_TABLE()->getLightLevel());
		self::assertSame(0, VanillaBlocks::CRIMSON_STAIRS()->getFlammability());
		self::assertSame(20, VanillaBlocks::OAK_STAIRS()->getFlammability());
		self::assertSame(2.0, VanillaBlocks::MANGROVE_LOG()->getBreakInfo()->getBlastResistance());
		self::assertSame(10.0, VanillaBlocks::MANGROVE_LOG()->setStripped(true)->getBreakInfo()->getBlastResistance());
		self::assertSame(10.0, VanillaBlocks::OAK_LOG()->getBreakInfo()->getBlastResistance());
	}
}
