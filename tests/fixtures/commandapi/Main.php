<?php
declare(strict_types=1);

namespace RyzeTests\CommandApi;

use CortexPE\Commando\BaseCommand;
use CortexPE\Commando\args\IntegerArgument;
use pocketmine\command\CommandMetadata;
use pocketmine\command\CommandSender;
use pocketmine\command\defaults\NativeCommand;
use pocketmine\console\ConsoleCommandSender;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;

final class Main extends PluginBase{
	protected function onEnable() : void{
		$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() : void{
			try{
				$server = $this->getServer();
				$sender = new ConsoleCommandSender($server, $server->getLanguage());
				$map = $server->getCommandMap();
				$native = 0;
				foreach($map->getCommands() as $label => $command){
					if($label !== "pocketmine:" . $command->getName()){continue;}
					if(!$command instanceof NativeCommand || $command->getSignatures() === []){throw new \RuntimeException("Missing native route " . $label);}
					foreach([589, 2193, 2225] as $protocol){
						if(CommandMetadata::forProtocol($command, $sender, $protocol) === []){throw new \RuntimeException("Missing metadata " . $label . " protocol=" . $protocol);}
					}
					++$native;
				}
				if($native !== 42 || CommandMetadata::API_VERSION !== 1){throw new \RuntimeException("Wrong native command API");}
				$command = new class($this, "ryzeci", "Public API fixture", ["ryzealias"]) extends BaseCommand{
					public array $calls = [];
					protected function prepare() : void{
						$this->setPermission("ryzemp.smoke.command");
						$this->registerArgument(0, new IntegerArgument("amount"));
					}
					public function onRun(CommandSender $sender, string $aliasUsed, array $args) : void{$this->calls[] = $args;}
				};
				$map->register("publicsmoke", $command);
				if($map->getCommand("ryzealias") !== $command || $command->getOwningPlugin() !== $this){throw new \RuntimeException("Plugin route/alias failed");}
				if(!$server->dispatchCommand($sender, "ryzealias 7")){throw new \RuntimeException("Plugin dispatch failed");}
				$server->dispatchCommand($sender, "ryzeci invalid");
				if($command->calls !== [["amount" => 7]]){throw new \RuntimeException("Typed command parsing failed");}
				$file = (new \ReflectionClass(BaseCommand::class))->getFileName();
				$root = dirname((new \ReflectionClass(\pocketmine\Server::class))->getFileName(), 2);
				if(!str_starts_with($file, $root . "/lib/commando/")){throw new \RuntimeException("Commando is not embedded in server");}
				foreach(["pocketmine:help give", "pocketmine:time query", "pocketmine:version"] as $input){
					if(!$server->dispatchCommand($sender, $input)){throw new \RuntimeException("Native dispatch failed: " . $input);}
				}
				$this->getLogger()->info("COMMAND_API_PUBLIC_OK native=42 protocols=3 plugin-alias-typed-dispatch=ok");
			}catch(\Throwable $error){
				$this->getLogger()->critical("COMMAND_API_PUBLIC_FAILED " . $error->getMessage());
			}finally{
				$this->getServer()->shutdown();
			}
		}), 2);
	}
}
