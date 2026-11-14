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
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\GameMode;
use function count;
use function strtolower;

class GamemodeCommand extends VanillaCommand implements ExhaustiveOverloads{

	public function __construct(){
		parent::__construct(
			"gamemode",
			KnownTranslationFactory::pocketmine_command_gamemode_description(),
			KnownTranslationFactory::commands_gamemode_usage()
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_GAMEMODE_SELF,
			DefaultPermissionNames::COMMAND_GAMEMODE_OTHER
		]);
	}

	public function getOverloads() : array{
		return [
			[CommandParameter::gameMode("gameMode"), CommandParameter::target("player", true)],
			[CommandParameter::int("gameMode"), CommandParameter::target("player", true)],
		];
	}

	/**
	 * @internal
	 * Parses a game mode argument: PocketMine's names and numbers, plus Bedrock's numbers (5 = default, 6 = spectator)
	 * and "default"/"d" (the server's default game mode) if $default is given.
	 */
	public static function parseGameMode(string $arg, ?GameMode $default) : ?GameMode{
		return match(strtolower($arg)){
			"default", "d", "5" => $default,
			"6" => GameMode::SPECTATOR,
			default => GameMode::fromString($arg)
		};
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) === 0 || count($args) > 2){
			throw new InvalidCommandSyntaxException();
		}

		$gameMode = self::parseGameMode($args[0], $sender->getServer()->getGamemode());
		if($gameMode === null){
			$sender->sendMessage(KnownTranslationFactory::pocketmine_command_gamemode_unknown($args[0]));
			return true;
		}

		$targets = $this->fetchPermittedPlayerTargets($sender, $args[1] ?? null, DefaultPermissionNames::COMMAND_GAMEMODE_SELF, DefaultPermissionNames::COMMAND_GAMEMODE_OTHER);
		foreach($targets as $target){
			if($target->getGamemode() === $gameMode){
				$sender->sendMessage(KnownTranslationFactory::pocketmine_command_gamemode_failure($target->getName()));
				continue;
			}

			$target->setGamemode($gameMode);
			if($gameMode !== $target->getGamemode()){
				$sender->sendMessage(KnownTranslationFactory::pocketmine_command_gamemode_failure($target->getName()));
			}else{
				if($target === $sender){
					Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_gamemode_success_self($gameMode->getTranslatableName()));
				}else{
					$target->sendMessage(KnownTranslationFactory::gameMode_changed($gameMode->getTranslatableName()));
					Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_gamemode_success_other($gameMode->getTranslatableName(), $target->getName()));
				}
			}
		}

		return true;
	}
}
