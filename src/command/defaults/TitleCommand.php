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
use pocketmine\command\parameter\CommandParameter;
use pocketmine\command\parameter\ExhaustiveOverloads;
use pocketmine\command\selector\CommandTargets;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissionNames;
use function array_slice;
use function count;
use function implode;

class TitleCommand extends VanillaCommand implements ExhaustiveOverloads{

	public function __construct(){
		parent::__construct(
			"title",
			KnownTranslationFactory::pocketmine_command_title_description(),
			KnownTranslationFactory::commands_title_usage()
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_TITLE_SELF,
			DefaultPermissionNames::COMMAND_TITLE_OTHER
		]);
	}

	public function getOverloads() : array{
		return [
			[CommandParameter::target("player"), CommandParameter::enum("clear", "TitleClear", ["clear"])],
			[CommandParameter::target("player"), CommandParameter::enum("reset", "TitleReset", ["reset"])],
			[CommandParameter::target("player"), CommandParameter::enum("titleLocation", "TitleSet", ["title", "subtitle", "actionbar"]), CommandParameter::message("titleText")],
			[CommandParameter::target("player"), CommandParameter::enum("times", "TitleTimes", ["times"]), CommandParameter::int("fadeIn"), CommandParameter::int("stay"), CommandParameter::int("fadeOut")],
		];
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 2){
			throw new InvalidCommandSyntaxException();
		}

		$players = $this->fetchPermittedPlayerTargets($sender, $args[0], DefaultPermissionNames::COMMAND_TITLE_SELF, DefaultPermissionNames::COMMAND_TITLE_OTHER);
		if(count($players) === 0){
			return true;
		}

		switch($args[1]){
			case "clear":
			case "reset":
				break;
			case "title":
			case "subtitle":
			case "actionbar":
				if(count($args) < 3){
					throw new InvalidCommandSyntaxException();
				}
				break;
			case "times":
				if(count($args) < 5){
					throw new InvalidCommandSyntaxException();
				}
				break;
			default:
				throw new InvalidCommandSyntaxException();
		}

		$text = count($args) >= 3 ? CommandTargets::expandSelectors($sender, implode(" ", array_slice($args, 2))) : "";
		foreach($players as $player){
			switch($args[1]){
				case "clear":
					$player->removeTitles();
					break;
				case "reset":
					$player->resetTitles();
					break;
				case "title":
					$player->sendTitle($text);
					break;
				case "subtitle":
					$player->sendSubTitle($text);
					break;
				case "actionbar":
					$player->sendActionBarMessage($text);
					break;
				default: //times
					$player->setTitleDuration($this->getInteger($sender, $args[2]), $this->getInteger($sender, $args[3]), $this->getInteger($sender, $args[4]));
			}
		}

		$sender->sendMessage(KnownTranslationFactory::commands_title_success());

		return true;
	}
}
