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

namespace pocketmine\world\generator\vanilla\feature\block;

/**
 * Java's Direction enum, as ints in vanilla's ordinal order (the order matters: vanilla iterates and picks random
 * directions by ordinal).
 */
final class Direction{
	public const DOWN = 0;
	public const UP = 1;
	public const NORTH = 2;
	public const SOUTH = 3;
	public const WEST = 4;
	public const EAST = 5;

	public const STEP_X = [0, 0, 0, 0, -1, 1];
	public const STEP_Y = [-1, 1, 0, 0, 0, 0];
	public const STEP_Z = [0, 0, -1, 1, 0, 0];
	public const OPPOSITE = [1, 0, 3, 2, 5, 4];
	public const NAMES = ["down", "up", "north", "south", "west", "east"];
	/** Horizontal directions in 2D data value order (Direction.Plane.HORIZONTAL / from2DDataValue): S, W, N, E. */
	public const HORIZONTAL = [self::SOUTH, self::WEST, self::NORTH, self::EAST];
	/** Direction.Plane.HORIZONTAL iteration order: N, E, S, W. */
	public const PLANE_HORIZONTAL = [self::NORTH, self::EAST, self::SOUTH, self::WEST];
	/** Direction.Plane.VERTICAL: UP, DOWN. */
	public const PLANE_VERTICAL = [self::UP, self::DOWN];
	/** Axis per direction: 0 = x, 1 = y, 2 = z. */
	public const AXIS = [1, 1, 2, 2, 0, 0];

	private function __construct(){
		//NOOP
	}

	public static function fromName(string $name) : int{
		return match($name){
			"down" => self::DOWN,
			"up" => self::UP,
			"north" => self::NORTH,
			"south" => self::SOUTH,
			"west" => self::WEST,
			"east" => self::EAST,
			default => throw new \RuntimeException("Unknown direction $name")
		};
	}

	/** Clockwise rotation around Y (horizontal directions only). */
	public static function clockWise(int $direction) : int{
		return match($direction){
			self::NORTH => self::EAST,
			self::EAST => self::SOUTH,
			self::SOUTH => self::WEST,
			self::WEST => self::NORTH,
			default => throw new \InvalidArgumentException("Not a horizontal direction")
		};
	}

	public static function counterClockWise(int $direction) : int{
		return match($direction){
			self::NORTH => self::WEST,
			self::WEST => self::SOUTH,
			self::SOUTH => self::EAST,
			self::EAST => self::NORTH,
			default => throw new \InvalidArgumentException("Not a horizontal direction")
		};
	}
}
