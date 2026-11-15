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
use pocketmine\entity\ai\control\LookControl;
use pocketmine\entity\ai\control\MoveControl;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\animal\Camel;
use pocketmine\entity\animal\CamelHusk;
use pocketmine\entity\animal\Chicken;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\Attribute;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Creaking;
use pocketmine\entity\monster\Enderman;
use pocketmine\entity\monster\Ravager;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\monster\Slime;
use pocketmine\entity\monster\Warden;
use pocketmine\entity\monster\WardenAnger;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\IntMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\player\Player;
use pocketmine\world\Explosion;
use pocketmine\world\Position;
use pocketmine\world\WindBurst;
use function abs;
use function atan2;
use function cos;
use function count;
use function deg2rad;
use function implode;
use function max;
use function min;
use function mt_srand;
use function sin;
use function sprintf;
use const M_PI;

/**
 * How AI-moved mobs steer, turn and jump (vanilla MoveControl, BodyRotationControl, LivingEntity jump delay and
 * knockback), their step heights, and the MOVING flag and HURT_TIME countdown BDS sends for them.
 */
final class EngineMovementTest extends MobTestCase{

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261019);
	}

	private function loc(float $x, float $y, float $z, float $yaw = 0.0) : Location{
		return new Location($x, $y, $z, $this->world, $yaw, 0);
	}

	/**
	 * Turns off the mob's own goals: the test moves it and makes it look around.
	 */
	private function still(Mob $mob) : Mob{
		$mob->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$mob->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		return $mob;
	}

	/**
	 * A cow that doesn't move by itself (not even when pushed) and can take a lot of damage.
	 */
	private function dummy(float $x, float $y, float $z) : Cow{
		$cow = new Cow($this->loc($x, $y, $z));
		$this->still($cow);
		$cow->setMaxHealth(100000);
		$cow->setHealth(100000);
		$cow->getAttributeMap()->get(Attribute::KNOCKBACK_RESISTANCE)?->setValue(1.0);
		return $cow;
	}

	private static function flag(Entity $entity, int $flag) : bool{
		$flags = $entity->getNetworkProperties()->getAll()[$flag >= 64 ? EntityMetadataProperties::FLAGS2 : EntityMetadataProperties::FLAGS] ?? null;
		self::assertInstanceOf(LongMetadataProperty::class, $flags);
		return ($flags->getValue() & (1 << ($flag % 64))) !== 0;
	}

	private static function hurtTime(Entity $entity) : int{
		$property = $entity->getNetworkProperties()->getAll()[EntityMetadataProperties::HURT_TIME] ?? null;
		self::assertInstanceOf(IntMetadataProperty::class, $property);
		return $property->getValue();
	}

	/**
	 * Yaw (vanilla convention, 0 = +Z) of a horizontal direction.
	 */
	private static function yawOf(float $dx, float $dz) : float{
		return atan2($dz, $dx) / M_PI * 180 - 90;
	}

	private static function angle(float $a, float $b) : float{
		return abs(MoveControl::wrapDegrees($a - $b));
	}

	public function testWalkingMobKeepsBodyAlongItsWayWhileHeadLooksAway() : void{
		//walks east, then turns the corner north, looking at a point far to the west-north-west all the time
		$zombie = $this->still(new Zombie($this->loc(0.5, 1, 0.5)));
		$waypoints = [[6.5, 0.5], [6.5, 8.5]];
		$leg = 0;
		$samples = [];
		$lookedAway = 0;
		for($t = 0; $t < 200 && $leg < count($waypoints); ++$t){
			$pos = $zombie->getPosition();
			[$wx, $wz] = $waypoints[$leg];
			if(($pos->x - $wx) ** 2 + ($pos->z - $wz) ** 2 < 0.3 ** 2){
				++$leg;
				continue;
			}
			$zombie->getMoveControl()->setWantedPosition($wx, 1, $wz, 1.0);
			$zombie->getLookControl()->setLookAt(-30.5, 2.6, 20.5, 30.0, 30.0);
			$this->runTicks(1);
			$l = $zombie->getLocation();
			$samples[] = [$l->x, $l->z, $l->yaw, $zombie->getHeadYaw()];
		}
		self::assertSame(count($waypoints), $leg, "the zombie walks the whole way");
		$lines = [];
		for($i = 0, $count = count($samples) - 1; $i < $count; ++$i){
			[$x, $z, $yaw, $head] = $samples[$i];
			[$nx, $nz] = $samples[$i + 1];
			$lines[] = sprintf("%d: %.2f,%.2f yaw %.1f head %.1f", $i, $x, $z, $yaw, $head);
			self::assertLessThanOrEqual(LookControl::MAX_HEAD_BODY_ANGLE + 0.01, self::angle($head, $yaw), "tick $i: the head turned too far from the body");
			if(($nx - $x) ** 2 + ($nz - $z) ** 2 < 0.05 ** 2){
				continue;
			}
			//the body set on this tick is the way the mob goes on the next one
			self::assertLessThan(1.0, self::angle($yaw, self::yawOf($nx - $x, $nz - $z)), "tick $i: the zombie walks sideways:\n" . implode("\n", $lines));
			if(self::angle($head, $yaw) > 45){
				++$lookedAway;
			}
		}
		self::assertGreaterThan(10, $lookedAway, "the head looked away from the way it walked");
	}

	public function testStandingMobTurnsBodyTowardsHeadAfterAWhile() : void{
		$zombie = $this->still(new Zombie($this->loc(0.5, 1, 0.5)));
		$this->runTicks(2);
		//a point at yaw 60, within the head's range of the body (75 degrees)
		$yaw = deg2rad(60);
		$lookX = 0.5 - sin($yaw) * 8;
		$lookZ = 0.5 + cos($yaw) * 8;
		$bodies = [];
		for($t = 0; $t < 40; ++$t){
			$zombie->getLookControl()->setLookAt($lookX, 2.6, $lookZ);
			$this->runTicks(1);
			$bodies[] = $zombie->getLocation()->yaw;
		}
		self::assertEqualsWithDelta(60.0, $zombie->getHeadYaw(), 0.5, "the head looks at the point");
		//the head reaches it after 6 ticks; the body waits until the head has been still for 10 ticks...
		for($t = 0; $t < 16; ++$t){
			self::assertEqualsWithDelta(0.0, $bodies[$t], 0.01, "tick $t: the body turned while the head was still turning or had just stopped");
		}
		//...then turns to face where it looks over the next 10
		for($t = 16; $t < 39; ++$t){
			self::assertGreaterThanOrEqual($bodies[$t] - 0.01, $bodies[$t + 1], "the body turns one way only");
		}
		self::assertGreaterThan(1.0, $bodies[20]);
		self::assertEqualsWithDelta(60.0, $bodies[30], 0.5, "the body faces where the mob looks");
	}

	public function testStandingMobTurnsBodyWhenHeadTurnsFar() : void{
		$zombie = $this->still(new Zombie($this->loc(0.5, 1, 0.5)));
		$this->runTicks(2);
		for($t = 0; $t < 12; ++$t){
			//straight behind it
			$zombie->getLookControl()->setLookAt(0.5, 2.6, -8.5, 30.0, 30.0);
			$this->runTicks(1);
			self::assertLessThanOrEqual(LookControl::MAX_HEAD_BODY_ANGLE + 0.01, self::angle($zombie->getHeadYaw(), $zombie->getLocation()->yaw), "tick $t");
		}
		self::assertEqualsWithDelta(180.0, $zombie->getHeadYaw(), 0.5);
		self::assertEqualsWithDelta(LookControl::MAX_HEAD_BODY_ANGLE, self::angle(180.0, $zombie->getLocation()->yaw), 0.5, "the body turned only as far as the head needs");
	}

	/**
	 * A baby zombie riding a chicken turns to its target behind it: being carried by the chicken doesn't count as walking
	 * (its own move control doesn't run), so its body follows its head like a mob standing still.
	 */
	public function testJockeyTurnsToTargetBehindIt() : void{
		$chicken = new Chicken($this->loc(0.5, 1, 0.5));
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$zombie->setBaby(true);
		self::assertTrue($zombie->mount($chicken));
		$zombie->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		$cow = $this->dummy(0.5, 1, -6.5);
		$zombie->setAttackTarget($cow);
		$facing = 0;
		$lines = [];
		for($t = 0; $t < 30; ++$t){
			$this->runTicks(1);
			$pos = $zombie->getPosition();
			$target = $cow->getPosition();
			$head = $zombie->getHeadYaw();
			$body = $zombie->getLocation()->yaw;
			$lines[] = sprintf("%d: chicken %.1f body %.1f head %.1f", $t, $chicken->getLocation()->yaw, $body, $head);
			self::assertLessThanOrEqual(LookControl::MAX_HEAD_BODY_ANGLE + 0.01, self::angle($head, $body), "tick $t: the head turned too far from the body");
			if($t >= 10 && self::angle($head, self::yawOf($target->x - $pos->x, $target->z - $pos->z)) < 15){
				++$facing;
			}
		}
		self::assertSame($chicken, $zombie->getVehicle());
		self::assertGreaterThanOrEqual(10, $facing, "the rider looks at its target behind it:\n" . implode("\n", $lines));
	}

	/**
	 * Walks the mob towards x = 40 for the given ticks, calling $each before every tick, and returns how far it got.
	 *
	 * @phpstan-param (\Closure(int) : void)|null $each
	 */
	private function walkEast(Mob $mob, int $ticks, ?\Closure $each = null) : float{
		$start = $mob->getPosition()->x;
		for($t = 0; $t < $ticks; ++$t){
			if($each !== null){
				$each($t);
			}
			$pos = $mob->getPosition();
			$mob->getMoveControl()->setWantedPosition(40.5, 1, $pos->z, 1.0);
			$this->runTicks(1);
		}
		return $mob->getPosition()->x - $start;
	}

	public function testBurningMobKeepsWalking() : void{
		$zombie = $this->still(new Zombie($this->loc(-20.5, 1, 0.5)));
		$zombie->setMaxHealth(100);
		$zombie->setHealth(100);
		$this->runTicks(2);
		$expected = $zombie->getMoveControl()->getGroundSpeed(1.0) * 60;
		$zombie->setOnFire(20);
		$distance = $this->walkEast($zombie, 60);
		self::assertLessThan(98.0, $zombie->getHealth(), "the fire hurts it");
		self::assertGreaterThan($expected * 0.9, $distance, "fire damage doesn't stop it");
	}

	public function testMobWithKnockbackResistanceKeepsWalkingWhenHit() : void{
		$zombie = $this->still(new Zombie($this->loc(-20.5, 1, 0.5)));
		$zombie->setMaxHealth(100);
		$zombie->setHealth(100);
		$zombie->getAttributeMap()->get(Attribute::KNOCKBACK_RESISTANCE)?->setValue(1.0);
		$attacker = $this->dummy(-22.5, 1, 0.5);
		$this->runTicks(2);
		$expected = $zombie->getMoveControl()->getGroundSpeed(1.0) * 60;
		$hits = 0;
		$distance = $this->walkEast($zombie, 60, function(int $t) use ($zombie, $attacker, &$hits) : void{
			if($t % 10 === 0){
				$ev = new EntityDamageByEntityEvent($attacker, $zombie, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1);
				$zombie->attack($ev);
				$hits += $ev->isCancelled() ? 0 : 1;
			}
		});
		self::assertSame(6, $hits);
		self::assertGreaterThan($expected * 0.9, $distance, "hits it resists the knockback of don't stop it");
	}

	public function testKnockbackStillCarriesMob() : void{
		$zombie = $this->still(new Zombie($this->loc(-10.5, 1, 0.5)));
		$attacker = $this->dummy(-9.5, 1, 0.5);
		$this->walkEast($zombie, 5);
		$before = $zombie->getPosition()->x;
		$zombie->attack(new EntityDamageByEntityEvent($attacker, $zombie, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		$this->walkEast($zombie, 6);
		self::assertLessThan($before - 0.5, $zombie->getPosition()->x, "the hit knocks it back, away from its way");
		$knocked = $zombie->getPosition()->x;
		$this->walkEast($zombie, 30);
		self::assertGreaterThan($knocked + 1.0, $zombie->getPosition()->x, "then it walks on");
	}

	public function testWardenHitRepeatedlyKeepsClosingIn() : void{
		$warden = new Warden($this->loc(-14.5, 1, 0.5));
		$cow = $this->dummy(3.5, 1, 0.5);
		$this->runTicks(2);
		$warden->increaseAnger($cow, WardenAnger::ANGRY_THRESHOLD + 20);
		//roars first
		for($i = 0; $i < Warden::ROAR_TICKS + 40 && $warden->getAttackTarget() !== $cow; ++$i){
			$this->runTicks(1);
		}
		self::assertSame($cow, $warden->getAttackTarget());
		$start = $warden->getPosition()->x;
		for($t = 0; $t < 40; ++$t){
			if($t % 10 === 0){
				$warden->attack(new EntityDamageByEntityEvent($cow, $warden, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
			}
			$this->runTicks(1);
		}
		self::assertSame(Warden::STATE_NONE, $warden->getState());
		self::assertGreaterThan(6.0, $warden->getPosition()->x - $start, "the warden walks on while it is hit every 10 ticks");
	}

	public function testMobWalkingAlongWallDoesNotJump() : void{
		for($x = -6; $x <= 20; ++$x){
			for($y = 1; $y <= 3; ++$y){
				$this->set($x, $y, 2, VanillaBlocks::STONE());
			}
		}
		$zombie = $this->still(new Zombie($this->loc(0.5, 1, 1.5)));
		$maxY = 1.0;
		for($t = 0; $t < 60; ++$t){
			//diagonally into the wall: it slides along it
			$zombie->getMoveControl()->setWantedPosition(20.5, 1, 8.5, 1.0);
			$this->runTicks(1);
			$maxY = max($maxY, $zombie->getPosition()->y);
		}
		self::assertGreaterThan(3.0, $zombie->getPosition()->x, "it slides along the wall");
		self::assertEqualsWithDelta(1.0, $maxY, 0.001, "it never jumps");
	}

	public function testMobJumpsOnceUpOneBlockStep() : void{
		for($x = 3; $x <= 10; ++$x){
			for($z = -3; $z <= 3; ++$z){
				$this->set($x, 1, $z, VanillaBlocks::STONE());
			}
		}
		$zombie = $this->still(new Zombie($this->loc(0.5, 1, 0.5)));
		$zombie->getNavigation()->moveTo(6.5, 2, 0.5, 1.0);
		$jumps = 0;
		$wasOnGround = true;
		for($t = 0; $t < 80; ++$t){
			$this->runTicks(1);
			$onGround = $zombie->isOnGround();
			if($wasOnGround && !$onGround){
				++$jumps;
			}
			$wasOnGround = $onGround;
		}
		$pos = $zombie->getPosition();
		self::assertEqualsWithDelta(2.0, $pos->y, 0.001, "it gets up the step");
		//the path ends once the mob is within reach of its last waypoint (0.45 blocks), then it slides to a stop
		self::assertEqualsWithDelta(6.5, $pos->x, 0.3);
		self::assertSame(1, $jumps);
	}

	/**
	 * @phpstan-return array<string, array{string}>
	 */
	public static function floorProvider() : array{
		return [
			"stone" => ["stone"],
			"farmland" => ["farmland"],
			"dirt path" => ["dirt path"],
			"soul sand" => ["soul sand"],
		];
	}

	/**
	 * Replaces the stone floor (y = 0) around the origin with the given block: stone, or a block lower than a full block
	 * the mobs stand on (their feet in the floor's row).
	 */
	private function floorOf(string $floor, int $minX = -4, int $minZ = -4, int $maxX = 4, int $maxZ = 4, int $y = 0) : void{
		$block = match($floor){
			"stone" => VanillaBlocks::STONE(),
			"farmland" => VanillaBlocks::FARMLAND(),
			"dirt path" => VanillaBlocks::GRASS_PATH(),
			"soul sand" => VanillaBlocks::SOUL_SAND(),
			default => throw new \InvalidArgumentException("Unknown floor $floor")
		};
		for($x = $minX; $x <= $maxX; ++$x){
			for($z = $minZ; $z <= $maxZ; ++$z){
				$this->world->setBlockAt($x, $y, $z, $block, false);
			}
		}
	}

	/**
	 * @dataProvider floorProvider
	 */
	public function testMeleeMobJumpsOverOneBlockWallToItsTarget(string $floor) : void{
		$this->floorOf($floor);
		for($z = -3; $z <= 3; ++$z){
			$this->set(1, 1, $z, VanillaBlocks::STONE());
		}
		$zombie = new Zombie($this->loc(-1.5, 1, 0.5));
		$zombie->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		//right behind the wall, out of reach of a zombie pressed against it
		$cow = $this->dummy(2.5, 1, 0.5);
		$zombie->setAttackTarget($cow);
		$maxY = 1.0;
		for($t = 0; $t < 200 && $cow->getHealth() >= 100000; ++$t){
			$this->runTicks(1);
			$maxY = max($maxY, $zombie->getPosition()->y);
		}
		self::assertGreaterThan(1.9, $maxY, "the zombie jumps onto the wall");
		self::assertLessThan(100000, $cow->getHealth(), "the zombie reaches and hits its target behind the wall");
	}

	/**
	 * @phpstan-return array<string, array{string}>
	 */
	public static function pushProvider() : array{
		return [
			"sonic boom" => ["sonic boom"],
			"ravager roar" => ["roar"],
			"block explosion" => ["explosion"],
			"wind burst" => ["wind"],
			"mace smash" => ["mace"],
		];
	}

	/**
	 * Pushes which don't go through knockBack() (they call setMotion() after a hit without knockback, or without any
	 * hit) carry a walking mob back like a knockback: its walking doesn't wipe them out on the next tick.
	 *
	 * @dataProvider pushProvider
	 */
	public function testPushWithoutKnockbackCarriesWalkingMob(string $push) : void{
		$zombie = $this->still(new Zombie($this->loc(-10.5, 1, 0.5)));
		$zombie->setMaxHealth(1000);
		$zombie->setHealth(1000);
		$this->walkEast($zombie, 5);
		$pos = $zombie->getPosition();
		$before = $pos->x;
		switch($push){
			case "sonic boom":
				$warden = new Warden($this->loc($pos->x + 5, 1, 0.5));
				$this->still($warden);
				$warden->sonicBoom($zombie);
				break;
			case "roar":
				$ravager = new Ravager($this->loc($pos->x + 2, 1, 0.5));
				$this->still($ravager);
				$ravager->roar();
				break;
			case "explosion":
				(new Explosion(new Position($pos->x + 1.5, 1, 0.5, $this->world), 2.0, VanillaBlocks::STONE()))->explodeB();
				break;
			case "wind":
				WindBurst::apply($this->world, new Vector3($pos->x + 1, 1.5, 0.5), 1.2, 1.22, true);
				break;
			case "mace":
				//a smash near it: it is knocked back without being hit
				$mace = VanillaItems::MACE();
				$player = $this->createMock(Player::class);
				//the mock is never spawned: nothing to dispose of when it is destroyed
				(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
				(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
				$mace->onSmashAttack($player, $this->dummy($pos->x + 3, 1, 0.5), 3.0);
				break;
		}
		$this->walkEast($zombie, 6);
		self::assertLessThan($before - 0.3, $zombie->getPosition()->x, "the $push pushes it back, away from its way");
		$pushed = $zombie->getPosition()->x;
		$this->walkEast($zombie, 30);
		self::assertGreaterThan($pushed + 1.0, $zombie->getPosition()->x, "then it walks on");
	}

	/**
	 * Strafing on a block lower than a full block goes the way asked, sideways or backwards (a pillager backing off from
	 * a target too close), not forwards: these floors are walkable.
	 *
	 * @dataProvider floorProvider
	 */
	public function testStrafingKeepsItsWayOnLowFloor(string $floor) : void{
		$this->floorOf($floor);
		$skeleton = $this->still(new Skeleton($this->loc(0.5, 1, 0.5)));
		$this->runTicks(5);
		self::assertTrue($skeleton->isOnGround());
		$control = $skeleton->getMoveControl();
		//yaw 0 faces +Z, its right is -X
		$control->strafe(0.0, 0.5, 1.0);
		$control->tick();
		self::assertLessThan(-0.01, $control->getVelocityX(), "it strafes to its right on $floor");
		self::assertEqualsWithDelta(0.0, $control->getVelocityZ(), 0.0001, "not forwards");
		$control->strafe(-0.5, 0.0, 1.0);
		$control->tick();
		self::assertLessThan(-0.01, $control->getVelocityZ(), "it backs off on $floor");
		self::assertEqualsWithDelta(0.0, $control->getVelocityX(), 0.0001);
	}

	/**
	 * A skeleton close to its target strafes backwards on a dirt path instead of walking into it.
	 */
	public function testSkeletonBacksOffOnDirtPath() : void{
		$this->floorOf("dirt path", -12, -12, 12, 12);
		$skeleton = new Skeleton($this->loc(0.5, 1, -5.5));
		$skeleton->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		$skeleton->setMaxHealth(1000);
		$skeleton->setHealth(1000);
		$cow = $this->dummy(0.5, 1, 1.5);
		$skeleton->setAttackTarget($cow);
		//it walks towards its target until it has seen it for a second, then strafes
		$this->runTicks(25);
		$start = $skeleton->getPosition()->distance($cow->getPosition());
		$closest = $start;
		for($t = 0; $t < 40; ++$t){
			$this->runTicks(1);
			$closest = min($closest, $skeleton->getPosition()->distance($cow->getPosition()));
		}
		self::assertGreaterThan(1.5, $closest, "it never walks into its target");
		self::assertGreaterThan($start + 1.0, $skeleton->getPosition()->distance($cow->getPosition()), "it backs off");
	}

	/**
	 * @phpstan-return array<string, array{bool, string}>
	 */
	public static function strafeEdgeProvider() : array{
		return [
			"ledge" => [true, "stone"],
			"lava" => [false, "stone"],
			"ledge, dirt path" => [true, "dirt path"],
			"lava, farmland" => [false, "farmland"],
		];
	}

	/**
	 * A skeleton circle-strafing a target close to it on a 7x7 area, raised 3 blocks (a ledge all around) or ringed
	 * with lava, never strafes off it, standing on stone or on a block lower than a full block.
	 *
	 * @dataProvider strafeEdgeProvider
	 */
	public function testSkeletonDoesNotStrafeOffWalkableGround(bool $ledge, string $floor) : void{
		for($x = -6; $x <= 6; ++$x){
			for($z = -6; $z <= 6; ++$z){
				$inside = $x >= -3 && $x <= 3 && $z >= -3 && $z <= 3;
				if($ledge && $inside){
					for($y = 1; $y <= 3; ++$y){
						$this->world->setBlockAt($x, $y, $z, VanillaBlocks::STONE(), false);
					}
				}elseif(!$ledge && !$inside){
					$this->world->setBlockAt($x, 1, $z, VanillaBlocks::LAVA(), false);
				}
			}
		}
		$this->floorOf($floor, -3, -3, 3, 3, $ledge ? 3 : 0);
		$y = $ledge ? 4 : 1;
		$skeleton = new Skeleton($this->loc(0.5, $y, -1.5));
		$skeleton->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		$skeleton->setMaxHealth(1000);
		$skeleton->setHealth(1000);
		$cow = $this->dummy(0.5, $y, 1.5);
		$skeleton->setAttackTarget($cow);
		$strafed = 0;
		for($t = 0; $t < 400; ++$t){
			$this->runTicks(1);
			$pos = $skeleton->getPosition();
			self::assertTrue($pos->x > -3.0 && $pos->x < 4.0 && $pos->z > -3.0 && $pos->z < 4.0 && $pos->y >= $y - 0.1, sprintf("tick %d: the skeleton left the area: %.2f,%.2f,%.2f", $t, $pos->x, $pos->y, $pos->z));
			if($skeleton->getMoveControl()->isMoving()){
				++$strafed;
			}
		}
		self::assertGreaterThan(100, $strafed, "the skeleton strafes");
	}

	public function testSlimeHopsOutOfPit() : void{
		for($x = -8; $x <= 8; ++$x){
			for($z = -8; $z <= 8; ++$z){
				if($x !== 0 || $z !== 0){
					$this->world->setBlockAt($x, 1, $z, VanillaBlocks::STONE(), false);
				}
			}
		}
		$slime = new Slime($this->loc(0.5, 1, 0.5), null, 1);
		$slime->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		$cow = $this->dummy(6.5, 2, 0.5);
		$slime->setAttackTarget($cow);
		$out = null;
		for($t = 0; $t < 200 && $out === null; ++$t){
			$this->runTicks(1);
			if($slime->isOnGround() && $slime->getPosition()->y >= 1.999){
				$out = $t;
			}
		}
		self::assertNotNull($out, "the slime hops out of the pit");
		for($t = 0; $t < 200; ++$t){
			$this->runTicks(1);
		}
		self::assertLessThan(3.0, abs($cow->getPosition()->x - $slime->getPosition()->x), "it reaches its target");
	}

	public function testStepHeights() : void{
		self::assertSame(1.5625, (new Camel($this->loc(0.5, 1, 0.5)))->getStepHeight());
		self::assertSame(1.5625, (new CamelHusk($this->loc(4.5, 1, 0.5)))->getStepHeight());
		self::assertSame(1.0625, (new Enderman($this->loc(8.5, 1, 0.5)))->getStepHeight());
		self::assertSame(1.0625, (new Creaking($this->loc(12.5, 1, 0.5)))->getStepHeight());
		self::assertEqualsWithDelta(0.6, (new Zombie($this->loc(16.5, 1, 0.5)))->getStepHeight(), 1.0e-6);
	}

	public function testCamelWalksUpBlockAndHalfWithoutJumping() : void{
		for($x = 3; $x <= 9; ++$x){
			for($z = -3; $z <= 3; ++$z){
				$this->set($x, 1, $z, VanillaBlocks::STONE());
				$this->set($x, 2, $z, VanillaBlocks::STONE_SLAB());
			}
		}
		$camel = $this->still(new Camel($this->loc(0.5, 1, 0.5, 270)));
		$airborne = 0;
		for($t = 0; $t < 60; ++$t){
			$camel->getMoveControl()->setWantedPosition(6.5, 2.5, 0.5, 4.0);
			$this->runTicks(1);
			if(!$camel->isOnGround()){
				++$airborne;
			}
		}
		self::assertEqualsWithDelta(2.5, $camel->getPosition()->y, 0.001, "it walks up 1.5 blocks");
		self::assertSame(0, $airborne, "without jumping");
	}

	public function testCamelTakesLessFallDamage() : void{
		$camel = $this->still(new Camel($this->loc(0.5, 9, 0.5)));
		$cow = $this->still(new Cow($this->loc(6.5, 9, 0.5)));
		$cow->setMaxHealth(32);
		$cow->setHealth(32);
		for($t = 0; $t < 60; ++$t){
			$this->runTicks(1);
		}
		self::assertEqualsWithDelta(27.0, $cow->getHealth(), 0.001, "an 8 block fall deals 5 damage");
		self::assertEqualsWithDelta(31.0, $camel->getHealth(), 0.001, "camels take 4 less (damage_sensor)");
	}

	public function testMovingFlagFollowsHorizontalMovement() : void{
		$zombie = $this->still(new Zombie($this->loc(0.5, 1, 0.5)));
		$this->runTicks(3);
		self::assertFalse(self::flag($zombie, EntityMetadataFlags::MOVING));
		$changes = 0;
		$moving = false;
		for($t = 0; $t < 20; ++$t){
			$zombie->getMoveControl()->setWantedPosition(10.5, 1, 0.5, 1.0);
			$this->runTicks(1);
			if(self::flag($zombie, EntityMetadataFlags::MOVING) !== $moving){
				$moving = !$moving;
				++$changes;
			}
		}
		self::assertTrue($moving, "set while it walks");
		self::assertSame(1, $changes, "and stays set");
		$zombie->getMoveControl()->stop();
		for($t = 0; $t < 20; ++$t){
			$this->runTicks(1);
		}
		self::assertFalse(self::flag($zombie, EntityMetadataFlags::MOVING), "cleared once it stops");
	}

	/**
	 * HURT_TIME is above 0 for the same ticks as the BDS countdown from 9 to 0 after a hit (clients only test whether it
	 * is above 0), but changes only twice.
	 */
	public function testHurtTimeAfterHit() : void{
		$zombie = $this->still(new Zombie($this->loc(0.5, 1, 0.5)));
		$this->runTicks(3);
		self::assertSame(0, self::hurtTime($zombie));
		$zombie->attack(new EntityDamageEvent($zombie, EntityDamageEvent::CAUSE_MAGIC, 1));
		$values = [];
		$hurtTicks = 0;
		for($t = 0; $t < 15; ++$t){
			$this->runTicks(1);
			$value = self::hurtTime($zombie);
			if($value > 0){
				++$hurtTicks;
			}
			if(count($values) === 0 ? $value !== 0 : $values[count($values) - 1] !== $value){
				$values[] = $value;
			}
		}
		self::assertSame([9, 0], $values, "the start and the end of the hurt time");
		self::assertSame(9, $hurtTicks, "BDS counts down from 9: above 0 for 9 ticks");
	}
}
