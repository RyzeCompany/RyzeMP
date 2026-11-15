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

namespace pocketmine\block;

use pocketmine\entity\monster\Warden;
use pocketmine\entity\monster\WardenSpawnTracker;
use pocketmine\math\Vector3;
use pocketmine\world\redstone\RedstoneTestCase;
use pocketmine\world\vibration\GameEvent;

/**
 * Sculk shriekers: shrieking, warning levels and warden summoning. Players can't be created in these tests, so the
 * shriekers are set off with the warning trackers of imaginary players.
 */
final class SculkShriekerTest extends RedstoneTestCase{

	private function shrieker(int $x = 0, int $y = 1, int $z = 0) : SculkShrieker{
		$shrieker = $this->get($x, $y, $z);
		self::assertInstanceOf(SculkShrieker::class, $shrieker);
		return $shrieker;
	}

	public function testPlacedShriekerShrieksWithoutWarning() : void{
		$this->floor(-8, -8, 8, 8);
		$this->set(0, 1, 0, VanillaBlocks::SCULK_SHRIEKER());
		$tracker = new WardenSpawnTracker($this->tick);
		self::assertFalse($this->shrieker()->canRespond());
		self::assertTrue($this->shrieker()->tryShriekWithTrackers([$tracker]));
		self::assertTrue($this->shrieker()->isActive());
		self::assertSame(0, $tracker->getWarningLevel($this->tick), "player-placed shriekers don't warn");
		self::assertFalse($this->shrieker()->tryShriekWithTrackers([$tracker]), "already shrieking");

		$this->runTicks(SculkShrieker::SHRIEKING_TICKS - 1);
		self::assertTrue($this->shrieker()->isActive());
		$this->runTicks(1);
		self::assertFalse($this->shrieker()->isActive());
	}

	public function testNaturalShriekerRaisesWarningLevel() : void{
		$this->floor(-8, -8, 8, 8);
		$this->set(0, 1, 0, VanillaBlocks::SCULK_SHRIEKER()->setCanSummon(true));
		$tracker = new WardenSpawnTracker($this->tick);
		$other = new WardenSpawnTracker($this->tick);
		self::assertTrue($this->shrieker()->canRespond());
		self::assertTrue($this->shrieker()->tryShriekWithTrackers([$tracker, $other]));
		self::assertSame(1, $tracker->getWarningLevel($this->tick));
		self::assertSame(1, $other->getWarningLevel($this->tick), "shared with the players around");

		$this->runTicks(SculkShrieker::SHRIEKING_TICKS);
		self::assertFalse($this->shrieker()->isActive());
		self::assertFalse($this->shrieker()->tryShriekWithTrackers([$tracker]), "on warning cooldown: no shriek");
		self::assertSame(1, $tracker->getWarningLevel($this->tick));

		$this->runTicks(WardenSpawnTracker::WARNING_COOLDOWN_TICKS);
		self::assertTrue($this->shrieker()->tryShriekWithTrackers([$tracker]));
		self::assertSame(2, $tracker->getWarningLevel($this->tick));
	}

	public function testPeacefulShriekerDoesNotWarn() : void{
		$this->difficulty = 0;
		$this->floor(-8, -8, 8, 8);
		$this->set(0, 1, 0, VanillaBlocks::SCULK_SHRIEKER()->setCanSummon(true));
		self::assertFalse($this->shrieker()->canRespond());
	}

	public function testFourthWarningSummonsAWarden() : void{
		$this->floor(-8, -8, 8, 8);
		$this->set(0, 1, 0, VanillaBlocks::SCULK_SHRIEKER()->setCanSummon(true));
		$tracker = new WardenSpawnTracker($this->tick);
		$tracker->setWarningLevel(3);
		self::assertTrue($this->shrieker()->tryShriekWithTrackers([$tracker]));
		self::assertSame(4, $tracker->getWarningLevel($this->tick));
		$this->runTicks(SculkShrieker::SHRIEKING_TICKS);

		$wardens = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof Warden){
				$wardens[] = $entity;
			}
		}
		self::assertCount(1, $wardens, "a warden emerges at the end of the shriek");
		self::assertTrue($wardens[0]->isEmerging());
		self::assertSame(1.0, $wardens[0]->getPosition()->y, "on top of the floor");

		//a warden nearby: no more warnings
		self::assertNull(WardenSpawnTracker::tryWarnTrackers($this->world, new Vector3(0, 1, 0), [new WardenSpawnTracker($this->tick)]));
	}

	public function testSensorClickReachesShrieker() : void{
		$this->floor(-8, -8, 12, 8);
		$this->set(0, 1, 0, VanillaBlocks::SCULK_SHRIEKER());
		$this->set(4, 1, 0, VanillaBlocks::SCULK_SENSOR());
		$this->runTicks(2);

		//no player caused the vibration: the shrieker doesn't hear the sensor
		$this->world->addGameEvent(new Vector3(6, 1, 0), GameEvent::BLOCK_PLACE);
		$this->runTicks(3);
		$sensor = $this->get(4, 1, 0);
		self::assertInstanceOf(SculkSensor::class, $sensor);
		self::assertTrue($sensor->isActive());
		self::assertFalse($this->world->isBlockUpdateScheduled(0, 1, 0));

		//a player's vibration: the click travels to the shrieker (4 blocks, 4 ticks)
		$this->world->getVibrations()->notifyShriekers(4, 1, 0, 1000);
		self::assertTrue($this->world->isBlockUpdateScheduled(0, 1, 0));
		self::assertSame(1, $this->world->getVibrations()->getStatistics()["pending"]);
		$this->runTicks(4);
		self::assertSame(0, $this->world->getVibrations()->getStatistics()["pending"], "the click arrived (the player is gone)");
		self::assertFalse($this->shrieker()->isActive());

		//too far
		$this->set(-6, 1, 0, VanillaBlocks::SCULK_SHRIEKER());
		$this->world->getVibrations()->notifyShriekers(4, 1, 0, 1000);
		self::assertFalse($this->world->isBlockUpdateScheduled(-6, 1, 0));
	}

	public function testWardenSpawnTrackerDecaysAndSaves() : void{
		$tracker = new WardenSpawnTracker(0);
		$tracker->increaseWarningLevel(0);
		$tracker->increaseWarningLevel(100);
		self::assertSame(1, $tracker->getWarningLevel(100), "cooldown");
		$tracker->increaseWarningLevel(200);
		self::assertSame(2, $tracker->getWarningLevel(200));
		self::assertSame(2, $tracker->getWarningLevel(200 + WardenSpawnTracker::DECREASE_INTERVAL_TICKS - 1));
		self::assertSame(1, $tracker->getWarningLevel(200 + WardenSpawnTracker::DECREASE_INTERVAL_TICKS));

		$nbt = $tracker->save(300 + WardenSpawnTracker::DECREASE_INTERVAL_TICKS);
		$loaded = new WardenSpawnTracker(5000);
		$loaded->load($nbt, 5000);
		self::assertSame(1, $loaded->getWarningLevel(5000));
		self::assertSame(0, $loaded->getWarningLevel(5000 + WardenSpawnTracker::DECREASE_INTERVAL_TICKS));
	}
}
