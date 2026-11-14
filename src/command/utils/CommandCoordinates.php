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

use pocketmine\command\BlockCommandSender;
use pocketmine\command\CommandSender;
use pocketmine\command\EntityCommandSender;
use pocketmine\command\ExecuteCommandSender;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\world\World;
use function cos;
use function deg2rad;
use function is_finite;
use function max;
use function min;
use function preg_match;
use function sin;
use function substr;

/**
 * Parses command coordinates like Bedrock: absolute numbers, relative ones (~, ~5, ~-2.5) and local ones (^left ^up
 * ^forward, relative to the origin's rotation; all three coordinates must be local then).
 */
final class CommandCoordinates{
	public const MAX_COORD = 30000000;
	public const MIN_COORD = -30000000;

	private const NUMBER = '(?:-?(?:\d+(?:\.\d*)?|\.\d+))';

	private function __construct(){
		//NOOP
	}

	/**
	 * Origin of the sender's relative and local coordinates: the position and rotation an /execute runs with (see
	 * {@link ExecuteCommandSender}), the entity running the command (a player, or the entity of an
	 * {@link EntityCommandSender}), or the centre of a command block; null for the console and other senders, for which
	 * each command picks its own origin.
	 */
	public static function senderOrigin(CommandSender $sender) : ?Location{
		if($sender instanceof ExecuteCommandSender){
			return $sender->getExecutingLocation();
		}
		if($sender instanceof Entity){
			return $sender->getLocation();
		}
		if($sender instanceof EntityCommandSender){
			return $sender->getExecutingEntity()->getLocation();
		}
		if($sender instanceof BlockCommandSender){
			$position = $sender->getExecutingPosition();
			return Location::fromObject($position, $position->getWorld());
		}
		return null;
	}

	/** Whether the argument is a coordinate: a number, or ~ / ^ optionally followed by a number. */
	public static function isCoordinate(string $arg) : bool{
		return preg_match('/^(?:[~^]' . self::NUMBER . '?|' . self::NUMBER . ')$/', $arg) === 1;
	}

	/**
	 * Resolves x y z arguments. Relative coordinates are added to the origin's position; local coordinates are
	 * offsets left, up and forward from it, following its yaw and pitch. X and Z are limited to +-30000000, Y to the
	 * world height.
	 *
	 * @throws InvalidCommandSyntaxException if an argument is not a coordinate, or local and other coordinates are mixed
	 */
	public static function parsePosition(Location $origin, string $x, string $y, string $z) : Vector3{
		foreach([$x, $y, $z] as $arg){
			if(!self::isCoordinate($arg)){
				throw new InvalidCommandSyntaxException();
			}
		}
		$local = (int) ($x[0] === "^") + (int) ($y[0] === "^") + (int) ($z[0] === "^");
		if($local === 3){
			$position = self::localToWorld($origin, self::offset($x), self::offset($y), self::offset($z));
		}elseif($local === 0){
			$position = new Vector3(
				self::resolve($origin->x, $x),
				self::resolve($origin->y, $y),
				self::resolve($origin->z, $z)
			);
		}else{
			throw new InvalidCommandSyntaxException();
		}
		if(!is_finite($position->x) || !is_finite($position->y) || !is_finite($position->z)){
			//a few hundred digits are INF as a float, and 0 * INF (local coordinates) is NAN
			throw new InvalidCommandSyntaxException();
		}
		return new Vector3(
			self::clamp($position->x, self::MIN_COORD, self::MAX_COORD),
			self::clamp($position->y, World::Y_MIN, World::Y_MAX),
			self::clamp($position->z, self::MIN_COORD, self::MAX_COORD)
		);
	}

	/**
	 * Resolves a rotation argument (yRot or xRot): a number, or ~ / ~n relative to the current value.
	 *
	 * @throws InvalidCommandSyntaxException
	 */
	public static function parseRotation(float $current, string $arg) : float{
		if(!self::isCoordinate($arg) || $arg[0] === "^"){
			throw new InvalidCommandSyntaxException();
		}
		$rotation = self::resolve($current, $arg);
		if(!is_finite($rotation)){
			throw new InvalidCommandSyntaxException();
		}
		return $rotation;
	}

	private static function resolve(float $origin, string $arg) : float{
		return $arg[0] === "~" ? $origin + self::offset($arg) : (float) $arg;
	}

	/** Number after the ~ or ^ (0 if there is none). */
	private static function offset(string $arg) : float{
		return (float) substr($arg, 1);
	}

	/**
	 * Same as vanilla's local coordinates: left, up and forward axes from the yaw and pitch.
	 */
	private static function localToWorld(Location $origin, float $left, float $up, float $forward) : Vector3{
		$yaw = deg2rad($origin->yaw);
		$pitch = deg2rad($origin->pitch);
		$forwardAxis = new Vector3(-sin($yaw) * cos($pitch), -sin($pitch), cos($yaw) * cos($pitch));
		$upAxis = new Vector3(-sin($yaw) * sin($pitch), cos($pitch), cos($yaw) * sin($pitch));
		$leftAxis = $forwardAxis->cross($upAxis)->multiply(-1);
		return $origin->asVector3()
			->addVector($leftAxis->multiply($left))
			->addVector($upAxis->multiply($up))
			->addVector($forwardAxis->multiply($forward));
	}

	private static function clamp(float $value, float $min, float $max) : float{
		return max($min, min($max, $value));
	}
}
