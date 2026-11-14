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
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use function file_exists;
use function range;
use function sort;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class EndGatewaysTest extends DimensionWorldTestCase{
	private string $fightPath;

	protected function getDimension() : Dimension{
		return Dimension::END;
	}

	protected function setUp() : void{
		parent::setUp();
		$this->fightPath = sys_get_temp_dir() . "/pm-end-gateway-test-" . uniqid() . ".json";
		DragonFight::setInstance(new DragonFight($this->fightPath));
	}

	protected function tearDown() : void{
		DragonFight::reset();
		if(file_exists($this->fightPath)){
			unlink($this->fightPath);
		}
		parent::tearDown();
	}

	public function testRingOrderIsASeedDependentPermutation() : void{
		$order = EndGateways::getRingOrder(12345);
		self::assertSame($order, EndGateways::getRingOrder(12345));
		self::assertNotSame($order, EndGateways::getRingOrder(54321));
		$sorted = $order;
		sort($sorted);
		self::assertSame(range(0, EndGateways::COUNT - 1), $sorted);
	}

	public function testRingPositions() : void{
		//the vanilla positions, rounding quirks included (-1 instead of 0)
		self::assertTrue(EndGateways::getRingPosition(0)->equals(new Vector3(96, 75, 0)));
		self::assertTrue(EndGateways::getRingPosition(1)->equals(new Vector3(91, 75, 29)));
		self::assertTrue(EndGateways::getRingPosition(5)->equals(new Vector3(-1, 75, 96)));
		self::assertTrue(EndGateways::getRingPosition(10)->equals(new Vector3(-96, 75, -1)));
		self::assertTrue(EndGateways::getRingPosition(15)->equals(new Vector3(0, 75, -96)));
		self::assertTrue(EndGateways::getRingPosition(19)->equals(new Vector3(91, 75, -30)));
		for($i = 0; $i < EndGateways::COUNT; ++$i){
			$pos = EndGateways::getRingPosition($i);
			self::assertEqualsWithDelta(96.0, $pos->withComponents(null, 0, null)->length(), 1.5, "gateway $i");
		}
	}

	public function testBuild() : void{
		$this->fill(9, 78, -1, 11, 82, 1, VanillaBlocks::END_STONE());
		$exit = new Vector3(1000, 70, 5);
		EndGateways::build($this->world, new Vector3(10, 80, 0), $exit, true);
		self::assertSame(BlockTypeIds::END_GATEWAY, $this->get(10, 80, 0)->getTypeId());
		$tile = $this->world->getTileAt(10, 80, 0);
		self::assertInstanceOf(TileEndGateway::class, $tile);
		self::assertTrue($exit->equals($tile->getExitPortal() ?? Vector3::zero()));
		self::assertTrue($tile->isExactTeleport());
		foreach([79, 81] as $y){
			self::assertSame(BlockTypeIds::BEDROCK, $this->get(10, $y, 0)->getTypeId());
			self::assertSame(BlockTypeIds::BEDROCK, $this->get(9, $y, 0)->getTypeId());
			self::assertSame(BlockTypeIds::BEDROCK, $this->get(10, $y, 1)->getTypeId());
			self::assertSame(BlockTypeIds::AIR, $this->get(9, $y, 1)->getTypeId());
		}
		self::assertSame(BlockTypeIds::BEDROCK, $this->get(10, 78, 0)->getTypeId());
		self::assertSame(BlockTypeIds::BEDROCK, $this->get(10, 82, 0)->getTypeId());
		self::assertSame(BlockTypeIds::AIR, $this->get(9, 78, 0)->getTypeId());
		self::assertSame(BlockTypeIds::AIR, $this->get(11, 80, 0)->getTypeId());
		self::assertSame(BlockTypeIds::AIR, $this->get(11, 80, 1)->getTypeId());
	}

	public function testTileSaveData() : void{
		EndGateways::build($this->world, new Vector3(10, 80, 0), new Vector3(-1000, 60, 20), false);
		$tile = $this->world->getTileAt(10, 80, 0);
		self::assertInstanceOf(TileEndGateway::class, $tile);
		$nbt = $tile->saveNBT();
		$copy = new TileEndGateway($this->world, new Vector3(10, 80, 0));
		$copy->readSaveData($nbt);
		self::assertTrue((new Vector3(-1000, 60, 20))->equals($copy->getExitPortal() ?? Vector3::zero()));
		self::assertFalse($copy->isExactTeleport());
		$copy->readSaveData(new CompoundTag());
		self::assertNull($copy->getExitPortal());
	}

	public function testFindExitXZ() : void{
		$gateway = new Vector3(96, 75, 0);
		//nothing out there: 16 steps outwards from 1024
		self::assertSame([true, 1280.0, 0.0], EndGateways::findExitXZ($gateway, fn(int $cx, int $cz) : bool => true));
		//islands from x 1120: outwards from 1024 until the first one
		self::assertSame([true, 1120.0, 0.0], EndGateways::findExitXZ($gateway, fn(int $cx, int $cz) : bool => $cx < 70));
		//islands from x 912: inwards from 1024 to the first empty chunk, then back out to the island
		self::assertSame([true, 912.0, 0.0], EndGateways::findExitXZ($gateway, fn(int $cx, int $cz) : bool => $cx < 57));
		//a chunk that isn't there yet stops the search
		self::assertSame([false, 64, 0], EndGateways::findExitXZ($gateway, fn(int $cx, int $cz) : ?bool => null));
		self::assertSame([false, 66, 0], EndGateways::findExitXZ($gateway, fn(int $cx, int $cz) : ?bool => $cx < 66 ? true : null));
	}

	public function testFindValidSpawnInChunk() : void{
		self::assertNull(EndGateways::findValidSpawnInChunk($this->world, 2, 0));
		$this->set(40, 50, 5, VanillaBlocks::END_STONE());
		$this->set(35, 60, 10, VanillaBlocks::END_STONE());
		$this->set(33, 40, 1, VanillaBlocks::END_STONE());
		$this->set(33, 41, 1, VanillaBlocks::OBSIDIAN()); //covered: not a spot
		$this->set(36, 20, 1, VanillaBlocks::END_STONE()); //below y 30
		$spawn = EndGateways::findValidSpawnInChunk($this->world, 2, 0);
		self::assertNotNull($spawn);
		self::assertTrue($spawn->equals(new Vector3(40, 50, 5)), "found $spawn");
	}

	public function testIslandAndTallestBlock() : void{
		$origin = new Vector3(50, 70, 20);
		EndGateways::placeIsland($this->world, $origin);
		self::assertSame(BlockTypeIds::END_STONE, $this->get(50, 70, 20)->getTypeId());
		self::assertSame(BlockTypeIds::END_STONE, $this->get(54, 70, 20)->getTypeId(), "the top layer is at least 4 blocks wide");
		self::assertSame(BlockTypeIds::AIR, $this->get(50, 71, 20)->getTypeId());
		$tallest = EndGateways::findTallestBlock($this->world, new Vector3(50, 70, 20), 16, true);
		self::assertSame(70, $tallest->getFloorY());

		//bedrock is skipped unless allowed, and so is the centre column
		$this->set(52, 90, 20, VanillaBlocks::BEDROCK());
		self::assertSame(90, EndGateways::findTallestBlock($this->world, $origin, 5, true)->getFloorY());
		self::assertSame(70, EndGateways::findTallestBlock($this->world, $origin, 5, false)->getFloorY());
		$arrival = EndGateways::getArrivalPosition($this->world, new Vector3(50, 80, 20));
		self::assertSame(71, $arrival->getFloorY());
	}

	public function testDragonKillsSpawnTheTwentyGateways() : void{
		$fight = DragonFight::getInstance();
		$positions = [];
		for($i = 0; $i < EndGateways::COUNT; ++$i){
			$pos = $fight->spawnNextGateway($this->world);
			self::assertNotNull($pos);
			$positions[(string) $pos] = $pos;
			if($pos->equals(new Vector3(96, 75, 0))){
				//its chunks are loaded in the test world: built at once, without an exit
				self::assertSame(BlockTypeIds::END_GATEWAY, $this->get(96, 75, 0)->getTypeId());
				$tile = $this->world->getTileAt(96, 75, 0);
				self::assertInstanceOf(TileEndGateway::class, $tile);
				self::assertNull($tile->getExitPortal());
			}
		}
		self::assertCount(EndGateways::COUNT, $positions);
		self::assertNull($fight->spawnNextGateway($this->world));
		self::assertNull((new DragonFight($this->fightPath))->spawnNextGateway($this->world), "saved");
	}
}
