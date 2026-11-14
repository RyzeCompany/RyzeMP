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

namespace pocketmine\entity\aquatic;

use pocketmine\block\Fence;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Water;
use pocketmine\entity\ai\goal\AvoidEntityGoal;
use pocketmine\entity\ai\goal\FlockingGoal;
use pocketmine\entity\ai\goal\RandomSwimGoal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\animal\Axolotl;
use pocketmine\entity\Location;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\world\mobs\spawn\BundledSpawnRules;
use Symfony\Component\Filesystem\Path;
use function count;
use function floor;
use function max;
use function min;
use function mt_rand;
use function mt_srand;
use function sqrt;

/**
 * Fish schools (minecraft:flocking), tropical fish varieties per herd, fish wandering (random_swim) and the fish bone
 * looting count.
 */
final class FishSchoolTest extends MobTestCase{

	protected function setUp() : void{
		parent::setUp();
		for($x = -20; $x <= 20; ++$x){
			for($z = -20; $z <= 20; ++$z){
				for($y = 1; $y <= 6; ++$y){
					$this->world->setBlockAt($x, $y, $z, VanillaBlocks::WATER(), false);
				}
			}
		}
		PathBlockTypes::clearCache();
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * @phpstan-template T of AbstractFish
	 * @phpstan-param \Closure(Location) : T $create
	 * @phpstan-param list<array{float, float}> $positions
	 * @return AbstractFish[]
	 * @phpstan-return list<T>
	 */
	private function spawnFish(\Closure $create, array $positions) : array{
		$fish = [];
		foreach($positions as [$x, $z]){
			$f = $create($this->loc($x, 3, $z));
			$f->spawnToAll();
			$fish[] = $f;
		}
		return $fish;
	}

	public function testSpreadCodConvergeIntoOneSchool() : void{
		mt_srand(1234);
		$fish = $this->spawnFish(fn(Location $l) => new Cod($l), [[-4, -4], [4, -4], [-4, 4], [4, 4], [0, 0], [2, -2]]);
		$this->runTicks(400);
		$largest = 0;
		foreach($fish as $f){
			$largest = max($largest, $f->getFlockingGoal()->getSchoolSize());
		}
		//a loner (loner_chance) may drift away, the rest form one school of at least low_flock_limit
		self::assertGreaterThanOrEqual(4, $largest);
		$distanceSum = 0.0;
		$followers = 0;
		for($t = 0; $t < 10; ++$t){
			$this->runTicks(20);
			foreach($fish as $f){
				$leader = $f->getFlockingGoal()->getLeader();
				if($leader !== null){
					$distanceSum += sqrt($leader->getPosition()->distanceSquared($f->getPosition()));
					++$followers;
				}
			}
		}
		self::assertGreaterThanOrEqual(30, $followers);
		//followers keep about the influence radius (3) from their swimming leader, trailing it a little
		self::assertLessThan(3.5, $distanceSum / $followers);
		//each one has its own place around the leader: they don't pile up (and push each other) on one spot
		$nearestSum = 0.0;
		foreach($fish as $f){
			$nearest = 100.0;
			foreach($fish as $other){
				if($other !== $f){
					$nearest = min($nearest, $f->getPosition()->distance($other->getPosition()));
				}
			}
			$nearestSum += $nearest;
		}
		self::assertGreaterThan(0.6, $nearestSum / count($fish), "separation");
	}

	public function testSchoolSizeIsLimited() : void{
		mt_srand(42);
		$positions = [];
		for($i = 0; $i < 12; ++$i){
			$positions[] = [($i % 4) * 1.5 - 2, (int) ($i / 4) * 1.5 - 2];
		}
		$fish = $this->spawnFish(fn(Location $l) => new Cod($l), $positions);
		$this->runTicks(300);
		$largest = 0;
		foreach($fish as $f){
			$largest = max($largest, $f->getFlockingGoal()->getSchoolSize());
		}
		self::assertGreaterThan(1, $largest);
		self::assertLessThanOrEqual(8, $largest, "high_flock_limit 8");
	}

	public function testLonerNeverJoins() : void{
		mt_srand(7);
		[$a, $b, $c] = $this->spawnFish(fn(Location $l) => new class($l) extends Cod implements NeverSavedWithChunkEntity{
			protected function createFlockingGoal() : FlockingGoal{
				return new FlockingGoal($this, 3.0, 4, 8, 1.0, 1.95);
			}
		}, [[0, 0], [1, 0], [0, 1]]);
		$this->runTicks(300);
		foreach([$a, $b, $c] as $f){
			self::assertFalse($f->getFlockingGoal()->isFollower());
			self::assertSame(1, $f->getFlockingGoal()->getSchoolSize());
		}
	}

	public function testDifferentKindsDontSchool() : void{
		mt_srand(5);
		$cod = $this->spawnFish(fn(Location $l) => new Cod($l), [[0, 0]])[0];
		$salmon = $this->spawnFish(fn(Location $l) => new Salmon($l), [[1, 0]])[0];
		$this->runTicks(250);
		self::assertSame(1, $cod->getFlockingGoal()->getSchoolSize());
		self::assertSame(1, $salmon->getFlockingGoal()->getSchoolSize());
		self::assertFalse($cod->getFlockingGoal()->isFollower());
	}

	public function testTropicalFishOnlySchoolWithTheSameLook() : void{
		mt_srand(9);
		$fish = $this->spawnFish(fn(Location $l) => new TropicalFish($l), [[0, 0], [1, 0], [0, 1], [1, 1], [-1, 0], [0, -1], [-1, -1], [2, 2], [2, 0]]);
		foreach($fish as $i => $f){
			$f->setVariety($i < 4 ? "clownfish" : ($i < 8 ? "yellow_tang" : "blue_dory"));
		}
		$schooled = 0;
		for($t = 0; $t < 30; ++$t){
			$this->runTicks(10);
			foreach($fish as $f){
				$leader = $f->getFlockingGoal()->getLeader();
				if($leader !== null){
					++$schooled;
					self::assertInstanceOf(TropicalFish::class, $leader);
					self::assertTrue($f->hasSameLook($leader), "match_variants: only fish of the same look school together");
				}
			}
			//the only blue dory stays alone
			self::assertSame(1, $fish[8]->getFlockingGoal()->getSchoolSize());
			self::assertFalse($fish[8]->getFlockingGoal()->isFollower());
		}
		self::assertGreaterThan(30, $schooled);
	}

	public function testHerdMembersOfTheSameLookSchool() : void{
		$fish = $this->spawnFish(fn(Location $l) => new TropicalFish($l), [[0, 0], [1, 0]]);
		$fish[0]->setVariety("clownfish");
		$fish[1]->setVariety("yellow_tang");
		self::assertFalse($fish[1]->getFlockingGoal()->follow($fish[0]->getFlockingGoal()));
		$fish[1]->setVariety("clownfish");
		self::assertTrue($fish[1]->getFlockingGoal()->follow($fish[0]->getFlockingGoal()));
		self::assertSame($fish[0], $fish[1]->getFlockingGoal()->getLeader());
	}

	public function testFollowersLeaveWhenTheLeaderIsGone() : void{
		[$leader, $follower] = $this->spawnFish(fn(Location $l) => new Cod($l), [[0, 0], [1, 0]]);
		self::assertTrue($follower->getFlockingGoal()->follow($leader->getFlockingGoal()));
		self::assertSame(2, $leader->getFlockingGoal()->getSchoolSize());
		$leader->flagForDespawn();
		$this->runTicks(5);
		self::assertFalse($follower->getFlockingGoal()->isFollower());
	}

	public function testHerdVarietyEvent() : void{
		$fish = new TropicalFish($this->loc(0, 3, 0));
		self::assertTrue($fish->applyVarietyEvent("minecraft:become_yellow_tang"));
		[$base, $pattern, $mark, $shape] = TropicalFish::PRESETS["yellow_tang"];
		self::assertSame([$base, $pattern, $mark, $shape], [$fish->getBaseColor(), $fish->getPatternColor(), $fish->getPattern(), $fish->getShape()]);
		//the behaviour pack spells it "anenonme"
		self::assertTrue($fish->applyVarietyEvent("minecraft:become_anenonme"));
		self::assertSame(TropicalFish::PRESETS["anemone"], [$fish->getBaseColor(), $fish->getPatternColor(), $fish->getPattern(), $fish->getShape()]);
		self::assertFalse($fish->applyVarietyEvent("minecraft:entity_born"));
		self::assertFalse($fish->applyVarietyEvent("minecraft:become_shark"));
		$fish->close();
	}

	public function testBundledTropicalFishHerdEventsAreVarieties() : void{
		$rules = BundledSpawnRules::loadFile(Path::join(\pocketmine\RESOURCE_PATH, BundledSpawnRules::FILE));
		self::assertNotNull($rules);
		$fish = new TropicalFish($this->loc(0, 3, 0));
		$events = [];
		$plainHerds = 0;
		foreach($rules->getConditions() as $condition){
			if($condition->identifier !== "minecraft:tropicalfish"){
				continue;
			}
			foreach($condition->herds as $herd){
				if($herd->event === null){
					++$plainHerds;
					continue;
				}
				self::assertSame(0, $herd->eventSkipCount, "the whole herd gets the variety");
				self::assertTrue($fish->applyVarietyEvent($herd->event), $herd->event);
				$events[$herd->event] = true;
			}
		}
		$fish->close();
		self::assertCount(22, $events);
		self::assertGreaterThan(0, $plainHerds, "the small herds keep the random look");
	}

	public function testNewFishHaveAFullyRandomLook() : void{
		mt_srand(3);
		$presets = 0;
		$colors = [];
		for($i = 0; $i < 200; ++$i){
			$fish = new TropicalFish($this->loc(0, 3, 0));
			$look = [$fish->getBaseColor(), $fish->getPatternColor(), $fish->getPattern(), $fish->getShape()];
			foreach(TropicalFish::PRESETS as $preset){
				if($preset === $look){
					++$presets;
					break;
				}
			}
			$colors[$fish->getBaseColor()] = true;
			self::assertLessThanOrEqual(14, $fish->getBaseColor());
			self::assertLessThanOrEqual(14, $fish->getPatternColor());
			$fish->close();
		}
		//a random look is one of the 22 presets about 22 / 2700 of the time (it was 90% before)
		self::assertLessThan(10, $presets);
		self::assertGreaterThan(12, count($colors));
	}

	public function testFishKeepSwimming() : void{
		mt_srand(11);
		$fish = $this->spawnFish(fn(Location $l) => new class($l) extends Cod implements NeverSavedWithChunkEntity{
			protected function createFlockingGoal() : FlockingGoal{
				return new FlockingGoal($this, 3.0, 4, 8, 1.0, 1.95); //alone
			}
		}, [[0, 0]])[0];
		$this->runTicks(20);
		$swimming = 0;
		$minX = $maxX = $fish->getPosition()->x;
		for($t = 0; $t < 200; ++$t){
			$this->runTicks(1);
			foreach($fish->getGoalSelector()->getRunningGoals() as $goal){
				if($goal instanceof RandomSwimGoal){
					++$swimming;
				}
			}
			$minX = min($minX, $fish->getPosition()->x);
			$maxX = max($maxX, $fish->getPosition()->x);
		}
		//interval 0: a new swim starts within a goal evaluation (3 ticks) after the previous one ends
		self::assertGreaterThan(170, $swimming);
		self::assertGreaterThan(2.0, $maxX - $minX);
	}

	public function testFishStillFleeFromAxolotls() : void{
		mt_srand(21);
		$cod = $this->spawnFish(fn(Location $l) => new class($l) extends Cod implements NeverSavedWithChunkEntity{
			protected function createFlockingGoal() : FlockingGoal{
				return new FlockingGoal($this, 3.0, 4, 8, 1.0, 1.95); //alone
			}
		}, [[0, 0]])[0];
		$axolotl = new Axolotl($this->loc(2.5, 3, 0.5));
		$axolotl->spawnToAll();
		self::assertTrue(Axolotl::isAnyNear($this->world, 0.5, 0.5, 7.0));
		$start = $cod->getPosition()->distance($axolotl->getPosition());
		$fled = false;
		for($t = 0; $t < 60 && !$fled; ++$t){
			$this->runTicks(1);
			foreach($cod->getGoalSelector()->getRunningGoals() as $goal){
				if($goal instanceof AvoidEntityGoal){
					$fled = true;
				}
			}
		}
		self::assertTrue($fled, "the cod flees from the axolotl (avoid_mob_type)");
		$axolotl->close();
		self::assertGreaterThan(0.0, $start);
	}

	public function testAxolotlsAreOnlyNearInTheirChunks() : void{
		self::assertFalse(Axolotl::isAnyNear($this->world, 0.5, 0.5, 7.0));
		$axolotl = new Axolotl($this->loc(2.5, 3, 0.5));
		$axolotl->spawnToAll();
		//chunk 0,0 is touched from chunk -1,-1 but not from farther away
		self::assertTrue(Axolotl::isAnyNear($this->world, -3.5, -3.5, 7.0));
		self::assertFalse(Axolotl::isAnyNear($this->world, 40.5, 0.5, 7.0));
		self::assertFalse(Axolotl::isAnyNear($this->world, -20.5, 0.5, 7.0));
		//teleported to another chunk: counted there at once
		$axolotl->teleport(new Vector3(40.5, 3, 0.5));
		self::assertTrue(Axolotl::isAnyNear($this->world, 40.5, 0.5, 7.0));
		self::assertFalse(Axolotl::isAnyNear($this->world, 0.5, 0.5, 7.0));
		$axolotl->close();
		self::assertFalse(Axolotl::isAnyNear($this->world, 40.5, 0.5, 7.0));
	}

	public function testWaterAroundIsLookedUpAgainAfterABlockChange() : void{
		$cod = new Cod($this->loc(0.5, 3, 0.5));
		$cod->spawnToAll();
		$this->runTicks(2);
		$around = new \ReflectionMethod(Cod::class, "getBlocksAroundWithEntityInsideActions");
		self::assertNotEmpty($around->invoke($cod));
		//the water around the fish turns to air: the neighbour update tells the fish
		for($x = -1; $x <= 1; ++$x){
			for($z = -1; $z <= 1; ++$z){
				for($y = 2; $y <= 4; ++$y){
					$this->world->setBlockAt($x, $y, $z, VanillaBlocks::AIR());
				}
			}
		}
		$this->runTicks(1);
		$blocks = $around->invoke($cod);
		self::assertIsArray($blocks);
		foreach($blocks as $block){
			self::assertNotInstanceOf(Water::class, $block);
		}
		$cod->close();
	}

	public function testSwimmingFishDoNotEnterBlocks() : void{
		mt_srand(5);
		//pillars, fences and blocks placed next to the fish while they swim: the movement without collision lookup
		//(see AbstractFish::move()) never takes a box into a collision box
		for($x = -20; $x <= 20; $x += 3){
			for($z = -20; $z <= 20; $z += 3){
				$this->world->setBlockAt($x, mt_rand(1, 5), $z, mt_rand(0, 1) === 0 ? VanillaBlocks::STONE() : VanillaBlocks::OAK_FENCE(), false);
			}
		}
		PathBlockTypes::clearCache();
		$fish = [];
		for($i = 0; $i < 30; ++$i){
			$f = new Cod($this->loc(mt_rand(-18, 18) + 0.5, 3.2, mt_rand(-18, 18) + 0.5));
			if(count($this->world->getBlockCollisionBoxes($f->getBoundingBox())) !== 0){
				$f->close();
				continue;
			}
			$f->spawnToAll();
			$fish[] = $f;
		}
		self::assertNotEmpty($fish);
		for($t = 0; $t < 300; ++$t){
			$this->runTicks(1);
			if($t % 20 === 10){
				//a block next to each fish, outside its box
				foreach($fish as $f){
					$pos = $f->getPosition();
					$x = (int) floor($pos->x) + 1;
					$y = (int) floor($pos->y);
					$z = (int) floor($pos->z);
					if(
						$x <= 20 && $y >= 1 && $y <= 6 && count($this->world->getNearbyEntities(new AxisAlignedBB($x, $y, $z, $x + 1, $y + 1, $z + 1))) === 0 &&
						!$this->isFenceAround($x, $y, $z)
					){
						$this->world->setBlockAt($x, $y, $z, VanillaBlocks::STONE());
					}
				}
			}
			foreach($fish as $f){
				$bb = $f->getBoundingBox()->contractedCopy(0.01, 0.01, 0.01);
				self::assertCount(0, $this->world->getBlockCollisionBoxes($bb), "fish inside a block on tick $t");
			}
		}
	}

	/**
	 * A block placed next to a fence changes the fence's connections, and so its box reaching into the block above,
	 * without the world's collision box cache of that block knowing (any entity, fish or not, can then end up in it).
	 */
	private function isFenceAround(int $x, int $y, int $z) : bool{
		for($dx = -1; $dx <= 1; ++$dx){
			for($dy = -2; $dy <= 1; ++$dy){
				for($dz = -1; $dz <= 1; ++$dz){
					if($this->world->getBlockAt($x + $dx, $y + $dy, $z + $dz) instanceof Fence){
						return true;
					}
				}
			}
		}
		return false;
	}

	public function testBoneLootingCount() : void{
		$bonus = new \ReflectionMethod(AbstractFish::class, "boneLootingBonus");
		self::assertSame(0, $bonus->invoke(null, 0));
		mt_srand(17);
		$seen = [];
		for($i = 0; $i < 200; ++$i){
			$extra = $bonus->invoke(null, 3);
			self::assertIsInt($extra);
			self::assertGreaterThanOrEqual(3, $extra);
			self::assertLessThanOrEqual(6, $extra);
			$seen[$extra] = true;
		}
		//Looting III: 1 + 3..6 = 4-7 bones
		self::assertCount(4, $seen);
	}
}
