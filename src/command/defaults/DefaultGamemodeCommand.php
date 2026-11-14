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

use pocketmine\command\CommandSender;
use pocketmine\command\parameter\CommandEnums;
use pocketmine\command\parameter\CommandParameter;
use pocketmine\command\parameter\ExhaustiveOverloads;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\ServerProperties;
use function count;

class DefaultGamemodeCommand extends VanillaCommand implements ExhaustiveOverloads{

	public function __construct(){
		parent::__construct(
			"defaultgamemode",
			KnownTranslationFactory::pocketmine_command_defaultgamemode_description(),
			KnownTranslationFactory::commands_defaultgamemode_usage()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_DEFAULTGAMEMODE);
	}

	public function getOverloads() : array{
		return [
			[CommandParameter::enum("gameMode", "DefaultGameMode", CommandEnums::gameModes(withDefault: false))],
			[CommandParameter::int("gameMode")],
		];
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) !== 1){
			throw new InvalidCommandSyntaxException();
		}

		$gameMode = GamemodeCommand::parseGameMode($args[0], null);
		if($gameMode === null){
			$sender->sendMessage(KnownTranslationFactory::pocketmine_command_gamemode_unknown($args[0]));
			return true;
		}

		//TODO: this probably shouldn't use the enum name directly
		$sender->getServer()->getConfigGroup()->setConfigString(ServerProperties::GAME_MODE, $gameMode->name);
		$sender->sendMessage(KnownTranslationFactory::commands_defaultgamemode_success($gameMode->getTranslatableName()));
		return true;
	}
}
