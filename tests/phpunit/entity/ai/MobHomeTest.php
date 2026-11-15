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
use pocketmine\block\CreakingHeart;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\hostile\goal\FreeWanderGoal;
use pocketmine\entity\ai\navigation\RandomPos;
use pocketmine\entity\animal\Animal;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\HappyGhast;
use pocketmine\entity\aquatic\Nautilus;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Creaking;
use pocketmine\entity\monster\ElderGuardian;
use pocketmine\entity\monster\Guardian;
use pocketmine\entity\monster\PiglinBrute;
use pocketmine\entity\monster\ZombieNautilus;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\VanillaItems;
use pocketmine\math\Axis;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\vibration\VibrationListener;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;
use function mt_srand;
use function sqrt;

final class MobHomeTest extends MobTestCase{

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261021);
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * Fills a box with water source blocks (no block updates).
	 */
	private function water(int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ) : void{
		$water = VanillaBlocks::WATER();
		for($x = $minX; $x <= $maxX; ++$x){
			for($y = $minY; $y <= $maxY; ++$y){
				for($z = $minZ; $z <= $maxZ; ++$z){
					$this->world->setBlockAt($x, $y, $z, $water, false);
				}
			}
		}
	}

	private function player() : Player{
		$player = $this->createMock(Player::class);
		$player->method("getUniqueId")->willReturn(Uuid::uuid4());
		$player->method("isConnected")->willReturn(false);
		$player->method("getWorld")->willReturn($this->world);
		//the mock is never spawned: nothing to dispose of when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	private static function homeDistance(Mob $mob) : float{
		$loc = $mob->getLocation();
		$distanceSquared = $mob->getHomeDistanceSquared($loc->x, $loc->y, $loc->z);
		self::assertNotNull($distanceSquared);
		return sqrt($distanceSquared);
	}

	public function testHomeRestrictionAndSaving() : void{
		$cow = new Cow($this->loc(3.5, 1, 4.5));
		self::assertNull($cow->getHomePos(), "no home unless the mob has one");
		self::assertFalse($cow->hasHomeRestriction());
		self::assertTrue($cow->isWithinHome(1000, 1, 1000));
		self::assertArrayNotHasKey(Mob::TAG_HOME_X, $cow->saveNBT()->getValue(), "nothing saved without a home");

		$cow->setHomeRestriction(8);
		self::assertTrue($cow->hasHomeRestriction());
		self::assertEquals(new Vector3(3, 1, 4), $cow->getHomePos(), "home where the mob is");
		self::assertTrue($cow->isWithinHome(10.0, 1, 4.5));
		self::assertFalse($cow->isWithinHome(12.0, 1, 4.5));

		$nbt = $cow->saveNBT();
		$cow->flagForDespawn();
		$loaded = new Cow($this->loc(20.5, 1, 20.5), $nbt);
		self::assertEquals(new Vector3(3, 1, 4), $loaded->getHomePos(), "home saved and loaded");
		self::assertSame(0, $loaded->getHomeRadius(), "the radius comes from the mob, not the save");
		$loaded->flagForDespawn();
	}

	public function testLandWanderTargetsStayWithinTheHome() : void{
		$cow = new Cow($this->loc(0.5, 1, 0.5));
		$cow->setHomeRestriction(5);
		for($i = 0; $i < 200; ++$i){
			$pos = RandomPos::getLandPos($cow, 10, 7);
			if($pos !== null){
				self::assertTrue($cow->isWithinHome($pos->x + 0.5, $pos->y, $pos->z + 0.5), "wander target $pos outside the home");
			}
		}
		$cow->flagForDespawn();
	}

	public function testGuardianWanderTargetsStayWithinSixteenBlocks() : void{
		$this->water(-24, 1, -24, 24, 6, 24);
		$guardian = new Guardian($this->loc(0.5, 3, 0.5));
		self::assertSame(Guardian::HOME_RADIUS, $guardian->getHomeRadius());
		self::assertEquals(new Vector3(0, 3, 0), $guardian->getHomePos(), "home where it spawned");
		//at the edge of its home: the targets don't go farther
		$guardian->teleport(new Vector3(14.5, 3, 0.5));
		$goal = new class($guardian, $guardian->getSwimControl(), 0.1, 80, 8, 4, FreeWanderGoal::MEDIUM_WATER) extends FreeWanderGoal{
			public function pick() : ?Vector3{
				return $this->pickPoint();
			}
		};
		$found = 0;
		for($i = 0; $i < 200; ++$i){
			$pos = $goal->pick();
			if($pos !== null){
				++$found;
				self::assertTrue($guardian->isWithinHome($pos->x, $pos->y, $pos->z), "wander target $pos beyond 16 blocks");
			}
		}
		self::assertGreaterThan(0, $found);
		$guardian->flagForDespawn();
	}

	public function testElderGuardianSwimsBackHome() : void{
		$this->water(-8, 1, -4, 40, 6, 4);
		$elder = new ElderGuardian($this->loc(0.5, 3, 0.5));
		$elder->spawnToAll();
		$elder->teleport(new Vector3(30.5, 3, 0.5));
		self::assertGreaterThan(16.0, self::homeDistance($elder));
		for($i = 0; $i < 600 && self::homeDistance($elder) >= 15.0; ++$i){
			$this->runTicks(1);
		}
		self::assertLessThan(15.0, self::homeDistance($elder), "pushed out of its monument, it swims back");
		$elder->flagForDespawn();
	}

	public function testNautilusHomeFollowsItsTamingAndSaddle() : void{
		$this->water(-10, 1, -10, 30, 4, 10);
		$nautilus = new Nautilus($this->loc(0.5, 2, 0.5));
		$nautilus->setAge(0);
		$nautilus->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		self::assertSame(0, $nautilus->getHomeRadius(), "wild: not restricted");

		$nautilus->teleport(new Vector3(5.5, 2, 0.5));
		$player = $this->player();
		$nautilus->setOwner($player);
		self::assertSame(Nautilus::HOME_RADIUS_UNSADDLED, $nautilus->getHomeRadius());
		self::assertEquals(new Vector3(5, 2, 0), $nautilus->getHomePos(), "home set on tame");

		$nautilus->teleport(new Vector3(10.5, 2, 0.5));
		$nautilus->setSaddled(true);
		self::assertSame(Nautilus::HOME_RADIUS_SADDLED, $nautilus->getHomeRadius());
		self::assertEquals(new Vector3(10, 2, 0), $nautilus->getHomePos(), "home set on saddle");

		$nautilus->teleport(new Vector3(15.5, 2, 0.5));
		(new \ReflectionMethod($nautilus, "onPassengerRemoved"))->invoke($nautilus, $player);
		self::assertEquals(new Vector3(15, 2, 0), $nautilus->getHomePos(), "home set on dismount");

		$cow = new Cow($this->loc(20.5, 5, 0.5));
		$nautilus->setLeashHolder($cow);
		$nautilus->teleport(new Vector3(20.5, 2, 0.5));
		$nautilus->unleash(false);
		self::assertEquals(new Vector3(20, 2, 0), $nautilus->getHomePos(), "home set on unleash");

		$nautilus->setSaddled(false);
		self::assertSame(Nautilus::HOME_RADIUS_UNSADDLED, $nautilus->getHomeRadius());
		$cow->flagForDespawn();
		$nautilus->flagForDespawn();
	}

	public function testZombieNautilusHomeOnTame() : void{
		$this->water(-4, 1, -4, 4, 4, 4);
		$nautilus = new ZombieNautilus($this->loc(0.5, 2, 0.5));
		self::assertSame(0, $nautilus->getHomeRadius());
		$nautilus->teleport(new Vector3(2.5, 2, 1.5));
		$nautilus->setOwner($this->player());
		self::assertSame(ZombieNautilus::HOME_RADIUS_UNSADDLED, $nautilus->getHomeRadius());
		self::assertEquals(new Vector3(2, 2, 1), $nautilus->getHomePos());
		$nautilus->flagForDespawn();
	}

	public function testHappyGhastHomeRadiusFollowsItsHarness() : void{
		$ghast = new HappyGhast($this->loc(0.5, 10, 0.5), CompoundTag::create()->setInt(Animal::TAG_AGE, 0));
		self::assertSame(HappyGhast::HOME_RADIUS_UNHARNESSED, $ghast->getHomeRadius());
		self::assertEquals(new Vector3(0, 10, 0), $ghast->getHomePos(), "home where it spawned");

		$ghast->teleport(new Vector3(8.5, 12, 3.5));
		$ghast->setHarness(VanillaItems::HARNESS());
		self::assertSame(HappyGhast::HOME_RADIUS_SMALL, $ghast->getHomeRadius());
		self::assertEquals(new Vector3(8, 12, 3), $ghast->getHomePos(), "home set on harness");

		$ghast->teleport(new Vector3(-6.5, 11, 3.5));
		$ghast->setHarness(null);
		self::assertSame(HappyGhast::HOME_RADIUS_UNHARNESSED, $ghast->getHomeRadius());
		self::assertEquals(new Vector3(-7, 11, 3), $ghast->getHomePos(), "home set on unharness");

		//wander targets of a parked happy ghast stay around it
		for($i = 0; $i < 100; ++$i){
			$pos = $ghast->findWanderPos(16, 4);
			if($pos !== null){
				self::assertTrue($ghast->isWithinHome($pos->x, $pos->y, $pos->z));
			}
		}

		$baby = new HappyGhast($this->loc(0.5, 10, 0.5), CompoundTag::create()->setInt(Animal::TAG_AGE, Animal::BABY_AGE));
		self::assertTrue($baby->isBaby());
		self::assertSame(HappyGhast::HOME_RADIUS_SMALL, $baby->getHomeRadius(), "ghastling");
		$ghast->flagForDespawn();
		$baby->flagForDespawn();
	}

	private function creakingWithHeart() : Creaking{
		$this->world->setTime(World::TIME_MIDNIGHT);
		$this->set(0, 1, 0, VanillaBlocks::PALE_OAK_LOG()->setAxis(Axis::Y));
		$this->set(0, 3, 0, VanillaBlocks::PALE_OAK_LOG()->setAxis(Axis::Y));
		$this->set(0, 2, 0, VanillaBlocks::CREAKING_HEART()->setAxis(Axis::Y));
		$this->runTicks(2);
		$heart = $this->get(0, 2, 0);
		self::assertInstanceOf(CreakingHeart::class, $heart);
		$creaking = $heart->summonCreaking();
		self::assertNotNull($creaking);
		return $creaking;
	}

	public function testHeartBoundCreakingStaysWithinItsHome() : void{
		$this->floor(-24, -8, 47, 8);
		$creaking = $this->creakingWithHeart();
		self::assertSame(Creaking::HOME_RADIUS, $creaking->getHomeRadius());
		self::assertTrue($creaking->isHomeAllMovement());
		self::assertEquals(new Vector3(0, 2, 0), $creaking->getHomePos(), "home at the heart");

		$creaking->teleport(new Vector3(20.5, 1, 0.5));
		$inside = new Cow($this->loc(28.5, 1, 0.5));
		$outside = new Cow($this->loc(38.5, 1, 0.5));
		$goal = new MeleeAttackGoal($creaking, 1.0, Creaking::ATTACK_DAMAGE, Creaking::OBSERVATION_RANGE);
		$creaking->setAttackTarget($inside);
		self::assertTrue($goal->canStart(), "target inside its home");
		$creaking->setAttackTarget($outside);
		self::assertFalse($goal->canStart(), "all_movement: no chase beyond 32 blocks of the heart");

		$player = new Creaking($this->loc(5.5, 1, 5.5));
		self::assertSame(0, $player->getHomeRadius(), "player-spawned: no home");
		$player->setAttackTarget($outside);
		$goal = new MeleeAttackGoal($player, 1.0, Creaking::ATTACK_DAMAGE, 64.0);
		self::assertTrue($goal->canStart());

		$creaking->setAttackTarget(null);
		$player->setAttackTarget(null);
		$inside->flagForDespawn();
		$outside->flagForDespawn();
		$player->flagForDespawn();
	}

	public function testCreakingEmitsVibrations() : void{
		$creaking = $this->creakingWithHeart();
		$listener = new class($creaking) implements VibrationListener{
			/** @var GameEvent[] */
			public array $events = [];

			public function __construct(private Creaking $creaking){}

			public function getListenerPosition() : Vector3{
				return new Vector3(0, 1, 0);
			}

			public function getListenerRange() : int{
				return 16;
			}

			public function onVibration(Vector3 $pos, GameEvent $event, ?Entity $source, ?Block $affected, float $distance) : void{
				if($source === $this->creaking){
					$this->events[] = $event;
				}
			}
		};
		$this->world->getVibrations()->addListener($listener);
		$cow = new Cow($this->loc(3.5, 1, 3.5));
		$creaking->attack(new EntityDamageByEntityEvent($cow, $creaking, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		self::assertSame([GameEvent::ENTITY_ACTION], $listener->events, "damaged_by_entity: entity_act");

		$listener->events = [];
		$creaking->crumble();
		self::assertSame([GameEvent::ENTITY_DIE], $listener->events, "crumble: entity_die");
		$this->world->getVibrations()->removeListener($listener);
		$cow->flagForDespawn();
	}

	public function testPiglinBruteWalksBackToItsSpawn() : void{
		$brute = new PiglinBrute($this->loc(0.5, 1, 0.5));
		$brute->setImmuneToZombification(true);
		$brute->spawnToAll();
		self::assertEquals(new Vector3(0, 1, 0), $brute->getHomePos());
		$brute->teleport(new Vector3(16.5, 1, 0.5));
		for($i = 0; $i < 3000 && self::homeDistance($brute) > 4.5; ++$i){
			$this->runTicks(1);
		}
		self::assertLessThanOrEqual(4.5, self::homeDistance($brute), "back at its bastion");
		$brute->flagForDespawn();
	}
}
