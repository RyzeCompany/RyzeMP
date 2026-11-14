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

use PHPUnit\Framework\MockObject\MockObject;
use pocketmine\command\selector\SelectorTestCase;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\ExperienceManager;
use pocketmine\entity\Location;
use pocketmine\item\VanillaItems;
use pocketmine\lang\KnownTranslationKeys;
use pocketmine\lang\Translatable;
use pocketmine\math\Vector3;
use pocketmine\player\GameMode;
use pocketmine\world\Position;

class SelectorCommandsTest extends SelectorTestCase{

	private function spawnCow(float $x, float $z) : Cow{
		$cow = new Cow($this->loc($x, 1, $z));
		$cow->spawnToAll();
		return $cow;
	}

	public function testKill() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$near = $this->spawnCow(5.5, 0.5);
		$far = $this->spawnCow(30.5, 0.5);

		$alice->expects(self::never())->method("attack");
		$this->runCommand(new KillCommand(), $alice, "kill @e[type=cow,r=20]");
		self::assertFalse($near->isAlive());
		self::assertTrue($far->isAlive());
		self::assertSame(KnownTranslationKeys::COMMANDS_KILL_SUCCESSFUL, $this->lastMessage("Alice"));

		$this->runCommand(new KillCommand(), $alice, "kill @e[type=cow,r=20]");
		self::assertSame(KnownTranslationKeys::COMMANDS_GENERIC_NOTARGETMATCH, $this->lastMessage("Alice"));

		$console = $this->console();
		$this->runCommand(new KillCommand(), $console, "kill @s");
		self::assertSame(KnownTranslationKeys::COMMANDS_GENERIC_NOTARGETMATCH, $this->lastMessage("CONSOLE"));
	}

	public function testKillNearest() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$near = $this->spawnCow(5.5, 0.5);
		$far = $this->spawnCow(9.5, 0.5);
		$alice->expects(self::never())->method("attack");

		$this->runCommand(new KillCommand(), $alice, "kill @n[type=cow]");
		self::assertFalse($near->isAlive());
		self::assertTrue($far->isAlive());
		$this->runCommand(new KillCommand(), $alice, "kill @n");
		self::assertFalse($far->isAlive(), "@n: the nearest living entity (the dead cow is skipped)");
		self::assertSame(KnownTranslationKeys::COMMANDS_KILL_SUCCESSFUL, $this->lastMessage("Alice"));

		$this->runCommand(new KillCommand(), $alice, "kill Nobody");
		self::assertSame(KnownTranslationKeys::COMMANDS_GENERIC_NOTARGETMATCH, $this->lastMessage("Alice"), "unknown names fail like an empty selector");
		$this->runCommand(new KillCommand(), $alice, "kill @q");
		self::assertSame(KnownTranslationKeys::COMMANDS_GENERIC_SYNTAX, $this->lastMessage("Alice"));
		$this->runCommand(new GiveCommand(), $alice, "give @n[type=cow] diamond");
		self::assertSame(KnownTranslationKeys::COMMANDS_GENERIC_TARGETNOTPLAYER, $this->lastMessage("Alice"));
	}

	public function testKillSelfAndAllPlayers() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$bob = $this->player("Bob", 3.5, 1, 0.5);
		$alice->expects(self::once())->method("attack");
		$this->runCommand(new KillCommand(), $alice, "kill @s");

		$bob->expects(self::once())->method("attack");
		$this->runCommand(new KillCommand(), $this->console(), "kill @a[name=Bob]");
	}

	public function testTeleport() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$teleported = null;
		$alice->expects(self::once())->method("teleport")->willReturnCallback(function(Vector3 $pos) use (&$teleported) : bool{
			$teleported = $pos;
			return true;
		});
		$this->runCommand(new TeleportCommand(), $alice, "tp @s ~ ~10 ~");
		self::assertInstanceOf(Location::class, $teleported);
		self::assertEqualsWithDelta(11.0, $teleported->y, 0.001);
		self::assertEqualsWithDelta(0.5, $teleported->x, 0.001);

		$cow = $this->spawnCow(8.5, 8.5);
		$this->runCommand(new TeleportCommand(), $alice, "tp @e[type=cow] @s");
		self::assertEqualsWithDelta(0.5, $cow->getPosition()->x, 0.001);
		self::assertEqualsWithDelta(0.5, $cow->getPosition()->z, 0.001);

		$this->runCommand(new TeleportCommand(), $alice, "tp @e[type=cow] 4 1 4");
		self::assertEqualsWithDelta(4.0, $cow->getPosition()->x, 0.001);

		$this->runCommand(new TeleportCommand(), $alice, "tp @e[type=cow] @a");
		self::assertSame(KnownTranslationKeys::COMMANDS_TP_SUCCESS, $this->lastMessage("Alice"), "one player: a single destination");
		$this->player("Bob", 3.5, 1, 0.5);
		$this->runCommand(new TeleportCommand(), $alice, "tp @e[type=cow] @a");
		self::assertSame(KnownTranslationKeys::COMMANDS_GENERIC_TOOMANYTARGETS, $this->lastMessage("Alice"));
	}

	public function testGiveAndClear() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$bob = $this->player("Bob", 3.5, 1, 0.5);
		$this->runCommand(new GiveCommand(), $this->console(), "give @a diamond");
		self::assertTrue($alice->getInventory()->contains(VanillaItems::DIAMOND()->setCount(1)));
		self::assertTrue($bob->getInventory()->contains(VanillaItems::DIAMOND()->setCount(1)));
		self::assertFalse($bob->getInventory()->contains(VanillaItems::DIAMOND()->setCount(2)), "Bedrock gives 1 by default");

		$this->runCommand(new GiveCommand(), $alice, "give @a[name=Bob] diamond 5");
		self::assertTrue($bob->getInventory()->contains(VanillaItems::DIAMOND()->setCount(6)));

		$this->runCommand(new GiveCommand(), $alice, "give @e diamond");
		self::assertSame(KnownTranslationKeys::COMMANDS_GENERIC_TARGETNOTPLAYER, $this->lastMessage("Alice"));

		$this->runCommand(new ClearCommand(), $alice, "clear @a diamond");
		self::assertFalse($alice->getInventory()->contains(VanillaItems::DIAMOND()));
		self::assertFalse($bob->getInventory()->contains(VanillaItems::DIAMOND()));
	}

	public function testEffect() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$cow = $this->spawnCow(5.5, 0.5);
		$this->runCommand(new EffectCommand(), $alice, "effect @e[type=cow] speed 10 1");
		self::assertTrue($cow->getEffects()->has(VanillaEffects::SPEED()));
		$this->runCommand(new EffectCommand(), $alice, "effect @e[type=cow] clear");
		self::assertFalse($cow->getEffects()->has(VanillaEffects::SPEED()));
	}

	public function testPlayerCommands() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$bob = $this->player("Bob", 3.5, 1, 0.5);
		$console = $this->console();

		$alice->expects(self::once())->method("setGamemode")->with(GameMode::CREATIVE);
		$bob->expects(self::once())->method("setGamemode")->with(GameMode::CREATIVE);
		$this->runCommand(new GamemodeCommand(), $console, "gamemode creative @a");

		foreach([$alice, $bob] as $player){
			$xp = $player->getXpManager();
			self::assertInstanceOf(MockObject::class, $xp);
			/** @var ExperienceManager&MockObject $xp */
			$xp->expects(self::once())->method("addXp")->with(10);
			$player->expects(self::once())->method("setSpawn")->with(self::callback(fn(Position $p) => $p->getFloorX() === 1 && $p->getFloorY() === 2 && $p->getFloorZ() === 3));
			$player->expects(self::once())->method("sendTitle")->with("Hello Alice");
			$player->expects(self::once())->method("kick");
		}
		$this->runCommand(new XpCommand(), $console, "xp 10 @a");
		$this->runCommand(new SpawnpointCommand(), $console, "spawnpoint @a 1 2 3");
		$this->runCommand(new TitleCommand(), $alice, "title @a title Hello @s");
		$this->runCommand(new KickCommand(), $console, "kick @a bye");
	}

	public function testTellSayMe() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$this->player("Bob", 3.5, 1, 0.5);
		$this->player("Carol", 6.5, 1, 0.5);

		$this->runCommand(new TellCommand(), $alice, "tell @a hi");
		self::assertSame([KnownTranslationKeys::COMMANDS_MESSAGE_DISPLAY_INCOMING], $this->messagesOf("Bob"));
		self::assertSame([KnownTranslationKeys::COMMANDS_MESSAGE_DISPLAY_INCOMING], $this->messagesOf("Carol"));

		$this->runCommand(new TellCommand(), $alice, "tell @s hi");
		self::assertSame(KnownTranslationKeys::COMMANDS_MESSAGE_SAMETARGET, $this->lastMessage("Alice"));

		$broadcasts = [];
		$this->server()->method("broadcastMessage")->willReturnCallback(function(Translatable|string $message) use (&$broadcasts) : int{
			self::assertInstanceOf(Translatable::class, $message);
			$broadcasts[] = $message->getParameters();
			return 0;
		});
		$this->runCommand(new SayCommand(), $alice, "say hello @a[c=2]");
		$this->runCommand(new MeCommand(), $alice, "me waves at @p[name=!Alice]");
		self::assertCount(2, $broadcasts);
		self::assertSame("hello Alice, Bob", $broadcasts[0][1]);
		$emote = $broadcasts[1][1];
		self::assertIsString($emote);
		self::assertStringEndsWith("waves at Bob", $emote);
	}

	public function testOpWithSelector() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$this->player("Bob", 3.5, 1, 0.5);
		$opped = [];
		$this->server()->method("addOp")->willReturnCallback(function(string $name) use (&$opped) : void{
			$opped[] = $name;
		});
		$this->runCommand(new OpCommand(), $alice, "op @a");
		self::assertSame(["Alice", "Bob"], $opped);
	}

	public function testTag() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$cow = $this->spawnCow(5.5, 0.5);
		$other = $this->spawnCow(9.5, 0.5);
		$this->runCommand(new TagCommand(), $alice, "tag @e[type=cow,c=1] add marked");
		self::assertTrue($cow->hasCommandTag("marked"));
		self::assertFalse($other->hasCommandTag("marked"));
		self::assertSame(KnownTranslationKeys::COMMANDS_TAG_ADD_SUCCESS_SINGLE, $this->lastMessage("Alice"));

		$this->runCommand(new TagCommand(), $alice, "tag @e[type=cow,c=1] add marked");
		self::assertSame(KnownTranslationKeys::COMMANDS_TAG_ADD_FAILED, $this->lastMessage("Alice"));

		$this->runCommand(new KillCommand(), $alice, "kill @e[tag=marked]");
		self::assertFalse($cow->isAlive());
		self::assertTrue($other->isAlive());

		$this->runCommand(new TagCommand(), $alice, "tag @e[type=cow] list");
		self::assertSame(KnownTranslationKeys::COMMANDS_TAG_LIST_SINGLE_EMPTY, $this->lastMessage("Alice"));

		//saved with the entity
		$cow2 = $this->spawnCow(2.5, 2.5);
		$cow2->addCommandTag("persisted");
		$copy = new Cow($this->loc(2.5, 1, 2.5), $cow2->saveNBT());
		self::assertSame(["persisted"], $copy->getCommandTags());
		$copy->flagForDespawn();
	}
}
