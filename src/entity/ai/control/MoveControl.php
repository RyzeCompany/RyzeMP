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

namespace pocketmine\entity\ai\control;

use pocketmine\block\Block;
use pocketmine\block\Door;
use pocketmine\block\Fence;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\Mob;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function abs;
use function atan2;
use function ceil;
use function cos;
use function deg2rad;
use function floor;
use function fmod;
use function max;
use function min;
use function sin;
use function sqrt;
use const M_PI;

/**
 * Turns a wanted position (or strafing input) into the horizontal velocity the mob walks with, applied by
 * {@link Mob} during its movement update, and decides when to jump.
 *
 * Speeds follow vanilla: a mob with movement attribute s walking with speed multiplier m reaches a ground speed of
 * (s * m)^2 * 2.1586 blocks per tick, the steady state of Java's travel() on a block with friction 0.6. Mob::setSpeed()
 * sets both the speed and the forward input to s * m, aiStep() scales the input by 0.98 (INPUT_FRICTION),
 * getFrictionInfluencedSpeed() returns the speed unchanged for friction <= 0.6, and the velocity keeps 0.6 * 0.91 of
 * itself every tick: the step per tick settles at 0.98 * (s * m)^2 / (1 - 0.546).
 */
class MoveControl{
	protected const STATE_WAIT = 0;
	protected const STATE_MOVE_TO = 1;
	protected const STATE_STRAFE = 2;
	protected const STATE_DRIVE = 3;

	/** Steady ground speed factor, see class doc. */
	public const GROUND_SPEED_FACTOR = 0.98 / (1 - 0.6 * 0.91);
	private const MAX_SPEED = 0.6;
	private const MAX_TURN_DEGREES = 90.0;
	/**
	 * The body doesn't turn towards a wanted position closer than this (horizontally): the direction to a point a few
	 * hundredths of a block away is noise (the mob slid past it, the target nudged it), and following it turned the
	 * mob around by up to 90 degrees a tick, back and forth, without going anywhere.
	 */
	public const MIN_TURN_DISTANCE = 0.1;
	/** Highest a jump takes a mob above its feet (vanilla jump: 0.42 blocks per tick upwards, about 1.25 blocks). */
	private const MAX_JUMP_RISE = 1.25;

	/**
	 * Top of the collision boxes of each block state above the bottom of its cell; 0 for blocks without collision and
	 * for doors and fences, which never make a mob jump (see {@link MoveControl::shouldJump()}).
	 * @var float[] state ID => top
	 * @phpstan-var array<int, float>
	 */
	private static array $collisionTops = [];

	protected int $state = self::STATE_WAIT;
	protected float $wantedX = 0.0;
	protected float $wantedY = 0.0;
	protected float $wantedZ = 0.0;
	protected float $speedMultiplier = 1.0;
	private float $strafeForward = 0.0;
	private float $strafeRight = 0.0;
	private float $driveSpeed = 0.0;
	protected bool $jump = false;

	/** Output of tick(): wanted horizontal velocity in blocks per tick. */
	protected float $velocityX = 0.0;
	protected float $velocityZ = 0.0;
	/** Output of tick(): the speed the mob walks with, before it is capped by the distance to the wanted position. */
	protected float $walkSpeed = 0.0;
	protected bool $active = false;
	/** Output of tick(): the mob reached the wanted position on this tick and should stop there. */
	protected bool $arrived = false;

	public function __construct(
		protected Mob $mob
	){}

	public function setWantedPosition(float $x, float $y, float $z, float $speedMultiplier) : void{
		$this->state = self::STATE_MOVE_TO;
		$this->wantedX = $x;
		$this->wantedY = $y;
		$this->wantedZ = $z;
		$this->speedMultiplier = $speedMultiplier;
	}

	/**
	 * Moves relative to the mob's facing: forward/backward and right/left inputs between -1 and 1.
	 */
	public function strafe(float $forward, float $right, float $speedMultiplier) : void{
		$this->state = self::STATE_STRAFE;
		$this->strafeForward = $forward;
		$this->strafeRight = $right;
		$this->speedMultiplier = $speedMultiplier;
	}

	/**
	 * Moves at the given speed in blocks per tick relative to the mob's facing, with forward/backward and left/right
	 * inputs between -1 and 1, stepping up blocks it bumps into. Used for mobs steered by their rider: the input must be
	 * renewed every tick.
	 */
	public function drive(float $forward, float $left, float $speed) : void{
		$this->state = self::STATE_DRIVE;
		$this->strafeForward = $forward;
		$this->strafeRight = -$left;
		$this->driveSpeed = $speed;
		$this->speedMultiplier = 1.0;
	}

	public function stop() : void{
		$this->state = self::STATE_WAIT;
		$this->active = false;
		$this->arrived = false;
		$this->velocityX = $this->velocityZ = 0.0;
	}

	public function hasWanted() : bool{
		return $this->state !== self::STATE_WAIT;
	}

	public function isMoving() : bool{
		return $this->active;
	}

	/**
	 * Returns and clears whether the mob reached its wanted position on this tick: a walking mob then stops there
	 * instead of sliding past it (which would make it turn around and walk back).
	 */
	public function consumeArrival() : bool{
		$arrived = $this->arrived;
		$this->arrived = false;
		return $arrived;
	}

	public function getWantedX() : float{ return $this->wantedX; }

	public function getWantedY() : float{ return $this->wantedY; }

	public function getWantedZ() : float{ return $this->wantedZ; }

	public function getSpeedMultiplier() : float{ return $this->speedMultiplier; }

	public function jump() : void{
		$this->jump = true;
	}

	/**
	 * Returns whether a jump was asked for and not handled yet.
	 */
	public function wantsToJump() : bool{
		return $this->jump;
	}

	/**
	 * Returns and clears the jump request.
	 */
	public function consumeJump() : bool{
		$jump = $this->jump;
		$this->jump = false;
		return $jump;
	}

	public function getVelocityX() : float{ return $this->velocityX; }

	public function getVelocityZ() : float{ return $this->velocityZ; }

	/**
	 * Speed in blocks per tick the mob walks with on this tick before it is capped by the distance to the wanted
	 * position (the length of the velocity is lower on the last steps): how fast it speeds up depends on this one
	 * (see {@link Mob::tryChangeMovement()}). 0 when the mob doesn't walk on the ground.
	 */
	public function getWalkSpeed() : float{ return $this->walkSpeed; }

	/**
	 * Wanted vertical velocity, only used when {@link MoveControl::isThreeDimensional()} is true.
	 */
	public function getVelocityY() : float{ return 0.0; }

	/**
	 * Returns whether the mob currently moves in three dimensions (flying, or swimming in water): the velocity output
	 * includes a vertical component and the mob is not pulled down by gravity while moving.
	 */
	public function isThreeDimensional() : bool{
		return false;
	}

	/**
	 * Returns the ground speed in blocks per tick for the mob's movement attribute times the given multiplier.
	 */
	public function getGroundSpeed(float $speedMultiplier) : float{
		$s = $this->mob->getMovementSpeed() * $speedMultiplier;
		return min(self::MAX_SPEED, $s * $s * self::GROUND_SPEED_FACTOR);
	}

	public function tick() : void{
		$mob = $this->mob;
		$this->arrived = false;
		$this->walkSpeed = 0.0;
		if($this->state === self::STATE_MOVE_TO){
			$loc = $mob->getLiveLocation();
			$dx = $this->wantedX - $loc->x;
			$dz = $this->wantedZ - $loc->z;
			$dy = $this->wantedY - $loc->y;
			$distSq = $dx * $dx + $dz * $dz;
			if($distSq < 0.0001){
				$this->active = false;
				$this->arrived = true;
				$this->velocityX = $this->velocityZ = 0.0;
				$this->state = self::STATE_WAIT;
				return;
			}
			$dist = sqrt($distSq);
			if($dist > self::MIN_TURN_DISTANCE){
				$yaw = atan2($dz, $dx) / M_PI * 180 - 90;
				$mob->setBodyYaw(self::rotlerp($loc->yaw, $yaw, self::MAX_TURN_DEGREES));
			}

			$this->walkSpeed = $this->getGroundSpeed($this->speedMultiplier);
			$speed = min($this->walkSpeed, $dist);
			$this->velocityX = $dx / $dist * $speed;
			$this->velocityZ = $dz / $dist * $speed;
			$this->active = true;

			//on flat ground (most ticks) there is nothing to check
			if(($dy > 0.0 || $mob->isCollidedHorizontally) && $mob->isOnGround() && $this->shouldJump($dy, $distSq)){
				$this->jump = true;
			}
		}elseif($this->state === self::STATE_STRAFE || $this->state === self::STATE_DRIVE){
			$speed = $this->state === self::STATE_DRIVE ? $this->driveSpeed : $this->getGroundSpeed($this->speedMultiplier);
			$forward = $this->strafeForward;
			$right = $this->strafeRight;
			$len = sqrt($forward * $forward + $right * $right);
			if($len < 0.0001){
				$this->active = false;
				$this->velocityX = $this->velocityZ = 0.0;
				if($this->state === self::STATE_DRIVE){
					$this->state = self::STATE_WAIT;
				}
				return;
			}
			if($len > 1){
				$forward /= $len;
				$right /= $len;
			}
			$yawRad = deg2rad($mob->getLiveLocation()->yaw);
			$sin = sin($yawRad);
			$cos = cos($yawRad);
			//yaw 0 faces +Z; "right" of a mob facing +Z is -X
			$this->velocityX = (-$sin * $forward - $cos * $right) * $speed;
			$this->velocityZ = ($cos * $forward - $sin * $right) * $speed;
			$this->walkSpeed = $speed;
			if($this->state === self::STATE_STRAFE && !$this->isWalkable($this->velocityX, $this->velocityZ)){
				//vanilla: strafing that would step off walkable ground (a ledge, water, fire, lava...) turns into walking
				//forwards, towards what the mob faces
				$this->velocityX = -$sin * $speed;
				$this->velocityZ = $cos * $speed;
			}
			$this->active = true;
			if($this->state === self::STATE_DRIVE && $mob->isOnGround() && $mob->isCollidedHorizontally){
				$this->jump = true;
			}
			$this->state = self::STATE_WAIT; //strafing input must be renewed every tick
		}else{
			$this->active = false;
			$this->velocityX = $this->velocityZ = 0.0;
		}
	}

	/**
	 * Vanilla MoveControl jump rule for a mob on the ground walking to its wanted position: it jumps when the position is
	 * higher than it can step up and about a block away or closer (Java: squared distance below max(1, width); wider mobs
	 * walk to block centres here, so for them the distance at which they touch the block counts), or when it stands
	 * inside a block whose collision top is above its feet (not a door or a fence). Walking into a wall, a fence or
	 * another mob doesn't make it jump; bumping into a single block it can stand on does (see
	 * {@link MoveControl::bumpedIntoStep()}).
	 */
	private function shouldJump(float $dy, float $distSq) : bool{
		$mob = $this->mob;
		if($dy > $mob->getStepHeight()){
			$width = $mob->getSize()->getWidth();
			if($distSq < max(1.0, $width, ((1 + $width) / 2 + 0.05) ** 2)){
				return true;
			}
		}
		$collided = $mob->isCollidedHorizontally;
		//the block checks below only matter when it is blocked or going up. Path nodes are block rows: a mob standing on a
		//block lower than a full block (farmland, dirt path, soul sand, a chest) has its feet a little below the nodes on
		//the same floor ($this->wantedY - $dy is its Y), which is not going up
		if(!$collided && ($dy <= 0.0 || ($dy < 0.5 && $this->wantedY <= floor($this->wantedY - $dy + 0.49)))){
			return false;
		}
		$world = $mob->getWorld();
		$loc = $mob->getLiveLocation();
		//the bounding box has the feet: the location is lower right after stepping up (Entity::$ySize)
		$feetY = (Mob::getLiveBoundingBoxOf($mob) ?? $mob->getBoundingBox())->minY;
		$y = (int) floor($feetY);
		//inside a block whose top is above its feet; for a mob standing on a block lower than a full block (farmland, dirt
		//path, soul sand) this is that block, whose top is its feet height (a cached lookup, no Block object)
		if($y + self::collisionTop($world, (int) floor($loc->x), $y, (int) floor($loc->z)) > $feetY + 0.001){
			return true;
		}
		return $collided && $this->bumpedIntoStep($loc->x, $loc->z, $feetY);
	}

	/**
	 * Returns whether the mob, blocked while walking, bumped into a single block it could stand on but not step onto:
	 * a block at its feet (not a fence, wall or closed door) with room for the mob above it, higher than its step
	 * height and within jump reach. Mobs walking straight at their target without a path (MeleeAttackGoal close to it)
	 * jump onto it this way instead of staying stuck in front of it; walls two blocks high, fences and doors still don't
	 * make them jump. Only the columns right in front of it along the axes it walks on are checked.
	 */
	private function bumpedIntoStep(float $x, float $z, float $feetY) : bool{
		$mob = $this->mob;
		$stepHeight = $mob->getStepHeight();
		$row = (int) floor($feetY + 0.01);
		if($row + 1 - $feetY <= $stepHeight){
			//it steps onto any block in its feet row without jumping; the feet row is also the row of the block it stands
			//on when that is lower than a full block (farmland, dirt path, soul sand): only the row above can stop it
			++$row;
			if($row + 0.5 - $feetY > self::MAX_JUMP_RISE){
				return false; //no floor block there is within jump reach
			}
		}
		$vx = $this->velocityX;
		$vz = $this->velocityZ;
		$ax = abs($vx);
		$az = abs($vz);
		$reach = $mob->getSize()->getWidth() / 2 + 0.1;
		$headBlocks = max(0, (int) ceil($mob->getSize()->getHeight()) - 1);
		$world = $mob->getWorld();
		$fx = (int) floor($x);
		$fz = (int) floor($z);
		return
			($ax > 0.0001 && $ax >= $az * 0.4 && self::isStep($world, (int) floor($x + ($vx > 0 ? $reach : -$reach)), $row, $fz, $headBlocks, $feetY, $stepHeight)) ||
			($az > 0.0001 && $az >= $ax * 0.4 && self::isStep($world, $fx, $row, (int) floor($z + ($vz > 0 ? $reach : -$reach)), $headBlocks, $feetY, $stepHeight));
	}

	/**
	 * Returns whether the block at the given position is a floor block (top between half a block and a block) whose top
	 * is higher above $feetY than the step height but within jump reach, and a mob needing $headBlocks free blocks above
	 * its feet can stand on it, without anything that hurts.
	 */
	private static function isStep(World $world, int $x, int $y, int $z, int $headBlocks, float $feetY, float $stepHeight) : bool{
		$flags = PathBlockTypes::at($world, $x, $y, $z);
		if(($flags & PathBlockTypes::FLOOR) === 0 || ($flags & (PathBlockTypes::TALL | PathBlockTypes::DANGER)) !== 0){
			return false;
		}
		$rise = $y + self::collisionTop($world, $x, $y, $z) - $feetY;
		if($rise <= $stepHeight || $rise > self::MAX_JUMP_RISE){
			return false;
		}
		for($i = 1; $i <= $headBlocks + 1; ++$i){
			$above = PathBlockTypes::at($world, $x, $y + $i, $z);
			if(($above & PathBlockTypes::PASSABLE) === 0 || ($above & PathBlockTypes::DANGER) !== 0){
				return false;
			}
		}
		return true;
	}

	/**
	 * Returns whether the mob, moved by the given offset, would still stand on walkable ground: a floor below, room for
	 * its height, no water and nothing that hurts (vanilla MoveControl.isWalkable(), checked before strafing).
	 */
	private function isWalkable(float $dx, float $dz) : bool{
		$mob = $this->mob;
		$loc = $mob->getLiveLocation();
		$world = $mob->getWorld();
		$headBlocks = max(0, (int) ceil($mob->getSize()->getHeight()) - 1);
		$feetY = (Mob::getLiveBoundingBoxOf($mob) ?? $mob->getBoundingBox())->minY;
		$x = (int) floor($loc->x + $dx);
		$y = (int) floor($feetY);
		$z = (int) floor($loc->z + $dz);
		if($feetY - $y >= 0.5 && (PathBlockTypes::at($world, $x, $y, $z) & PathBlockTypes::FLOOR) !== 0){
			//its feet are on top of a block lower than a full block (farmland, dirt path, soul sand, a slab), a floor
			//block there is one it steps onto: it would stand in the cell above, as from a full block (Java's walk node
			//evaluator treats the cells of blocks lower than a full block as open, like air)
			++$y;
		}
		return PathBlockTypes::isStandable($world, $x, $y, $z, $headBlocks, false);
	}

	/**
	 * Returns the top of the collision boxes of the block at the given position above the bottom of its cell, 0 for no
	 * collision, doors and fences (cached per block state).
	 */
	private static function collisionTop(World $world, int $x, int $y, int $z) : float{
		if($y < $world->getMinY() || $y >= $world->getMaxY()){
			return 0.0;
		}
		$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		if($chunk === null){
			return 0.0;
		}
		$stateId = $chunk->getBlockStateId($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK);
		return self::$collisionTops[$stateId] ??= self::computeCollisionTop($world->getBlockAt($x, $y, $z));
	}

	private static function computeCollisionTop(Block $block) : float{
		if($block instanceof Door || $block instanceof Fence){
			return 0.0;
		}
		$baseY = $block->getPosition()->y;
		$top = 0.0;
		foreach($block->getCollisionBoxes() as $bb){
			$top = max($top, $bb->maxY - $baseY);
		}
		return $top;
	}

	/**
	 * Three-dimensional movement towards the wanted position (flying, swimming) at the given speed in blocks per
	 * tick. Strafing input is ignored. Sets the vertical velocity in $velocityY.
	 */
	protected function tickThreeDimensional(float $speed, float &$velocityY) : void{
		$velocityY = 0.0;
		if($this->state !== self::STATE_MOVE_TO){
			$this->state = self::STATE_WAIT;
			$this->active = false;
			$this->velocityX = $this->velocityZ = 0.0;
			return;
		}
		$mob = $this->mob;
		$loc = $mob->getLiveLocation();
		$dx = $this->wantedX - $loc->x;
		$dy = $this->wantedY - $loc->y;
		$dz = $this->wantedZ - $loc->z;
		$distSq = $dx * $dx + $dy * $dy + $dz * $dz;
		if($distSq < 0.0025){
			$this->active = false;
			$this->velocityX = $this->velocityZ = 0.0;
			$this->state = self::STATE_WAIT;
			return;
		}
		$dist = sqrt($distSq);
		if($dx * $dx + $dz * $dz > self::MIN_TURN_DISTANCE ** 2){
			$yaw = atan2($dz, $dx) / M_PI * 180 - 90;
			$mob->setBodyYaw(self::rotlerp($loc->yaw, $yaw, self::MAX_TURN_DEGREES));
		}
		$speed = min(self::MAX_SPEED, $speed, $dist);
		$this->velocityX = $dx / $dist * $speed;
		$velocityY = $dy / $dist * $speed;
		$this->velocityZ = $dz / $dist * $speed;
		$this->active = true;
	}

	/**
	 * Rotates the angle $from towards $to by at most $max degrees, taking the shortest way round.
	 */
	public static function rotlerp(float $from, float $to, float $max) : float{
		//wrapDegrees() written out: called for every walking mob and every mob turning its head on every tick
		$diff = fmod($to - $from, 360.0);
		if($diff >= 180.0){
			$diff -= 360.0;
		}elseif($diff < -180.0){
			$diff += 360.0;
		}
		if($diff > $max){
			$diff = $max;
		}elseif($diff < -$max){
			$diff = -$max;
		}
		$result = $from + $diff;
		if($result < 0){
			$result += 360;
		}elseif($result >= 360){
			$result -= 360;
		}
		return $result;
	}

	/**
	 * Wraps an angle to [-180, 180).
	 */
	public static function wrapDegrees(float $degrees) : float{
		$degrees = fmod($degrees, 360.0);
		if($degrees >= 180.0){
			$degrees -= 360.0;
		}elseif($degrees < -180.0){
			$degrees += 360.0;
		}
		return $degrees;
	}
}
