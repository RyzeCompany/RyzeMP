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
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use function array_shift;
use function count;

class OpCommand extends VanillaCommand implements ExhaustiveOverloads{

	public function __construct(){
		parent::__construct(
			"op",
			KnownTranslationFactory::pocketmine_command_op_description(),
			KnownTranslationFactory::commands_op_usage()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_OP_GIVE);
	}

	public function getOverloads() : array{
		return [[CommandParameter::target("player")]];
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) === 0){
			throw new InvalidCommandSyntaxException();
		}

		$name = array_shift($args);
		if(TargetSelector::isSelectorArgument($name)){
			$names = [];
			foreach(CommandTargets::players($sender, $name) as $player){
				$names[] = $player->getName();
			}
		}elseif(Player::isValidUserName($name)){
			$names = [$name];
		}else{
			throw new InvalidCommandSyntaxException();
		}

		foreach($names as $opName){
			$sender->getServer()->addOp($opName);
			if(($player = $sender->getServer()->getPlayerExact($opName)) !== null){
				$player->sendMessage(KnownTranslationFactory::commands_op_message()->prefix(TextFormat::GRAY));
			}
			Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_op_success($opName));
		}
		return true;
	}
}
