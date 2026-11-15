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

use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\PolarBear;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageEvent;

final class PowderSnowFreezeTest extends MobTestCase{

	private function snowPit(int $x) : void{
		for($y = 1; $y <= 3; ++$y){
			for($dx = -1; $dx <= 1; ++$dx){
				for($dz = -1; $dz <= 1; ++$dz){
					$this->set($x + $dx, $y, $dz, $dx === 0 && $dz === 0 ? VanillaBlocks::POWDER_SNOW() : VanillaBlocks::STONE());
				}
			}
		}
	}

	public function testFreezesAndHurts() : void{
		$this->snowPit(0);
		$cow = new Cow(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$this->runTicks(60);
		self::assertGreaterThan(40, $cow->getTicksFrozen());
		$this->runTicks(Living::FREEZE_TICKS + 40);
		self::assertSame(Living::FREEZE_TICKS, $cow->getTicksFrozen());
		self::assertLessThan($cow->getMaxHealth(), $cow->getHealth());
		self::assertSame(EntityDamageEvent::CAUSE_FREEZE, $cow->getLastDamageCause()?->getCause());
		$cow->close();
	}

	public function testThawsOutside() : void{
		$cow = new Cow(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$cow->setTicksFrozen(100);
		$this->runTicks(10);
		self::assertSame(80, $cow->getTicksFrozen());
		$cow->close();
	}

	public function testImmuneMobs() : void{
		$this->snowPit(4);
		$bear = new PolarBear(new Location(4.5, 1, 0.5, $this->world, 0, 0));
		$this->runTicks(40);
		self::assertSame(0, $bear->getTicksFrozen());
		$bear->close();
	}
}
