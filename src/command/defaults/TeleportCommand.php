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

namespace pocketmine\command\defaults;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\parameter\CommandParameter;
use pocketmine\command\parameter\ExhaustiveOverloads;
use pocketmine\command\selector\CommandTargets;
use pocketmine\command\selector\TargetSelector;
use pocketmine\command\utils\CommandCoordinates;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\entity\Location;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\math\Vector3;
use pocketmine\permission\DefaultPermissionNames;
use function array_merge;
use function array_pop;
use function array_shift;
use function array_slice;
use function atan2;
use function count;
use function max;
use function min;
use function rad2deg;
use function round;
use function sqrt;
use function strtolower;

/**
 * Bedrock syntax, each optionally preceded by <victim: target> (default: the sender):
 *  - /tp <destination: target> [checkForBlocks]
 *  - /tp <destination: x y z> [yRot] [xRot] [checkForBlocks]
 *  - /tp <destination: x y z> facing <lookAtPosition: x y z> [checkForBlocks]
 *  - /tp <destination: x y z> facing <lookAtEntity: target> [checkForBlocks]
 * Victims may be any entities (e.g. /tp @e[type=cow] @s). Relative (~) and local (^) coordinates are relative to the
 * sender, or to each victim when the console runs the command; ~ rotations are relative to each victim's.
 * checkForBlocks is accepted but not checked.
 */
class TeleportCommand extends VanillaCommand implements ExhaustiveOverloads{

	public function __construct(){
		parent::__construct(
			"tp",
			KnownTranslationFactory::pocketmine_command_tp_description(),
			KnownTranslationFactory::commands_tp_usage(),
			["teleport"]
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_TELEPORT_SELF,
			DefaultPermissionNames::COMMAND_TELEPORT_OTHER
		]);
	}

	public function getOverloads() : array{
		$destinations = [
			[CommandParameter::target("destination"), CommandParameter::boolean("checkForBlocks", true)],
			[CommandParameter::position("destination"), CommandParameter::value("yRot", true), CommandParameter::value("xRot", true), CommandParameter::boolean("checkForBlocks", true)],
			[CommandParameter::position("destination"), self::facing(), CommandParameter::position("lookAtPosition"), CommandParameter::boolean("checkForBlocks", true)],
			[CommandParameter::position("destination"), self::facing(), CommandParameter::target("lookAtEntity"), CommandParameter::boolean("checkForBlocks", true)],
		];
		$overloads = $destinations;
		foreach($destinations as $destination){
			$overloads[] = array_merge([CommandParameter::target("victim")], $destination);
		}
		return $overloads;
	}

	private static function facing() : CommandParameter{
		return CommandParameter::enum("facing", "TeleportFacing", ["facing"]);
	}

	private static function isBoolean(string $arg) : bool{
		$arg = strtolower($arg);
		return $arg === "true" || $arg === "false";
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) === 0 || count($args) > 9){
			throw new InvalidCommandSyntaxException();
		}

		$victimArg = null;
		$destinationArgs = [];
		$lookAtArgs = null;
		$rotationArgs = [];
		if(!CommandCoordinates::isCoordinate($args[0]) && count($args) >= 2 && !(count($args) === 2 && self::isBoolean($args[1]))){
			$victimArg = array_shift($args);
		}
		if(count($args) > 0 && self::isBoolean($args[count($args) - 1])){
			array_pop($args); //checkForBlocks
		}

		if(count($args) === 1){
			$destinationArg = $args[0];
		}elseif(count($args) >= 3){
			$destinationArg = null;
			$destinationArgs = array_slice($args, 0, 3);
			$rest = array_slice($args, 3);
			if(count($rest) > 0 && strtolower($rest[0]) === "facing"){
				$lookAtArgs = array_slice($rest, 1);
				if(count($lookAtArgs) !== 1 && count($lookAtArgs) !== 3){
					throw new InvalidCommandSyntaxException();
				}
			}elseif(count($rest) <= 2){
				$rotationArgs = $rest;
			}else{
				throw new InvalidCommandSyntaxException();
			}
		}else{
			throw new InvalidCommandSyntaxException();
		}

		$victims = $this->fetchPermittedEntityTargets($sender, $victimArg, DefaultPermissionNames::COMMAND_TELEPORT_SELF, DefaultPermissionNames::COMMAND_TELEPORT_OTHER);
		if(count($victims) === 0){
			return true;
		}

		if($destinationArg !== null){
			$destination = CommandTargets::entity($sender, $destinationArg);
			foreach($victims as $victim){
				$victim->teleport($destination->getLocation());
			}
			Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_tp_success(TargetSelector::joinNames($victims), TargetSelector::getEntityName($destination)));
			return true;
		}

		$lookAtEntity = $lookAtArgs !== null && count($lookAtArgs) === 1 ? CommandTargets::entity($sender, $lookAtArgs[0]) : null;
		$locations = [];
		$senderOrigin = CommandCoordinates::senderOrigin($sender);
		foreach($victims as $victim){
			//relative to the sender (a command block's centre too), or to each victim when the console runs it
			$base = $senderOrigin ?? $victim->getLocation();
			$position = CommandCoordinates::parsePosition($base, $destinationArgs[0], $destinationArgs[1], $destinationArgs[2]);
			$current = $victim->getLocation();
			if($lookAtArgs !== null){
				$lookAt = $lookAtEntity !== null ?
					$lookAtEntity->getEyePos() :
					CommandCoordinates::parsePosition($base, $lookAtArgs[0], $lookAtArgs[1], $lookAtArgs[2]);
				[$yaw, $pitch] = self::rotationTowards($position->add(0, $victim->getEyeHeight(), 0), $lookAt);
			}else{
				$yaw = isset($rotationArgs[0]) ? CommandCoordinates::parseRotation($current->yaw, $rotationArgs[0]) : $current->yaw;
				$pitch = isset($rotationArgs[1]) ? max(-90.0, min(90.0, CommandCoordinates::parseRotation($current->pitch, $rotationArgs[1]))) : $current->pitch;
			}
			$locations[] = Location::fromObject($position, $base->getWorld(), $yaw, $pitch);
		}
		foreach($victims as $i => $victim){
			$victim->teleport($locations[$i]);
		}

		$targetLocation = $locations[0];
		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_tp_success_coordinates(
			TargetSelector::joinNames($victims),
			(string) round($targetLocation->x, 2),
			(string) round($targetLocation->y, 2),
			(string) round($targetLocation->z, 2)
		));
		return true;
	}

	/**
	 * Yaw and pitch to look from one point at another (like Living::lookAt()).
	 *
	 * @return float[]
	 * @phpstan-return array{float, float}
	 */
	private static function rotationTowards(Vector3 $from, Vector3 $to) : array{
		$xDist = $to->x - $from->x;
		$zDist = $to->z - $from->z;
		$horizontal = sqrt($xDist ** 2 + $zDist ** 2);
		$pitch = -rad2deg(atan2($to->y - $from->y, $horizontal));
		$yaw = rad2deg(atan2($zDist, $xDist)) - 90;
		if($yaw < 0){
			$yaw += 360.0;
		}
		return [$yaw, $pitch];
	}
}
