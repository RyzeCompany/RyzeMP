<?php

declare(strict_types=1);

namespace pocketmine\command;

use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\types\command\CommandData;
use pocketmine\network\mcpe\protocol\types\command\ConstrainedEnumValue;
use pocketmine\network\mcpe\protocol\types\command\CommandHardEnum;
use pocketmine\network\mcpe\protocol\types\command\CommandSoftEnum;
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


	/**
	 * Intern equivalent command enums before building AvailableCommandsPacket.
	 *
	 * Commands (especially alternative subcommand overloads) may construct fresh
	 * CommandHardEnum objects with the same advertised name and values. The
	 * BedrockProtocol assembler indexes those by object identity, which would
	 * otherwise publish duplicate enum definitions under one name. Bedrock's
	 * command parser may resolve a duplicate name to the wrong definition.
	 *
	 * Keep distinct value sets distinct, including when a plugin accidentally
	 * reuses one name for different value sets. Only the packet-local clones are
	 * modified; the registered command/argument objects remain unchanged.
	 *
	 * @param list<CommandData> $commands
	 * @return list<CommandData>
	 */
	public static function canonicalizePacketEnums(array $commands) : array{
		/** @var array<string, CommandHardEnum|CommandSoftEnum> $canonical */
		$canonical = [];
		/** @var array<string, string> $firstFingerprintByName */
		$firstFingerprintByName = [];

		$intern = static function(CommandHardEnum|CommandSoftEnum $enum) use (&$canonical, &$firstFingerprintByName) : CommandHardEnum|CommandSoftEnum{
			$kind = $enum instanceof CommandSoftEnum ? "soft" : "hard";
			$originalName = $enum->getName();
			$values = $enum->getValues();
			$fingerprint = hash("sha256", serialize($values));
			$nameKey = $kind . "\0" . $originalName;
			$first = $firstFingerprintByName[$nameKey] ?? null;
			$firstFingerprintByName[$nameKey] ??= $fingerprint;
			$name = $first !== null && $first !== $fingerprint
				? $originalName . ":values-" . substr($fingerprint, 0, 16)
				: $originalName;
			$key = $kind . "\0" . $name . "\0" . $fingerprint;
			if(!isset($canonical[$key])){
				$canonical[$key] = $kind === "soft"
					? new CommandSoftEnum($name, $values)
					: new CommandHardEnum($name, $values);
			}
			return $canonical[$key];
		};

		foreach($commands as $command){
			if($command->aliases !== null){
				$command->aliases = $intern($command->aliases);
			}
			foreach($command->getOverloads() as $overload){
				foreach($overload->getParameters() as $parameter){
					if($parameter->enum !== null){
						$parameter->enum = $intern($parameter->enum);
					}
				}
			}
		}
		return $commands;
	}


	/**
	 * Limited opt-in diagnostic for the exact crate edit/remove routes.
	 * Produces structured, escaped single-line records without player data,
	 * IPs, command input, raw packets or enums from unrelated commands.
	 *
	 * @param list<CommandData> $commands
	 * @return list<string>
	 */
	public static function describeCrateOverloads(array $commands) : array{
		$lines = [];
		foreach($commands as $command){
			$name = strtolower($command->getName());
			if($name !== "crate" && !str_ends_with($name, ":crate")){continue;}
			foreach($command->getOverloads() as $index => $overload){
				$parameters = $overload->getParameters();
				$action = $parameters[0]->enum?->getValues() ?? [];
				if(!in_array("edit", $action, true) && !in_array("remove", $action, true)){continue;}
				$arguments = [];
				foreach($parameters as $parameter){
					$enum = $parameter->enum;
					$values = $enum === null ? null : array_map(
						static fn(string|ConstrainedEnumValue $value) : string =>
							$value instanceof ConstrainedEnumValue ? $value->getValue() : $value,
						$enum->getValues()
					);
					$arguments[] = [
						"name" => $parameter->paramName,
						"type" => $enum === null ? "primitive" : ($enum instanceof CommandSoftEnum ? "soft_enum" : "hard_enum"),
						"enum" => $enum?->getName(),
						"values" => $values,
						"param_type" => $enum === null ? $parameter->paramType : null
					];
				}
				$lines[] = json_encode([
					"command" => $name,
					"overload" => $index,
					"arguments" => $arguments
				], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			}
		}
		return $lines === [] ? ['{"status":"no_crate_edit_or_remove_overloads"}'] : $lines;
	}

	/** Refresh after dynamic names or visibility change; use only on the server thread. */
	public static function refresh(Server $server) : void{
		foreach($server->getOnlinePlayers() as $player){
			if($player->isConnected()){$player->getNetworkSession()->syncAvailableCommands();}
		}
	}
}
