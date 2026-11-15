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
use pocketmine\entity\ai\control\MoveControl;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\animal\Axolotl;
use pocketmine\entity\animal\Bee;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\aquatic\Dolphin;
use pocketmine\entity\Entity;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Breeze;
use pocketmine\entity\monster\Hoglin;
use pocketmine\entity\monster\Ravager;
use pocketmine\entity\monster\Spider;
use pocketmine\entity\monster\Vindicator;
use pocketmine\entity\monster\Warden;
use pocketmine\entity\monster\WardenAnger;
use pocketmine\entity\monster\Zoglin;
use pocketmine\entity\projectile\BreezeWindCharge;
use pocketmine\entity\Zombie;
use pocketmine\math\Vector3;
use function abs;
use function atan2;
use function count;
use function fmod;
use function implode;
use function max;
use function min;
use function mt_srand;
use function sprintf;
use const M_PI;

/**
 * Deterministic chases: the yaw and position of a mob chasing a target, tick by tick, compared with what vanilla mobs
 * do (BDS 1.26.52 captures): they walk up to the target's collision box and stop there facing it, they don't turn back
 * and forth on the spot.
 */
final class MobChaseSmoothnessTest extends MobTestCase{

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261019);
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * Goal evaluations are staggered by entity ID (see {@link Mob::GOAL_EVALUATION_INTERVAL}): the next entity created
	 * gets an ID with this remainder, so that a chase goes the same way whatever tests ran before.
	 */
	private static function alignNextEntityId(int $remainder) : void{
		do{
			$id = Entity::nextRuntimeId();
		}while(($id + 1) % Mob::GOAL_EVALUATION_INTERVAL !== $remainder);
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
	 * Runs the chase and returns one sample per tick: mob position, body yaw, head yaw, target position, target health.
	 *
	 * @phpstan-param (\Closure(int) : ?Vector3)|null $targetPath position of the target at each tick
	 * @phpstan-return list<array{x: float, y: float, z: float, yaw: float, head: float, tx: float, ty: float, tz: float, health: float}>
	 */
	private function chase(Mob $mob, Living $target, int $ticks, ?\Closure $targetPath = null) : array{
		$samples = [];
		for($t = 0; $t < $ticks; ++$t){
			$pos = $targetPath !== null ? $targetPath($t) : null;
			if($pos !== null){
				$target->teleport($pos);
			}
			$this->runTicks(1);
			$l = $mob->getLocation();
			$tl = $target->getLocation();
			$samples[] = ["x" => $l->x, "y" => $l->y, "z" => $l->z, "yaw" => $l->yaw, "head" => $mob->getHeadYaw(), "tx" => $tl->x, "ty" => $tl->y, "tz" => $tl->z, "health" => $target->getHealth()];
		}
		return $samples;
	}

	/**
	 * Returns the ticks at which the body yaw turned by at least $minDelta degrees in the opposite direction of a turn
	 * of at least $minDelta degrees in the 3 ticks before, while the mob moved less than half a block: a mob turning
	 * back and forth on the spot ("dancing"). The turns of a mob following a zigzag path come with progress.
	 *
	 * @phpstan-param list<array{x: float, z: float, yaw: float, head: float, ...}> $samples
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	private static function yawReversals(array $samples, string $key = "yaw", float $minDelta = 30.0) : array{
		$reversals = [];
		$lastBig = 0.0;
		$lastBigTick = -100;
		for($i = 1, $count = count($samples); $i < $count; ++$i){
			$delta = MoveControl::wrapDegrees($samples[$i][$key] - $samples[$i - 1][$key]);
			if(abs($delta) < $minDelta){
				continue;
			}
			if($i - $lastBigTick <= 3 && ($delta > 0) !== ($lastBig > 0)){
				$before = $samples[$lastBigTick - 1];
				$moved = ($samples[$i]["x"] - $before["x"]) ** 2 + ($samples[$i]["z"] - $before["z"]) ** 2;
				if($moved < 0.5 ** 2){
					$reversals[] = $i;
				}
			}
			$lastBig = $delta;
			$lastBigTick = $i;
		}
		return $reversals;
	}

	/**
	 * @phpstan-param list<array{yaw: float, ...}> $samples
	 */
	private static function yawTravel(array $samples, int $from, int $to, string $key = "yaw") : float{
		$sum = 0.0;
		for($i = $from + 1; $i <= $to; ++$i){
			$sum += abs(MoveControl::wrapDegrees($samples[$i][$key] - $samples[$i - 1][$key]));
		}
		return $sum;
	}

	/**
	 * @phpstan-param list<array{x: float, y: float, z: float, yaw: float, head: float, tx: float, ty: float, tz: float, health: float}> $samples
	 */
	private static function describe(array $samples, int $from = 0, int $to = 40) : string{
		$lines = [];
		for($i = $from; $i < $to && $i < count($samples); ++$i){
			$s = $samples[$i];
			$lines[] = sprintf("%d: %.2f,%.2f,%.2f yaw %.1f head %.1f target %.2f,%.2f,%.2f", $i, $s["x"], $s["y"], $s["z"], $s["yaw"], $s["head"], $s["tx"], $s["ty"], $s["tz"]);
		}
		return implode("\n", $lines);
	}

	/**
	 * Yaw (vanilla convention, 0 = +Z) from a point to another.
	 */
	private static function yawTo(float $fromX, float $fromZ, float $toX, float $toZ) : float{
		return atan2($toZ - $fromZ, $toX - $fromX) / M_PI * 180 - 90;
	}

	public function testSpiderChasingTargetOnFlatGroundDoesNotSpin() : void{
		$spider = new Spider($this->loc(0.5, 1, 0.5));
		$cow = $this->dummy(6.5, 1, 0.5);
		$spider->setAttackTarget($cow);
		$samples = $this->chase($spider, $cow, 120);
		self::assertSame([], self::yawReversals($samples), "the spider turns back and forth:\n" . self::describe($samples, 40, 120));
		self::assertLessThan(100000, $cow->getHealth(), "the spider hits its target");
		//it never walks into its target: the collision boxes at most touch (vanilla mobs push each other apart)
		$contact = ($spider->getSize()->getWidth() + $cow->getSize()->getWidth()) / 2;
		foreach($samples as $i => $s){
			self::assertGreaterThan($contact - 0.05, max(abs($s["tx"] - $s["x"]), abs($s["tz"] - $s["z"])), "tick $i: the spider is inside its target");
		}
	}

	public function testSpiderNextToFidgetingTargetKeepsFacingIt() : void{
		//like a player standing next to the spider and moving a little (or pushed by the spider's collision box)
		$spider = new Spider($this->loc(0.5, 1, 0.5));
		$cow = $this->dummy(3.5, 1, 0.5);
		$spider->setAttackTarget($cow);
		$samples = $this->chase($spider, $cow, 100, fn(int $t) => new Vector3(3.5 + (($t * 7) % 5) * 0.03, 1, 0.5 + (($t * 3) % 5) * 0.03));
		self::assertSame([], self::yawReversals($samples), "the spider turns back and forth:\n" . self::describe($samples, 0, 60));
		self::assertSame([], self::yawReversals($samples, "head", 25.0), "the spider's head swings back and forth:\n" . self::describe($samples, 0, 60));
		self::assertLessThan(20.0, self::yawTravel($samples, 30, 99), "the spider's body keeps turning:\n" . self::describe($samples, 30, 99));
		$last = $samples[99];
		self::assertLessThan(30.0, abs(MoveControl::wrapDegrees($last["head"] - self::yawTo($last["x"], $last["z"], $last["tx"], $last["tz"]))), "the spider looks at its target");
	}

	public function testSpiderClimbsWallToTargetOnLedge() : void{
		for($x = -3; $x <= 3; ++$x){
			for($y = 1; $y <= 3; ++$y){
				$this->set($x, $y, 6, VanillaBlocks::STONE());
				$this->set($x, $y, 7, VanillaBlocks::STONE());
			}
		}
		//with an ID of remainder 0 its goals are evaluated on the ticks where it turns back once, at the edge of the
		//ledge, after its target fell off (the same before entity pushing): left to path smoothing (AI1-06)
		self::alignNextEntityId(1);
		$spider = new Spider($this->loc(0.5, 1, 0.5));
		$cow = $this->dummy(0.5, 4, 7.5);
		$spider->setAttackTarget($cow);
		$samples = $this->chase($spider, $cow, 160);
		self::assertSame([], self::yawReversals($samples), "the spider turns back and forth:\n" . self::describe($samples, 30, 160));
		$reached = false;
		foreach($samples as $s){
			if($s["y"] >= 3.9 && max(abs($s["tx"] - $s["x"]), abs($s["tz"] - $s["z"])) < 2.0){
				$reached = true;
				break;
			}
		}
		self::assertTrue($reached, "the spider climbs to its target:\n" . self::describe($samples, 20, 160));
		self::assertLessThan(100000, $cow->getHealth(), "the spider hits its target on the ledge");
	}

	public function testSpiderClimbsToTargetHighOnWallFace() : void{
		//like a player on a ladder or vines: in the air next to the wall, well above the spider
		for($x = -3; $x <= 3; ++$x){
			for($y = 1; $y <= 9; ++$y){
				$this->set($x, $y, 6, VanillaBlocks::STONE());
			}
		}
		$spider = new Spider($this->loc(0.5, 1, 4.5));
		$cow = $this->dummy(0.5, 6, 5.5);
		$spider->setAttackTarget($cow);
		$samples = $this->chase($spider, $cow, 160, fn(int $t) => new Vector3(0.5, 6, 5.5));
		$maxY = 0.0;
		foreach($samples as $s){
			$maxY = max($maxY, $s["y"]);
		}
		self::assertGreaterThan(4.0, $maxY, "the spider climbs the wall to its target:\n" . self::describe($samples, 0, 80));
		self::assertLessThan(100000, $cow->getHealth(), "the spider hits its target on the wall:\n" . self::describe($samples, 0, 160));
	}

	/**
	 * A pool of water x -4..4, z -4..4, y 1..9, walled with stone.
	 */
	private function pool() : void{
		for($x = -5; $x <= 5; ++$x){
			for($z = -5; $z <= 5; ++$z){
				$wall = $x === -5 || $x === 5 || $z === -5 || $z === 5;
				for($y = 1; $y <= 10; ++$y){
					if($wall){
						$this->world->setBlockAt($x, $y, $z, VanillaBlocks::STONE(), false);
					}elseif($y <= 9){
						$this->world->setBlockAt($x, $y, $z, VanillaBlocks::WATER(), false);
					}
				}
			}
		}
	}

	/**
	 * @phpstan-return array<string, array{\Closure(Location) : Mob, float, float}>
	 */
	public static function swimmerVerticalOffsetProvider() : array{
		$dolphin = fn(Location $l) => new Dolphin($l);
		$axolotl = fn(Location $l) => new Axolotl($l);
		//(nautiluses don't settle at their target: aquatic_charge_attack dashes through it, see NautilusAiTest)
		return [
			"dolphin, target below" => [$dolphin, 6.0, 2.0],
			"dolphin, target above" => [$dolphin, 2.0, 5.0],
			"axolotl, target below" => [$axolotl, 6.0, 2.0],
			"axolotl, target above" => [$axolotl, 2.0, 5.0],
		];
	}

	/**
	 * A swimming target (in water: never on the ground) straight above or below a swimming mob.
	 *
	 * @dataProvider swimmerVerticalOffsetProvider
	 * @phpstan-param \Closure(Location) : Mob $factory
	 */
	public function testSwimmingMobReachesTargetStraightAboveOrBelow(\Closure $factory, float $mobY, float $targetY) : void{
		$this->pool();
		$mob = $factory($this->loc(0.6, $mobY, 0.5));
		$cow = $this->dummy(0.5, $targetY, 0.5);
		$mob->setAttackTarget($cow);
		$samples = $this->chase($mob, $cow, 120, fn(int $t) => new Vector3(0.5, $targetY, 0.5));
		self::assertLessThan(100000, $cow->getHealth(), "the mob swims to its target and hits it:\n" . self::describe($samples, 0, 120));
		//straight above or below it: it stops where the collision boxes touch vertically. Its momentum in the water may
		//carry it a little into the target box while it brakes, never deep, and it settles at the contact height; or the
		//two boxes, overlapping a little, push each other apart and it ends up beside the target, touching it
		$mobHeight = $mob->getSize()->getHeight();
		$targetHeight = $cow->getSize()->getHeight();
		$contact = ($mob->getSize()->getWidth() + $cow->getSize()->getWidth()) / 2;
		foreach($samples as $i => $s){
			if(abs($s["ty"] - $targetY) > 0.05){
				//a hit knocked the target about for this tick
				continue;
			}
			$dy = $s["ty"] - $s["y"];
			$tolerance = $i >= 80 ? 0.1 : 0.3;
			$beside = max(abs($s["tx"] - $s["x"]), abs($s["tz"] - $s["z"])) > $contact - $tolerance;
			self::assertTrue($beside || $dy > $mobHeight - $tolerance || -$dy > $targetHeight - $tolerance, "tick $i: the mob is inside its target:\n" . self::describe($samples, 0, 120));
		}
	}

	public function testBeeFliesUpToAirborneTargetAboveIt() : void{
		$bee = new Bee($this->loc(0.6, 2, 0.5));
		$cow = $this->dummy(0.5, 5, 0.5);
		$bee->becomeAngryAt($cow);
		$samples = $this->chase($bee, $cow, 120, fn(int $t) => new Vector3(0.5, 5, 0.5));
		self::assertLessThan(100000, $cow->getHealth(), "the bee flies up to its target and stings it:\n" . self::describe($samples, 0, 120));
	}

	/**
	 * Raises the ground to y = $top + 1 around (-8..8, -8..8), except for the given open blocks.
	 *
	 * @phpstan-param list<array{int, int, int}> $open
	 */
	private function raisedGround(int $top, array $open) : void{
		for($x = -8; $x <= 8; ++$x){
			for($z = -8; $z <= 8; ++$z){
				for($y = 1; $y <= $top; ++$y){
					$this->world->setBlockAt($x, $y, $z, VanillaBlocks::STONE(), false);
				}
			}
		}
		foreach($open as [$x, $y, $z]){
			$this->world->setBlockAt($x, $y, $z, VanillaBlocks::AIR(), false);
		}
	}

	public function testZombieDropsIntoPitToTarget() : void{
		$this->raisedGround(2, [[0, 1, 0], [0, 2, 0]]);
		$zombie = new Zombie($this->loc(0.5, 3, -4.5));
		$cow = $this->dummy(0.5, 1, 0.5);
		$zombie->setAttackTarget($cow);
		$samples = $this->chase($zombie, $cow, 160);
		self::assertLessThan(100000, $cow->getHealth(), "the zombie gets down to its target in the pit:\n" . self::describe($samples, 0, 160));
	}

	public function testZombieFollowsTargetDownShaftIntoTunnel() : void{
		//a 3 deep shaft at 0,0 with a 2 high tunnel going east at its bottom, the zombie comes from the north
		$open = [[0, 1, 0], [0, 2, 0], [0, 3, 0]];
		for($x = 1; $x <= 5; ++$x){
			$open[] = [$x, 1, 0];
			$open[] = [$x, 2, 0];
		}
		$this->raisedGround(3, $open);
		$zombie = new Zombie($this->loc(0.5, 4, -4.5));
		$cow = $this->dummy(5.5, 1, 0.5);
		$zombie->setAttackTarget($cow);
		$samples = $this->chase($zombie, $cow, 200);
		$minY = 100.0;
		foreach($samples as $s){
			$minY = min($minY, $s["y"]);
		}
		self::assertLessThan(2.0, $minY, "the zombie drops down the shaft:\n" . self::describe($samples, 0, 200));
		self::assertLessThan(100000, $cow->getHealth(), "the zombie follows its target into the tunnel:\n" . self::describe($samples, 0, 200));
	}

	public function testSpiderGoesAroundCorner() : void{
		//an L-shaped wall between the spider and its target, too high to climb over quickly
		for($y = 1; $y <= 5; ++$y){
			for($x = -2; $x <= 4; ++$x){
				$this->set($x, $y, 4, VanillaBlocks::STONE());
			}
			for($z = 4; $z <= 9; ++$z){
				$this->set(4, $y, $z, VanillaBlocks::STONE());
			}
		}
		$spider = new Spider($this->loc(1.5, 1, 1.5));
		$cow = $this->dummy(1.5, 1, 7.5);
		$spider->setAttackTarget($cow);
		$samples = $this->chase($spider, $cow, 200);
		self::assertSame([], self::yawReversals($samples), "the spider turns back and forth:\n" . self::describe($samples, 0, 200));
		self::assertLessThan(100000, $cow->getHealth(), "the spider reaches and hits its target:\n" . self::describe($samples, 0, 200));
	}

	public function testSpiderFollowsWalkingTarget() : void{
		$spider = new Spider($this->loc(0.5, 1, 0.5));
		$cow = $this->dummy(4.5, 1, 0.5);
		$spider->setAttackTarget($cow);
		//walks around a 8x8 square at 0.15 blocks per tick (a walking player is 0.22)
		$path = function(int $t) : Vector3{
			$d = fmod($t * 0.15, 32);
			return match(true){
				$d < 8 => new Vector3(4.5 + $d, 1, 0.5),
				$d < 16 => new Vector3(12.5, 1, 0.5 + $d - 8),
				$d < 24 => new Vector3(12.5 - ($d - 16), 1, 8.5),
				default => new Vector3(4.5, 1, 8.5 - ($d - 24))
			};
		};
		$samples = $this->chase($spider, $cow, 240, $path);
		self::assertSame([], self::yawReversals($samples), "the spider turns back and forth:\n" . self::describe($samples, 0, 240));
	}

	/**
	 * @phpstan-return list<array{string, \Closure(Location) : Mob}>
	 */
	public static function meleeMobProvider() : array{
		return [
			["zombie", fn(Location $l) => new Zombie($l)],
			["spider", fn(Location $l) => new Spider($l)],
			["iron golem", fn(Location $l) => new IronGolem($l)],
			["hoglin", fn(Location $l) => new Hoglin($l)],
			["zoglin", fn(Location $l) => new Zoglin($l)],
			["vindicator", fn(Location $l) => new Vindicator($l)],
		];
	}

	/**
	 * @dataProvider meleeMobProvider
	 * @phpstan-param \Closure(Location) : Mob $factory
	 */
	public function testMeleeMobFacesTargetFromOutsideItOnEveryHit(string $name, \Closure $factory) : void{
		$mob = $factory($this->loc(0.5, 1, 0.5));
		$cow = $this->dummy(5.5, 1, 0.5);
		$mob->setAttackTarget($cow);
		$samples = $this->chase($mob, $cow, 160);
		$this->assertHitsFromOutsideFacingTarget($name, $mob, $cow, $samples, 3);
	}

	/**
	 * @phpstan-param list<array{x: float, y: float, z: float, yaw: float, head: float, tx: float, ty: float, tz: float, health: float}> $samples
	 */
	private function assertHitsFromOutsideFacingTarget(string $name, Mob $mob, Living $target, array $samples, int $minHits) : void{
		$contact = ($mob->getSize()->getWidth() + $target->getSize()->getWidth()) / 2;
		$hits = 0;
		for($i = 1, $count = count($samples); $i < $count; ++$i){
			if($samples[$i]["health"] >= $samples[$i - 1]["health"]){
				continue;
			}
			++$hits;
			//the position before the knockback of the hit
			$s = $samples[$i - 1];
			$gap = max(abs($s["tx"] - $s["x"]), abs($s["tz"] - $s["z"]));
			self::assertGreaterThan($contact - 0.05, $gap, "$name hit $hits at tick $i from inside its target:\n" . self::describe($samples, max(0, $i - 20), $i + 1));
			$toTarget = self::yawTo($s["x"], $s["z"], $s["tx"], $s["tz"]);
			self::assertLessThan(35.0, abs(MoveControl::wrapDegrees($s["head"] - $toTarget)), "$name hit $hits at tick $i without looking at its target:\n" . self::describe($samples, max(0, $i - 20), $i + 1));
			self::assertLessThan(60.0, abs(MoveControl::wrapDegrees($s["yaw"] - $toTarget)), "$name hit $hits at tick $i with its body turned away:\n" . self::describe($samples, max(0, $i - 20), $i + 1));
		}
		self::assertGreaterThanOrEqual($minHits, $hits, "$name should hit its target");
		self::assertSame([], self::yawReversals($samples), "$name turns back and forth:\n" . self::describe($samples, 0, count($samples)));
	}

	public function testWardenFacesTargetFromOutsideItOnEveryHit() : void{
		$warden = new Warden($this->loc(0.5, 1, 0.5));
		$cow = $this->dummy(5.5, 1, 0.5);
		$this->runTicks(2);
		$warden->increaseAnger($cow, WardenAnger::ANGRY_THRESHOLD + 20);
		//roars first
		for($i = 0; $i < Warden::ROAR_TICKS + 40 && $warden->getAttackTarget() !== $cow; ++$i){
			$this->runTicks(1);
		}
		self::assertSame($cow, $warden->getAttackTarget());
		$samples = $this->chase($warden, $cow, 120);
		$this->assertHitsFromOutsideFacingTarget("warden", $warden, $cow, $samples, 4);
		//one hit a second, vanilla timing
		$hitTicks = [];
		for($i = 1, $count = count($samples); $i < $count; ++$i){
			if($samples[$i]["health"] < $samples[$i - 1]["health"]){
				$hitTicks[] = $i;
			}
		}
		for($i = 1, $count = count($hitTicks); $i < $count; ++$i){
			self::assertSame(20, $hitTicks[$i] - $hitTicks[$i - 1]);
		}
		self::assertNotSame(Warden::STATE_SONIC_BOOM, $warden->getState(), "no sonic boom at a target it hits in melee");
	}

	public function testMobStopsOnReachedPositionWithoutTurningBack() : void{
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$zombie->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$zombie->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		$this->runTicks(2);
		$samples = [];
		for($t = 0; $t < 40; ++$t){
			$zombie->getMoveControl()->setWantedPosition(2.05, 1, 0.5, 1.0);
			$this->runTicks(1);
			$l = $zombie->getLocation();
			$samples[] = ["x" => $l->x, "y" => $l->y, "z" => $l->z, "yaw" => $l->yaw, "head" => $zombie->getHeadYaw(), "tx" => 2.05, "ty" => 1.0, "tz" => 0.5, "health" => 0.0];
		}
		self::assertSame([], self::yawReversals($samples), self::describe($samples));
		$last = $samples[39];
		self::assertEqualsWithDelta(2.05, $last["x"], 0.011, "it stops on the wanted position");
		self::assertEqualsWithDelta(270.0, $last["yaw"], 0.5, "it still faces the way it walked");
		self::assertLessThan(0.5, self::yawTravel($samples, 20, 39), "no turning once there:\n" . self::describe($samples));
	}

	public function testRavagerBitesWithDelayedAttack() : void{
		$ravager = new Ravager($this->loc(0.5, 1, 0.5));
		$cow = $this->dummy(3.5, 1, 0.5);
		$ravager->setAttackTarget($cow);
		$starts = [];
		$ends = [];
		$hits = [];
		$attacking = false;
		$health = $cow->getHealth();
		for($t = 0; $t < 120; ++$t){
			$this->runTicks(1);
			if($ravager->isDelayedAttacking() !== $attacking){
				$attacking = $ravager->isDelayedAttacking();
				if($attacking){
					$starts[] = $t;
				}else{
					$ends[] = $t;
				}
			}
			if($cow->getHealth() < $health){
				$hits[] = $t;
				$health = $cow->getHealth();
			}
		}
		self::assertGreaterThanOrEqual(3, count($starts), "the ravager bites");
		//BDS 1.26.52: hit 7 ticks after the start, attack over after 15, next bite 31 ticks after the previous one
		foreach($hits as $i => $hit){
			self::assertSame($starts[$i] + Ravager::ATTACK_HIT_TICK, $hit, "bite $i lands halfway through the attack");
		}
		self::assertCount(count($starts), $hits);
		foreach($ends as $i => $end){
			self::assertSame($starts[$i] + Ravager::ATTACK_DURATION, $end);
		}
		for($i = 1, $count = count($starts); $i < $count; ++$i){
			self::assertSame(Ravager::ATTACK_INTERVAL, $starts[$i] - $starts[$i - 1]);
		}
	}

	public function testBreezeShowsShootingAndLeapingToClients() : void{
		$breeze = new Breeze($this->loc(0.5, 1, 0.5));
		$cow = $this->dummy(8.5, 1, 0.5);
		$breeze->setAttackTarget($cow);
		$chargeStart = null;
		$chargeEnd = null;
		$shot = null;
		$leapStart = null;
		$leapEnd = null;
		$leapAirborne = false;
		for($t = 0; $t < 400 && ($chargeEnd === null || $leapEnd === null); ++$t){
			$this->runTicks(1);
			if($chargeStart === null && $breeze->isChargingShot()){
				$chargeStart = $t;
			}
			if($shot === null && $chargeStart !== null){
				foreach($this->world->getEntities() as $entity){
					if($entity instanceof BreezeWindCharge){
						$shot = $t;
						break;
					}
				}
			}
			if($chargeStart !== null && $chargeEnd === null && !$breeze->isChargingShot()){
				$chargeEnd = $t;
			}
			if($leapStart === null && $breeze->isLeaping()){
				$leapStart = $t;
			}
			if($leapStart !== null && $leapEnd === null){
				$leapAirborne = $leapAirborne || !$breeze->isOnGround();
				if(!$breeze->isLeaping()){
					$leapEnd = $t;
					self::assertTrue($breeze->isOnGround(), "the leap ends on landing");
				}
			}
		}
		self::assertNotNull($chargeStart, "the breeze winds up a shot");
		self::assertNotNull($shot, "the breeze shoots");
		self::assertNotNull($chargeEnd);
		//BDS 1.26.52: the shooting flag is set about 16 ticks before the wind charge and cleared 4 ticks after it
		self::assertSame(15, $shot - $chargeStart);
		self::assertSame(4, $chargeEnd - $shot);
		self::assertNotNull($leapStart, "the breeze leaps");
		self::assertNotNull($leapEnd, "the breeze lands");
		self::assertTrue($leapAirborne);
	}
}
