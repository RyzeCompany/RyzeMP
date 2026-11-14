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

use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\LeapAtTargetGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\animal\Animal;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Llama;
use pocketmine\entity\animal\Panda;
use pocketmine\entity\animal\PolarBear;
use pocketmine\entity\animal\Sheep;
use pocketmine\entity\animal\Wolf;
use pocketmine\entity\animation\Animation;
use pocketmine\entity\animation\ArmSwingAnimation;
use pocketmine\entity\aquatic\Dolphin;
use pocketmine\entity\aquatic\Nautilus;
use pocketmine\entity\Entity;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Drowned;
use pocketmine\entity\monster\Hoglin;
use pocketmine\entity\monster\Husk;
use pocketmine\entity\monster\Piglin;
use pocketmine\entity\monster\PiglinBrute;
use pocketmine\entity\monster\Silverfish;
use pocketmine\entity\monster\Spider;
use pocketmine\entity\monster\Vindicator;
use pocketmine\entity\monster\Warden;
use pocketmine\entity\monster\WitherSkeleton;
use pocketmine\entity\monster\Zoglin;
use pocketmine\entity\monster\ZombieVillager;
use pocketmine\entity\monster\ZombifiedPiglin;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\entity\Zombie;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\player\Player;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;
use function count;
use function fmod;
use function mt_srand;

/**
 * Bedrock melee: melee_box_attack reach and field of view, attack cooldowns, leaps, fire spreading, ANGRY flag and arm
 * swings (BDS 1.26.52 captures).
 */
final class MeleeCombatTest extends MobTestCase{

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261019);
	}

	private function loc(float $x, float $y, float $z, float $yaw = 0.0) : Location{
		return new Location($x, $y, $z, $this->world, $yaw, 0);
	}

	/**
	 * A cow that doesn't move by itself and can take a lot of damage.
	 */
	private function dummy(float $x, float $y, float $z) : Cow{
		$cow = new Cow($this->loc($x, $y, $z));
		$cow->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$cow->setMaxHealth(100000);
		$cow->setHealth(100000);
		return $cow;
	}

	/**
	 * Collision box of an entity of the given size standing at x, y, z.
	 */
	private static function box(float $x, float $y, float $z, float $width, float $height) : AxisAlignedBB{
		return new AxisAlignedBB($x - $width / 2, $y, $z - $width / 2, $x + $width / 2, $y + $height, $z + $width / 2);
	}

	/**
	 * Keeps a mob standing where it is (no walking, no leaping) while its melee goal runs.
	 */
	private static function immobilize(Mob $mob) : void{
		$mob->setMovementSpeed(0.0);
		$mob->getGoalSelector()->setFlagDisabled(Goal::FLAG_JUMP, true);
	}

	/**
	 * Runs $ticks ticks, putting the target back at its position every tick (knockback, tossing), and returns the ticks
	 * at which it lost health.
	 *
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	private function hitTicks(Living $target, int $ticks) : array{
		$pos = $target->getPosition();
		$health = $target->getHealth();
		$hits = [];
		for($t = 0; $t < $ticks; ++$t){
			$target->teleport($pos);
			$target->setMotion(Vector3::zero());
			$this->runTicks(1);
			if($target->getHealth() < $health){
				$hits[] = $t;
			}
			$health = $target->getHealth();
		}
		return $hits;
	}

	/**
	 * MeleeAttackGoal::isInBoxReach() with the Bedrock horizontal_reach 0.8.
	 */
	private static function isInBoxReach(AxisAlignedBB $attacker, AxisAlignedBB $target) : bool{
		return (new \ReflectionMethod(MeleeAttackGoal::class, "isInBoxReach"))->invoke(null, $attacker, $target, 0.8) === true;
	}

	/**
	 * MeleeAttackGoal::isInFieldOfView().
	 */
	private static function isInFieldOfView(float $yaw, float $dx, float $dz, float $fovCos) : bool{
		return (new \ReflectionMethod(MeleeAttackGoal::class, "isInFieldOfView"))->invoke(null, $yaw, $dx, $dz, $fovCos) === true;
	}

	public function testSpiderBoxReach() : void{
		//BDS 1.26.52: a spider hits with 0.7 blocks between its box and the target's, the reach is 0.8
		$spider = self::box(0.5, 1, 0.5, 1.4, 0.9);
		$right = 0.5 + 0.7 + 0.3;
		self::assertTrue(self::isInBoxReach($spider, self::box($right + 0.7, 1, 0.5, 0.6, 1.8)));
		self::assertFalse(self::isInBoxReach($spider, self::box($right + 1.0, 1, 0.5, 0.6, 1.8)));
		//diagonally: the box grows on each axis
		self::assertTrue(self::isInBoxReach($spider, self::box($right + 0.7, 1, 0.5 + 1.4, 0.6, 1.8)));
		//no reach upwards: the heights must overlap
		self::assertTrue(self::isInBoxReach($spider, self::box(0.5, 1.85, 0.5 + 1.2, 0.6, 1.8)));
		self::assertFalse(self::isInBoxReach($spider, self::box(0.5, 2.0, 0.5 + 1.2, 0.6, 1.8)));
		self::assertFalse(self::isInBoxReach($spider, self::box(0.5, -1.0, 0.5 + 1.2, 0.6, 1.8)));
	}

	/**
	 * @phpstan-return array<string, array{float, float}>
	 */
	public static function meleeReachProvider() : array{
		//attacker width => farthest centre distance from a player-sized target (0.6 wide): half widths + 0.8
		return [
			"zombie" => [0.6, 1.4],
			"warden" => [0.9, 1.55],
			"iron golem" => [1.4, 1.8],
			"spider" => [1.4, 1.8],
			"cave spider" => [0.7, 1.45],
		];
	}

	/**
	 * @dataProvider meleeReachProvider
	 */
	public function testMeleeBoxReachDistance(float $width, float $maxDistance) : void{
		$attacker = self::box(0, 0, 0, $width, 1.0);
		self::assertTrue(self::isInBoxReach($attacker, self::box($maxDistance - 0.01, 0, 0, 0.6, 1.8)));
		self::assertFalse(self::isInBoxReach($attacker, self::box($maxDistance + 0.01, 0, 0, 0.6, 1.8)));
		self::assertFalse(self::isInBoxReach($attacker, self::box(0, 0, -$maxDistance - 0.01, 0.6, 1.8)));
	}

	public function testFieldOfView() : void{
		$cos45 = 0.7071;
		//yaw 0 looks towards +Z, yaw 90 towards -X
		self::assertTrue(self::isInFieldOfView(0.0, 0.0, 1.0, $cos45));
		self::assertTrue(self::isInFieldOfView(0.0, 0.7, 1.0, $cos45));
		self::assertFalse(self::isInFieldOfView(0.0, 1.0, 0.5, $cos45));
		self::assertFalse(self::isInFieldOfView(0.0, 0.0, -1.0, $cos45));
		self::assertTrue(self::isInFieldOfView(90.0, -1.0, 0.0, $cos45));
		self::assertFalse(self::isInFieldOfView(90.0, 1.0, 0.0, $cos45));
		self::assertTrue(self::isInFieldOfView(270.0, 1.0, 0.2, $cos45));
	}

	/**
	 * Returns the reach check of a melee goal of the mob: whether it can hit the given target from where it is.
	 *
	 * @phpstan-return \Closure(Living) : bool
	 */
	private static function reachProbe(Mob $mob, float $meleeFov = 90.0) : \Closure{
		$goal = new class($mob, 1.0, 3.0, 16.0, MeleeAttackGoal::ATTACK_INTERVAL, $meleeFov) extends MeleeAttackGoal{
			public function reaches(Living $target) : bool{
				$a = $this->mob->getLocation();
				$b = $target->getLocation();
				$dx = $b->x - $a->x;
				$dy = $b->y - $a->y;
				$dz = $b->z - $a->z;
				return $this->isInReach($target, $dx, $dz, $dx * $dx + $dy * $dy + $dz * $dz);
			}
		};
		return $goal->reaches(...);
	}

	public function testNoHitBehindExceptWarden() : void{
		//both look towards +Z (yaw 0)
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$front = $this->dummy(0.5, 1, 1.9);
		$behind = $this->dummy(8.5, 1, 8.5 - 1.4);
		$zombieReaches = self::reachProbe($zombie);
		self::assertTrue($zombieReaches($front));
		$zombie->teleport(new Vector3(8.5, 1, 8.5));
		self::assertFalse($zombieReaches($behind), "a zombie doesn't hit a target behind it");

		$warden = new Warden($this->loc(16.5, 1, 16.5));
		$behindWarden = $this->dummy(16.5, 1, 16.5 - 1.5);
		self::assertTrue(self::reachProbe($warden, 360.0)($behindWarden), "the warden hits all around it (melee_fov 360)");
	}

	public function testTargetStraightAboveIsInView() : void{
		//a flying or swimming mob right below its target: no direction to face, it hits
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$above = $this->dummy(0.6, 2.92, 0.5);
		self::assertTrue(self::reachProbe($zombie)($above));
	}

	public function testImmobileSpiderHitsAtBedrockGapOnly() : void{
		$spider = new Spider($this->loc(0.5, 1, 0.5));
		self::immobilize($spider);
		//0.7 between the boxes (spider 1.4 wide, cow 0.9 wide)
		$near = $this->dummy(0.5 + 0.7 + 0.7 + 0.45, 1, 0.5);
		$spider->setAttackTarget($near);
		self::assertNotSame([], $this->hitTicks($near, 40), "the spider hits a target 0.7 blocks from its box");

		$spider2 = new Spider($this->loc(10.5, 1, 0.5));
		self::immobilize($spider2);
		$far = $this->dummy(10.5 + 0.7 + 1.0 + 0.45, 1, 0.5);
		$spider2->setAttackTarget($far);
		self::assertSame([], $this->hitTicks($far, 60), "the spider doesn't hit a target 1 block from its box");
	}

	public function testHoglinAttackIntervals() : void{
		$hoglin = new Hoglin($this->loc(0.5, 1, 0.5));
		$hoglin->setAge(0);
		self::immobilize($hoglin);
		$cow = $this->dummy(2.0, 1, 0.5);
		$hoglin->setAttackTarget($cow);
		$hits = $this->hitTicks($cow, 140);
		self::assertGreaterThanOrEqual(3, count($hits));
		for($i = 1, $count = count($hits); $i < $count; ++$i){
			self::assertSame(Hoglin::ATTACK_INTERVAL, $hits[$i] - $hits[$i - 1], "adult hoglins hit every 2 seconds");
		}

		$baby = new Hoglin($this->loc(10.5, 1, 0.5));
		$baby->setAge(Animal::BABY_AGE);
		self::immobilize($baby);
		$cow2 = $this->dummy(11.5, 1, 0.5);
		$baby->setAttackTarget($cow2);
		$hits = $this->hitTicks($cow2, 80);
		self::assertGreaterThanOrEqual(3, count($hits));
		for($i = 1, $count = count($hits); $i < $count; ++$i){
			self::assertSame(Hoglin::BABY_ATTACK_INTERVAL, $hits[$i] - $hits[$i - 1], "baby hoglins hit every 0.75 seconds");
		}
	}

	public function testCooldownGoesOnWhileTheAttackIsInterrupted() : void{
		//a hoglin fleeing a repellent or panicking for a moment: the 2 s cooldown runs on meanwhile
		$hoglin = new Hoglin($this->loc(0.5, 1, 0.5));
		$hoglin->setAge(0);
		self::immobilize($hoglin);
		$cow = $this->dummy(2.0, 1, 0.5);
		$hoglin->setAttackTarget($cow);
		$pos = $cow->getPosition();
		$health = $cow->getHealth();
		$hits = [];
		for($t = 0; $t < 100 && count($hits) < 2; ++$t){
			if(count($hits) === 1 && $t === $hits[0] + 5){
				$hoglin->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
			}elseif(count($hits) === 1 && $t === $hits[0] + 20){
				$hoglin->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, false);
			}
			$cow->teleport($pos);
			$cow->setMotion(Vector3::zero());
			$this->runTicks(1);
			if($cow->getHealth() < $health){
				$hits[] = $t;
			}
			$health = $cow->getHealth();
		}
		self::assertCount(2, $hits);
		self::assertSame(Hoglin::ATTACK_INTERVAL, $hits[1] - $hits[0], "the attack stopped for 15 ticks doesn't delay the next hit");
	}

	public function testLeapDoesNotDelayTheNextHit() : void{
		//the target steps back after every hit, so that the spider walks or leaps to it again: either way it hits again
		//1 s after the last hit (in Bedrock the leap doesn't interrupt the melee attack and its cooldown)
		$spider = new Spider($this->loc(0.5, 1, 0.5));
		$cow = $this->dummy(2.5, 1, 0.5);
		$spider->setAttackTarget($cow);
		$direction = 1;
		$cowX = 2.5;
		$health = $cow->getHealth();
		$lastHit = null;
		$leaped = false;
		$intervals = [];
		$leapIntervals = 0;
		//the spider turning back: with vanilla ground inertia, the momentum of a leap away from the target can make it
		//arrive a few ticks after its cooldown, so these hits are only checked not to come early
		$turned = false;
		$turnIntervals = [];
		for($t = 0; $t < 1200; ++$t){
			$cow->teleport(new Vector3($cowX, 1, 0.5));
			$cow->setMotion(Vector3::zero());
			$this->runTicks(1);
			foreach($spider->getGoalSelector()->getRunningGoals() as $goal){
				$leaped = $leaped || $goal instanceof LeapAtTargetGoal;
			}
			if($cow->getHealth() < $health){
				if($lastHit !== null){
					if($turned){
						$turnIntervals[] = $t - $lastHit;
					}else{
						$intervals[] = $t - $lastHit;
					}
					if($leaped){
						++$leapIntervals;
					}
				}
				$lastHit = $t;
				$leaped = false;
				$turned = false;
				//2 to 4 blocks away again, in the leap range
				$spiderX = $spider->getLocation()->x;
				if($spiderX + $direction * 3.5 > 18 || $spiderX + $direction * 3.5 < -18){
					$direction = -$direction;
					$turned = true;
				}
				$cowX = $spiderX + $direction * 3.5;
			}
			$health = $cow->getHealth();
		}
		self::assertGreaterThan(10, count($intervals));
		self::assertGreaterThan(3, $leapIntervals, "the spider leaps between some of the hits");
		foreach($intervals as $i => $interval){
			self::assertSame(MeleeAttackGoal::ATTACK_INTERVAL, $interval, "hit $i: one hit per second, leap or not");
		}
		foreach($turnIntervals as $i => $interval){
			self::assertGreaterThanOrEqual(MeleeAttackGoal::ATTACK_INTERVAL, $interval, "hit $i after turning back: not before the cooldown");
		}
	}

	public function testWildWolfBitesItsPrey() : void{
		$wolf = new Wolf($this->loc(0.5, 1, 0.5));
		$sheep = new Sheep($this->loc(4.5, 1, 0.5));
		$sheep->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$sheep->setMaxHealth(1000);
		$sheep->setHealth(1000);
		$wolf->setAttackTarget($sheep);
		$this->runTicks(100);
		self::assertLessThan(1000, $sheep->getHealth(), "the wolf bites");
		self::assertSame(0.0, fmod(1000 - $sheep->getHealth(), 3.0), "wild wolves bite for 3");
	}

	public function testSpiderLeapsWhileAttacking() : void{
		$spider = new Spider($this->loc(0.5, 1, 0.5));
		$cow = $this->dummy(3.5, 1, 0.5);
		$spider->setAttackTarget($cow);
		$leaps = 0;
		$leaping = false;
		$meleeAfterLeap = false;
		for($t = 0; $t < 300; ++$t){
			if($spider->getLocation()->x > 15 && $spider->isOnGround()){
				//back to the start, still on the floor
				$spider->teleport(new Vector3(-15.5, 1, 0.5));
			}
			//keep the target 3 blocks ahead
			$cow->teleport(new Vector3($spider->getLocation()->x + 3, 1, 0.5));
			$this->runTicks(1);
			$running = $spider->getGoalSelector()->getRunningGoals();
			$leapRunning = false;
			$meleeRunning = false;
			foreach($running as $goal){
				$leapRunning = $leapRunning || $goal instanceof LeapAtTargetGoal;
				$meleeRunning = $meleeRunning || ($goal instanceof MeleeAttackGoal);
			}
			if($leapRunning && !$leaping){
				++$leaps;
			}
			if($leaps > 0 && !$leapRunning && $meleeRunning){
				$meleeAfterLeap = true;
			}
			$leaping = $leapRunning;
		}
		self::assertGreaterThan(0, $leaps, "the spider leaps at its target");
		self::assertLessThan(30, $leaps, "now and then, not at every chance");
		self::assertTrue($meleeAfterLeap, "the attack goes on after the leap");
	}

	public function testSpiderJockeyDoesNotLeap() : void{
		$spider = new Spider($this->loc(-15.5, 1, 0.5));
		$rider = new WitherSkeleton($this->loc(-15.5, 2, 0.5));
		self::assertTrue($rider->mount($spider));
		$cow = $this->dummy(-12.5, 1, 0.5);
		$spider->setAttackTarget($cow);
		for($t = 0; $t < 120; ++$t){
			$cow->teleport(new Vector3($spider->getLocation()->x + 3, 1, 0.5));
			$this->runTicks(1);
			self::assertSame($spider, $rider->getVehicle(), "tick $t: the skeleton still rides the spider");
			foreach($spider->getGoalSelector()->getRunningGoals() as $goal){
				self::assertNotInstanceOf(LeapAtTargetGoal::class, $goal, "tick $t: no leap with a rider (Java)");
			}
		}
	}

	/**
	 * @phpstan-return array<string, array{\Closure(Location) : Mob, bool}>
	 */
	public static function fireSpreadProvider() : array{
		//can_spread_on_fire in the behavior pack: zombie, husk, drowned, zombie villager
		return [
			"zombie" => [fn(Location $l) => new Zombie($l), true],
			"husk" => [fn(Location $l) => new Husk($l), true],
			"drowned" => [fn(Location $l) => new Drowned($l), true],
			"zombie villager" => [fn(Location $l) => new ZombieVillager($l), true],
			"spider" => [fn(Location $l) => new Spider($l), false],
			"zombified piglin" => [fn(Location $l) => new ZombifiedPiglin($l), false],
		];
	}

	/**
	 * @dataProvider fireSpreadProvider
	 * @phpstan-param \Closure(Location) : Mob $factory
	 */
	public function testOnlyZombieLikeMobsSpreadFire(\Closure $factory, bool $spreads) : void{
		$this->world->setDifficulty(World::DIFFICULTY_HARD);
		$mob = $factory($this->loc(0.5, 1, 0.5));
		$mob->setMaxHealth(1000);
		$mob->setHealth(1000);
		self::immobilize($mob);
		$cow = $this->dummy(0.5 + $mob->getSize()->getWidth() / 2 + 0.45 + 0.5, 1, 0.5);
		$mob->setAttackTarget($cow);
		$mob->setOnFire(30);
		$hits = $this->hitTicks($cow, 100);
		self::assertGreaterThanOrEqual(4, count($hits));
		self::assertSame($spreads, $cow->isOnFire(), $spreads ? "it sets its target on fire (can_spread_on_fire)" : "it doesn't set its target on fire");
	}

	private static function flag(Entity $entity, int $flag) : bool{
		$flags = $entity->getNetworkProperties()->getAll()[$flag >= 64 ? EntityMetadataProperties::FLAGS2 : EntityMetadataProperties::FLAGS] ?? null;
		self::assertInstanceOf(LongMetadataProperty::class, $flags);
		return ($flags->getValue() & (1 << ($flag % 64))) !== 0;
	}

	/**
	 * @phpstan-return array<string, array{\Closure(Location) : Mob, bool}>
	 */
	public static function angryMobProvider() : array{
		return [
			"spider" => [fn(Location $l) => new Spider($l), true],
			"vindicator" => [fn(Location $l) => new Vindicator($l), true],
			"piglin brute" => [fn(Location $l) => new PiglinBrute($l), true],
			"zoglin" => [fn(Location $l) => new Zoglin($l), true],
			"adult hoglin" => [function(Location $l) : Mob{
				$hoglin = new Hoglin($l);
				$hoglin->setAge(0);
				return $hoglin;
			}, true],
			"baby hoglin" => [function(Location $l) : Mob{
				$hoglin = new Hoglin($l);
				$hoglin->setAge(Animal::BABY_AGE);
				return $hoglin;
			}, false],
			"piglin" => [fn(Location $l) => new Piglin($l), true],
			"silverfish" => [fn(Location $l) => new Silverfish($l), true],
			"dolphin" => [fn(Location $l) => new Dolphin($l), true],
			"aggressive panda" => [function(Location $l) : Mob{
				//only aggressive pandas take a target
				$panda = new Panda($l);
				$panda->setGenes(Panda::GENE_AGGRESSIVE, Panda::GENE_AGGRESSIVE);
				return $panda;
			}, true],
			"polar bear" => [fn(Location $l) => new PolarBear($l), true],
			"llama" => [fn(Location $l) => new Llama($l), true],
			"wild adult nautilus" => [function(Location $l) : Mob{
				$nautilus = new Nautilus($l);
				$nautilus->setAge(0);
				return $nautilus;
			}, true],
			"baby nautilus" => [function(Location $l) : Mob{
				$nautilus = new Nautilus($l);
				$nautilus->setAge(Animal::BABY_AGE);
				return $nautilus;
			}, false],
			"zombie" => [fn(Location $l) => new Zombie($l), false],
			"iron golem" => [fn(Location $l) => new IronGolem($l), false],
		];
	}

	/**
	 * @dataProvider angryMobProvider
	 * @phpstan-param \Closure(Location) : Mob $factory
	 */
	public function testAngryFlagFollowsAttackTarget(\Closure $factory, bool $angry) : void{
		$mob = $factory($this->loc(0.5, 1, 0.5));
		$mob->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$mob->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		$this->runTicks(1);
		self::assertFalse(self::flag($mob, EntityMetadataFlags::ANGRY));
		$cow = $this->dummy(6.5, 1, 0.5);
		$mob->setAttackTarget($cow);
		$this->runTicks(1);
		self::assertSame($angry, self::flag($mob, EntityMetadataFlags::ANGRY));
		$mob->setAttackTarget(null);
		$this->runTicks(1);
		self::assertFalse(self::flag($mob, EntityMetadataFlags::ANGRY));
	}

	public function testTamedNautilusIsNoLongerAngry() : void{
		//only nautilus_wild_adult_calm turns angry: taming it while it targets something clears the flag, even before
		//the target is dropped
		$nautilus = new Nautilus($this->loc(0.5, 1, 0.5));
		$nautilus->setAge(0);
		$nautilus->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$nautilus->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		$cow = $this->dummy(6.5, 1, 0.5);
		$nautilus->setAttackTarget($cow);
		$this->runTicks(1);
		self::assertTrue(self::flag($nautilus, EntityMetadataFlags::ANGRY));

		$player = $this->createMock(Player::class);
		$player->method("getUniqueId")->willReturn(Uuid::uuid4());
		$player->method("isConnected")->willReturn(false);
		//the mock is never spawned: nothing to dispose of when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		$nautilus->setOwner($player);
		self::assertSame($cow, $nautilus->getAttackTarget());
		$this->runTicks(1);
		self::assertFalse(self::flag($nautilus, EntityMetadataFlags::ANGRY), "tamed with a target: not angry");
		$nautilus->setAttackTarget(null);
		$this->runTicks(1);
		self::assertFalse(self::flag($nautilus, EntityMetadataFlags::ANGRY));
		self::assertTrue($nautilus->isTamed());
	}

	/** Arm swings broadcast by the mobs of heldItemProvider(). */
	private static int $armSwings = 0;

	public static function countArmSwing(Animation $animation) : void{
		if($animation instanceof ArmSwingAnimation){
			++self::$armSwings;
		}
	}

	/**
	 * @phpstan-return array<string, array{\Closure(Location) : Mob, int}>
	 */
	public static function heldItemProvider() : array{
		//mobs counting the arm swings they broadcast (never saved: no save ID needed)
		return [
			"vindicator (iron axe)" => [fn(Location $l) => new class($l) extends Vindicator implements NeverSavedWithChunkEntity{
				public function broadcastAnimation(Animation $animation, ?array $targets = null) : void{
					MeleeCombatTest::countArmSwing($animation);
					parent::broadcastAnimation($animation, $targets);
				}
			}, 2],
			"piglin brute (golden axe)" => [fn(Location $l) => new class($l) extends PiglinBrute implements NeverSavedWithChunkEntity{
				public function broadcastAnimation(Animation $animation, ?array $targets = null) : void{
					MeleeCombatTest::countArmSwing($animation);
					parent::broadcastAnimation($animation, $targets);
				}
			}, 2],
			"wither skeleton (stone sword)" => [fn(Location $l) => new class($l) extends WitherSkeleton implements NeverSavedWithChunkEntity{
				public function broadcastAnimation(Animation $animation, ?array $targets = null) : void{
					MeleeCombatTest::countArmSwing($animation);
					parent::broadcastAnimation($animation, $targets);
				}
			}, 2],
			"spider" => [fn(Location $l) => new class($l) extends Spider implements NeverSavedWithChunkEntity{
				public function broadcastAnimation(Animation $animation, ?array $targets = null) : void{
					MeleeCombatTest::countArmSwing($animation);
					parent::broadcastAnimation($animation, $targets);
				}
			}, 1],
			"zombie" => [fn(Location $l) => new class($l) extends Zombie implements NeverSavedWithChunkEntity{
				public function broadcastAnimation(Animation $animation, ?array $targets = null) : void{
					MeleeCombatTest::countArmSwing($animation);
					parent::broadcastAnimation($animation, $targets);
				}
			}, 1],
		];
	}

	/**
	 * BDS 1.26.52 sends ARM_SWING twice on the hit tick for mobs hitting with an item in their main hand, once for the
	 * others (see Mob::attackMelee()).
	 *
	 * @dataProvider heldItemProvider
	 * @phpstan-param \Closure(Location) : Mob $factory
	 */
	public function testSwingsTwiceWithHeldItem(\Closure $factory, int $swings) : void{
		$mob = $factory($this->loc(0.5, 1, 0.5));
		$cow = $this->dummy(2.0, 1, 0.5);
		self::$armSwings = 0;
		self::assertTrue($mob->attackMelee($cow, 2.0));
		self::assertSame($swings, self::$armSwings);
	}
}
