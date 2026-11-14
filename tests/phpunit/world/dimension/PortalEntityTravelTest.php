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

namespace pocketmine\world\dimension;

use pocketmine\block\BlockTypeIds;
use pocketmine\block\tile\EndGateway as TileEndGateway;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\animal\Pig;
use pocketmine\entity\animal\TameableAnimal;
use pocketmine\entity\animal\Wolf;
use pocketmine\entity\boss\EnderDragon;
use pocketmine\entity\boss\Wither;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\vehicle\Minecart;
use pocketmine\entity\vehicle\MinecartChest;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\event\RegisteredListener;
use pocketmine\item\VanillaItems;
use pocketmine\math\Axis;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\plugin\Plugin;
use pocketmine\timings\TimingsHandler;
use pocketmine\world\World;
use pocketmine\world\WorldManager;
use Ramsey\Uuid\Uuid;
use function array_filter;
use function array_values;

/**
 * Entities other than players going through Nether portals, End portals and End gateways (see PortalTravel).
 */
final class PortalEntityTravelTest extends DimensionWorldTestCase{
	private World $nether;
	private World $end;

	protected function setUp() : void{
		parent::setUp();
		$this->nether = $this->createWorld("dimension_test_nether", Dimension::NETHER);
		$this->end = $this->createWorld("dimension_test_the_end", Dimension::END);
		$worlds = ["dimension_test" => $this->world, "dimension_test_nether" => $this->nether, "dimension_test_the_end" => $this->end];
		$worldManager = $this->createMock(WorldManager::class);
		$worldManager->method("getWorldByName")->willReturnCallback(fn(string $name) => $worlds[$name] ?? null);
		$worldManager->method("findEntity")->willReturnCallback(function(int $id) use ($worlds) : ?Entity{
			foreach($worlds as $world){
				if(($entity = $world->getEntity($id)) !== null){
					return $entity;
				}
			}
			return null;
		});
		$this->server->method("getWorldManager")->willReturn($worldManager);
	}

	/**
	 * Builds and lights a 2x3 portal standing on the given axis whose bottom-left interior block is at x,y,z.
	 */
	private function portal(World $world, int $x, int $y, int $z, int $axis = Axis::X) : NetherPortalShape{
		[$ax, $az] = $axis === Axis::X ? [1, 0] : [0, 1];
		$obsidian = VanillaBlocks::OBSIDIAN();
		for($w = -1; $w <= 2; ++$w){
			for($h = -1; $h <= 3; ++$h){
				if($w === -1 || $w === 2 || $h === -1 || $h === 3){
					$world->setBlockAt($x + $w * $ax, $y + $h, $z + $w * $az, $obsidian);
				}
			}
		}
		self::assertTrue(NetherPortalShape::tryLight($world, new Vector3($x, $y, $z)));
		$shape = NetherPortalShape::findLit($world, new Vector3($x, $y, $z));
		self::assertNotNull($shape);
		return $shape;
	}

	/**
	 * @return Entity[]
	 * @phpstan-return list<Entity>
	 */
	private function entitiesOf(World $world, string $class) : array{
		return array_values(array_filter($world->getEntities(), fn(Entity $e) => $e instanceof $class && !$e->isClosed() && !$e->isFlaggedForDespawn()));
	}

	private function droppedItem(World $world, float $x, float $y, float $z, Vector3 $motion) : ItemEntity{
		$item = new ItemEntity(new Location($x, $y, $z, $world, 0, 0), VanillaItems::DIAMOND()->setCount(5)->setCustomName("Shiny"));
		$item->setMotion($motion);
		$item->spawnToAll();
		return $item;
	}

	public function testItemThroughNetherPortalComesOutOfTheLinkedPortal() : void{
		$this->portal($this->world, 0, 10, 0);
		$this->portal($this->nether, 0, 40, 0);
		$item = $this->droppedItem($this->world, 0.7, 10.2, 0.5, new Vector3(0, 0, 0.2));

		PortalTravel::getInstance()->onEntityInsidePortal($item, Dimension::NETHER, new Vector3(0, 10, 0));
		self::assertTrue($item->isFlaggedForDespawn(), "the original goes away");
		self::assertFalse($item->canSaveWithChunk(), "and is never saved again");

		$arrived = $this->entitiesOf($this->nether, ItemEntity::class);
		self::assertCount(1, $arrived);
		$copy = $arrived[0];
		self::assertInstanceOf(ItemEntity::class, $copy);
		self::assertSame(5, $copy->getItem()->getCount());
		self::assertSame("Shiny", $copy->getItem()->getCustomName());
		//same place relative to the portal: 0.2 blocks into the 0.25 wide item's room along it, at the bottom
		$pos = $copy->getPosition();
		self::assertEqualsWithDelta(0.7, $pos->x, 0.001);
		self::assertEqualsWithDelta(40.2, $pos->y, 0.001);
		self::assertEqualsWithDelta(0.5, $pos->z, 0.001);
		self::assertEqualsWithDelta(0.2, $copy->getMotion()->z, 0.001, "keeps its motion");

		$this->runTicks(1);
		self::assertTrue($item->isClosed());
	}

	public function testItemFallingIntoAPortalTravelsDuringItsTick() : void{
		$this->portal($this->world, 0, 10, 0);
		$this->portal($this->nether, 0, 40, 0);
		$item = $this->droppedItem($this->world, 0.5, 13.5, 0.5, new Vector3(0, 0, 0));
		for($i = 0; $i < 40 && !$item->isClosed(); ++$i){
			$this->runTicks(1);
		}
		self::assertTrue($item->isClosed());
		self::assertCount(1, $this->entitiesOf($this->nether, ItemEntity::class));
		self::assertCount(0, $this->entitiesOf($this->world, ItemEntity::class));
	}

	public function testPortalsOnDifferentAxesTurnTheEntity() : void{
		$entry = $this->portal($this->world, 0, 10, 0, Axis::X);
		$exit = $this->portal($this->nether, 4, 40, 0, Axis::Z);
		$item = $this->droppedItem($this->world, 0.5, 10.1, 0.5, new Vector3(0, 0, 0.3));
		[, $yawChange] = PortalTravel::getRelativeArrival($item, $entry, $exit, $this->nether);
		self::assertSame(90.0, $yawChange);

		PortalTravel::getInstance()->onEntityInsidePortal($item, Dimension::NETHER, new Vector3(0, 10, 0));
		$arrived = $this->entitiesOf($this->nether, ItemEntity::class);
		self::assertCount(1, $arrived);
		$motion = $arrived[0]->getMotion();
		//+90 degrees of yaw: (x, z) becomes (-z, x), out of the Z axis portal
		self::assertEqualsWithDelta(-0.3, $motion->x, 0.001);
		self::assertEqualsWithDelta(0.0, $motion->z, 0.001);
		self::assertEqualsWithDelta(4.5, $arrived[0]->getPosition()->x, 0.001, "comes out in the plane of the exit portal");
	}

	public function testNoPortalOnTheOtherSideKeepsTheEntity() : void{
		$this->portal($this->world, 0, 10, 0);
		$item = $this->droppedItem($this->world, 0.5, 10.1, 0.5, new Vector3(0, 0, 0));
		PortalTravel::getInstance()->onEntityInsidePortal($item, Dimension::NETHER, new Vector3(0, 10, 0));
		self::assertFalse($item->isFlaggedForDespawn());
		self::assertCount(0, $this->entitiesOf($this->nether, ItemEntity::class));
		self::assertCount(0, NetherPortalRegistry::getInstance()->getAll($this->nether), "only players build a portal on the other side");
	}

	public function testCooldownAfterTravellingStartsAgainWhileInThePortal() : void{
		$this->portal($this->world, 0, 10, 0);
		$this->portal($this->nether, 0, 40, 0);
		$pig = new Pig(new Location(0.5, 10, 0.5, $this->world, 0, 0));
		$pig->setNameTag("Bacon");
		$pig->spawnToAll();
		$travel = PortalTravel::getInstance();
		$travel->onEntityInsidePortal($pig, Dimension::NETHER, new Vector3(0, 10, 0));
		$arrived = $this->entitiesOf($this->nether, Pig::class);
		self::assertCount(1, $arrived);
		$copy = $arrived[0];
		self::assertSame("Bacon", $copy->getNameTag());
		self::assertTrue($travel->isOnPortalCooldown($copy));

		//standing in the portal it came out of: the cooldown keeps starting again
		for($i = 0; $i < PortalTravel::ENTITY_COOLDOWN_TICKS + 100; ++$i){
			++$this->tick;
			$travel->onEntityInsidePortal($copy, Dimension::NETHER, new Vector3(0, 40, 0));
		}
		self::assertFalse($copy->isFlaggedForDespawn());
		self::assertCount(0, $this->entitiesOf($this->world, Pig::class));

		//out of the portal for 15 seconds: it may go back
		$this->tick += PortalTravel::ENTITY_COOLDOWN_TICKS;
		self::assertFalse($travel->isOnPortalCooldown($copy));
		$travel->onEntityInsidePortal($copy, Dimension::NETHER, new Vector3(0, 40, 0));
		self::assertTrue($copy->isFlaggedForDespawn());
		self::assertCount(1, $this->entitiesOf($this->world, Pig::class));
	}

	public function testMinecartTakesItsPassengerAlong() : void{
		$this->portal($this->world, 0, 10, 0);
		$this->portal($this->nether, 0, 40, 0);
		$cart = new Minecart(new Location(0.5, 10, 0.5, $this->world, 0, 0));
		$cart->spawnToAll();
		$pig = new Pig(new Location(0.5, 10, 0.5, $this->world, 0, 0));
		$pig->setNameTag("Rider");
		$pig->spawnToAll();
		self::assertTrue($pig->mount($cart));
		$travel = PortalTravel::getInstance();

		//the passenger doesn't go by itself: its vehicle takes it
		self::assertFalse(PortalTravel::canTravel($pig, true));
		$travel->onEntityInsidePortal($pig, Dimension::NETHER, new Vector3(0, 10, 0));
		self::assertFalse($pig->isClosed());
		self::assertFalse(PortalTravel::canTravel($cart), "End gateways end rides: no passengers through them");
		self::assertTrue(PortalTravel::canTravel($cart, true));

		$travel->onEntityInsidePortal($cart, Dimension::NETHER, new Vector3(0, 10, 0));
		self::assertTrue($pig->isClosed());
		self::assertFalse($pig->canSaveWithChunk());
		$carts = $this->entitiesOf($this->nether, Minecart::class);
		$pigs = $this->entitiesOf($this->nether, Pig::class);
		self::assertCount(1, $carts);
		self::assertCount(1, $pigs);
		self::assertSame($carts[0], $pigs[0]->getVehicle(), "back on its seat");
		self::assertSame("Rider", $pigs[0]->getNameTag());
		self::assertTrue($travel->isOnPortalCooldown($pigs[0]));
		self::assertCount(0, $this->entitiesOf($this->world, Pig::class));
	}

	public function testChestMinecartKeepsItsInventory() : void{
		$this->portal($this->world, 0, 10, 0);
		$this->portal($this->nether, 0, 40, 0);
		$cart = new MinecartChest(new Location(0.5, 10, 0.5, $this->world, 0, 0));
		$cart->getInventory()->setItem(3, VanillaItems::DIAMOND()->setCount(7));
		$cart->spawnToAll();
		PortalTravel::getInstance()->onEntityInsidePortal($cart, Dimension::NETHER, new Vector3(0, 10, 0));
		$arrived = $this->entitiesOf($this->nether, MinecartChest::class);
		self::assertCount(1, $arrived);
		self::assertInstanceOf(MinecartChest::class, $arrived[0]);
		self::assertTrue($arrived[0]->getInventory()->getItem(3)->equalsExact(VanillaItems::DIAMOND()->setCount(7)));
		self::assertFalse($cart->canSaveWithChunk(), "the items are never duplicated");
	}

	public function testTamedWolfKeepsItsOwner() : void{
		$this->portal($this->world, 0, 10, 0);
		$this->portal($this->nether, 0, 40, 0);
		$owner = Uuid::uuid4();
		$wolf = new Wolf(new Location(0.5, 10, 0.5, $this->world, 0, 0), CompoundTag::create()->setString(TameableAnimal::TAG_OWNER_UUID, $owner->toString()));
		self::assertTrue($wolf->isTamed());
		$wolf->spawnToAll();
		PortalTravel::getInstance()->onEntityInsidePortal($wolf, Dimension::NETHER, new Vector3(0, 10, 0));
		$arrived = $this->entitiesOf($this->nether, Wolf::class);
		self::assertCount(1, $arrived);
		self::assertInstanceOf(Wolf::class, $arrived[0]);
		self::assertTrue($arrived[0]->isTamed());
		self::assertTrue($owner->equals($arrived[0]->getOwnerUuid()));
	}

	public function testExitPortalChunksStayLoadedForAWhile() : void{
		$this->portal($this->world, 0, 10, 0);
		$this->portal($this->nether, 0, 40, 0);
		self::assertCount(1, $this->nether->getChunkLoaders(0, 0), "only the test's own loader");
		$item = $this->droppedItem($this->world, 0.5, 10.1, 0.5, new Vector3(0, 0, 0));
		PortalTravel::getInstance()->onEntityInsidePortal($item, Dimension::NETHER, new Vector3(0, 10, 0));
		self::assertCount(1, $this->entitiesOf($this->nether, ItemEntity::class));
		self::assertCount(2, $this->nether->getChunkLoaders(0, 0), "held after the trip");
		self::assertCount(2, $this->nether->getChunkLoaders(-1, 0), "with the blocks around the portal");

		$heldUntil = $this->tick + PortalTravel::PORTAL_TICKET_TICKS;
		PortalTravel::tickWorld($this->nether, $heldUntil - 20 - ($heldUntil % 20));
		self::assertCount(2, $this->nether->getChunkLoaders(0, 0));
		PortalTravel::tickWorld($this->nether, $heldUntil + 20 - ($heldUntil % 20));
		self::assertCount(1, $this->nether->getChunkLoaders(0, 0), "released");
		self::assertCount(1, $this->nether->getChunkLoaders(-1, 0));
	}

	public function testNoKnownPortalInRangeLoadsNothing() : void{
		$this->portal($this->world, 0, 10, 0);
		$this->portal($this->nether, 100, 40, 0); //more than 16 blocks from the arrival point
		$item = $this->droppedItem($this->world, 0.5, 10.1, 0.5, new Vector3(0, 0, 0));
		PortalTravel::getInstance()->onEntityInsidePortal($item, Dimension::NETHER, new Vector3(0, 10, 0));
		self::assertFalse($item->isFlaggedForDespawn());
		self::assertCount(1, $this->nether->getChunkLoaders(6, 0), "the far portal's chunk wasn't touched");
		self::assertCount(1, $this->nether->getChunkLoaders(0, 0));
	}

	public function testCooldownLengths() : void{
		$item = $this->droppedItem($this->world, 0.5, 20, 0.5, new Vector3(0, 0, 0));
		self::assertSame(300, PortalTravel::getCooldownTicks($item));
		self::assertSame(300, PortalTravel::getCooldownTicks(new Pig(new Location(0.5, 20, 0.5, $this->world, 0, 0))));
	}

	public function testDragonAndWitherNeverTravel() : void{
		$this->portal($this->world, 0, 10, 0);
		$this->portal($this->nether, 0, 40, 0);
		foreach([new EnderDragon(new Location(0.5, 10, 0.5, $this->world, 0, 0)), new Wither(new Location(0.5, 10, 0.5, $this->world, 0, 0))] as $boss){
			self::assertFalse(PortalTravel::canTravel($boss));
			PortalTravel::getInstance()->onEntityInsidePortal($boss, Dimension::NETHER, new Vector3(0, 10, 0));
			self::assertFalse($boss->isFlaggedForDespawn());
			self::assertSame($this->world, $boss->getWorld());
		}
		self::assertCount(0, $this->nether->getEntities());
	}

	public function testUnsavedEntitiesDoNotTravel() : void{
		$pig = new Pig(new Location(0.5, 10, 0.5, $this->world, 0, 0));
		$item = $this->droppedItem($this->world, 0.5, 10, 0.5, new Vector3(0, 0, 0));
		self::assertTrue(PortalTravel::canTravel($pig));
		self::assertTrue(PortalTravel::canTravel($item));
		$pig->setCanSaveWithChunk(false);
		self::assertFalse(PortalTravel::canTravel($pig), "entities which aren't saved (plugin NPCs...) don't travel");
	}

	public function testPluginsCanCancelTheTrip() : void{
		$this->portal($this->world, 0, 10, 0);
		$this->portal($this->nether, 0, 40, 0);
		$listener = new RegisteredListener(function(EntityTeleportEvent $ev) : void{
			$ev->cancel();
		}, EventPriority::NORMAL, $this->createConfiguredMock(Plugin::class, ["isEnabled" => true]), false, new TimingsHandler("test"));
		HandlerListManager::global()->getListFor(EntityTeleportEvent::class)->register($listener);
		try{
			$item = $this->droppedItem($this->world, 0.5, 10.1, 0.5, new Vector3(0, 0, 0));
			PortalTravel::getInstance()->onEntityInsidePortal($item, Dimension::NETHER, new Vector3(0, 10, 0));
			self::assertFalse($item->isFlaggedForDespawn());
			self::assertCount(0, $this->entitiesOf($this->nether, ItemEntity::class));
		}finally{
			HandlerListManager::global()->getListFor(EntityTeleportEvent::class)->unregister($listener);
		}
	}

	public function testItemThroughEndPortalReachesTheSpawnPlatform() : void{
		$this->world->setBlockAt(5, 10, 5, VanillaBlocks::END_PORTAL());
		$item = $this->droppedItem($this->world, 5.5, 10.1, 5.5, new Vector3(0, 0, 0));
		PortalTravel::getInstance()->onEntityInsidePortal($item, Dimension::END, new Vector3(5, 10, 5));
		$arrived = $this->entitiesOf($this->end, ItemEntity::class);
		self::assertCount(1, $arrived);
		$arrival = EndSpawnPlatform::getArrivalPosition();
		self::assertTrue($arrived[0]->getPosition()->equals($arrival));
		self::assertSame(BlockTypeIds::OBSIDIAN, $this->end->getBlockAt($arrival->getFloorX(), $arrival->getFloorY() - 1, $arrival->getFloorZ())->getTypeId(), "the platform was built");
	}

	public function testPigThroughEndGateway() : void{
		$gateway = new Vector3(2, 60, 0);
		$exit = new Vector3(60, 70, 1);
		$this->end->setBlock($gateway, VanillaBlocks::END_GATEWAY());
		$tile = $this->end->getTile($gateway);
		self::assertInstanceOf(TileEndGateway::class, $tile);
		$tile->setExitPortal($exit, true);

		$pig = new Pig(new Location(2.5, 60, 0.5, $this->end, 0, 0));
		$pig->spawnToAll();
		EndGatewayTravel::getInstance()->onEntityInsideGateway($pig, $gateway);
		self::assertFalse($pig->isClosed(), "same world: the pig itself moves");
		self::assertTrue($pig->getPosition()->equals(new Vector3(60.5, 70, 1.5)));
		self::assertTrue(PortalTravel::getInstance()->isOnPortalCooldown($pig));

		//right back into a gateway: nothing happens while the cooldown lasts
		$pig->teleport(new Vector3(2.5, 60, 0.5));
		++$this->tick;
		EndGatewayTravel::getInstance()->onEntityInsideGateway($pig, $gateway);
		self::assertEqualsWithDelta(2.5, $pig->getPosition()->x, 0.001);
	}
}
