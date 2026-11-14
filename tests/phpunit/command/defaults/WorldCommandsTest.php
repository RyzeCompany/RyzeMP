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

use pocketmine\block\BlockTypeIds;
use pocketmine\block\PointedDripstone;
use pocketmine\block\utils\DripstoneThickness;
use pocketmine\command\selector\SelectorTestCase;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Horse;
use pocketmine\entity\Entity;
use pocketmine\entity\monster\Creeper;
use pocketmine\entity\monster\Slime;
use pocketmine\entity\Zombie;
use pocketmine\inventory\ArmorInventory;
use pocketmine\item\VanillaItems;
use pocketmine\lang\KnownTranslationKeys;
use function count;

/**
 * /summon, /replaceitem, /setblock, /fill, /damage and /ride in the test world.
 */
class WorldCommandsTest extends SelectorTestCase{

	/**
	 * @phpstan-template T of Entity
	 * @phpstan-param class-string<T> $class
	 * @phpstan-return list<T>
	 */
	private function entitiesOf(string $class) : array{
		$found = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof $class && !$entity->isClosed()){
				$found[] = $entity;
			}
		}
		return $found;
	}

	public function testSummonOverloadsAndEvents() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$this->runCommand(new SummonCommand(), $alice, "summon cow 5 1 5 90 0 minecraft:entity_born Calf");
		$cows = $this->entitiesOf(Cow::class);
		self::assertCount(1, $cows);
		self::assertTrue($cows[0]->isBaby());
		self::assertSame("Calf", $cows[0]->getNameTag());
		self::assertEqualsWithDelta(90.0, $cows[0]->getLocation()->yaw, 0.001);
		self::assertSame(KnownTranslationKeys::COMMANDS_SUMMON_SUCCESS, $this->lastMessage("Alice"));

		$this->runCommand(new SummonCommand(), $alice, "summon slime 3 1 3 0 0 spawn_large");
		$slimes = $this->entitiesOf(Slime::class);
		self::assertCount(1, $slimes);
		self::assertSame(4, $slimes[0]->getSlimeSize());

		$this->runCommand(new SummonCommand(), $alice, "summon creeper ~ ~ ~2 ~ ~ minecraft:become_charged");
		$creepers = $this->entitiesOf(Creeper::class);
		self::assertCount(1, $creepers);
		self::assertTrue($creepers[0]->isPowered());

		//<entityType> <nameTag> [spawnPos]
		$this->runCommand(new SummonCommand(), $alice, "summon zombie Bob 2 1 2");
		$zombies = $this->entitiesOf(Zombie::class);
		self::assertCount(1, $zombies);
		self::assertSame("Bob", $zombies[0]->getNameTag());
		self::assertEqualsWithDelta(2.0, $zombies[0]->getPosition()->x, 0.001);

		//unknown events are ignored, like BDS
		$this->runCommand(new SummonCommand(), $alice, "summon zombie 2 1 2 0 0 foo:bar");
		self::assertCount(2, $this->entitiesOf(Zombie::class));

		$this->runCommand(new SummonCommand(), $alice, "summon not_an_entity 1 1 1");
		self::assertSame(KnownTranslationKeys::COMMANDS_GENERIC_SYNTAX, $this->lastMessage("Alice"));
	}

	public function testSummonEventNeedsRotations() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$this->expectException(InvalidCommandSyntaxException::class);
		$this->runCommand(new SummonCommand(), $alice, "summon cow 1 1 1 minecraft:entity_born");
	}

	public function testReplaceItem() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$this->runCommand(new ReplaceItemCommand(), $alice, "replaceitem entity Alice slot.armor.head 0 iron_helmet");
		self::assertTrue($alice->getArmorInventory()->getItem(ArmorInventory::SLOT_HEAD)->equals(VanillaItems::IRON_HELMET(), true, false));
		$this->runCommand(new ReplaceItemCommand(), $alice, "replaceitem entity Alice slot.inventory 3 diamond 5");
		self::assertSame(5, $alice->getInventory()->getItem(12)->getCount());
		$this->runCommand(new ReplaceItemCommand(), $alice, "replaceitem entity Alice slot.hotbar 9 stone");
		self::assertSame(KnownTranslationKeys::COMMANDS_REPLACEITEM_BADSLOTNUMBER, $this->lastMessage("Alice"));
		$this->runCommand(new ReplaceItemCommand(), $alice, "replaceitem entity Alice slot.inventory 3 keep dirt");
		self::assertSame(KnownTranslationKeys::COMMANDS_REPLACEITEM_FAILED, $this->lastMessage("Alice"));
		self::assertSame(5, $alice->getInventory()->getItem(12)->getCount(), "keep leaves an occupied slot alone");
		$this->runCommand(new ReplaceItemCommand(), $alice, "replaceitem entity Alice slot.inventory 4 keep dirt");
		self::assertSame(BlockTypeIds::DIRT, $alice->getInventory()->getItem(13)->getBlock()->getTypeId());

		$zombie = new Zombie($this->loc(3.5, 1, 0.5));
		$zombie->spawnToAll();
		$this->runCommand(new ReplaceItemCommand(), $alice, "replaceitem entity @e[type=zombie] slot.weapon.mainhand 0 iron_sword");
		self::assertTrue($zombie->getMainHandItem()->equals(VanillaItems::IRON_SWORD(), true, false));
		$this->runCommand(new ReplaceItemCommand(), $alice, "replaceitem entity @e[type=zombie] slot.armor.feet 0 iron_boots");
		self::assertFalse($zombie->getArmorInventory()->getBoots()->isNull());
		$this->runCommand(new ReplaceItemCommand(), $alice, "replaceitem entity @e[type=zombie] slot.hotbar 0 stone");
		self::assertSame(KnownTranslationKeys::COMMANDS_REPLACEITEM_FAILED, $this->lastMessage("Alice"), "mobs have no hotbar");

		self::assertNull(ReplaceItemCommand::resolveEntitySlot($zombie, ReplaceItemCommand::SLOT_ENDERCHEST, 0));
		self::assertSame([$zombie->getArmorInventory(), ArmorInventory::SLOT_CHEST], ReplaceItemCommand::resolveEntitySlot($zombie, ReplaceItemCommand::SLOT_CHEST, 0));
	}

	public function testSetBlockAndFill() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$this->runCommand(new SetBlockCommand(), $alice, 'setblock 2 1 2 pointed_dripstone ["dripstone_thickness"="frustum","hanging"=false]');
		self::assertSame(KnownTranslationKeys::COMMANDS_SETBLOCK_SUCCESS, $this->lastMessage("Alice"));
		$this->world->doTick(1);
		$block = $this->world->getBlockAt(2, 1, 2);
		self::assertInstanceOf(PointedDripstone::class, $block);
		self::assertSame(DripstoneThickness::FRUSTUM, $block->getThickness(), "the placed block keeps its states, like BDS");

		$this->runCommand(new SetBlockCommand(), $alice, "setblock 2 1 2 stone keep");
		self::assertSame(KnownTranslationKeys::COMMANDS_SETBLOCK_NOCHANGE, $this->lastMessage("Alice"));
		$this->runCommand(new SetBlockCommand(), $alice, "setblock 3 1 3 wooden_door");
		self::assertSame(BlockTypeIds::OAK_DOOR, $this->world->getBlockAt(3, 2, 3)->getTypeId(), "the upper half too");

		$this->runCommand(new FillCommand(), $alice, "fill 0 1 5 2 3 7 dirt hollow");
		self::assertSame(KnownTranslationKeys::COMMANDS_FILL_SUCCESS, $this->lastMessage("Alice"));
		self::assertSame(BlockTypeIds::DIRT, $this->world->getBlockAt(0, 1, 5)->getTypeId());
		self::assertSame(BlockTypeIds::AIR, $this->world->getBlockAt(1, 2, 6)->getTypeId());
		$this->runCommand(new FillCommand(), $alice, "fill 0 1 5 2 3 7 glass replace dirt");
		self::assertSame(BlockTypeIds::GLASS, $this->world->getBlockAt(2, 3, 7)->getTypeId());
		self::assertSame(BlockTypeIds::AIR, $this->world->getBlockAt(1, 2, 6)->getTypeId());
		$this->runCommand(new FillCommand(), $alice, "fill 0 1 0 40 41 40 stone");
		self::assertSame(KnownTranslationKeys::COMMANDS_FILL_TOOMANYBLOCKS, $this->lastMessage("Alice"));
	}

	public function testDamage() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$cow = new Cow($this->loc(3.5, 1, 0.5));
		$cow->spawnToAll();
		$health = $cow->getHealth();
		$this->runCommand(new DamageCommand(), $alice, "damage @e[type=cow] 3 fall");
		self::assertSame(KnownTranslationKeys::COMMANDS_DAMAGE_SUCCESS, $this->lastMessage("Alice"));
		self::assertEqualsWithDelta($health - 3, $cow->getHealth(), 0.001);
		$this->runCommand(new DamageCommand(), $alice, "damage @e[type=cow] -1");
		self::assertSame(KnownTranslationKeys::COMMANDS_GENERIC_NUM_TOOSMALL, $this->lastMessage("Alice"));
		$this->expectException(InvalidCommandSyntaxException::class);
		$this->runCommand(new DamageCommand(), $alice, "damage @e[type=cow] 1 not_a_cause");
	}

	public function testRide() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$horse = new Horse($this->loc(3.5, 1, 0.5));
		$horse->spawnToAll();
		$zombie = new Zombie($this->loc(5.5, 1, 0.5));
		$zombie->spawnToAll();

		$this->runCommand(new RideCommand(), $alice, "ride @e[type=zombie] start_riding @e[type=horse]");
		self::assertSame(KnownTranslationKeys::COMMANDS_RIDE_STARTRIDING_FAILURE, $this->lastMessage("Alice"), "zombies don't ride horses");
		$this->runCommand(new RideCommand(), $alice, "ride @e[type=horse] start_riding @e[type=zombie]");
		self::assertSame(KnownTranslationKeys::COMMANDS_RIDE_STARTRIDING_NOTRIDEABLE, $this->lastMessage("Alice"));
		$count = count($this->messagesOf("Alice"));
		$this->runCommand(new RideCommand(), $alice, "ride @e[type=zombie] stop_riding");
		self::assertCount($count, $this->messagesOf("Alice"), "nothing to stop: no message, like BDS");

		$before = count($this->entitiesOf(Horse::class));
		$this->runCommand(new RideCommand(), $alice, "ride @e[type=zombie] summon_ride horse");
		self::assertCount($before + 1, $this->entitiesOf(Horse::class), "the ride is summoned even if the rider can't get on");
		self::assertSame(KnownTranslationKeys::COMMANDS_RIDE_SUMMONRIDE_FAILED, $this->lastMessage("Alice"));

		try{
			$this->runCommand(new RideCommand(), $alice, "ride @e[type=zombie] fly_away");
			self::fail("syntax error expected");
		}catch(InvalidCommandSyntaxException){
			//expected
		}
	}

}
