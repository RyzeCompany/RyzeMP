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

use pocketmine\block\utils\MobHeadType;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\NearestAttackableEntityGoal;
use pocketmine\entity\ai\goal\NearestAttackableTargetGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\TemptGoal;
use pocketmine\entity\ai\hostile\goal\NearestLivingTargetGoal;
use pocketmine\entity\ai\sensing\Sensing;
use pocketmine\entity\animal\Armadillo;
use pocketmine\entity\animal\Cat;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\PolarBear;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Blaze;
use pocketmine\entity\monster\Creeper;
use pocketmine\entity\monster\Guardian;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\monster\Spider;
use pocketmine\entity\monster\Witch;
use pocketmine\entity\monster\Zoglin;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\entity\object\ArmorStand;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\PlayerOffHandInventory;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;
use function count;
use function mt_rand;
use function mt_srand;

/**
 * Bedrock target selection: invisibility, per-mob target memory, reselection and follow range, line of sight through
 * doors, spider anger, armor stands, tempt range and scaring, panic on any damage, anger broadcast.
 */
final class TargetingTest extends MobTestCase{
	private int $nextPlayerId = 7000000;
	/**
	 * @var Player[]
	 * @phpstan-var list<Player>
	 */
	private array $players = [];

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261020);
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * A mocked survival player standing at the location (moved by changing the location's coordinates).
	 *
	 * @param Item[] $armor slot => item
	 * @phpstan-param array<int, Item> $armor
	 */
	private function player(Location $location, bool $invisible = false, array $armor = [], ?Item $held = null, bool $sneaking = false) : Player{
		$player = $this->createMock(Player::class);
		$armorInventory = new ArmorInventory($player);
		foreach($armor as $slot => $item){
			$armorInventory->setItem($slot, $item);
		}
		$inventory = new PlayerInventory($player);
		$inventory->setItemInHand($held ?? VanillaItems::AIR());
		$player->method("getWorld")->willReturn($this->world);
		$player->method("isAlive")->willReturn(true);
		$player->method("isConnected")->willReturn(true);
		$player->method("getGamemode")->willReturn(GameMode::SURVIVAL);
		$player->method("getLocation")->willReturn($location);
		$player->method("getPosition")->willReturn($location);
		$player->method("getEyePos")->willReturnCallback(fn() => $location->add(0, 1.62, 0));
		$player->method("getBoundingBox")->willReturnCallback(fn() => new AxisAlignedBB($location->x - 0.3, $location->y, $location->z - 0.3, $location->x + 0.3, $location->y + 1.8, $location->z + 0.3));
		$player->method("getId")->willReturn(++$this->nextPlayerId);
		$player->method("isInvisible")->willReturn($invisible);
		$player->method("isSneaking")->willReturn($sneaking);
		$player->method("getArmorInventory")->willReturn($armorInventory);
		$player->method("getInventory")->willReturn($inventory);
		$player->method("getOffHandInventory")->willReturn(new PlayerOffHandInventory($player));
		$player->method("getUniqueId")->willReturn(Uuid::uuid4());
		//closed (never disposed like a real player) only after the test: mobs can't target closed entities
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		$this->players[] = $player;
		return $player;
	}

	protected function tearDown() : void{
		//keep the mocks from running Player::onDispose() when they are destroyed
		foreach($this->players as $player){
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		}
		$this->players = [];
		parent::tearDown();
	}

	/**
	 * Makes the players the players of the world (what the mobs' player searches look at).
	 */
	private function setPlayers(Player ...$players) : void{
		(new \ReflectionProperty(World::class, "players"))->setValue($this->world, $players);
	}

	private static function freeze(Mob $mob) : void{
		$mob->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
	}

	/**
	 * A frozen mob that can take a lot of damage (zombies burn in the test world's daylight).
	 *
	 * @phpstan-template T of Mob
	 * @phpstan-param T $mob
	 * @phpstan-return T
	 */
	private static function sturdy(Mob $mob) : Mob{
		self::freeze($mob);
		$mob->setMaxHealth(100000);
		$mob->setHealth(100000);
		return $mob;
	}

	/**
	 * Runs ticks until the mob has the target (at most $maxTicks), and returns whether it got it.
	 */
	private function runUntilTargets(Mob $mob, ?Entity $target, int $maxTicks) : bool{
		for($i = 0; $i < $maxTicks; ++$i){
			if($mob->getAttackTarget() === $target){
				return true;
			}
			$this->runTicks(1);
		}
		return $mob->getAttackTarget() === $target;
	}

	private function wall(int $z) : void{
		for($x = -4; $x <= 4; ++$x){
			for($y = 1; $y <= 4; ++$y){
				$this->world->setBlockAt($x, $y, $z, VanillaBlocks::STONE(), false);
			}
		}
	}

	public function testVisibilityFactor() : void{
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$skeleton = new Skeleton($this->loc(2.5, 1, 0.5));
		$cow = new Cow($this->loc(4.5, 1, 0.5));
		$at = $this->loc(0.5, 1, 10.5);

		self::assertSame(1.0, Sensing::getVisibilityFactor($this->player($at), $zombie));
		self::assertEqualsWithDelta(0.07, Sensing::getVisibilityFactor($this->player($at, true), $zombie), 0.0001, "invisible without armour");
		self::assertEqualsWithDelta(0.35, Sensing::getVisibilityFactor($this->player($at, true, [
			ArmorInventory::SLOT_CHEST => VanillaItems::IRON_CHESTPLATE(),
			ArmorInventory::SLOT_FEET => VanillaItems::IRON_BOOTS()
		]), $zombie), 0.0001, "invisible with 2 armour pieces");

		$zombieHead = [ArmorInventory::SLOT_HEAD => VanillaBlocks::MOB_HEAD()->setMobHeadType(MobHeadType::ZOMBIE)->asItem()];
		self::assertSame(0.5, Sensing::getVisibilityFactor($this->player($at, false, $zombieHead), $zombie), "zombie head against a zombie");
		self::assertSame(1.0, Sensing::getVisibilityFactor($this->player($at, false, $zombieHead), $skeleton), "zombie head against a skeleton");
		self::assertSame(1.0, Sensing::getVisibilityFactor($this->player($at, false, $zombieHead), $cow));
		$skull = [ArmorInventory::SLOT_HEAD => VanillaBlocks::MOB_HEAD()->setMobHeadType(MobHeadType::SKELETON)->asItem()];
		self::assertSame(0.5, Sensing::getVisibilityFactor($this->player($at, false, $skull), $skeleton));
		//the skull counts as a worn piece: 0.7 x 1/4, halved
		self::assertEqualsWithDelta(0.0875, Sensing::getVisibilityFactor($this->player($at, true, $skull), $skeleton), 0.0001, "invisible with the skull on");

		//sneaking: 0.8 (target_sneak_visibility_multiplier), for every mob
		self::assertEqualsWithDelta(0.8, Sensing::getVisibilityFactor($this->player($at, sneaking: true), $cow), 0.0001, "sneaking");
		self::assertEqualsWithDelta(0.056, Sensing::getVisibilityFactor($this->player($at, true, sneaking: true), $zombie), 0.0001, "invisible and sneaking");
		self::assertEqualsWithDelta(0.4, Sensing::getVisibilityFactor($this->player($at, false, $zombieHead, sneaking: true), $zombie), 0.0001, "sneaking with the zombie head on");
		self::assertTrue(Sensing::canDetect($zombie, $this->player($at, sneaking: true), 25.0, 19.9 * 19.9));
		self::assertFalse(Sensing::canDetect($zombie, $this->player($at, sneaking: true), 25.0, 20.1 * 20.1));

		//detection: at least 2 blocks
		$invisible = $this->player($at, true);
		self::assertFalse(Sensing::canDetect($zombie, $invisible, 25.0, 10 * 10));
		self::assertFalse(Sensing::canDetect($zombie, $invisible, 25.0, 2.1 * 2.1));
		self::assertTrue(Sensing::canDetect($zombie, $invisible, 25.0, 1.9 * 1.9));
		self::assertTrue(Sensing::canDetect($zombie, $this->player($at), 25.0, 24 * 24));
	}

	public function testInvisiblePlayerIsOnlyNoticedUpClose() : void{
		$zombie = self::sturdy(new Zombie($this->loc(0.5, 1, 0.5)));
		$location = $this->loc(0.5, 1, 10.5);
		$player = $this->player($location, true);
		$this->setPlayers($player);
		self::assertFalse($this->runUntilTargets($zombie, $player, 100), "invisible player 10 blocks away");

		$location->z = 2.0;
		self::assertTrue($this->runUntilTargets($zombie, $player, 100), "invisible player 1.5 blocks away");

		//a visible player is noticed from afar
		$other = self::sturdy(new Zombie($this->loc(0.5, 1, -10.5)));
		$visible = $this->player($this->loc(0.5, 1, -2.5));
		$this->setPlayers($player, $visible);
		self::assertTrue($this->runUntilTargets($other, $visible, 100));
	}

	public function testZombieRemembersUnseenTargetFor17Seconds() : void{
		$zombie = self::sturdy(new Zombie($this->loc(0.5, 1, 0.5)));
		$player = $this->player($this->loc(0.5, 1, 8.5));
		$this->setPlayers($player);
		self::assertTrue($this->runUntilTargets($zombie, $player, 100));

		$this->wall(4);
		$this->runTicks(17 * 20 - 40);
		self::assertSame($player, $zombie->getAttackTarget(), "still remembered after 15 seconds");
		$this->runTicks(60);
		self::assertNull($zombie->getAttackTarget(), "forgotten after 17 seconds");
	}

	public function testSkeletonForgetsUnseenTargetAfter3Seconds() : void{
		$skeleton = self::sturdy(new Skeleton($this->loc(0.5, 1, 0.5)));
		$player = $this->player($this->loc(0.5, 1, 8.5));
		$this->setPlayers($player);
		self::assertTrue($this->runUntilTargets($skeleton, $player, 100));

		$this->wall(4);
		$this->runTicks(50);
		self::assertSame($player, $skeleton->getAttackTarget());
		$this->runTicks(20);
		self::assertNull($skeleton->getAttackTarget());
	}

	public function testZombieReselectsCloserTarget() : void{
		$zombie = self::sturdy(new Zombie($this->loc(0.5, 1, 0.5)));
		$far = $this->player($this->loc(0.5, 1, 12.5));
		$this->setPlayers($far);
		self::assertTrue($this->runUntilTargets($zombie, $far, 100));

		$close = $this->player($this->loc(3.5, 1, 0.5));
		$this->setPlayers($far, $close);
		self::assertTrue($this->runUntilTargets($zombie, $close, 30), "reselect_targets");

		//a creeper keeps its first target
		$creeper = self::sturdy(new Creeper($this->loc(0.5, 1, -0.5)));
		$this->setPlayers($far);
		self::assertTrue($this->runUntilTargets($creeper, $far, 100));
		$this->setPlayers($far, $close);
		$this->runTicks(40);
		self::assertSame($far, $creeper->getAttackTarget());
	}

	public function testReselectionWaitsForTheAiBudget() : void{
		//three cows updated before the zombie
		for($i = 0; $i < 3; ++$i){
			self::sturdy(new Cow($this->loc(-8.5 - $i, 1, -8.5)));
		}
		$zombie = self::sturdy(new Zombie($this->loc(0.5, 1, 0.5)));
		$far = $this->player($this->loc(0.5, 1, 12.5));
		$this->setPlayers($far);
		self::assertTrue($this->runUntilTargets($zombie, $far, 100));

		//no AI update left for the zombie: the target is kept, no closer one is looked for. The budget serves the requests
		//waiting longest first: one waiting since tick 0 takes the only update of every tick
		AiBudget::configure(true, 1, 100000, 400, requireNearbyPlayer: false);
		$close = $this->player($this->loc(3.5, 1, 0.5));
		$this->setPlayers($far, $close);
		for($i = 0; $i < 60; ++$i){
			self::assertTrue(AiBudget::tryAiUpdate($this->tick + 1, 0));
			$this->runTicks(1);
		}
		self::assertSame($far, $zombie->getAttackTarget(), "no reselection outside the AI budget");

		//one update per tick, shared with the cows updated before it: the zombie still gets its turn (first come, first
		//served, the cows would take it on every tick)
		self::assertTrue($this->runUntilTargets($zombie, $close, 60), "reselection once AI updates are granted again");
	}

	/**
	 * The target a reselection switches to: the nearest candidate found (the result of a search method called through
	 * reflection) when it is closer than the current target.
	 */
	private static function reselected(mixed $nearest, Living $current, Mob $mob) : ?Living{
		if($nearest === null){
			return null;
		}
		self::assertInstanceOf(Living::class, $nearest);
		if($nearest === $current){
			return null;
		}
		$loc = $mob->getLocation();
		return $nearest->getLocation()->distanceSquared($loc) < $current->getLocation()->distanceSquared($loc) ? $nearest : null;
	}

	public function testBoundedReselectionPicksTheSameTarget() : void{
		$mob = self::sturdy(new Zombie($this->loc(0.5, 1, 0.5)));
		$filter = fn(Living $e) => $e instanceof Cow && $e->getNameTag() !== "no";
		$entityGoal = new NearestAttackableEntityGoal($mob, $filter, 16.0);
		$livingGoal = new NearestLivingTargetGoal($mob, $filter, 16.0);
		$playerGoal = new NearestAttackableTargetGoal($mob, 16.0);
		$entitySearch = new \ReflectionMethod($entityGoal, "findTarget");
		$livingSearch = new \ReflectionMethod($livingGoal, "findNearest");
		$playerSearch = new \ReflectionMethod($playerGoal, "findTarget");
		$this->wall(-3);
		$checked = 0;
		$switches = 0;
		for($trial = 0; $trial < 60; ++$trial){
			$cows = [];
			$players = [];
			for($i = 0, $n = mt_rand(1, 8); $i < $n; ++$i){
				$location = $this->loc(mt_rand(-180, 180) / 10, 1 + mt_rand(0, 60) / 10, mt_rand(-180, 180) / 10);
				$cow = new Cow($location);
				if(mt_rand(0, 4) === 0){
					$cow->setNameTag("no");
				}
				$cows[] = $cow;
				$players[] = $this->player(clone $location);
			}
			//ties: a candidate as far as the current target, mirrored
			$current = $cows[mt_rand(0, count($cows) - 1)];
			$c = $current->getLocation();
			$cows[] = new Cow($this->loc(1.0 - $c->x, $c->y, 1.0 - $c->z));
			$currentPlayer = $players[mt_rand(0, count($players) - 1)];
			$p = $currentPlayer->getLocation();
			$players[] = $this->player($this->loc(1.0 - $p->x, $p->y, 1.0 - $p->z));
			$this->setPlayers(...$players);
			$mob->getSensing()->reset();

			$loc = $mob->getLocation();
			$d = $current->getLocation()->distanceSquared($loc);
			$dp = $p->distanceSquared($loc);
			foreach([
				"entity goal" => [$entitySearch->invoke($entityGoal), $entitySearch->invoke($entityGoal, $d), $current],
				"living goal" => [$livingSearch->invoke($livingGoal), $livingSearch->invoke($livingGoal, $current, $d), $current],
				"player goal" => [$playerSearch->invoke($playerGoal), $playerSearch->invoke($playerGoal, $currentPlayer, $dp), $currentPlayer]
			] as $name => [$unbounded, $bounded, $was]){
				$expected = self::reselected($unbounded, $was, $mob);
				self::assertSame($expected, self::reselected($bounded, $was, $mob), "$name, trial $trial");
				if($expected !== null){
					++$switches;
				}
				++$checked;
			}
			foreach($cows as $cow){
				$cow->close();
			}
		}
		self::assertSame(180, $checked);
		self::assertGreaterThan(30, $switches, "trials with a closer target");
		self::assertLessThan(150, $switches, "trials without one");
	}

	public function testNearestPlayerWithinDistance() : void{
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$near = $this->player($this->loc(0.5, 1, 4.5));
		$far = $this->player($this->loc(0.5, 1, 8.5));
		$this->setPlayers($near, $far);
		$sensing = $zombie->getSensing();
		self::assertSame($near, $sensing->getNearestPlayer(16.0));
		self::assertSame($far, $sensing->getNearestPlayer(16.0, fn(Player $p) => $p !== $near, false, 9 * 9));
		self::assertNull($sensing->getNearestPlayer(16.0, fn(Player $p) => $p !== $near, false, 7 * 7), "farther than the distance");
		self::assertSame($near, $sensing->getNearestPlayer(16.0, null, false, 100 * 100), "the range still applies");
		self::assertNull($sensing->getNearestPlayer(3.0, null, false, 100 * 100));
	}

	public function testFollowRange() : void{
		self::assertSame(16.0, (new Zombie($this->loc(0.5, 1, 0.5)))->getFollowRange());
		self::assertSame(64.0, (new Witch($this->loc(1.5, 1, 0.5)))->getFollowRange());
		self::assertSame(48.0, (new Blaze($this->loc(2.5, 1, 0.5)))->getFollowRange());
		self::assertSame(16.0, (new Guardian($this->loc(3.5, 1, 0.5)))->getFollowRange());

		//skeletons follow targets up to 16 blocks, witches up to 64
		$skeleton = self::sturdy(new Skeleton($this->loc(0.5, 1, 0.5)));
		$witch = self::sturdy(new Witch($this->loc(-0.5, 1, 0.5)));
		$location = $this->loc(0.5, 1, 6.5);
		$player = $this->player($location);
		$this->setPlayers($player);
		self::assertTrue($this->runUntilTargets($skeleton, $player, 100));
		self::assertTrue($this->runUntilTargets($witch, $player, 100));
		$location->z = 20.5;
		$this->runTicks(2);
		self::assertNull($skeleton->getAttackTarget());
		self::assertSame($player, $witch->getAttackTarget());
	}

	public function testDoorsBlockLineOfSight() : void{
		$a = self::sturdy(new Cow($this->loc(0.5, 1, 0.5)));
		$b = self::sturdy(new Cow($this->loc(0.5, 1, 4.5)));
		self::assertTrue(Sensing::hasLineOfSight($a, $b));

		$door = VanillaBlocks::OAK_DOOR()->setFacing(Facing::SOUTH);
		foreach([false, true] as $open){
			$this->world->setBlockAt(0, 1, 2, (clone $door)->setOpen($open)->setTop(false), false);
			$this->world->setBlockAt(0, 2, 2, (clone $door)->setOpen($open)->setTop(true), false);
			self::assertSame($open, Sensing::hasLineOfSight($a, $b), $open ? "open door" : "closed door");
		}

		$this->world->setBlockAt(0, 1, 2, VanillaBlocks::AIR(), false);
		$this->world->setBlockAt(0, 2, 2, VanillaBlocks::AIR(), false);
		$gate = VanillaBlocks::OAK_FENCE_GATE()->setFacing(Facing::SOUTH);
		$this->world->setBlockAt(0, 2, 2, (clone $gate)->setOpen(false), false);
		self::assertFalse(Sensing::hasLineOfSight($a, $b), "closed fence gate");
		$this->world->setBlockAt(0, 2, 2, (clone $gate)->setOpen(true), false);
		self::assertTrue(Sensing::hasLineOfSight($a, $b), "open fence gate");

		$this->world->setBlockAt(0, 2, 2, VanillaBlocks::OAK_TRAPDOOR()->setFacing(Facing::NORTH)->setOpen(true), false);
		self::assertFalse(Sensing::hasLineOfSight($a, $b), "open trapdoor standing across the view");

		//see-through blocks stay see-through
		$this->world->setBlockAt(0, 2, 2, VanillaBlocks::GLASS(), false);
		self::assertTrue(Sensing::hasLineOfSight($a, $b), "glass");
	}

	/**
	 * A spider whose brightness the test decides.
	 */
	private function spider(bool $bright) : Spider{
		$spider = new class($this->loc(0.5, 1, 0.5)) extends Spider implements NeverSavedWithChunkEntity{
			public bool $bright = false;

			public function isInBrightLight() : bool{
				return $this->bright;
			}
		};
		$spider->bright = $bright;
		return self::sturdy($spider);
	}

	public function testSpiderCalmsDownInDaylight() : void{
		$cow = self::sturdy(new Cow($this->loc(4.5, 1, 0.5)));
		foreach([true, false] as $bright){
			$spider = $this->spider($bright);
			$spider->attack(new EntityDamageByEntityEvent($cow, $spider, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
			self::assertTrue($this->runUntilTargets($spider, $cow, 10));
			self::assertTrue($spider->isAngry());
			$this->runTicks(7 * 20 - 20);
			self::assertSame($cow, $spider->getAttackTarget(), "angry for at least 7 seconds");
			$this->runTicks(6 * 20 + 20);
			if($bright){
				self::assertNull($spider->getAttackTarget(), "calm and neutral in daylight after 13 seconds");
				self::assertFalse($spider->isAngry());
				$this->runTicks(60);
				self::assertNull($spider->getAttackTarget(), "the old hit is not taken again");
			}else{
				self::assertSame($cow, $spider->getAttackTarget(), "keeps attacking in the dark");
				self::assertTrue($spider->isAngry());
			}
			$spider->flagForDespawn();
			$this->runTicks(1);
		}
	}

	public function testZoglinIgnoresArmorStands() : void{
		$zoglin = self::sturdy(new Zoglin($this->loc(0.5, 1, 0.5)));
		$stand = new ArmorStand($this->loc(2.5, 1, 0.5));
		self::assertFalse($zoglin->getSensing()->isValidTarget($stand, 16.0));
		$this->runTicks(60);
		self::assertNull($zoglin->getAttackTarget());

		$cow = self::sturdy(new Cow($this->loc(6.5, 1, 0.5)));
		self::assertTrue($this->runUntilTargets($zoglin, $cow, 100), "the cow behind the armor stand");
	}

	/**
	 * Runs ticks until a goal of the class runs (at most $maxTicks), and returns whether it does.
	 *
	 * @phpstan-param class-string<Goal> $class
	 */
	private function runUntilRunning(Mob $mob, string $class, int $maxTicks) : bool{
		for($i = 0; $i < $maxTicks; ++$i){
			foreach($mob->getGoalSelector()->getRunningGoals() as $goal){
				if($goal instanceof $class){
					return true;
				}
			}
			$this->runTicks(1);
		}
		return false;
	}

	/**
	 * @phpstan-param class-string<Goal> $class
	 */
	private function isRunning(Mob $mob, string $class) : bool{
		foreach($mob->getGoalSelector()->getRunningGoals() as $goal){
			if($goal instanceof $class){
				return true;
			}
		}
		return false;
	}

	public function testWildCatGetsScaredByMovingPlayer() : void{
		$cat = new Cat($this->loc(0.5, 1, 0.5));
		$location = $this->loc(0.5, 1, 4.5);
		$player = $this->player($location, held: VanillaItems::RAW_FISH());
		$this->setPlayers($player);
		self::assertTrue($this->runUntilRunning($cat, TemptGoal::class, 60));
		$this->runTicks(5);
		self::assertTrue($this->isRunning($cat, TemptGoal::class), "a player standing still keeps tempting");

		$location->x += 0.5;
		$this->runTicks(1);
		self::assertFalse($this->isRunning($cat, TemptGoal::class), "scared by the player moving");
		$this->runTicks(60);
		self::assertFalse($this->isRunning($cat, TemptGoal::class), "calms down before being tempted again");

		//turning around scares it too
		$other = new Cat($this->loc(12.5, 1, 0.5));
		$otherLocation = $this->loc(12.5, 1, 3.5);
		$this->setPlayers($player, $this->player($otherLocation, held: VanillaItems::RAW_FISH()));
		self::assertTrue($this->runUntilRunning($other, TemptGoal::class, 60));
		$this->runTicks(2);
		$otherLocation->yaw += 20;
		$this->runTicks(1);
		self::assertFalse($this->isRunning($other, TemptGoal::class), "scared by the player turning");
	}

	public function testTamedCatIsNotScared() : void{
		$cat = new Cat($this->loc(0.5, 1, 0.5));
		$location = $this->loc(0.5, 1, 4.5);
		$player = $this->player($location, held: VanillaItems::RAW_FISH());
		$cat->setOwner($player);
		self::assertTrue($cat->isTamed());
		$this->setPlayers($player);
		self::assertTrue($this->runUntilRunning($cat, TemptGoal::class, 60));
		$location->x += 0.5;
		$this->runTicks(1);
		self::assertTrue($this->isRunning($cat, TemptGoal::class));
	}

	public function testCatIsTemptedFrom16Blocks() : void{
		$cat = new Cat($this->loc(0.5, 1, 0.5));
		$player = $this->player($this->loc(0.5, 1, 14.5), held: VanillaItems::RAW_FISH());
		$this->setPlayers($player);
		self::assertTrue($this->runUntilRunning($cat, TemptGoal::class, 60), "within_radius 16");

		$cow = new Cow($this->loc(5.5, 1, 0.5));
		$this->setPlayers($this->player($this->loc(5.5, 1, 14.5), held: VanillaItems::WHEAT()));
		$this->runTicks(60);
		self::assertFalse($this->isRunning($cow, TemptGoal::class), "default range 10");
	}

	public function testAnyDamageMakesAnimalsPanic() : void{
		$cow = new Cow($this->loc(0.5, 1, 0.5));
		$this->runTicks(5);
		self::assertFalse($this->isRunning($cow, PanicGoal::class));
		$cow->attack(new EntityDamageEvent($cow, EntityDamageEvent::CAUSE_FALL, 1));
		self::assertTrue($this->runUntilRunning($cow, PanicGoal::class, 10), "fall damage");

		//polar bears only panic from fire-like damage, never when hit
		$attacker = self::sturdy(new Cow($this->loc(8.5, 1, 8.5)));
		$hit = new PolarBear($this->loc(-6.5, 1, 0.5));
		$hit->attack(new EntityDamageByEntityEvent($attacker, $hit, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		$fall = new PolarBear($this->loc(-6.5, 1, 8.5));
		$fall->attack(new EntityDamageEvent($fall, EntityDamageEvent::CAUSE_FALL, 1));
		$lava = new PolarBear($this->loc(-6.5, 1, -8.5));
		$lava->attack(new EntityDamageEvent($lava, EntityDamageEvent::CAUSE_LAVA, 1));
		$this->runTicks(10);
		self::assertFalse($this->isRunning($hit, PanicGoal::class), "hit");
		self::assertFalse($this->isRunning($fall, PanicGoal::class), "fall");
		self::assertTrue($this->isRunning($lava, PanicGoal::class), "lava");

		//armadillos ignore damage from mobs
		$armadillo = new Armadillo($this->loc(6.5, 1, -6.5));
		$armadillo->attack(new EntityDamageByEntityEvent($attacker, $armadillo, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		$this->runTicks(10);
		self::assertFalse($this->isRunning($armadillo, PanicGoal::class), "armadillo hit");
	}

	public function testTargetTurnedDownByTheFilterIsDropped() : void{
		$hunter = self::sturdy(new class($this->loc(0.5, 1, 0.5)) extends Zombie implements NeverSavedWithChunkEntity{
			protected function registerGoals() : void{
				$this->targetSelector->addGoal(1, new NearestLivingTargetGoal($this, fn(Living $e) => $e instanceof Cow && $e->getNameTag() !== "safe", 16.0, persistTicks: 20));
			}
		});
		$cow = self::sturdy(new Cow($this->loc(4.5, 1, 0.5)));
		self::assertTrue($this->runUntilTargets($hunter, $cow, 100));
		$cow->setNameTag("safe");
		$this->runTicks(16);
		self::assertSame($cow, $hunter->getAttackTarget(), "persist_time");
		$this->runTicks(8);
		self::assertNull($hunter->getAttackTarget());
	}

	public function testFilterIsNotCheckedAgainByDefault() : void{
		//Bedrock reevaluate_description is false unless set, and Java never checks the selector again either: a wolf
		//keeps chasing a baby turtle that reached the water
		$hunter = self::sturdy(new class($this->loc(0.5, 1, 0.5)) extends Zombie implements NeverSavedWithChunkEntity{
			protected function registerGoals() : void{
				$this->targetSelector->addGoal(1, new NearestLivingTargetGoal($this, fn(Living $e) => $e instanceof Cow && $e->getNameTag() !== "safe", 16.0));
			}
		});
		$cow = self::sturdy(new Cow($this->loc(4.5, 1, 0.5)));
		self::assertTrue($this->runUntilTargets($hunter, $cow, 100));
		$cow->setNameTag("safe");
		$this->runTicks(60);
		self::assertSame($cow, $hunter->getAttackTarget());

		$entityHunter = self::sturdy(new class($this->loc(0.5, 1, 8.5)) extends Zombie implements NeverSavedWithChunkEntity{
			protected function registerGoals() : void{
				$this->targetSelector->addGoal(1, new NearestAttackableEntityGoal($this, fn(Living $e) => $e instanceof Cow && $e->getNameTag() !== "safe", 16.0));
			}
		});
		$other = self::sturdy(new Cow($this->loc(4.5, 1, 8.5)));
		self::assertTrue($this->runUntilTargets($entityHunter, $other, 100));
		$other->setNameTag("safe");
		$this->runTicks(60);
		self::assertSame($other, $entityHunter->getAttackTarget());
	}

	public function testHurtByTargetAlertsTheGroup() : void{
		$factory = fn(Location $l) => new class($l) extends Zombie implements NeverSavedWithChunkEntity{
			protected function registerGoals() : void{
				$this->targetSelector->addGoal(1, new HurtByTargetGoal($this, alertRange: 10.0, angerTicks: 100));
			}
		};
		$hurt = self::sturdy($factory($this->loc(0.5, 1, 0.5)));
		$near = self::sturdy($factory($this->loc(6.5, 1, 0.5)));
		$far = self::sturdy($factory($this->loc(0.5, 1, 14.5)));
		$skeleton = self::sturdy(new Skeleton($this->loc(-4.5, 1, 0.5)));
		$cow = self::sturdy(new Cow($this->loc(0.5, 1, -6.5)));
		$hurt->attack(new EntityDamageByEntityEvent($cow, $hurt, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		$this->runTicks(10);
		self::assertSame($cow, $hurt->getAttackTarget());
		self::assertSame($cow, $near->getAttackTarget(), "alerted");
		self::assertNull($far->getAttackTarget(), "out of the alert range");
		self::assertNull($skeleton->getAttackTarget(), "not of the group");

		//anger duration: the target is dropped 100 ticks after the hit
		$this->runTicks(100);
		self::assertNull($hurt->getAttackTarget());
		self::assertNull($near->getAttackTarget());
	}
}
