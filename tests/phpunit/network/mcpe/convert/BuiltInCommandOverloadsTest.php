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

use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\defaults\VanillaCommand;
use pocketmine\command\parameter\CommandEnums;
use pocketmine\command\parameter\CommandParameter;
use pocketmine\command\parameter\CommandParameterType;
use pocketmine\command\parameter\ExhaustiveOverloads;
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\serializer\AvailableCommandsPacketAssembler;
use pocketmine\network\mcpe\protocol\serializer\AvailableCommandsPacketDisassembler;
use pocketmine\network\mcpe\protocol\types\command\CommandData;
use pocketmine\network\mcpe\protocol\types\command\CommandHardEnum;
use pocketmine\network\mcpe\protocol\types\command\CommandPermissions;
use pocketmine\permission\DefaultPermissions;
use function array_unique;
use function basename;
use function count;
use function glob;
use function implode;
use function is_subclass_of;
use function preg_match;
use function sort;
use function strlen;

/**
 * Checks the client command data of every built-in command (src/command/defaults).
 */
class BuiltInCommandOverloadsTest extends TestCase{

	/**
	 * @return VanillaCommand[]
	 * @phpstan-return list<VanillaCommand>
	 */
	public static function builtInCommands() : array{
		DefaultPermissions::registerCorePermissions();
		$commands = [];
		$files = glob(__DIR__ . "/../../../../../src/command/defaults/*Command.php");
		self::assertNotFalse($files);
		foreach($files as $file){
			$class = "pocketmine\\command\\defaults\\" . basename($file, ".php");
			if($class === VanillaCommand::class || !is_subclass_of($class, VanillaCommand::class)){
				continue;
			}
			$command = (new \ReflectionClass($class))->newInstance();
			self::assertInstanceOf(VanillaCommand::class, $command);
			$commands[] = $command;
		}
		self::assertGreaterThan(40, count($commands));
		return $commands;
	}

	public function testEveryBuiltInCommandDeclaresOverloads() : void{
		$missing = [];
		foreach(self::builtInCommands() as $command){
			$label = $command->getLabel();
			if(count($command->getOverloads()) === 0){
				$missing[] = $label;
			}
		}
		self::assertSame([], $missing, "built-in commands without overloads");
	}

	public function testOverloadsAreWellFormed() : void{
		/** @var string[][] $enumValues */
		$enumValues = [];
		foreach(self::builtInCommands() as $command){
			$label = $command->getLabel();
			foreach($command->getOverloads() as $i => $overload){
				$optional = false;
				foreach($overload as $parameter){
					$where = "/$label overload $i parameter " . $parameter->getName();
					self::assertFalse($optional && !$parameter->isOptional(), "$where: required parameter after an optional one");
					$optional = $optional || $parameter->isOptional();
					self::assertMatchesRegularExpression('/^[A-Za-z][A-Za-z0-9_]*$/', $parameter->getName(), $where);

					$type = $parameter->getType();
					if($type === CommandParameterType::ENUM || $type === CommandParameterType::SOFT_ENUM){
						$name = $parameter->getEnumName();
						self::assertNotNull($name, $where);
						$values = $parameter->getEnumValues();
						self::assertNotEmpty($values, "$where: empty enum $name");
						self::assertCount(count($values), array_unique($values), "$where: duplicate values in enum $name");
						foreach($values as $value){
							self::assertSame(1, preg_match('/^\S+$/', $value), "$where: enum $name value \"$value\" cannot be typed as one word");
						}
						$sorted = $values;
						sort($sorted);
						$key = ($type === CommandParameterType::SOFT_ENUM ? "soft:" : "") . $name;
						if(isset($enumValues[$key])){
							self::assertSame($enumValues[$key], $sorted, "$where: enum $name is declared with different values elsewhere");
						}
						$enumValues[$key] = $sorted;
					}elseif($type === CommandParameterType::POSTFIX){
						self::assertNotEmpty($parameter->getPostfix(), $where);
					}
				}
			}
		}
	}

	public function testPacketEncodesAndDecodes() : void{
		$commands = self::builtInCommands();
		$mapper = new CommandOverloadMapper(CommandOverloadMapper::commandNames($commands));
		$data = [];
		foreach($commands as $command){
			$aliases = $command->getAliases();
			$aliasEnum = count($aliases) > 0 ? new CommandHardEnum($command->getLabel() . "Aliases", [...$aliases, $command->getLabel()]) : null;
			$data[] = new CommandData($command->getLabel(), "", 0, CommandPermissions::NORMAL, $aliasEnum, $mapper->map($command), chainedSubCommandData: []);
		}
		$packet = AvailableCommandsPacketAssembler::assemble($data, [], []);

		$enumNames = [];
		foreach($packet->enums as $enum){
			$enumNames[] = $enum->getName();
		}
		self::assertCount(count($enumNames), array_unique($enumNames), "duplicate hard enum names: " . implode(", ", $enumNames));
		$softEnumNames = [];
		foreach($packet->softEnums as $enum){
			$softEnumNames[] = $enum->getName();
		}
		self::assertCount(count($softEnumNames), array_unique($softEnumNames), "duplicate soft enum names");
		foreach($packet->enums as $enum){
			foreach($enum->getValueIndexes() as $index){
				self::assertArrayHasKey($index, $packet->enumValues, "enum " . $enum->getName() . " references a missing value");
			}
		}

		$writer = new ByteBufferWriter();
		$packet->encode($writer, \pocketmine\network\mcpe\protocol\ProtocolInfo::CURRENT_PROTOCOL);
		self::assertLessThan(200_000, strlen($writer->getData()), "AvailableCommandsPacket should stay reasonably small");

		$decoded = new AvailableCommandsPacket();
		$decoded->decode(new ByteBufferReader($writer->getData()), \pocketmine\network\mcpe\protocol\ProtocolInfo::CURRENT_PROTOCOL);
		$commandData = AvailableCommandsPacketDisassembler::disassemble($decoded)->commandData;
		self::assertCount(count($commands), $commandData);

		$byName = [];
		foreach($commandData as $entry){
			$byName[$entry->getName()] = $entry;
		}
		foreach($commands as $command){
			$label = $command->getLabel();
			self::assertArrayHasKey($label, $byName);
			$expected = count($command->getOverloads()) + ($command instanceof ExhaustiveOverloads ? 0 : 1);
			self::assertCount($expected, $byName[$label]->getOverloads(), "/$label overloads");
		}

		$item = $byName["give"]->getOverloads()[0]->getParameters()[1]->enum;
		self::assertInstanceOf(CommandHardEnum::class, $item);
		self::assertSame(CommandEnums::ITEM, $item->getName());
		self::assertContains("diamond_sword", $item->getValues());

		self::assertSame("L", $byName["xp"]->getOverloads()[1]->getParameters()[0]->postfix);

		$help = $byName["help"]->getOverloads()[1]->getParameters()[0]->enum;
		self::assertInstanceOf(CommandHardEnum::class, $help);
		self::assertSame(CommandEnums::COMMAND_NAME, $help->getName());
		self::assertContains("tp", $help->getValues());
		self::assertContains("teleport", $help->getValues());

		self::assertCount(1, $byName["list"]->getOverloads(), "no free-text overload for exhaustive commands");
		self::assertCount(0, $byName["list"]->getOverloads()[0]->getParameters());
	}

	public function testCommandNameEnumWithoutCommandsFallsBackToString() : void{
		$command = new class extends VanillaCommand{
			public function __construct(){
				parent::__construct("cmdnametest");
			}

			public function getOverloads() : array{
				return [[CommandParameter::commandName("command")]];
			}

			public function execute(CommandSender $sender, string $commandLabel, array $args){
				return true;
			}
		};
		$overloads = (new CommandOverloadMapper())->map($command);
		$parameter = $overloads[0]->getParameters()[0];
		self::assertNull($parameter->enum);
		self::assertSame(AvailableCommandsPacket::ARG_TYPE_STRING, $parameter->paramType);
	}

	public function testPluginCommandsKeepFreeTextOverload() : void{
		$command = new class("plugincmd") extends Command{
			public function execute(CommandSender $sender, string $commandLabel, array $args){
				return true;
			}
		};
		$overloads = (new CommandOverloadMapper())->map($command);
		self::assertCount(1, $overloads);
		self::assertSame(AvailableCommandsPacket::ARG_TYPE_RAWTEXT, $overloads[0]->getParameters()[0]->paramType);
	}
}
