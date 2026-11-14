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
use pocketmine\block\Door;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\BreakDoorGoal;
use pocketmine\entity\ai\goal\FleeSunGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\OpenDoorGoal;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Drowned;
use pocketmine\entity\monster\Husk;
use pocketmine\entity\monster\Piglin;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\monster\Vindicator;
use pocketmine\entity\monster\ZombieVillager;
use pocketmine\entity\monster\ZombifiedPiglin;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\Zombie;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\LightArray;
use pocketmine\world\GameRules;
use pocketmine\world\World;
use function mt_srand;

/**
 * Mobs and doors (opening, breaking) and undead fleeing the sun (flee_sun, avoid_sun).
 */
final class DoorsSunTest extends MobTestCase{

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261021);
		$this->world->setTime(World::TIME_NIGHT);
	}

	private function loc(float $x, float $z) : Location{
		return new Location($x, 1, $z, $this->world, 0, 0);
	}

	/**
	 * A mob only the test moves (through its navigation); flagless goals (doors) still run.
	 *
	 * @phpstan-template T of Mob
	 * @phpstan-param T $mob
	 * @phpstan-return T
	 */
	private function puppet(Mob $mob) : Mob{
		$mob->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_JUMP | Goal::FLAG_LOOK, true);
		$mob->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		$mob->setMaxHealth(10000);
		$mob->setHealth(10000);
		$mob->spawnToAll();
		return $mob;
	}

	/**
	 * A stone wall across x = 6 (3 high) with a door at (6, 1, 0).
	 */
	private function wallWithDoor(Door $door) : void{
		for($z = -24; $z <= 24; ++$z){
			for($y = 1; $y <= 3; ++$y){
				$this->set(6, $y, $z, VanillaBlocks::STONE());
			}
		}
		$door = $door->setFacing(Facing::EAST);
		$this->set(6, 1, 0, (clone $door)->setTop(false));
		$this->set(6, 2, 0, (clone $door)->setTop(true));
	}

	private function doorAt() : Block{
		return $this->get(6, 1, 0);
	}

	/**
	 * Walks the mob towards (12.5, 1, 0.5), behind the door, for up to $ticks ticks or until $until returns true;
	 * returns the ticks run.
	 *
	 * @phpstan-param \Closure() : bool $until
	 */
	private function walkThrough(Mob $mob, int $ticks, \Closure $until) : int{
		for($i = 0; $i < $ticks; ++$i){
			if($i % 20 === 0){
				$mob->getNavigation()->moveTo(12.5, 1, 0.5, 1.0);
			}
			$this->runTicks(1);
			if($until()){
				return $i + 1;
			}
		}
		return $ticks;
	}

	private function zombie(bool $breaksDoors) : Zombie{
		return $this->puppet(new Zombie($this->loc(2.5, 0.5), CompoundTag::create()->setByte("CanBreakDoors", $breaksDoors ? 1 : 0)));
	}

	private function countDoorItems() : int{
		$count = 0;
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof ItemEntity && $entity->getItem()->getBlock() instanceof Door){
				$count += $entity->getItem()->getCount();
			}
		}
		return $count;
	}

	public function testDoorBreakingZombieBreaksAWoodenDoorOnHard() : void{
		$this->difficulty = World::DIFFICULTY_HARD;
		$this->wallWithDoor(VanillaBlocks::OAK_DOOR());
		$zombie = $this->zombie(true);
		self::assertTrue($zombie->getNavigation()->getPathFinder()->canBreakDoors(), "its paths go through doors");
		$ticks = $this->walkThrough($zombie, 400, fn() => !$this->doorAt() instanceof Door);
		self::assertFalse($this->doorAt() instanceof Door, "the door is broken");
		self::assertFalse($this->get(6, 2, 0) instanceof Door, "both halves");
		self::assertGreaterThanOrEqual(BreakDoorGoal::DEFAULT_BREAK_TICKS, $ticks, "after the break time (12 seconds)");
		$this->runTicks(2);
		self::assertSame(0, $this->countDoorItems(), "a broken door drops nothing");
		$zombie->close();
	}

	public function testDoorBreakingZombieOnlyBumpsIntoDoorsOnNormal() : void{
		$this->difficulty = World::DIFFICULTY_NORMAL;
		$this->wallWithDoor(VanillaBlocks::OAK_DOOR());
		$zombie = $this->zombie(true);
		$this->walkThrough($zombie, 400, fn() => false);
		self::assertInstanceOf(Door::class, $this->doorAt(), "no door breaking below hard");
		self::assertLessThan(6.0, $zombie->getPosition()->x, "still in front of the door");
		$zombie->close();
	}

	public function testMobGriefingOffKeepsTheDoor() : void{
		$this->difficulty = World::DIFFICULTY_HARD;
		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		$this->wallWithDoor(VanillaBlocks::OAK_DOOR());
		$zombie = $this->zombie(true);
		$this->walkThrough($zombie, 300, fn() => false);
		self::assertInstanceOf(Door::class, $this->doorAt());
		$zombie->close();
	}

	public function testIronDoorsAreNeverBroken() : void{
		$this->difficulty = World::DIFFICULTY_HARD;
		$this->wallWithDoor(VanillaBlocks::IRON_DOOR());
		$zombie = $this->zombie(true);
		$this->walkThrough($zombie, 300, fn() => false);
		self::assertInstanceOf(Door::class, $this->doorAt());
		$zombie->close();
	}

	public function testOrdinaryZombiesDoNotPathThroughDoors() : void{
		$zombie = $this->zombie(false);
		self::assertFalse($zombie->getNavigation()->getPathFinder()->passesDoors());
		$saved = $zombie->saveNBT();
		self::assertSame(0, $saved->getByte("CanBreakDoors"));
		$zombie->close();
	}

	public function testOneZombieInTenBreaksDoorsAndKeepsIt() : void{
		$breakers = 0;
		$total = 200;
		for($i = 0; $i < $total; ++$i){
			$zombie = new Zombie($this->loc(0.5, 0.5));
			if($zombie->getNavigation()->getPathFinder()->canBreakDoors()){
				++$breakers;
				$copy = new Zombie($this->loc(0.5, 0.5), $zombie->saveNBT());
				self::assertTrue($copy->getNavigation()->getPathFinder()->canBreakDoors(), "saved with the zombie");
				$copy->close();
			}
			$zombie->close();
		}
		self::assertGreaterThan(5, $breakers, "about 10% ($breakers of $total)");
		self::assertLessThan(40, $breakers, "about 10% ($breakers of $total)");

		$piglin = new ZombifiedPiglin($this->loc(0.5, 0.5));
		self::assertFalse($piglin->getNavigation()->getPathFinder()->canBreakDoors(), "zombified piglins open doors instead");
		self::assertTrue($piglin->getNavigation()->getPathFinder()->canOpenDoors());
		$piglin->close();
	}

	public function testConvertedMobsDoNotRollDoorBreaking() : void{
		for($i = 0; $i < 30; ++$i){
			$husk = new Husk($this->loc(0.5, 0.5), CompoundTag::create()->setByte("CanBreakDoors", 1));
			$husk->spawnToAll();
			(new \ReflectionMethod($husk, "convertToZombie"))->invoke($husk);
			foreach($this->world->getEntities() as $entity){
				if($entity instanceof Zombie && !$entity instanceof Husk && !$entity->isFlaggedForDespawn()){
					self::assertFalse($entity->getNavigation()->getPathFinder()->canBreakDoors(), "as_adult adds no can_break_doors");
					$entity->close();
				}
			}
			$husk->close();

			$villager = new VillagerV2($this->loc(0.5, 0.5));
			$zombieVillager = ZombieVillager::fromVillager($villager);
			self::assertFalse($zombieVillager->getNavigation()->getPathFinder()->canBreakDoors(), "entity_transformed adds no can_break_doors");
			$zombieVillager->close();
			$villager->close();
		}
	}

	public function testVindicatorBreaksDoorsOnlyInRaids() : void{
		$this->difficulty = World::DIFFICULTY_NORMAL;
		$this->wallWithDoor(VanillaBlocks::OAK_DOOR());
		$vindicator = $this->puppet(new Vindicator($this->loc(2.5, 0.5)));
		self::assertFalse($vindicator->getNavigation()->getPathFinder()->canBreakDoors(), "not outside raids");
		$this->walkThrough($vindicator, 200, fn() => false);
		self::assertInstanceOf(Door::class, $this->doorAt());

		$vindicator->joinRaid(1, 1, new Vector3(12.5, 1, 0.5));
		self::assertTrue($vindicator->getNavigation()->getPathFinder()->canBreakDoors(), "in a raid");
		$ticks = $this->walkThrough($vindicator, 800, fn() => !$this->doorAt() instanceof Door);
		self::assertFalse($this->doorAt() instanceof Door, "broken in the raid on normal");
		self::assertGreaterThanOrEqual(600, $ticks, "after 30 seconds");

		$vindicator->leaveRaid();
		self::assertFalse($vindicator->getNavigation()->getPathFinder()->canBreakDoors());
		$vindicator->close();
	}

	private function piglin() : Piglin{
		return $this->puppet(new Piglin($this->loc(2.5, 0.5), CompoundTag::create()->setByte("IsImmuneToZombification", 1)));
	}

	public function testPiglinOpensWoodenDoorsAndClosesThem() : void{
		$this->wallWithDoor(VanillaBlocks::OAK_DOOR());
		$piglin = $this->piglin();
		$opened = false;
		$this->walkThrough($piglin, 300, function() use ($piglin, &$opened) : bool{
			$door = $this->doorAt();
			$opened = $opened || ($door instanceof Door && $door->isOpen());
			return $piglin->getPosition()->x > 9.0;
		});
		self::assertTrue($opened, "opened the door");
		self::assertGreaterThan(9.0, $piglin->getPosition()->x, "went through, at " . $piglin->getPosition());
		$this->runTicks(10);
		$door = $this->doorAt();
		self::assertInstanceOf(Door::class, $door);
		self::assertFalse($door->isOpen(), "closed it behind itself");
		$piglin->close();
	}

	public function testPiglinDoesNotOpenIronDoors() : void{
		$this->wallWithDoor(VanillaBlocks::IRON_DOOR());
		$piglin = $this->piglin();
		$this->walkThrough($piglin, 200, fn() => false);
		$door = $this->doorAt();
		self::assertInstanceOf(Door::class, $door);
		self::assertFalse($door->isOpen());
		self::assertLessThan(6.0, $piglin->getPosition()->x);
		$piglin->close();
	}

	public function testSectionDoorFlagFollowsPlacedDoors() : void{
		$watch = $this->world->getPathRecomputeWatch();
		$chunk = $this->world->getChunk(0, 0);
		self::assertNotNull($chunk);
		self::assertSame(0, $watch->getDoorSections($chunk), "no door yet (kept)");
		$this->wallWithDoor(VanillaBlocks::IRON_DOOR());
		self::assertSame(0, $watch->getDoorSections($chunk), "iron doors are not opened");
		$this->set(6, 17, 2, VanillaBlocks::OAK_DOOR()->setFacing(Facing::EAST));
		self::assertSame(1 << (1 - Chunk::MIN_SUBCHUNK_INDEX), $watch->getDoorSections($chunk), "a placed wooden door sets the bit of its section");
	}

	public function testOpenDoorGoalOnlyRunsNearDoors() : void{
		$piglin = $this->piglin();
		$goal = new OpenDoorGoal($piglin);
		self::assertFalse($goal->canStart(), "nothing to do without a path");
		$piglin->getNavigation()->moveTo(2.5, 1, 10.5, 1.0);
		$this->runTicks(1);
		self::assertNotNull($piglin->getNavigation()->getPath());
		self::assertFalse($goal->canStart(), "no door on the path");
		$piglin->close();

		$this->wallWithDoor(VanillaBlocks::OAK_DOOR());
		$piglin = $this->puppet(new Piglin($this->loc(4.5, 0.5), CompoundTag::create()->setByte("IsImmuneToZombification", 1)));
		$goal = new OpenDoorGoal($piglin);
		$piglin->getNavigation()->moveTo(10.5, 1, 0.5, 1.0);
		$this->runTicks(1);
		self::assertTrue($goal->canStart(), "a door in the next nodes");
		$piglin->close();
	}

	/**
	 * Sky light over the test floor: full, except under a stone roof at y = 4 over x/z $min..$max (sky light 10 below
	 * it). Test chunks have no light computed.
	 */
	private function skyWithRoof(int $minX, int $minZ, int $maxX, int $maxZ) : void{
		for($x = $minX; $x <= $maxX; ++$x){
			for($z = $minZ; $z <= $maxZ; ++$z){
				$this->set($x, 4, $z, VanillaBlocks::STONE());
			}
		}
		foreach($this->world->getLoadedChunks() as $chunk){
			$chunk->getSubChunk(0)->setBlockSkyLightArray(LightArray::fill(15));
			$chunk->setLightPopulated();
		}
		for($x = $minX; $x <= $maxX; ++$x){
			for($z = $minZ; $z <= $maxZ; ++$z){
				$chunk = $this->world->getChunk($x >> 4, $z >> 4);
				self::assertNotNull($chunk);
				for($y = 0; $y <= 4; ++$y){
					$chunk->getSubChunk(0)->getBlockSkyLightArray()->set($x & 15, $y, $z & 15, $y === 4 ? 0 : 10);
				}
			}
		}
		$this->world->setTime(World::TIME_NOON);
		$this->runTicks(1);
	}

	private function skeleton(float $x, float $z) : Skeleton{
		$skeleton = new Skeleton($this->loc($x, $z));
		$skeleton->getArmorInventory()->clearAll();
		$skeleton->setMaxHealth(10000);
		$skeleton->setHealth(10000);
		$skeleton->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		$skeleton->spawnToAll();
		return $skeleton;
	}

	public function testBurningSkeletonRunsIntoTheShade() : void{
		$this->skyWithRoof(4, -3, 9, 3);
		$skeleton = $this->skeleton(0.5, 0.5);
		self::assertTrue(FleeSunGoal::seesSky($this->world, 0, 2, 0));
		self::assertFalse(FleeSunGoal::seesSky($this->world, 6, 2, 0));
		$shaded = false;
		for($i = 0; $i < 200 && !$shaded; ++$i){
			if(!$skeleton->isOnFire()){
				$skeleton->setOnFire(8);
			}
			$this->runTicks(1);
			$pos = $skeleton->getPosition();
			$shaded = !FleeSunGoal::seesSky($this->world, $pos->getFloorX(), $pos->getFloorY() + 1, $pos->getFloorZ());
		}
		self::assertTrue($shaded, "found the shade, at " . $skeleton->getPosition());
		$skeleton->close();
	}

	public function testHelmetedSkeletonDoesNotFlee() : void{
		$this->skyWithRoof(2, -10, 10, 10);
		$skeleton = $this->skeleton(0.5, 0.5);
		$skeleton->getArmorInventory()->setHelmet(VanillaItems::IRON_HELMET());
		$skeleton->setOnFire(8);
		$goal = new FleeSunGoal($skeleton);
		self::assertFalse($goal->canStart(), "a helmet keeps the sun off");
		$skeleton->getArmorInventory()->clearAll();
		self::assertTrue($goal->canStart());
		$this->world->setTime(World::TIME_NIGHT);
		$this->runTicks(1);
		self::assertFalse($goal->canStart(), "not at night");
		$skeleton->close();
	}

	public function testBurningMobWithATargetKeepsFighting() : void{
		$this->skyWithRoof(2, -10, 10, 10);
		$drowned = new Drowned($this->loc(0.5, 0.5));
		$drowned->getArmorInventory()->clearAll();
		$drowned->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		$drowned->spawnToAll();
		$target = $this->zombie(false);
		$drowned->setOnFire(8);
		$goal = new FleeSunGoal($drowned);
		$drowned->setAttackTarget($target);
		self::assertFalse($goal->canStart(), "keeps attacking while it burns");
		$drowned->setAttackTarget(null);
		self::assertTrue($goal->canStart(), "runs for the shade without a target");
		$drowned->close();
		$target->close();
	}

	public function testAvoidSunCutsPathsAtTheSunByDay() : void{
		$this->skyWithRoof(-3, -3, 3, 3);
		$skeleton = $this->skeleton(0.5, 0.5);
		$skeleton->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		$navigation = $skeleton->getNavigation();
		$navigation->moveTo(12.5, 1, 0.5, 1.0);
		$this->runTicks(1);
		$path = $navigation->getPath();
		self::assertNotNull($path);
		self::assertFalse($path->reachesTarget(), "cut before the sun");
		$end = $path->getEnd();
		self::assertNotNull($end);
		self::assertLessThanOrEqual(3, $end[0], "ends in the shade");

		//at night the whole path
		$navigation->stop();
		$this->world->setTime(World::TIME_NIGHT);
		$this->runTicks(41);
		$navigation->moveTo(12.5, 1, 0.5, 1.0);
		$this->runTicks(1);
		$path = $navigation->getPath();
		self::assertNotNull($path);
		self::assertTrue($path->reachesTarget(), "at night");
		$skeleton->close();
	}

	public function testMobsWithoutAvoidSunWalkIntoTheSun() : void{
		$this->skyWithRoof(-3, -3, 3, 3);
		$zombie = $this->puppet(new Zombie($this->loc(0.5, 0.5), CompoundTag::create()->setByte("CanBreakDoors", 0)));
		$zombie->getNavigation()->moveTo(12.5, 1, 0.5, 1.0);
		$this->runTicks(1);
		self::assertTrue($zombie->getNavigation()->getPath()?->reachesTarget() ?? false);
		$zombie->close();
	}
}
