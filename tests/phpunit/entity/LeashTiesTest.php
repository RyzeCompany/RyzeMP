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

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\HappyGhast;
use pocketmine\entity\aquatic\Nautilus;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\object\LeashKnot;
use pocketmine\entity\vehicle\Boat;
use pocketmine\inventory\PlayerInventory;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\player\Player;
use pocketmine\world\Position;
use Ramsey\Uuid\Uuid;
use function array_filter;
use function count;

/**
 * Leads on boats, leads tied to boats, happy ghasts and other leashable_to mobs, per-entity lead lengths and shears
 * cutting leads (C-04).
 */
final class LeashTiesTest extends MobTestCase{
	private Vector3 $playerPos;
	private bool $sneaking = false;

	private function player(Item $held) : Player{
		$this->playerPos = new Vector3(0.5, 1, 3.5);
		$player = $this->createMock(Player::class);
		$inventory = new PlayerInventory($player);
		$inventory->setItemInHand($held);
		$uuid = Uuid::uuid4();
		$player->method("getInventory")->willReturn($inventory);
		$player->method("hasFiniteResources")->willReturn(true);
		$player->method("getUniqueId")->willReturn($uuid);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("isAlive")->willReturn(true);
		$player->method("isSneaking")->willReturnCallback(fn() => $this->sneaking);
		$player->method("getId")->willReturn(999998);
		$player->method("getPosition")->willReturnCallback(fn() => Position::fromObject($this->playerPos, $this->world));
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	private function droppedLeads() : int{
		return count(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof ItemEntity && !$e->isFlaggedForDespawn() && $e->getItem()->getTypeId() === ItemTypeIds::LEAD));
	}

	private static function leadHolderId(Entity $entity) : int{
		$property = $entity->getNetworkProperties()->getAll()[EntityMetadataProperties::LEAD_HOLDER_EID] ?? null;
		self::assertInstanceOf(LongMetadataProperty::class, $property);
		return $property->getValue();
	}

	private function water(int $minX, int $maxX, int $minZ, int $maxZ) : void{
		for($x = $minX; $x <= $maxX; ++$x){
			for($z = $minZ; $z <= $maxZ; ++$z){
				$this->set($x, 1, $z, VanillaBlocks::WATER());
			}
		}
	}

	public function testBoatCanBeLeashedAndFollows() : void{
		$this->water(-3, 3, -3, 16);
		$player = $this->player(VanillaItems::LEAD());
		$boat = new Boat(new Location(0.5, 1.5, 0.5, $this->world, 0, 0));
		$boat->spawnToAll();
		self::assertTrue($boat->onInteract($player, Vector3::zero()));
		self::assertSame($player, $boat->getLeashHolder());
		self::assertTrue($player->getInventory()->getItemInHand()->isNull(), "lead used");
		self::assertNull($boat->getVehicle());
		$this->runTicks(1);
		self::assertSame(999998, self::leadHolderId($boat));
		self::assertNotSame("", $boat->saveNBT()->getString(Mob::TAG_LEASH_PLAYER, ""), "the holder is saved");

		$this->playerPos = new Vector3(0.5, 1.5, 9.5);
		$this->runTicks(60);
		self::assertTrue($boat->isLeashed());
		self::assertGreaterThan(3.0, $boat->getPosition()->z, "the lead drew the boat towards the player");

		//using it again lets go
		self::assertTrue($boat->onInteract($player, Vector3::zero()));
		self::assertFalse($boat->isLeashed());
		self::assertSame(1, $this->droppedLeads());
		$boat->close();
	}

	public function testLeashedCowTiedToBoat() : void{
		$player = $this->player(VanillaItems::LEAD());
		$cow = new Cow(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$cow->onInteract($player, Vector3::zero());
		self::assertSame($player, $cow->getLeashHolder());

		$boat = new Boat(new Location(2.5, 1, 2.5, $this->world, 0, 0));
		$boat->spawnToAll();
		$this->sneaking = true;
		self::assertTrue($boat->onInteract($player, Vector3::zero()));
		self::assertSame($boat, $cow->getLeashHolder(), "the cow is tied to the boat");
		self::assertNull($player->getVehicle());
		self::assertSame($boat->getPersistentUniqueId(), $cow->saveNBT()->getLong(Mob::TAG_LEASH_ENTITY));

		//breaking the boat lets the cow go
		$boat->breakBoat(false);
		$this->runTicks(2);
		self::assertFalse($cow->isLeashed());
		$cow->close();
	}

	public function testBoatByItsKnotSleeps() : void{
		$this->set(0, 1, 3, VanillaBlocks::OAK_FENCE());
		$knot = LeashKnot::getOrCreate($this->world, new Vector3(0, 1, 3));
		$boat = new Boat(new Location(0.5, 1, 2.5, $this->world, 0, 0));
		$boat->spawnToAll();
		$boat->setLeashHolder($knot);
		$needsTicks = new \ReflectionMethod(Boat::class, "needsLeashTicks");
		self::assertFalse($needsTicks->invoke($boat), "resting by its knot");
		$boat->teleport(new Vector3(0.5, 1, 6.5));
		self::assertTrue($needsTicks->invoke($boat), "drawn back to its knot");

		//knots let go of boats themselves when they break
		$knot->untie();
		self::assertFalse($boat->isLeashed());
		$boat->close();
	}

	public function testTyingNeedsSneaking() : void{
		$player = $this->player(VanillaItems::LEAD()->setCount(2));
		$cowA = new Cow(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$cowB = new Cow(new Location(2.5, 1, 0.5, $this->world, 0, 0));
		$cowA->onInteract($player, Vector3::zero());
		$cowB->onInteract($player, Vector3::zero());
		self::assertSame(2, LeashInteractions::getLeadCount($player));

		//tapping one of two leashed cows lets it go instead of tying the other to it
		self::assertTrue($cowA->onInteract($player, Vector3::zero()));
		self::assertFalse($cowA->isLeashed());
		self::assertSame($player, $cowB->getLeashHolder());
		self::assertSame(1, LeashInteractions::getLeadCount($player));

		//tapping a boat without sneaking doesn't tie the cow to it
		$boat = new Boat(new Location(2.5, 1, 2.5, $this->world, 0, 0));
		$boat->spawnToAll();
		$boat->onInteract($player, Vector3::zero());
		self::assertSame($player, $cowB->getLeashHolder());
		self::assertFalse($boat->isLeashed());

		$cowB->unleash(false);
		self::assertSame(0, LeashInteractions::getLeadCount($player));
		$cowA->close();
		$cowB->close();
		$boat->close();
	}

	public function testLeashedCowTiedToAnotherMobOnlyIfAdult() : void{
		$player = $this->player(VanillaItems::LEAD());
		$cow = new Cow(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$cow->onInteract($player, Vector3::zero());
		$calf = new Cow(new Location(2.5, 1, 0.5, $this->world, 0, 0));
		$calf->makeSpawnBaby();
		$this->sneaking = true;
		self::assertFalse(LeashInteractions::isLeashableTo($calf));
		$calf->onInteract($player, Vector3::zero());
		self::assertSame($player, $cow->getLeashHolder());

		$adult = new Cow(new Location(2.5, 1, 2.5, $this->world, 0, 0));
		self::assertTrue($adult->onInteract($player, Vector3::zero()));
		self::assertSame($adult, $cow->getLeashHolder());
		self::assertFalse($adult->isLeashed(), "the lead in hand stays in hand");
		$cow->close();
		$calf->close();
		$adult->close();
	}

	public function testHappyGhastAndNautilusPresets() : void{
		$ghast = new HappyGhast(new Location(0.5, 4, 0.5, $this->world, 0, 0));
		self::assertSame(14.0, $ghast->getLeashBreakDistance());
		$nautilus = new Nautilus(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		self::assertSame(14.0, $nautilus->getLeashBreakDistance());
		$cow = new Cow(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		self::assertSame(Mob::LEASH_BREAK_DISTANCE, $cow->getLeashBreakDistance());

		//a ghast led from 12 blocks away keeps its lead (a cow would lose it)
		$player = $this->player(VanillaItems::LEAD());
		self::assertTrue($ghast->onInteract($player, Vector3::zero()));
		self::assertTrue($ghast->isLeashed());
		$this->playerPos = $ghast->getPosition()->add(0, 0, 12);
		$this->runTicks(2);
		self::assertTrue($ghast->isLeashed());

		//a boat tied to the ghast
		$player2 = $this->player(VanillaItems::LEAD());
		$this->playerPos = $ghast->getPosition()->add(0, -2, 1);
		$boat = new Boat(new Location($ghast->getPosition()->x + 2, 1, $ghast->getPosition()->z, $this->world, 0, 0));
		$boat->spawnToAll();
		$boat->onInteract($player2, Vector3::zero());
		self::assertSame($player2, $boat->getLeashHolder());
		self::assertTrue(LeashInteractions::isLeashableTo($ghast));
		$this->sneaking = true;
		self::assertTrue($ghast->onInteract($player2, Vector3::zero()));
		self::assertSame($ghast, $boat->getLeashHolder(), "happy ghasts tow boats");
		$ghast->close();
		$nautilus->close();
		$cow->close();
		$boat->close();
	}

	public function testShearsCutALead() : void{
		$player = $this->player(VanillaItems::LEAD());
		$cow = new Cow(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$cow->onInteract($player, Vector3::zero());
		$player->getInventory()->setItemInHand(VanillaItems::SHEARS());
		self::assertTrue($cow->onInteract($player, Vector3::zero()));
		self::assertFalse($cow->isLeashed());
		self::assertSame(1, $this->droppedLeads());
		$shears = $player->getInventory()->getItemInHand();
		self::assertInstanceOf(Durable::class, $shears);
		self::assertSame(1, $shears->getDamage());

		//shears used on a holder cut the leads it holds
		$player->getInventory()->setItemInHand(VanillaItems::LEAD());
		$cow->onInteract($player, Vector3::zero());
		$boat = new Boat(new Location(2.5, 1, 2.5, $this->world, 0, 0));
		$boat->spawnToAll();
		$this->sneaking = true;
		$boat->onInteract($player, Vector3::zero());
		self::assertSame($boat, $cow->getLeashHolder());
		$this->sneaking = false;
		$player->getInventory()->setItemInHand(VanillaItems::SHEARS());
		self::assertTrue($boat->onInteract($player, Vector3::zero()));
		self::assertFalse($cow->isLeashed());
		self::assertSame(2, $this->droppedLeads());
		$cow->close();
		$boat->close();
	}
}
