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

namespace pocketmine\block\utils;

use pocketmine\math\Facing;

/**
 * Orientation of a crafter: the direction its front faces and the direction its top points to (Bedrock block state
 * "orientation", e.g. "north_up" or "down_east"; same set as Java's FrontAndTop).
 */
enum CrafterOrientation{
	case DOWN_EAST;
	case DOWN_NORTH;
	case DOWN_SOUTH;
	case DOWN_WEST;
	case UP_EAST;
	case UP_NORTH;
	case UP_SOUTH;
	case UP_WEST;
	case WEST_UP;
	case EAST_UP;
	case NORTH_UP;
	case SOUTH_UP;

	/**
	 * Direction the front (output) of the crafter faces.
	 */
	public function getFront() : int{
		return match($this){
			self::DOWN_EAST, self::DOWN_NORTH, self::DOWN_SOUTH, self::DOWN_WEST => Facing::DOWN,
			self::UP_EAST, self::UP_NORTH, self::UP_SOUTH, self::UP_WEST => Facing::UP,
			self::WEST_UP => Facing::WEST,
			self::EAST_UP => Facing::EAST,
			self::NORTH_UP => Facing::NORTH,
			self::SOUTH_UP => Facing::SOUTH,
		};
	}

	/**
	 * Direction the top of the crafter points to.
	 */
	public function getTop() : int{
		return match($this){
			self::DOWN_EAST, self::UP_EAST => Facing::EAST,
			self::DOWN_NORTH, self::UP_NORTH => Facing::NORTH,
			self::DOWN_SOUTH, self::UP_SOUTH => Facing::SOUTH,
			self::DOWN_WEST, self::UP_WEST => Facing::WEST,
			self::WEST_UP, self::EAST_UP, self::NORTH_UP, self::SOUTH_UP => Facing::UP,
		};
	}

	/**
	 * Returns the orientation with the given front and top. For a horizontal front the top is always up.
	 */
	public static function fromFrontAndTop(int $front, int $top) : self{
		foreach(self::cases() as $case){
			if($case->getFront() === $front && $case->getTop() === $top){
				return $case;
			}
		}
		throw new \InvalidArgumentException("Invalid crafter orientation: front " . Facing::toString($front) . ", top " . Facing::toString($top));
	}
}
