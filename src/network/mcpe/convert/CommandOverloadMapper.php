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

namespace pocketmine\network\mcpe\convert;

use pocketmine\command\Command;
use pocketmine\command\parameter\CommandEnums;
use pocketmine\command\parameter\CommandParameter;
use pocketmine\command\parameter\CommandParameterType;
use pocketmine\command\parameter\ExhaustiveOverloads;
use pocketmine\command\parameter\ParameterizedCommand;
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\types\command\CommandHardEnum;
use pocketmine\network\mcpe\protocol\types\command\CommandOverload;
use pocketmine\network\mcpe\protocol\types\command\CommandParameter as NetworkCommandParameter;
use pocketmine\network\mcpe\protocol\types\command\CommandSoftEnum;
use pocketmine\network\mcpe\protocol\types\command\ConstrainedEnumValue;
use pocketmine\utils\Utils;
use function array_map;
use function count;
use function str_contains;

/**
 * Converts the overloads of {@link ParameterizedCommand}s into AvailableCommandsPacket overloads.
 */
final class CommandOverloadMapper{
	/**
	 * Hard enums by name, shared by all the commands of one packet (the client wants each enum once). The first
	 * declaration of a name wins.
	 * @var CommandHardEnum[]
	 * @phpstan-var array<string, CommandHardEnum>
	 */
	private array $enums = [];
	/**
	 * Soft enums by name, shared like the hard enums.
	 * @var CommandSoftEnum[]
	 * @phpstan-var array<string, CommandSoftEnum>
	 */
	private array $softEnums = [];

	/**
	 * @param string[] $commandNames values of {@link CommandParameterType::COMMAND_NAME} parameters: the names and
	 *                               aliases of the commands the receiving player can use
	 * @phpstan-param list<string> $commandNames
	 */
	public function __construct(
		private array $commandNames = []
	){}

	/**
	 * Overloads of the command: its declared ones (if any) followed by the free-text "args" overload every command
	 * accepts, so the client never rejects a syntax the command understands. Commands implementing
	 * {@link ExhaustiveOverloads} get no free-text overload.
	 *
	 * @return CommandOverload[]
	 * @phpstan-return list<CommandOverload>
	 */
	public function map(Command $command) : array{
		$overloads = [];
		if($command instanceof ParameterizedCommand){
			foreach($command->getOverloads() as $parameters){
				$overloads[] = new CommandOverload(chaining: false, parameters: array_map(fn(CommandParameter $p) => $this->mapParameter($p), $parameters));
			}
		}
		if(!$command instanceof ExhaustiveOverloads || count($overloads) === 0){
			$overloads[] = new CommandOverload(chaining: false, parameters: [NetworkCommandParameter::standard("args", AvailableCommandsPacket::ARG_TYPE_RAWTEXT, 0, true)]);
		}
		return $overloads;
	}

	/** Enum constraint ID "allow aliases" on the wire (BDS marks the namespaced item and block names with it). */
	private const CONSTRAINT_ALLOW_ALIASES = 3;

	/**
	 * Item and block names the way BDS sends them: each "minecraft:" name, constrained as an alias (accepted but not
	 * listed), followed by the plain name. The client finds the item of each suggestion, and its icon, this way.
	 *
	 * @param string[] $values
	 * @phpstan-param list<string> $values
	 * @return list<string|ConstrainedEnumValue>
	 */
	private static function withNamespacedAliases(array $values) : array{
		$result = [];
		foreach($values as $value){
			if(!str_contains($value, ":")){
				$result[] = new ConstrainedEnumValue("minecraft:" . $value, [self::CONSTRAINT_ALLOW_ALIASES]);
			}
			$result[] = $value;
		}
		return $result;
	}

	private function mapParameter(CommandParameter $parameter) : NetworkCommandParameter{
		$type = $parameter->getType();
		if($type === CommandParameterType::ENUM || $type === CommandParameterType::COMMAND_NAME){
			$name = $parameter->getEnumName() ?? $parameter->getName();
			$values = $type === CommandParameterType::COMMAND_NAME ? $this->commandNames : $parameter->getEnumValues();
			if(!isset($this->enums[$name]) && count($values) === 0){
				//the client does not accept empty enums
				return NetworkCommandParameter::standard($parameter->getName(), AvailableCommandsPacket::ARG_TYPE_STRING, 0, $parameter->isOptional());
			}
			$enum = $this->enums[$name] ??= new CommandHardEnum($name, $name === CommandEnums::ITEM || $name === "Block" ? self::withNamespacedAliases($values) : $values);
			//like BDS: item and block names carry the "has enum constraint" option, which makes the client show the
			//item icon next to each suggestion
			$flags = $name === CommandEnums::ITEM || $name === "Block" ? NetworkCommandParameter::FLAG_HAS_ENUM_CONSTRAINT : 0;
			return NetworkCommandParameter::enum($parameter->getName(), $enum, $flags, $parameter->isOptional());
		}
		if($type === CommandParameterType::SOFT_ENUM){
			$name = $parameter->getEnumName() ?? $parameter->getName();
			$enum = $this->softEnums[$name] ??= new CommandSoftEnum($name, $parameter->getEnumValues());
			return NetworkCommandParameter::softEnum($parameter->getName(), $enum, 0, $parameter->isOptional());
		}
		if($type === CommandParameterType::POSTFIX){
			return NetworkCommandParameter::postfixed($parameter->getName(), $parameter->getPostfix() ?? "", 0, $parameter->isOptional());
		}
		return NetworkCommandParameter::standard($parameter->getName(), match($type){
			CommandParameterType::TARGET => AvailableCommandsPacket::ARG_TYPE_TARGET,
			CommandParameterType::INT => AvailableCommandsPacket::ARG_TYPE_INT,
			CommandParameterType::FLOAT => AvailableCommandsPacket::ARG_TYPE_FLOAT,
			CommandParameterType::VALUE => AvailableCommandsPacket::ARG_TYPE_VALUE,
			CommandParameterType::POSITION => AvailableCommandsPacket::ARG_TYPE_POSITION,
			CommandParameterType::STRING => AvailableCommandsPacket::ARG_TYPE_STRING,
			CommandParameterType::MESSAGE => AvailableCommandsPacket::ARG_TYPE_MESSAGE,
			CommandParameterType::RAWTEXT => AvailableCommandsPacket::ARG_TYPE_RAWTEXT,
			CommandParameterType::JSON => AvailableCommandsPacket::ARG_TYPE_JSON,
			CommandParameterType::BLOCK_STATES => AvailableCommandsPacket::ARG_TYPE_BLOCK_STATES,
		}, 0, $parameter->isOptional());
	}

	/**
	 * Values of the "CommandName" enum for a player who can use the given commands: their labels and aliases.
	 *
	 * @param Command[] $commands
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function commandNames(array $commands) : array{
		$names = [];
		foreach($commands as $command){
			$names[$command->getLabel()] = true;
			foreach($command->getAliases() as $alias){
				$names[$alias] = true;
			}
		}
		$list = [];
		foreach(Utils::stringifyKeys($names) as $name => $_){
			$list[] = $name;
		}
		return $list;
	}
}
