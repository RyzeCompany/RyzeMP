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

namespace pocketmine\entity\vehicle;

use PHPUnit\Framework\MockObject\MockObject;
use pocketmine\block\DetectorRail;
use pocketmine\block\dispenser\DispenseBehaviorRegistry;
use pocketmine\block\dispenser\DispenseContext;
use pocketmine\block\PoweredRail;
use pocketmine\block\tile\Chest as TileChest;
use pocketmine\block\tile\Container;
use pocketmine\block\tile\Hopper as TileHopper;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\block\BlockLegacyMetadata as Shape;
use pocketmine\entity\ai\AiBudget;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\animal\Pig;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Location;
use pocketmine\entity\object\ItemEntity;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\SimpleInventory;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\permission\DefaultPermissions;
use pocketmine\permission\PermissionManager;
use pocketmine\world\format\Chunk;
use pocketmine\world\redstone\RedstoneTestCase;
use function abs;
use function array_filter;
use function array_values;

/**
 * Minecarts in a real world: rail physics (vanilla OldMinecartBehavior), rails acting on carts, containers, TNT,
 * saving and generated carts.
 */
final class MinecartTest extends RedstoneTestCase{

	protected function getPocketmineYml() : array{
		return [
			"redstone" => ["enabled" => true, "max-wire-updates-per-tick" => 1_000_000],
			"mobs" => ["enabled" => true, "spawning" => ["enabled" => false]]
		];
	}

	protected function setUp() : void{
		parent::setUp();
		AiBudget::configure(true, 100000, 100000, 400, requireNearbyPlayer: false);
		PathBlockTypes::clearCache();
		$this->floor(-24, -24, 24, 24);
	}

	private function rail(int $x, int $y, int $z, int $shape) : void{
		$this->set($x, $y, $z, VanillaBlocks::RAIL()->setShape($shape));
	}

	private function eastWestTrack(int $fromX, int $toX, int $y = 1, int $z = 0) : void{
		for($x = $fromX; $x <= $toX; ++$x){
			$this->rail($x, $y, $z, Shape::RAIL_STRAIGHT_EAST_WEST);
		}
	}

	private function cart(MinecartType $type, float $x, float $y, float $z, ?Vector3 $motion = null) : AbstractMinecart{
		$cart = $type->createEntity(Location::fromObject(new Vector3($x, $y, $z), $this->world));
		$cart->spawnToAll();
		if($motion !== null){
			$cart->setMotion($motion);
		}
		return $cart;
	}

	private function minecart(float $x, float $y, float $z, ?Vector3 $motion = null) : Minecart{
		$cart = $this->cart(MinecartType::NORMAL, $x, $y, $z, $motion);
		self::assertInstanceOf(Minecart::class, $cart);
		return $cart;
	}

	private function chestCart(float $x, float $y, float $z, ?Vector3 $motion = null) : MinecartChest{
		$cart = $this->cart(MinecartType::CHEST, $x, $y, $z, $motion);
		self::assertInstanceOf(MinecartChest::class, $cart);
		return $cart;
	}

	private function hopperCart(float $x, float $y, float $z, ?Vector3 $motion = null) : MinecartHopper{
		$cart = $this->cart(MinecartType::HOPPER, $x, $y, $z, $motion);
		self::assertInstanceOf(MinecartHopper::class, $cart);
		return $cart;
	}

	private function tntCart(float $x, float $y, float $z, ?Vector3 $motion = null) : MinecartTNT{
		$cart = $this->cart(MinecartType::TNT, $x, $y, $z, $motion);
		self::assertInstanceOf(MinecartTNT::class, $cart);
		return $cart;
	}

	private function commandCart(float $x, float $y, float $z, ?Vector3 $motion = null) : MinecartCommandBlock{
		$cart = $this->cart(MinecartType::COMMAND_BLOCK, $x, $y, $z, $motion);
		self::assertInstanceOf(MinecartCommandBlock::class, $cart);
		return $cart;
	}

	/**
	 * @phpstan-template T of Entity
	 * @phpstan-param class-string<T> $class
	 * @phpstan-return list<T>
	 */
	private function entities(string $class) : array{
		return array_values(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof $class && !$e->isFlaggedForDespawn() && !$e->isClosed()));
	}

	private static function itemCount(Inventory $inventory, Item $item) : int{
		$count = 0;
		foreach($inventory->all($item) as $stack){
			$count += $stack->getCount();
		}
		return $count;
	}

	public function testStandsStillOnRail() : void{
		$this->rail(0, 1, 0, Shape::RAIL_STRAIGHT_NORTH_SOUTH);
		$pos = AbstractMinecart::getPlacementPosition($this->get(0, 1, 0));
		self::assertNotNull($pos);
		$cart = VanillaItems::MINECART()->spawnMinecart($this->world, $pos);
		self::assertInstanceOf(Minecart::class, $cart);
		$this->runTicks(10);
		$location = $cart->getLocation();
		self::assertEqualsWithDelta(0.5, $location->x, 1e-9);
		self::assertEqualsWithDelta(1.0625, $location->y, 1e-9);
		self::assertEqualsWithDelta(0.5, $location->z, 1e-9);
		self::assertTrue($cart->isOnRails());
		self::assertEqualsWithDelta(0.0, $cart->getMotion()->length(), 1e-9);
	}

	public function testRollsAlongStraightRailAndSlowsDown() : void{
		$this->eastWestTrack(-2, 20);
		$cart = $this->minecart(0.5, 1.0625, 0.5, new Vector3(0.4, 0, 0));
		$this->runTicks(1);
		//one tick: moved 0.4, then 96% of the speed is kept (empty cart)
		self::assertEqualsWithDelta(0.9, $cart->getLocation()->x, 1e-6);
		self::assertEqualsWithDelta(0.384, $cart->getMotion()->x, 1e-6);
		self::assertEqualsWithDelta(1.0625, $cart->getLocation()->y, 1e-9);
		self::assertEqualsWithDelta(0.5, $cart->getLocation()->z, 1e-9);
		$this->runTicks(200);
		//an empty cart rolls 0.4 / (1 - 0.96) = 10 blocks
		self::assertEqualsWithDelta(10.5, $cart->getLocation()->x, 0.2);
		self::assertLessThan(0.001, abs($cart->getMotion()->x));
	}

	public function testMaxSpeedIsClamped() : void{
		$this->eastWestTrack(-2, 40);
		$cart = $this->minecart(0.5, 1.0625, 0.5, new Vector3(1.5, 0, 0));
		$this->runTicks(1);
		self::assertEqualsWithDelta(0.9, $cart->getLocation()->x, 1e-6);
	}

	public function testFollowsCurve() : void{
		$this->eastWestTrack(0, 3);
		//the curve at x=4 connects west and south
		$this->rail(4, 1, 0, Shape::RAIL_CURVE_SOUTHWEST);
		for($z = 1; $z <= 12; ++$z){
			$this->rail(4, 1, $z, Shape::RAIL_STRAIGHT_NORTH_SOUTH);
		}
		$cart = $this->minecart(0.5, 1.0625, 0.5, new Vector3(0.4, 0, 0));
		$this->runTicks(40);
		$location = $cart->getLocation();
		self::assertEqualsWithDelta(4.5, $location->x, 1e-6);
		self::assertGreaterThan(2.0, $location->z);
		self::assertEqualsWithDelta(1.0625, $location->y, 1e-9);
	}

	public function testSlidesDownSlope() : void{
		//slope going up to the east at x=1, flat track to the west
		$this->eastWestTrack(-12, 0);
		$this->rail(1, 1, 0, Shape::RAIL_ASCENDING_EAST);
		$this->set(2, 1, 0, VanillaBlocks::STONE());
		$this->rail(2, 2, 0, Shape::RAIL_STRAIGHT_EAST_WEST);
		$pos = AbstractMinecart::getPlacementPosition($this->get(1, 1, 0));
		self::assertNotNull($pos);
		$cart = VanillaItems::MINECART()->spawnMinecart($this->world, $pos);
		$this->runTicks(5);
		self::assertLessThan(0.0, $cart->getMotion()->x, "the cart rolls down to the west");
		$this->runTicks(30);
		self::assertLessThan(1.0, $cart->getLocation()->x);
		self::assertEqualsWithDelta(1.0625, $cart->getLocation()->y, 1e-6, "back on the flat track");
	}

	public function testClimbsSlope() : void{
		$this->eastWestTrack(-4, 0);
		$this->rail(1, 1, 0, Shape::RAIL_ASCENDING_EAST);
		$this->set(2, 1, 0, VanillaBlocks::STONE());
		$this->set(3, 1, 0, VanillaBlocks::STONE());
		$this->set(4, 1, 0, VanillaBlocks::STONE());
		$this->eastWestTrack(2, 4, 2);
		$cart = $this->minecart(-0.5, 1.0625, 0.5, new Vector3(0.4, 0, 0));
		$this->runTicks(20);
		$location = $cart->getLocation();
		self::assertGreaterThan(2.0, $location->x);
		self::assertEqualsWithDelta(2.0625, $location->y, 1e-6, "on the upper track");
	}

	public function testPoweredRailBoostsAndStartsCarts() : void{
		$this->eastWestTrack(-2, 30);
		$this->set(2, 0, 0, VanillaBlocks::REDSTONE());
		$this->set(2, 1, 0, VanillaBlocks::POWERED_RAIL()->setShape(Shape::RAIL_STRAIGHT_EAST_WEST));
		$this->runTicks(2);
		$rail = $this->get(2, 1, 0);
		self::assertInstanceOf(PoweredRail::class, $rail);
		self::assertTrue($rail->isPowered());

		$slow = $this->minecart(0.5, 1.0625, 0.5, new Vector3(0.1, 0, 0));
		$this->runTicks(40);
		//without the boost an empty cart rolls 0.1 / 0.04 = 2.5 blocks
		self::assertGreaterThan(5.0, $slow->getLocation()->x);
		$slow->flagForDespawn();

		//a stopped cart on a powered rail next to a solid block is pushed away from it
		$this->set(-3, 1, 1, VanillaBlocks::STONE());
		$this->set(-2, 0, 1, VanillaBlocks::REDSTONE());
		$this->set(-2, 1, 1, VanillaBlocks::POWERED_RAIL()->setShape(Shape::RAIL_STRAIGHT_EAST_WEST));
		$this->eastWestTrack(-1, 10, 1, 1);
		$this->runTicks(2);
		$stopped = $this->minecart(-1.5, 1.0625, 1.5);
		$this->runTicks(10);
		self::assertGreaterThan(-1.5, $stopped->getLocation()->x);
		self::assertGreaterThan(0.0, $stopped->getMotion()->x);
	}

	public function testUnpoweredPoweredRailBrakes() : void{
		$this->eastWestTrack(-2, 30);
		for($x = 3; $x <= 6; ++$x){
			$this->set($x, 1, 0, VanillaBlocks::POWERED_RAIL()->setShape(Shape::RAIL_STRAIGHT_EAST_WEST));
		}
		$cart = $this->minecart(0.5, 1.0625, 0.5, new Vector3(0.3, 0, 0));
		$this->runTicks(60);
		self::assertLessThan(7.0, $cart->getLocation()->x);
		self::assertEqualsWithDelta(0.0, $cart->getMotion()->x, 1e-9);
	}

	public function testDetectorRail() : void{
		$this->eastWestTrack(-2, 2);
		$this->set(0, 1, 0, VanillaBlocks::DETECTOR_RAIL()->setShape(Shape::RAIL_STRAIGHT_EAST_WEST));
		$cart = $this->chestCart(0.5, 1.0625, 0.5);
		$cart->getInventory()->addItem(VanillaItems::DIAMOND()->setCount(64));
		$this->runTicks(2);
		$rail = $this->get(0, 1, 0);
		self::assertInstanceOf(DetectorRail::class, $rail);
		self::assertTrue($rail->isActivated());
		self::assertSame(1, $rail->getComparatorOutput(), "one full stack in 27 slots");

		$cart->flagForDespawn();
		$this->runTicks(DetectorRail::RECHECK_TICKS + 2);
		$rail = $this->get(0, 1, 0);
		self::assertInstanceOf(DetectorRail::class, $rail);
		self::assertFalse($rail->isActivated());
	}

	private function poweredActivatorRail(int $x, int $z) : void{
		$this->set($x, 0, $z, VanillaBlocks::REDSTONE());
		$this->set($x, 1, $z, VanillaBlocks::ACTIVATOR_RAIL()->setShape(Shape::RAIL_STRAIGHT_EAST_WEST));
		$this->runTicks(2);
	}

	public function testActivatorRailEjectsRider() : void{
		$this->eastWestTrack(-2, 2);
		$this->poweredActivatorRail(0, 0);
		$pig = new Pig(Location::fromObject(new Vector3(5.5, 1, 5.5), $this->world));
		$pig->spawnToAll();
		$cart = $this->minecart(0.5, 1.0625, 0.5);
		self::assertTrue($pig->mount($cart));
		$this->runTicks(1);
		self::assertNull($pig->getVehicle());
		self::assertCount(0, $cart->getPassengers());
		//not picked up again at once
		$pig->teleport($cart->getLocation());
		$this->runTicks(2);
		self::assertNull($pig->getVehicle());
	}

	public function testMobsGetInEmptyCarts() : void{
		$this->eastWestTrack(-2, 2);
		$cart = $this->minecart(0.5, 1.0625, 0.5);
		$pig = new Pig(Location::fromObject(new Vector3(0.7, 1, 0.5), $this->world));
		$pig->spawnToAll();
		$this->runTicks(2);
		self::assertSame($cart, $pig->getVehicle());
		//only one seat
		$other = new Pig(Location::fromObject(new Vector3(0.6, 1, 0.5), $this->world));
		$other->spawnToAll();
		$this->runTicks(2);
		self::assertNull($other->getVehicle());
		//chest minecarts carry no one
		$chestCart = $this->chestCart(10.5, 1.0625, 10.5);
		$third = new Pig(Location::fromObject(new Vector3(10.6, 1, 10.5), $this->world));
		$third->spawnToAll();
		$this->runTicks(2);
		self::assertNull($third->getVehicle());
		self::assertCount(0, $chestCart->getPassengers());
	}

	public function testCartsPushEachOther() : void{
		$this->eastWestTrack(-2, 30);
		$moving = $this->minecart(0.5, 1.0625, 0.5, new Vector3(0.3, 0, 0));
		$still = $this->minecart(3.5, 1.0625, 0.5);
		$moving->setRotation(0, 0);
		$still->setRotation(0, 0);
		$this->runTicks(40);
		self::assertGreaterThan(3.5, $still->getLocation()->x, "the hit cart moved on");
		self::assertLessThan($still->getLocation()->x, $moving->getLocation()->x);
	}

	public function testActivatorRailPrimesTntAndDisablesHopper() : void{
		$this->eastWestTrack(-2, 2);
		$this->poweredActivatorRail(0, 0);
		$tnt = $this->tntCart(0.5, 1.0625, 0.5);
		self::assertFalse($tnt->isPrimed());
		$this->runTicks(1);
		self::assertTrue($tnt->isPrimed());
		self::assertLessThanOrEqual(MinecartTNT::FUSE_TICKS, $tnt->getFuse());
		$this->runTicks(MinecartTNT::FUSE_TICKS + 2);
		self::assertTrue($tnt->hasExploded());
		self::assertTrue($tnt->isClosed());

		$this->eastWestTrack(-2, 2, 1, 3);
		$this->poweredActivatorRail(0, 3);
		$hopper = $this->hopperCart(0.5, 1.0625, 3.5);
		self::assertTrue($hopper->isEnabled());
		$this->runTicks(1);
		self::assertFalse($hopper->isEnabled());
	}

	public function testTntExplodesWhenCrashing() : void{
		$this->eastWestTrack(-2, 3);
		$this->set(4, 1, 0, VanillaBlocks::STONE());
		$tnt = $this->tntCart(0.5, 1.0625, 0.5, new Vector3(0.4, 0, 0));
		$this->runTicks(20);
		self::assertTrue($tnt->hasExploded());
	}

	public function testHitsBreakTheCart() : void{
		$this->eastWestTrack(-2, 2);
		$cart = $this->chestCart(0.5, 1.0625, 0.5);
		$cart->getInventory()->setItem(3, VanillaItems::APPLE()->setCount(5));
		for($i = 0; $i < 4; ++$i){
			$cart->attack(new EntityDamageEvent($cart, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
			self::assertFalse($cart->isBroken(), "hit $i");
		}
		$cart->attack(new EntityDamageEvent($cart, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		self::assertTrue($cart->isBroken());
		$this->runTicks(1);
		$drops = [];
		foreach($this->entities(ItemEntity::class) as $item){
			$drops[$item->getItem()->getVanillaName()] = $item->getItem()->getCount();
		}
		self::assertSame(["Minecart" => 1, "Chest" => 1, "Apple" => 5], $drops);
	}

	public function testHopperCartPicksUpItemsAndHopperEmptiesIt() : void{
		//hopper under the rail, pointing into a chest
		$this->set(0, 0, 0, VanillaBlocks::HOPPER()->setFacing(Facing::EAST));
		$this->set(1, 0, 0, VanillaBlocks::CHEST());
		$this->eastWestTrack(-2, 0); //rails don't stand on chests
		$cart = $this->hopperCart(0.5, 1.0625, 0.5);
		$this->world->dropItem(new Vector3(0.5, 1.5, 0.5), VanillaItems::GOLD_INGOT()->setCount(3), Vector3::zero());
		$this->runTicks(2);
		self::assertCount(0, $this->entities(ItemEntity::class), "picked up");
		$this->runTicks(120);
		$chest = $this->world->getTileAt(1, 0, 0);
		self::assertInstanceOf(TileChest::class, $chest);
		self::assertCount(0, $cart->getInventory()->getContents(), "emptied by the hopper");
		self::assertSame(3, self::itemCount($chest->getInventory(), VanillaItems::GOLD_INGOT()));
	}

	public function testHopperCartPullsFromContainerAbove() : void{
		$this->eastWestTrack(-2, 2);
		$this->set(0, 2, 0, VanillaBlocks::CHEST());
		$chest = $this->world->getTileAt(0, 2, 0);
		self::assertInstanceOf(TileChest::class, $chest);
		$chest->getInventory()->addItem(VanillaItems::COAL()->setCount(2));
		$cart = $this->hopperCart(0.5, 1.0625, 0.5);
		$this->runTicks(MinecartHopper::TRANSFER_COOLDOWN_TICKS * 3);
		self::assertCount(0, $chest->getInventory()->getContents());
		self::assertSame(2, self::itemCount($cart->getInventory(), VanillaItems::COAL()));
	}

	public function testHopperFillsChestCart() : void{
		$this->eastWestTrack(-2, 2);
		$this->set(0, 2, 0, VanillaBlocks::HOPPER()->setFacing(Facing::DOWN));
		$hopper = $this->world->getTileAt(0, 2, 0);
		self::assertInstanceOf(TileHopper::class, $hopper);
		$cart = $this->chestCart(0.5, 1.0625, 0.5);
		$this->runTicks(2);
		$hopper->getInventory()->addItem(VanillaItems::STICK()->setCount(2));
		$this->runTicks(40);
		self::assertSame(2, self::itemCount($cart->getInventory(), VanillaItems::STICK()));
		self::assertCount(0, $hopper->getInventory()->getContents());
	}

	public function testSavedAndLoaded() : void{
		$this->eastWestTrack(-2, 2);
		$chest = $this->chestCart(0.5, 1.0625, 0.5, new Vector3(0.1, 0, 0));
		$chest->getInventory()->setItem(26, VanillaItems::EMERALD()->setCount(7));
		$chest->setNameTag("Loot");
		$chest->setCustomDisplayBlock(VanillaBlocks::GLOWSTONE());
		$nbt = $chest->saveNBT();
		self::assertSame(EntityIds::CHEST_MINECART, $nbt->getString(EntityFactory::TAG_IDENTIFIER));
		$loaded = EntityFactory::getInstance()->createFromData($this->world, $nbt);
		self::assertInstanceOf(MinecartChest::class, $loaded);
		self::assertSame(7, $loaded->getInventory()->getItem(26)->getCount());
		self::assertSame("Loot", $loaded->getNameTag());
		self::assertTrue($loaded->getCustomDisplayBlock()?->isSameState(VanillaBlocks::GLOWSTONE()) ?? false);
		self::assertEqualsWithDelta(0.1, $loaded->getMotion()->x, 1e-9);

		$tnt = $this->tntCart(5.5, 1.0625, 5.5);
		$tnt->prime(33);
		$loadedTnt = EntityFactory::getInstance()->createFromData($this->world, $tnt->saveNBT());
		self::assertInstanceOf(MinecartTNT::class, $loadedTnt);
		self::assertSame(33, $loadedTnt->getFuse());

		$hopper = $this->hopperCart(7.5, 1.0625, 7.5);
		$hopper->setEnabled(false);
		$loadedHopper = EntityFactory::getInstance()->createFromData($this->world, $hopper->saveNBT());
		self::assertInstanceOf(MinecartHopper::class, $loadedHopper);
		self::assertFalse($loadedHopper->isEnabled());

		$command = $this->commandCart(9.5, 1.0625, 9.5);
		$command->setCommand("/say hi");
		$loadedCommand = EntityFactory::getInstance()->createFromData($this->world, $command->saveNBT());
		self::assertInstanceOf(MinecartCommandBlock::class, $loadedCommand);
		self::assertSame("/say hi", $loadedCommand->getCommand());

		$plain = $this->minecart(11.5, 1.0625, 11.5);
		self::assertInstanceOf(Minecart::class, EntityFactory::getInstance()->createFromData($this->world, $plain->saveNBT()));
	}

	public function testLootTableKeptUntilOpened() : void{
		$cart = $this->chestCart(0.5, 1.0625, 0.5);
		$cart->setLootTable("loot_tables/chests/abandoned_mineshaft.json", 1234);
		$nbt = $cart->saveNBT();
		self::assertSame("loot_tables/chests/abandoned_mineshaft.json", $nbt->getString(Container::TAG_LOOT_TABLE));
		self::assertSame(1234, $nbt->getLong(Container::TAG_LOOT_TABLE_SEED_LONG));
		$loaded = EntityFactory::getInstance()->createFromData($this->world, $nbt);
		self::assertInstanceOf(MinecartChest::class, $loaded);
		self::assertSame("loot_tables/chests/abandoned_mineshaft.json", $loaded->getLootTable());
		self::assertSame(1234, $loaded->getLootTableSeed());
	}

	public function testGeneratedChestMinecartGetsItsLootTable() : void{
		$this->rail(3, 1, 3, Shape::RAIL_STRAIGHT_NORTH_SOUTH);
		$chunk = $this->world->getChunk(0, 0);
		self::assertNotNull($chunk);
		//as written by MineShaftCorridor::createMinecartChest()
		$chunk->addPendingEntityNBT(CompoundTag::create()
			->setString(Chunk::PENDING_ENTITY_IDENTIFIER, "minecraft:chest_minecart")
			->setTag(Chunk::PENDING_ENTITY_POS, new ListTag([new DoubleTag(3.5), new DoubleTag(1.5), new DoubleTag(3.5)]))
			->setTag(Chunk::PENDING_ENTITY_ROTATION, new ListTag([new FloatTag(0.0), new FloatTag(0.0)]))
			->setString(Container::TAG_LOOT_TABLE, "loot_tables/chests/abandoned_mineshaft.json")
			->setInt(Container::TAG_LOOT_TABLE_SEED, -99)
			->setLong(Container::TAG_LOOT_TABLE_SEED_LONG, -99_000_000_000)
		);
		$this->world->setChunk(0, 0, $chunk);
		$carts = $this->entities(MinecartChest::class);
		self::assertCount(1, $carts);
		self::assertSame("loot_tables/chests/abandoned_mineshaft.json", $carts[0]->getLootTable());
		self::assertSame(-99_000_000_000, $carts[0]->getLootTableSeed());
		$this->runTicks(5);
		self::assertEqualsWithDelta(1.0625, $carts[0]->getLocation()->y, 1e-9, "settled on the rail");
	}

	public function testDispenserPlacesCartOnRail() : void{
		$this->rail(1, 1, 0, Shape::RAIL_STRAIGHT_EAST_WEST);
		$inventory = new SimpleInventory(9);
		$item = VanillaItems::HOPPER_MINECART();
		$context = new DispenseContext($this->world, new Vector3(0, 1, 0), Facing::EAST, $inventory);
		$result = DispenseBehaviorRegistry::getInstance()->get($item)->dispense($context, (clone $item)->setCount(1));
		self::assertTrue($result === null || $result->isNull());
		$this->runTicks(3);
		$carts = $this->entities(MinecartHopper::class);
		self::assertCount(1, $carts);
		self::assertEqualsWithDelta(1.0625, $carts[0]->getLocation()->y, 1e-6);
		self::assertTrue($carts[0]->isOnRails());
	}

	public function testCommandBlockRunsOnActivatorRail() : void{
		if(PermissionManager::getInstance()->getPermission(DefaultPermissions::ROOT_OPERATOR) === null){
			DefaultPermissions::registerCorePermissions();
		}
		$server = $this->world->getServer();
		self::assertInstanceOf(MockObject::class, $server);
		$commands = [];
		$server->method("dispatchCommand")->willReturnCallback(function($sender, string $command) use (&$commands) : bool{
			self::assertInstanceOf(MinecartCommandSender::class, $sender);
			$commands[] = $command;
			$sender->sendMessage("done");
			return true;
		});
		$this->eastWestTrack(-2, 2);
		$this->poweredActivatorRail(0, 0);
		$cart = $this->commandCart(0.5, 1.0625, 0.5);
		$cart->setCommand("/say @s");
		$this->runTicks(9);
		self::assertSame(["say @s", "say @s", "say @s"], $commands, "every 4 ticks while on the powered rail");
		self::assertSame("done", $cart->getLastOutput());
		self::assertSame(1, $cart->getSuccessCount());
		self::assertSame($cart, $cart->getCommandSender()->getExecutingEntity());
	}

}
