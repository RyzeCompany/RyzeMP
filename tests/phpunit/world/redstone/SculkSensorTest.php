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

use pocketmine\block\SculkSensor;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\vibration\VibrationManager;

class SculkSensorTest extends RedstoneTestCase{

	private function sensor(int $x, int $y, int $z) : SculkSensor{
		$sensor = $this->get($x, $y, $z);
		self::assertInstanceOf(SculkSensor::class, $sensor);
		return $sensor;
	}

	public function testPowerForDistance() : void{
		self::assertSame(15, VibrationManager::getPowerForDistance(0, 8));
		self::assertSame(14, VibrationManager::getPowerForDistance(1, 8));
		self::assertSame(8, VibrationManager::getPowerForDistance(4, 8));
		self::assertSame(1, VibrationManager::getPowerForDistance(8, 8));
		self::assertSame(8, VibrationManager::getPowerForDistance(8, 16));
	}

	public function testFrequencies() : void{
		self::assertSame(1, GameEvent::STEP->getFrequency());
		self::assertSame(12, GameEvent::BLOCK_DESTROY->getFrequency());
		self::assertSame(13, GameEvent::BLOCK_PLACE->getFrequency());
		self::assertSame(15, GameEvent::EXPLODE->getFrequency());
	}

	public function testSensorPhasesAndOutput() : void{
		$this->floor(-2, -2, 12, 2);
		$this->set(0, 1, 0, VanillaBlocks::SCULK_SENSOR());
		$this->set(-1, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->runTicks(1);

		$this->world->addGameEvent(new Vector3(4, 1, 0), GameEvent::BLOCK_PLACE);
		$this->runTicks(3);
		self::assertSame(SculkSensor::PHASE_INACTIVE, $this->sensor(0, 1, 0)->getPhase(), "the vibration travels one block per tick");
		$this->runTicks(1);
		$sensor = $this->sensor(0, 1, 0);
		self::assertSame(SculkSensor::PHASE_ACTIVE, $sensor->getPhase());
		self::assertSame(8, $sensor->getOutputPower());
		self::assertSame(13, $sensor->getComparatorOutput());
		self::assertSame(8, $this->wirePower(-1, 1, 0));
		self::assertTrue($this->world->isBlockPowered(new Vector3(0, -1, 0)), "the block below is strongly powered");

		//deaf while active
		$this->world->addGameEvent(new Vector3(1, 1, 0), GameEvent::EXPLODE);
		$this->runTicks(SculkSensor::ACTIVE_TICKS - 1);
		self::assertSame(8, $this->sensor(0, 1, 0)->getOutputPower());
		$this->runTicks(1);
		self::assertSame(SculkSensor::PHASE_COOLDOWN, $this->sensor(0, 1, 0)->getPhase());
		self::assertSame(0, $this->wirePower(-1, 1, 0));

		//deaf while cooling down
		$this->world->addGameEvent(new Vector3(1, 1, 0), GameEvent::EXPLODE);
		$this->runTicks(SculkSensor::COOLDOWN_TICKS);
		self::assertSame(SculkSensor::PHASE_INACTIVE, $this->sensor(0, 1, 0)->getPhase());
		$this->runTicks(5);
		self::assertSame(SculkSensor::PHASE_INACTIVE, $this->sensor(0, 1, 0)->getPhase());
	}

	public function testOutOfRange() : void{
		$this->floor(-2, -2, 12, 2);
		$this->set(0, 1, 0, VanillaBlocks::SCULK_SENSOR());
		$this->world->addGameEvent(new Vector3(9, 1, 0), GameEvent::BLOCK_PLACE);
		$this->runTicks(12);
		self::assertSame(SculkSensor::PHASE_INACTIVE, $this->sensor(0, 1, 0)->getPhase());
	}

	public function testWoolOccludes() : void{
		$this->floor(-2, -2, 12, 2);
		$this->set(0, 1, 0, VanillaBlocks::SCULK_SENSOR());
		$this->set(2, 1, 0, VanillaBlocks::WOOL()->setColor(DyeColor::WHITE));
		$this->world->addGameEvent(new Vector3(4, 1, 0), GameEvent::BLOCK_PLACE);
		$this->runTicks(6);
		self::assertSame(SculkSensor::PHASE_INACTIVE, $this->sensor(0, 1, 0)->getPhase());

		//placing or breaking wool is silent
		$this->world->addGameEvent(new Vector3(0, 1, 2), GameEvent::BLOCK_PLACE, null, VanillaBlocks::WOOL());
		$this->runTicks(4);
		self::assertSame(SculkSensor::PHASE_INACTIVE, $this->sensor(0, 1, 0)->getPhase());
	}

	public function testClosestVibrationWins() : void{
		$this->floor(-2, -2, 12, 2);
		$this->set(0, 1, 0, VanillaBlocks::SCULK_SENSOR());
		$this->world->addGameEvent(new Vector3(6, 1, 0), GameEvent::EXPLODE);
		$this->world->addGameEvent(new Vector3(2, 1, 0), GameEvent::STEP);
		$this->runTicks(2);
		$sensor = $this->sensor(0, 1, 0);
		self::assertSame(SculkSensor::PHASE_ACTIVE, $sensor->getPhase());
		self::assertSame(1, $sensor->getComparatorOutput());
		//the farther vibration's stale update must not end the active phase early
		$this->runTicks(6);
		self::assertSame(SculkSensor::PHASE_ACTIVE, $this->sensor(0, 1, 0)->getPhase());
	}

	public function testBlockPlaceAndBreakAreDetected() : void{
		$this->floor(-2, -2, 12, 2);
		$this->set(0, 1, 0, VanillaBlocks::SCULK_SENSOR());
		$this->breakBlock(3, 0, 0);
		$this->runTicks(4);
		$sensor = $this->sensor(0, 1, 0);
		self::assertSame(SculkSensor::PHASE_ACTIVE, $sensor->getPhase());
		self::assertSame(GameEvent::BLOCK_DESTROY->getFrequency(), $sensor->getComparatorOutput());
	}

	public function testCalibratedSensorFiltersFrequency() : void{
		$this->floor(-2, -2, 20, 2);
		//input on the west side: a weighted plate gives exactly 13 (block place)
		$this->set(0, 1, 0, VanillaBlocks::CALIBRATED_SCULK_SENSOR()->setFacing(Facing::WEST));
		$this->set(-1, 1, 0, VanillaBlocks::WEIGHTED_PRESSURE_PLATE_LIGHT()->setOutputSignalStrength(GameEvent::BLOCK_DESTROY->getFrequency()));
		$this->set(0, 1, 1, VanillaBlocks::REDSTONE_WIRE());
		$this->runTicks(1);

		$this->world->addGameEvent(new Vector3(12, 1, 0), GameEvent::BLOCK_PLACE);
		$this->runTicks(14);
		self::assertSame(SculkSensor::PHASE_INACTIVE, $this->sensor(0, 1, 0)->getPhase(), "filtered: only frequency 12");

		$this->world->addGameEvent(new Vector3(12, 1, 0), GameEvent::BLOCK_DESTROY);
		$this->runTicks(12);
		$sensor = $this->sensor(0, 1, 0);
		self::assertSame(SculkSensor::PHASE_ACTIVE, $sensor->getPhase(), "calibrated sensors hear 16 blocks away");
		self::assertSame(VibrationManager::getPowerForDistance(12, 16), $this->wirePower(0, 1, 1));
		self::assertSame(0, $sensor->getWeakPower(Facing::WEST), "no output on the input side");
	}

	public function testFallingItemIsHeard() : void{
		$this->floor(-2, -2, 12, 2);
		$this->set(0, 1, 0, VanillaBlocks::SCULK_SENSOR());
		$this->world->dropItem(new Vector3(3.5, 3, 0.5), VanillaItems::STICK(), Vector3::zero());
		$heard = false;
		for($i = 0; $i < 40 && !$heard; ++$i){
			$this->runTicks(1);
			$heard = $this->sensor(0, 1, 0)->isActive();
		}
		self::assertTrue($heard, "an item hitting the ground makes a vibration");
		self::assertSame(GameEvent::HIT_GROUND->getFrequency(), $this->sensor(0, 1, 0)->getComparatorOutput());
	}

	public function testItemLandingOnWoolIsSilent() : void{
		$this->floor(-2, -2, 12, 2);
		$this->set(0, 1, 0, VanillaBlocks::SCULK_SENSOR());
		$this->set(3, 1, 0, VanillaBlocks::WOOL());
		$this->world->dropItem(new Vector3(3.5, 4, 0.5), VanillaItems::STICK(), Vector3::zero());
		$this->runTicks(40);
		self::assertSame(SculkSensor::PHASE_INACTIVE, $this->sensor(0, 1, 0)->getPhase());
	}

	public function testSensorIndexFollowsBlockChanges() : void{
		$this->floor(-2, -2, 12, 2);
		$this->world->addGameEvent(new Vector3(0, 1, 0), GameEvent::BLOCK_PLACE); //indexes the chunks (no sensors)
		self::assertSame(0, $this->world->getVibrations()->getStatistics()["sensors"]);
		$this->set(5, 1, 0, VanillaBlocks::SCULK_SENSOR());
		self::assertSame(1, $this->world->getVibrations()->getStatistics()["sensors"]);
		$this->breakBlock(5, 1, 0);
		self::assertSame(0, $this->world->getVibrations()->getStatistics()["sensors"]);
	}
}
