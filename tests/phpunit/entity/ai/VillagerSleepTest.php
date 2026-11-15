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

namespace pocketmine\entity\ai;

use pocketmine\block\Door;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Location;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;

/**
 * Villagers reaching their beds at night: through doors, and only claiming beds they can reach.
 */
final class VillagerSleepTest extends MobTestCase{

	private function bed(int $x, int $y, int $z) : Vector3{
		$bed = VanillaBlocks::BED()->setFacing(Facing::SOUTH);
		$this->set($x, $y, $z, (clone $bed)->setHead(false));
		$this->set($x, $y, $z + 1, (clone $bed)->setHead(true));
		return new Vector3($x, $y, $z + 1);
	}

	private function villager(float $x, float $z) : VillagerV2{
		$villager = new VillagerV2(new Location($x, 1, $z, $this->world, 0, 0));
		$villager->setAge(0);
		$villager->spawnToAll();
		return $villager;
	}

	/**
	 * A 5x5 stone hut (walls x 0..4, z 0..4, 3 high, with a roof) with an oak door in the north wall at (2, 1, 0).
	 */
	private function hut() : void{
		for($x = 0; $x <= 4; ++$x){
			for($z = 0; $z <= 4; ++$z){
				$wall = $x === 0 || $x === 4 || $z === 0 || $z === 4;
				for($y = 1; $y <= 3; ++$y){
					$this->set($x, $y, $z, $wall ? VanillaBlocks::STONE() : VanillaBlocks::AIR());
				}
				$this->set($x, 4, $z, VanillaBlocks::STONE());
			}
		}
		$door = VanillaBlocks::OAK_DOOR()->setFacing(Facing::SOUTH);
		$this->set(2, 1, 0, (clone $door)->setTop(false));
		$this->set(2, 2, 0, (clone $door)->setTop(true));
	}

	private function door() : Door{
		$door = $this->get(2, 1, 0);
		self::assertInstanceOf(Door::class, $door);
		return $door;
	}

	public function testOpensTheDoorToReachItsBedAndClosesIt() : void{
		$this->hut();
		$head = $this->bed(2, 1, 2);
		$villager = $this->villager(2.5, -4.5);
		$villager->updateDweller();
		self::assertTrue($villager->getBedPosition()?->equals($head), "claims the bed behind the door");

		$this->world->setTime(13500);
		for($i = 0; $i < 1200 && !$villager->isSleeping(); ++$i){
			$this->runTicks(1);
		}
		self::assertTrue($villager->isSleeping(), "asleep in its bed, at " . $villager->getPosition());
		self::assertFalse($this->door()->isOpen(), "the door was closed behind it");
		$villager->close();
	}

	public function testSwimsToABedAcrossWater() : void{
		//a pond (water at y=1, x/z 4..10) with the bed on a stone island in the middle
		for($x = 4; $x <= 10; ++$x){
			for($z = 4; $z <= 10; ++$z){
				$this->set($x, 1, $z, VanillaBlocks::WATER());
			}
		}
		$this->set(7, 1, 7, VanillaBlocks::STONE());
		$this->set(7, 1, 8, VanillaBlocks::STONE());
		$head = $this->bed(7, 2, 7);
		$villager = $this->villager(0.5, 0.5);
		$villager->updateDweller();
		self::assertTrue($villager->getBedPosition()?->equals($head) ?? false);
		$this->world->setTime(13500);
		for($i = 0; $i < 1200 && !$villager->isSleeping(); ++$i){
			$this->runTicks(1);
		}
		self::assertTrue($villager->isSleeping(), "swam to its bed, at " . $villager->getPosition());
		$villager->close();
	}

	public function testOnlyTheOwnerSleepsInABed() : void{
		$head = $this->bed(0, 1, 0);
		$owner = $this->villager(0.5, -1.5);
		$owner->updateDweller();
		self::assertTrue($owner->getBedPosition()?->equals($head) ?? false);
		$other = $this->villager(1.5, 0.5);
		//a stale claim pointing at the same bed
		(new \ReflectionProperty(VillagerV2::class, "bed"))->setValue($other, $head);
		self::assertFalse($other->startSleeping(), "not its bed");
		self::assertNull($other->getBedPosition(), "forgets the stale claim");
		self::assertTrue($owner->startSleeping());
		self::assertFalse($other->startSleeping());
		$owner->close();
		$other->close();
	}

	public function testDoesNotClaimABedItCannotReach() : void{
		//a bed sealed in stone, and a reachable one farther away
		for($x = -6; $x <= -2; ++$x){
			for($z = -6; $z <= -2; ++$z){
				for($y = 1; $y <= 3; ++$y){
					$this->set($x, $y, $z, VanillaBlocks::STONE());
				}
			}
		}
		$sealed = $this->bed(-4, 1, -5);
		$free = $this->bed(10, 1, 10);
		$villager = $this->villager(0.5, 0.5);
		$villager->updateDweller();
		self::assertFalse($villager->getBedPosition()?->equals($sealed) ?? false, "the sealed bed is skipped");
		$villager->updateDweller();
		self::assertTrue($villager->getBedPosition()?->equals($free) ?? false, "takes the reachable bed");
		$villager->close();
	}
}
