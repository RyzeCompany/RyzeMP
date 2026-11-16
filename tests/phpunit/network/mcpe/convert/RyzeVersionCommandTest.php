<?php

/*
 * This file is part of RyzeMP.
 *
 * RyzeMP is free software: you can redistribute it and/or modify it
 * under the terms of the GNU Lesser General Public License, version 3
 * or (at your option) any later version.
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use PHPUnit\Framework\TestCase;
use pocketmine\command\CommandSender;
use pocketmine\command\defaults\VersionCommand;
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\permission\DefaultPermissions;

final class RyzeVersionCommandTest extends TestCase{

	public function testVersionCommandHasNativeSuggestionsAndLegacyFallback() : void{
		DefaultPermissions::registerCorePermissions();
		$command = new VersionCommand();
		self::assertSame(["ver", "about"], $command->getAliases());
		$overloads = (new CommandOverloadMapper())->map($command);
		self::assertCount(4, $overloads);
		self::assertCount(0, $overloads[0]->getParameters(), "bare /version remains valid");
		self::assertSame(["runtime", "protocols", "help"], $overloads[1]->getParameters()[0]->enum?->getValues());
		self::assertSame(["plugin"], $overloads[2]->getParameters()[0]->enum?->getValues());
		self::assertSame(AvailableCommandsPacket::ARG_TYPE_RAWTEXT, $overloads[2]->getParameters()[1]->paramType);
		self::assertSame(AvailableCommandsPacket::ARG_TYPE_RAWTEXT, $overloads[3]->getParameters()[0]->paramType, "legacy /version plugin name stays supported");
	}

	public function testProtocolsSubcommandListsTheSameVersionsAcceptedAtLogin() : void{
		DefaultPermissions::registerCorePermissions();
		$messages = [];
		$sender = $this->createMock(CommandSender::class);
		$sender->method("sendMessage")->willReturnCallback(static function($message) use (&$messages) : void{
			$messages[] = (string) $message;
		});
		$command = new VersionCommand();
		self::assertTrue($command->execute($sender, "version", ["protocols"]));
		$joined = implode("\n", $messages);
		self::assertStringContainsString("32 (version: protocol)", $joined);
		self::assertStringContainsString("1.20.0: 589", $joined);
		self::assertStringContainsString("1.26.60: 2225", $joined);
	}
}
