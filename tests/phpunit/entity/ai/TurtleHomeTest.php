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

use pocketmine\block\TurtleEgg;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\GoHomeGoal;
use pocketmine\entity\animal\Animal;
use pocketmine\entity\animal\Turtle;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use function fwrite;
use function hrtime;
use function min;
use function mt_srand;
use function sprintf;
use const PHP_FLOAT_MAX;
use const STDERR;

/**
 * Pregnant turtles going back to a distant home beach (behavior.go_home, then lay_egg).
 */
final class TurtleHomeTest extends MobTestCase{
	private const HOME_X = 150;

	protected function getChunkRadius() : int{
		return 10;
	}

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261021);
		//a long corridor to the home beach
		$this->floor(-8, -3, self::HOME_X + 8, 3);
		$sand = VanillaBlocks::SAND();
		for($x = self::HOME_X - 3; $x <= self::HOME_X + 3; ++$x){
			for($z = -3; $z <= 3; ++$z){
				$this->world->setBlockAt($x, 0, $z, $sand, false);
			}
		}
	}

	public function testHomeIsSavedLikeBefore() : void{
		//saves from before the generic home (same tags) keep their home
		$nbt = CompoundTag::create()->setInt(Animal::TAG_AGE, 0)
			->setInt(Mob::TAG_HOME_X, 40)->setInt(Mob::TAG_HOME_Y, 1)->setInt(Mob::TAG_HOME_Z, -2);
		$turtle = new Turtle(new Location(0.5, 1, 0.5, $this->world, 0, 0), $nbt);
		self::assertEquals(new Vector3(40, 1, -2), $turtle->getHomePosition());
		self::assertSame(0, $turtle->getHomeRadius(), "turtles wander freely");
		$saved = $turtle->saveNBT();
		self::assertSame(40, $saved->getInt(Turtle::TAG_HOME_X));
		self::assertSame(-2, $saved->getInt(Turtle::TAG_HOME_Z));

		$new = new Turtle(new Location(3.5, 1, 4.5, $this->world, 0, 0));
		self::assertEquals(new Vector3(3, 1, 4), $new->getHomePosition(), "home where it first spawned");
		$turtle->flagForDespawn();
		$new->flagForDespawn();
	}

	public function testPregnantTurtleGoesBackToADistantHomeToLayItsEggs() : void{
		$turtle = new Turtle(new Location(0.5, 1, 0.5, $this->world, 0, 0), CompoundTag::create()->setInt(Animal::TAG_AGE, 0));
		$turtle->setHomePosition(new Vector3(self::HOME_X, 1, 0));
		$turtle->spawnToAll();
		$turtle->setPregnant(true);
		$i = 0;
		for(; $i < 12000 && !$turtle->wantsToLayEgg(); ++$i){
			$this->runTicks(1);
		}
		self::assertTrue($turtle->wantsToLayEgg(), "back home after " . $i . " ticks, at " . $turtle->getPosition());
		self::assertLessThanOrEqual(4.5, $turtle->getPosition()->distance(new Vector3(self::HOME_X + 0.5, 1, 0.5)));

		for($j = 0; $j < 1200 && $turtle->isPregnant(); ++$j){
			$this->runTicks(1);
		}
		self::assertFalse($turtle->isPregnant(), "eggs laid");
		$eggs = 0;
		for($x = self::HOME_X - 3; $x <= self::HOME_X + 3; ++$x){
			for($z = -3; $z <= 3; ++$z){
				if($this->get($x, 1, $z) instanceof TurtleEgg){
					++$eggs;
				}
			}
		}
		self::assertSame(1, $eggs, "on the home beach");
		$turtle->flagForDespawn();
	}

	public function testTurtleBredOnItsHomeBeachLaysThere() : void{
		$turtle = new Turtle(new Location(self::HOME_X + 0.5, 1, 0.5, $this->world, 0, 0), CompoundTag::create()->setInt(Animal::TAG_AGE, 0));
		$turtle->spawnToAll();
		$turtle->setPregnant(true);
		self::assertTrue($turtle->wantsToLayEgg(), "already home");
		$j = 0;
		for(; $j < 1200 && $turtle->isPregnant(); ++$j){
			$this->runTicks(1);
		}
		self::assertFalse($turtle->isPregnant(), "eggs laid after " . $j . " ticks");
		$turtle->flagForDespawn();
	}

	public function testGoHomeOnHomeFiresWhenAlreadyHome() : void{
		$turtle = new Turtle(new Location(0.5, 1, 0.5, $this->world, 0, 0), CompoundTag::create()->setInt(Animal::TAG_AGE, 0));
		$turtle->setHomePosition(new Vector3(self::HOME_X, 1, 0));
		$turtle->setPregnant(true);
		self::assertFalse($turtle->wantsToLayEgg(), "home far away");
		//strolled back home by itself: the next go_home chance finds it home
		$turtle->setHomePosition(new Vector3(0, 1, 2));
		$fired = false;
		$goal = new GoHomeGoal($turtle, 1.0, 1, 4.0, function() use (&$fired) : void{
			$fired = true;
		});
		self::assertFalse($goal->canStart(), "nothing to walk");
		self::assertTrue($fired, "on_home");
		$turtle->flagForDespawn();
	}

	/**
	 * The sand search reads raw state IDs and skips chunks without sand: a search that finds nothing must stay cheap
	 * (it was about 16 ms with a Block object per spot, now under 1 ms with covered sand everywhere).
	 */
	public function testFailedNestSearchIsCheap() : void{
		$turtle = new Turtle(new Location(0.5, 1, 0.5, $this->world, 0, 0), CompoundTag::create()->setInt(Animal::TAG_AGE, 0));
		$search = (new \ReflectionMethod(Turtle::class, "searchNestPlace"))->getClosure($turtle);

		//no sand nearby at all: palette checks only
		self::assertNull($search());
		$noSand = $this->minTime($search);

		//sand in every chunk of the area, but covered: every column is read
		for($x = -16; $x <= 16; $x += 16){
			for($z = -16; $z <= 16; $z += 16){
				$this->world->setBlockAt($x, 0, $z, VanillaBlocks::SAND(), false);
				$this->world->setBlockAt($x, 1, $z, VanillaBlocks::STONE(), false);
			}
		}
		self::assertNull($search());
		$covered = $this->minTime($search);
		fwrite(STDERR, sprintf("\nfailed nest search: %.3f ms without sand, %.3f ms with covered sand in every chunk\n", $noSand * 1000, $covered * 1000));
		self::assertLessThan(0.0005, $noSand);
		self::assertLessThan(0.008, $covered);

		//uncovered: found, the nearest first
		$this->world->setBlockAt(16, 1, 16, VanillaBlocks::AIR(), false);
		$this->world->setBlockAt(0, 1, 0, VanillaBlocks::AIR(), false);
		self::assertEquals(new Vector3(0, 1, 0), $search());
		$turtle->flagForDespawn();
	}

	/**
	 * @phpstan-param \Closure() : mixed $search
	 */
	private function minTime(\Closure $search) : float{
		$min = PHP_FLOAT_MAX;
		for($i = 0; $i < 7; ++$i){
			$start = hrtime(true);
			$search();
			$min = min($min, (hrtime(true) - $start) / 1e9);
		}
		return $min;
	}
}
