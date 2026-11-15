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
use function count;
use function strtolower;

/**
 * /weather <clear|rain|thunder> [duration] and /weather query (Bedrock syntax; the duration is in ticks, random
 * 6000-18000 if omitted). Changes the weather of the sender's world (the default world from the console).
 */
class WeatherCommand extends VanillaCommand implements ExhaustiveOverloads{
	/** Longest accepted duration (Bedrock caps it at 1000000 seconds). */
	public const MAX_DURATION = 20_000_000;

	public function __construct(){
		parent::__construct(
			"weather",
			KnownTranslationFactory::pocketmine_command_weather_description(),
			KnownTranslationFactory::pocketmine_command_weather_usage()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_WEATHER);
	}

	public function getOverloads() : array{
		return [
			[CommandParameter::enum("type", "WeatherType", ["clear", "rain", "thunder"]), CommandParameter::int("duration", true)],
			[CommandParameter::enum("query", "WeatherQuery", ["query"])],
		];
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 1 || count($args) > 2){
			throw new InvalidCommandSyntaxException();
		}

		$world = $sender instanceof Player ? $sender->getWorld() : $sender->getServer()->getWorldManager()->getDefaultWorld();
		if($world === null){
			return true;
		}
		$weather = $world->getWeather();
		$mode = strtolower($args[0]);

		if($mode === "query"){
			$state = match(true){
				$weather->isThundering() => KnownTranslationFactory::commands_weather_query_thunder(),
				$weather->isRaining() => KnownTranslationFactory::commands_weather_query_rain(),
				default => KnownTranslationFactory::commands_weather_query_clear()
			};
			$sender->sendMessage(KnownTranslationFactory::commands_weather_query($state));
			return true;
		}

		$duration = isset($args[1]) ? $this->getBoundedInt($sender, $args[1], 1, self::MAX_DURATION) : null;
		if(isset($args[1]) && $duration === null){
			return true;
		}

		if(!$weather->hasWeather()){
			$sender->sendMessage(KnownTranslationFactory::pocketmine_command_weather_noWeather()->prefix(TextFormat::RED));
			return true;
		}

		[$changed, $message] = match($mode){
			"clear" => [$weather->setClear($duration), KnownTranslationFactory::commands_weather_clear()],
			"rain" => [$weather->setRain($duration), KnownTranslationFactory::commands_weather_rain()],
			"thunder" => [$weather->setThunder($duration), KnownTranslationFactory::commands_weather_thunder()],
			default => throw new InvalidCommandSyntaxException()
		};
		if($changed){
			Command::broadcastCommandMessage($sender, $message);
		}
		return true;
	}
}
