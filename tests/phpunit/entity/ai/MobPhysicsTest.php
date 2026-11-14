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

use pocketmine\block\Block;
use pocketmine\block\SweetBerryBush;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\GoalSelector;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\PrioritizedGoal;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Fox;
use pocketmine\entity\animal\Horse;
use pocketmine\entity\animal\SkeletonHorse;
use pocketmine\entity\animal\ZombieHorse;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Husk;
use pocketmine\entity\monster\Piglin;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\monster\Spider;
use pocketmine\entity\monster\WitherSkeleton;
use pocketmine\entity\monster\ZombifiedPiglin;
use pocketmine\entity\Zombie;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use function abs;
use function max;

/**
 * How AI-moved mobs move on and in blocks and liquids (vanilla LivingEntity.travel()): acceleration and friction,
 * slippery and slow blocks, cobwebs, berry bushes and powder snow, ladders, lava, and the mobs which sink in water.
 */
final class MobPhysicsTest extends MobTestCase{

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * Turns off the mob's own goals: the test moves it.
	 *
	 * @phpstan-template T of Mob
	 * @phpstan-param T $mob
	 * @phpstan-return T
	 */
	private function still(Mob $mob) : Mob{
		$mob->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$mob->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		return $mob;
	}

	private function area(int $minX, int $y, int $minZ, int $maxX, int $maxZ, Block $block) : void{
		for($x = $minX; $x <= $maxX; ++$x){
			for($z = $minZ; $z <= $maxZ; ++$z){
				$this->set($x, $y, $z, $block);
			}
		}
	}

	/**
	 * Walks the mob east towards a far point for the given ticks and returns the distance covered on each tick.
	 *
	 * @return float[]
	 * @phpstan-return list<float>
	 */
	private function walkEast(Mob $mob, int $ticks, float $speedMultiplier = 1.0) : array{
		$steps = [];
		for($t = 0; $t < $ticks; ++$t){
			$before = $mob->getPosition()->x;
			$mob->getMoveControl()->setWantedPosition(40.5, $mob->getPosition()->y, $mob->getPosition()->z, $speedMultiplier);
			$this->runTicks(1);
			$steps[] = $mob->getPosition()->x - $before;
		}
		return $steps;
	}

	/**
	 * Stops the mob's walking and returns how far it slides.
	 */
	private function slide(Mob $mob) : float{
		$mob->getMoveControl()->stop();
		$start = $mob->getPosition()->x;
		$this->runTicks(40);
		return $mob->getPosition()->x - $start;
	}

	/**
	 * @return Goal[]
	 */
	private static function goals(GoalSelector $selector) : array{
		$entries = (new \ReflectionProperty(GoalSelector::class, "goals"))->getValue($selector);
		self::assertIsArray($entries);
		$goals = [];
		foreach($entries as $entry){
			self::assertInstanceOf(PrioritizedGoal::class, $entry);
			$goals[] = $entry->goal;
		}
		return $goals;
	}

	private static function flag(Mob $mob, int $flag) : bool{
		$flags = $mob->getNetworkProperties()->getAll()[EntityMetadataProperties::FLAGS] ?? null;
		self::assertInstanceOf(LongMetadataProperty::class, $flags);
		return ($flags->getValue() & (1 << $flag)) !== 0;
	}

	public function testSteadySpeedOnStoneIsUnchangedAndReachedWithAcceleration() : void{
		$zombie = $this->still(new Zombie($this->loc(-20.5, 1, 0.5)));
		$this->runTicks(2);
		$steps = $this->walkEast($zombie, 30);
		$expected = $zombie->getMoveControl()->getGroundSpeed(1.0);
		self::assertEqualsWithDelta($expected, $steps[29], 0.0001, "the steady speed on stone is the move control's ground speed");
		self::assertLessThan($expected * 0.75, $steps[0], "it speeds up instead of starting at full speed");
		self::assertGreaterThan($expected * 0.95, $steps[5], "within a few ticks");
		self::assertLessThan(0.2, $this->slide($zombie), "it stops almost at once on stone");
	}

	public function testMobSlidesOnIce() : void{
		$this->area(-24, 0, -3, 24, 3, VanillaBlocks::ICE());
		$zombie = $this->still(new Zombie($this->loc(-20.5, 1, 0.5)));
		$this->runTicks(2);
		$steps = $this->walkEast($zombie, 60);
		$ground = $zombie->getMoveControl()->getGroundSpeed(1.0);
		self::assertLessThan($ground * 0.2, $steps[0], "slow to start on ice");
		self::assertEqualsWithDelta($ground, $steps[59], $ground * 0.1, "about as fast as on stone once going");

		$zombie->getMoveControl()->stop();
		$x0 = $zombie->getPosition()->x;
		$this->runTicks(1);
		$x1 = $zombie->getPosition()->x;
		$this->runTicks(1);
		$x2 = $zombie->getPosition()->x;
		self::assertEqualsWithDelta(0.98 * 0.91, ($x2 - $x1) / ($x1 - $x0), 0.002, "vanilla ice inertia once it stops walking");
		$slide = $x2 - $x0 + $this->slide($zombie);
		self::assertGreaterThan(0.8, $slide, "it slides on when it stops walking");
		self::assertLessThan(1.2, $slide, "about a block, like vanilla");
	}

	public function testSoulSandSlowsMobsDown() : void{
		$this->area(-24, 0, -3, 24, 3, VanillaBlocks::SOUL_SAND());
		$zombie = $this->still(new Zombie($this->loc(-20.5, 1, 0.5)));
		$this->runTicks(5);
		$steps = $this->walkEast($zombie, 30);
		$ground = $zombie->getMoveControl()->getGroundSpeed(1.0);
		self::assertEqualsWithDelta($ground * 0.454 / (1 - 0.546 * 0.4), $steps[29], 0.002, "vanilla speed factor 0.4");
	}

	public function testHoneySlowsMobsDownLikeSoulSand() : void{
		$this->area(-24, 0, -3, 24, 3, VanillaBlocks::HONEY_BLOCK());
		$zombie = $this->still(new Zombie($this->loc(-20.5, 1, 0.5)));
		$this->runTicks(5);
		$steps = $this->walkEast($zombie, 30);
		$ground = $zombie->getMoveControl()->getGroundSpeed(1.0);
		self::assertEqualsWithDelta($ground * 0.454 / (1 - 0.546 * 0.4), $steps[29], 0.002, "speed factor 0.4 only, not also the 0.8 friction");
	}

	public function testStoppedMobSlowsDownWithVanillaGroundInertia() : void{
		$zombie = $this->still(new Zombie($this->loc(0.5, 1, 0.5)));
		$this->runTicks(2);
		$zombie->setMotion(new Vector3(0.4, 0, 0));
		$this->runTicks(1);
		$x1 = $zombie->getPosition()->x;
		$this->runTicks(1);
		$x2 = $zombie->getPosition()->x;
		$this->runTicks(1);
		$x3 = $zombie->getPosition()->x;
		self::assertEqualsWithDelta(0.6 * 0.91, ($x3 - $x2) / ($x2 - $x1), 0.002, "vanilla stone inertia");
	}

	public function testCobwebSlowsZombieButNotSpider() : void{
		$this->area(-2, 1, -3, 2, 3, VanillaBlocks::COBWEB());
		$zombie = $this->still(new Zombie($this->loc(-3.5, 1, 0.5)));
		$spider = $this->still(new Spider($this->loc(-3.5, 1, -2.5)));
		$this->runTicks(2);
		$zombieDistance = $spiderDistance = 0.0;
		for($t = 0; $t < 60; ++$t){
			$zombie->getMoveControl()->setWantedPosition(10.5, 1, 0.5, 1.0);
			$spider->getMoveControl()->setWantedPosition(10.5, 1, -2.5, 1.0);
			$this->runTicks(1);
		}
		$zombieDistance = $zombie->getPosition()->x + 3.5;
		$spiderDistance = $spider->getPosition()->x + 3.5;
		self::assertGreaterThan(10.0, $spiderDistance, "the spider walks through the cobwebs at full speed");
		self::assertLessThan(4.0, $zombieDistance, "the zombie is stuck in the cobwebs");
		self::assertGreaterThan(1.0, $zombieDistance, "but makes its way slowly");
	}

	public function testBerryBushSlowsAndHurtsZombieButNotFox() : void{
		$bush = VanillaBlocks::SWEET_BERRY_BUSH()->setAge(SweetBerryBush::STAGE_MATURE);
		$this->area(-2, 0, -3, 2, 3, VanillaBlocks::GRASS());
		$this->area(-2, 1, -3, 2, 3, $bush);
		$zombie = $this->still(new Zombie($this->loc(-1.5, 1, 0.5)));
		$fox = $this->still(new Fox($this->loc(-1.5, 1, -2.5)));
		$this->runTicks(30);
		self::assertEqualsWithDelta($zombie->getMaxHealth(), $zombie->getHealth(), 0.001, "standing still in the bush doesn't hurt");
		$this->walkEast($zombie, 30);
		$this->walkEast($fox, 30);
		self::assertLessThan($zombie->getMaxHealth(), $zombie->getHealth(), "the bush hurts the moving zombie");
		self::assertEqualsWithDelta($fox->getMaxHealth(), $fox->getHealth(), 0.001, "foxes take no damage from berry bushes");
	}

	public function testFoxStandsOnPowderSnowWhereCowSinks() : void{
		$this->area(-3, 1, -3, 3, 3, VanillaBlocks::POWDER_SNOW());
		$fox = $this->still(new Fox($this->loc(0.5, 2, 0.5)));
		$cow = $this->still(new Cow($this->loc(0.5, 2, -2.5)));
		$this->runTicks(40);
		self::assertEqualsWithDelta(2.0, $fox->getPosition()->y, 0.001, "the fox stands on the powder snow");
		self::assertTrue($fox->isOnGround());
		self::assertLessThan(1.9, $cow->getPosition()->y, "the cow sinks into it");
		//and walks on it
		$steps = $this->walkEast($fox, 10);
		self::assertGreaterThan(0.5, $steps[0] + $steps[1] + $steps[2] + $steps[3] + $steps[4] + $steps[5] + $steps[6] + $steps[7] + $steps[8] + $steps[9]);
		self::assertEqualsWithDelta(2.0, $fox->getPosition()->y, 0.001);
	}

	public function testZombieClimbsLadderWhenPressedAgainstIt() : void{
		for($y = 1; $y <= 4; ++$y){
			$this->area(-2, $y, 3, 2, 4, VanillaBlocks::STONE());
			$this->set(0, $y, 2, VanillaBlocks::LADDER()->setFacing(Facing::NORTH));
		}
		$zombie = $this->still(new Zombie($this->loc(0.5, 1, 0.5)));
		$this->runTicks(2);
		$maxY = 1.0;
		for($t = 0; $t < 80; ++$t){
			$zombie->getMoveControl()->setWantedPosition(0.5, $zombie->getPosition()->y, 4.5, 1.0);
			$this->runTicks(1);
			$maxY = max($maxY, $zombie->getPosition()->y);
		}
		self::assertGreaterThanOrEqual(5.0, $maxY, "the zombie climbs the ladder to the top of the wall");
		self::assertEqualsWithDelta(5.0, $zombie->getPosition()->y, 0.001, "and walks onto it");
		self::assertGreaterThan(3.0, $zombie->getPosition()->z);
		self::assertSame(0.0, $zombie->getFallDistance());
	}

	public function testHorseDoesNotClimbLadders() : void{
		for($y = 1; $y <= 4; ++$y){
			$this->area(-2, $y, 3, 2, 4, VanillaBlocks::STONE());
			$this->set(0, $y, 2, VanillaBlocks::LADDER()->setFacing(Facing::NORTH));
		}
		$horse = $this->still(new Horse($this->loc(0.5, 1, 0.5)));
		for($t = 0; $t < 60; ++$t){
			$horse->getMoveControl()->setWantedPosition(0.5, 1, 4.5, 1.0);
			$this->runTicks(1);
		}
		self::assertLessThan(2.5, $horse->getPosition()->y, "no minecraft:can_climb");
	}

	public function testZombieChasesTargetUpLadder() : void{
		for($y = 1; $y <= 3; ++$y){
			for($x = -3; $x <= 3; ++$x){
				for($z = 6; $z <= 9; ++$z){
					$this->set($x, $y, $z, VanillaBlocks::STONE());
				}
			}
			$this->set(0, $y, 5, VanillaBlocks::LADDER()->setFacing(Facing::NORTH));
		}
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$zombie->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		$cow = $this->still(new Cow($this->loc(0.5, 4, 7.5)));
		$cow->setMaxHealth(100000);
		$cow->setHealth(100000);
		$zombie->setAttackTarget($cow);
		$up = false;
		for($t = 0; $t < 200 && !$up; ++$t){
			$this->runTicks(1);
			$up = $zombie->getPosition()->y >= 4.0 && $zombie->isOnGround();
		}
		self::assertTrue($up, "the zombie followed its target up the ladder");
	}

	public function testLavaSlowsMobsDown() : void{
		$this->area(-24, 1, -3, 24, 3, VanillaBlocks::LAVA());
		$zombie = $this->still(new Zombie($this->loc(-20.5, 1, 0.5)));
		$zombie->setMaxHealth(100000);
		$zombie->setHealth(100000);
		$this->runTicks(5);
		$steps = $this->walkEast($zombie, 20);
		self::assertLessThan($zombie->getMoveControl()->getGroundSpeed(1.0) * 0.2, $steps[19], "it wades slowly through lava");
		self::assertTrue($zombie->isInLava());
	}

	public function testFloatingMobSwimsUpInLava() : void{
		$this->area(-3, 1, -3, 3, 3, VanillaBlocks::LAVA());
		$this->area(-3, 2, -3, 3, 3, VanillaBlocks::LAVA());
		$this->area(-3, 3, -3, 3, 3, VanillaBlocks::LAVA());
		$cow = new Cow($this->loc(0.5, 1, 0.5));
		$cow->setMaxHealth(100000);
		$cow->setHealth(100000);
		$cow->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK, true);
		$this->runTicks(60);
		self::assertGreaterThan(1.8, $cow->getPosition()->y, "FloatGoal also works in lava (Java FloatGoal): it swims up slowly");
	}

	public function testUndeadSinkAndBreatheUnderwater() : void{
		for($y = 1; $y <= 3; ++$y){
			$this->area(-4, $y, -4, 4, 4, VanillaBlocks::WATER());
		}
		$zombie = new Zombie($this->loc(0.5, 3, 0.5));
		$skeleton = new Skeleton($this->loc(2.5, 3, 0.5));
		$skeletonHorse = new SkeletonHorse($this->loc(-2.5, 3, 0.5));
		$zombieHorse = new ZombieHorse($this->loc(-2.5, 3, -2.5));
		$cow = new Cow($this->loc(2.5, 3, -2.5));
		foreach([$zombie, $skeleton, $skeletonHorse, $zombieHorse, $cow] as $mob){
			$mob->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK, true);
			$mob->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		}
		$this->runTicks(400);
		self::assertEqualsWithDelta(1.0, $zombie->getPosition()->y, 0.01, "the zombie walks on the bottom");
		self::assertEqualsWithDelta(1.0, $skeleton->getPosition()->y, 0.01, "the skeleton too");
		self::assertEqualsWithDelta(1.0, $skeletonHorse->getPosition()->y, 0.01, "the skeleton horse too");
		self::assertGreaterThan(2.5, $zombieHorse->getPosition()->y, "the zombie horse floats");
		self::assertGreaterThan(2.5, $cow->getPosition()->y, "the cow floats");
		self::assertEqualsWithDelta($zombie->getMaxHealth(), $zombie->getHealth(), 0.001, "zombies don't drown");
		self::assertEqualsWithDelta($skeleton->getMaxHealth(), $skeleton->getHealth(), 0.001, "skeletons don't drown");
	}

	public function testSunkHuskBecomesZombie() : void{
		for($y = 1; $y <= 4; ++$y){
			$this->area(-3, $y, -3, 3, 3, VanillaBlocks::WATER());
		}
		$husk = new Husk($this->loc(0.5, 2, 0.5));
		$husk->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK, true);
		$husk->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		$this->runTicks(30 * 20 + 15 * 20 + 20);
		self::assertTrue($husk->isFlaggedForDespawn() || $husk->isClosed(), "the husk sank, stayed underwater and turned into a zombie");
		$zombies = 0;
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof Zombie && !$entity instanceof Husk){
				++$zombies;
			}
		}
		self::assertSame(1, $zombies);
	}

	public function testSinkingMobsHaveNoFloatGoal() : void{
		foreach([
			new Zombie($this->loc(0.5, 1, 0.5)), new Husk($this->loc(0.5, 1, 0.5)), new ZombifiedPiglin($this->loc(0.5, 1, 0.5)),
			new Skeleton($this->loc(0.5, 1, 0.5)), new WitherSkeleton($this->loc(0.5, 1, 0.5)), new Piglin($this->loc(0.5, 1, 0.5)),
			new SkeletonHorse($this->loc(0.5, 1, 0.5))
		] as $mob){
			foreach(self::goals($mob->getGoalSelector()) as $goal){
				self::assertNotInstanceOf(FloatGoal::class, $goal, $mob->getName() . " sinks");
			}
			$mob->flagForDespawn();
		}
		foreach([new SkeletonHorse($this->loc(0.5, 1, 0.5)), new ZombieHorse($this->loc(0.5, 1, 0.5))] as $horse){
			foreach(self::goals($horse->getGoalSelector()) as $goal){
				self::assertNotInstanceOf(PanicGoal::class, $goal, $horse->getName() . " doesn't panic");
			}
			$horse->flagForDespawn();
		}
	}

	public function testCanClimbFlag() : void{
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$spider = new Spider($this->loc(2.5, 1, 0.5));
		$horse = new Horse($this->loc(-2.5, 1, 0.5));
		$this->runTicks(1);
		self::assertTrue(self::flag($zombie, EntityMetadataFlags::CAN_CLIMB));
		self::assertTrue(self::flag($spider, EntityMetadataFlags::CAN_CLIMB));
		self::assertFalse(self::flag($horse, EntityMetadataFlags::CAN_CLIMB));
	}

	public function testWalkingIntoStillMobStopsAtOnce() : void{
		//arrival on stone is exact: no overshoot
		$zombie = $this->still(new Zombie($this->loc(0.5, 1, 0.5)));
		for($t = 0; $t < 60; ++$t){
			if($zombie->getMoveControl()->hasWanted() || $t < 2){
				$zombie->getMoveControl()->setWantedPosition(4.5, 1, 0.5, 1.0);
			}
			$this->runTicks(1);
		}
		self::assertLessThan(0.01, abs($zombie->getPosition()->x - 4.5));
	}
}
