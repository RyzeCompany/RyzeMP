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

use PHPUnit\Framework\MockObject\MockObject;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\golem\SnowGolem;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\MagmaCube;
use pocketmine\entity\monster\Slime;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\WorldManager;
use function count;
use function mt_srand;

final class SlimeTest extends MobTestCase{

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261019);
		$server = $this->world->getServer();
		if($server instanceof MockObject){
			$worldManager = $this->createMock(WorldManager::class);
			$worldManager->method("findEntity")->willReturnCallback(fn(int $id) => $this->world->getEntity($id));
			$server->method("getWorldManager")->willReturn($worldManager);
		}
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	private function freeze(Mob $mob) : void{
		$mob->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
	}

	public function testBedrockSizes() : void{
		foreach([[1, 1, 0.3, 0.0, 3.0, 0.6], [2, 4, 0.4, 2.0, 4.0, 0.66], [4, 16, 0.6, 4.0, 6.0, 0.75]] as [$size, $health, $speed, $damage, $magmaDamage, $magmaSpeed]){
			$slime = new Slime($this->loc(0.5, 1, 0.5), null, $size);
			self::assertSame($health, $slime->getMaxHealth());
			self::assertEqualsWithDelta($speed, $slime->getMovementSpeed(), 1.0e-6);
			self::assertSame($damage, $slime->getContactDamage());
			self::assertEqualsWithDelta(0.52 * $size, $slime->getSize()->getWidth(), 1.0e-6);
			$magma = new MagmaCube($this->loc(4.5, 1, 0.5), null, $size);
			self::assertSame($health, $magma->getMaxHealth());
			self::assertSame($magmaDamage, $magma->getContactDamage());
			self::assertEqualsWithDelta($magmaSpeed, $magma->getMovementSpeed(), 1.0e-6);
			$slime->flagForDespawn();
			$magma->flagForDespawn();
		}
	}

	public function testLargerSizesFromSavedData() : void{
		$big = new Slime($this->loc(0.5, 1, 0.5), CompoundTag::create()->setInt(Slime::TAG_VARIANT, 8));
		self::assertSame(8, $big->getSlimeSize());
		self::assertSame(64, $big->getMaxHealth());
		self::assertSame(8.0, $big->getContactDamage());
		self::assertEqualsWithDelta(4.16, $big->getSize()->getWidth(), 1.0e-6);

		$invalid = new Slime($this->loc(8.5, 1, 0.5), CompoundTag::create()->setInt(Slime::TAG_VARIANT, 500), 2);
		self::assertSame(2, $invalid->getSlimeSize(), "sizes beyond the limit are ignored");
		self::assertSame(Slime::MAX_SIZE, (new Slime($this->loc(-8.5, 1, 0.5), null, 1000))->getSlimeSize());
	}

	public function testSplitPositions() : void{
		$slime = new Slime($this->loc(0.5, 1, 0.5), null, 4);
		$slime->setPersistent(true);
		$children = $slime->split();
		self::assertGreaterThanOrEqual(2, count($children));
		foreach($children as $child){
			$pos = $child->getPosition();
			self::assertEqualsWithDelta(0.5, $pos->x, 1.0e-6, "above the centre, like BDS");
			self::assertEqualsWithDelta(0.5, $pos->z, 1.0e-6);
			self::assertEqualsWithDelta(1.5, $pos->y, 1.0e-6);
			self::assertTrue($child->isPersistent());
			self::assertSame(2, $child->getSlimeSize());
		}
	}

	public function testFallDamage() : void{
		$slime = new Slime($this->loc(0.5, 12, 0.5), null, 2);
		$magma = new MagmaCube($this->loc(6.5, 12, 0.5), null, 2);
		$this->freeze($slime);
		$this->freeze($magma);
		$slime->spawnToAll();
		$magma->spawnToAll();
		for($i = 0; $i < 60 && (!$slime->isOnGround() || !$magma->isOnGround()); ++$i){
			$this->runTicks(1);
		}
		self::assertLessThan(4.0, $slime->getHealth(), "slimes take fall damage (no damage sensor)");
		self::assertSame(4.0, $magma->getHealth(), "magma cubes don't");
	}

	public function testGolemsAreHurtByTouch() : void{
		$slime = new Slime($this->loc(0.5, 1, 0.5), null, 4);
		$this->freeze($slime);
		$iron = new IronGolem($this->loc(2.2, 1, 0.5));
		$this->freeze($iron);
		$slime->spawnToAll();
		$iron->spawnToAll();
		$this->runTicks(3);
		self::assertLessThan($iron->getMaxHealth(), $iron->getHealth(), "slimes hurt iron golems they touch");

		$magma = new MagmaCube($this->loc(10.5, 1, 0.5), null, 4);
		$this->freeze($magma);
		$snow = new SnowGolem($this->loc(12.0, 1, 0.5));
		$this->freeze($snow);
		$magma->spawnToAll();
		$snow->spawnToAll();
		$this->runTicks(3);
		self::assertSame((float) $snow->getMaxHealth(), $snow->getHealth(), "magma cubes leave snow golems alone");

		$small = new Slime($this->loc(-10.5, 1, 0.5), null, 1);
		$this->freeze($small);
		$snow2 = new SnowGolem($this->loc(-10.0, 1, 0.5));
		$this->freeze($snow2);
		$small->spawnToAll();
		$snow2->spawnToAll();
		$this->runTicks(3);
		self::assertSame((float) $snow2->getMaxHealth(), $snow2->getHealth(), "small slimes are harmless");
	}

	public function testGolemsAreTargetedLikePlayers() : void{
		//one target goal for players and golems: the nearest golem wins, whatever its kind
		$slime = new Slime($this->loc(0.5, 1, 0.5), null, 4);
		$this->freeze($slime);
		$iron = new IronGolem($this->loc(8.5, 1, 0.5));
		$this->freeze($iron);
		$snow = new SnowGolem($this->loc(0.5, 1, 5.5));
		$this->freeze($snow);
		foreach([$slime, $iron, $snow] as $mob){
			$mob->spawnToAll();
		}
		for($i = 0; $i < 100 && $slime->getAttackTarget() === null; ++$i){
			$this->runTicks(1);
		}
		self::assertSame($snow, $slime->getAttackTarget(), "the nearest golem");

		//magma cubes: iron golems only, even with a snow golem closer
		$magma = new MagmaCube($this->loc(0.5, 1, -10.5), null, 4);
		$this->freeze($magma);
		$snow2 = new SnowGolem($this->loc(3.5, 1, -10.5));
		$this->freeze($snow2);
		$magma->spawnToAll();
		$snow2->spawnToAll();
		for($i = 0; $i < 100 && $magma->getAttackTarget() === null; ++$i){
			$this->runTicks(1);
		}
		self::assertSame($iron, $magma->getAttackTarget());
	}

	public function testSlimesFloatInLava() : void{
		for($x = -6; $x <= 6; ++$x){
			for($z = -2; $z <= 2; ++$z){
				for($y = 0; $y <= 3; ++$y){
					$this->set($x, $y, $z, VanillaBlocks::LAVA());
				}
			}
		}
		$magma = new MagmaCube($this->loc(3.5, 1, 0.5), null, 1);
		//a big one: lava burns slimes
		$slime = new Slime($this->loc(-3.5, 1, 0.5), null, 4);
		$magma->spawnToAll();
		$slime->spawnToAll();
		//the test world's lava doesn't last long: look at the first ticks only
		$this->runTicks(6);
		self::assertGreaterThan(1.5, $magma->getPosition()->y, "magma cubes swim up in lava");
		self::assertTrue($slime->isAlive());
		self::assertGreaterThan(1.2, $slime->getPosition()->y, "slimes too (behavior.slime_float: water and lava)");
	}

	public function testDeathSplitInheritsName() : void{
		$slime = new MagmaCube($this->loc(0.5, 1, 0.5), null, 2);
		$slime->setNameTag("Lava Larry");
		$slime->spawnToAll();
		$this->runTicks(1);
		$slime->attack(new EntityDamageEvent($slime, EntityDamageEvent::CAUSE_MAGIC, 100));
		self::assertFalse($slime->isAlive());
		$this->runTicks(25); //it splits when the body is removed, after the death animation
		$found = 0;
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof MagmaCube && $entity !== $slime){
				self::assertSame(1, $entity->getSlimeSize());
				self::assertSame("Lava Larry", $entity->getNameTag());
				++$found;
			}
		}
		self::assertGreaterThanOrEqual(2, $found);
	}
}
