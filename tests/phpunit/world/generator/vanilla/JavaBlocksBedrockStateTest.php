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

namespace pocketmine\world\generator\vanilla;

use PHPUnit\Framework\TestCase;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use function array_keys;
use function file_exists;
use function get_class;
use function getenv;
use function ksort;
use function strpos;
use function zlib_decode;

/**
 * JavaBlocks keeps the Geyser Bedrock state of each Java state compactly (distinct states once, encoded): the states it
 * returns must be exactly what blocks.nbt has, for every Java state.
 */
final class JavaBlocksBedrockStateTest extends TestCase{

	private static function dataDir() : string{
		$dir = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		if($dir === false || !file_exists($dir . "/" . VanillaDataFetcher::JAVA_BLOCKS_FILE) || !file_exists($dir . "/" . VanillaDataFetcher::GEYSER_DIR . "/blocks.nbt")){
			self::markTestSkipped("POCKETMINE_VANILLA_WORLDGEN_DATA is not set");
		}
		return $dir;
	}

	public function testBedrockStatesMatchGeyserMappingsForAllJavaStates() : void{
		$dir = self::dataDir();
		$blocks = JavaBlocks::get($dir);

		$raw = Filesystem::fileGetContents($dir . "/" . VanillaDataFetcher::GEYSER_DIR . "/blocks.nbt");
		$decoded = @zlib_decode($raw);
		$root = (new BigEndianNbtSerializer())->read($decoded === false ? $raw : $decoded)->mustGetCompoundTag();
		$mappings = $root->getListTag("bedrock_mappings");
		self::assertNotNull($mappings);

		$javaId = 0;
		foreach($mappings as $mapping){
			self::assertInstanceOf(CompoundTag::class, $mapping);
			$expectedName = $mapping->getString("bedrock_identifier", "");
			[$name, $states] = $blocks->bedrockState($javaId);
			if($expectedName === ""){
				//the Java name is used: only check that it is namespaced like the others
				self::assertNotSame(false, strpos($name, ":"), "state $javaId");
			}else{
				self::assertSame(strpos($expectedName, ":") === false ? "minecraft:" . $expectedName : $expectedName, $name, "state $javaId");
			}
			$expected = [];
			foreach(Utils::stringifyKeys($mapping->getCompoundTag("state")?->getValue() ?? []) as $key => $tag){
				$expected[$key] = $tag;
			}
			ksort($expected);
			self::assertSame(array_keys($expected), array_keys($states), "state $javaId");
			foreach(Utils::stringifyKeys($expected) as $key => $tag){
				$actual = $states[$key];
				self::assertSame(get_class($tag), get_class($actual), "state $javaId $key");
				self::assertTrue($tag->equals($actual), "state $javaId $key");
			}
			$javaId++;
		}
		self::assertSame($javaId, $blocks->stateCount(), "one mapping per Java state");
		self::assertSame(["minecraft:air", []], $blocks->bedrockState($javaId), "states without a mapping are air");
	}

	public function testBedrockStateTagsAreNotShared() : void{
		$blocks = JavaBlocks::get(self::dataDir());
		$state = $blocks->parseState("minecraft:oak_stairs[facing=east,half=top,shape=straight,waterlogged=false]");
		[, $first] = $blocks->bedrockState($state);
		[, $second] = $blocks->bedrockState($state);
		self::assertNotEmpty($first);
		foreach(Utils::stringifyKeys($first) as $key => $tag){
			self::assertNotSame($tag, $second[$key], "a caller can't change the states another one gets");
			self::assertTrue($tag->equals($second[$key]));
		}
	}
}
