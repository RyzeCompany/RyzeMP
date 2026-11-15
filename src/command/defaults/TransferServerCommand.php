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
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use function count;
use function filter_var;
use const FILTER_VALIDATE_INT;

class TransferServerCommand extends VanillaCommand implements ExhaustiveOverloads{

	public function __construct(){
		parent::__construct(
			"transferserver",
			KnownTranslationFactory::pocketmine_command_transferserver_description(),
			KnownTranslationFactory::pocketmine_command_transferserver_usage()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_TRANSFERSERVER);
	}

	public function getOverloads() : array{
		return [[CommandParameter::string("server"), CommandParameter::int("port", true)]];
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 1){
			throw new InvalidCommandSyntaxException();
		}elseif(!($sender instanceof Player)){
			$sender->sendMessage(KnownTranslationFactory::pocketmine_command_error_playerUserOnly()->prefix(TextFormat::RED));

			return false;
		}

		//a port out of range would be truncated to 16 bits on the wire and send the player somewhere else
		$port = filter_var($args[1] ?? "19132", FILTER_VALIDATE_INT, ["options" => ["min_range" => 0, "max_range" => 65535]]);
		if($port === false){
			throw new InvalidCommandSyntaxException();
		}

		$sender->transfer($args[0], $port);

		return true;
	}
}
