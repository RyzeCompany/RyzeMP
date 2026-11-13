<?php

/*
 * Adapted from VanillaMine's /weather command for RyzeMP's native command pipeline.
 * Copyright (c) PocketMine Team and VanillaMine contributors.
 * SPDX-License-Identifier: LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use function count;
use function strtolower;

/** Weather is scoped to the sender's world, or the default world for the console. */
final class WeatherCommand extends NativeCommand{
	public const MAX_DURATION = 20_000_000;

	public function __construct(){
		parent::__construct("weather", "Controlar el clima del mundo", "/weather <clear|rain|thunder|query> [ticks]");
		$this->setPermission(DefaultPermissionNames::COMMAND_WEATHER);
	}

	protected function executeParsed(CommandSender $sender, string $commandLabel, array $args) : bool{
		if(count($args) < 1 || count($args) > 2){
			throw new InvalidCommandSyntaxException();
		}
		$world = $sender instanceof Player ?
			$sender->getWorld() :
			$sender->getServer()->getWorldManager()->getDefaultWorld();
		if($world === null){
			$sender->sendMessage("No hay un mundo cargado.");
			return true;
		}
		$weather = $world->getWeather();
		$mode = strtolower($args[0]);
		if($mode === "query"){
			if(count($args) !== 1){
				throw new InvalidCommandSyntaxException();
			}
			$sender->sendMessage("Clima de " . $world->getFolderName() . ": " .
				($weather->isThundering() ? "thunder" : ($weather->isRaining() ? "rain" : "clear")));
			return true;
		}
		if(!in_array($mode, ["clear", "rain", "thunder"], true)){
			throw new InvalidCommandSyntaxException();
		}
		if(!$weather->hasWeather()){
			$sender->sendMessage("Este mundo no tiene clima (Nether o End).");
			return true;
		}
		$duration = isset($args[1]) ? $this->getBoundedInt($sender, $args[1], 1, self::MAX_DURATION) : null;
		if(isset($args[1]) && $duration === null){
			return true;
		}
		$changed = match($mode){
			"clear" => $weather->setClear($duration),
			"rain" => $weather->setRain($duration),
			"thunder" => $weather->setThunder($duration)
		};
		if($changed){
			Command::broadcastCommandMessage($sender, "Clima establecido en $mode para " . $world->getFolderName());
		}
		return true;
	}
}
