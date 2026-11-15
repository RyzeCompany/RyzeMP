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

use PHPUnit\Framework\MockObject\MockObject;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\monster\Piglin;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\object\ArmorStand;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\projectile\EnderPearl;
use pocketmine\entity\projectile\Trident;
use pocketmine\inventory\ArmorInventory;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\world\Position;
use pocketmine\world\redstone\NullThreadSafeLogger;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * Entity persistence and owners: mob armor and projectile owners survive a save and reload, ender pearl rules, and the
 * Bedrock void (damage line, rate and floor).
 */
final class EntitySavingTest extends MobTestCase{

	/**
	 * Players returned by the mocked Server::getPlayerByUUID(), by UUID.
	 * @var Player[]
	 * @phpstan-var array<string, Player>
	 */
	private array $onlinePlayers = [];
	/**
	 * @var Player[]
	 * @phpstan-var list<Player>
	 */
	private array $mockPlayers = [];

	protected function setUp() : void{
		parent::setUp();
		$server = $this->world->getServer();
		self::assertInstanceOf(MockObject::class, $server);
		$server->method("getPlayerByUUID")->willReturnCallback(fn(UuidInterface $uuid) => $this->onlinePlayers[$uuid->toString()] ?? null);
	}

	protected function tearDown() : void{
		EnderPearl::setEndermiteChance(null);
		//mocked players never ran their constructor: they must not dispose of themselves when destroyed
		foreach($this->mockPlayers as $player){
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		}
		$this->mockPlayers = [];
		$this->onlinePlayers = [];
		parent::tearDown();
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * A connected player standing in the given world, built as a mock.
	 */
	private function player(UuidInterface $uuid, World $world, Vector3 $pos, int $id) : Player&MockObject{
		$player = $this->createMock(Player::class);
		$player->method("getId")->willReturn($id);
		$player->method("getUniqueId")->willReturn($uuid);
		$player->method("isConnected")->willReturn(true);
		$player->method("isClosed")->willReturn(false);
		$player->method("isAlive")->willReturn(true);
		$player->method("getWorld")->willReturn($world);
		$player->method("getPosition")->willReturn(Position::fromObject($pos, $world));
		$player->method("getEyePos")->willReturn($pos->add(0, 1.62, 0));
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new NullThreadSafeLogger());
		$this->mockPlayers[] = $player;
		return $player;
	}

	public function testMobArmorSurvivesSaveAndLoad() : void{
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$zombie->getArmorInventory()->setHelmet(VanillaItems::IRON_HELMET());
		$zombie->getArmorInventory()->setBoots(VanillaItems::DIAMOND_BOOTS());

		$nbt = $zombie->saveNBT();
		$armorTag = $nbt->getListTag("Armor", CompoundTag::class);
		self::assertNotNull($armorTag);
		self::assertCount(4, $armorTag, "helmet to boots, like BDS");

		$zombie->close();
		$loaded = new Zombie($this->loc(0.5, 1, 0.5), $nbt);
		$armor = $loaded->getArmorInventory();
		self::assertTrue($armor->getHelmet()->equalsExact(VanillaItems::IRON_HELMET()));
		self::assertTrue($armor->getChestplate()->isNull());
		self::assertTrue($armor->getLeggings()->isNull());
		self::assertTrue($armor->getBoots()->equalsExact(VanillaItems::DIAMOND_BOOTS()));
	}

	public function testMobWithoutArmorTagLoadsLikeBefore() : void{
		$zombie = new Zombie($this->loc(0.5, 1, 0.5), CompoundTag::create());
		self::assertCount(0, $zombie->getArmorInventory()->getContents());
	}

	public function testArmorStandAndPiglinKeepTheirFormat() : void{
		$stand = new ArmorStand($this->loc(0.5, 1, 0.5));
		$stand->getArmorInventory()->setChestplate(VanillaItems::GOLDEN_CHESTPLATE());
		$nbt = $stand->saveNBT();
		$stand->close();
		$loadedStand = new ArmorStand($this->loc(0.5, 1, 0.5), $nbt);
		self::assertTrue($loadedStand->getArmorInventory()->getChestplate()->equalsExact(VanillaItems::GOLDEN_CHESTPLATE()));
		self::assertCount(1, $loadedStand->getArmorInventory()->getContents());

		$piglin = new Piglin($this->loc(2.5, 1, 0.5));
		$piglin->getArmorInventory()->clearAll();
		$piglin->getArmorInventory()->setItem(ArmorInventory::SLOT_LEGS, VanillaItems::GOLDEN_LEGGINGS());
		$nbt = $piglin->saveNBT();
		self::assertSame(4, $nbt->getListTag(Piglin::TAG_ARMOR, CompoundTag::class)?->count());
		$piglin->close();
		$loadedPiglin = new Piglin($this->loc(2.5, 1, 0.5), $nbt);
		self::assertCount(1, $loadedPiglin->getArmorInventory()->getContents());
		self::assertTrue($loadedPiglin->getArmorInventory()->getLeggings()->equalsExact(VanillaItems::GOLDEN_LEGGINGS()));
	}

	public function testMobOwnerOfAProjectileIsFoundAfterReload() : void{
		$skeleton = new Skeleton($this->loc(0.5, 1, 0.5));
		$arrow = new Arrow($this->loc(5.5, 1, 5.5), $skeleton, false);
		self::assertSame($skeleton, $arrow->getOwningEntity());

		$arrowNbt = $arrow->saveNBT();
		self::assertSame($skeleton->getPersistentUniqueId(), $arrowNbt->getLong("OwnerID"));
		self::assertSame($skeleton->getPersistentUniqueId(), $skeleton->saveNBT()->getLong(Entity::TAG_UNIQUE_ID), "the owner saves the ID it is found by");
		$arrow->close();

		$loaded = new Arrow($this->loc(5.5, 1, 5.5), null, false, $arrowNbt);
		self::assertNull($loaded->getOwningEntityId(), "resolved lazily");
		self::assertSame($skeleton, $loaded->getOwningEntity());
		self::assertSame($skeleton->getId(), $loaded->getOwningEntityId(), "cached after the first lookup");
	}

	public function testMissingMobOwnerIsSearchedAtMostOnceASecond() : void{
		$skeleton = new Skeleton($this->loc(0.5, 1, 0.5));
		$arrowNbt = (new Arrow($this->loc(5.5, 1, 5.5), $skeleton, false))->saveNBT();
		$skeletonNbt = $skeleton->saveNBT();
		$skeleton->close();

		$loaded = new Arrow($this->loc(5.5, 1, 5.5), null, false, $arrowNbt);
		self::assertNull($loaded->getOwningEntity());
		//the owner's chunk loads: the next search, a second later, finds it
		$skeleton = new Skeleton($this->loc(0.5, 1, 0.5), $skeletonNbt);
		self::assertNull($loaded->getOwningEntity(), "not searched again within the same second");
		$this->tick += 20;
		self::assertSame($skeleton, $loaded->getOwningEntity());
	}

	public function testPlayerOwnerIsFoundByUuidAfterReconnecting() : void{
		$uuid = Uuid::uuid4();
		$thrower = $this->player($uuid, $this->world, new Vector3(0.5, 1, 0.5), 100_000);
		$arrow = new Arrow($this->loc(5.5, 1, 5.5), $thrower, false);
		$nbt = $arrow->saveNBT();
		self::assertSame($uuid->toString(), $nbt->getString("VanillaMineOwnerUUID"));
		self::assertNull($nbt->getTag("OwnerID"));
		$arrow->close();

		$loaded = new Arrow($this->loc(5.5, 1, 5.5), null, false, $nbt);
		self::assertNull($loaded->getOwningEntity(), "the thrower is offline");
		$this->onlinePlayers[$uuid->toString()] = $reconnected = $this->player($uuid, $this->world, new Vector3(0.5, 1, 0.5), 100_001);
		self::assertSame($reconnected, $loaded->getOwningEntity());
		self::assertSame(100_001, $loaded->getOwningEntityId());
	}

	public function testLoyaltyTridentReturnsToItsOwnerAfterReload() : void{
		$uuid = Uuid::uuid4();
		$item = VanillaItems::TRIDENT()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::LOYALTY(), 3));
		$thrower = $this->player($uuid, $this->world, new Vector3(0.5, 1, 0.5), 100_000);
		$trident = new Trident($this->loc(0.5, 20, 0.5), $item, $thrower);
		$nbt = $trident->saveNBT();
		$trident->close();

		//after a restart the thrower comes back, and the trident is below the world (where loyalty calls it back)
		$this->onlinePlayers[$uuid->toString()] = $this->player($uuid, $this->world, new Vector3(0.5, 1, 0.5), 100_001);
		$loaded = new Trident($this->loc(0.5, $this->world->getMinY() - 2, 0.5), $item, null, $nbt);
		$loaded->spawnToAll();
		$this->runTicks(2);
		self::assertTrue($loaded->isReturning(), "the loyalty trident flies back to its reconnected owner");
		self::assertFalse($loaded->isFlaggedForDespawn());
	}

	public function testEnderPearlTeleportsItsOwnerInTheSameWorld() : void{
		$owner = new IronGolem($this->loc(10.5, 1, 10.5));
		$owner->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true); //no stroll away from where it lands
		$pearl = new EnderPearl($this->loc(0.5, 3, 0.5), $owner);
		$pearl->setMotion(new Vector3(0, -0.5, 0));
		$pearl->spawnToAll();
		$this->runTicks(20);
		self::assertTrue($pearl->isClosed() || $pearl->isFlaggedForDespawn());
		self::assertEqualsWithDelta(0.5, $owner->getPosition()->x, 0.5, "teleported to the pearl");
	}

	public function testEnderPearlDoesNotTeleportADeadOwner() : void{
		$owner = new IronGolem($this->loc(10.5, 1, 10.5));
		$owner->setHealth(0);
		$pearl = new EnderPearl($this->loc(0.5, 3, 0.5), $owner);
		$pearl->setMotion(new Vector3(0, -0.5, 0));
		$pearl->spawnToAll();
		$this->runTicks(10);
		self::assertSame(10.5, $owner->getPosition()->x);
	}

	public function testEnderPearlDoesNotTeleportAnOwnerInAnotherWorld() : void{
		$uuid = Uuid::uuid4();
		$elsewhere = $this->createMock(World::class);
		$elsewhere->method("isLoaded")->willReturn(true);
		$owner = $this->player($uuid, $elsewhere, new Vector3(10.5, 1, 10.5), 100_000);
		$this->onlinePlayers[$uuid->toString()] = $owner;
		$owner->expects(self::never())->method("teleport");
		$owner->expects(self::never())->method("attack");
		$pearl = new EnderPearl($this->loc(0.5, 3, 0.5), $owner);
		$pearl->setMotion(new Vector3(0, -0.5, 0));
		$pearl->spawnToAll();
		$this->runTicks(20);
		self::assertTrue($pearl->isClosed() || $pearl->isFlaggedForDespawn());
	}

	public function testNoEndermiteWithMobSpawningOff() : void{
		EnderPearl::setEndermiteChance(1.0);
		$pearl = new EnderPearl($this->loc(0.5, 3, 0.5), null);
		$this->world->setMobSpawningEnabled(false);
		self::assertNull($pearl->trySpawnEndermite(new Vector3(0.5, 1, 0.5)));
		$this->world->setMobSpawningEnabled(true);
		self::assertNotNull($pearl->trySpawnEndermite(new Vector3(0.5, 1, 0.5)));
	}

	public function testVoidDamageStartsSixteenBlocksBelowTheWorld() : void{
		$minY = $this->world->getMinY();
		$above = new IronGolem($this->loc(0.5, $minY - 15.9, 0.5));
		$below = new IronGolem($this->loc(5.5, $minY - 16.2, 5.5));
		$above->spawnToAll();
		$below->spawnToAll();
		$this->runTicks(1);
		self::assertSame($above->getMaxHealth(), (int) $above->getHealth());
		self::assertSame($below->getMaxHealth() - 4, (int) $below->getHealth());
	}

	public function testVoidDealsFourDamageEveryTenTicksAndMobsStopOnTheVoidFloor() : void{
		$minY = $this->world->getMinY();
		$golem = new IronGolem($this->loc(0.5, $minY - 20, 0.5));
		$golem->spawnToAll();
		$hits = 0;
		$health = $golem->getHealth();
		for($i = 0; $i < 60; ++$i){
			$this->runTicks(1);
			if($golem->getHealth() < $health){
				++$hits;
				self::assertEqualsWithDelta(4, $health - $golem->getHealth(), 0.001);
				$health = $golem->getHealth();
			}
			self::assertGreaterThanOrEqual($minY - 41, $golem->getPosition()->y, "stopped on the void floor 40 blocks below the world");
		}
		self::assertSame(6, $hits, "60 ticks: 6 hits of 4");
		self::assertTrue($golem->isAlive());
		self::assertEqualsWithDelta($minY - 40, $golem->getPosition()->y, 0.5);
	}
}
