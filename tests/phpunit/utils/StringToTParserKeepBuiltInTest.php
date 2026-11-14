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

namespace pocketmine\utils;

use PHPUnit\Framework\TestCase;
use pocketmine\item\AddedItemAliases;
use function in_array;

final class StringToTParserKeepBuiltInTest extends TestCase{

	/** @phpstan-return StringToTParser<string> */
	private static function parser() : StringToTParser{
		/** @phpstan-extends StringToTParser<string> */
		return new class extends StringToTParser{};
	}

	public function testBuiltInAliasIsKept() : void{
		$p = self::parser();
		$p->register("filled_map", fn() => "server");
		$p->register("map", fn() => "server map");
		$p->keepBuiltIn("filled_map", "map", "unknown_alias");

		//a plugin implementing filled maps itself loads, and the built-in one stays in use
		$p->register("filled_map", fn() => "plugin");
		$p->register("filled_map", fn() => "another plugin");
		$p->registerAlias("map", "filled_map");
		self::assertSame("server", $p->parse("filled_map"));

		//override() still replaces on purpose, as in PocketMine-MP 5.44.3
		$p->override("map", fn() => "overridden");
		self::assertSame("overridden", $p->parse("map"));
	}

	public function testOtherAliasesStillThrow() : void{
		$p = self::parser();
		$p->register("diamond", fn() => "server");
		$p->keepBuiltIn("filled_map");
		$this->expectException(\InvalidArgumentException::class);
		$p->register("diamond", fn() => "plugin");
	}

	public function testAddedItemAliasesOnlyHasNewThings() : void{
		self::assertTrue(in_array("filled_map", AddedItemAliases::LIST, true));
		self::assertFalse(in_array("diamond", AddedItemAliases::LIST, true));
	}
}
