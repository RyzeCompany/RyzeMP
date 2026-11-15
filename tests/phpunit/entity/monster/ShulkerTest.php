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

namespace pocketmine\entity\monster;

use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\Location;
use pocketmine\entity\projectile\ShulkerBullet;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use function array_filter;
use function array_values;
use function mt_srand;

/**
 * Shulkers: attachment to walls and ceilings, closed shell armor, duplication when hit by a shulker bullet.
 */
final class ShulkerTest extends MobTestCase{

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261021);
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * @return Shulker[]
	 * @phpstan-return list<Shulker>
	 */
	private function shulkers() : array{
		return array_values(array_filter($this->world->getEntities(), fn($e) => $e instanceof Shulker && !$e->isClosed() && $e->isAlive()));
	}

	public function testWallAndCeilingShulkersStayAttached() : void{
		//a wall east of (2, 2, 0) and a ceiling above (-3, 2, 0), with air below both spots
		$this->set(3, 2, 0, VanillaBlocks::STONE());
		$this->set(-3, 3, 0, VanillaBlocks::STONE());
		$wall = new Shulker($this->loc(2.5, 2, 0.5));
		$ceiling = new Shulker($this->loc(-2.5, 2, 0.5));
		$this->runTicks(45);
		self::assertSame(Facing::EAST, $wall->getAttachFace(), "nothing below: it attaches to the wall");
		self::assertSame(Facing::UP, $ceiling->getAttachFace(), "it hangs from the ceiling");
		self::assertTrue($wall->getPosition()->equals(new Vector3(2.5, 2, 0.5)), "it doesn't jump away from the wall");
		self::assertTrue($ceiling->getPosition()->equals(new Vector3(-2.5, 2, 0.5)), "it doesn't jump away from the ceiling");

		//saved and loaded again, it keeps its face
		$nbt = $wall->saveNBT();
		$wall->flagForDespawn();
		$loaded = new Shulker($this->loc(2.5, 2, 0.5), $nbt);
		self::assertSame(Facing::EAST, $loaded->getAttachFace());
		$this->runTicks(45);
		self::assertSame(Facing::EAST, $loaded->getAttachFace());
		self::assertTrue($loaded->getPosition()->equals(new Vector3(2.5, 2, 0.5)));

		//the wall goes away: it moves to another face or somewhere else
		$this->set(3, 2, 0, VanillaBlocks::AIR());
		$this->runTicks(21);
		self::assertFalse($loaded->getPosition()->equals(new Vector3(2.5, 2, 0.5)) && $loaded->getAttachFace() === Facing::EAST, "it doesn't stay on a missing wall");
	}

	public function testClosedShellIsArmored() : void{
		$closed = new Shulker($this->loc(0.5, 1, 0.5));
		$open = new Shulker($this->loc(4.5, 1, 0.5));
		$open->setPeek(Shulker::PEEK_OPEN);
		$cow = new Cow($this->loc(2.5, 1, 4.5));
		$cow->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK, true);
		self::assertSame(Shulker::CLOSED_ARMOR, $closed->getArmorPoints());
		self::assertSame(0, $open->getArmorPoints());

		$hitClosed = new EntityDamageByEntityEvent($cow, $closed, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 10);
		$closed->attack($hitClosed);
		$hitOpen = new EntityDamageByEntityEvent($cow, $open, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 10);
		$open->attack($hitOpen);
		self::assertEqualsWithDelta(10.0, $hitOpen->getFinalDamage(), 0.001);
		self::assertLessThan(5.0, $hitClosed->getFinalDamage(), "the closed shell takes much less melee damage");
		self::assertGreaterThan(0.0, $hitClosed->getFinalDamage());
	}

	/**
	 * Hits the shulker with bullets until it teleports (shots may find no spot to teleport to). Returns whether it did.
	 */
	private function shootUntilTeleport(Shulker $shulker, Cow $shooter) : bool{
		for($i = 0; $i < 60; ++$i){
			$this->runTicks(11); //past the hurt cooldown
			$shulker->setHealth($shulker->getMaxHealth());
			$shulker->setPeek(Shulker::PEEK_OPEN);
			$before = $shulker->getPosition();
			$bullet = new ShulkerBullet($this->loc($before->x, $before->y + 0.5, $before->z), $shooter, null);
			$shulker->attack(new EntityDamageByChildEntityEvent($shooter, $bullet, $shulker, EntityDamageEvent::CAUSE_PROJECTILE, ShulkerBullet::DAMAGE));
			$bullet->flagForDespawn();
			if(!$shulker->getPosition()->equals($before)){
				return true;
			}
		}
		return false;
	}

	public function testBulletHitDuplicatesALonelyShulker() : void{
		$shulker = new Shulker($this->loc(0.5, 1, 0.5));
		$shulker->setColor(DyeColor::LIME);
		$shooter = new Cow($this->loc(20.5, 1, 20.5));
		$shooter->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK, true);
		self::assertTrue($this->shootUntilTeleport($shulker, $shooter), "an open shulker hit by a bullet teleports");
		$shulkers = $this->shulkers();
		self::assertCount(2, $shulkers, "alone, it always leaves a copy");
		$copy = $shulkers[0] === $shulker ? $shulkers[1] : $shulkers[0];
		self::assertTrue($copy->getPosition()->equals(new Vector3(0.5, 1, 0.5)), "the copy is where it was");
		self::assertSame(DyeColor::LIME, $copy->getColor());
	}

	public function testCrowdedShulkersDontDuplicate() : void{
		$shulker = new Shulker($this->loc(0.5, 1, 0.5));
		foreach([[3, 0], [-3, 0], [0, 3], [0, -3], [3, 3]] as [$x, $z]){
			new Shulker($this->loc($x + 0.5, 1, $z + 0.5));
		}
		$shooter = new Cow($this->loc(20.5, 1, 20.5));
		$shooter->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK, true);
		self::assertTrue($this->shootUntilTeleport($shulker, $shooter));
		self::assertCount(6, $this->shulkers(), "with 5 other shulkers around, no copy");
	}

	public function testClosedShulkerHitByBulletDoesNothing() : void{
		$shulker = new Shulker($this->loc(0.5, 1, 0.5));
		$shooter = new Cow($this->loc(20.5, 1, 20.5));
		$this->runTicks(1);
		$bullet = new ShulkerBullet($this->loc(0.5, 1.5, 0.5), $shooter, null);
		$ev = new EntityDamageByChildEntityEvent($shooter, $bullet, $shulker, EntityDamageEvent::CAUSE_PROJECTILE, ShulkerBullet::DAMAGE);
		$shulker->attack($ev);
		self::assertTrue($ev->isCancelled());
		self::assertCount(1, $this->shulkers());
		self::assertTrue($shulker->getPosition()->equals(new Vector3(0.5, 1, 0.5)));
	}
}
