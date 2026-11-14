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
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\world\World;
use function str_repeat;

class CommandCoordinatesTest extends TestCase{

	public function testIsCoordinate() : void{
		foreach(["0", "-5", "1.5", ".5", "-0.25", "~", "~3", "~-2.5", "^", "^1", "^-.5", "10."] as $arg){
			self::assertTrue(CommandCoordinates::isCoordinate($arg), $arg);
		}
		foreach(["", "~~", "^~", "abc", "1e5", "--1", "~a", "@s", "true", "1,5"] as $arg){
			self::assertFalse(CommandCoordinates::isCoordinate($arg), $arg);
		}
	}

	private static function assertVector(Vector3 $expected, Vector3 $actual) : void{
		self::assertEqualsWithDelta($expected->x, $actual->x, 0.0001);
		self::assertEqualsWithDelta($expected->y, $actual->y, 0.0001);
		self::assertEqualsWithDelta($expected->z, $actual->z, 0.0001);
	}

	public function testAbsoluteAndRelative() : void{
		$origin = new Location(10, 64, -10, null, 0, 0);
		self::assertVector(new Vector3(1, 2, 3), CommandCoordinates::parsePosition($origin, "1", "2", "3"));
		self::assertVector(new Vector3(10, 66, -12.5), CommandCoordinates::parsePosition($origin, "~", "~2", "~-2.5"));
		self::assertVector(new Vector3(5, 64, -10), CommandCoordinates::parsePosition($origin, "5", "~", "~"));
	}

	public function testLocal() : void{
		//yaw 0 faces +z: forward = +z, left = +x
		$south = new Location(0, 64, 0, null, 0, 0);
		self::assertVector(new Vector3(0, 64, 2), CommandCoordinates::parsePosition($south, "^", "^", "^2"));
		self::assertVector(new Vector3(1, 65, 0), CommandCoordinates::parsePosition($south, "^1", "^1", "^"));

		//yaw 90 faces -x: left = +z
		$west = new Location(0, 64, 0, null, 90, 0);
		self::assertVector(new Vector3(-3, 64, 0), CommandCoordinates::parsePosition($west, "^", "^", "^3"));
		self::assertVector(new Vector3(0, 64, 1), CommandCoordinates::parsePosition($west, "^1", "^", "^"));

		//pitch -90 looks up: forward = +y
		$up = new Location(0, 64, 0, null, 0, -90);
		self::assertVector(new Vector3(0, 69, 0), CommandCoordinates::parsePosition($up, "^", "^", "^5"));
	}

	public function testLimits() : void{
		$origin = new Location(0, 0, 0, null, 0, 0);
		$pos = CommandCoordinates::parsePosition($origin, "40000000", "100000", "~-40000000");
		self::assertSame((float) CommandCoordinates::MAX_COORD, $pos->x);
		self::assertSame((float) World::Y_MAX, $pos->y);
		self::assertSame((float) CommandCoordinates::MIN_COORD, $pos->z);
	}

	/**
	 * A few hundred digits are INF as a float; as a local coordinate 0 * INF gives NAN, which used to reach
	 * Entity::teleport() and crash the server.
	 */
	public function testNonFiniteIsRejected() : void{
		$huge = str_repeat("9", 400);
		$origin = new Location(0, 64, 0, null, 0, 0);
		$rejected = 0;
		foreach([["^$huge", "^", "^"], ["^", "^", "^$huge"], ["^", "^$huge", "^"], [$huge, "64", "0"], ["~", "~-$huge", "~"]] as [$x, $y, $z]){
			try{
				CommandCoordinates::parsePosition($origin, $x, $y, $z);
				self::fail("$x $y $z should be rejected");
			}catch(InvalidCommandSyntaxException){
				$rejected++;
			}
		}
		foreach([$huge, "-$huge", "~$huge"] as $arg){
			try{
				CommandCoordinates::parseRotation(0, $arg);
				self::fail("rotation $arg should be rejected");
			}catch(InvalidCommandSyntaxException){
				$rejected++;
			}
		}
		self::assertSame(8, $rejected);
	}

	public function testMixedLocalIsRejected() : void{
		$this->expectException(InvalidCommandSyntaxException::class);
		CommandCoordinates::parsePosition(new Location(0, 0, 0, null, 0, 0), "^", "~", "^");
	}

	public function testInvalidIsRejected() : void{
		$this->expectException(InvalidCommandSyntaxException::class);
		CommandCoordinates::parsePosition(new Location(0, 0, 0, null, 0, 0), "1", "two", "3");
	}

	public function testRotation() : void{
		self::assertSame(45.0, CommandCoordinates::parseRotation(10, "45"));
		self::assertSame(55.0, CommandCoordinates::parseRotation(10, "~45"));
		self::assertSame(10.0, CommandCoordinates::parseRotation(10, "~"));
		$this->expectException(InvalidCommandSyntaxException::class);
		CommandCoordinates::parseRotation(10, "^5");
	}
}
