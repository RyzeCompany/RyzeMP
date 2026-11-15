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
use pocketmine\utils\fixtures\TestAbstractClass;
use pocketmine\utils\fixtures\TestInstantiableClass;
use pocketmine\utils\fixtures\TestInterface;
use pocketmine\utils\fixtures\TestSubclassOfInstantiableClass;
use pocketmine\utils\fixtures\TestTrait;
use function define;
use function defined;

class UtilsTest extends TestCase{

	public function setUp() : void{
		if(!defined('pocketmine\PATH')){
			define('pocketmine\PATH', 'dummy');
		}
	}

	/**
	 * @return string[][]
	 * @phpstan-return list<array{string}>
	 */
	public static function parseDocCommentNewlineProvider() : array{
		return [
			["\t/**\r\n\t * @param PlayerJoinEvent \$event\r\n\t * @priority HIGHEST\r\n\t * @notHandler\r\n\t */"],
			["\t/**\n\t * @param PlayerJoinEvent \$event\n\t * @priority HIGHEST\n\t * @notHandler\n\t */"],
			["\t/**\r\t * @param PlayerJoinEvent \$event\r\t * @priority HIGHEST\r\t * @notHandler\r\t */"]
		];
	}

	/**
	 * @dataProvider parseDocCommentNewlineProvider
	 */
	public function testParseDocCommentNewlines(string $docComment) : void{
		$tags = Utils::parseDocComment($docComment);

		self::assertArrayHasKey("notHandler", $tags);
		self::assertEquals("", $tags["notHandler"]);
		self::assertArrayHasKey("priority", $tags);
		self::assertEquals("HIGHEST", $tags["priority"]);
	}

	/**
	 * @return string[][]
	 * @phpstan-return list<array{string}>
	 */
	public static function parseDocCommentOneLineProvider() : array{
		return [
			["/** @ignoreCancelled true dummy */"],
			["/**@ignoreCancelled true dummy*/"],
			["/** @ignoreCancelled    true dummy */"]
		];
	}

	/**
	 * @dataProvider parseDocCommentOneLineProvider
	 */
	public function testParseOneLineDocComment(string $comment) : void{
		$tags = Utils::parseDocComment($comment);
		self::assertArrayHasKey("ignoreCancelled", $tags);
		self::assertEquals("true dummy", $tags["ignoreCancelled"]);
	}

	public function testParseEmptyDocComment() : void{
		$tags = Utils::parseDocComment("");
		self::assertCount(0, $tags);
	}

	public function testParseDocCommentWithTagsContainingHyphens() : void{
		$tags = Utils::parseDocComment("/** @phpstan-return list<string> */");
		self::assertArrayHasKey("phpstan-return", $tags);
		self::assertEquals("list<string>", $tags["phpstan-return"]);
	}

	public function testNamespacedNiceClosureName() : void{
		//be careful with this test. The closure has to be declared on the same line as the assertion.
		self::assertSame('closure@' . Filesystem::cleanPath(__FILE__) . '#L' . __LINE__, Utils::getNiceClosureName(function() : void{}));
	}

	/**
	 * @return string[][]
	 * @return list<array{class-string, class-string}>
	 */
	public static function validInstanceProvider() : array{
		return [
			//direct instance / implement / extend
			[TestInstantiableClass::class, TestInstantiableClass::class],
			[TestInstantiableClass::class, TestAbstractClass::class],
			[TestInstantiableClass::class, TestInterface::class],

			//inherited
			[TestSubclassOfInstantiableClass::class, TestInstantiableClass::class],
			[TestSubclassOfInstantiableClass::class, TestAbstractClass::class],
			[TestSubclassOfInstantiableClass::class, TestInterface::class]
		];
	}

	/**
	 * @dataProvider validInstanceProvider
	 * @doesNotPerformAssertions
	 * @phpstan-param class-string $className
	 * @phpstan-param class-string $baseName
	 */
	public function testValidInstanceWithValidCombinations(string $className, string $baseName) : void{
		Utils::testValidInstance($className, $baseName);
	}

	/**
	 * @return string[][]
	 * @return list<array{string, string}>
	 */
	public static function validInstanceInvalidCombinationsProvider() : array{
		return [
			["iDontExist abc", TestInstantiableClass::class],
			[TestInstantiableClass::class, "iDon'tExist abc"],
			["iDontExist", "iAlsoDontExist"],
			[TestInstantiableClass::class, TestTrait::class],
			[TestTrait::class, TestTrait::class],
			[TestAbstractClass::class, TestAbstractClass::class],
			[TestInterface::class, TestInterface::class],
			[TestInstantiableClass::class, TestSubclassOfInstantiableClass::class]
		];
	}

	/**
	 * @dataProvider validInstanceInvalidCombinationsProvider
	 */
	public function testValidInstanceInvalidParameters(string $className, string $baseName) : void{
		$this->expectException(\InvalidArgumentException::class);
		Utils::testValidInstance($className, $baseName); //@phpstan-ignore-line
	}

	/**
	 * @return \Generator<int, array{string, ?int}, void, void>
	 */
	public static function cgroupCpuLimitProvider() : \Generator{
		yield ["max 100000", null]; //cgroup v2, no limit
		yield ["max 100000\n", null];
		yield ["200000 100000", 2]; //200% (Pterodactyl CPU limit 200)
		yield ["150000 100000\n", 2]; //150% rounds up
		yield ["50000 100000", 1];
		yield ["-1 100000", null]; //cgroup v1, no limit
		yield ["", null];
		yield ["garbage", null];
	}

	/**
	 * @dataProvider cgroupCpuLimitProvider
	 */
	public function testParseCgroupCpuLimit(string $limit, ?int $expected) : void{
		self::assertSame($expected, Utils::parseCgroupCpuLimit($limit));
	}

	/**
	 * @return \Generator<int, array{string, ?int}, void, void>
	 */
	public static function cgroupCpuListProvider() : \Generator{
		yield ["0-1,4", 3];
		yield ["0-1,4\n", 3];
		yield ["0", 1];
		yield ["0-15", 16];
		yield ["2,4,6-7", 4]; //Pterodactyl CPU pinning "2,4,6-7"
		yield ["", null];
		yield ["\n", null];
		yield ["3-1", null];
		yield ["0-", null];
		yield ["0,,1", null];
		yield ["garbage", null];
	}

	/**
	 * @dataProvider cgroupCpuListProvider
	 */
	public function testParseCgroupCpuList(string $list, ?int $expected) : void{
		self::assertSame($expected, Utils::parseCgroupCpuList($list));
	}

	/**
	 * @return \Generator<int, array{string, ?string}, void, void>
	 */
	public static function cgroupV2PathProvider() : \Generator{
		yield ["0::/\n", "/"]; //own cgroup namespace (Docker's default on cgroup v2)
		yield ["0::/system.slice/docker-0123abcd.scope\n", "/system.slice/docker-0123abcd.scope"]; //host cgroup namespace
		//cgroup v1 hierarchies listed before the unified one (hybrid systems)
		yield ["12:cpu,cpuacct:/docker/0123abcd\n1:name=systemd:/docker/0123abcd\n0::/docker/0123abcd\n", "/docker/0123abcd"];
		yield ["12:cpu,cpuacct:/docker/0123abcd\n1:name=systemd:/docker/0123abcd\n", null]; //cgroup v1 only
		yield ["0::/../../user.slice\n", null]; //outside the cgroup namespace
		yield ["0::\n", null];
		yield ["", null];
	}

	/**
	 * @dataProvider cgroupV2PathProvider
	 */
	public function testParseCgroupV2Path(string $procSelfCgroup, ?string $expected) : void{
		self::assertSame($expected, Utils::parseCgroupV2Path($procSelfCgroup));
	}

	public function testGetUsableCoreCount() : void{
		$usable = Utils::getUsableCoreCount();
		self::assertGreaterThanOrEqual(0, $usable);
		if(Utils::getCoreCount() > 0){
			self::assertLessThanOrEqual(Utils::getCoreCount(), $usable, "the cgroup limits only lower the core count");
		}
	}
}
