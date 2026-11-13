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

namespace pocketmine\world\generator\vanilla\structure\template;

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use function count;
use function explode;
use function implode;
use function in_array;
use function sort;
use function str_starts_with;
use function substr;

/**
 * Vanilla's Rotation and Mirror: transforms of template positions (StructureTemplate.transform()), directions and
 * block states (BlockState.rotate() / mirror()).
 */
final class Rotations{
	public const NONE = 0;
	public const CLOCKWISE_90 = 1;
	public const CLOCKWISE_180 = 2;
	public const COUNTERCLOCKWISE_90 = 3;
	public const NAMES = ["none", "clockwise_90", "180", "counterclockwise_90"];

	public const MIRROR_NONE = 0;
	/** Mirror.LEFT_RIGHT: z = -z (north <-> south) */
	public const MIRROR_LEFT_RIGHT = 1;
	/** Mirror.FRONT_BACK: x = -x (east <-> west) */
	public const MIRROR_FRONT_BACK = 2;

	private const CLOCKWISE = [Direction::NORTH => Direction::EAST, Direction::EAST => Direction::SOUTH, Direction::SOUTH => Direction::WEST, Direction::WEST => Direction::NORTH];
	private const RAIL_SHAPES = ["north_south", "east_west", "ascending_east", "ascending_west", "ascending_north", "ascending_south", "south_east", "south_west", "north_west", "north_east"];

	/** @phpstan-var array<string, array<int, int>> "rotation:mirror" => state => state */
	private static array $cache = [];
	private static ?JavaBlocks $cacheOwner = null;

	private function __construct(){
		//NOOP
	}

	public static function rotateDirection(int $direction, int $rotation) : int{
		if(!isset(self::CLOCKWISE[$direction])){
			return $direction;
		}
		for($i = 0; $i < $rotation; ++$i){
			$direction = self::CLOCKWISE[$direction];
		}
		return $direction;
	}

	public static function mirrorDirection(int $direction, int $mirror) : int{
		return match(true){
			$mirror === self::MIRROR_LEFT_RIGHT && ($direction === Direction::NORTH || $direction === Direction::SOUTH) => Direction::OPPOSITE[$direction],
			$mirror === self::MIRROR_FRONT_BACK && ($direction === Direction::EAST || $direction === Direction::WEST) => Direction::OPPOSITE[$direction],
			default => $direction
		};
	}

	/**
	 * Mirror.getRotation(direction)
	 */
	public static function mirrorRotation(int $direction, int $mirror) : int{
		$axis = Direction::AXIS[$direction];
		return ($mirror === self::MIRROR_LEFT_RIGHT && $axis === 2) || ($mirror === self::MIRROR_FRONT_BACK && $axis === 0) ? self::CLOCKWISE_180 : self::NONE;
	}

	/**
	 * Rotation.getRotated(rotation): composition of two rotations.
	 */
	public static function compose(int $a, int $b) : int{
		return ($a + $b) & 3;
	}

	/**
	 * StructureTemplate.transform(pos, mirror, rotation, pivot)
	 * @phpstan-return array{int, int}
	 */
	public static function transform(int $x, int $z, int $mirror, int $rotation, int $pivotX, int $pivotZ) : array{
		if($mirror === self::MIRROR_LEFT_RIGHT){
			$z = -$z;
		}elseif($mirror === self::MIRROR_FRONT_BACK){
			$x = -$x;
		}
		return match($rotation){
			self::COUNTERCLOCKWISE_90 => [$pivotX - $pivotZ + $z, $pivotX + $pivotZ - $x],
			self::CLOCKWISE_90 => [$pivotX + $pivotZ - $z, $pivotZ - $pivotX + $x],
			self::CLOCKWISE_180 => [$pivotX + $pivotX - $x, $pivotZ + $pivotZ - $z],
			default => [$x, $z]
		};
	}

	/**
	 * StructureTemplate.transform(Vec3 pos, mirror, rotation, pivot), for entity positions.
	 * @phpstan-return array{float, float}
	 */
	public static function transformExact(float $x, float $z, int $mirror, int $rotation, int $pivotX, int $pivotZ) : array{
		if($mirror === self::MIRROR_LEFT_RIGHT){
			$z = 1.0 - $z;
		}elseif($mirror === self::MIRROR_FRONT_BACK){
			$x = 1.0 - $x;
		}
		return match($rotation){
			self::COUNTERCLOCKWISE_90 => [$pivotX - $pivotZ + $z, $pivotX + $pivotZ + 1 - $x],
			self::CLOCKWISE_90 => [$pivotX + $pivotZ + 1 - $z, $pivotZ - $pivotX + $x],
			self::CLOCKWISE_180 => [$pivotX + $pivotX + 1 - $x, $pivotZ + $pivotZ + 1 - $z],
			default => [$x, $z]
		};
	}

	/**
	 * Yaw of a rotated/mirrored entity (Entity.rotate() + Entity.mirror()).
	 */
	public static function transformYaw(float $yaw, int $mirror, int $rotation) : float{
		$yaw = match($mirror){
			self::MIRROR_LEFT_RIGHT => 180.0 - $yaw,
			self::MIRROR_FRONT_BACK => -$yaw,
			default => $yaw
		};
		return match($rotation){
			self::CLOCKWISE_180 => $yaw + 180.0,
			self::COUNTERCLOCKWISE_90 => $yaw + 270.0,
			self::CLOCKWISE_90 => $yaw + 90.0,
			default => $yaw
		};
	}

	/**
	 * StructureTemplate.getBoundingBox(startPos, rotation, pivot, mirror, size)
	 * @phpstan-return array{int, int, int, int, int, int}
	 */
	public static function boundingBox(int $x, int $y, int $z, int $sizeX, int $sizeY, int $sizeZ, int $rotation, int $mirror = self::MIRROR_NONE, int $pivotX = 0, int $pivotZ = 0) : array{
		[$ax, $az] = self::transform(0, 0, $mirror, $rotation, $pivotX, $pivotZ);
		[$bx, $bz] = self::transform($sizeX - 1, $sizeZ - 1, $mirror, $rotation, $pivotX, $pivotZ);
		return [
			$x + ($ax < $bx ? $ax : $bx), $y, $z + ($az < $bz ? $az : $bz),
			$x + ($ax > $bx ? $ax : $bx), $y + $sizeY - 1, $z + ($az > $bz ? $az : $bz)
		];
	}

	/**
	 * BlockState.mirror(mirror).rotate(rotation), cached.
	 */
	public static function transformState(JavaBlocks $blocks, int $state, int $mirror, int $rotation) : int{
		if($mirror === self::MIRROR_NONE && $rotation === self::NONE){
			return $state;
		}
		if(self::$cacheOwner !== $blocks){
			self::$cache = [];
			self::$cacheOwner = $blocks;
		}
		$key = $rotation . ":" . $mirror;
		if(isset(self::$cache[$key][$state])){
			return self::$cache[$key][$state];
		}
		$result = $mirror !== self::MIRROR_NONE ? self::mirrorState($blocks, $state, $mirror) : $state;
		$result = self::rotateState($blocks, $result, $rotation);
		return self::$cache[$key][$state] = $result;
	}

	public static function rotateState(JavaBlocks $blocks, int $state, int $rotation) : int{
		if($rotation === self::NONE){
			return $state;
		}
		$result = $state;
		foreach($blocks->propertyNames($state) as $property){
			$value = $blocks->getProperty($state, $property);
			if($value === null){
				continue;
			}
			if(($property === "facing" || $property === "horizontal_facing") && $value !== "up" && $value !== "down"){
				$result = $blocks->with($result, $property, Direction::NAMES[self::rotateDirection(Direction::fromName($value), $rotation)]);
			}elseif($property === "axis" && ($rotation === self::CLOCKWISE_90 || $rotation === self::COUNTERCLOCKWISE_90) && $value !== "y"){
				$result = $blocks->with($result, $property, $value === "x" ? "z" : "x");
			}elseif($property === "rotation"){
				$result = $blocks->withInt($result, $property, ((int) $value + $rotation * 4) % 16);
			}elseif($property === "shape" && in_array($value, self::RAIL_SHAPES, true)){
				$result = $blocks->with($result, $property, self::transformRailShape($value, fn(int $d) : int => self::rotateDirection($d, $rotation)));
			}elseif($property === "orientation"){
				[$front, $top] = explode("_", $value, 2) + [1 => "up"];
				$result = $blocks->with($result, $property, Direction::NAMES[self::rotateDirection(Direction::fromName($front), $rotation)] . "_" . Direction::NAMES[self::rotateDirection(Direction::fromName($top), $rotation)]);
			}
		}
		$sides = ["north", "east", "south", "west"];
		if($blocks->hasProperty($state, "north") && $blocks->hasProperty($state, "east") && $blocks->hasProperty($state, "south") && $blocks->hasProperty($state, "west")){
			foreach($sides as $i => $side){
				$value = $blocks->getProperty($state, $side);
				if($value !== null){
					$result = $blocks->with($result, $sides[($i + $rotation) % 4], $value);
				}
			}
		}
		return $result;
	}

	public static function mirrorState(JavaBlocks $blocks, int $state, int $mirror) : int{
		$name = $blocks->blockName($state);
		$facing = $blocks->getProperty($state, "facing");
		if($facing !== null && $facing !== "up" && $facing !== "down"){
			$direction = Direction::fromName($facing);
			$axisRotation = self::mirrorRotation($direction, $mirror);
			if($blocks->hasProperty($state, "shape") && $blocks->hasProperty($state, "half") && !$blocks->hasProperty($state, "hinge")){
				//stairs
				if($axisRotation !== self::NONE){
					$shape = $blocks->getProperty($state, "shape");
					$swapped = match($shape){
						"inner_left" => "inner_right",
						"inner_right" => "inner_left",
						"outer_left" => "outer_right",
						"outer_right" => "outer_left",
						default => $shape
					};
					$state = self::rotateState($blocks, $state, self::CLOCKWISE_180);
					return $swapped !== null ? $blocks->with($state, "shape", $swapped) : $state;
				}
				return $state;
			}
			$state = self::rotateState($blocks, $state, $axisRotation);
			if($blocks->hasProperty($state, "hinge")){
				$state = $blocks->with($state, "hinge", $blocks->getProperty($state, "hinge") === "left" ? "right" : "left");
			}elseif($blocks->hasProperty($state, "type") && in_array($blocks->getProperty($state, "type"), ["left", "right"], true) && ($name === "minecraft:chest" || $name === "minecraft:trapped_chest" || $name === "minecraft:copper_chest" || $name === "minecraft:exposed_copper_chest" || $name === "minecraft:weathered_copper_chest" || $name === "minecraft:oxidized_copper_chest")){
				$state = $blocks->with($state, "type", $blocks->getProperty($state, "type") === "left" ? "right" : "left");
			}
			return $state;
		}
		$rotationValue = $blocks->getProperty($state, "rotation");
		if($rotationValue !== null){
			$r = (int) $rotationValue;
			$r = $mirror === self::MIRROR_LEFT_RIGHT ? (16 - $r) % 16 : (8 - $r + 16) % 16;
			return $blocks->withInt($state, "rotation", $r);
		}
		$shape = $blocks->getProperty($state, "shape");
		if($shape !== null && in_array($shape, self::RAIL_SHAPES, true)){
			return $blocks->with($state, "shape", self::transformRailShape($shape, fn(int $d) : int => self::mirrorDirection($d, $mirror)));
		}
		if($blocks->hasProperty($state, "north") && $blocks->hasProperty($state, "south") && $blocks->hasProperty($state, "east") && $blocks->hasProperty($state, "west")){
			[$a, $b] = $mirror === self::MIRROR_LEFT_RIGHT ? ["north", "south"] : ["east", "west"];
			$va = $blocks->getProperty($state, $a);
			$vb = $blocks->getProperty($state, $b);
			if($va !== null && $vb !== null){
				$state = $blocks->with($blocks->with($state, $a, $vb), $b, $va);
			}
		}
		return $state;
	}

	/**
	 * @phpstan-param \Closure(int) : int $transform
	 */
	private static function transformRailShape(string $shape, \Closure $transform) : string{
		if(str_starts_with($shape, "ascending_")){
			return "ascending_" . Direction::NAMES[$transform(Direction::fromName(substr($shape, 10)))];
		}
		$parts = [];
		foreach(explode("_", $shape, 2) as $part){
			$parts[] = $transform(Direction::fromName($part));
		}
		sort($parts);
		foreach(self::RAIL_SHAPES as $candidate){
			if(str_starts_with($candidate, "ascending_")){
				continue;
			}
			$candidateParts = [];
			foreach(explode("_", $candidate, 2) as $part){
				$candidateParts[] = Direction::fromName($part);
			}
			sort($candidateParts);
			if($candidateParts === $parts && count($parts) === 2){
				return $candidate;
			}
		}
		return implode("_", [$shape]);
	}
}
