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

namespace pocketmine\entity\projectile;

use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\Location;
use pocketmine\entity\Zombie;
use pocketmine\math\Facing;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;

final class ArrowPiercingTest extends MobTestCase{

	private function hit(Arrow $arrow, Zombie $zombie) : void{
		$result = new RayTraceResult($zombie->getBoundingBox(), Facing::UP, $zombie->getPosition());
		(fn() => $this->onHitEntity($zombie, $result))->call($arrow);
	}

	public function testPiercingArrowGoesThroughOneMobPerLevel() : void{
		$arrow = new Arrow(new Location(0.5, 2, 0.5, $this->world, 0, 0), null, false);
		$arrow->setMotion(new Vector3(0, 0, 2));
		$arrow->setPierceLevel(1);
		$first = new Zombie(new Location(0.5, 1, 3.5, $this->world, 0, 0));
		$second = new Zombie(new Location(0.5, 1, 6.5, $this->world, 0, 0));

		self::assertTrue($arrow->canCollideWith($first));
		$this->hit($arrow, $first);
		self::assertFalse($arrow->isFlaggedForDespawn(), "goes through the first mob");
		self::assertFalse($arrow->canCollideWith($first), "each mob is hit once");
		self::assertGreaterThan(1.9, $arrow->getMotion()->z);
		$this->hit($arrow, $second);
		self::assertTrue($arrow->isFlaggedForDespawn(), "stops in the second");
		foreach([$first, $second, $arrow] as $entity){
			$entity->close();
		}
	}

	public function testNormalArrowStops() : void{
		$arrow = new Arrow(new Location(0.5, 2, 0.5, $this->world, 0, 0), null, false);
		$zombie = new Zombie(new Location(0.5, 1, 3.5, $this->world, 0, 0));
		$this->hit($arrow, $zombie);
		self::assertTrue($arrow->isFlaggedForDespawn());
		$zombie->close();
		$arrow->close();
	}
}
