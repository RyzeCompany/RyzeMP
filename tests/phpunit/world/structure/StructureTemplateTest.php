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

namespace pocketmine\world\structure;

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\utils\Filesystem;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\format\io\leveldb\LevelDB;
use pocketmine\world\format\io\WritableWorldProvider;
use pocketmine\world\redstone\NullThreadSafeLogger;
use pocketmine\world\WorldCreationOptions;
use function array_keys;
use function array_map;
use function extension_loaded;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Structure templates in the Bedrock format, against data saved by BDS 1.26.52 (tests/phpunit/world/structure/bds:
 * "t1" is a 3x2x4 build with a structure void, a furnace, a chest holding 5 diamonds and an armor stand).
 */
final class StructureTemplateTest extends TestCase{

	public static function bds(string $name) : StructureTemplate{
		return StructureTemplate::fromBinary(Filesystem::fileGetContents(__DIR__ . "/bds/$name.mcstructure"));
	}

	private static function blockName(StructureTemplate $template, int $x, int $y, int $z) : ?string{
		$paletteIndex = $template->getPaletteIndex(StructureTemplate::index($x, $y, $z, $template->getSizeY(), $template->getSizeZ()));
		return $paletteIndex === null ? null : $template->getPalette()[$paletteIndex]->getString("name");
	}

	public function testReadsBdsTemplate() : void{
		$template = self::bds("t1");
		self::assertSame([3, 2, 4], [$template->getSizeX(), $template->getSizeY(), $template->getSizeZ()]);
		self::assertSame([0, -60, 0], $template->getWorldOrigin());
		self::assertSame("minecraft:stone", self::blockName($template, 0, 0, 0));
		self::assertSame("minecraft:diamond_block", self::blockName($template, 2, 0, 0));
		self::assertSame("minecraft:gold_block", self::blockName($template, 0, 0, 3));
		self::assertSame("minecraft:furnace", self::blockName($template, 1, 0, 2));
		self::assertSame("minecraft:chest", self::blockName($template, 2, 1, 3));
		self::assertSame("minecraft:air", self::blockName($template, 1, 0, 0));
		//the structure void reads back as 0xffffffff from the int array
		self::assertNull(self::blockName($template, 2, 0, 3));
		self::assertNull($template->getLiquids());

		$blockEntities = $template->getBlockEntities();
		self::assertCount(2, $blockEntities);
		$chest = $template->getBlockEntity(StructureTemplate::index(2, 1, 3, 2, 4));
		self::assertNotNull($chest);
		self::assertSame("Chest", $chest->getString("id"));
		self::assertCount(1, $template->getEntities());
		self::assertSame("minecraft:armor_stand", $template->getEntities()[0]->getString("identifier"));
		self::assertFalse($template->isEmpty());
	}

	public function testNbtRoundTrip() : void{
		$template = self::bds("t1");
		$copy = StructureTemplate::fromBinary($template->toBinary());
		self::assertSame($template->getBlocks(), $copy->getBlocks());
		self::assertSame($template->getWorldOrigin(), $copy->getWorldOrigin());
		self::assertCount(8, $copy->getPalette());
		foreach($template->getPalette() as $i => $entry){
			self::assertTrue($entry->equals($copy->getPalette()[$i]));
		}
		self::assertSame([10, 23], array_keys($copy->getBlockEntities()));
		self::assertCount(1, $copy->getEntities());
		self::assertSame(StructureTemplate::FORMAT_VERSION, $template->toNbt()->getInt(StructureTemplate::TAG_FORMAT_VERSION));
	}

	/**
	 * .mcstructure files (format 1) store the layers as lists of int lists; -1 is a structure void.
	 */
	public function testReadsFormatOneLists() : void{
		$stone = GlobalBlockStateHandlers::getSerializer()->serialize(VanillaBlocks::STONE()->getStateId())->toVanillaNbt();
		$water = GlobalBlockStateHandlers::getSerializer()->serialize(VanillaBlocks::WATER()->getStillForm()->getStateId())->toVanillaNbt();
		$ints = fn(int ...$values) => new ListTag(array_map(fn(int $v) => new IntTag($v), $values), NBT::TAG_Int);
		$root = CompoundTag::create()
			->setInt(StructureTemplate::TAG_FORMAT_VERSION, 1)
			->setTag(StructureTemplate::TAG_SIZE, $ints(2, 1, 1))
			->setTag(StructureTemplate::TAG_STRUCTURE, CompoundTag::create()
				->setTag(StructureTemplate::TAG_BLOCK_INDICES, new ListTag([$ints(0, -1), $ints(1, -1)], NBT::TAG_List))
				->setTag(StructureTemplate::TAG_ENTITIES, new ListTag([], NBT::TAG_Compound))
				->setTag(StructureTemplate::TAG_PALETTE, CompoundTag::create()->setTag(StructureTemplate::TAG_DEFAULT_PALETTE, CompoundTag::create()
					->setTag(StructureTemplate::TAG_BLOCK_PALETTE, new ListTag([$stone, $water], NBT::TAG_Compound))
					->setTag(StructureTemplate::TAG_BLOCK_POSITION_DATA, CompoundTag::create())
				))
			)
			->setTag(StructureTemplate::TAG_WORLD_ORIGIN, $ints(5, 6, 7));
		$template = StructureTemplate::fromNbt($root);
		self::assertSame(0, $template->getPaletteIndex(0));
		self::assertNull($template->getPaletteIndex(1));
		self::assertSame(1, $template->getLiquidPaletteIndex(0));
		self::assertNull($template->getLiquidPaletteIndex(1));
		self::assertSame([5, 6, 7], $template->getWorldOrigin());
	}

	public function testRejectsWrongLayerSize() : void{
		$root = self::bds("t1")->toNbt();
		$root->getCompoundTag(StructureTemplate::TAG_STRUCTURE)?->setTag(StructureTemplate::TAG_BLOCK_INDICES, new ListTag([new ListTag([new IntTag(0)], NBT::TAG_Int)], NBT::TAG_List));
		$this->expectException(StructureTemplateException::class);
		StructureTemplate::fromNbt($root);
	}

	public function testEmptyTemplate() : void{
		self::assertTrue(StructureTemplate::empty(2, 2, 2)->isEmpty());
		$builder = new StructureTemplateBuilder(1, 1, 1);
		$builder->setBlock(0, 0, 0, VanillaBlocks::STRUCTURE_VOID()->getStateId());
		self::assertTrue($builder->build()->isEmpty(), "structure voids are not saved");
		$builder->setBlock(0, 0, 0, VanillaBlocks::AIR()->getStateId());
		self::assertFalse($builder->build()->isEmpty(), "air is saved");
	}

	/**
	 * Templates saved to disk go to the world database under the vanilla key, like BDS.
	 */
	public function testLevelDBStorageUsesVanillaKey() : void{
		if(!extension_loaded("leveldb")){
			self::markTestSkipped("leveldb extension not loaded");
		}
		$path = sys_get_temp_dir() . "/pm-structure-test-" . uniqid();
		LevelDB::generate($path, "structure_test", WorldCreationOptions::create());
		$provider = new LevelDB($path, new NullThreadSafeLogger());
		try{
			$storage = StructureTemplateStorage::forProvider($provider);
			$template = self::bds("t1");
			$storage->save("mystructure:copy", $template, true);
			$raw = $provider->getDatabase()->get("structuretemplate_mystructure:copy");
			self::assertIsString($raw);
			self::assertSame($template->getBlocks(), StructureTemplate::fromBinary($raw)->getBlocks());

			//a template written by BDS is found by name
			$provider->getDatabase()->put("structuretemplate_mystructure:bds", Filesystem::fileGetContents(__DIR__ . "/bds/res_r90.mcstructure"));
			$loaded = StructureTemplateStorage::forProvider($provider)->load("mystructure:bds");
			self::assertNotNull($loaded);
			self::assertSame(9, $loaded->getSizeX());
			self::assertNull($storage->load("mystructure:missing"));

			//memory saves don't reach the database
			$storage->save("mystructure:memory", $template, false);
			self::assertFalse($provider->getDatabase()->get("structuretemplate_mystructure:memory"));
			self::assertNotNull($storage->load("mystructure:memory"));
		}finally{
			$provider->close();
			Filesystem::recursiveUnlink($path);
		}
	}

	/**
	 * The last template too big for the cache stays loaded (the screen queries it again and again), until it is saved
	 * again.
	 */
	public function testLastBigTemplateStaysLoaded() : void{
		$path = sys_get_temp_dir() . "/pm-structure-test-" . uniqid();
		$provider = $this->createMock(WritableWorldProvider::class);
		$provider->method("getPath")->willReturn($path);
		try{
			StructureTemplateStorage::forProvider($provider)->save("test:big", StructureTemplate::empty(100, 105, 100), true);
			$storage = StructureTemplateStorage::forProvider($provider);
			$loaded = $storage->load("test:big");
			self::assertNotNull($loaded);
			self::assertSame($loaded, $storage->load("test:big"));
			$storage->save("test:big", StructureTemplate::empty(1, 1, 1), true);
			self::assertSame(1, $storage->load("test:big")?->getVolume());
		}finally{
			Filesystem::recursiveUnlink($path);
		}
	}
}
