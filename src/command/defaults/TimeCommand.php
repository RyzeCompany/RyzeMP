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
use pocketmine\player\Player;
use pocketmine\world\World;
use function array_keys;
use function count;
use function intdiv;
use function is_numeric;
use function max;
use function strtolower;

/**
 * Bedrock syntax: /time add <amount>, /time set <amount|day|night|noon|midnight|sunrise|sunset>,
 * /time query <daytime|gametime|day>. PocketMine also has /time start and /time stop, and /time query without a
 * value (the world time).
 */
class TimeCommand extends VanillaCommand implements ExhaustiveOverloads{
	private const TIME_SPECS = [
		"day" => World::TIME_DAY,
		"night" => World::TIME_NIGHT,
		"noon" => World::TIME_NOON,
		"midnight" => World::TIME_MIDNIGHT,
		"sunrise" => World::TIME_SUNRISE,
		"sunset" => World::TIME_SUNSET,
	];

	public function __construct(){
		parent::__construct(
			"time",
			KnownTranslationFactory::pocketmine_command_time_description(),
			KnownTranslationFactory::pocketmine_command_time_usage()
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_TIME_ADD,
			DefaultPermissionNames::COMMAND_TIME_SET,
			DefaultPermissionNames::COMMAND_TIME_START,
			DefaultPermissionNames::COMMAND_TIME_STOP,
			DefaultPermissionNames::COMMAND_TIME_QUERY
		]);
	}

	public function getOverloads() : array{
		return [
			[CommandParameter::enum("mode", "TimeModeAdd", ["add"]), CommandParameter::int("amount")],
			[CommandParameter::enum("mode", "TimeModeSet", ["set"]), CommandParameter::int("amount")],
			[CommandParameter::enum("mode", "TimeModeSet", ["set"]), CommandParameter::enum("time", "TimeSpec", array_keys(self::TIME_SPECS))],
			[CommandParameter::enum("mode", "TimeModeQuery", ["query"]), CommandParameter::enum("time", "TimeQuery", ["daytime", "gametime", "day"], true)],
			[CommandParameter::enum("mode", "TimeModeControl", ["start", "stop"])],
		];
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 1 || count($args) > 2){
			throw new InvalidCommandSyntaxException();
		}

		$mode = strtolower($args[0]);
		if($mode === "start"){
			if(!$this->testPermission($sender, DefaultPermissionNames::COMMAND_TIME_START)){
				return true;
			}
			foreach($sender->getServer()->getWorldManager()->getWorlds() as $world){
				$world->startTime();
			}
			Command::broadcastCommandMessage($sender, "Restarted the time");
			return true;
		}elseif($mode === "stop"){
			if(!$this->testPermission($sender, DefaultPermissionNames::COMMAND_TIME_STOP)){
				return true;
			}
			foreach($sender->getServer()->getWorldManager()->getWorlds() as $world){
				$world->stopTime();
			}
			Command::broadcastCommandMessage($sender, "Stopped the time");
			return true;
		}elseif($mode === "query"){
			if(!$this->testPermission($sender, DefaultPermissionNames::COMMAND_TIME_QUERY)){
				return true;
			}
			if($sender instanceof Player){
				$world = $sender->getWorld();
			}else{
				$world = $sender->getServer()->getWorldManager()->getDefaultWorld();
				if($world === null){
					return true;
				}
			}
			$value = match(isset($args[1]) ? strtolower($args[1]) : null){
				"daytime" => $world->getTimeOfDay(),
				"day" => intdiv($world->getTime(), World::TIME_FULL),
				"gametime", null => $world->getTime(),
				default => throw new InvalidCommandSyntaxException()
			};
			$sender->sendMessage($sender->getLanguage()->translate(KnownTranslationFactory::commands_time_query((string) $value)));
			return true;
		}

		if(count($args) < 2){
			throw new InvalidCommandSyntaxException();
		}

		if($mode === "set"){
			if(!$this->testPermission($sender, DefaultPermissionNames::COMMAND_TIME_SET)){
				return true;
			}
			$value = self::TIME_SPECS[strtolower($args[1])] ?? $this->getTimeArgument($args[1]);
			foreach($sender->getServer()->getWorldManager()->getWorlds() as $world){
				$world->setTime($value);
			}
			Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_time_set((string) $value));
		}elseif($mode === "add"){
			if(!$this->testPermission($sender, DefaultPermissionNames::COMMAND_TIME_ADD)){
				return true;
			}
			$value = $this->getTimeArgument($args[1]);
			foreach($sender->getServer()->getWorldManager()->getWorlds() as $world){
				$world->setTime($world->getTime() + $value);
			}
			Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_time_added((string) $value));
		}else{
			throw new InvalidCommandSyntaxException();
		}

		return true;
	}

	/**
	 * @throws InvalidCommandSyntaxException
	 */
	private function getTimeArgument(string $arg) : int{
		if(!is_numeric($arg)){
			throw new InvalidCommandSyntaxException();
		}
		return max(0, (int) $arg);
	}
}
