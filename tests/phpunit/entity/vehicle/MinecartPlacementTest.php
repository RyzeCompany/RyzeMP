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

use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\block\BlockLegacyMetadata as Shape;
use pocketmine\entity\ai\AiBudget;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\object\ArmorStand;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\ItemUseResult;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\IntMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\MetadataProperty;
use pocketmine\player\Player;
use pocketmine\world\redstone\RedstoneTestCase;

/**
 * Where minecarts are placed and what clients are told about them: the rail centre on every rail shape, carts staying
 * on the last piece of a track, network position, flags and hurt data.
 */
final class MinecartPlacementTest extends RedstoneTestCase{

	protected function getPocketmineYml() : array{
		return [
			"redstone" => ["enabled" => true, "max-wire-updates-per-tick" => 1_000_000],
			"mobs" => ["enabled" => true, "spawning" => ["enabled" => false]]
		];
	}

	protected function setUp() : void{
		parent::setUp();
		AiBudget::configure(true, 100000, 100000, 400, requireNearbyPlayer: false);
		$this->floor(-24, -24, 24, 24);
	}

	private function rail(int $x, int $y, int $z, int $shape) : void{
		$this->set($x, $y, $z, VanillaBlocks::RAIL()->setShape($shape));
	}

	/**
	 * The metadata clients would get now.
	 *
	 * @return MetadataProperty[]
	 * @phpstan-return array<int, MetadataProperty>
	 */
	private static function networkData(Entity $entity) : array{
		/** @phpstan-var array<int, MetadataProperty> $data */
		$data = (new \ReflectionMethod(Entity::class, "getAllNetworkData"))->invoke($entity);
		return $data;
	}

	private static function flag(Entity $entity, int $flag) : bool{
		$flags = self::networkData($entity)[$flag >= 64 ? EntityMetadataProperties::FLAGS2 : EntityMetadataProperties::FLAGS] ?? null;
		self::assertInstanceOf(LongMetadataProperty::class, $flags);
		return ($flags->getValue() & (1 << ($flag % 64))) !== 0;
	}

	private static function intProperty(Entity $entity, int $id) : int{
		$property = self::networkData($entity)[$id] ?? null;
		self::assertInstanceOf(IntMetadataProperty::class, $property);
		return $property->getValue();
	}

	/**
	 * A mocked player in the test world, for item use.
	 */
	private function player() : Player{
		$player = $this->createMock(Player::class);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("getLocation")->willReturn(new Location(0.5, 1, -3.5, $this->world, 0, 0));
		//keep the mock from running Player::onDispose() when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	/**
	 * @return float[][]|int[][]
	 * @phpstan-return array<string, array{int, float, float}>
	 */
	public static function flatShapes() : array{
		//shape, where the cart rests in the block: the middle of straight rails, the middle of the line between the two
		//ends of curves (vanilla OldMinecartBehavior: carts take the short cut, the client draws them on the rail)
		return [
			"north-south" => [Shape::RAIL_STRAIGHT_NORTH_SOUTH, 0.5, 0.5],
			"east-west" => [Shape::RAIL_STRAIGHT_EAST_WEST, 0.5, 0.5],
			"curve south-east" => [Shape::RAIL_CURVE_SOUTHEAST, 0.75, 0.75],
			"curve south-west" => [Shape::RAIL_CURVE_SOUTHWEST, 0.25, 0.75],
			"curve north-west" => [Shape::RAIL_CURVE_NORTHWEST, 0.25, 0.25],
			"curve north-east" => [Shape::RAIL_CURVE_NORTHEAST, 0.75, 0.25],
		];
	}

	/**
	 * @dataProvider flatShapes
	 */
	public function testPlacedAndStaysOnALoneRail(int $shape, float $restX, float $restZ) : void{
		$this->rail(3, 1, 3, $shape);
		$rail = $this->get(3, 1, 3);
		$returned = [];
		$item = VanillaItems::MINECART();
		self::assertSame(ItemUseResult::SUCCESS, $item->onInteractBlock($this->player(), $this->get(3, 2, 3), $rail, Facing::UP, new Vector3(0.9, 0.125, 0.1), $returned));
		self::assertSame(0, $item->getCount(), "used up");
		$carts = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof Minecart){
				$carts[] = $entity;
			}
		}
		self::assertCount(1, $carts);
		$cart = $carts[0];
		//whatever point of the rail is clicked, the cart is in the middle of the block, just above the rail
		$location = $cart->getLocation();
		self::assertEqualsWithDelta(3.5, $location->x, 1e-9);
		self::assertEqualsWithDelta(1.0625, $location->y, 1e-9);
		self::assertEqualsWithDelta(3.5, $location->z, 1e-9);

		$this->runTicks(40);
		$location = $cart->getLocation();
		self::assertEqualsWithDelta(3 + $restX, $location->x, 1e-9, "doesn't drift");
		self::assertEqualsWithDelta(1.0625, $location->y, 1e-9);
		self::assertEqualsWithDelta(3 + $restZ, $location->z, 1e-9);
		self::assertTrue($cart->isOnRails());
		self::assertSame(0.0, $cart->getMotion()->lengthSquared());
		$railPoint = $cart->getRailPoint($location->x, $location->y, $location->z);
		self::assertNotNull($railPoint);
		self::assertEqualsWithDelta(0.0, $railPoint->distance($location), 1e-9, "on the rail line");
	}

	/**
	 * @return int[][]
	 * @phpstan-return array<string, array{int, int, int}>
	 */
	public static function slopes() : array{
		//shape, offset of the block holding the upper end
		return [
			"ascending east" => [Shape::RAIL_ASCENDING_EAST, 1, 0],
			"ascending west" => [Shape::RAIL_ASCENDING_WEST, -1, 0],
			"ascending north" => [Shape::RAIL_ASCENDING_NORTH, 0, -1],
			"ascending south" => [Shape::RAIL_ASCENDING_SOUTH, 0, 1],
		];
	}

	/**
	 * @dataProvider slopes
	 */
	public function testPlacedHalfABlockHigherOnSlopes(int $shape, int $dx, int $dz) : void{
		$this->set(3 + $dx, 1, 3 + $dz, VanillaBlocks::STONE());
		$this->rail(3, 1, 3, $shape);
		$pos = AbstractMinecart::getPlacementPosition($this->get(3, 1, 3));
		self::assertNotNull($pos);
		self::assertEqualsWithDelta(3.5, $pos->x, 1e-9);
		self::assertEqualsWithDelta(1.5625, $pos->y, 1e-9);
		self::assertEqualsWithDelta(3.5, $pos->z, 1e-9);
		$cart = VanillaItems::MINECART()->spawnMinecart($this->world, $pos, AbstractMinecart::getRailYaw($this->get(3, 1, 3)));
		$this->runTicks(1);
		//it starts rolling down the slope, on the rail line
		self::assertTrue($cart->isOnRails());
		$location = $cart->getLocation();
		if($dx === 0){
			self::assertEqualsWithDelta(3.5, $location->x, 1e-9);
			self::assertLessThan(0.0, ($location->z - 3.5) * $dz, "downhill");
		}else{
			self::assertEqualsWithDelta(3.5, $location->z, 1e-9);
			self::assertLessThan(0.0, ($location->x - 3.5) * $dx, "downhill");
		}
		$expected = $cart->getRailPoint($location->x, $location->y, $location->z);
		self::assertNotNull($expected);
		self::assertEqualsWithDelta($expected->y, $location->y, 1e-6, "on the slope");
	}

	public function testNotPushedOffTheLastRailByAnEntityBesideIt() : void{
		for($x = 0; $x <= 2; ++$x){
			$this->rail($x, 1, 0, Shape::RAIL_STRAIGHT_EAST_WEST);
		}
		$cart = VanillaItems::MINECART()->spawnMinecart($this->world, new Vector3(2.5, 1.0625, 0.5));
		//beside the cart, 0.01 block away from touching it (inside the 0.2 margin used between carts)
		$stand = new ArmorStand(Location::fromObject(new Vector3(2.5, 1, 0.5 + 0.49 + 0.25 + 0.01), $this->world));
		$stand->spawnToAll();
		$this->runTicks(60);
		$location = $cart->getLocation();
		self::assertEqualsWithDelta(2.5, $location->x, 1e-9, "not pushed along the rail");
		self::assertEqualsWithDelta(0.5, $location->z, 1e-9);
		self::assertEqualsWithDelta(1.0625, $location->y, 1e-9);
		self::assertTrue($cart->isOnRails());
	}

	public function testPushedByAnEntityWalkingIntoIt() : void{
		for($x = -10; $x <= 10; ++$x){
			$this->rail($x, 1, 0, Shape::RAIL_STRAIGHT_EAST_WEST);
		}
		$cart = VanillaItems::MINECART()->spawnMinecart($this->world, new Vector3(0.5, 1.0625, 0.5));
		//overlapping the west end of the cart
		$stand = new ArmorStand(Location::fromObject(new Vector3(-0.1, 1, 0.5), $this->world));
		$stand->spawnToAll();
		$this->runTicks(10);
		self::assertGreaterThan(0.5, $cart->getLocation()->x, "pushed east, away from it");
		self::assertEqualsWithDelta(0.5, $cart->getLocation()->z, 1e-9, "along the rail");
	}

	public function testNetworkPositionOffRailsAndOnSlope() : void{
		//BDS: a cart off the rails is sent 0.35 above its bottom; on a slope rail.y + 1.5 - d (d along the slope)
		$cart = VanillaItems::MINECART()->spawnMinecart($this->world, new Vector3(5.5, 1, 5.5));
		self::assertEqualsWithDelta(1.35, $cart->getOffsetPosition($cart->getLocation())->y, 1e-9);

		$this->rail(0, 1, 0, Shape::RAIL_ASCENDING_NORTH);
		$cart = VanillaItems::MINECART()->spawnMinecart($this->world, new Vector3(0.5, 1.5625, 0.5));
		self::assertEqualsWithDelta(2.0, $cart->getOffsetPosition($cart->getLocation())->y, 1e-9);
	}

	public function testNetworkPositionAndData() : void{
		$this->rail(0, 1, 0, Shape::RAIL_STRAIGHT_NORTH_SOUTH);
		$cart = VanillaItems::MINECART()->spawnMinecart($this->world, new Vector3(0.5, 1.0625, 0.5), 90.0);
		//BDS sends a cart on a flat rail at rail.y + 0.5 (the cart rests 1/16 above the rail): 0.4375 above the bottom
		$network = $cart->getOffsetPosition($cart->getLocation());
		self::assertEqualsWithDelta(0.5, $network->x, 1e-9);
		self::assertEqualsWithDelta(1.5, $network->y, 1e-9);
		self::assertEqualsWithDelta(0.5, $network->z, 1e-9);

		//clients don't move a cart the server keeps still
		self::assertTrue(self::flag($cart, EntityMetadataFlags::NO_AI));
		self::assertSame(40, self::intProperty($cart, EntityMetadataProperties::HEALTH), "structural integrity");
		$cart->attack(new EntityDamageEvent($cart, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		self::assertSame(30, self::intProperty($cart, EntityMetadataProperties::HEALTH));
		self::assertSame(10, self::intProperty($cart, EntityMetadataProperties::HURT_TIME));

		$this->runTicks(1);
		$packet = (new \ReflectionMethod($cart, "createMovementPacket"))->invoke($cart);
		self::assertInstanceOf(MoveActorAbsolutePacket::class, $packet);
		self::assertSame(MoveActorAbsolutePacket::FLAG_GROUND, $packet->flags & MoveActorAbsolutePacket::FLAG_GROUND, "resting on the rail");
		self::assertEqualsWithDelta(1.5, $packet->position->y, 1e-9);
		self::assertSame(0.0, $packet->headYaw);
	}
}
