<?php

declare(strict_types=1);

namespace pocketmine\command;

use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\types\command\CommandOverload;
use pocketmine\network\mcpe\protocol\types\command\CommandParameter;
use pocketmine\Server;

/** RyzeMP's native command metadata and per-session protocol boundary. */
final class CommandMetadata{
	public const API_VERSION = 1;
	public const COMMANDO_VERSION = "3.3.0";

	/** @return list<CommandOverload> */
	public static function forProtocol(Command $command, CommandSender $sender, int $protocol) : array{
		$overloads = $command instanceof CommandOverloadProvider ? $command->getNetworkOverloads($sender) : [
			new CommandOverload(false, [CommandParameter::standard("args", AvailableCommandsPacket::ARG_TYPE_RAWTEXT, 0, true)])
		];
		$result = [];
		foreach($overloads as $overload){
			$parameters = [];
			foreach($overload->getParameters() as $source){
				$parameter = clone $source;
				if($parameter->enum === null && $parameter->postfix === null){
					$parameter->paramType = AvailableCommandsPacket::convertArg($protocol, $source->paramType);
				}
				$parameters[] = $parameter;
			}
			$result[] = new CommandOverload($overload->isChaining(), $parameters);
		}
		return $result;
	}

	/** Refresh after dynamic names or visibility change; use only on the server thread. */
	public static function refresh(Server $server) : void{
		foreach($server->getOnlinePlayers() as $player){
			if($player->isConnected()){$player->getNetworkSession()->syncAvailableCommands();}
		}
	}
}
