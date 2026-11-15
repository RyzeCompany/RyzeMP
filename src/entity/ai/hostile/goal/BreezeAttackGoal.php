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

namespace pocketmine\entity\ai\hostile\goal;

use pocketmine\block\BlockTypeIds;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\navigation\RandomPos;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Breeze;
use pocketmine\math\Vector3;
use function atan2;
use function cos;
use function count;
use function deg2rad;
use function floor;
use function min;
use function mt_rand;
use function shuffle;
use function sin;
use function sqrt;

/**
 * Breeze combat (Bedrock breeze.json): behavior.fire_at_target (0.75 s aim, wind charge, 0.2 s recovery, 0.5 s
 * cooldown, within 16 blocks, only when standing), behavior.jump_around_target and behavior.move_around_target (walks
 * to 4-8 blocks from the target).
 *
 * jump_around_target: when the target is 4-20 blocks away and the breeze stands on the ground or in water (not in lava)
 * with 4 blocks of free space above it, it picks a landing spot 4-8 blocks from the target on its own side (within 45
 * degrees), snapped to the surface within 10 blocks, gets ready for 0.5 s and leaps there at one of the jump angles
 * (40, 55, 60, 75 or 80 degrees, the first one reaching it with at most 1.4 blocks per tick). The next leap may start
 * 0.5 s after the landing, or 0.1 s if it was hurt within the last 2 s (it leaps away from what hit it).
 */
class BreezeAttackGoal extends Goal{
	public const SHOOT_RANGE = 16.0;
	/** Farthest target distance for a leap (valid_distance_to_target max). */
	public const JUMP_RANGE = 20.0;
	/** Closest target distance for a leap (valid_distance_to_target min). */
	public const JUMP_MIN_RANGE = 4.0;
	public const PRE_SHOOT_TICKS = 15;
	public const POST_SHOOT_TICKS = 4;
	public const SHOOT_COOLDOWN_TICKS = 10;
	public const JUMP_COOLDOWN_TICKS = 10;
	public const JUMP_COOLDOWN_WHEN_HURT_TICKS = 2;
	/** Damage within this many ticks counts for the short jump cooldown (last_hurt_duration). */
	public const LAST_HURT_TICKS = 40;
	public const PREPARE_JUMP_TICKS = 10;
	public const MAX_JUMP_VELOCITY = 1.4;
	public const REQUIRED_VERTICAL_SPACE = 4;
	public const SNAP_TO_SURFACE_RANGE = 10;
	public const LANDING_MIN_DISTANCE = 4.0;
	public const LANDING_MAX_DISTANCE = 8.0;
	public const JUMP_ANGLES = [40.0, 55.0, 60.0, 75.0, 80.0];

	/** Drag of the breeze in the air (Living), for the leap trajectory. */
	private const AIR_DRAG = 0.02;

	private const PHASE_NONE = 0;
	private const PHASE_PREPARE = 1;
	private const PHASE_LEAP = 2;

	private int $shootTicks = -1;
	private int $shootCooldown = 0;
	/** Ticks since the last landing (or failed leap attempt). */
	private int $ticksSinceJump = 0;
	private int $jumpPhase = self::PHASE_NONE;
	private int $prepareTicks = 0;
	private ?Vector3 $jumpVelocity = null;

	public function __construct(
		protected Breeze $breeze
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK | self::FLAG_JUMP;
	}

	public function canStart() : bool{
		return $this->breeze->getSensing()->isValidTarget($this->breeze->getAttackTarget(), Breeze::FOLLOW_RANGE);
	}

	public function stop() : void{
		$this->shootTicks = -1;
		$this->breeze->setChargingShot(false);
		if($this->jumpPhase === self::PHASE_PREPARE){
			$this->breeze->setPreparingJump(false);
		}
		$this->jumpPhase = self::PHASE_NONE;
		$this->jumpVelocity = null;
		$this->breeze->getNavigation()->stop();
	}

	public function tick() : void{
		$breeze = $this->breeze;
		$target = $breeze->getAttackTarget();
		if($target === null){
			return;
		}
		$breeze->getLookControl()->setLookAtEntity($target, 30.0, 30.0);
		$distSq = $breeze->getLiveLocation()->distanceSquared(Mob::getLiveLocationOf($target) ?? $target->getLocation());
		$canSee = $breeze->getSensing()->canSee($target);
		if($this->shootCooldown > 0){
			--$this->shootCooldown;
		}
		if($this->ticksSinceJump < self::JUMP_COOLDOWN_TICKS){
			++$this->ticksSinceJump;
		}

		if($this->shootTicks >= 0){
			//aiming, shooting and recovering: stand still
			$breeze->getNavigation()->stop();
			++$this->shootTicks;
			if($this->shootTicks === self::PRE_SHOOT_TICKS && $canSee){
				$breeze->shootWindCharge($target);
			}elseif($this->shootTicks >= self::PRE_SHOOT_TICKS + self::POST_SHOOT_TICKS){
				$this->shootTicks = -1;
				$this->shootCooldown = self::SHOOT_COOLDOWN_TICKS;
				$breeze->setChargingShot(false);
			}
			return;
		}

		if($this->jumpPhase === self::PHASE_PREPARE){
			$breeze->getNavigation()->stop();
			if(++$this->prepareTicks >= self::PREPARE_JUMP_TICKS){
				$this->takeOff();
			}
			return;
		}
		if($this->jumpPhase === self::PHASE_LEAP){
			if($breeze->isLeaping()){
				return;
			}
			//landed: the cooldown counts from here
			$this->jumpPhase = self::PHASE_NONE;
			$this->ticksSinceJump = 0;
		}

		$onGround = $breeze->isOnGround();
		if(!$onGround && !$breeze->isInWater()){
			return;
		}
		if($onGround && $canSee && $this->shootCooldown <= 0 && $distSq <= self::SHOOT_RANGE ** 2){
			$this->shootTicks = 0;
			//the client plays the shooting animation from the wind-up to the end of the shot
			$breeze->setChargingShot(true);
			return;
		}
		if(
			$distSq > self::JUMP_MIN_RANGE ** 2 && $distSq <= self::JUMP_RANGE ** 2 && !$breeze->isInLava() &&
			$this->ticksSinceJump >= $this->getJumpCooldown() && $this->startJump($target)
		){
			return;
		}
		if($onGround && $breeze->getNavigation()->isDone()){
			$tLoc = $target->getLocation();
			$pos = RandomPos::getLandPos($breeze, 8, 3, $distSq < 16 ? $tLoc : null);
			if($pos !== null){
				$breeze->getNavigation()->moveTo($pos->x + 0.5, $pos->y, $pos->z + 0.5, 1.2);
			}
		}
	}

	/**
	 * Returns the ticks between a landing and the next leap: shorter when the breeze was hurt recently.
	 */
	private function getJumpCooldown() : int{
		$breeze = $this->breeze;
		$hurtAgo = $breeze->getWorld()->getServer()->getTick() - $breeze->getLastHurtTick();
		return $hurtAgo >= 0 && $hurtAgo <= self::LAST_HURT_TICKS ? self::JUMP_COOLDOWN_WHEN_HURT_TICKS : self::JUMP_COOLDOWN_TICKS;
	}

	/**
	 * Picks a landing spot and the leap to it, and starts getting ready to leap. Returns false (and waits for the
	 * cooldown again) when there is no room above the breeze, no landing spot or no jump angle reaching it.
	 */
	private function startJump(Living $target) : bool{
		$breeze = $this->breeze;
		$landing = $this->hasVerticalSpace() ? $this->findLanding($target) : null;
		$velocity = $landing !== null ? $this->findJumpVelocity($landing) : null;
		if($velocity === null){
			$this->ticksSinceJump = 0;
			return false;
		}
		$breeze->getNavigation()->stop();
		$this->jumpVelocity = $velocity;
		$this->jumpPhase = self::PHASE_PREPARE;
		$this->prepareTicks = 0;
		$breeze->setPreparingJump(true);
		return true;
	}

	/**
	 * Returns the take-off velocity of the first jump angle (in random order) reaching the landing spot.
	 */
	private function findJumpVelocity(Vector3 $landing) : ?Vector3{
		$from = $this->breeze->getLiveLocation()->asVector3();
		$angles = self::JUMP_ANGLES;
		shuffle($angles);
		foreach($angles as $angle){
			$velocity = self::solveJumpVelocity($from, $landing, $angle, $this->breeze->getGravity(), self::AIR_DRAG, self::MAX_JUMP_VELOCITY);
			if($velocity !== null){
				return $velocity;
			}
		}
		return null;
	}

	private function takeOff() : void{
		$breeze = $this->breeze;
		$velocity = $this->jumpVelocity;
		$this->jumpVelocity = null;
		$this->jumpPhase = self::PHASE_NONE;
		$breeze->setPreparingJump(false);
		$this->ticksSinceJump = 0;
		if($velocity === null || (!$breeze->isOnGround() && !$breeze->isInWater())){
			return;
		}
		$breeze->getNavigation()->stop();
		$breeze->setMotion($velocity);
		$breeze->onJump();
		$this->jumpPhase = self::PHASE_LEAP;
	}
	/**
	 * Returns whether the breeze has the free space above it needed to leap (required_vertical_space), and doesn't
	 * stand in a honey block.
	 */
	private function hasVerticalSpace() : bool{
		$loc = $this->breeze->getLiveLocation();
		$world = $this->breeze->getWorld();
		$x = (int) floor($loc->x);
		$y = (int) floor($loc->y);
		$z = (int) floor($loc->z);
		if($world->getBlockAt($x, $y, $z)->getTypeId() === BlockTypeIds::HONEY_BLOCK){
			return false;
		}
		for($i = 1; $i <= self::REQUIRED_VERTICAL_SPACE; ++$i){
			if(count($world->getBlockAt($x, $y + $i, $z)->getCollisionBoxes()) > 0){
				return false;
			}
		}
		return true;
	}

	/**
	 * Returns a landing spot 4-8 blocks from the target on the breeze's side of it, on the surface within 10 blocks of
	 * the target's height, or null if there is none or it is in lava or fire.
	 */
	private function findLanding(Living $target) : ?Vector3{
		$loc = $this->breeze->getLiveLocation();
		$world = $this->breeze->getWorld();
		$tLoc = $target->getLocation();
		$angle = deg2rad(mt_rand(-45, 45)) + atan2($loc->z - $tLoc->z, $loc->x - $tLoc->x);
		$distance = mt_rand((int) (self::LANDING_MIN_DISTANCE * 10), (int) (self::LANDING_MAX_DISTANCE * 10)) / 10;
		$x = $tLoc->x + cos($angle) * $distance;
		$z = $tLoc->z + sin($angle) * $distance;
		$bx = (int) floor($x);
		$bz = (int) floor($z);
		$by = (int) floor($tLoc->y);
		$landY = null;
		if(count($world->getBlockAt($bx, $by, $bz)->getCollisionBoxes()) === 0){
			for($y = $by; $y >= $by - self::SNAP_TO_SURFACE_RANGE; --$y){
				if(count($world->getBlockAt($bx, $y - 1, $bz)->getCollisionBoxes()) > 0){
					$landY = $y;
					break;
				}
			}
		}else{
			for($y = $by + 1; $y <= $by + self::SNAP_TO_SURFACE_RANGE; ++$y){
				if(count($world->getBlockAt($bx, $y, $bz)->getCollisionBoxes()) === 0){
					$landY = $y;
					break;
				}
			}
		}
		if($landY === null){
			return null;
		}
		$feet = $world->getBlockAt($bx, $landY, $bz)->getTypeId();
		if($feet === BlockTypeIds::LAVA || $feet === BlockTypeIds::FIRE || $feet === BlockTypeIds::SOUL_FIRE){
			return null;
		}
		return new Vector3($x, $landY, $z);
	}

	/**
	 * Returns the take-off velocity to land on $to from $from when leaping at $angleDegrees above the horizon, or null if
	 * it would be faster than $maxVelocity or that angle can't reach it. The ballistic solution (like Java's
	 * LongJumpUtil, aiming half a block short of the spot) is corrected for the drag of the mob's movement, worked out
	 * tick by tick like the mob moves in the air (gravity, then drag, then the move; the breeze leaves the ground when it
	 * takes off, see {@link Breeze::onJump()}).
	 */
	public static function solveJumpVelocity(Vector3 $from, Vector3 $to, float $angleDegrees, float $gravity, float $drag, float $maxVelocity) : ?Vector3{
		$dx = $to->x - $from->x;
		$dz = $to->z - $from->z;
		$horizontal = sqrt($dx * $dx + $dz * $dz);
		if($horizontal < 0.5001){
			return null;
		}
		$r = $horizontal - 0.5;
		$dirX = $dx / $horizontal;
		$dirZ = $dz / $horizontal;
		$dy = $to->y - $from->y;
		$angle = deg2rad($angleDegrees);
		$cos = cos($angle);
		$sin = sin($angle);
		$denominator = $r * sin(2 * $angle) - 2 * $dy * $cos * $cos;
		if($denominator <= 0){
			return null;
		}
		$v0 = sqrt($r * $r * $gravity / $denominator);
		if($v0 > $maxVelocity){
			return null;
		}
		$vh = $v0 * $cos;
		$vy = $v0 * $sin;

		//where the leap really comes back down to the height of the spot
		$friction = 1 - $drag;
		$y = $travelled = 0.0;
		$simVy = $vy;
		$simVh = $vh;
		$reached = null;
		for($tick = 0; $tick < 200; ++$tick){
			$simVy = ($simVy - $gravity) * $friction;
			$simVh *= $friction;
			$nextY = $y + $simVy;
			if($simVy < 0 && $nextY <= $dy){
				if($y < $dy){
					//the top of the leap is lower than the spot
					return null;
				}
				$reached = $travelled + $simVh * min(1.0, ($y - $dy) / ($y - $nextY));
				break;
			}
			$y = $nextY;
			$travelled += $simVh;
		}
		if($reached === null || $reached <= 0){
			return null;
		}
		$vh *= $r / $reached;
		if($vh * $vh + $vy * $vy > $maxVelocity * $maxVelocity){
			return null;
		}
		return new Vector3($dirX * $vh, $vy, $dirZ * $vh);
	}
}
