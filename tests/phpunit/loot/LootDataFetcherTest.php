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

namespace pocketmine\loot;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pocketmine\utils\Filesystem;
use pocketmine\world\generator\vanilla\VanillaDataFetcher;
use function class_exists;
use function file_exists;
use function hash_file;
use function json_encode;
use function mkdir;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function uniqid;
use const JSON_THROW_ON_ERROR;

class LootDataFetcherTest extends TestCase{
	private string $dir;

	protected function setUp() : void{
		$this->dir = sys_get_temp_dir() . "/pm-loot-fetch-test-" . uniqid();
		mkdir($this->dir, 0777, true);
	}

	protected function tearDown() : void{
		LootTables::setDataRoot(null);
		Filesystem::recursiveUnlink($this->dir);
	}

	/**
	 * A small Mojang-style bundler jar: META-INF/versions.list naming a game jar stored in META-INF/versions/.
	 */
	private function makeServerJar(?string $listedSha256 = null) : string{
		$game = $this->dir . "/game-fixture.jar";
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($game, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
		$zip->addFromString("data/minecraft/loot_table/chests/test.json", '{"pools":[]}');
		$zip->addFromString("data/minecraft/loot_table/big.json", str_repeat("{}", 5000)); //deflated
		$zip->addFromString("data/minecraft/tags/item/test.json", '{"values":["minecraft:stick"]}');
		$zip->setCompressionName("data/minecraft/tags/item/test.json", \ZipArchive::CM_STORE);
		$zip->addFromString("data/minecraft/worldgen/noise/skipped.json", "{}");
		$zip->addFromString("net/minecraft/Skipped.class", "\xca\xfe\xba\xbe");
		$zip->close();

		$server = $this->dir . "/server.jar";
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($server, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
		$zip->addFromString("META-INF/versions.list", ($listedSha256 ?? (string) hash_file("sha256", $game)) . "\t26.3\t26.3/server-26.3.jar\n");
		$zip->addFile($game, "META-INF/versions/26.3/server-26.3.jar");
		$zip->close();
		return $server;
	}

	/**
	 * @return \Generator<string, array{bool}>
	 */
	public static function readerProvider() : \Generator{
		yield "zip extension" => [true];
		yield "phar" => [false];
	}

	#[DataProvider("readerProvider")]
	public function testExtractLootData(bool $allowZipExtension) : void{
		if(!class_exists(\ZipArchive::class)){
			self::markTestSkipped("the zip extension is needed to build the fixture");
		}
		$tmp = $this->dir . "/tmp";
		mkdir($tmp);
		$gameJar = LootDataFetcher::extractBundledJar($this->makeServerJar(), $tmp);
		$root = $this->dir . "/root";
		[$files, $bytes] = LootDataFetcher::extractData($gameJar, $root, function() : void{}, $allowZipExtension);

		self::assertSame(3, $files);
		self::assertSame(strlen('{"pools":[]}') + 10000 + strlen('{"values":["minecraft:stick"]}'), $bytes);
		self::assertSame('{"pools":[]}', Filesystem::fileGetContents($root . "/data/minecraft/loot_table/chests/test.json"));
		self::assertSame(str_repeat("{}", 5000), Filesystem::fileGetContents($root . "/data/minecraft/loot_table/big.json"));
		self::assertSame('{"values":["minecraft:stick"]}', Filesystem::fileGetContents($root . "/data/minecraft/tags/item/test.json"));
		self::assertFalse(file_exists($root . "/data/minecraft/worldgen/noise/skipped.json"));
		self::assertFalse(file_exists($root . "/net/minecraft/Skipped.class"));
	}

	public function testBundledJarChecksum() : void{
		if(!class_exists(\ZipArchive::class)){
			self::markTestSkipped("the zip extension is needed to build the fixture");
		}
		$tmp = $this->dir . "/tmp";
		mkdir($tmp);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("Checksum mismatch");
		LootDataFetcher::extractBundledJar($this->makeServerJar(str_repeat("0", 64)), $tmp);
	}

	public function testAvailability() : void{
		$root = $this->dir;
		self::assertFalse(LootData::isAvailable($root));
		self::assertFalse(LootDataFetcher::isReady($root));

		//extraction interrupted before the stamp: not usable, fetched again
		mkdir($root . "/data/minecraft/loot_table", 0777, true);
		self::assertFalse(LootData::isAvailable($root));
		self::assertFalse(LootDataFetcher::isReady($root));

		mkdir($root . "/geyser");
		Filesystem::safeFilePutContents($root . "/" . LootData::GEYSER_ITEMS_FILE, "{}");
		Filesystem::safeFilePutContents($root . "/" . LootData::LOOT_STAMP_FILE, json_encode(["minecraft" => VanillaDataFetcher::MINECRAFT_VERSION], JSON_THROW_ON_ERROR));
		self::assertTrue(LootData::isAvailable($root));
		self::assertTrue(LootDataFetcher::isReady($root));

		//data of another version: usable meanwhile, fetched again
		Filesystem::safeFilePutContents($root . "/" . LootData::LOOT_STAMP_FILE, json_encode(["minecraft" => "1.0"], JSON_THROW_ON_ERROR));
		self::assertTrue(LootData::isAvailable($root));
		self::assertFalse(LootDataFetcher::isReady($root));

		//a vanilla worldgen cache is always preferred as it is
		Filesystem::safeFilePutContents($root . "/" . LootData::JAVA_ITEMS_FILE, json_encode(["items" => ["minecraft:stick" => [64, 0, 0]]], JSON_THROW_ON_ERROR));
		self::assertTrue(LootDataFetcher::isReady($root));

		LootTables::setDataRoot($root);
		self::assertTrue(LootTables::isAvailable());
	}

	public function testItemInfoWithoutJavaItems() : void{
		$root = $this->dir;
		mkdir($root . "/data/minecraft/loot_table", 0777, true);
		Filesystem::safeFilePutContents($root . "/" . LootData::LOOT_STAMP_FILE, json_encode(["minecraft" => VanillaDataFetcher::MINECRAFT_VERSION], JSON_THROW_ON_ERROR));
		LootTables::setDataRoot($root);
		$data = LootTables::getData();
		self::assertNotNull($data);

		[$stack, $damage, $enchantability] = $data->itemInfo("minecraft:iron_sword");
		self::assertSame(1, $stack);
		self::assertGreaterThan(200, $damage);
		self::assertSame(14, $enchantability);
		self::assertSame([16, 0, 0], $data->itemInfo("minecraft:ender_pearl"));
		self::assertSame([64, 0, 0], $data->itemInfo("minecraft:stick"));
		self::assertSame(1, $data->itemInfo("minecraft:book")[2]);
		self::assertSame(0, $data->itemInfo("minecraft:shears")[2]);
		self::assertTrue($data->itemExists("minecraft:stick"));
		self::assertFalse($data->itemExists("minecraft:no_such_item"));
		self::assertSame([64, 0, 0], $data->itemInfo("minecraft:no_such_item"));
	}
}
