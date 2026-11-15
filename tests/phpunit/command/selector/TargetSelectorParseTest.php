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

namespace pocketmine\command\selector;

use PHPUnit\Framework\TestCase;
use pocketmine\lang\KnownTranslationKeys;

class TargetSelectorParseTest extends TestCase{

	public function testIsSelector() : void{
		self::assertTrue(TargetSelector::isSelector("@a"));
		self::assertTrue(TargetSelector::isSelector("@e[type=cow]"));
		self::assertTrue(TargetSelector::isSelector("@initiator"));
		self::assertTrue(TargetSelector::isSelector("@n"));
		self::assertTrue(TargetSelector::isSelector("@n[type=cow]"));
		self::assertTrue(TargetSelector::isSelectorArgument("@x"));
		self::assertFalse(TargetSelector::isSelectorArgument("Steve"));
		self::assertFalse(TargetSelector::isSelector("Steve"));
		self::assertFalse(TargetSelector::isSelector("@everyone"));
		self::assertFalse(TargetSelector::isSelector("@"));
	}

	public static function validProvider() : \Generator{
		yield ["@p", TargetSelector::NEAREST_PLAYER];
		yield ["@a", TargetSelector::ALL_PLAYERS];
		yield ["@r", TargetSelector::RANDOM_PLAYER];
		yield ["@e", TargetSelector::ALL_ENTITIES];
		yield ["@s", TargetSelector::SELF];
		yield ["@initiator", TargetSelector::INITIATOR];
		yield ["@n", TargetSelector::NEAREST_ENTITY];
		yield ["@n[type=cow,r=5,c=2]", TargetSelector::NEAREST_ENTITY];
		yield ["@a[type=player]", TargetSelector::ALL_PLAYERS];
		yield ["@e[r=5,rm=5]", TargetSelector::ALL_ENTITIES];
		yield ["@e[]", TargetSelector::ALL_ENTITIES];
		yield ["@e[type=cow,r=20]", TargetSelector::ALL_ENTITIES];
		yield ["@e[ type = minecraft:cow , r = 20 ]", TargetSelector::ALL_ENTITIES];
		yield ["@e[type=!cow,type=!pig,name=!Bob]", TargetSelector::ALL_ENTITIES];
		yield ["@a[name=\"Big Bob\",tag=a,tag=!b,tag=,tag=!]", TargetSelector::ALL_PLAYERS];
		yield ["@e[x=~,y=~-5,z=10.5,dx=3,dy=-2,dz=0,rm=1,r=5.5]", TargetSelector::ALL_ENTITIES];
		yield ["@a[c=-2,l=10,lm=1,m=creative,rx=90,rxm=-90,ry=180,rym=-180]", TargetSelector::ALL_PLAYERS];
		yield ["@a[m=!spectator,m=!1]", TargetSelector::ALL_PLAYERS];
		yield ["@a[m=d]", TargetSelector::ALL_PLAYERS];
		yield ["@e[family=monster,family=!undead]", TargetSelector::ALL_ENTITIES];
		yield ["@e[type=custom:thing]", TargetSelector::ALL_ENTITIES];
	}

	/**
	 * @dataProvider validProvider
	 */
	public function testParseValid(string $text, string $type) : void{
		$selector = TargetSelector::parse($text);
		self::assertSame($type, $selector->getType());
	}

	public static function invalidProvider() : \Generator{
		yield ["@x", KnownTranslationKeys::COMMANDS_GENERIC_SYNTAX];
		yield ["@everyone", KnownTranslationKeys::COMMANDS_GENERIC_SYNTAX];
		yield ["@", KnownTranslationKeys::COMMANDS_GENERIC_SYNTAX];
		yield ["@e[type=cow", KnownTranslationKeys::COMMANDS_GENERIC_SYNTAX];
		yield ["@e[type]", KnownTranslationKeys::COMMANDS_GENERIC_SYNTAX];
		yield ["@e[type=cow,,r=2]", KnownTranslationKeys::COMMANDS_GENERIC_SYNTAX];
		yield ["@e[name=\"unterminated]", KnownTranslationKeys::COMMANDS_GENERIC_SYNTAX];
		yield ["@e[foo=1]", KnownTranslationKeys::COMMANDS_GENERIC_PARAMETER_INVALID];
		yield ["@e[hasitem={item=diamond}]", KnownTranslationKeys::POCKETMINE_COMMAND_SELECTOR_UNSUPPORTEDARGUMENT];
		yield ["@e[scores={kills=1..}]", KnownTranslationKeys::POCKETMINE_COMMAND_SELECTOR_UNSUPPORTEDARGUMENT];
		yield ["@e[type=notamob]", KnownTranslationKeys::COMMANDS_GENERIC_ENTITY_INVALIDTYPE];
		yield ["@e[type=cow,type=pig]", KnownTranslationKeys::COMMANDS_GENERIC_DUPLICATETYPE];
		yield ["@e[r=1,r=2]", KnownTranslationKeys::COMMANDS_GENERIC_DUPLICATESELECTORARGUMENT];
		yield ["@a[name=a,name=b]", KnownTranslationKeys::COMMANDS_GENERIC_TOOMANYNAMES];
		yield ["@e[r=-1]", KnownTranslationKeys::COMMANDS_GENERIC_RADIUSNEGATIVE];
		yield ["@e[r=abc]", KnownTranslationKeys::COMMANDS_GENERIC_NUM_INVALID];
		yield ["@e[r=2,rm=5]", KnownTranslationKeys::COMMANDS_GENERIC_RADIUSERROR];
		yield ["@e[c=0]", KnownTranslationKeys::POCKETMINE_COMMAND_SELECTOR_INVALIDVALUE];
		yield ["@e[c=1.5]", KnownTranslationKeys::COMMANDS_GENERIC_NUM_INVALID];
		yield ["@a[l=-1]", KnownTranslationKeys::POCKETMINE_COMMAND_SELECTOR_INVALIDVALUE];
		yield ["@a[l=1,lm=5]", KnownTranslationKeys::COMMANDS_GENERIC_LEVELERROR];
		yield ["@a[m=flying]", KnownTranslationKeys::POCKETMINE_COMMAND_SELECTOR_INVALIDVALUE];
		yield ["@e[x=~a]", KnownTranslationKeys::COMMANDS_GENERIC_NUM_INVALID];
		yield ["@e[family=]", KnownTranslationKeys::POCKETMINE_COMMAND_SELECTOR_INVALIDVALUE];
		yield ["@a[type=cow]", KnownTranslationKeys::COMMANDS_GENERIC_INVALIDPLAYERTYPE];
		yield ["@p[type=!player]", KnownTranslationKeys::COMMANDS_GENERIC_INVALIDPLAYERTYPE];
	}

	public function testSyntaxErrorPointsAtTheProblem() : void{
		try{
			TargetSelector::parse("@x[r=1]");
			self::fail("@x is not a selector");
		}catch(TargetSelectorException $e){
			self::assertSame(["", "@x", "[r=1]"], $e->getTranslatable()->getParameters());
		}
	}

	/**
	 * @dataProvider invalidProvider
	 */
	public function testParseInvalid(string $text, string $expectedKey) : void{
		try{
			TargetSelector::parse($text);
			self::fail("Expected $text to be rejected");
		}catch(TargetSelectorException $e){
			self::assertSame($expectedKey, $e->getTranslatable()->getText());
		}
	}

	public function testPlayerOnly() : void{
		self::assertTrue(TargetSelector::parse("@a")->isPlayerOnly());
		self::assertTrue(TargetSelector::parse("@p[r=3]")->isPlayerOnly());
		self::assertTrue(TargetSelector::parse("@s")->isPlayerOnly());
		self::assertTrue(TargetSelector::parse("@r")->isPlayerOnly());
		self::assertTrue(TargetSelector::parse("@e[type=player]")->isPlayerOnly());
		self::assertFalse(TargetSelector::parse("@e")->isPlayerOnly());
		self::assertFalse(TargetSelector::parse("@e[type=!cow]")->isPlayerOnly());
		self::assertFalse(TargetSelector::parse("@r[type=cow]")->isPlayerOnly());
		self::assertFalse(TargetSelector::parse("@n")->isPlayerOnly());
		self::assertTrue(TargetSelector::parse("@n[type=player]")->isPlayerOnly());
	}

	public function testNormalizeEntityType() : void{
		self::assertSame("minecraft:cow", TargetSelector::normalizeEntityType("cow"));
		self::assertSame("minecraft:cow", TargetSelector::normalizeEntityType("Minecraft:Cow"));
		self::assertSame("minecraft:player", TargetSelector::normalizeEntityType("player"));
		self::assertSame("custom:thing", TargetSelector::normalizeEntityType("custom:thing"));
		self::assertNull(TargetSelector::normalizeEntityType("unicorn"));
	}
}
