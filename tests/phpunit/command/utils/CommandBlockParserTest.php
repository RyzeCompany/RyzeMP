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

namespace pocketmine\command\utils;

use PHPUnit\Framework\TestCase;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Door;
use pocketmine\block\PointedDripstone;
use pocketmine\block\utils\DripstoneThickness;
use pocketmine\block\VanillaBlocks;
use pocketmine\lang\KnownTranslationKeys;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\StringTag;
use function ltrim;

class CommandBlockParserTest extends TestCase{

	public function testParseStates() : void{
		$states = CommandBlockParser::parseStates('["dripstone_thickness"="frustum","hanging"=false, "facing_direction"=2, plain:word]');
		self::assertEquals(new StringTag("frustum"), $states["dripstone_thickness"]);
		self::assertEquals(new ByteTag(0), $states["hanging"]);
		self::assertEquals(new IntTag(2), $states["facing_direction"]);
		self::assertEquals(new StringTag("word"), $states["plain"]);
		self::assertSame([], CommandBlockParser::parseStates("[]"));
	}

	public function testBadStates() : void{
		try{
			CommandBlockParser::parseStates('["a"=]');
			self::fail("expected an error");
		}catch(CommandFailureException $e){
			self::assertSame(KnownTranslationKeys::COMMANDS_BLOCKSTATE_INVALIDSTATE, ltrim($e->getTranslatable()->getText(), "%"));
		}
	}

	public function testParseNamesAndStates() : void{
		$offset = 0;
		$stone = CommandBlockParser::parse(["minecraft:stone", "replace"], $offset);
		self::assertSame(1, $offset, "only the block is read");
		self::assertSame(BlockTypeIds::STONE, $stone->getBlock()->getTypeId());

		//states split over several arguments by spaces
		$offset = 1;
		$parsed = CommandBlockParser::parse(["x", "pointed_dripstone", '["dripstone_thickness"="frustum",', '"hanging"=false]', "keep"], $offset);
		self::assertSame(4, $offset);
		$block = $parsed->getBlock();
		self::assertInstanceOf(PointedDripstone::class, $block);
		self::assertSame(DripstoneThickness::FRUSTUM, $block->getThickness());
		self::assertSame("minecraft:pointed_dripstone", $parsed->getName());

		//blocks newer than the legacy ID tables, and old names the upgrader knows
		$offset = 0;
		self::assertSame(BlockTypeIds::SOUL_SOIL, CommandBlockParser::parse(["soul_soil"], $offset)->getBlock()->getTypeId());
		$offset = 0;
		self::assertInstanceOf(Door::class, CommandBlockParser::parse(["wooden_door"], $offset)->getBlock());
	}

	public function testErrors() : void{
		foreach([
			[["not_a_block"], KnownTranslationKeys::COMMANDS_SETBLOCK_NOTFOUND],
			[["stone", '["nope"=1]'], KnownTranslationKeys::COMMANDS_BLOCKSTATE_STATEERROR],
			[["pointed_dripstone", '["hanging"="yes"]'], KnownTranslationKeys::COMMANDS_BLOCKSTATE_TYPEERROR],
			[["pointed_dripstone", '["dripstone_thickness"="huge"]'], KnownTranslationKeys::COMMANDS_BLOCKSTATE_VALUEERROR],
		] as [$args, $key]){
			$offset = 0;
			try{
				CommandBlockParser::parse($args, $offset);
				self::fail("expected $key");
			}catch(CommandFailureException $e){
				self::assertSame($key, ltrim($e->getTranslatable()->getText(), "%"));
			}
		}
	}

	public function testMatchesOnlyGivenStates() : void{
		$offset = 0;
		$any = CommandBlockParser::parse(["pointed_dripstone"], $offset);
		$offset = 0;
		$tip = CommandBlockParser::parse(["pointed_dripstone", '["dripstone_thickness"="tip"]'], $offset);
		$frustum = VanillaBlocks::POINTED_DRIPSTONE()->setThickness(DripstoneThickness::FRUSTUM);
		self::assertTrue($any->matches($frustum), "no states given: any state matches");
		self::assertFalse($tip->matches($frustum));
		self::assertTrue($tip->matches(VanillaBlocks::POINTED_DRIPSTONE()->setThickness(DripstoneThickness::TIP)));
		self::assertFalse($any->matches(VanillaBlocks::STONE()));
	}

	public function testUpperHalf() : void{
		$offset = 0;
		$door = CommandBlockParser::parse(["wooden_door"], $offset)->getBlock();
		$upper = CommandBlockWriter::getUpperHalf($door);
		self::assertInstanceOf(Door::class, $upper);
		self::assertTrue($upper->isTop());
		self::assertNull(CommandBlockWriter::getUpperHalf($upper));
		self::assertNull(CommandBlockWriter::getUpperHalf(VanillaBlocks::STONE()));
	}
}
