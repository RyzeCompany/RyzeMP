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
use pocketmine\entity\ai\control\MoveControl;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\navigation\NavigationOptions;
use pocketmine\entity\ai\navigation\Path;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\ai\navigation\PathFinder;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Strider;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Husk;
use pocketmine\entity\monster\Spider;
use pocketmine\entity\monster\Vindicator;
use pocketmine\entity\Zombie;
use pocketmine\math\Facing;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use function abs;
use function count;
use function implode;
use function max;
use function mt_srand;
use function sprintf;

/**
 * Paths that fit the mob (footprints of wide mobs), walked straight where they can be, the navigation options of the
 * vanilla mobs (water, lava, fire, damage blocks, danger margin), and new searches when blocks change near a path.
 */
final class PathWidthTest extends MobTestCase{

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261020);
		//older native searches (chunkutils2 < 0.3.11) don't know the navigation options: they would avoid every danger
		//and keep wide mobs 1 block wide (see testOlderNativeSearch())
		[, $nativeHasOptions] = PathFinder::getNativeSupport();
		PathFinder::setNativeEnabled($nativeHasOptions);
	}

	protected function tearDown() : void{
		PathFinder::setNativeEnabled(true);
		PathFinder::setNativeOptionsEnabled(true);
		parent::tearDown();
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * A mob whose goals don't move it: only the test moves it, through its navigation.
	 *
	 * @template T of Mob
	 * @phpstan-param T $mob
	 * @phpstan-return T
	 */
	private function puppet(Mob $mob) : Mob{
		$mob->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_JUMP, true);
		$mob->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		return $mob;
	}

	private function finder(int $headBlocks, int $maxDrop, NavigationOptions $options, int $footprint = 1) : PathFinder{
		$finder = new PathFinder($this->world, $headBlocks, $maxDrop, true, false, 400);
		$finder->applyOptions($options);
		$finder->setFootprint($footprint);
		return $finder;
	}

	/**
	 * A wall of stone at x = $x (y 1..3, z -24..24) with openings at the given z.
	 *
	 * @param true[] $openZ z => true
	 * @phpstan-param array<int, true> $openZ
	 */
	private function wall(int $x, array $openZ) : void{
		for($z = -24; $z <= 24; ++$z){
			for($y = 1; $y <= 3; ++$y){
				$this->world->setBlockAt($x, $y, $z, isset($openZ[$z]) ? VanillaBlocks::AIR() : VanillaBlocks::STONE(), false);
			}
		}
	}

	/**
	 * @return int[] Z of the nodes in the column X = $x
	 * @phpstan-return list<int>
	 */
	private static function crossingsAt(Path $path, int $x) : array{
		$z = [];
		foreach($path->getNodes() as $node){
			if($node[0] === $x){
				$z[] = $node[2];
			}
		}
		return $z;
	}

	private static function describe(?Path $path) : string{
		if($path === null){
			return "no path";
		}
		$nodes = [];
		foreach($path->getNodes() as [$x, $y, $z]){
			$nodes[] = "$x:$y:$z";
		}
		return ($path->reachesTarget() ? "" : "partial ") . implode(" ", $nodes);
	}

	public function testFootprints() : void{
		self::assertSame(1, PathFinder::footprintOf(0.6));
		self::assertSame(1, PathFinder::footprintOf(0.9));
		self::assertSame(2, PathFinder::footprintOf(1.0));
		self::assertSame(2, PathFinder::footprintOf(1.4));
		self::assertSame(2, PathFinder::footprintOf(1.95));
		self::assertSame(3, PathFinder::footprintOf(2.0));

		//the footprint centred closest to the mob
		self::assertSame(3, PathFinder::nodeCoordinate(3.9, 1));
		self::assertSame(-1, PathFinder::nodeCoordinate(-0.1, 1));
		self::assertSame(3, PathFinder::nodeCoordinate(4.2, 2)); //blocks 3 and 4, centre 4.0
		self::assertSame(4, PathFinder::nodeCoordinate(4.6, 2)); //blocks 4 and 5, centre 5.0
		self::assertSame(3, PathFinder::nodeCoordinate(4.5, 3)); //blocks 3 to 5, centre 4.5
	}

	public function testWideMobNeedsTwoBlockGap() : void{
		$this->wall(4, [0 => true]);
		$golem = NavigationOptions::forEntity(EntityIds::IRON_GOLEM);
		$path = $this->finder(2, 1, $golem, 2)->findPath(0, 1, 0, 8, 1, 0);
		self::assertTrue($path === null || !$path->reachesTarget(), "a 2-wide footprint can't go through a 1-wide gap: " . self::describe($path));
		$narrow = $this->finder(2, 1, $golem, 1)->findPath(0, 1, 0, 8, 1, 0);
		self::assertNotNull($narrow);
		self::assertTrue($narrow->reachesTarget(), "a 1-wide footprint can");

		$this->wall(4, [0 => true, 6 => true, 7 => true]);
		$path = $this->finder(2, 1, $golem, 2)->findPath(0, 1, 0, 8, 1, 0);
		self::assertNotNull($path);
		self::assertTrue($path->reachesTarget(), "through the 2-wide gap: " . self::describe($path));
		self::assertSame([6], self::crossingsAt($path, 4), "the footprint takes blocks 6 and 7 of the wall: " . self::describe($path));
	}

	public function testWideMobWalksMiddleOfCorridorWithoutTouchingWalls() : void{
		//a corridor 2 blocks wide (z 0 and 1) along X
		for($x = -6; $x <= 22; ++$x){
			for($y = 1; $y <= 3; ++$y){
				$this->world->setBlockAt($x, $y, -1, VanillaBlocks::STONE(), false);
				$this->world->setBlockAt($x, $y, 2, VanillaBlocks::STONE(), false);
			}
		}
		$this->world->setBlockAt(5, 1, 0, VanillaBlocks::AIR(), false);
		foreach(["spider" => fn(Location $l) => new Spider($l), "iron golem" => fn(Location $l) => new IronGolem($l)] as $name => $factory){
			$mob = $this->puppet($factory($this->loc(0.0, 1, 1.0)));
			self::assertTrue($mob->getNavigation()->moveTo(18.0, 1, 1.0, 1.0));
			$collided = 0;
			$climbing = 0;
			$maxOff = 0.0;
			for($i = 0; $i < 200 && $mob->getPosition()->x < 16; ++$i){
				$this->runTicks(1);
				if($mob->isCollidedHorizontally){
					++$collided;
				}
				if($mob->canClimbWalls()){
					++$climbing;
				}
				$maxOff = max($maxOff, abs($mob->getPosition()->z - 1.0));
			}
			self::assertGreaterThanOrEqual(16.0, $mob->getPosition()->x, "$name walks down the corridor, at " . $mob->getPosition());
			self::assertSame(0, $collided, "$name brushed the walls");
			self::assertSame(0, $climbing, "$name climbed the walls");
			self::assertLessThan(0.2, $maxOff, "$name walks in the middle of the corridor");
			$mob->close();
		}
	}

	public function testIronGolemCannotPassOneBlockDoorway() : void{
		$this->wall(4, [0 => true]);
		$golem = $this->puppet(new IronGolem($this->loc(0.0, 1, 1.0)));
		$golem->getNavigation()->moveTo(8.0, 1, 1.0, 1.0);
		for($i = 0; $i < 200; ++$i){
			$this->runTicks(1);
			self::assertLessThan(4.0, $golem->getPosition()->x, "the golem squeezed through the doorway at tick $i");
		}
	}

	public function testDiagonalOverOpenGroundIsStraight() : void{
		$zombie = $this->puppet(new Zombie($this->loc(0.5, 1, 0.5)));
		$zombie->getNavigation()->moveTo(14.5, 1, 6.5, 1.0);
		$yaws = [];
		for($i = 0; $i < 200 && !$zombie->getNavigation()->isDone(); ++$i){
			$this->runTicks(1);
			$yaws[] = $zombie->getLocation()->yaw;
		}
		self::assertGreaterThan(13.0, $zombie->getPosition()->x, "the zombie got there");
		$turns = [];
		for($i = 6, $count = count($yaws); $i < $count; ++$i){
			$delta = MoveControl::wrapDegrees($yaws[$i] - $yaws[$i - 1]);
			if(abs($delta) > 3){
				$turns[] = sprintf("tick %d: %.1f", $i, $delta);
			}
		}
		//one slight correction at the waypoint the straight walk was aimed at, no 45 degree zig-zag
		self::assertLessThanOrEqual(1, count($turns), "heading changes: " . implode(", ", $turns));
	}

	/**
	 * A river 3 blocks wide (x 4..6, water instead of the floor) with a bridge at z = 10 (or none).
	 */
	private function river(bool $bridge) : void{
		for($x = 4; $x <= 6; ++$x){
			for($z = -24; $z <= 24; ++$z){
				$this->world->setBlockAt($x, -1, $z, VanillaBlocks::STONE(), false);
				$this->world->setBlockAt($x, 0, $z, $bridge && $z === 10 ? VanillaBlocks::STONE() : VanillaBlocks::WATER(), false);
			}
		}
	}

	private function swims(Path $path) : bool{
		foreach($path->getNodes() as [$x, $y, $z]){
			if((PathBlockTypes::at($this->world, $x, $y, $z) & PathBlockTypes::WATER) !== 0){
				return true;
			}
		}
		return false;
	}

	public function testCowTakesBridgeInsteadOfSwimming() : void{
		$this->river(true);
		$cow = $this->finder(1, 3, NavigationOptions::forEntity(EntityIds::COW))->findPath(0, 1, 0, 10, 1, 0);
		self::assertNotNull($cow);
		self::assertTrue($cow->reachesTarget());
		self::assertFalse($this->swims($cow), "the cow avoids water (BDS: animals with avoid_water take a detour of 9 blocks): " . self::describe($cow));
		$wolf = $this->finder(0, 3, NavigationOptions::forEntity(EntityIds::WOLF))->findPath(0, 1, 0, 10, 1, 0);
		self::assertNotNull($wolf);
		self::assertTrue($this->swims($wolf), "the wolf swims across (BDS): " . self::describe($wolf));

		$this->river(false);
		$cow = $this->finder(1, 3, NavigationOptions::forEntity(EntityIds::COW))->findPath(0, 1, 0, 10, 1, 0);
		self::assertNotNull($cow);
		self::assertTrue($cow->reachesTarget(), "without a bridge the cow swims (BDS: a fox does)");
		$golem = $this->finder(2, 1, NavigationOptions::forEntity(EntityIds::IRON_GOLEM), 2)->findPath(0, 1, 0, 10, 1, 0);
		self::assertTrue($golem === null || !$golem->reachesTarget(), "iron golems never path into water (can_path_over_water false): " . self::describe($golem));
	}

	public function testStriderPathsOverLava() : void{
		for($x = 3; $x <= 8; ++$x){
			for($z = -10; $z <= 10; ++$z){
				$this->world->setBlockAt($x, -1, $z, VanillaBlocks::STONE(), false);
				$this->world->setBlockAt($x, 0, $z, VanillaBlocks::LAVA(), false);
			}
		}
		$strider = $this->finder(1, 3, NavigationOptions::forEntity(EntityIds::STRIDER))->findPath(0, 1, 0, 11, 1, 0);
		self::assertNotNull($strider);
		self::assertTrue($strider->reachesTarget());
		self::assertSame([], self::crossingsAtLevel($strider, 0), "the strider stays on the surface: " . self::describe($strider));
		self::assertSame([0], self::crossingsAt($strider, 5), "the strider walks straight across the lava: " . self::describe($strider));
		$cow = $this->finder(1, 3, NavigationOptions::forEntity(EntityIds::COW))->findPath(0, 1, 0, 11, 1, 0);
		self::assertNotNull($cow);
		self::assertNotContains(0, self::crossingsAt($cow, 5), "the cow goes around: " . self::describe($cow));

		//a wither skeleton wades through shallow lava (can_path_over_lava) when there's no way round, a cow doesn't
		for($x = 3; $x <= 8; ++$x){
			for($z = -24; $z <= 24; ++$z){
				$this->world->setBlockAt($x, -1, $z, VanillaBlocks::STONE(), false);
				$this->world->setBlockAt($x, 0, $z, $x <= 4 ? VanillaBlocks::LAVA() : VanillaBlocks::STONE(), false);
			}
		}
		$skeleton = $this->finder(2, 3, NavigationOptions::forEntity(EntityIds::WITHER_SKELETON))->findPath(0, 1, 0, 11, 1, 0);
		self::assertNotNull($skeleton);
		self::assertTrue($skeleton->reachesTarget());
		self::assertContains(3, self::crossingsAtLevel($skeleton, 0), "the wither skeleton wades through: " . self::describe($skeleton));
		$cow = $this->finder(1, 3, NavigationOptions::forEntity(EntityIds::COW))->findPath(0, 1, 0, 11, 1, 0);
		self::assertTrue($cow === null || !$cow->reachesTarget(), self::describe($cow));
	}

	public function testStriderWalksAcrossLavaLake() : void{
		for($x = -2; $x <= 14; ++$x){
			for($z = -6; $z <= 6; ++$z){
				$this->world->setBlockAt($x, -1, $z, VanillaBlocks::STONE(), false);
				$this->world->setBlockAt($x, 0, $z, VanillaBlocks::LAVA(), false);
			}
		}
		$strider = $this->puppet(new Strider($this->loc(0.5, 1, 0.5)));
		self::assertTrue($strider->getNavigation()->moveTo(11.5, 1, 0.5, 1.0));
		for($i = 0; $i < 200 && $strider->getPosition()->x < 11; ++$i){
			$this->runTicks(1);
			self::assertGreaterThan(0.8, $strider->getPosition()->y, "the strider stays on the lava at tick $i");
		}
		self::assertGreaterThan(11.0, $strider->getPosition()->x, "the strider walked across the lava, at " . $strider->getPosition());
	}

	/**
	 * @return int[] X of the nodes at the given Y
	 * @phpstan-return list<int>
	 */
	private static function crossingsAtLevel(Path $path, int $y) : array{
		$x = [];
		foreach($path->getNodes() as $node){
			if($node[1] === $y){
				$x[] = $node[0];
			}
		}
		return $x;
	}

	/**
	 * A line of fire across x = 4 (on netherrack) with an opening at the given z.
	 *
	 * @param true[] $openZ z => true
	 * @phpstan-param array<int, true> $openZ
	 */
	private function fireLine(array $openZ) : void{
		for($z = -24; $z <= 24; ++$z){
			$this->world->setBlockAt(4, 0, $z, VanillaBlocks::NETHERRACK(), false);
			$this->world->setBlockAt(4, 1, $z, isset($openZ[$z]) ? VanillaBlocks::AIR() : VanillaBlocks::FIRE(), false);
		}
	}

	public function testWhoWalksThroughFire() : void{
		$this->fireLine([5 => true]);
		$zombie = $this->finder(1, 3, NavigationOptions::forEntity(EntityIds::ZOMBIE))->findPath(0, 1, 0, 8, 1, 0);
		self::assertNotNull($zombie);
		self::assertSame([0], self::crossingsAt($zombie, 4), "zombies walk through fire (BDS): " . self::describe($zombie));
		$vindicator = $this->finder(1, 3, NavigationOptions::forEntity(EntityIds::VINDICATOR))->findPath(0, 1, 0, 8, 1, 0);
		self::assertNotNull($vindicator);
		self::assertSame([5], self::crossingsAt($vindicator, 4), "vindicators go through the gap (BDS): " . self::describe($vindicator));
		$wolf = $this->finder(0, 3, NavigationOptions::forEntity(EntityIds::WOLF))->findPath(0, 1, 0, 8, 1, 0);
		self::assertTrue($wolf === null || !$wolf->reachesTarget(), "wolves avoid damage blocks and the blocks next to them, a 1-wide gap between fires is no way (BDS): " . self::describe($wolf));

		$this->fireLine([4 => true, 5 => true, 6 => true]);
		$wolf = $this->finder(0, 3, NavigationOptions::forEntity(EntityIds::WOLF))->findPath(0, 1, 0, 8, 1, 0);
		self::assertNotNull($wolf);
		self::assertTrue($wolf->reachesTarget());
		self::assertSame([5], self::crossingsAt($wolf, 4), "the middle of a 3-wide gap is not next to a fire (BDS): " . self::describe($wolf));
	}

	public function testFireIsACostWithoutAWayRound() : void{
		$this->fireLine([]);
		$vindicator = $this->finder(1, 3, NavigationOptions::forEntity(EntityIds::VINDICATOR))->findPath(0, 1, 0, 8, 1, 0);
		self::assertNotNull($vindicator);
		self::assertSame([0], self::crossingsAt($vindicator, 4), "vindicators walk through fire when there is no way round (BDS 1.26.60, Java malus 16): " . self::describe($vindicator));
		$wolf = $this->finder(0, 3, NavigationOptions::forEntity(EntityIds::WOLF))->findPath(0, 1, 0, 8, 1, 0);
		self::assertTrue($wolf === null || !$wolf->reachesTarget(), "wolves never walk through fire: " . self::describe($wolf));
	}

	/**
	 * The bot's mob_fire_crossing: a line of soul fire on soul soil all across the way to the target, which a husk
	 * walks through and a vindicator too (BDS 1.26.60, Java), also with the older native searches, which avoid all fire.
	 */
	public function testMobsWalkThroughSoulFireLineToTheirTarget() : void{
		foreach([[true, true], [true, false], [false, false]] as [$native, $nativeOptions]){
			PathFinder::setNativeEnabled($native);
			PathFinder::setNativeOptionsEnabled($nativeOptions);
			foreach([fn(Location $l) => new Husk($l), fn(Location $l) => new Vindicator($l)] as $factory){
				for($z = -24; $z <= 24; ++$z){
					$this->world->setBlockAt(4, 0, $z, VanillaBlocks::SOUL_SOIL(), false);
					$this->world->setBlockAt(4, 1, $z, VanillaBlocks::SOUL_FIRE(), false);
				}
				$mob = $factory($this->loc(-1.5, 1, 0.5));
				$cow = new Cow($this->loc(9.5, 1, 0.5));
				$cow->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
				$cow->setMaxHealth(100000);
				$cow->setHealth(100000);
				$mob->setAttackTarget($cow);
				$this->runTicks(200);
				$label = $mob->getName() . ($native ? ($nativeOptions ? " (native search)" : " (older native search)") : " (PHP search)");
				self::assertLessThan(100000, $cow->getHealth(), "$label crossed the fire, at " . $mob->getPosition());
				$mob->close();
				$cow->close();
			}
		}
	}

	public function testZombieWalksThroughFireToItsTarget() : void{
		$this->fireLine([]);
		$zombie = new Zombie($this->loc(1.5, 1, 0.5));
		$cow = new Cow($this->loc(7.5, 1, 0.5));
		$cow->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$cow->setMaxHealth(100000);
		$cow->setHealth(100000);
		$zombie->setAttackTarget($cow);
		$this->runTicks(160);
		self::assertLessThan(100000, $cow->getHealth(), "the zombie crossed the fire, at " . $zombie->getPosition());
	}

	public function testPathKeepsClearOfFireWhenItCan() : void{
		//a row of fire along x = 4 for z <= 0: the path from one side to the other goes round its end
		for($z = -24; $z <= 0; ++$z){
			$this->world->setBlockAt(4, 0, $z, VanillaBlocks::NETHERRACK(), false);
			$this->world->setBlockAt(4, 1, $z, VanillaBlocks::FIRE(), false);
		}
		$path = $this->finder(1, 3, NavigationOptions::forEntity(EntityIds::VINDICATOR))->findPath(0, 1, -3, 8, 1, -3);
		self::assertNotNull($path);
		self::assertTrue($path->reachesTarget());
		foreach($path->getNodes() as [$x, $y, $z]){
			self::assertFalse(abs($x - 4) <= 1 && $z <= 1, "node $x:$y:$z is next to the fire: " . self::describe($path));
		}
		//without the danger margin the path hugs the end of the row
		$finder = $this->finder(1, 3, NavigationOptions::forEntity(EntityIds::VINDICATOR));
		$finder->setDangerMargin(0, 0, 0.0);
		$hugging = $finder->findPath(0, 1, -3, 8, 1, -3);
		self::assertNotNull($hugging);
		self::assertContains(1, self::crossingsAt($hugging, 4), self::describe($hugging));
	}

	public function testWallAcrossPathMakesMobLookForNewPath() : void{
		$zombie = $this->puppet(new Zombie($this->loc(0.5, 1, 0.5)));
		$navigation = $zombie->getNavigation();
		self::assertTrue($navigation->moveTo(20.5, 1, 0.5, 1.0));
		$this->runTicks(5);
		$first = $navigation->getPath();
		self::assertNotNull($first);

		//blocks far from the path, or changes that don't matter to paths, change nothing
		$this->set(5, 1, 15, VanillaBlocks::STONE());
		$this->set(8, 0, 0, VanillaBlocks::COBBLESTONE());
		self::assertFalse($navigation->isRecomputePending());

		//without block updates: the world tells the paths about every block state change
		$this->wall(12, [8 => true]);
		self::assertTrue($navigation->isRecomputePending(), "the wall is across the path");
		for($i = 0; $i < 20 && $navigation->getPath() === $first; ++$i){
			$this->runTicks(1);
		}
		$path = $navigation->getPath();
		self::assertNotNull($path);
		self::assertNotSame($first, $path, "new path within 20 ticks");
		self::assertSame([8], self::crossingsAt($path, 12), "through the gap: " . self::describe($path));
	}

	public function testDoorClosingOnPath() : void{
		$this->wall(6, [0 => true]);
		$door = VanillaBlocks::OAK_DOOR()->setFacing(Facing::EAST)->setOpen(true);
		$this->set(6, 1, 0, $door);
		$this->set(6, 2, 0, (clone $door)->setTop(true));
		foreach([true, false] as $opensDoors){
			$zombie = $this->puppet(new Zombie($this->loc(0.5, 1, 0.5)));
			$navigation = $zombie->getNavigation();
			$navigation->getPathFinder()->setCanOpenDoors($opensDoors);
			$navigation->moveTo(12.5, 1, 0.5, 1.0);
			$this->runTicks(2);
			self::assertNotNull($navigation->getPath());
			$this->set(6, 1, 0, (clone $door)->setOpen(false));
			$this->set(6, 2, 0, (clone $door)->setTop(true)->setOpen(false));
			self::assertSame(!$opensDoors, $navigation->isRecomputePending(), $opensDoors ? "a mob which opens doors keeps its path" : "a closed door blocks the path");
			$this->set(6, 1, 0, $door);
			$this->set(6, 2, 0, (clone $door)->setTop(true));
			$zombie->close();
		}
	}

	public function testPathsAreForgottenWithTheMob() : void{
		$watch = $this->world->getPathRecomputeWatch();
		$zombie = $this->puppet(new Zombie($this->loc(0.5, 1, 0.5)));
		$zombie->getNavigation()->moveTo(10.5, 1, 0.5, 1.0);
		$this->runTicks(3);
		self::assertSame(1, $watch->getCount());
		$zombie->getNavigation()->stop();
		self::assertSame(0, $watch->getCount());
		$zombie->getNavigation()->moveTo(10.5, 1, 4.5, 1.0);
		$this->runTicks(3);
		self::assertSame(1, $watch->getCount());
		$zombie->close();
		self::assertSame(0, $watch->getCount());
	}

	public function testMobWhichCannotMoveGivesUpItsPath() : void{
		$start = $this->loc(0.5, 1, 0.5);
		$zombie = $this->puppet(new Zombie($start));
		$zombie->getNavigation()->moveTo(15.5, 1, 0.5, 1.0);
		$this->runTicks(3);
		self::assertNotNull($zombie->getNavigation()->getPath());
		//held where it is (like a mob stuck on something its path doesn't know about)
		for($i = 0; $i < 90; ++$i){
			$zombie->teleport($start);
			$this->runTicks(1);
		}
		self::assertFalse($zombie->getNavigation()->isDone(), "the path is kept for 100 ticks");
		for($i = 0; $i < 15; ++$i){
			$zombie->teleport($start);
			$this->runTicks(1);
		}
		self::assertTrue($zombie->getNavigation()->isDone(), "no progress for 100 ticks: the path is given up");
	}

	/**
	 * With the native search of chunkutils2 0.3.7 to 0.3.10 (one block wide, without the navigation options), wide mobs
	 * keep the 1-wide paths of before: checking those paths against them, or searching in PHP, would cost much more than
	 * the search. Mobs which need more than it does (striders, breezes) and mobs which open doors search in PHP.
	 */
	public function testOlderNativeSearch() : void{
		PathFinder::setNativeEnabled(true);
		[$hasNative, $hasOptions] = PathFinder::getNativeSupport();
		if(!$hasNative){
			self::markTestSkipped("No native path search");
		}
		PathFinder::setNativeOptionsEnabled(false);
		$golem = $this->finder(2, 1, NavigationOptions::forEntity(EntityIds::IRON_GOLEM));
		self::assertSame(1, $golem->getEffectiveFootprint(2));
		self::assertSame(1, $golem->getEffectiveFootprint(1));
		$golem->setCanOpenDoors(true);
		self::assertSame(2, $golem->getEffectiveFootprint(2), "door searches run in PHP, with the footprint");

		foreach([
			EntityIds::ZOMBIE => true, EntityIds::COW => true, EntityIds::ZOMBIE_PIGMAN => true, EntityIds::GOAT => true,
			EntityIds::STRIDER => false, EntityIds::BREEZE => false
		] as $id => $native){
			self::assertSame($native, $this->finder(1, 1, NavigationOptions::forEntity($id))->fitsLegacyNativeSearch(), $id);
		}
		self::assertFalse($this->finder(1, 1, NavigationOptions::forEntity(EntityIds::SPIDER), 2)->fitsLegacyNativeSearch(), "footprint 2");

		//an iron golem walks the middle of blocks, through 1-wide gaps
		$this->wall(4, [0 => true]);
		$mob = $this->puppet(new IronGolem($this->loc(0.5, 1, 0.5)));
		self::assertTrue($mob->getNavigation()->moveTo(8.5, 1, 0.5, 1.0));
		$this->runTicks(2);
		$path = $mob->getNavigation()->getPath();
		self::assertNotNull($path);
		self::assertSame(0.5, $mob->getNavigation()->getNodeOffset());
		self::assertSame([0], self::crossingsAt($path, 4), self::describe($path));
		$mob->close();

		if($hasOptions){
			PathFinder::setNativeOptionsEnabled(true);
			$mob = $this->puppet(new IronGolem($this->loc(0.5, 1, 0.5)));
			$mob->getNavigation()->moveTo(8.5, 1, 0.5, 1.0);
			$this->runTicks(2);
			self::assertSame(1.0, $mob->getNavigation()->getNodeOffset());
			$path = $mob->getNavigation()->getPath();
			self::assertTrue($path === null || !$path->reachesTarget(), "with chunkutils2 0.3.11 the golem doesn't fit: " . self::describe($path));
			$mob->close();
		}
	}

	public function testWideMobWandersWhereItFits() : void{
		//1-wide alleys everywhere except one 2x2 spot
		for($x = -6; $x <= 6; ++$x){
			for($z = -6; $z <= 6; ++$z){
				if(($x % 2 === 0 || $z % 2 === 0) && !($x >= 2 && $x <= 3 && $z >= 2 && $z <= 3) && !($x === 0 && $z === 0)){
					$this->world->setBlockAt($x, 1, $z, VanillaBlocks::STONE(), false);
					$this->world->setBlockAt($x, 2, $z, VanillaBlocks::STONE(), false);
				}
			}
		}
		$golem = $this->puppet(new IronGolem($this->loc(0.5, 1, 0.5)));
		for($i = 0; $i < 30; ++$i){
			$pos = $golem->findWanderPos(6, 2);
			if($pos === null){
				continue;
			}
			$x = (int) ($pos->x - 0.5);
			$z = (int) ($pos->z - 0.5);
			self::assertTrue(PathBlockTypes::isStandable($this->world, $x, (int) $pos->y, $z, 2, false, 2), "the footprint of the golem fits at $pos");
		}
	}
}
