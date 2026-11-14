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

namespace pocketmine\command\parameter;

use PHPUnit\Framework\TestCase;
use pocketmine\command\defaults\GamemodeCommand;
use pocketmine\command\utils\CommandItemParser;
use pocketmine\entity\effect\StringToEffectParser;
use pocketmine\item\enchantment\StringToEnchantmentParser;
use pocketmine\item\LegacyStringToItemParserException;
use pocketmine\item\VanillaItems;
use pocketmine\player\GameMode;
use pocketmine\world\World;
use function count;

/**
 * Every value suggested to clients is accepted by the commands.
 */
class CommandEnumsTest extends TestCase{

	public function testItemsParse() : void{
		$ids = CommandEnums::items();
		self::assertGreaterThan(1000, count($ids));
		self::assertContains("diamond_sword", $ids);
		self::assertContains("white_terracotta", $ids);
		self::assertNotContains("air", $ids);
		foreach($ids as $id){
			$item = CommandItemParser::parse($id);
			self::assertFalse($item->isNull(), $id);
		}
		self::assertTrue(CommandItemParser::parse("minecraft:diamond")->equals(VanillaItems::DIAMOND()));
		self::assertTrue(CommandItemParser::parse("Minecraft:White_Terracotta")->equals(CommandItemParser::parse("white_terracotta")));
	}

	public function testUnknownItem() : void{
		$this->expectException(LegacyStringToItemParserException::class);
		CommandItemParser::parse("not_an_item_at_all");
	}

	public function testWithData() : void{
		$apple = VanillaItems::APPLE();
		self::assertSame($apple, CommandItemParser::withData($apple, 0));
		self::assertNull(CommandItemParser::withData(VanillaItems::POTION(), 30000), "unknown potion type");
	}

	public function testEffectsAndEnchantmentsParse() : void{
		foreach(CommandEnums::effects() as $name){
			self::assertNotNull(StringToEffectParser::getInstance()->parse($name), $name);
		}
		foreach(CommandEnums::enchantments() as $name){
			self::assertNotNull(StringToEnchantmentParser::getInstance()->parse($name), $name);
		}
	}

	public function testGameModesParse() : void{
		foreach(CommandEnums::gameModes() as $name){
			self::assertNotNull(GamemodeCommand::parseGameMode($name, GameMode::SURVIVAL), $name);
		}
		foreach(CommandEnums::gameModes(withDefault: false) as $name){
			self::assertNotNull(GamemodeCommand::parseGameMode($name, null), $name);
		}
		self::assertSame(GameMode::SPECTATOR, GamemodeCommand::parseGameMode("6", null));
		self::assertSame(GameMode::CREATIVE, GamemodeCommand::parseGameMode("5", GameMode::CREATIVE));
		self::assertNull(GamemodeCommand::parseGameMode("default", null));
	}

	public function testDifficultiesParse() : void{
		foreach(CommandEnums::difficulties() as $name){
			self::assertNotSame(-1, World::getDifficultyFromString($name), $name);
		}
	}
}
