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

namespace pocketmine\entity\ai\goal;

use pocketmine\entity\ai\navigation\ClimbingPathNavigator;
use pocketmine\entity\ai\navigation\PathNavigator;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\math\AxisAlignedBB;
use function abs;
use function cos;
use function deg2rad;
use function max;
use function mt_rand;
use function sin;
use function sqrt;

/**
 * Chases the attack target and hits it when in reach (vanilla behavior.melee_box_attack, or the older melee_attack
 * family when built with a reach multiplier).
 *
 * Close to the target the mob walks straight at it but stops where the two collision boxes touch, like vanilla mobs
 * kept apart by their collision boxes: walking to the target's own position would put the mob inside it, where the
 * direction to the target (and so the mob's body and head yaw) swings around with every small movement of the target,
 * and where players can't see the mob's attack animation.
 *
 * Reach (Bedrock melee_box_attack): the target's collision box must intersect the mob's collision box grown by
 * {@link MeleeAttackGoal::HORIZONTAL_REACH} on the horizontal axes only (their heights must overlap), and the target
 * must be inside the mob's melee field of view. BDS 1.26.52 captures agree: a spider hits with 0.7 blocks between the
 * boxes, a warden with 0.8, and mobs never walk into their target.
 * The field of view (melee_fov, 90 degrees unless the mob sets another, 360 for the warden) is taken as the whole
 * cone ahead of the mob, half of it on each side of the way its head looks, measured horizontally: with 360 the mob
 * hits all around it. A target straight above or below the mob (the boxes overlap horizontally) gives no direction to
 * face and is always in view.
 */
class MeleeAttackGoal extends Goal{
	/** Ticks between two hits unless the mob sets another (Bedrock cooldown_time 1 s). */
	public const ATTACK_INTERVAL = 20;
	/** Bedrock horizontal_reach: how far the mob's collision box is grown on the horizontal axes to hit. */
	private const HORIZONTAL_REACH = 0.8;
	/** Bedrock melee_fov in degrees, unless the mob sets another. */
	private const MELEE_FOV = 90.0;
	/**
	 * Height above a climber up to which an airborne target close by is walked to directly (a jump peaks 1.25 blocks
	 * up) instead of climbed to.
	 */
	private const MAX_AIRBORNE_TARGET_DY = 1.5;
	/**
	 * Gap between the heights of the two boxes still taken as overlapping: flying and swimming mobs stop where their
	 * box touches the box of a target straight above or below them (see approach()) and settle within a few hundredths
	 * of a block of it, where vanilla mobs (which don't collide with their target) overlap it.
	 */
	private const VERTICAL_TOLERANCE = 0.05;

	/**
	 * AI tick ({@link Mob::getAiTick()}) from which the mob may hit again. The cooldown goes on while the goal is
	 * stopped: a leap, a flight from a repellent or a panic in between doesn't delay the next hit.
	 */
	private int $nextAttackTick = 0;
	private int $repathDelay = 0;
	/** Cosine of half the melee field of view; -1 when the mob hits all around it. */
	private float $fovCos;

	/**
	 * @param int        $attackInterval  ticks between two hits (Bedrock cooldown_time)
	 * @param float      $meleeFov        melee field of view in degrees (Bedrock melee_fov), 360 to hit all around
	 * @param float|null $reachMultiplier null for the melee_box_attack reach; otherwise the reach of the older
	 *                                    melee_attack family (delayed_attack, stomp_attack, ocelotattack): the distance
	 *                                    between the feet must be at most sqrt((width * multiplier)^2 + target width)
	 * @param bool       $canSpreadOnFire whether a burning mob sets the target it hits on fire (Bedrock
	 *                                    can_spread_on_fire, Java Zombie::doHurtTarget)
	 */
	public function __construct(
		protected Mob $mob,
		protected float $speedMultiplier,
		protected float $damage,
		protected float $followRange = 35.0,
		private int $attackInterval = self::ATTACK_INTERVAL,
		float $meleeFov = self::MELEE_FOV,
		private ?float $reachMultiplier = null,
		private bool $canSpreadOnFire = false
	){
		$this->fovCos = $meleeFov >= 360.0 ? -1.0 : cos(deg2rad($meleeFov / 2));
	}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public function canStart() : bool{
		$target = $this->mob->getAttackTarget();
		return $this->mob->getSensing()->isValidTarget($target, $this->followRange) && ($target === null || $this->isWithinHomeRestriction($target));
	}

	/**
	 * Returns whether the mob may go after the target: a mob whose home restriction covers all movement (Bedrock
	 * restriction_type all_movement, see {@link \pocketmine\entity\HomeTrait}) doesn't chase a target outside it (Java
	 * MeleeAttackGoal checks isWithinHome too).
	 */
	private function isWithinHomeRestriction(Living $target) : bool{
		if(!$this->mob->isHomeAllMovement()){
			return true;
		}
		$pos = Mob::getLiveLocationOf($target) ?? $target->getLocation();
		return $this->mob->isWithinHome($pos->x, $pos->y, $pos->z);
	}

	public function start() : void{
		$this->repathDelay = 0;
		if($this->nextAttackTick === 0){
			//the first hit waits a whole interval, like BDS 1.26.60: summoned zombies (1 s) first hit a player standing
			//next to them after about 1.1 s, hoglins (2 s) after about 2.3 s, both of a pair on the same tick
			$this->nextAttackTick = $this->mob->getAiTick() + $this->getAttackInterval();
		}
	}

	public function stop() : void{
		$this->mob->getNavigation()->stop();
	}

	/**
	 * Returns whether the target can be hit from where the mob is (see the class doc). $dx, $dz and $distSq are the
	 * horizontal offsets and the squared distance from the mob's feet to the target's.
	 */
	protected function isInReach(Living $target, float $dx, float $dz, float $distSq) : bool{
		if($this->reachMultiplier !== null){
			$w = $this->mob->getSize()->getWidth() * $this->reachMultiplier;
			if($distSq > $w * $w + $target->getSize()->getWidth()){
				return false;
			}
		}elseif(!self::isInBoxReach($this->mob->getBoundingBox(), Mob::getLiveBoundingBoxOf($target) ?? $target->getBoundingBox(), self::HORIZONTAL_REACH)){
			return false;
		}
		if($this->fovCos <= -1.0 || max(abs($dx), abs($dz)) < ($this->mob->getSize()->getWidth() + $target->getSize()->getWidth()) / 2){
			//hits all around, or the target is straight above or below it
			return true;
		}
		return self::isInFieldOfView($this->mob->getHeadYaw(), $dx, $dz, $this->fovCos);
	}

	/**
	 * Bedrock melee_box_attack reach: whether the target box intersects the attacker box grown by $horizontalReach on
	 * the horizontal axes only. Computed on the boxes' coordinates, without a grown copy of the box.
	 */
	private static function isInBoxReach(AxisAlignedBB $attacker, AxisAlignedBB $target, float $horizontalReach) : bool{
		return $target->maxX > $attacker->minX - $horizontalReach && $target->minX < $attacker->maxX + $horizontalReach &&
			$target->maxZ > $attacker->minZ - $horizontalReach && $target->minZ < $attacker->maxZ + $horizontalReach &&
			$target->maxY > $attacker->minY - self::VERTICAL_TOLERANCE && $target->minY < $attacker->maxY + self::VERTICAL_TOLERANCE;
	}

	/**
	 * Returns whether the horizontal direction ($dx, $dz) is within the field of view of a mob looking towards $yaw
	 * (vanilla convention, 0 = +Z), given the cosine of half the field of view.
	 */
	private static function isInFieldOfView(float $yaw, float $dx, float $dz, float $fovCos) : bool{
		$yawRad = deg2rad($yaw);
		//the mob looks towards (-sin(yaw), cos(yaw))
		return -sin($yawRad) * $dx + cos($yawRad) * $dz >= $fovCos * sqrt($dx * $dx + $dz * $dz);
	}

	/**
	 * Ticks between two attacks.
	 */
	protected function getAttackInterval() : int{
		return $this->attackInterval;
	}

	/**
	 * Called when the target is in reach and the cooldown is over: hits it.
	 */
	protected function performAttack(Living $target) : void{
		if($this->mob->attackMelee($target, $this->damage) && $this->canSpreadOnFire && $this->mob->isOnFire()){
			//Java Zombie::doHurtTarget: a chance of 0.3 per difficulty level, for 2 seconds per level
			$difficulty = $this->mob->getWorld()->getDifficulty();
			if(mt_rand(0, 9) < 3 * $difficulty){
				$target->setOnFire(2 * $difficulty);
			}
		}
	}

	/**
	 * Returns whether the mob goes straight at a target in the air or in water close by (horizontally), $dy blocks
	 * above it (below when negative), instead of following the navigation.
	 */
	private function goesStraightToAirborneTarget(float $dy) : bool{
		if($this->mob->getMoveControl()->isThreeDimensional()){
			//flying and swimming mobs fly or swim straight up or down to it
			return true;
		}
		if($dy < 0){
			//well below: the navigation takes walking mobs down to it
			return false;
		}
		//above: it comes down where it is (jumping, knocked back or tossed up), no need to look for a way up. But a
		//climber climbs to a target well above it (on a ladder or vines) along the wall
		return $dy < self::MAX_AIRBORNE_TARGET_DY || !$this->mob->getNavigation() instanceof ClimbingPathNavigator;
	}

	/**
	 * Walks straight towards the target, up to the point where the collision boxes touch. Returns false, without
	 * moving the mob, when a walking mob's next step that way isn't safe (a drop deeper than its paths take, fire,
	 * lava...: the target was knocked over an edge or stands across a hole): the navigation takes over then, and goes
	 * no further than a path does.
	 */
	private function approach(PathNavigator $navigation, Location $mobLoc, Location $targetLoc, Living $target, float $dx, float $dz) : bool{
		//axis-aligned boxes touch when the larger of the two horizontal distances is the sum of the half widths
		$gap = max(abs($dx), abs($dz));
		$mob = $this->mob;
		$mobSize = $mob->getSize(); //every tick of a mob close to its target: looked up once
		$contact = ($mobSize->getWidth() + $target->getSize()->getWidth()) / 2;
		$control = $mob->getMoveControl();
		if($gap <= $contact){
			if($mob->canClimbWalls() && $targetLoc->y > $mobLoc->y + $mobSize->getHeight() && !$control->isThreeDimensional()){
				//a climber on a wall under its target (a player on a ladder or vines): it keeps pressing against the wall
				//towards it, which keeps it climbing, up to where it can reach the target (the reach doesn't grow upwards)
				$navigation->stop();
				$control->setWantedPosition($targetLoc->x, $mobLoc->y, $targetLoc->z, $this->speedMultiplier);
				return true;
			}
			//already touching: stop right here instead of sliding on into the target. A flying or swimming mob still sets
			//its height: it aims at the exact height where the boxes touch, so that it brakes there instead of drifting
			//on into the target
			$wantedY = $mobLoc->y;
			if($control->isThreeDimensional()){
				$mobHeight = $mobSize->getHeight();
				$targetHeight = $target->getSize()->getHeight();
				$targetCentre = $targetLoc->y + $targetHeight / 2;
				if($gap < $contact - 0.1){
					//right above or below the target: on top of it or under it
					$wantedY = $mobLoc->y + $mobHeight / 2 >= $targetCentre ? $targetLoc->y + $targetHeight : $targetLoc->y - $mobHeight;
				}elseif($mobLoc->y >= $targetLoc->y + $targetHeight || $mobLoc->y + $mobHeight <= $targetLoc->y){
					//beside it but higher or lower: level with it
					$wantedY = $targetCentre - $mobHeight / 2;
				}
			}
			$navigation->stop();
			$control->setWantedPosition($mobLoc->x, $wantedY, $mobLoc->z, $this->speedMultiplier);
			return true;
		}
		$scale = 1 - $contact / $gap;
		$sx = $dx * $scale;
		$sz = $dz * $scale;
		if($control->isThreeDimensional()){
			//flying and swimming mobs go to the target's height
			$navigation->stop();
			$control->setWantedPosition($mobLoc->x + $sx, $targetLoc->y, $mobLoc->z + $sz, $this->speedMultiplier);
			return true;
		}
		//the block column it walks into next, at most a block ahead
		$length = sqrt($sx * $sx + $sz * $sz);
		$ahead = $length > 1.0 ? 1.0 / $length : 1.0;
		if(!$navigation->isSafeStep($mobLoc->x + $sx * $ahead, $mobLoc->z + $sz * $ahead)){
			return false;
		}
		//walking mobs don't chase a jumping or knocked up target by jumping themselves
		$navigation->stop();
		$control->setWantedPosition($mobLoc->x + $sx, $mobLoc->y, $mobLoc->z + $sz, $this->speedMultiplier);
		return true;
	}

	public function tick() : void{
		$target = $this->mob->getAttackTarget();
		if($target === null){
			return;
		}
		$this->mob->getLookControl()->setLookAtEntity($target, 30.0, 30.0);
		$mobLoc = $this->mob->getLiveLocation();
		$targetLoc = Mob::getLiveLocationOf($target) ?? $target->getLocation();
		$dx = $targetLoc->x - $mobLoc->x;
		$dz = $targetLoc->z - $mobLoc->z;
		$dy = $targetLoc->y - $mobLoc->y;
		$horizontalSq = $dx * $dx + $dz * $dz;
		$distSq = $horizontalSq + $dy * $dy;

		$navigation = $this->mob->getNavigation();
		$straight = false;
		if($horizontalSq < 4.0 && (abs($dy) < 1.0 || (!$target->isOnGround() && $this->goesStraightToAirborneTarget($dy)))){
			//close enough to walk straight at it, unless the way there isn't safe
			$straight = $this->approach($navigation, $mobLoc, $targetLoc, $target, $dx, $dz);
			if(!$straight && $navigation->getPath() === null){
				//no path (yet) to take over: don't keep walking towards where the straight way led on the last tick
				$this->mob->getMoveControl()->stop();
			}
		}
		if($straight){
			$this->repathDelay = 0;
		}elseif(--$this->repathDelay <= 0){
			$this->repathDelay = 4 + mt_rand(0, 7);
			if($distSq > 1024){
				$this->repathDelay += 10;
			}elseif($distSq > 256){
				$this->repathDelay += 5;
			}
			if(!$navigation->moveToEntity($target, $this->speedMultiplier, 0.0)){
				$this->repathDelay += 15;
			}
		}

		$now = $this->mob->getAiTick();
		if($this->damage > 0 && $now >= $this->nextAttackTick && $this->isInReach($target, $dx, $dz, $distSq) && $this->mob->getSensing()->canSee($target)){
			$this->nextAttackTick = $now + $this->getAttackInterval();
			$this->performAttack($target);
		}
	}
}
