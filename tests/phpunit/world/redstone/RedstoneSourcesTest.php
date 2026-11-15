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

use pocketmine\block\RedstoneTorch;
use pocketmine\block\Target;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;

class RedstoneSourcesTest extends RedstoneTestCase{

	private function torchLit(int $x, int $y, int $z) : bool{
		$torch = $this->get($x, $y, $z);
		self::assertInstanceOf(RedstoneTorch::class, $torch);
		return $torch->isLit();
	}

	public function testTorchInvertsItsSupport() : void{
		$this->floor(-1, -1, 6, 1);
		$this->set(1, 1, 0, VanillaBlocks::STONE());
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_TORCH()->setFacing(Facing::EAST));
		$this->set(3, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->runTicks(3);
		self::assertTrue($this->torchLit(2, 1, 0));
		self::assertSame(15, $this->wirePower(3, 1, 0));

		//lever on top of the support block
		$this->set(1, 2, 0, VanillaBlocks::LEVER());
		$this->interact(1, 2, 0);
		$this->runTicks(1);
		self::assertTrue($this->torchLit(2, 1, 0), "torch must wait one redstone tick");
		$this->runTicks(2);
		self::assertFalse($this->torchLit(2, 1, 0));
		self::assertSame(0, $this->wirePower(3, 1, 0));

		$this->interact(1, 2, 0);
		$this->runTicks(3);
		self::assertTrue($this->torchLit(2, 1, 0));
		self::assertSame(15, $this->wirePower(3, 1, 0));
	}

	public function testTorchStronglyPowersBlockAbove() : void{
		$this->floor(-1, -1, 6, 1);
		$this->set(1, 1, 0, VanillaBlocks::REDSTONE_TORCH()->setFacing(Facing::UP));
		$this->set(1, 2, 0, VanillaBlocks::STONE());
		$this->set(2, 2, 0, VanillaBlocks::REDSTONE_LAMP());
		$this->runTicks(2);
		self::assertTrue($this->world->isBlockPowered(new Vector3(2, 2, 0)));
	}

	public function testTorchBurnsOutInFastLoop() : void{
		$this->floor(-1, -1, 6, 1);
		$this->set(1, 1, 0, VanillaBlocks::STONE()); //support of the torch
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_TORCH()->setFacing(Facing::EAST));
		$this->set(2, 2, 0, VanillaBlocks::STONE()); //strongly powered by the torch
		$this->set(2, 3, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->set(1, 2, 0, VanillaBlocks::REDSTONE_WIRE()); //steps down and powers the support
		$this->runTicks(60);
		self::assertFalse($this->torchLit(2, 1, 0));
		for($i = 0; $i < 80; ++$i){
			$this->runTicks(1);
			self::assertFalse($this->torchLit(2, 1, 0), "torch must stay burned out");
		}
		$relit = false;
		for($i = 0; $i < 120 && !$relit; ++$i){
			$this->runTicks(1);
			$relit = $this->torchLit(2, 1, 0);
		}
		self::assertTrue($relit, "torch must relight after the burnout cooldown");
	}

	public function testStoneButtonPulse() : void{
		$this->floor(-1, -1, 6, 1);
		$this->set(1, 1, 0, VanillaBlocks::STONE());
		$this->set(1, 2, 0, VanillaBlocks::STONE_BUTTON()->setFacing(Facing::UP));
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->runTicks(1);
		$this->interact(1, 2, 0);
		$this->runTicks(1);
		self::assertSame(15, $this->wirePower(2, 1, 0));
		$this->runTicks(18);
		self::assertSame(15, $this->wirePower(2, 1, 0));
		$this->runTicks(2);
		self::assertSame(0, $this->wirePower(2, 1, 0));
	}

	public function testWeightedPlatePowerLevel() : void{
		$this->floor(-1, -1, 6, 1);
		$this->set(1, 1, 0, VanillaBlocks::WEIGHTED_PRESSURE_PLATE_LIGHT()->setOutputSignalStrength(7));
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->runTicks(1);
		self::assertSame(7, $this->wirePower(2, 1, 0));
	}

	public function testDaylightSensorOutput() : void{
		$this->floor(-1, -1, 6, 1);
		//the test chunks have no sky light: an inverted sensor gives full power once its tile starts the update loop
		$this->set(1, 1, 0, VanillaBlocks::DAYLIGHT_SENSOR()->setInverted(true));
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->runTicks(3);
		self::assertSame(15, $this->wirePower(2, 1, 0));
	}

	public function testTargetHitPower() : void{
		self::assertSame(15, Target::calculateHitPower(Facing::UP, new Vector3(10.5, 65, 3.5)));
		self::assertSame(1, Target::calculateHitPower(Facing::UP, new Vector3(10.99, 65, 3.5)));
		self::assertSame(8, Target::calculateHitPower(Facing::EAST, new Vector3(11, 65.75, 3.5)));
	}

	public function testTargetTransientPower() : void{
		$this->floor(-1, -1, 6, 1);
		$this->set(1, 1, 0, VanillaBlocks::TARGET());
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->redstone()->setTransientPower(1, 1, 0, 12);
		$this->redstone()->updateAround(1, 1, 0);
		$this->runTicks(1);
		self::assertSame(12, $this->wirePower(2, 1, 0));
	}
}
