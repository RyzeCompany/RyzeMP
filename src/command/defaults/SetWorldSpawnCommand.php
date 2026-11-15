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
use pocketmine\entity\Location;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\utils\TextFormat;
use function count;

class SetWorldSpawnCommand extends VanillaCommand implements ExhaustiveOverloads{

	public function __construct(){
		parent::__construct(
			"setworldspawn",
			KnownTranslationFactory::pocketmine_command_setworldspawn_description(),
			KnownTranslationFactory::commands_setworldspawn_usage()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_SETWORLDSPAWN);
	}

	public function getOverloads() : array{
		return [[CommandParameter::position("spawnPoint", true)]];
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		$senderOrigin = CommandCoordinates::senderOrigin($sender);
		if(count($args) === 0){
			if($senderOrigin !== null){
				$world = $senderOrigin->getWorld();
				$pos = $senderOrigin->asVector3()->floor();
			}else{
				$sender->sendMessage(KnownTranslationFactory::pocketmine_command_error_playerUserOnly()->prefix(TextFormat::RED));

				return true;
			}
		}elseif(count($args) === 3){
			if($senderOrigin !== null){
				$origin = $senderOrigin;
				$world = $origin->getWorld();
			}else{
				$world = $sender->getServer()->getWorldManager()->getDefaultWorld();
				$origin = new Location(0.0, 0.0, 0.0, $world, 0.0, 0.0);
			}
			$pos = CommandCoordinates::parsePosition($origin, $args[0], $args[1], $args[2])->floor();
		}else{
			throw new InvalidCommandSyntaxException();
		}

		$world->setSpawnLocation($pos);

		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_setworldspawn_success((string) $pos->x, (string) $pos->y, (string) $pos->z));

		return true;
	}
}
