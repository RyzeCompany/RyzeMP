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
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\random\XoroshiroRandom;
use function pack;
use function sprintf;
use function unpack;

class RandomTest extends TestCase{

	public function testXoroshiroSeedUpgrade() : void{
		$r = XoroshiroRandom::create(1);
		//values from deepslate's tests (generated with Minecraft Java Edition)
		self::assertSame("5272463233947570727", sprintf("%u", $r->getSeedLo()));
		self::assertSame("1927618558350093866", sprintf("%u", $r->getSeedHi()));
		$fork = XoroshiroRandom::create(1)->fork();
		self::assertInstanceOf(XoroshiroRandom::class, $fork);
		self::assertSame("17413076366490032638", sprintf("%u", $fork->getSeedLo()));
		self::assertSame("6451672561743293322", sprintf("%u", $fork->getSeedHi()));
	}

	public function testXoroshiroSequences() : void{
		$r = XoroshiroRandom::create(1);
		$expected = ["-1033667707219518978", "6451672561743293322", "-1821890263888393630", "890086654470169703", "8094835630745194324"];
		foreach($expected as $value){
			self::assertSame($value, (string) $r->nextLong());
		}
		$r = XoroshiroRandom::create(1);
		foreach([1734564350, 836234122, 825264738, -1425890201, 767430484] as $value){
			self::assertSame($value, $r->nextInt());
		}
		$r = XoroshiroRandom::create(1);
		self::assertSame(10, $r->nextBoundedInt(25));
		self::assertSame(49, $r->nextBoundedInt(256));
		self::assertSame(48, $r->nextBoundedInt(255));
		self::assertSame(169, $r->nextBoundedInt(254));
		self::assertSame(383715241, $r->nextBoundedInt(0x7FFFFFFF));
		$r = XoroshiroRandom::create(1);
		foreach([0.9439647613102243, 0.34974587038035987, 0.9012351308931007] as $value){
			self::assertSame($value, $r->nextDouble());
		}

		$r = XoroshiroRandom::create(-4172144997902289642);
		foreach(FixtureLoader::strings("xoroshiroLongs") as $value){
			self::assertSame($value, (string) $r->nextLong());
		}
		foreach(FixtureLoader::intPairs("xoroshiroBounded") as [$bound, $value]){
			self::assertSame($value, $r->nextBoundedInt($bound));
		}
		foreach(FixtureLoader::floats("xoroshiroDoubles") as $value){
			self::assertSame((float) $value, $r->nextDouble());
		}
	}

	public function testPositional() : void{
		$named = XoroshiroRandom::create(124)->forkPositional()->fromHashOf("minecraft:deepslate")->forkPositional();
		//deepslate test values
		self::assertSame(0.9723014235496521, $named->at(15, 8, 0)->nextFloat());
		self::assertSame(0.9699087142944336, $named->at(15, 7, 0)->nextFloat());
		self::assertSame(0.9694857001304626, $named->at(15, 6, 0)->nextFloat());

		$pos = XoroshiroRandom::create(42)->forkPositional();
		foreach(FixtureLoader::stringPairs("hashOf") as [$name, $value]){
			self::assertSame($value, (string) $pos->fromHashOf($name)->nextLong());
		}
	}

	public function testLegacyRandom() : void{
		//java.util.Random(123)
		$r = new LegacyRandom(123);
		foreach([-1188957731, 1018954901, -39088943, 1295249578, 1087885590] as $value){
			self::assertSame($value, $r->nextInt());
		}
		$r = new LegacyRandom(123);
		self::assertSame(185, $r->nextBoundedInt(256));
		self::assertSame(200, $r->nextBoundedInt(255));
		self::assertSame(74, $r->nextBoundedInt(254));
		$r = new LegacyRandom(123);
		foreach([0.7231742029971469, 0.9908988967772393, 0.25329310557439133] as $value){
			self::assertSame($value, $r->nextDouble());
		}
	}

	public function testFround() : void{
		foreach([0.1, -1.0E-30, 3.4E38, 1.0 + 2 ** -24, 1.0 + 3 * 2 ** -24, -0.2222, 123456.789] as $value){
			$unpacked = unpack("g", pack("g", $value));
			self::assertIsArray($unpacked);
			$expected = $unpacked[1];
			self::assertSame($expected, FloatMath::fround($value));
		}
	}
}
