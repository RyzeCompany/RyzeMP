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
use pocketmine\command\utils\CommandCoordinates;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\world\Position;
use function count;
use function floor;

class SpawnpointCommand extends VanillaCommand implements ExhaustiveOverloads{

	public function __construct(){
		parent::__construct(
			"spawnpoint",
			KnownTranslationFactory::pocketmine_command_spawnpoint_description(),
			KnownTranslationFactory::commands_spawnpoint_usage()
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_SPAWNPOINT_SELF,
			DefaultPermissionNames::COMMAND_SPAWNPOINT_OTHER
		]);
	}

	public function getOverloads() : array{
		return [[CommandParameter::target("player", true), CommandParameter::position("spawnPos", true)]];
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) !== 0 && count($args) !== 1 && count($args) !== 4){
			throw new InvalidCommandSyntaxException();
		}
		$targets = $this->fetchPermittedPlayerTargets($sender, $args[0] ?? null, DefaultPermissionNames::COMMAND_SPAWNPOINT_SELF, DefaultPermissionNames::COMMAND_SPAWNPOINT_OTHER);

		$senderOrigin = CommandCoordinates::senderOrigin($sender);
		foreach($targets as $target){
			if(count($args) === 4){
				$world = $target->getWorld();
				//relative to the sender (a command block's centre too), or to each target when the console runs it
				$origin = $senderOrigin ?? $target->getLocation();
				$pos = CommandCoordinates::parsePosition($origin, $args[1], $args[2], $args[3]);
				$target->setSpawn(Position::fromObject($pos, $world));

				Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_spawnpoint_success_single($target->getName(), (string) (int) floor($pos->x), (string) (int) floor($pos->y), (string) (int) floor($pos->z)));
			}else{
				//the sender's position, or each target's own position when the console runs it
				$cpos = $senderOrigin ?? $target->getPosition();
				$pos = Position::fromObject($cpos->floor(), $cpos->getWorld());
				$target->setSpawn($pos);

				Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_spawnpoint_success_single($target->getName(), (string) $pos->getFloorX(), (string) $pos->getFloorY(), (string) $pos->getFloorZ()));
			}
		}
		return true;
	}
}
