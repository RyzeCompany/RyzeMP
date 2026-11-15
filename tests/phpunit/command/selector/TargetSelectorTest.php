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

namespace pocketmine\command\selector;

use pocketmine\command\CommandSender;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Pig;
use pocketmine\entity\Entity;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\Zombie;
use pocketmine\item\VanillaItems;
use pocketmine\lang\KnownTranslationKeys;
use pocketmine\player\GameMode;
use function array_map;
use function sort;

class TargetSelectorTest extends SelectorTestCase{

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	private function select(string $selector, CommandSender $sender) : array{
		$names = array_map(fn(Entity $e) => TargetSelector::getEntityName($e), TargetSelector::parse($selector)->selectEntities($sender));
		return $names;
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	private function selectSorted(string $selector, CommandSender $sender) : array{
		$names = $this->select($selector, $sender);
		sort($names);
		return $names;
	}

	private function spawnCow(float $x, float $z, string $name = "") : Cow{
		$cow = new Cow($this->loc($x, 1, $z));
		$cow->setNameTag($name);
		$cow->spawnToAll();
		return $cow;
	}

	public function testPlayerSelectors() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$this->player("Bob", 10.5, 1, 0.5, GameMode::CREATIVE, 5);
		$this->player("Carol", -20.5, 1, 0.5, GameMode::ADVENTURE, 30);
		$this->player("Dead", 1.5, 1, 0.5, alive: false);
		$this->player("Ghost", 2.5, 1, 0.5, GameMode::SPECTATOR);

		self::assertSame(["Alice", "Bob", "Carol", "Dead", "Ghost"], $this->selectSorted("@a", $alice), "@a includes dead players");
		self::assertSame(["Alice"], $this->select("@s", $alice));
		self::assertSame(["Alice"], $this->select("@initiator", $alice));
		self::assertSame(["Alice"], $this->select("@p", $alice), "@p is the nearest living, non-spectator player");
		self::assertSame(["Bob"], $this->select("@p[x=12,y=1,z=0]", $alice));
		self::assertSame(["Carol"], $this->select("@a[c=-1]", $alice), "negative c selects the farthest");
		self::assertSame(["Alice", "Ghost"], $this->select("@a[c=2,name=!Dead]", $alice));
		self::assertSame(["Bob"], $this->select("@a[m=creative]", $alice));
		self::assertSame(["Alice", "Carol", "Dead", "Ghost"], $this->selectSorted("@a[m=!c]", $alice));
		self::assertSame(["Alice", "Dead"], $this->selectSorted("@a[m=d]", $alice));
		self::assertSame(["Bob", "Carol"], $this->selectSorted("@a[lm=1]", $alice));
		self::assertSame(["Bob"], $this->select("@a[lm=1,l=10]", $alice));
		self::assertSame(["Alice", "Bob", "Dead", "Ghost"], $this->selectSorted("@a[r=10.5]", $alice));
		self::assertSame(["Carol"], $this->select("@a[rm=11]", $alice));
		self::assertSame(["bob"], array_map("strtolower", $this->select("@a[name=bob]", $alice)), "player names are case-insensitive");
		self::assertSame(["Ghost"], $this->select("@a[dx=0,dy=0,dz=0,x=2,y=1,z=0]", $alice), "volume selects intersecting hitboxes");
		self::assertCount(1, $this->select("@r", $alice));
		self::assertCount(3, $this->select("@r[c=10]", $alice), "@r skips dead players and spectators");
	}

	public function testRotationArguments() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5, yaw: 90.0, pitch: 45.0);
		$this->player("Bob", 1.5, 1, 0.5, yaw: 270.0, pitch: -10.0);

		self::assertSame(["Alice"], $this->select("@a[rxm=0]", $alice));
		self::assertSame(["Bob"], $this->select("@a[rx=0]", $alice));
		self::assertSame(["Alice"], $this->select("@a[rym=45,ry=135]", $alice));
		self::assertSame(["Bob"], $this->select("@a[rym=-100,ry=-80]", $alice), "yaw 270 is -90");
		self::assertSame(["Alice", "Bob"], $this->selectSorted("@a[rym=60,ry=-60]", $alice), "wrapping yaw range");
	}

	public function testEntitySelectors() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$near = $this->spawnCow(3.5, 0.5, "Daisy");
		$far = $this->spawnCow(15.5, 0.5);
		$pig = new Pig($this->loc(-4.5, 1, 0.5));
		$pig->spawnToAll();
		$zombie = new Zombie($this->loc(6.5, 1, 6.5));
		$zombie->spawnToAll();
		$item = new ItemEntity($this->loc(1.5, 1, 1.5), VanillaItems::DIAMOND());
		$item->spawnToAll();
		$dead = $this->spawnCow(2.5, 2.5, "Dead cow");
		$dead->setHealth(0);

		self::assertSame(["Cow", "Daisy"], $this->selectSorted("@e[type=cow]", $alice), "dead entities are excluded");
		self::assertSame(["Daisy"], $this->select("@e[type=minecraft:cow,r=10]", $alice));
		self::assertSame(["Cow"], $this->select("@e[type=cow,c=-1]", $alice));
		self::assertSame(["Daisy"], $this->select("@e[type=cow,c=1]", $alice));
		self::assertSame(["Daisy"], $this->select("@e[type=cow,name=Daisy]", $alice));
		self::assertSame(["Cow"], $this->select("@e[type=cow,name=!Daisy]", $alice));
		self::assertSame(["Pig", "Zombie", "item"], $this->selectSorted("@e[type=!cow]", $alice));
		self::assertSame(["Zombie"], $this->select("@e[family=monster]", $alice));
		self::assertSame(["Zombie"], $this->select("@e[family=undead]", $alice));
		self::assertSame(["Cow", "Daisy", "Pig"], $this->selectSorted("@e[family=animal]", $alice));
		self::assertSame(["item"], $this->select("@e[family=inanimate]", $alice));
		self::assertSame(["Pig"], $this->select("@e[family=pig]", $alice));
		self::assertSame(["Daisy", "item"], $this->selectSorted("@e[x=1,y=1,z=0,dx=2,dy=1,dz=2]", $alice));
		self::assertSame(["Cow"], $this->select("@r[type=cow,rm=10]", $alice), "@r can select entities when given a type");
		self::assertCount(1, $this->select("@e[c=1]", $alice));

		$near->addCommandTag("tagged");
		$far->addCommandTag("tagged");
		$far->addCommandTag("other");
		self::assertSame(["Cow", "Daisy"], $this->selectSorted("@e[tag=tagged]", $alice));
		self::assertSame(["Cow"], $this->select("@e[tag=tagged,tag=other]", $alice));
		self::assertSame(["Daisy"], $this->select("@e[tag=tagged,tag=!other]", $alice));
		self::assertSame(["Pig", "Zombie", "item"], $this->selectSorted("@e[tag=]", $alice), "tag= selects entities without tags");
		self::assertSame(["Cow", "Daisy"], $this->selectSorted("@e[tag=!]", $alice), "tag=! selects entities with any tag");
	}

	public function testNearestEntity() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$this->spawnCow(3.5, 0.5, "Daisy");
		$this->spawnCow(15.5, 0.5);
		$dead = $this->spawnCow(1.5, 0.5, "Dead cow");
		$dead->setHealth(0);
		$pig = new Pig($this->loc(-1.5, 1, 0.5));
		$pig->spawnToAll();
		$item = new ItemEntity($this->loc(30.5, 1, 0.5), VanillaItems::DIAMOND());
		$item->spawnToAll();

		self::assertSame(["Pig"], $this->select("@n", $alice), "nearest living entity of any type");
		self::assertSame(["Daisy"], $this->select("@n[type=cow]", $alice));
		self::assertSame(["Cow"], $this->select("@n[type=cow,name=!Daisy]", $alice));
		self::assertSame(["Daisy", "Cow"], $this->select("@n[type=cow,c=2]", $alice), "c selects more, nearest first");
		self::assertSame(["item"], $this->select("@n[c=-1]", $alice), "negative c: the farthest");
		self::assertSame(["Cow"], $this->select("@n[x=14,y=1,z=0]", $alice), "position arguments move the origin");
		self::assertSame([], $this->select("@n[type=zombie]", $alice));
		self::assertSame(["Pig"], $this->select("@n", $this->console()), "the console uses the world spawn");
	}

	public function testConsoleSender() : void{
		$this->player("Alice", 0.5, 1, 5.5);
		$this->player("Bob", 30.5, 1, 0.5);
		$console = $this->console();

		self::assertSame([], $this->select("@s", $console), "the console is no entity");
		self::assertSame(["Alice"], $this->select("@p", $console), "the console's origin is the world spawn");
		self::assertSame(["Alice", "Bob"], $this->selectSorted("@a", $console));
	}

	public function testCommandTargets() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$this->player("Bob", 10.5, 1, 0.5);
		$this->spawnCow(3.5, 0.5);

		self::assertCount(2, CommandTargets::players($alice, "@a"));
		self::assertSame("Bob", CommandTargets::player($alice, "bo")->getName(), "plain names are matched by prefix");
		self::assertCount(1, CommandTargets::entities($alice, "@e"), "mocked players are not world entities");

		foreach([
			["@e", KnownTranslationKeys::COMMANDS_GENERIC_TARGETNOTPLAYER],
			["@a[name=nobody]", KnownTranslationKeys::COMMANDS_GENERIC_NOTARGETMATCH],
			["nobody", KnownTranslationKeys::COMMANDS_GENERIC_NOTARGETMATCH],
			["@x", KnownTranslationKeys::COMMANDS_GENERIC_SYNTAX],
			["@n", KnownTranslationKeys::COMMANDS_GENERIC_TARGETNOTPLAYER],
		] as [$target, $key]){
			try{
				CommandTargets::players($alice, $target);
				self::fail("$target should fail");
			}catch(TargetSelectorException $e){
				self::assertSame($key, $e->getTranslatable()->getText(), $target);
			}
		}
		try{
			CommandTargets::player($alice, "@a");
			self::fail("@a matches two players");
		}catch(TargetSelectorException $e){
			self::assertSame(KnownTranslationKeys::COMMANDS_GENERIC_TOOMANYTARGETS, $e->getTranslatable()->getText());
		}

		self::assertSame("hi Alice and Bob, @x and @e[type=nothing]", CommandTargets::expandSelectors($alice, "hi @s and @a[name=Bob], @x and @e[type=nothing]"));
		self::assertSame("all: Alice, Bob!", CommandTargets::expandSelectors($alice, "all: @a[c=2]!"));
		self::assertSame("mail@a.b @abc", CommandTargets::expandSelectors($alice, "mail@a.b @abc"));
		self::assertSame("near: Cow", CommandTargets::expandSelectors($alice, "near: @n"));
	}
}
