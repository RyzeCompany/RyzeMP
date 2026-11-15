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

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\navigation\PathNavigator;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\Attribute;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Vindicator;
use pocketmine\entity\monster\Warden;
use pocketmine\entity\monster\WardenAnger;
use pocketmine\entity\monster\Zoglin;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\Zombie;
use pocketmine\math\Vector3;
use function min;
use function mt_srand;
use function sprintf;

/**
 * Melee mobs close to their target walk straight at it, but not off a cliff, into a hole or into lava: no further than
 * a path would take them. Paths avoid lava and fire, and drop at most the mob's max fall: Bedrock
 * minecraft:preferred_path max_fall_blocks, 3 for most mobs, 20 for the warden, 1 for iron golems and villagers, with
 * or without a target (Java lets a mob with a target and enough health drop further).
 */
final class MeleeLedgeTest extends MobTestCase{

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261019);
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * A cow that doesn't move by itself and can take a lot of damage.
	 */
	private function dummy(float $x, float $y, float $z) : Cow{
		$cow = new Cow($this->loc($x, $y, $z));
		$cow->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$cow->setMaxHealth(100000);
		$cow->setHealth(100000);
		return $cow;
	}

	/**
	 * The floor ends at x = 2: east of it is a pit (x 3..12, z -6..6), 10 blocks deep by default.
	 */
	private function cliff(int $depth = 10) : void{
		for($x = 3; $x <= 12; ++$x){
			for($z = -6; $z <= 6; ++$z){
				$this->world->setBlockAt($x, 0, $z, VanillaBlocks::AIR(), false);
				$this->world->setBlockAt($x, -$depth, $z, VanillaBlocks::STONE(), false);
			}
		}
	}

	/**
	 * @phpstan-return array<string, array{\Closure(Location) : Mob}>
	 */
	public static function meleeMobProvider() : array{
		return [
			"zombie" => [fn(Location $l) => new Zombie($l)],
			"zoglin" => [fn(Location $l) => new Zoglin($l)],
			"vindicator" => [fn(Location $l) => new Vindicator($l)],
		];
	}

	/**
	 * @dataProvider meleeMobProvider
	 * @phpstan-param \Closure(Location) : Mob $factory
	 */
	public function testDoesNotFollowTargetKnockedOverTheEdge(\Closure $factory) : void{
		$this->cliff();
		$mob = $factory($this->loc(-1.5, 1, 0.5));
		$cow = $this->dummy(2.3, 1, 0.5);
		$mob->setAttackTarget($cow);
		$this->assertStaysUpWhileTargetFalls($mob, $cow);
	}

	public function testMaxDrops() : void{
		$loc = $this->loc(0.5, 1, 0.5);
		self::assertSame(3, (new Zombie($loc))->getNavigation()->getMaxDrop());
		self::assertSame(20, (new Warden($loc))->getNavigation()->getMaxDrop());
		self::assertSame(1, (new IronGolem($loc))->getNavigation()->getMaxDrop());
		self::assertSame(1, (new VillagerV2($loc))->getNavigation()->getMaxDrop());
	}

	/**
	 * Wardens path down drops of up to 20 blocks: a pit deeper than that.
	 */
	public function testWardenDoesNotFollowTargetKnockedOverTheEdge() : void{
		$this->cliff(24);
		$warden = new Warden($this->loc(-1.5, 1, 0.5));
		$warden->setNameTag("test"); //named wardens don't dig back
		$cow = $this->dummy(2.3, 1, 0.5);
		$this->runTicks(2);
		$warden->increaseAnger($cow, WardenAnger::MAX_ANGER);
		$this->assertStaysUpWhileTargetFalls($warden, $cow);
	}

	public function testWardenFollowsTargetDownDropWithinItsFall() : void{
		$this->cliff();
		$warden = new Warden($this->loc(-1.5, 1, 0.5));
		$warden->setNameTag("test");
		$cow = $this->dummy(6.5, -9, 0.5);
		$this->runTicks(2);
		$warden->increaseAnger($cow, WardenAnger::MAX_ANGER);
		$this->runTicks(300);
		self::assertLessThan(-8, $warden->getPosition()->y, "the warden went down the 10 blocks drop to its target");
		self::assertLessThan(100000, $cow->getHealth(), "the target was hit");
	}

	private function assertStaysUpWhileTargetFalls(Mob $mob, Cow $cow) : void{
		$lowest = 1.0;
		for($i = 0; $i < 300; ++$i){
			$this->runTicks(1);
			$lowest = min($lowest, $mob->getPosition()->y);
			if($i === 150 && $cow->getPosition()->y > -5){
				//its hits didn't knock it over: throw it down
				$cow->setMotion(new Vector3(0.8, 0.4, 0));
			}
		}
		self::assertLessThan(-5, $cow->getPosition()->y, "the target is down in the pit");
		self::assertLessThan(100000, $cow->getHealth(), "the target was hit");
		self::assertGreaterThan(0.9, $lowest, sprintf("%s followed its target down the cliff (now at %s)", $mob->getName(), $mob->getPosition()));
		self::assertLessThan(3.0 + $mob->getSize()->getWidth() / 2, $mob->getPosition()->x, "stays on the floor");
	}

	public function testDoesNotWalkIntoLavaToReachTargetAcrossIt() : void{
		//a lava trench at x = 1 between the zombie and its target
		for($z = -10; $z <= 10; ++$z){
			$this->world->setBlockAt(1, 0, $z, VanillaBlocks::LAVA(), false);
		}
		//close enough to go straight at it, not knocked back by the hits: the point where they touch is above the lava
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$cow = $this->dummy(2.4, 1, 0.5);
		$resistance = $cow->getAttributeMap()->get(Attribute::KNOCKBACK_RESISTANCE);
		self::assertNotNull($resistance);
		$resistance->setValue(1.0);
		$zombie->setAttackTarget($cow);
		for($i = 0; $i < 100; ++$i){
			$this->runTicks(1);
			self::assertFalse($zombie->isOnFire(), "walked into the lava at tick $i (at " . $zombie->getPosition() . ")");
			self::assertGreaterThan(0.9, $zombie->getPosition()->y, "stepped into the trench at tick $i");
		}
	}

	/**
	 * A 2 blocks deep trench at x = 2: within the drop of a path.
	 */
	private function trench() : void{
		for($z = -6; $z <= 6; ++$z){
			$this->world->setBlockAt(2, 0, $z, VanillaBlocks::AIR(), false);
			$this->world->setBlockAt(2, -1, $z, VanillaBlocks::AIR(), false);
			$this->world->setBlockAt(2, -2, $z, VanillaBlocks::STONE(), false);
		}
	}

	public function testSafeStepRules() : void{
		$this->trench();
		$world = $this->world;
		self::assertTrue(PathNavigator::isSafeColumn($world, 2, 1, 0, 3, 1), "2 blocks down");
		self::assertTrue(PathNavigator::isSafeColumn($world, 0, 1, 0, 3, 1), "level");
		self::assertFalse(PathNavigator::isSafeColumn($world, 2, 1, 0, 1, 1), "deeper than the mob's drop");
		self::assertTrue(PathNavigator::isSafeColumn($world, 3, 0, 0, 3, 1), "into the side of a block: an obstacle");
		$world->setBlockAt(5, 1, 0, VanillaBlocks::FIRE(), false);
		self::assertFalse(PathNavigator::isSafeColumn($world, 5, 1, 0, 3, 1), "fire");
		$world->setBlockAt(6, 0, 0, VanillaBlocks::MAGMA(), false);
		self::assertFalse(PathNavigator::isSafeColumn($world, 6, 1, 0, 3, 1), "on magma");
		$world->setBlockAt(7, 0, 0, VanillaBlocks::WATER(), false);
		self::assertTrue(PathNavigator::isSafeColumn($world, 7, 1, 0, 3, 1), "into water");
		$world->setBlockAt(8, 1, 0, VanillaBlocks::STONE_SLAB(), false);
		self::assertTrue(PathNavigator::isSafeColumn($world, 8, 1, 0, 3, 1), "onto a slab");
		$world->setBlockAt(9, 0, 0, VanillaBlocks::AIR(), false);
		self::assertFalse(PathNavigator::isSafeColumn($world, 9, 1, 0, 3, 1), "nothing below within reach");
		//an island at the bottom of a void world: paths take the bottom of the world as a floor, a step doesn't
		self::assertFalse(PathNavigator::isSafeColumn($world, 10, $world->getMinY() + 1, 0, 3, 1), "off the bottom of the world");
		$world->setBlockAt(11, $world->getMinY(), 0, VanillaBlocks::STONE(), false);
		self::assertTrue(PathNavigator::isSafeColumn($world, 11, $world->getMinY() + 2, 0, 3, 1), "onto the lowest block of the world");

		//standing on a floor lower than a full block, its feet are in the floor's row: the blocks next to it are steps
		$world->setBlockAt(13, 0, 0, VanillaBlocks::SOUL_SAND(), false);
		self::assertTrue(PathNavigator::isSafeColumn($world, 13, 0, 0, 3, 1), "along a soul sand floor");
		$world->setBlockAt(14, 0, 0, VanillaBlocks::SOUL_SAND(), false);
		$world->setBlockAt(14, 1, 0, VanillaBlocks::SOUL_FIRE(), false);
		self::assertFalse(PathNavigator::isSafeColumn($world, 14, 0, 0, 3, 1), "soul fire on the soul sand next to it");
		$world->setBlockAt(15, 1, 0, VanillaBlocks::CAMPFIRE()->setLit(true), false);
		self::assertFalse(PathNavigator::isSafeColumn($world, 15, 0, 0, 3, 1), "a lit campfire next to a dirt path");
		$world->setBlockAt(16, 1, 0, VanillaBlocks::STONE(), false);
		self::assertTrue(PathNavigator::isSafeColumn($world, 16, 1, 0, 3, 1), "onto a step");
		$world->setBlockAt(17, 1, 0, VanillaBlocks::STONE(), false);
		$world->setBlockAt(17, 2, 0, VanillaBlocks::FIRE(), false);
		self::assertFalse(PathNavigator::isSafeColumn($world, 17, 1, 0, 3, 1), "onto a step with fire on top");
		$world->setBlockAt(18, 1, 0, VanillaBlocks::STONE(), false);
		$world->setBlockAt(18, 2, 0, VanillaBlocks::STONE(), false);
		$world->setBlockAt(18, 3, 0, VanillaBlocks::FIRE(), false);
		self::assertTrue(PathNavigator::isSafeColumn($world, 18, 1, 0, 3, 1), "a wall with fire on top: it bumps into the wall");
		$world->setBlockAt(19, 2, 0, VanillaBlocks::COBWEB(), false);
		self::assertFalse(PathNavigator::isSafeColumn($world, 19, 1, 0, 3, 1), "a cobweb at head height");
		self::assertTrue(PathNavigator::isSafeColumn($world, 19, 1, 0, 3, 0), "a cobweb above a mob a block tall");
	}

	/**
	 * A mob (a zombie by default) walking straight at a target which doesn't move, with something that hurts it in the
	 * column x = 1, all across the floor between them: it doesn't walk into it.
	 *
	 * @phpstan-param (\Closure(Location) : Mob)|null $factory
	 */
	private function assertDoesNotWalkIntoDangerAcross(float $mobY, float $targetY, ?\Closure $factory = null) : void{
		$mob = ($factory ?? fn(Location $l) => new Zombie($l))($this->loc(0.3, $mobY, 0.5));
		$cow = $this->dummy(2.2, $targetY, 0.5);
		$resistance = $cow->getAttributeMap()->get(Attribute::KNOCKBACK_RESISTANCE);
		self::assertNotNull($resistance);
		$resistance->setValue(1.0);
		$mob->setAttackTarget($cow);
		for($i = 0; $i < 100; ++$i){
			$this->runTicks(1);
			self::assertFalse($mob->isOnFire(), "set on fire at tick $i (at " . $mob->getPosition() . ")");
			self::assertSame($mob->getMaxHealth(), (int) $mob->getHealth(), "hurt at tick $i (at " . $mob->getPosition() . ")");
			self::assertLessThan(1.0, $mob->getPosition()->x, "walked into the column x = 1 at tick $i");
		}
	}

	/**
	 * A vindicator whose paths never go through fire, like the mobs which avoid damage blocks: undead mobs walk through
	 * fire (BDS), other mobs only when there is no way round (see NavigationOptions::getCostlyMask()), as across the
	 * lines of fire of these tests, and a path is followed into it. The straight steps towards a target close by are
	 * checked here: they never go into fire.
	 */
	private static function fireWallVindicator(Location $location) : Vindicator{
		$vindicator = new Vindicator($location);
		$navigation = $vindicator->getNavigation();
		$navigation->setOptions($navigation->getOptions()->setAvoidDamageBlocks(true));
		return $vindicator;
	}

	public function testDoesNotWalkIntoSoulFireOnSoulSand() : void{
		for($x = -24; $x <= 24; ++$x){
			for($z = -24; $z <= 24; ++$z){
				$this->world->setBlockAt($x, 0, $z, VanillaBlocks::SOUL_SAND(), false);
			}
		}
		for($z = -24; $z <= 24; ++$z){
			$this->world->setBlockAt(1, 1, $z, VanillaBlocks::SOUL_FIRE(), false);
		}
		$this->assertDoesNotWalkIntoDangerAcross(0.875, 0.875, self::fireWallVindicator(...));
	}

	public function testDoesNotStepFromDirtPathIntoLitCampfire() : void{
		for($x = -24; $x <= 24; ++$x){
			for($z = -24; $z <= 24; ++$z){
				$this->world->setBlockAt($x, 0, $z, $x <= 0 ? VanillaBlocks::GRASS_PATH() : VanillaBlocks::GRASS(), false);
			}
		}
		for($z = -24; $z <= 24; ++$z){
			$this->world->setBlockAt(1, 1, $z, VanillaBlocks::CAMPFIRE()->setLit(true), false);
		}
		$this->assertDoesNotWalkIntoDangerAcross(0.9375, 1.0);
	}

	public function testDoesNotStepOntoBlockWithFireOnTop() : void{
		for($z = -24; $z <= 24; ++$z){
			$this->world->setBlockAt(1, 1, $z, VanillaBlocks::STONE(), false);
			$this->world->setBlockAt(1, 2, $z, VanillaBlocks::FIRE(), false);
		}
		$this->assertDoesNotWalkIntoDangerAcross(1.0, 1.0, self::fireWallVindicator(...));
	}

	public function testStillGetsDownSmallDropToTarget() : void{
		$this->trench();
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$cow = $this->dummy(2.5, -1, 0.5);
		$zombie->setAttackTarget($cow);
		$this->runTicks(100);
		self::assertLessThan(100000, $cow->getHealth(), "the zombie gets down to its target");
	}
}
