<?php

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use pocketmine\world\GameRules;
use function array_key_exists;
use function array_keys;
use function count;
use function implode;
use function strtolower;

final class GameRuleCommand extends VanillaCommand{
	public function __construct(){
		parent::__construct("gamerule", "Consultar o cambiar reglas del mundo", "/gamerule [regla] [true|false] [mundo]", ["gr", "gamerules"]);
		$this->setPermission(DefaultPermissionNames::COMMAND_GAMERULE);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args) : bool{
		if(!$this->testPermission($sender)){ return true; }
		if(count($args) > 3){ throw new InvalidCommandSyntaxException(); }
		$world = isset($args[2]) ? $sender->getServer()->getWorldManager()->getWorldByName($args[2]) :
			($sender instanceof Player ? $sender->getWorld() : $sender->getServer()->getWorldManager()->getDefaultWorld());
		if($world === null){
			$sender->sendMessage("El mundo indicado no esta cargado o no hay mundo predeterminado.");
			return true;
		}
		$rules = $world->getGameRules();
		if($args === []){
			$sender->sendMessage("Reglas disponibles: " . implode(", ", array_keys($rules->getAll())));
			return true;
		}
		$name = strtolower($args[0]);
		if(!array_key_exists($name, $rules->getAll())){
			$sender->sendMessage("Regla no implementada: $name. Disponible: " . GameRules::SHOW_COORDINATES);
			return true;
		}
		if(!isset($args[1])){
			$sender->sendMessage("$name = " . ($rules->getBool($name) ? "true" : "false") . " (" . $world->getFolderName() . ")");
			return true;
		}
		$value = match(strtolower($args[1])){ "true" => true, "false" => false, default => null };
		if($value === null){ throw new InvalidCommandSyntaxException(); }
		try{
			$world->setGameRule($name, $value);
		}catch(\RuntimeException $e){
			$sender->sendMessage("No se pudo guardar la regla: " . $e->getMessage());
			return true;
		}
		$sender->sendMessage("$name = " . ($value ? "true" : "false") . " (" . $world->getFolderName() . ")");
		return true;
	}
}
