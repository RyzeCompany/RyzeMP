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

namespace pocketmine\world\redstone;

use pocketmine\block\Block;
use pocketmine\block\ChorusPlant;
use pocketmine\block\Fire;
use pocketmine\block\RedstoneWire;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\utils\RedstoneWireConnection as Connection;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;

/**
 * Connection shapes that became block states in Bedrock 1.26.60: redstone dust (redstone_{north,east,south,west}),
 * fire and chorus plants (minecraft:connection_*).
 */
class ConnectionShapeTest extends RedstoneTestCase{

	/**
	 * Places the block and lets it update itself, like a block placed by a player (World::setBlock() alone only
	 * notifies the direct neighbours).
	 */
	private function place(int $x, int $y, int $z, Block $block) : void{
		$this->set($x, $y, $z, $block);
		$this->get($x, $y, $z)->onNearbyBlockChange();
		$this->get($x, $y, $z)->onPostPlace();
		$this->runTicks(1); //neighbour updates are processed on the next tick
	}

	private function wire(int $x, int $y, int $z) : RedstoneWire{
		$wire = $this->get($x, $y, $z);
		self::assertInstanceOf(RedstoneWire::class, $wire);
		return $wire;
	}

	/**
	 * @return Connection[]
	 * @phpstan-return array<int, Connection>
	 */
	private function shape(int $x, int $y, int $z) : array{
		$wire = $this->wire($x, $y, $z);
		$shape = [];
		foreach(Facing::HORIZONTAL as $face){
			$shape[$face] = $wire->getConnection($face);
		}
		return $shape;
	}

	public function testLonelyDustIsACross() : void{
		$this->floor(-2, -2, 2, 2);
		$this->place(0, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		foreach($this->shape(0, 1, 0) as $connection){
			self::assertSame(Connection::SIDE, $connection);
		}
	}

	public function testLineExtendsToBothEnds() : void{
		$this->floor(-2, -2, 4, 2);
		$this->place(0, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->place(1, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$shape = $this->shape(0, 1, 0);
		self::assertSame(Connection::SIDE, $shape[Facing::EAST]);
		self::assertSame(Connection::SIDE, $shape[Facing::WEST]); //a single connection extends into a straight line
		self::assertSame(Connection::NONE, $shape[Facing::NORTH]);
		self::assertSame(Connection::NONE, $shape[Facing::SOUTH]);
	}

	public function testCorner() : void{
		$this->floor(-2, -2, 2, 2);
		$this->place(0, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->place(1, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->place(0, 1, 1, VanillaBlocks::REDSTONE_WIRE());
		$shape = $this->shape(0, 1, 0);
		self::assertSame(Connection::SIDE, $shape[Facing::EAST]);
		self::assertSame(Connection::SIDE, $shape[Facing::SOUTH]);
		self::assertSame(Connection::NONE, $shape[Facing::WEST]);
		self::assertSame(Connection::NONE, $shape[Facing::NORTH]);
	}

	public function testClimbsUpTheSideOfABlock() : void{
		$this->floor(-2, -2, 3, 2);
		$this->set(1, 1, 0, VanillaBlocks::STONE());
		$this->place(0, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->place(1, 2, 0, VanillaBlocks::REDSTONE_WIRE());
		self::assertSame(Connection::UP, $this->wire(0, 1, 0)->getConnection(Facing::EAST));
		//the upper dust connects down to the lower one
		self::assertSame(Connection::SIDE, $this->wire(1, 2, 0)->getConnection(Facing::WEST));

		//a conductor on top of the lower dust cuts the climb
		$this->set(0, 2, 0, VanillaBlocks::STONE());
		$this->runTicks(1);
		self::assertNotSame(Connection::UP, $this->wire(0, 1, 0)->getConnection(Facing::EAST));
	}

	public function testConnectsToPowerSources() : void{
		$this->floor(-2, -2, 2, 2);
		$this->set(0, 1, -1, VanillaBlocks::REDSTONE_TORCH());
		$this->place(0, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		self::assertSame(Connection::SIDE, $this->wire(0, 1, 0)->getConnection(Facing::NORTH));
		self::assertSame(Connection::NONE, $this->wire(0, 1, 0)->getConnection(Facing::EAST));
	}

	public function testShapeSurvivesStateRoundTrip() : void{
		$this->floor(-2, -2, 2, 2);
		$this->place(0, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->place(1, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->place(0, 1, 1, VanillaBlocks::REDSTONE_WIRE());
		$wire = $this->wire(0, 1, 0);
		$copy = RuntimeBlockStateRegistry::getInstance()->fromStateId($wire->getStateId());
		self::assertInstanceOf(RedstoneWire::class, $copy);
		foreach(Facing::HORIZONTAL as $face){
			self::assertSame($wire->getConnection($face), $copy->getConnection($face));
		}
	}

	public function testFireOnTheGroundHasNoConnections() : void{
		$this->floor(-2, -2, 2, 2);
		$this->set(1, 1, 0, VanillaBlocks::OAK_PLANKS());
		$this->place(0, 1, 0, VanillaBlocks::FIRE());
		$fire = $this->get(0, 1, 0);
		self::assertInstanceOf(Fire::class, $fire);
		foreach([...Facing::HORIZONTAL, Facing::UP] as $face){
			self::assertFalse($fire->hasConnection($face));
		}
	}

	public function testFireClingsToFlammableWalls() : void{
		//no floor below the fire: it hangs on the planks next to it and the leaves above it
		$this->set(1, 5, 0, VanillaBlocks::OAK_PLANKS());
		$this->set(0, 6, 0, VanillaBlocks::OAK_LEAVES());
		$this->place(0, 5, 0, VanillaBlocks::FIRE());
		$fire = $this->get(0, 5, 0);
		self::assertInstanceOf(Fire::class, $fire);
		self::assertTrue($fire->hasConnection(Facing::EAST));
		self::assertTrue($fire->hasConnection(Facing::UP));
		self::assertFalse($fire->hasConnection(Facing::WEST));
		self::assertFalse($fire->hasConnection(Facing::NORTH));
	}

	public function testChorusPlantConnections() : void{
		$this->set(0, 1, 0, VanillaBlocks::END_STONE());
		$this->place(0, 2, 0, VanillaBlocks::CHORUS_PLANT());
		$this->place(0, 3, 0, VanillaBlocks::CHORUS_PLANT());
		$lower = $this->get(0, 2, 0);
		self::assertInstanceOf(ChorusPlant::class, $lower);
		self::assertTrue($lower->hasConnection(Facing::DOWN));
		self::assertTrue($lower->hasConnection(Facing::UP));
		self::assertFalse($lower->hasConnection(Facing::NORTH));
	}
}
