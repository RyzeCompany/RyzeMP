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

namespace pocketmine\world\generator\vanilla\structure\piece\monument;

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\random\LegacyRandom;

/**
 * OceanMonumentPieces.MonumentRoomFitter and its implementations (FitDoubleXYRoom, FitDoubleYZRoom, FitDoubleZRoom,
 * FitDoubleXRoom, FitDoubleYRoom, FitSimpleTopRoom, FitSimpleRoom), selected by kind.
 */
final class MonumentRoomFitter{
	public const DOUBLE_XY = 0;
	public const DOUBLE_YZ = 1;
	public const DOUBLE_Z = 2;
	public const DOUBLE_X = 3;
	public const DOUBLE_Y = 4;
	public const SIMPLE_TOP = 5;
	public const SIMPLE = 6;

	/** The order MonumentBuilding tries them in. */
	public const ALL = [self::DOUBLE_XY, self::DOUBLE_YZ, self::DOUBLE_Z, self::DOUBLE_X, self::DOUBLE_Y, self::SIMPLE_TOP, self::SIMPLE];

	private function __construct(){
		//NOOP
	}

	private static function open(RoomDefinition $definition, int $direction) : bool{
		return $definition->hasOpening[$direction] && !$definition->get($direction)->claimed;
	}

	public static function fits(int $kind, RoomDefinition $definition) : bool{
		return match($kind){
			self::DOUBLE_XY => self::open($definition, Direction::EAST) && self::open($definition, Direction::UP) && self::open($definition->get(Direction::EAST), Direction::UP),
			self::DOUBLE_YZ => self::open($definition, Direction::NORTH) && self::open($definition, Direction::UP) && self::open($definition->get(Direction::NORTH), Direction::UP),
			self::DOUBLE_Z => self::open($definition, Direction::NORTH),
			self::DOUBLE_X => self::open($definition, Direction::EAST),
			self::DOUBLE_Y => self::open($definition, Direction::UP),
			self::SIMPLE_TOP => !$definition->hasOpening[Direction::WEST] && !$definition->hasOpening[Direction::EAST] && !$definition->hasOpening[Direction::NORTH] && !$definition->hasOpening[Direction::SOUTH] && !$definition->hasOpening[Direction::UP],
			default => true
		};
	}

	public static function create(int $kind, int $orientation, RoomDefinition $definition, LegacyRandom $random) : OceanMonumentPiece{
		switch($kind){
			case self::DOUBLE_XY:
				$definition->claimed = true;
				$definition->get(Direction::EAST)->claimed = true;
				$definition->get(Direction::UP)->claimed = true;
				$definition->get(Direction::EAST)->get(Direction::UP)->claimed = true;
				return new OceanMonumentDoubleXYRoom($orientation, $definition);
			case self::DOUBLE_YZ:
				$definition->claimed = true;
				$definition->get(Direction::NORTH)->claimed = true;
				$definition->get(Direction::UP)->claimed = true;
				$definition->get(Direction::NORTH)->get(Direction::UP)->claimed = true;
				return new OceanMonumentDoubleYZRoom($orientation, $definition);
			case self::DOUBLE_Z:
				$source = $definition;
				if(!$definition->hasOpening[Direction::NORTH] || $definition->get(Direction::NORTH)->claimed){
					$source = $definition->get(Direction::SOUTH);
				}
				$source->claimed = true;
				$source->get(Direction::NORTH)->claimed = true;
				return new OceanMonumentDoubleZRoom($orientation, $source);
			case self::DOUBLE_X:
				$definition->claimed = true;
				$definition->get(Direction::EAST)->claimed = true;
				return new OceanMonumentDoubleXRoom($orientation, $definition);
			case self::DOUBLE_Y:
				$definition->claimed = true;
				$definition->get(Direction::UP)->claimed = true;
				return new OceanMonumentDoubleYRoom($orientation, $definition);
			case self::SIMPLE_TOP:
				$definition->claimed = true;
				return new OceanMonumentSimpleTopRoom($orientation, $definition);
			default:
				$definition->claimed = true;
				return new OceanMonumentSimpleRoom($orientation, $definition, $random);
		}
	}
}
