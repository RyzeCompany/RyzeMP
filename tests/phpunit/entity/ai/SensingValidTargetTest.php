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

use pocketmine\entity\animal\Cow;
use pocketmine\entity\Location;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\entity\Zombie;
use pocketmine\world\World;

/**
 * Sensing::isValidTarget() compares the worlds of the mob and its target on their live locations: the same answers
 * as Entity::getWorld(), also for classes overriding getWorld().
 */
final class SensingValidTargetTest extends MobTestCase{

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	public function testSameWorld() : void{
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$cow = new Cow($this->loc(10.5, 1, 0.5));
		$sensing = $zombie->getSensing();
		self::assertTrue($sensing->isValidTarget($cow, 16.0));
		self::assertFalse($sensing->isValidTarget($cow, 8.0), "out of range");
		self::assertFalse($sensing->isValidTarget($zombie, 16.0), "not its own target");
		$cow->close();
		self::assertFalse($sensing->isValidTarget($cow, 16.0), "closed");
	}

	public function testOtherWorld() : void{
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$cow = new Cow($this->loc(3.5, 1, 0.5));
		$elsewhere = $this->createMock(World::class);
		$elsewhere->method("isLoaded")->willReturn(true);
		$cow->getLiveLocation()->world = $elsewhere;
		try{
			self::assertNotSame($zombie->getWorld(), $cow->getWorld());
			self::assertFalse($zombie->getSensing()->isValidTarget($cow, 16.0), "a target in another world");
		}finally{
			$cow->getLiveLocation()->world = $this->world;
		}
	}

	public function testClassOverridingGetWorld() : void{
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$world = $this->world;
		//a plugin entity whose getWorld() isn't the world of its location: getWorld() decides, as before
		$cow = new class($this->loc(3.5, 1, 0.5), $world) extends Cow implements NeverSavedWithChunkEntity{
			public function __construct(Location $location, private World $reported){
				parent::__construct($location);
			}

			public function getWorld() : World{
				return $this->reported;
			}
		};
		$elsewhere = $this->createMock(World::class);
		$elsewhere->method("isLoaded")->willReturn(true);
		$cow->getLiveLocation()->world = $elsewhere;
		try{
			self::assertTrue($zombie->getSensing()->isValidTarget($cow, 16.0));
		}finally{
			$cow->getLiveLocation()->world = $world;
		}
	}
}
