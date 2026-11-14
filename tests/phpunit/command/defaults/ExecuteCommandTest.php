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

use pocketmine\block\VanillaBlocks;
use pocketmine\command\ExecuteCommandSender;
use pocketmine\command\selector\SelectorTestCase;
use pocketmine\command\SimpleCommandMap;
use pocketmine\command\utils\CommandCoordinates;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\entity\animal\Cow;
use pocketmine\lang\KnownTranslationKeys;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\Position;

class ExecuteCommandTest extends SelectorTestCase{

	protected function setUp() : void{
		parent::setUp();
		$server = $this->server();
		$server->method("getCommandMap")->willReturn(new SimpleCommandMap($server));
	}

	private function spawnCow(float $x, float $z, string $name = "") : Cow{
		$cow = new Cow($this->loc($x, 1, $z));
		$cow->setNameTag($name);
		$cow->spawnToAll();
		return $cow;
	}

	public function testAsRunsAsEachTarget() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$alice->expects(self::never())->method("attack");
		$a = $this->spawnCow(3.5, 0.5);
		$b = $this->spawnCow(6.5, 0.5);
		$far = $this->spawnCow(40.5, 0.5);

		//kill with no target acts on the executing entity
		$this->runCommand(new ExecuteCommand(), $alice, "execute as @e[type=cow,r=10] run kill");
		self::assertFalse($a->isAlive());
		self::assertFalse($b->isAlive());
		self::assertTrue($far->isAlive());
		self::assertSame(KnownTranslationKeys::COMMANDS_KILL_SUCCESSFUL, $this->lastMessage("Alice"), "the output goes to the player who ran /execute");
	}

	public function testAtAndRelativeCoordinates() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$cow = $this->spawnCow(10.5, 0.5, "Bessie");
		//"at" moves the position: ~ is relative to the cow, and @s is still Alice
		$this->runCommand(new ExecuteCommand(), $alice, "execute at @e[type=cow] run kill @e[type=cow,r=1]");
		self::assertFalse($cow->isAlive());
	}

	public function testConditions() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$this->spawnCow(3.5, 0.5);

		$this->runCommand(new ExecuteCommand(), $alice, "execute if entity @e[type=cow,r=5]");
		self::assertSame(KnownTranslationKeys::COMMANDS_EXECUTE_TRUECONDITION, $this->lastMessage("Alice"));
		$this->runCommand(new ExecuteCommand(), $alice, "execute unless entity @e[type=cow,r=5]");
		self::assertSame(KnownTranslationKeys::COMMANDS_EXECUTE_FALSECONDITION, $this->lastMessage("Alice"));
		$this->runCommand(new ExecuteCommand(), $alice, "execute if entity @e[type=cow,r=1] run kill @e[type=cow]");
		self::assertSame(KnownTranslationKeys::COMMANDS_EXECUTE_FALSECONDITION, $this->lastMessage("Alice"), "a failed condition stops the chain");

		//the floor is stone at y=0
		$this->runCommand(new ExecuteCommand(), $alice, "execute if block ~ ~-1 ~ stone");
		self::assertSame(KnownTranslationKeys::COMMANDS_EXECUTE_TRUECONDITION, $this->lastMessage("Alice"));
		$this->runCommand(new ExecuteCommand(), $alice, "execute if block ~ ~-1 ~ minecraft:dirt");
		self::assertSame(KnownTranslationKeys::COMMANDS_EXECUTE_FALSECONDITION, $this->lastMessage("Alice"));
		$this->runCommand(new ExecuteCommand(), $alice, "execute positioned 0 1 0 if blocks ~ ~-1 ~ ~1 ~-1 ~1 ~2 ~-1 ~2 all");
		self::assertSame(KnownTranslationKeys::COMMANDS_EXECUTE_TRUECONDITIONWITHCOUNT, $this->lastMessage("Alice"));
		$this->world->setBlockAt(3, 0, 3, VanillaBlocks::DIRT(), false);
		$this->runCommand(new ExecuteCommand(), $alice, "execute positioned 0 1 0 if blocks ~ ~-1 ~ ~1 ~-1 ~1 ~2 ~-1 ~2 all");
		self::assertSame(KnownTranslationKeys::COMMANDS_EXECUTE_FALSECONDITION, $this->lastMessage("Alice"));
	}

	public function testSyntax() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		foreach(["execute", "execute as @s", "execute in foo run say x", "execute bogus run say x", "execute positioned ~ ~ run say x"] as $line){
			try{
				$this->runCommand(new ExecuteCommand(), $alice, $line);
				self::fail("$line: syntax error expected");
			}catch(InvalidCommandSyntaxException){
				//expected
			}
		}
		$this->runCommand(new ExecuteCommand(), $alice, "execute align xq run say x");
		self::assertSame(KnownTranslationKeys::COMMANDS_EXECUTE_ALIGN_INVALIDINPUT, $this->lastMessage("Alice"));
		$this->runCommand(new ExecuteCommand(), $alice, "execute as @e[type=cow] run kill @s");
		self::assertSame(KnownTranslationKeys::COMMANDS_GENERIC_NOTARGETMATCH, $this->lastMessage("Alice"));
	}

	public function testContextChain() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5, yaw: 90.0);
		$context = ExecuteCommand::initialContext($alice);
		self::assertSame($alice, $context->getExecutor());
		self::assertSame($alice, $context->getOriginalSender());
		self::assertSame(90.0, $context->getYaw());

		//a nested context keeps the original sender, and its position is used by ~ and by selectors
		$nested = new ExecuteCommandSender($context, null, new Position(10.5, 5, 10.5, $this->world), 0.0, 0.0);
		self::assertSame($alice, $nested->getOriginalSender());
		self::assertSame("Alice", $nested->getName(), "no executor: the original sender's name");
		$origin = CommandCoordinates::senderOrigin($nested);
		self::assertNotNull($origin);
		self::assertSame(10.5, $origin->x);
		self::assertEquals(11.5, CommandCoordinates::parsePosition($origin, "~1", "~", "~")->x);
	}

	public function testDimensionNames() : void{
		self::assertSame(Dimension::NETHER, ExecuteCommand::parseDimension("nether"));
		self::assertSame(Dimension::NETHER, ExecuteCommand::parseDimension("minecraft:the_nether"));
		self::assertSame(Dimension::END, ExecuteCommand::parseDimension("the_end"));
		self::assertSame(Dimension::OVERWORLD, ExecuteCommand::parseDimension("Overworld"));
		$this->expectException(InvalidCommandSyntaxException::class);
		ExecuteCommand::parseDimension("foo");
	}

	public function testJoinArguments() : void{
		self::assertSame('say "hello world" @e[name="A B"] x', ExecuteCommand::joinArguments(["say", "hello world", '@e[name="A B"]', "x"]));
		self::assertSame('tell Bob "a \"quoted\" word"', ExecuteCommand::joinArguments(["tell", "Bob", 'a "quoted" word']));
	}
}
