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

namespace pocketmine\entity\ai\hostile;

use pocketmine\entity\ai\control\MoveControl;
use pocketmine\entity\Mob;
use pocketmine\math\Vector3;
use function abs;
use function cos;
use function deg2rad;
use function max;
use function mt_rand;
use function sin;

/**
 * Movement of mobs that only move by hopping (slimes, magma cubes, sulfur cubes; vanilla movement.jump and the
 * slime_* behaviours): they face a direction and, every jump delay, leap forward. They don't move on the ground
 * between hops.
 */
final class HopControl{
	/**
	 * Part of a push from other entities kept from one tick to the next between hops (vanilla ground friction 0.6 * 0.91,
	 * like {@link Mob}), and the speed below which it stops (vanilla LivingEntity.aiStep()).
	 */
	private const GROUND_PUSH_KEPT = 0.546;
	private const MIN_HORIZONTAL_SPEED = 0.003;

	private float $yaw = 0.0;
	private bool $aggressive = false;
	private bool $wantsToMove = false;
	/**
	 * -1 until the first hop is planned: a new mob on the ground waits a jump delay first, one floating in a liquid swims
	 * up at once.
	 */
	private int $jumpDelay = -1;
	private bool $wasOnGround = true;
	private bool $landed = false;
	private bool $jumped = false;
	/** Whether the last update kept the mob on the ground between hops: its motion is then only what is left of pushes. */
	private bool $resting = false;

	/**
	 * @param int[] $calmDelay       jump delay range in ticks when calm
	 * @param int[] $aggressiveDelay jump delay range in ticks when chasing a target
	 * @phpstan-param array{int, int} $calmDelay
	 * @phpstan-param array{int, int} $aggressiveDelay
	 */
	public function __construct(
		private Mob $mob,
		private array $calmDelay,
		private array $aggressiveDelay
	){
		$this->yaw = $mob->getLiveLocation()->yaw;
	}

	public function setDirection(float $yaw, bool $aggressive) : void{
		$this->yaw = $yaw;
		$this->aggressive = $aggressive;
	}

	public function getDirection() : float{
		return $this->yaw;
	}

	public function setWantsToMove(bool $wantsToMove) : void{
		$this->wantsToMove = $wantsToMove;
	}

	public function isAggressive() : bool{
		return $this->aggressive;
	}

	/**
	 * Whether the mob touched the ground at the end of a hop during the last movement update.
	 */
	public function hasLanded() : bool{
		return $this->landed;
	}

	/**
	 * Whether the mob leapt during the last movement update.
	 */
	public function hasJumped() : bool{
		return $this->jumped;
	}

	/**
	 * Ticks left before the next hop.
	 */
	public function getJumpDelay() : int{
		return $this->jumpDelay;
	}

	/**
	 * Returns the motion of the mob for this tick (gravity and drag included).
	 *
	 * @param float      $hopSpeed           horizontal speed of a hop, blocks per tick
	 * @param float      $jumpVelocity       vertical speed of a hop
	 * @param bool       $inWater            whether the mob floats in a liquid (water; lava for magma cubes)
	 * @param float|null $liquidJumpVelocity vertical speed of a hop in the liquid (default 60% of $jumpVelocity)
	 */
	public function computeMotion(Vector3 $motion, float $hopSpeed, float $jumpVelocity, bool $inWater, ?float $liquidJumpVelocity = null) : Vector3{
		$mob = $this->mob;
		$loc = $mob->getLiveLocation();
		$mob->setBodyYaw(MoveControl::rotlerp($loc->yaw, $this->yaw, 90.0));

		$onGround = $mob->isOnGround();
		$this->landed = $onGround && !$this->wasOnGround;
		$this->wasOnGround = $onGround;
		$this->jumped = false;
		$wasResting = $this->resting;
		$this->resting = false;

		$mx = $motion->x;
		$my = ($motion->y - 0.08) * 0.98;
		$mz = $motion->z;
		if($inWater){
			//slime_float: bob up to the surface
			$my = max($my, 0.08);
		}
		if($onGround || $inWater){
			if($this->wantsToMove && $this->jumpDelay < 0 && !$inWater){
				//the calm delay even with a target: BDS slimes from a split first hop 11-14 ticks after spawning
				[$min, $max] = $this->calmDelay;
				$this->jumpDelay = mt_rand($min, $max);
			}
			if($this->wantsToMove && --$this->jumpDelay <= 0){
				[$min, $max] = $this->aggressive ? $this->aggressiveDelay : $this->calmDelay;
				$this->jumpDelay = mt_rand($min, $max);
				$yawRad = deg2rad($this->yaw);
				$mx = -sin($yawRad) * $hopSpeed;
				$mz = cos($yawRad) * $hopSpeed;
				$my = $inWater ? max($my, $liquidJumpVelocity ?? $jumpVelocity * 0.6) : $jumpVelocity;
				$this->jumped = true;
			}elseif($onGround){
				//slimes stick to the ground between hops, but other mobs and players still push them (Java SlimeMoveControl
				//only stops the walking): the push of this tick, plus what is left of the earlier ones after ground friction
				//(the motion lost the speed of a hop on landing, and a push stopped by a block)
				$pushX = $mob->getAppliedEntityPushX();
				$pushZ = $mob->getAppliedEntityPushZ();
				$mx = $pushX;
				$mz = $pushZ;
				if($wasResting){
					$mx += ($motion->x - $pushX) * self::GROUND_PUSH_KEPT;
					$mz += ($motion->z - $pushZ) * self::GROUND_PUSH_KEPT;
				}
				if(abs($mx) < self::MIN_HORIZONTAL_SPEED && abs($mz) < self::MIN_HORIZONTAL_SPEED){
					$mx = $mz = 0.0;
				}
				$this->resting = true;
			}
		}elseif($this->wantsToMove && $mob->isCollidedHorizontally){
			//vanilla slimes keep pushing towards where they hop while airborne (Java SlimeMoveControl keeps setZza()): one
			//that hit a block keeps pressing against it and lands on top when its hop is high enough, e.g. up a step or
			//out of a pit, instead of falling back to where it jumped
			$yawRad = deg2rad($this->yaw);
			$mx = -sin($yawRad) * $hopSpeed;
			$mz = cos($yawRad) * $hopSpeed;
		}else{
			$mx *= 0.98;
			$mz *= 0.98;
		}
		return new Vector3($mx, $my, $mz);
	}
}
