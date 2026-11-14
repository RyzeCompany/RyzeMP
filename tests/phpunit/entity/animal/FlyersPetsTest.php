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

namespace pocketmine\entity\animal;

use pocketmine\block\DriedGhast;
use pocketmine\block\Jukebox;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\block\utils\WaterloggedHelper;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Water;
use pocketmine\entity\ai\goal\FindPlayerMountGoal;
use pocketmine\entity\ai\goal\FollowMobGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\GoalSelector;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\PrioritizedGoal;
use pocketmine\entity\ai\goal\TemptGoal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\effect\EffectManager;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\PlayerOffHandInventory;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\player\Player;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;
use function array_filter;
use function array_values;
use function mt_srand;

/**
 * Parrots and happy ghasts: dried ghast hatching, parrots on shoulders, dancing parrots, wild parrots following mobs,
 * happy ghast tempting and ghastling panic.
 */
final class FlyersPetsTest extends MobTestCase{
	private int $nextPlayerId = 7300000;
	/**
	 * @var Player[]
	 * @phpstan-var list<Player>
	 */
	private array $players = [];

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261022);
		LiquidLayer::clearCache();
	}

	protected function tearDown() : void{
		foreach($this->players as $player){
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		}
		$this->players = [];
		parent::tearDown();
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * A survival player at the location holding the item: a partial mock which keeps the real riding code (seats,
	 * mounting, dismounting).
	 */
	private function player(Location $location, ?Item $held = null) : Player{
		$player = $this->getMockBuilder(Player::class)->disableOriginalConstructor()->onlyMethods([
			"isAlive", "isConnected", "isSpectator", "isFlying", "isGliding", "isSwimming", "isSleeping", "syncNetworkData",
			"getInventory", "getOffHandInventory", "getUniqueId", "getEffects", "getNetworkSession"
		])->getMock();
		$player->method("isAlive")->willReturn(true);
		$player->method("isConnected")->willReturn(false);
		$player->method("isSpectator")->willReturn(false);
		$player->method("isFlying")->willReturn(false);
		$player->method("isGliding")->willReturn(false);
		$player->method("isSwimming")->willReturn(false);
		$player->method("isSleeping")->willReturn(false);
		$inventory = new PlayerInventory($player);
		$inventory->setItemInHand($held ?? VanillaItems::AIR());
		$player->method("getInventory")->willReturn($inventory);
		$player->method("getOffHandInventory")->willReturn(new PlayerOffHandInventory($player));
		$player->method("getUniqueId")->willReturn(Uuid::uuid4());
		$player->method("getEffects")->willReturn(new EffectManager($player));
		$player->method("getNetworkSession")->willReturn($this->createMock(NetworkSession::class));
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, $location);
		(new \ReflectionProperty(Entity::class, "size"))->setValue($player, new EntitySizeInfo(1.8, 0.6, 1.62));
		(new \ReflectionProperty(Entity::class, "id"))->setValue($player, ++$this->nextPlayerId);
		(new \ReflectionProperty(Entity::class, "networkProperties"))->setValue($player, new EntityMetadataCollection());
		(new \ReflectionProperty(Entity::class, "boundingBox"))->setValue($player, new AxisAlignedBB($location->x - 0.3, $location->y, $location->z - 0.3, $location->x + 0.3, $location->y + 1.8, $location->z + 0.3));
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		$this->players[] = $player;
		return $player;
	}

	private function setPlayers(Player ...$players) : void{
		(new \ReflectionProperty(World::class, "players"))->setValue($this->world, $players);
	}

	/**
	 * @phpstan-template T of Goal
	 * @phpstan-param class-string<T> $class
	 * @phpstan-return T|null
	 */
	private static function findGoal(Mob $mob, string $class) : ?Goal{
		/** @var PrioritizedGoal[] $goals */
		$goals = (new \ReflectionProperty(GoalSelector::class, "goals"))->getValue($mob->getGoalSelector());
		foreach($goals as $entry){
			if($entry->goal instanceof $class){
				return $entry->goal;
			}
		}
		return null;
	}

	private static function isRunning(Mob $mob, string $class) : bool{
		foreach($mob->getGoalSelector()->getRunningGoals() as $goal){
			if($goal instanceof $class){
				return true;
			}
		}
		return false;
	}

	/**
	 * @phpstan-return list<HappyGhast>
	 */
	private function happyGhasts() : array{
		return array_values(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof HappyGhast && !$e->isClosed()));
	}

	private function driedGhast(int $level, int $facing) : DriedGhast{
		$block = VanillaBlocks::DRIED_GHAST()->setRehydrationLevel($level);
		$block->setFacing($facing);
		return $block;
	}

	public function testWaterloggedDriedGhastHatchesAGhastling() : void{
		$this->set(3, 1, 3, $this->driedGhast(0, Facing::WEST));
		$block = $this->get(3, 1, 3);
		self::assertTrue(WaterloggedHelper::canWaterlog($block));
		WaterloggedHelper::waterlog($block);
		$this->runTicks(4990);
		$ghast = $this->get(3, 1, 3);
		self::assertInstanceOf(DriedGhast::class, $ghast);
		self::assertSame(0, $ghast->getRehydrationLevel(), "nothing before 5000 ticks");
		$this->runTicks(20);
		$ghast = $this->get(3, 1, 3);
		self::assertInstanceOf(DriedGhast::class, $ghast);
		self::assertSame(1, $ghast->getRehydrationLevel());
		self::assertTrue(LiquidLayer::holdsWater($ghast), "the water stays in the liquid layer");
		$this->runTicks(10000);
		$ghast = $this->get(3, 1, 3);
		self::assertInstanceOf(DriedGhast::class, $ghast);
		self::assertSame(3, $ghast->getRehydrationLevel());
		self::assertCount(0, $this->happyGhasts());
		$this->runTicks(5000);
		self::assertInstanceOf(Water::class, $this->get(3, 1, 3), "the water is left behind");
		$ghasts = $this->happyGhasts();
		self::assertCount(1, $ghasts);
		self::assertTrue($ghasts[0]->isBaby());
		self::assertEqualsWithDelta(90.0, $ghasts[0]->getLocation()->yaw, 0.001, "facing the block's way");
	}

	public function testDriedGhastDriesOutOfWater() : void{
		$this->set(3, 1, 3, $this->driedGhast(2, Facing::NORTH));
		$this->runTicks(5010);
		$ghast = $this->get(3, 1, 3);
		self::assertInstanceOf(DriedGhast::class, $ghast);
		self::assertSame(1, $ghast->getRehydrationLevel());
		$this->runTicks(5000);
		$ghast = $this->get(3, 1, 3);
		self::assertInstanceOf(DriedGhast::class, $ghast);
		self::assertSame(0, $ghast->getRehydrationLevel());
		self::assertFalse($this->world->isBlockUpdateScheduled(3, 1, 3), "a dry level 0 dried ghast stops ticking");
	}

	public function testDryDriedGhastDoesNotTick() : void{
		$this->set(3, 1, 3, $this->driedGhast(0, Facing::NORTH));
		$this->runTicks(2);
		self::assertFalse($this->world->isBlockUpdateScheduled(3, 1, 3));
	}

	private function tamedParrot(Location $location, Player $owner) : Parrot{
		$parrot = new Parrot($location);
		$parrot->setOwner($owner);
		$parrot->setSitting(false);
		$parrot->spawnToAll();
		return $parrot;
	}

	public function testPlayerSeatsOnlyTakeTamedParrots() : void{
		$player = $this->player($this->loc(0.5, 1, 0.5));
		self::assertCount(2, $player->getRideableSeats());
		$wild = new Parrot($this->loc(1.5, 1, 0.5));
		self::assertFalse($player->canAddPassenger($wild));
		$wild->setOwner($player);
		self::assertTrue($player->canAddPassenger($wild));
		self::assertFalse($player->canAddPassenger(new Zombie($this->loc(2.5, 1, 0.5))));
	}

	public function testTamedParrotLandsOnShoulderAfterDelay() : void{
		$player = $this->player($this->loc(0.5, 1, 0.5));
		$this->setPlayers($player);
		$parrot = $this->tamedParrot($this->loc(4.5, 2, 0.5), $player);
		$this->runTicks(80);
		self::assertNull($parrot->getVehicle(), "find_mount waits 100 ticks first");
		for($i = 0; $i < 300 && $parrot->getVehicle() === null; ++$i){
			$this->runTicks(1);
		}
		self::assertSame($player, $parrot->getVehicle());
		self::assertSame([$parrot], $player->getPassengers());

		//a second parrot takes the other shoulder, a third finds no seat
		$second = $this->tamedParrot($this->loc(0.5, 2, 3.5), $player);
		$third = $this->tamedParrot($this->loc(-2.5, 2, 0.5), $player);
		for($i = 0; $i < 500 && $second->getVehicle() === null && $third->getVehicle() === null; ++$i){
			$this->runTicks(1);
		}
		$this->runTicks(200);
		self::assertCount(2, $player->getPassengers());
	}

	public function testSittingParrotStaysOff() : void{
		$player = $this->player($this->loc(0.5, 1, 0.5));
		$this->setPlayers($player);
		$parrot = $this->tamedParrot($this->loc(1.5, 1, 0.5), $player);
		$parrot->setSitting(true);
		$this->runTicks(300);
		self::assertNull($parrot->getVehicle());
	}

	public function testShoulderParrotLeavesWhenPlayerFallsOrIsHurt() : void{
		$player = $this->player($this->loc(0.5, 1, 0.5));
		$parrot = $this->tamedParrot($this->loc(1.5, 2, 0.5), $player);
		self::assertTrue($parrot->mount($player));
		$this->runTicks(5);
		self::assertSame($player, $parrot->getVehicle());

		(new \ReflectionProperty(Entity::class, "fallDistance"))->setValue($player, 1.0);
		$this->runTicks(1);
		self::assertNull($parrot->getVehicle(), "falling");
		(new \ReflectionProperty(Entity::class, "fallDistance"))->setValue($player, 0.0);

		self::assertTrue($parrot->mount($player));
		(new \ReflectionMethod(Player::class, "dropShoulderPassengers"))->invoke($player);
		self::assertNull($parrot->getVehicle(), "jumping or getting hurt");
		self::assertSame([], $player->getPassengers());

		//the start delay runs again before it lands back
		$goal = self::findGoal($parrot, FindPlayerMountGoal::class);
		self::assertNotNull($goal);
		$this->setPlayers($player);
		self::assertFalse($goal->canStart());
	}

	public function testShoulderParrotIsPositionedOnTheShoulder() : void{
		$player = $this->player($this->loc(0.5, 1, 0.5));
		$parrot = $this->tamedParrot($this->loc(1.5, 2, 0.5), $player);
		self::assertTrue($parrot->mount($player));
		$pos = $player->getPassengerPosition($parrot);
		self::assertGreaterThan(2.0, $pos->y, "at the shoulders, not below the feet");
		self::assertLessThan(3.0, $pos->y);
	}

	public function testParrotsDanceToAPlayingJukebox() : void{
		$this->set(0, 1, 0, VanillaBlocks::JUKEBOX());
		$near = new Parrot($this->loc(2.5, 1, 0.5));
		$near->spawnToAll();
		$far = new Parrot($this->loc(8.5, 1, 0.5));
		$far->spawnToAll();
		$jukebox = $this->get(0, 1, 0);
		self::assertInstanceOf(Jukebox::class, $jukebox);
		$jukebox->insertRecord(VanillaItems::RECORD_13());
		self::assertTrue($near->isDancing());
		self::assertFalse($far->isDancing());

		$jukebox = $this->get(0, 1, 0);
		self::assertInstanceOf(Jukebox::class, $jukebox);
		$jukebox->ejectRecord();
		self::assertFalse($near->isDancing(), "stops with the music");
	}

	public function testDancingParrotStopsFarFromJukebox() : void{
		$this->set(0, 1, 0, VanillaBlocks::JUKEBOX());
		$parrot = new Parrot($this->loc(2.5, 1, 0.5));
		$parrot->spawnToAll();
		$parrot->setSitting(true); //keeps it from wandering back
		$jukebox = $this->get(0, 1, 0);
		self::assertInstanceOf(Jukebox::class, $jukebox);
		$jukebox->insertRecord(VanillaItems::RECORD_13());
		self::assertTrue($parrot->isDancing());
		$parrot->teleport($this->loc(10.5, 1, 0.5));
		$this->runTicks(25);
		self::assertFalse($parrot->isDancing());
	}

	public function testWildParrotFollowsAZombie() : void{
		$parrot = new Parrot($this->loc(0.5, 1, 0.5));
		$parrot->spawnToAll();
		$zombie = new Zombie($this->loc(12.5, 1, 0.5));
		$zombie->spawnToAll();
		$goal = self::findGoal($parrot, FollowMobGoal::class);
		self::assertNotNull($goal);
		$followed = null;
		for($i = 0; $i < 400 && $followed === null; ++$i){
			$this->runTicks(1);
			$followed = $goal->getFollowing();
		}
		self::assertSame($zombie, $followed);
	}

	public function testTamedParrotDoesNotFollowMobs() : void{
		$player = $this->player($this->loc(-20.5, 1, 0.5));
		$parrot = $this->tamedParrot($this->loc(0.5, 1, 0.5), $player);
		$parrot->setSitting(false);
		$zombie = new Zombie($this->loc(6.5, 1, 0.5));
		$zombie->spawnToAll();
		$goal = self::findGoal($parrot, FollowMobGoal::class);
		self::assertNotNull($goal);
		self::assertFalse($goal->canStart());
	}

	public function testAdultHappyGhastTemptedByHarnessOnlyWithoutOne() : void{
		$ghast = new HappyGhast($this->loc(0.5, 4, 0.5));
		$ghast->spawnToAll();
		$player = $this->player($this->loc(10.5, 1, 0.5), VanillaItems::HARNESS()->setColor(DyeColor::BLUE));
		$this->setPlayers($player);
		$tempt = null;
		/** @var PrioritizedGoal[] $goals */
		$goals = (new \ReflectionProperty(GoalSelector::class, "goals"))->getValue($ghast->getGoalSelector());
		foreach($goals as $entry){
			if($entry->goal instanceof TemptGoal && $entry->priority === 4){
				$tempt = $entry->goal;
			}
		}
		self::assertNotNull($tempt);
		self::assertTrue($tempt->canStart(), "harness tempts an unharnessed adult");
		$ghast->setHarness(VanillaItems::HARNESS()->setColor(DyeColor::RED));
		self::assertFalse($tempt->canStart(), "but not a harnessed one");
		$player->getInventory()->setItemInHand(VanillaItems::SNOWBALL());
		self::assertTrue($tempt->canStart(), "snowballs always do");
	}

	public function testGhastlingPanicsWhenHurt() : void{
		$ghast = new HappyGhast($this->loc(0.5, 4, 0.5));
		$ghast->setAge($ghast->getBabyStartAge());
		$ghast->spawnToAll();
		$this->runTicks(3);
		$ghast->attack(new EntityDamageEvent($ghast, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		$running = false;
		for($i = 0; $i < 10 && !$running; ++$i){
			$this->runTicks(1);
			$running = self::isRunning($ghast, PanicGoal::class);
		}
		self::assertTrue($running);
	}

	public function testAdultHappyGhastDoesNotPanic() : void{
		$ghast = new HappyGhast($this->loc(0.5, 4, 0.5));
		$ghast->spawnToAll();
		$this->runTicks(3);
		$ghast->attack(new EntityDamageEvent($ghast, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		$this->runTicks(10);
		self::assertFalse(self::isRunning($ghast, PanicGoal::class));
	}

	public function testGhastlingFollowsPassiveMobsButNotBabiesOrMonsters() : void{
		$ghast = new HappyGhast($this->loc(0.5, 3, 0.5));
		$ghast->setAge($ghast->getBabyStartAge());
		$ghast->spawnToAll();
		$zombie = new Zombie($this->loc(8.5, 1, 0.5));
		$zombie->spawnToAll();
		$calf = new Cow($this->loc(-8.5, 1, 0.5));
		$calf->setAge($calf->getBabyStartAge());
		$calf->spawnToAll();
		$goal = self::findGoal($ghast, FollowMobGoal::class);
		self::assertNotNull($goal);
		self::assertFalse($goal->canStart(), "neither a zombie nor a calf");
		$cow = new Cow($this->loc(0.5, 1, 9.5));
		$cow->spawnToAll();
		(new \ReflectionProperty(FollowMobGoal::class, "searchCooldown"))->setValue($goal, 0);
		self::assertTrue($goal->canStart());
		self::assertSame($cow, $goal->getFollowing());
	}
}
