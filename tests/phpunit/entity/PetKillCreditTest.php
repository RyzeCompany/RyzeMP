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

namespace pocketmine\entity;

use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Wolf;
use pocketmine\entity\monster\Phantom;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\PlayerOffHandInventory;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\VanillaItems;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;

/**
 * Hits from a tamed pet credit its owner for player-only drops and experience (Bedrock killed_by_player_or_pets),
 * without the owner's Looting.
 */
final class PetKillCreditTest extends MobTestCase{
	/**
	 * @var Player[]
	 * @phpstan-var list<Player>
	 */
	private array $players = [];

	protected function tearDown() : void{
		foreach($this->players as $player){
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		}
		$this->players = [];
		parent::tearDown();
	}

	private function owner() : Player{
		$location = new Location(0.5, 1, 3.5, $this->world, 0, 0);
		$player = $this->createMock(Player::class);
		$inventory = new PlayerInventory($player);
		$inventory->setItemInHand(VanillaItems::DIAMOND_SWORD()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::LOOTING(), 3)));
		$player->method("getWorld")->willReturn($this->world);
		$player->method("isAlive")->willReturn(true);
		$player->method("isConnected")->willReturn(true);
		$player->method("getGamemode")->willReturn(GameMode::SURVIVAL);
		$player->method("getLocation")->willReturn($location);
		$player->method("getPosition")->willReturn($location);
		$player->method("getId")->willReturn(7200001);
		$player->method("getArmorInventory")->willReturn(new ArmorInventory($player));
		$player->method("getInventory")->willReturn($inventory);
		$player->method("getOffHandInventory")->willReturn(new PlayerOffHandInventory($player));
		$player->method("getUniqueId")->willReturn(Uuid::uuid4());
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, $location);
		$this->players[] = $player;
		return $player;
	}

	public function testTamedWolfHitCountsAsPlayerHitWithoutLooting() : void{
		$this->floor(-8, -8, 8, 8);
		$owner = $this->owner();
		$wolf = new Wolf(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$wolf->setOwner($owner);
		$wolf->spawnToAll();
		$zombie = new Zombie(new Location(1.5, 1, 0.5, $this->world, 0, 0));
		$zombie->spawnToAll();

		self::assertFalse($zombie->wasRecentlyHitByPlayer());
		$zombie->attack(new EntityDamageByEntityEvent($wolf, $zombie, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 2));
		self::assertTrue($zombie->wasRecentlyHitByPlayer(), "the pet's hit credits the owner");
		self::assertSame(0, $zombie->getLootingLevel(), "the owner's Looting only applies to their own hits");

		(new \ReflectionProperty(Living::class, "attackTime"))->setValue($zombie, 0);
		(new \ReflectionProperty(Living::class, "noDamageTicks"))->setValue($zombie, 0);
		//the damager of the event is looked up by ID in the world
		$entities = new \ReflectionProperty(World::class, "entities");
		$worldEntities = $entities->getValue($this->world);
		self::assertIsArray($worldEntities);
		$worldEntities[$owner->getId()] = $owner;
		$entities->setValue($this->world, $worldEntities);
		$zombie->attack(new EntityDamageByEntityEvent($owner, $zombie, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 2));
		self::assertSame(3, $zombie->getLootingLevel());
	}

	public function testPetHitDoesNotGiveKilledByPlayerOnlyDrops() : void{
		$this->floor(-8, -8, 8, 8);
		$owner = $this->owner();
		$wolf = new Wolf(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$wolf->setOwner($owner);
		$wolf->spawnToAll();
		//phantom.json: the membrane pool is killed_by_player, so a pet's kill drops nothing
		$phantom = new Phantom(new Location(1.5, 2, 0.5, $this->world, 0, 0));
		$phantom->spawnToAll();
		$phantom->attack(new EntityDamageByEntityEvent($wolf, $phantom, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 2));
		self::assertTrue($phantom->wasRecentlyHitByPlayer(), "experience still counts the pet's hit");
		self::assertSame([], $phantom->getDrops());

		(new \ReflectionProperty(Living::class, "attackTime"))->setValue($phantom, 0);
		(new \ReflectionProperty(Living::class, "noDamageTicks"))->setValue($phantom, 0);
		$entities = new \ReflectionProperty(World::class, "entities");
		$worldEntities = $entities->getValue($this->world);
		self::assertIsArray($worldEntities);
		$worldEntities[$owner->getId()] = $owner;
		$entities->setValue($this->world, $worldEntities);
		$phantom->attack(new EntityDamageByEntityEvent($owner, $phantom, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 2));
		self::assertCount(1, $phantom->getDrops(), "the owner's own hit gives the membrane roll");
	}

	public function testWildWolfHitDoesNotCount() : void{
		$this->floor(-8, -8, 8, 8);
		$wolf = new Wolf(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$wolf->spawnToAll();
		$zombie = new Zombie(new Location(1.5, 1, 0.5, $this->world, 0, 0));
		$zombie->spawnToAll();
		$zombie->attack(new EntityDamageByEntityEvent($wolf, $zombie, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 2));
		self::assertFalse($zombie->wasRecentlyHitByPlayer());
	}
}
