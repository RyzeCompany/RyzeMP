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
use pocketmine\command\utils\CommandItemParser;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\data\bedrock\PotionTypeIdMap;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\Attribute;
use pocketmine\entity\AttributeFactory;
use pocketmine\entity\AttributeMap;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Location;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Potion;
use pocketmine\item\VanillaItems;
use pocketmine\lang\KnownTranslationKeys;
use pocketmine\math\Vector3;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\world\Position;
use function array_key_first;

/**
 * Bedrock command syntaxes advertised to clients (see getOverloads() of the commands) are accepted.
 */
class VanillaSyntaxCommandsTest extends SelectorTestCase{

	/**
	 * Locations the player given to captureTeleports() was teleported to.
	 * @var Location[]
	 * @phpstan-var list<Location>
	 */
	private array $teleports = [];

	/**
	 * @param Player&MockObject $player
	 */
	private function captureTeleports(Player $player) : void{
		$this->teleports = [];
		$player->method("teleport")->willReturnCallback(function(Vector3 $pos) : bool{
			self::assertInstanceOf(Location::class, $pos);
			$this->teleports[] = $pos;
			return true;
		});
	}

	private function assertVector(Vector3 $expected, Vector3 $actual) : void{
		self::assertEqualsWithDelta($expected->x, $actual->x, 0.001);
		self::assertEqualsWithDelta($expected->y, $actual->y, 0.001);
		self::assertEqualsWithDelta($expected->z, $actual->z, 0.001);
	}

	public function testTeleportLocalCoordinates() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5); //facing south (+z)
		$this->captureTeleports($alice);
		$this->runCommand(new TeleportCommand(), $alice, "tp ^ ^ ^5");
		$this->assertVector(new Vector3(0.5, 1, 5.5), $this->teleports[0]);
		$this->runCommand(new TeleportCommand(), $alice, "tp @s ^2 ^1 ^");
		$this->assertVector(new Vector3(2.5, 2, 0.5), $this->teleports[1]); //left of south is east (+x)
	}

	public function testTeleportLocalCoordinatesFollowRotation() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5, yaw: 90.0); //facing west (-x)
		$this->captureTeleports($alice);
		$this->runCommand(new TeleportCommand(), $alice, "tp ^ ^ ^3");
		$this->assertVector(new Vector3(-2.5, 1, 0.5), $this->teleports[0]);
	}

	public function testTeleportMixedLocalCoordinatesAreRejected() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$this->expectException(InvalidCommandSyntaxException::class);
		$this->runCommand(new TeleportCommand(), $alice, "tp ^ ~ ^");
	}

	public function testTeleportFacingAndRotation() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$bob = $this->player("Bob", 10.5, 1, 0.5);
		$bob->method("getEyePos")->willReturn(new Vector3(10.5, 1, 0));
		$this->captureTeleports($alice);

		$this->runCommand(new TeleportCommand(), $alice, "tp 0 1 0 facing 0 1 10");
		$this->assertVector(new Vector3(0, 1, 0), $this->teleports[0]);
		self::assertEqualsWithDelta(0.0, $this->teleports[0]->yaw, 0.001, "looking south");

		$this->runCommand(new TeleportCommand(), $alice, "tp Alice 0 1 0 facing Bob true");
		self::assertEqualsWithDelta(270.0, $this->teleports[1]->yaw, 0.001, "looking east, at Bob");

		$this->runCommand(new TeleportCommand(), $alice, "tp ~ ~ ~ 90 -45 false");
		self::assertEqualsWithDelta(90.0, $this->teleports[2]->yaw, 0.001);
		self::assertEqualsWithDelta(-45.0, $this->teleports[2]->pitch, 0.001);

		$this->runCommand(new TeleportCommand(), $alice, "tp Bob true");
		$this->assertVector(new Vector3(10.5, 1, 0.5), $this->teleports[3]);
		self::assertCount(4, $this->teleports);
	}

	public function testTeleportFacingNeedsAPositionOrTarget() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$this->expectException(InvalidCommandSyntaxException::class);
		$this->runCommand(new TeleportCommand(), $alice, "tp 0 1 0 facing 1 2");
	}

	public function testSpawnpointAndWorldSpawnLocalCoordinates() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$alice->expects(self::once())->method("setSpawn")->willReturnCallback(function(Vector3 $pos) : void{
			self::assertInstanceOf(Position::class, $pos);
			$this->assertVector(new Vector3(0.5, 1, 2.5), $pos);
		});
		$this->runCommand(new SpawnpointCommand(), $alice, "spawnpoint @s ^ ^ ^2");

		$this->runCommand(new SetWorldSpawnCommand(), $alice, "setworldspawn ^ ^ ^3");
		self::assertSame(KnownTranslationKeys::COMMANDS_SETWORLDSPAWN_SUCCESS, $this->lastMessage("Alice"));
	}

	public function testWorldSpawnRejectsMixedCoordinates() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$this->expectException(InvalidCommandSyntaxException::class);
		$this->runCommand(new SetWorldSpawnCommand(), $alice, "setworldspawn ~1 ~ ^");
	}

	public function testXpLevelsPostfix() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$attributes = new AttributeMap();
		$attributes->add(AttributeFactory::getInstance()->mustGet(Attribute::EXPERIENCE_LEVEL));
		$alice->method("getAttributeMap")->willReturn($attributes);
		$xp = $alice->getXpManager();
		self::assertInstanceOf(MockObject::class, $xp);
		$xp->expects(self::once())->method("addXpLevels")->with(5, false);
		$xp->expects(self::once())->method("addXp")->with(20, false);
		$this->runCommand(new XpCommand(), $alice, "xp 5L @s");
		$this->runCommand(new XpCommand(), $alice, "xp 20");
	}

	public function testXpRejectsWords() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$this->expectException(InvalidCommandSyntaxException::class);
		$this->runCommand(new XpCommand(), $alice, "xp lots");
	}

	public function testGiveVanillaIdsDataAndComponents() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$inventory = $alice->getInventory();

		$this->runCommand(new GiveCommand(), $alice, "give @s minecraft:white_terracotta 2");
		//a vanilla item ID without a PocketMine alias
		self::assertTrue($inventory->contains(CommandItemParser::parse("white_terracotta")->setCount(2)));

		$inventory->clearAll();
		$healing = PotionTypeIdMap::getInstance()->fromId(21);
		self::assertNotNull($healing);
		$this->runCommand(new GiveCommand(), $alice, "give @s potion 1 21");
		$potion = $inventory->getItem(0);
		self::assertInstanceOf(Potion::class, $potion);
		self::assertSame($healing, $potion->getType());

		$inventory->clearAll();
		$this->runCommand(new GiveCommand(), $alice, 'give @s diamond_pickaxe 1 0 {"minecraft:can_destroy":{"blocks":["stone"]},"minecraft:keep_on_death":{}}');
		$pickaxe = $inventory->getItem(0);
		self::assertSame(["stone" => "stone"], $pickaxe->getCanDestroy());
		self::assertTrue($pickaxe->keepOnDeath());

		$inventory->clearAll();
		$this->runCommand(new GiveCommand(), $alice, 'give @s apple 1 {"minecraft:item_lock":{"mode":"lock_in_inventory"}}');
		self::assertSame(2, $inventory->getItem(0)->getNamedTag()->getByte("minecraft:item_lock"));

		$this->runCommand(new GiveCommand(), $alice, 'give @s apple 1 {"minecraft:unknown":{}}');
		self::assertSame(KnownTranslationKeys::COMMANDS_GIVE_TAGERROR, $this->lastMessage("Alice"));
	}

	public function testClearWithData() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$this->runCommand(new GiveCommand(), $alice, "give @s potion 1 21");
		$this->runCommand(new GiveCommand(), $alice, "give @s potion 1 0");
		$this->runCommand(new ClearCommand(), $alice, "clear @s potion 21 1");
		$contents = $alice->getInventory()->getContents();
		self::assertCount(1, $contents);
		$left = $contents[array_key_first($contents)];
		self::assertInstanceOf(Potion::class, $left);
		self::assertNotSame(PotionTypeIdMap::getInstance()->fromId(21), $left->getType(), "only the healing potion is cleared");
	}

	public function testEffectClearOneEffect() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$cow = new Cow($this->loc(5.5, 1, 0.5));
		$cow->spawnToAll();
		$this->runCommand(new EffectCommand(), $alice, "effect @e[type=cow] speed 10");
		$this->runCommand(new EffectCommand(), $alice, "effect @e[type=cow] haste infinite 1 true");
		$this->runCommand(new EffectCommand(), $alice, "effect @e[type=cow] clear speed");
		self::assertFalse($cow->getEffects()->has(VanillaEffects::SPEED()));
		self::assertTrue($cow->getEffects()->has(VanillaEffects::HASTE()));
	}

	public function testEnchantById() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$alice->getInventory()->setItemInHand(VanillaItems::DIAMOND_SWORD());
		$this->runCommand(new EnchantCommand(), $alice, "enchant @s 9 2"); //sharpness
		self::assertSame(2, $alice->getInventory()->getItemInHand()->getEnchantmentLevel(VanillaEnchantments::SHARPNESS()));
	}

	public function testGamemodeBedrockValues() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5, GameMode::CREATIVE);
		$alice->expects(self::exactly(2))->method("setGamemode")->with(self::logicalOr(GameMode::SPECTATOR, GameMode::SURVIVAL));
		$this->runCommand(new GamemodeCommand(), $alice, "gamemode 6");
		$this->runCommand(new GamemodeCommand(), $alice, "gamemode d @s"); //the server's default: survival
	}

	public function testTimeQueryValues() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		foreach(["daytime", "gametime", "day"] as $query){
			$this->runCommand(new TimeCommand(), $alice, "time query $query");
		}
		$this->runCommand(new TimeCommand(), $alice, "time set Noon");
		self::assertSame(6000, $this->world->getTimeOfDay());
		$this->expectException(InvalidCommandSyntaxException::class);
		$this->runCommand(new TimeCommand(), $alice, "time query week");
	}
}
