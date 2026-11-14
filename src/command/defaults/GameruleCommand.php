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
use pocketmine\utils\TextFormat;
use pocketmine\utils\Utils;
use pocketmine\world\GameRules;
use function array_filter;
use function array_values;
use function count;
use function implode;
use function is_bool;
use function preg_match;
use function strtolower;

/**
 * /gamerule lists the game rules of the sender's world, /gamerule <rule> shows one and /gamerule <rule> <value>
 * changes it (Bedrock syntax). An optional last argument names another loaded world (the console uses the default
 * world).
 */
class GameruleCommand extends VanillaCommand implements ExhaustiveOverloads{

	public function __construct(){
		parent::__construct(
			"gamerule",
			KnownTranslationFactory::pocketmine_command_gamerule_description(),
			KnownTranslationFactory::pocketmine_command_gamerule_usage()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_GAMERULE);
	}

	public function getOverloads() : array{
		$boolRules = array_values(array_filter(GameRules::getNames(), GameRules::isBool(...)));
		$intRules = array_values(array_filter(GameRules::getNames(), fn(string $rule) => !GameRules::isBool($rule)));
		return [
			[],
			[CommandParameter::enum("rule", "BoolGameRule", $boolRules), CommandParameter::boolean("value", true), CommandParameter::string("world", true)],
			[CommandParameter::enum("rule", "IntGameRule", $intRules), CommandParameter::int("value", true), CommandParameter::string("world", true)],
		];
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) > 3){
			throw new InvalidCommandSyntaxException();
		}

		$worldManager = $sender->getServer()->getWorldManager();
		if(isset($args[2])){
			$world = $worldManager->getWorldByName($args[2]);
			if($world === null){
				$sender->sendMessage(KnownTranslationFactory::pocketmine_command_gamerule_unknownWorld($args[2])->prefix(TextFormat::RED));
				return true;
			}
		}else{
			$world = $sender instanceof Player ? $sender->getWorld() : $worldManager->getDefaultWorld();
			if($world === null){
				return true;
			}
		}

		if(count($args) === 0){
			$rules = [];
			foreach(Utils::stringifyKeys($world->getGameRules()) as $rule => $value){
				$rules[] = $rule . "=" . self::format($value);
			}
			$sender->sendMessage(KnownTranslationFactory::pocketmine_command_gamerule_list($world->getFolderName(), implode(", ", $rules)));
			return true;
		}

		$rule = GameRules::normalizeName($args[0]);
		if($rule === null){
			$sender->sendMessage(KnownTranslationFactory::pocketmine_command_gamerule_unknownRule($args[0])->prefix(TextFormat::RED));
			return true;
		}

		if(!isset($args[1])){
			$sender->sendMessage(KnownTranslationFactory::pocketmine_command_gamerule_query($rule, $world->getFolderName(), self::format($world->getGameRule($rule))));
			return true;
		}

		$value = self::parse($rule, $args[1]);
		if($value === null){
			$sender->sendMessage(KnownTranslationFactory::pocketmine_command_gamerule_invalidValue($args[1], $rule, GameRules::isBool($rule) ? "true/false" : "a whole number of 0 or more")->prefix(TextFormat::RED));
			return true;
		}
		if($world->setGameRule($rule, $value)){
			Command::broadcastCommandMessage($sender, KnownTranslationFactory::pocketmine_command_gamerule_success($rule, $world->getFolderName(), self::format($world->getGameRule($rule))));
		}
		return true;
	}

	private static function parse(string $rule, string $input) : bool|int|null{
		if(GameRules::isBool($rule)){
			return match(strtolower($input)){
				"true", "1", "on" => true,
				"false", "0", "off" => false,
				default => null
			};
		}
		return preg_match('/^\d{1,9}$/', $input) === 1 ? (int) $input : null;
	}

	private static function format(bool|int $value) : string{
		return is_bool($value) ? ($value ? "true" : "false") : (string) $value;
	}
}
