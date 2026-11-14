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
use pocketmine\entity\monster\Creaking;
use pocketmine\entity\monster\Warden;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\math\Vector3;
use pocketmine\nbt\BaseNbtSerializer;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;

/**
 * Positions saved as TAG_IntArray: pocketmine/nbt reads the values back as unsigned (-418 as 4294966878). A villager
 * whose bed had a negative coordinate crashed the server on the save after a chunk reload (the bed is its home, saved
 * as TAG_Int, which rejects 4294966878).
 */
final class NegativeIntArrayPositionsTest extends MobTestCase{

	private function loc() : Location{
		return new Location(0.5, 1, 0.5, $this->world, 0, 0);
	}

	/**
	 * @return array[]
	 * @phpstan-return list<array{string, BaseNbtSerializer}>
	 */
	private static function serializers() : array{
		return [["little endian (worlds)", new LittleEndianNbtSerializer()], ["big endian", new BigEndianNbtSerializer()]];
	}

	private static function throughDisk(BaseNbtSerializer $serializer, CompoundTag $nbt) : CompoundTag{
		return $serializer->read($serializer->write(new TreeRoot($nbt)))->mustGetCompoundTag();
	}

	public function testVillagerBedAndJobSite() : void{
		$bed = new Vector3(-418, -3, -26);
		$jobSite = new Vector3(-420, -64, -2147483648);

		$villager = new VillagerV2($this->loc());
		$nbt = $villager->saveNBT();
		$villager->flagForDespawn();
		$nbt->setIntArray(VillagerV2::TAG_BED, [-418, -3, -26]);
		$nbt->setIntArray(VillagerV2::TAG_JOB_SITE, [-420, -64, -2147483648]);

		foreach(self::serializers() as [$name, $serializer]){
			$read = self::throughDisk($serializer, $nbt);
			self::assertSame([4294966878, 4294967293, 4294967270], $read->getIntArray(VillagerV2::TAG_BED), "$name: the cause, unsigned values");

			$loaded = new VillagerV2($this->loc(), $read);
			self::assertEquals($bed, $loaded->getBedPosition(), $name);
			self::assertEquals($jobSite, $loaded->getJobSitePosition(), $name);
			self::assertEquals($bed, $loaded->getHomePos(), "$name: the bed is the home");

			//threw InvalidTagValueException (HomeZ 4294966878) before
			$saved = $loaded->saveNBT();
			$loaded->flagForDespawn();
			self::assertSame([-418, -3, -26], $saved->getIntArray(VillagerV2::TAG_BED), $name);
			self::assertSame([-420, -64, -2147483648], $saved->getIntArray(VillagerV2::TAG_JOB_SITE), $name);
			self::assertSame(-26, $saved->getInt(Mob::TAG_HOME_Z), $name);

			//and again: what was saved loads the same
			$again = new VillagerV2($this->loc(), self::throughDisk($serializer, $saved));
			self::assertEquals($bed, $again->getBedPosition(), $name);
			$again->flagForDespawn();
		}

		//signed values (a CompoundTag that never went through a serializer) are read as they are
		$direct = new VillagerV2($this->loc(), $nbt);
		self::assertEquals($bed, $direct->getBedPosition());
		self::assertEquals($jobSite, $direct->getJobSitePosition());
		$direct->flagForDespawn();

		//positive coordinates are unchanged
		$nbt->setIntArray(VillagerV2::TAG_BED, [418, 3, 2147483647]);
		$positive = new VillagerV2($this->loc(), self::throughDisk(new LittleEndianNbtSerializer(), $nbt));
		self::assertEquals(new Vector3(418, 3, 2147483647), $positive->getBedPosition());
		$positive->flagForDespawn();
	}

	public function testCreakingHeartPosition() : void{
		$creaking = new Creaking($this->loc());
		$creaking->setHeartPosition(new Vector3(-418, -3, -26));
		$nbt = $creaking->saveNBT();
		$creaking->flagForDespawn();
		self::assertSame([-418, -3, -26], $nbt->getIntArray(Creaking::TAG_HOME_POS));

		foreach(self::serializers() as [$name, $serializer]){
			$loaded = new Creaking($this->loc(), self::throughDisk($serializer, $nbt));
			self::assertEquals(new Vector3(-418, -3, -26), $loaded->getHeartPosition(), $name);
			self::assertSame([-418, -3, -26], $loaded->saveNBT()->getIntArray(Creaking::TAG_HOME_POS), $name);
			$loaded->flagForDespawn();
		}
	}

	public function testWardenSuspiciousPosition() : void{
		$warden = new Warden($this->loc());
		$nbt = $warden->saveNBT();
		$warden->flagForDespawn();
		$nbt->setIntArray("SuspiciousPos", [-418, -3, -26]);

		foreach(self::serializers() as [$name, $serializer]){
			$loaded = new Warden($this->loc(), self::throughDisk($serializer, $nbt));
			self::assertEquals(new Vector3(-418, -3, -26), $loaded->getSuspiciousLocation(), $name);
			self::assertSame([-418, -3, -26], $loaded->saveNBT()->getIntArray("SuspiciousPos"), $name);
			$loaded->flagForDespawn();
		}
	}
}
