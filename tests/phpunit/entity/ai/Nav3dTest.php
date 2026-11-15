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
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\control\SwimmingMoveControl;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\navigation\Path3DFinder;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\ai\navigation\StraightLineNavigator;
use pocketmine\entity\animal\Allay;
use pocketmine\entity\animal\Bat;
use pocketmine\entity\animal\Bee;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\HappyGhast;
use pocketmine\entity\aquatic\Cod;
use pocketmine\entity\aquatic\Dolphin;
use pocketmine\entity\aquatic\Nautilus;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\PlayerInventory;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\Position;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use function abs;
use function count;
use function max;
use function min;
use function mt_srand;

/**
 * 3D navigation of flyers and swimmers (paths around walls, budget and bounds), swimmers that don't walk or go
 * ashore, bat float_wander, allay owner rules.
 */
final class Nav3dTest extends MobTestCase{

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261022);
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	private function fill(int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ, Block $block) : void{
		for($x = $minX; $x <= $maxX; ++$x){
			for($y = $minY; $y <= $maxY; ++$y){
				for($z = $minZ; $z <= $maxZ; ++$z){
					$this->world->setBlockAt($x, $y, $z, $block, false);
				}
			}
		}
		PathBlockTypes::clearCache();
	}

	/**
	 * A water tank x 0..12, y 1..6, z -6..6 with a stone wall at x 6 and a single opening at (6, 4, 4).
	 */
	private function tankWithWall() : void{
		$this->fill(-1, 1, -7, 13, 7, 7, VanillaBlocks::STONE());
		$this->fill(0, 1, -6, 12, 6, 6, VanillaBlocks::WATER());
		$this->fill(6, 1, -6, 6, 6, 6, VanillaBlocks::STONE());
		$this->world->setBlockAt(6, 4, 4, VanillaBlocks::WATER(), false);
		PathBlockTypes::clearCache();
	}

	private function finder(int $medium, int $maxNodes = 400) : Path3DFinder{
		return new Path3DFinder($this->world, $medium, 0, 1, $maxNodes);
	}

	public function testFinderFindsTheOpeningInAWall() : void{
		$this->tankWithWall();
		$finder = $this->finder(Path3DFinder::MEDIUM_WATER);
		self::assertFalse($finder->isClear(2.5, 2.2, 0.5, 10.5, 2.2, 0.5, 0.15, 0.3), "the wall blocks the straight way");
		$path = $finder->findPath(2, 2, 0, 10, 2, 0);
		self::assertNotNull($path);
		self::assertTrue($path->reachesTarget());
		$through = false;
		foreach($path->getNodes() as [$x, $y, $z]){
			self::assertNotSame(PathBlockTypes::at($this->world, $x, $y, $z) & PathBlockTypes::WATER, 0, "every node is in water");
			if($x === 6){
				self::assertSame([6, 4, 4], [$x, $y, $z]);
				$through = true;
			}
		}
		self::assertTrue($through, "the path goes through the opening");
		self::assertLessThanOrEqual(400, $finder->getLastVisitedCount());
	}

	public function testFinderIsBoundedAndReturnsAPartialPath() : void{
		$this->tankWithWall();
		$this->world->setBlockAt(6, 4, 4, VanillaBlocks::STONE(), false);
		PathBlockTypes::clearCache();
		$finder = $this->finder(Path3DFinder::MEDIUM_WATER, 200);
		$path = $finder->findPath(2, 2, 0, 10, 2, 0);
		self::assertNotNull($path);
		self::assertFalse($path->reachesTarget(), "no way through the closed wall");
		self::assertLessThanOrEqual(200, $finder->getLastVisitedCount());
		$end = $path->getEnd();
		self::assertNotNull($end);
		self::assertSame(5, $end[0], "as close as it gets: against the wall");
		self::assertNull($finder->findPath(0, 30, 0, 100, 30, 0), "targets beyond the range aren't searched for");
	}

	public function testFinderResolvesTargetsOutOfTheMedium() : void{
		$this->poolAndBeach();
		$finder = $this->finder(Path3DFinder::MEDIUM_WATER);
		self::assertSame([3, 2, 0], $finder->resolveTarget(3, 2, 0, 1, 1, 0), "in water: the target itself");
		self::assertSame([3, 3, 0], $finder->resolveTarget(3, 6, 0, 1, 1, 0), "above the surface (coming up to breathe): the water below");
		self::assertSame([6, 2, 0], $finder->resolveTarget(7, 2, 0, 1, 2, 0), "in the sand at the edge: the water next to it");
		self::assertNull($finder->resolveTarget(10, 3, 0, 1, 2, 0), "on the beach: none, no search");
	}

	public function testFlyersAvoidWaterAndFenceTops() : void{
		$finder = $this->finder(Path3DFinder::MEDIUM_AIR);
		$this->world->setBlockAt(3, 1, 3, VanillaBlocks::WATER(), false);
		$this->world->setBlockAt(4, 1, 4, VanillaBlocks::OAK_FENCE(), false);
		PathBlockTypes::clearCache();
		self::assertTrue($finder->canBeAt(2, 1, 2));
		self::assertFalse($finder->canBeAt(3, 1, 3), "no flying into water");
		self::assertFalse($finder->canBeAt(4, 2, 4), "not on top of a fence");
		self::assertTrue($finder->canBeAt(4, 3, 4));
	}

	public function testFishSwimsThroughTheOpening() : void{
		$this->tankWithWall();
		$cod = new Cod($this->loc(2.5, 2.2, 0.5));
		$cod->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		$target = new Vector3(10.5, 2.2, 0.5);
		$reached = false;
		for($i = 0; $i < 600 && !$reached; ++$i){
			if($i % 40 === 0){
				$cod->getNavigation()->moveTo($target->x, $target->y, $target->z, 1.0);
			}
			$this->runTicks(1);
			$reached = $cod->getPosition()->distance($target) < 1.5;
		}
		self::assertTrue($reached, "the cod should get past the wall, at " . $cod->getPosition());
		$cod->flagForDespawn();
	}

	/**
	 * A water tank x 0..12, y 1..6, z -6..$maxZ with a stone wall at x 6 and a single opening at (6, 2, $openingZ).
	 */
	private function tankWithOpening(int $openingZ, int $maxZ) : void{
		$this->fill(-1, 1, -7, 13, 7, $maxZ + 1, VanillaBlocks::STONE());
		$this->fill(0, 1, -6, 12, 6, $maxZ, VanillaBlocks::WATER());
		$this->fill(6, 1, -6, 6, 6, $maxZ, VanillaBlocks::STONE());
		$this->world->setBlockAt(6, 2, $openingZ, VanillaBlocks::WATER(), false);
		PathBlockTypes::clearCache();
	}

	public function testOneMoveToFollowsADetourLeadingAwayFromTheTarget() : void{
		//the way through the opening leads away from the target for longer than the stuck check: progress is measured
		//along the path, the navigation doesn't give up halfway
		$this->tankWithOpening(6, 8);
		$cod = new Cod($this->loc(4.5, 2.2, 0.5));
		$cod->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		$navigation = $cod->getNavigation();
		$target = new Vector3(8.5, 2.2, 0.5);
		self::assertTrue($navigation->moveTo($target->x, $target->y, $target->z, 1.0));
		$reached = false;
		$i = 0;
		for(; $i < 600 && !$reached && !$navigation->isDone(); ++$i){
			$this->runTicks(1);
			$reached = $cod->getPosition()->distance($target) < 1.5;
		}
		self::assertGreaterThan(80, $i, "the detour takes longer than two stuck checks");
		self::assertTrue($reached, "the cod should get through the opening with a single moveTo(), at " . $cod->getPosition() . " after $i ticks");
		$cod->flagForDespawn();
	}

	public function testDetourLengthWithinTheNodeCap() : void{
		//pins the limit of the capped search (see Path3DFinder): an opening 6 blocks to the side is found, 10 isn't
		$this->tankWithOpening(6, 12);
		$finder = $this->finder(Path3DFinder::MEDIUM_WATER);
		$path = $finder->findPath(4, 2, 0, 8, 2, 0);
		self::assertNotNull($path);
		self::assertTrue($path->reachesTarget());

		$this->world->setBlockAt(6, 2, 6, VanillaBlocks::STONE(), false);
		$this->world->setBlockAt(6, 2, 10, VanillaBlocks::WATER(), false);
		PathBlockTypes::clearCache();
		$path = $finder->findPath(4, 2, 0, 8, 2, 0);
		self::assertTrue($path === null || !$path->reachesTarget(), "10 blocks to the side is beyond the node cap");
		self::assertSame(400, $finder->getLastVisitedCount());
	}

	/**
	 * @return int[] the navigator's failStreak and the tick until which its last failure counts
	 * @phpstan-return array{int, int}
	 */
	private function failState(StraightLineNavigator $navigation) : array{
		$streak = new \ReflectionProperty(StraightLineNavigator::class, "failStreak");
		$regions = new \ReflectionProperty(StraightLineNavigator::class, "failedRegions");
		/** @var int $s */
		$s = $streak->getValue($navigation);
		/** @var int[] $r */
		$r = $regions->getValue($navigation);
		return [$s, count($r) === 0 ? -1 : max($r)];
	}

	public function testFailedSearchesBackOffAndAreShared() : void{
		$this->tankWithOpening(6, 8);
		$this->world->setBlockAt(6, 2, 6, VanillaBlocks::STONE(), false);
		PathBlockTypes::clearCache();
		$target = new Vector3(8.5, 2.2, 0.5);

		$cod = new Cod($this->loc(4.5, 2.2, 0.5));
		$cod->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		$navigation = $cod->getNavigation();
		self::assertInstanceOf(StraightLineNavigator::class, $navigation);
		$waits = [];
		for($i = 0; $i < 1200 && count($waits) < 3; ++$i){
			if($navigation->isDone()){
				$navigation->moveTo($target->x, $target->y, $target->z, 1.0);
			}
			$this->runTicks(1);
			[$streak, $until] = $this->failState($navigation);
			if($streak > count($waits)){
				$waits[] = $until - $cod->getAiTick();
			}
		}
		self::assertCount(3, $waits);
		self::assertEqualsWithDelta(StraightLineNavigator::FAILED_RETRY_TICKS, $waits[0], 1, "first failure");
		self::assertEqualsWithDelta(StraightLineNavigator::FAILED_RETRY_TICKS * 4, $waits[1], 1, "second failure in a row");
		self::assertEqualsWithDelta(StraightLineNavigator::FAILED_RETRY_TICKS * 16, $waits[2], 1, "third failure in a row");

		//a second cod starting next to it doesn't search again: the failure is shared
		$other = new Cod($this->loc(4.5, 2.2, 1.5));
		$other->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		$otherNavigation = $other->getNavigation();
		self::assertInstanceOf(StraightLineNavigator::class, $otherNavigation);
		self::assertTrue($otherNavigation->moveTo($target->x, $target->y, $target->z, 1.0));
		$this->runTicks(2);
		self::assertTrue($otherNavigation->isDone(), "the shared failure stops it");
		self::assertSame(1, $this->failState($otherNavigation)[0]);
		$finder = (new \ReflectionProperty(StraightLineNavigator::class, "finder3d"))->getValue($otherNavigation);
		self::assertInstanceOf(Path3DFinder::class, $finder);
		self::assertSame(0, $finder->getLastVisitedCount(), "no search ran");
		$cod->flagForDespawn();
		$other->flagForDespawn();
	}

	public function testRefundedBudgetKeepsTheRequestsPlace() : void{
		$budget = new FairTickBudget(1);
		self::assertTrue($budget->tryTake(10));
		$budget->refund(10);
		//the unit is back for this tick
		self::assertTrue($budget->tryTake(10));
		self::assertFalse($budget->tryTake(10));
		//next tick: the refunded request (waiting since 10) is served before a new one
		self::assertFalse($budget->tryTake(11), "reserved for the requests waiting since tick 10");
		self::assertTrue($budget->tryTake(11, 10));
	}

	public function testBeeReachesAFlowerBehindALeafWall() : void{
		//a wall of leaves 9 blocks high between the bee and the flower: over it or around it
		$this->fill(4, 1, -8, 4, 9, 8, VanillaBlocks::OAK_LEAVES());
		$this->world->setBlockAt(8, 1, 0, VanillaBlocks::POPPY(), false);
		PathBlockTypes::clearCache();
		$bee = new Bee($this->loc(1.5, 2, 0.5));
		$bee->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		$target = new Vector3(8.5, 1.2, 0.5);
		$reached = false;
		for($i = 0; $i < 600 && !$reached; ++$i){
			if($i % 20 === 0){
				$bee->getNavigation()->moveTo($target->x, $target->y, $target->z, 1.0);
			}
			$this->runTicks(1);
			$reached = $bee->getPosition()->distance($target) < 1.2;
		}
		self::assertTrue($reached, "the bee should get to the flower, at " . $bee->getPosition());
		$bee->flagForDespawn();
	}

	public function testAllayFliesAroundACorner() : void{
		//a wall too high to fly over: the way to the cow is round its end, 5 blocks to the side
		$this->fill(3, 1, -10, 3, 14, 3, VanillaBlocks::STONE());
		$cow = new Cow($this->loc(6.5, 1, -1.5));
		$cow->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK, true);
		$allay = new Allay($this->loc(0.5, 2, 0.5));
		$allay->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		$navigation = $allay->getNavigation();
		self::assertInstanceOf(StraightLineNavigator::class, $navigation);
		self::assertSame(StraightLineNavigator::MEDIUM_AIR, $navigation->getMedium());
		$reached = false;
		for($i = 0; $i < 600 && !$reached; ++$i){
			if($i % 10 === 0){
				$navigation->moveToEntity($cow, 1.0, 2.0);
			}
			$this->runTicks(1);
			$reached = $allay->getPosition()->distance($cow->getPosition()) < 3.5;
		}
		self::assertTrue($reached, "the allay should get round the wall, at " . $allay->getPosition());
		$allay->flagForDespawn();
		$cow->flagForDespawn();
	}

	public function testHappyGhastsAndBatsDontSearch() : void{
		$ghast = new HappyGhast($this->loc(0.5, 10, 0.5));
		$navigation = $ghast->getNavigation();
		self::assertInstanceOf(StraightLineNavigator::class, $navigation);
		self::assertSame(StraightLineNavigator::MEDIUM_NONE, $navigation->getMedium());
		$bat = new Bat($this->loc(0.5, 5, 0.5));
		$navigation = $bat->getNavigation();
		self::assertInstanceOf(StraightLineNavigator::class, $navigation);
		self::assertSame(StraightLineNavigator::MEDIUM_NONE, $navigation->getMedium());
		$ghast->flagForDespawn();
		$bat->flagForDespawn();
	}

	/**
	 * A pool x 0..6, y 1..2, z -4..4 next to a sand beach at x 7..12.
	 */
	private function poolAndBeach() : void{
		$this->fill(-1, 1, -5, 7, 3, 5, VanillaBlocks::STONE());
		$this->fill(0, 1, -4, 6, 3, 4, VanillaBlocks::WATER());
		$this->fill(7, 1, -5, 12, 2, 5, VanillaBlocks::SAND());
	}

	public function testDolphinDoesNotLeaveTheWaterToChaseAPlayerOnTheBeach() : void{
		$this->poolAndBeach();
		$dolphin = new Dolphin($this->loc(3.5, 2, 0.5));
		$dolphin->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		$dolphin->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		$control = $dolphin->getMoveControl();
		self::assertInstanceOf(SwimmingMoveControl::class, $control);
		self::assertFalse($control->leavesWater());
		self::assertTrue($control->canWalk(), "dolphin_on_land can walk (move_to_water)");
		$beach = new Vector3(9.5, 3, 0.5);
		for($i = 0; $i < 200; ++$i){
			if($i % 10 === 0){
				$dolphin->getNavigation()->moveTo($beach->x, $beach->y, $beach->z, 1.2);
			}
			if($i >= 100){
				//up close, melee attacks steer straight at the target
				$control->setWantedPosition($beach->x, $beach->y, $beach->z, 1.2);
			}
			$this->runTicks(1);
			self::assertLessThan(7.0, $dolphin->getPosition()->x, "the dolphin stays in the pool (tick $i)");
		}
		self::assertGreaterThan(4.5, $dolphin->getPosition()->x, "it went to the edge of the water");
		$dolphin->flagForDespawn();
	}

	public function testNautilusDoesNotWalkOnLand() : void{
		$nautilus = new Nautilus($this->loc(0.5, 1, 0.5));
		$nautilus->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		$control = $nautilus->getMoveControl();
		self::assertInstanceOf(SwimmingMoveControl::class, $control);
		self::assertFalse($control->canWalk());
		$this->runTicks(10);
		$start = $nautilus->getPosition();
		for($i = 0; $i < 60; ++$i){
			$control->setWantedPosition(8.5, 1, 0.5, 2.0);
			$this->runTicks(1);
		}
		self::assertLessThan(0.3, abs($nautilus->getPosition()->x - $start->x), "it doesn't walk towards a target out of water");
		$nautilus->flagForDespawn();
	}

	public function testBatFloatWanderTargets() : void{
		$bat = new Bat($this->loc(0.5, 12, 0.5));
		for($i = 0; $i < 300; ++$i){
			$target = $bat->findFloatWanderTarget();
			if($target === null){
				continue;
			}
			self::assertLessThanOrEqual(Bat::FLOAT_XZ_DIST, abs($target->x - 0.5));
			self::assertLessThanOrEqual(Bat::FLOAT_XZ_DIST, abs($target->z - 0.5));
			self::assertGreaterThanOrEqual(12 - Bat::FLOAT_Y_DIST + Bat::FLOAT_Y_OFFSET, $target->y);
			self::assertLessThanOrEqual(12 + Bat::FLOAT_Y_DIST + Bat::FLOAT_Y_OFFSET, $target->y);
		}

		//a new target every 2-7 ticks (random_reselect, float_duration 0.1-0.35 s)
		$control = $bat->getMoveControl();
		$lastWanted = null;
		$lastChange = -1;
		$intervals = [];
		for($tick = 0; $tick < 200; ++$tick){
			$this->runTicks(1);
			if($bat->isResting()){
				continue;
			}
			$wanted = [$control->getWantedX(), $control->getWantedY(), $control->getWantedZ()];
			if($wanted !== $lastWanted){
				if($lastChange >= 0){
					$intervals[] = $tick - $lastChange;
				}
				$lastChange = $tick;
				$lastWanted = $wanted;
			}
		}
		self::assertGreaterThan(20, count($intervals));
		foreach($intervals as $interval){
			self::assertLessThanOrEqual(Bat::FLOAT_DURATION_MAX + 1, $interval);
		}
		$bat->flagForDespawn();
	}

	/**
	 * A player standing at the given position, connected (allays look their liked player up).
	 */
	private function player(Vector3 $pos, UuidInterface $uuid, Item $held, bool $sneaking = false, int $id = 0) : Player{
		$player = $this->createMock(Player::class);
		$inventory = new PlayerInventory($player);
		$inventory->setItemInHand($held);
		$location = Location::fromObject($pos, $this->world);
		$player->method("getInventory")->willReturn($inventory);
		$player->method("hasFiniteResources")->willReturn(true);
		$player->method("getUniqueId")->willReturn($uuid);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("getPosition")->willReturn(Position::fromObject($pos, $this->world));
		$player->method("getLocation")->willReturn($location);
		$player->method("isSneaking")->willReturn($sneaking);
		$player->method("isConnected")->willReturn(true);
		$player->method("isAlive")->willReturn(true);
		$player->method("isSpectator")->willReturn(false);
		$player->method("getName")->willReturn("Tester");
		$player->method("getSize")->willReturn(new EntitySizeInfo(1.8, 0.6, 1.62));
		$player->method("getId")->willReturn($id);
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	public function testAllayInteractionNeedsNoSneakingAndNoLead() : void{
		$allay = new Allay($this->loc(0.5, 2, 0.5));
		$uuid = Uuid::uuid4();
		$allay->onInteract($this->player(new Vector3(1.5, 1, 0.5), $uuid, VanillaItems::DIAMOND(), sneaking: true), Vector3::zero());
		self::assertNull($allay->getHeldItem(), "not while sneaking");
		$allay->onInteract($this->player(new Vector3(1.5, 1, 0.5), $uuid, VanillaItems::DIAMOND()), Vector3::zero());
		self::assertNotNull($allay->getHeldItem());
		$allay->flagForDespawn();
	}

	public function testOwnerCannotHurtTheAllay() : void{
		$allay = new Allay($this->loc(0.5, 2, 0.5));
		$uuid = Uuid::uuid4();
		$owner = $this->player(new Vector3(1.5, 1, 0.5), $uuid, VanillaItems::DIAMOND(), id: 987654321);
		$allay->onInteract($owner, Vector3::zero());
		self::assertNotNull($allay->getHeldItem());

		//the damager of the event is looked up in the worlds
		$entities = new \ReflectionProperty(World::class, "entities");
		/** @var array<int, Entity> $list */
		$list = $entities->getValue($this->world);
		$list[987654321] = $owner;
		$entities->setValue($this->world, $list);
		try{
			$hit = new EntityDamageByEntityEvent($owner, $allay, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 4);
			$allay->attack($hit);
			self::assertTrue($hit->isCancelled(), "no damage from its owner");
			self::assertSame(20.0, $allay->getHealth());
		}finally{
			unset($list[987654321]);
			$entities->setValue($this->world, $list);
		}

		$zombie = new Zombie($this->loc(2.5, 1, 0.5));
		$hit = new EntityDamageByEntityEvent($zombie, $allay, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 3);
		$allay->attack($hit);
		self::assertFalse($hit->isCancelled());
		$zombie->flagForDespawn();
		$allay->flagForDespawn();
	}

	public function testAllayFollowsItsOwnerFrom16BlocksTo4() : void{
		$uuid = Uuid::uuid4();
		$near = new Vector3(14.5, 2, 0.5);
		$allay = new Allay($this->loc(0.5, 2, 0.5));
		$owner = $this->player($near, $uuid, VanillaItems::DIAMOND());
		$allay->onInteract($owner, Vector3::zero());
		$closest = 100.0;
		for($i = 0; $i < 100; ++$i){
			$this->runTicks(1);
			$closest = min($closest, $allay->getPosition()->distance($near));
		}
		self::assertGreaterThan(5.0, $closest, "14 blocks away: no following (only wandering)");
		$allay->flagForDespawn();

		$far = new Vector3(20.5, 2, 0.5);
		$allay = new Allay($this->loc(0.5, 2, 0.5));
		$owner = $this->player($far, $uuid, VanillaItems::DIAMOND());
		$allay->onInteract($owner, Vector3::zero());
		$closest = 100.0;
		//the following part (then it may wander again)
		for($i = 0; $i < 60; ++$i){
			$this->runTicks(1);
			$closest = min($closest, $allay->getPosition()->distance($far));
		}
		self::assertLessThan(5.0, $closest, "followed its owner");
		self::assertGreaterThan(2.5, $closest, "stopped about 4 blocks away (it glides a little after stopping)");
		$allay->flagForDespawn();
	}
}
