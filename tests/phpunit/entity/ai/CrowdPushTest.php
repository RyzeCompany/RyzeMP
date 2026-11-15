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
use pocketmine\entity\animal\Animal;
use pocketmine\entity\animal\Camel;
use pocketmine\entity\animal\CamelHusk;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\HappyGhast;
use pocketmine\entity\animal\Sniffer;
use pocketmine\entity\animal\SulfurCube;
use pocketmine\entity\effect\EffectManager;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Shulker;
use pocketmine\entity\monster\Slime;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\Zombie;
use pocketmine\inventory\ArmorInventory;
use pocketmine\math\AxisAlignedBB;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\world\ChunkTicker;
use pocketmine\world\redstone\NullThreadSafeLogger;
use function array_slice;
use function array_unique;
use function count;
use function floor;
use function intdiv;
use function max;
use function min;
use function sqrt;

/**
 * Entity pushing (Bedrock minecraft:pushable_by_entity), the fair AI budgets and the activation range.
 */
final class CrowdPushTest extends MobTestCase{
	/**
	 * @var Player[]
	 * @phpstan-var list<Player>
	 */
	private array $fakePlayers = [];

	protected function tearDown() : void{
		foreach($this->fakePlayers as $player){
			if($this->world->getEntity($player->getId()) === $player){
				$this->world->removeEntity($player);
			}
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		}
		$this->fakePlayers = [];
		AiBudget::configure(true, 100000, 100000, 400, requireNearbyPlayer: false);
		parent::tearDown();
	}

	private function loc(float $x, float $z, float $y = 1.0) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * Keeps the mob's AI running (so that it pushes and is pushed) without any goal moving or turning it.
	 *
	 * @phpstan-template T of Mob
	 * @phpstan-param T $mob
	 * @phpstan-return T
	 */
	private function still(Mob $mob) : Mob{
		$mob->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		return $mob;
	}

	/**
	 * A survival Player built without its constructor, standing in the test world (see PlayerProximityEquivalenceTest).
	 */
	private function fakePlayer(float $x, float $z) : Player{
		$player = (new \ReflectionClass(Player::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(Entity::class, "id"))->setValue($player, Entity::nextRuntimeId());
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, $this->loc($x, $z));
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new NullThreadSafeLogger());
		$player->size = new EntitySizeInfo(1.8, 0.6, 1.62);
		$player->boundingBox = new AxisAlignedBB($x - 0.3, 1, $z - 0.3, $x + 0.3, 2.8, $z + 0.3);
		(new \ReflectionProperty(Player::class, "gamemode"))->setValue($player, GameMode::SURVIVAL);
		$session = $this->createMock(NetworkSession::class);
		$session->method("isConnected")->willReturn(true);
		(new \ReflectionProperty(Player::class, "networkSession"))->setValue($player, $session);
		(new \ReflectionProperty(Living::class, "armorInventory"))->setValue($player, new ArmorInventory($player));
		(new \ReflectionProperty(Living::class, "effectManager"))->setValue($player, new EffectManager($player));
		$this->world->addEntity($player);
		$this->fakePlayers[] = $player;
		return $player;
	}

	private static function horizontalDistance(Entity $a, Entity $b) : float{
		$la = $a->getLocation();
		$lb = $b->getLocation();
		return sqrt(($la->x - $lb->x) ** 2 + ($la->z - $lb->z) ** 2);
	}

	public function testJavaPushFormula() : void{
		//Java Entity.push(Entity): offset 0.3/0.1 -> sqrt(0.3) = 0.5477, push = offset / 0.5477 * 0.05
		MobPushing::computePush(0.3, 0.1, 1, 2, $x, $z);
		self::assertEqualsWithDelta(0.3 / sqrt(0.3) * 0.05, $x, 1e-12);
		self::assertEqualsWithDelta(0.1 / sqrt(0.3) * 0.05, $z, 1e-12);
		//beyond a block apart: 0.05 / sqrt(d) along the offset scaled by 1 / sqrt(d)
		MobPushing::computePush(-2.0, 0.0, 1, 2, $x, $z);
		self::assertEqualsWithDelta(-0.05, $x, 1e-12);
		self::assertEqualsWithDelta(0.0, $z, 1e-12);
		//stacked: the push of the smallest offset, opposite for the other order of the pair
		MobPushing::computePush(0.0, 0.0, 5, 9, $x1, $z1);
		MobPushing::computePush(0.0, 0.0, 9, 5, $x2, $z2);
		self::assertEqualsWithDelta(sqrt(0.01) * 0.05, sqrt($x1 * $x1 + $z1 * $z1), 1e-12);
		self::assertEqualsWithDelta(-$x1, $x2, 1e-12);
		self::assertEqualsWithDelta(-$z1, $z2, 1e-12);
	}

	public function testGridCellsAreFloorOfHalf() : void{
		foreach([0.0, 0.5, 1.999999, 2.0, 2.0000001, -0.0000001, -0.5, -2.0, -2.0000001, -3.7, 12345.6, -12345.6, 30000000.0, -30000000.0] as $v){
			self::assertSame((int) floor($v / 2), MobPushing::cellCoordinate($v), "cell of $v");
		}
	}

	public function testMobsSpawnedOnTheSameSpotSeparate() : void{
		$a = $this->still(new Zombie($this->loc(0.5, 0.5)));
		$b = $this->still(new Zombie($this->loc(0.5, 0.5)));
		$this->runTicks(20);
		self::assertFalse($a->getBoundingBox()->intersectsWith($b->getBoundingBox()), "the boxes don't overlap anymore");
		self::assertLessThan(3.0, self::horizontalDistance($a, $b), "pushed apart, not flung away");
		//apart, they stay where they are
		$la = $a->getLocation();
		$this->runTicks(20);
		self::assertEqualsWithDelta(0.0, $la->distance($a->getLocation()), 0.05);
	}

	public function testWalkingMobPushesStandingMob() : void{
		$walker = $this->still(new Zombie($this->loc(0.5, 0.5)));
		$cow = $this->still(new Cow($this->loc(3.5, 0.6)));
		$walker->getMoveControl()->setWantedPosition(12.5, 1, 0.5, 1.0);
		$this->runTicks(80);
		//the zombie, a little off the cow's centre line, pushes the cow forwards and aside, and squeezes past it
		$cowLoc = $cow->getLocation();
		self::assertGreaterThan(3.9, $cowLoc->x, "the cow was pushed along by the zombie walking into it");
		self::assertGreaterThan(0.75, $cowLoc->z, "and aside, away from the zombie's side");
		self::assertGreaterThan(7.0, $walker->getLocation()->x, "the zombie kept walking");
		self::assertFalse($walker->getBoundingBox()->intersectsWith($cow->getBoundingBox()));
	}

	public function testPlayersPushMobsButAreNotPushed() : void{
		$cow = $this->still(new Cow($this->loc(0.5, 0.5)));
		$player = $this->fakePlayer(0.9, 0.5);
		$this->runTicks(15);
		self::assertSame(0.9, $player->getLocation()->x, "the server never moves the player");
		self::assertSame(0.5, $player->getLocation()->z);
		self::assertLessThan(0.5, $cow->getLocation()->x, "the cow was pushed away from the player");
		self::assertFalse($cow->getBoundingBox()->intersectsWith($player->getBoundingBox()), "out of the player");
	}

	public function testPlayerWalkingIntoMobAtRestPushesIt() : void{
		//a cow that has stood still for a while (nothing to update in the push grid) still feels a player walking into it
		$cow = $this->still(new Cow($this->loc(0.5, 0.5)));
		$player = $this->fakePlayer(3.5, 0.5);
		$this->runTicks(30);
		self::assertSame(0.5, $cow->getLocation()->x);
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, $this->loc(0.9, 0.5));
		$player->boundingBox = new AxisAlignedBB(0.6, 1, 0.2, 1.2, 2.8, 0.8);
		$this->runTicks(15);
		self::assertLessThan(0.2, $cow->getLocation()->x, "pushed away from the player");
	}

	public function testUnpushableMobsStillPushOthers() : void{
		$shulker = new Shulker($this->loc(0.5, 0.5));
		$zombie = $this->still(new Zombie($this->loc(0.9, 0.5)));
		$this->runTicks(15);
		self::assertSame(0.5, $shulker->getLocation()->x, "shulkers are not pushed");
		self::assertGreaterThan(1.2, $zombie->getLocation()->x, "the zombie was pushed off the shulker");

		$player = $this->fakePlayer(5.5, 5.5);
		$cube = new SulfurCube($this->loc(8.5, 8.5), CompoundTag::create()->setInt(Animal::TAG_AGE, 0));
		self::assertTrue($cube->canBePushedBy($player), "without a block it is pushed like any mob");
		self::assertTrue($cube->absorbBlock(VanillaBlocks::STONE()->asItem()));
		self::assertFalse($cube->canBePushedBy($player), "holding a block, players kick it like a ball instead");
		self::assertTrue($cube->canBePushedBy($zombie), "other mobs still push it");
	}

	public function testConditionallyPushableMobs() : void{
		$zombie = new Zombie($this->loc(20.5, 20.5));
		//pushable_by_entity only in some component groups of the behavior pack
		$camel = new Camel($this->loc(5.5, 5.5));
		self::assertTrue($camel->canBePushedBy($zombie), "a standing camel");
		$camel->setSitting(true);
		self::assertFalse($camel->canBePushedBy($zombie), "a sitting camel (camel_sitting has no pushable_by_entity)");
		$husk = new CamelHusk($this->loc(10.5, 5.5));
		$husk->setSitting(true);
		self::assertFalse($husk->canBePushedBy($zombie), "a sitting camel husk");
		$sniffer = new Sniffer($this->loc(5.5, 12.5));
		self::assertTrue($sniffer->canBePushedBy($zombie));
		$sniffer->startDigging();
		self::assertFalse($sniffer->canBePushedBy($zombie), "a digging sniffer (on_digging_start removes the pushable group)");
		$ghast = new HappyGhast($this->loc(12.5, 12.5, 4.0), CompoundTag::create()->setInt(Animal::TAG_AGE, 0));
		self::assertTrue($ghast->canBePushedBy($zombie));
		$ghast->setImmobile(true);
		self::assertFalse($ghast->canBePushedBy($zombie), "an immobile happy ghast (adult_immobile has no pushable_by_entity)");
	}

	public function testSlimesArePushedBetweenHops() : void{
		//slimes stick to the ground between hops, but the push still moves them
		$a = $this->still(new Slime($this->loc(0.5, 0.5), null, 2));
		$b = $this->still(new Slime($this->loc(0.7, 0.5), null, 2));
		$this->runTicks(60);
		self::assertFalse($a->getBoundingBox()->intersectsWith($b->getBoundingBox()), "overlapping slimes separate without hopping");

		//as fast as other mobs: the push fades with ground friction instead of stopping on the next tick
		$slime = $this->still(new Slime($this->loc(-10.5, -10.5), null, 2));
		$cow = $this->still(new Cow($this->loc(-10.5, 10.5)));
		$this->runTicks(10);
		$slime->queueEntityPush(0.1, 0.0);
		$cow->queueEntityPush(0.1, 0.0);
		$this->runTicks(10);
		$slimeMoved = $slime->getLocation()->x + 10.5;
		$cowMoved = $cow->getLocation()->x + 10.5;
		self::assertGreaterThan(0.2, $cowMoved);
		self::assertGreaterThan(0.85 * $cowMoved, $slimeMoved, "a push moves a resting slime about as far as a cow");
		self::assertLessThan(1.15 * $cowMoved, $slimeMoved);

		//a small slime (no contact damage), 0.52 wide
		$resting = $this->still(new Slime($this->loc(10.5, 10.5), null, 1));
		$this->runTicks(10);
		$player = $this->fakePlayer(10.8, 10.5);
		$this->runTicks(20);
		self::assertSame(10.8, $player->getLocation()->x);
		self::assertLessThan(10.3, $resting->getLocation()->x, "a player pushes a resting slime");
		self::assertFalse($resting->getBoundingBox()->intersectsWith($player->getBoundingBox()));
	}

	public function testFrozenMobsNeitherPushNorArePushed() : void{
		//only chunk 0,0 is simulated: the zombie in chunk -1,0 is frozen
		$this->world->setSimulationDistance(4);
		$ticker = new ChunkTicker();
		$this->world->registerSimulatingChunk($ticker, 0, 0);
		$frozen = $this->still(new Zombie($this->loc(-0.4, 0.5)));
		$cow = $this->still(new Cow($this->loc(4.5, 0.5)));
		$this->runTicks(5);
		$frozenX = $frozen->getLocation()->x;

		//a cow overlapping the frozen zombie is not pushed off it, nor does it push the zombie
		$cow->teleport($this->loc(0.2, 0.5));
		$this->runTicks(200);
		self::assertSame($frozenX, $frozen->getLocation()->x, "the frozen zombie wasn't pushed");
		self::assertEqualsWithDelta(0.2, $cow->getLocation()->x, 1e-6, "the frozen zombie doesn't push");
		self::assertFalse($frozen->isPushingUpdated($this->tick));

		//simulated again, it is pushed normally, without the pushes of the frozen ticks piled up
		$this->world->registerSimulatingChunk($ticker, -1, 0);
		$maxStep = 0.0;
		$last = $frozen->getLocation();
		for($i = 0; $i < 30; ++$i){
			$this->runTicks(1);
			$maxStep = max($maxStep, $last->distance($frozen->getLocation()));
			$last = $frozen->getLocation();
		}
		self::assertLessThan(0.3, $maxStep, "no jump when it unfreezes");
		self::assertFalse($frozen->getBoundingBox()->intersectsWith($cow->getBoundingBox()), "pushed apart once simulated");
		$this->world->unregisterSimulatingChunk($ticker, 0, 0);
		$this->world->unregisterSimulatingChunk($ticker, -1, 0);
	}

	public function testJostledMobsAreSentLessOften() : void{
		$cow = $this->still(new CrowdPushBroadcastCow($this->loc(0.5, 0.5)));
		$this->runTicks(10);
		$cow->broadcasts = 0;
		//pushed back and forth for 40 ticks, like a mob in a packed pen
		for($i = 0; $i < 40; ++$i){
			$cow->queueEntityPush($i % 2 === 0 ? 0.09 : -0.09, 0.0);
			$this->runTicks(1);
		}
		self::assertLessThan(10, $cow->broadcasts, "a jostled mob isn't sent on every step");
		self::assertNotEquals(0.5, $cow->getLocation()->x, "it moved");
		$this->runTicks(20);
		self::assertTrue($cow->getSentPosition()->equals($cow->getLocation()), "the viewers get its final position");

		//pushed far enough, it is sent on the way
		$cow->broadcasts = 0;
		for($i = 0; $i < 20; ++$i){
			$cow->queueEntityPush(0.09, 0.0);
			$this->runTicks(1);
		}
		self::assertGreaterThan(3, $cow->broadcasts);
		$this->runTicks(20);
		self::assertTrue($cow->getSentPosition()->equals($cow->getLocation()));
	}

	public function testContactHookSeesOverlappingEntities() : void{
		$watcher = $this->still(new CrowdPushContactCow($this->loc(1.0, 0.5)));
		$shulker = new Shulker($this->loc(1.5, 0.5)); //x 1..2, the cow's box reaches 1.45
		$player = $this->fakePlayer(1.0, 1.2);
		$this->runTicks(3);
		self::assertContains($shulker->getId(), $watcher->contacts, "touched by a mob that can't be pushed");
		self::assertContains($player->getId(), $watcher->contacts, "touched by a player");
		$far = new Zombie($this->loc(6.5, 6.5));
		$this->runTicks(3);
		self::assertNotContains($far->getId(), $watcher->contacts);
	}

	public function testVillagersInOneBlockCellDontJitter() : void{
		//a 1x1 cell, two blocks high, like the cells of a trading hall
		$stone = VanillaBlocks::STONE();
		foreach([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dz]){
			for($y = 1; $y <= 2; ++$y){
				$this->world->setBlockAt(5 + $dx, $y, 5 + $dz, $stone, false);
			}
		}
		$a = $this->still(new VillagerV2($this->loc(5.5, 5.5)));
		$b = $this->still(new VillagerV2($this->loc(5.5, 5.5)));
		$this->runTicks(40);
		$positions = [$a->getLocation(), $b->getLocation()];
		$maxMove = 0.0;
		for($i = 0; $i < 20; ++$i){
			$this->runTicks(1);
			foreach([$a, $b] as $k => $villager){
				$maxMove = max($maxMove, $villager->getLocation()->distance($positions[$k]));
				$positions[$k] = $villager->getLocation();
				$box = $villager->getBoundingBox();
				self::assertTrue($box->minX >= 5 - 1e-6 && $box->maxX <= 6 + 1e-6 && $box->minZ >= 5 - 1e-6 && $box->maxZ <= 6 + 1e-6, "inside the cell");
			}
		}
		self::assertLessThan(1e-6, $maxMove, "pressed against the walls, they stay put");
	}

	public function testChasingZombiesDontStack() : void{
		$cow = $this->still(new Cow($this->loc(0.5, 0.5)));
		$cow->setMaxHealth(100000);
		$cow->setHealth(100000);
		/** @var Zombie[] $zombies */
		$zombies = [];
		for($i = 0; $i < 6; ++$i){
			$zombie = new Zombie($this->loc(8.5, 0.5));
			$zombie->setAttackTarget($cow);
			$zombies[] = $zombie;
		}
		$this->runTicks(160);
		$closest = 100.0;
		for($i = 0; $i < count($zombies); ++$i){
			for($j = $i + 1; $j < count($zombies); ++$j){
				$closest = min($closest, self::horizontalDistance($zombies[$i], $zombies[$j]));
			}
		}
		self::assertGreaterThan(0.25, $closest, "the zombies crowd around their target instead of merging into one");
	}

	public function testFairBudgetServesEveryoneInTurn() : void{
		$budget = new FairTickBudget(10);
		$waitingSince = [];
		$servedOn = [];
		for($i = 0; $i < 50; ++$i){
			$waitingSince[$i] = -1;
			$servedOn[$i] = -1;
		}
		for($tick = 1; $tick <= 5; ++$tick){
			$taken = 0;
			for($i = 0; $i < 50; ++$i){
				//every one asks on every tick, in the same order
				if($budget->tryTake($tick, $waitingSince[$i])){
					++$taken;
					$waitingSince[$i] = -1;
					if($servedOn[$i] < 0){
						$servedOn[$i] = $tick;
					}
				}elseif($waitingSince[$i] < 0){
					$waitingSince[$i] = $tick;
				}
			}
			self::assertSame(10, $taken, "the whole budget is used, and no more (tick $tick)");
		}
		foreach($servedOn as $i => $tick){
			self::assertGreaterThan(0, $tick, "requester $i got its turn within 5 ticks");
		}
		self::assertSame([1], array_unique(array_slice($servedOn, 0, 10)));
		self::assertSame([5], array_unique(array_slice($servedOn, 40, 10)));

		//without contention nothing is held back
		$budget = new FairTickBudget(10);
		for($tick = 1; $tick <= 3; ++$tick){
			for($i = 0; $i < 10; ++$i){
				self::assertTrue($budget->tryTake($tick));
			}
		}
	}

	public function testPathSearchBudgetReachesEveryMob() : void{
		AiBudget::configure(true, 100000, 10, 400, requireNearbyPlayer: false);
		/**
		 * @var Cow[] $cows
		 * @phpstan-var list<Cow> $cows
		 */
		$cows = [];
		for($i = 0; $i < 50; ++$i){
			$cows[] = $this->still(new Cow($this->loc(-20.5 + ($i % 10) * 2, -10.5 + (int) ($i / 10) * 2)));
		}
		$this->runTicks(1);
		for($tick = 0; $tick < 6; ++$tick){
			//every cow asks for a new path on every tick (like mobs chasing a moving target): first come, first served,
			//the first 10 cows in the world's update order would get all the searches
			foreach($cows as $cow){
				$cow->getNavigation()->moveTo(18.5, 1, 14.5 + $tick % 2, 1.0);
			}
			$this->runTicks(1);
		}
		foreach($cows as $i => $cow){
			self::assertNotNull($cow->getNavigation()->getPath(), "cow $i got a path search");
		}
	}

	public function testActivationRangeFollowsSimulationDistance() : void{
		self::assertSame(Mob::ACTIVATION_RANGE, Mob::getActivationRange($this->world), "no limit (0): 64 as before");
		$this->world->setSimulationDistance(4);
		self::assertSame(64, Mob::getActivationRange($this->world), "default simulation distance: unchanged");
		$this->world->setSimulationDistance(2);
		self::assertSame(64, Mob::getActivationRange($this->world), "never less than 64");
		$this->world->setSimulationDistance(8);
		self::assertSame(128, Mob::getActivationRange($this->world));

		AiBudget::configure(true, 100000, 100000, 400, requireNearbyPlayer: true);
		$zombie = new Zombie($this->loc(0.5, 0.5));
		$this->fakePlayer(100.5, 0.5);
		$activation = new \ReflectionMethod(Mob::class, "updateActivation");
		self::assertTrue($activation->invoke($zombie), "100 blocks away, within a simulation distance of 8 chunks");
		$this->world->setSimulationDistance(4);
		self::assertFalse($activation->invoke($zombie), "beyond 64 blocks with the default simulation distance");
		$zombie->close();
	}

	public function testGridReleasesItsMemoryWhenTheLastMobLeaves() : void{
		$mobs = [];
		for($i = 0; $i < 40; ++$i){
			$mobs[] = $this->still(new Zombie($this->loc(0.5 + ($i % 4) * 0.3, 0.5 + intdiv($i, 4) * 0.3)));
		}
		$this->runTicks(5);
		$grid = MobPushing::get($this->world);
		$property = static fn(string $name) : mixed => (new \ReflectionProperty(MobPushing::class, $name))->getValue($grid);
		$boxes = $property("boxes");
		self::assertIsArray($boxes);
		self::assertNotEmpty($boxes);
		foreach($mobs as $mob){
			$mob->close();
		}
		foreach(["cells", "boxes", "cellRanges", "plainBoxes", "dirtySince"] as $name){
			self::assertSame([], $property($name), $name);
		}
		//the closed mobs are out of the dirty list; the last one was not flagged closed yet when it left
		self::assertLessThanOrEqual(1, $property("dirtyCount"));
		$dirty = $property("dirty");
		self::assertIsArray($dirty);
		self::assertLessThanOrEqual(1, count($dirty));

		//and it works as before afterwards
		$a = $this->still(new Zombie($this->loc(0.5, 0.5)));
		$b = $this->still(new Zombie($this->loc(0.5, 0.5)));
		$this->runTicks(20);
		self::assertSame($grid, MobPushing::get($this->world));
		self::assertFalse($a->getBoundingBox()->intersectsWith($b->getBoundingBox()));
	}
}

/**
 * A cow recording the entities it touches (see Mob::onEntityContact()).
 */
final class CrowdPushContactCow extends Cow implements NeverSavedWithChunkEntity{
	/**
	 * @var int[]
	 * @phpstan-var list<int>
	 */
	public array $contacts = [];

	protected function onEntityContact(Entity $entity) : void{
		$this->contacts[] = $entity->getId();
	}
}

/**
 * A cow counting its movement updates to viewers, and the position last sent.
 */
final class CrowdPushBroadcastCow extends Cow implements NeverSavedWithChunkEntity{
	public int $broadcasts = 0;

	protected function broadcastMovement(bool $teleport = false) : void{
		++$this->broadcasts;
		parent::broadcastMovement($teleport);
	}

	public function getSentPosition() : Location{
		return $this->lastLocation;
	}
}
