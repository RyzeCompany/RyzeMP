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
use pocketmine\command\defaults\ClearCommand;
use pocketmine\command\defaults\EffectCommand;
use pocketmine\command\defaults\GamemodeCommand;
use pocketmine\command\defaults\GiveCommand;
use pocketmine\command\defaults\KickCommand;
use pocketmine\command\defaults\KillCommand;
use pocketmine\command\defaults\LocateCommand;
use pocketmine\command\defaults\SayCommand;
use pocketmine\command\defaults\SeedCommand;
use pocketmine\command\defaults\TagCommand;
use pocketmine\command\defaults\TeleportCommand;
use pocketmine\command\defaults\TellCommand;
use pocketmine\command\defaults\TitleCommand;
use pocketmine\command\defaults\VanillaCommand;
use pocketmine\command\defaults\XpCommand;
use pocketmine\command\parameter\CommandParameter;
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\serializer\AvailableCommandsPacketAssembler;
use pocketmine\network\mcpe\protocol\serializer\AvailableCommandsPacketDisassembler;
use pocketmine\network\mcpe\protocol\types\command\CommandData;
use pocketmine\network\mcpe\protocol\types\command\CommandPermissions;
use pocketmine\network\mcpe\protocol\types\command\CommandSoftEnum;
use pocketmine\permission\DefaultPermissions;
use function count;

class CommandOverloadMapperTest extends TestCase{

	public function testOverloadsSurviveEncoding() : void{
		DefaultPermissions::registerCorePermissions();
		/** @var Command[] $commands */
		$commands = [
			new KillCommand(), new TeleportCommand(), new GiveCommand(), new ClearCommand(), new EffectCommand(),
			new GamemodeCommand(), new XpCommand(), new TitleCommand(), new TellCommand(), new SayCommand(),
			new KickCommand(), new TagCommand(), new LocateCommand(), new SeedCommand()
		];
		$mapper = new CommandOverloadMapper();
		$data = [];
		foreach($commands as $command){
			$data[] = new CommandData($command->getLabel(), "", 0, CommandPermissions::NORMAL, null, $mapper->map($command), chainedSubCommandData: []);
		}
		$packet = AvailableCommandsPacketAssembler::assemble($data, [], []);
		$writer = new ByteBufferWriter();
		$packet->encode($writer);
		$decoded = new AvailableCommandsPacket();
		$decoded->decode(new ByteBufferReader($writer->getData()));
		$commandData = AvailableCommandsPacketDisassembler::disassemble($decoded)->commandData;
		self::assertCount(count($commands), $commandData);

		$byName = [];
		foreach($commandData as $entry){
			$byName[$entry->getName()] = $entry;
		}

		$kill = $byName["kill"]->getOverloads();
		self::assertCount(1, $kill, "built-in commands are exhaustive: no free-text overload");
		self::assertSame(AvailableCommandsPacket::ARG_TYPE_TARGET, $kill[0]->getParameters()[0]->paramType);
		self::assertTrue($kill[0]->getParameters()[0]->isOptional);

		self::assertCount(8, $byName["tp"]->getOverloads(), "4 destination forms, with and without victim");
		self::assertSame(AvailableCommandsPacket::ARG_TYPE_POSITION, $byName["tp"]->getOverloads()[1]->getParameters()[0]->paramType);

		$tagAction = $byName["tag"]->getOverloads()[0]->getParameters()[1]->enum;
		self::assertNotNull($tagAction);
		self::assertSame(["add", "remove"], $tagAction->getValues());

		self::assertCount(1, $byName["seed"]->getOverloads(), "exhaustive commands without declared overloads keep the free-text one");
	}

	public function testSoftEnums() : void{
		$command = new class extends VanillaCommand{
			public function __construct(){
				parent::__construct("softenumtest");
			}

			public function getOverloads() : array{
				return [
					[CommandParameter::softEnum("name", "TestNames", ["a", "b"])],
					[CommandParameter::softEnum("other", "TestNames", ["a", "b"], true)],
				];
			}

			public function execute(CommandSender $sender, string $commandLabel, array $args){
				return true;
			}
		};
		$overloads = (new CommandOverloadMapper())->map($command);
		$packet = AvailableCommandsPacketAssembler::assemble([new CommandData("softenumtest", "", 0, CommandPermissions::NORMAL, null, $overloads, chainedSubCommandData: [])], [], []);
		self::assertCount(1, $packet->softEnums, "soft enums are shared by name");
		$writer = new ByteBufferWriter();
		$packet->encode($writer);
		$decoded = new AvailableCommandsPacket();
		$decoded->decode(new ByteBufferReader($writer->getData()));
		$parameter = AvailableCommandsPacketDisassembler::disassemble($decoded)->commandData[0]->getOverloads()[0]->getParameters()[0];
		self::assertInstanceOf(CommandSoftEnum::class, $parameter->enum);
		self::assertSame("TestNames", $parameter->enum->getName());
		self::assertSame(["a", "b"], $parameter->enum->getValues());
	}
}
