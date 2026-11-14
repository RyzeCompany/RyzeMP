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

use pocketmine\entity\ai\navigation\PathNavigator;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use function abs;
use function atan2;
use function fmod;
use function min;
use function sqrt;
use const M_PI;

/**
 * Turns the mob's head (head yaw and pitch) towards a point. The look target must be set again every tick (by the
 * goal that wants it); otherwise the head turns back to face forward.
 *
 * It also turns the body like vanilla (Java LookControl.clampHeadRotationToBody() and BodyRotationControl): a moving
 * mob keeps its body along the way it walks (the move control turns it) and its head within
 * {@link LookControl::MAX_HEAD_BODY_ANGLE} degrees of it; a mob standing still turns its body to stay within that
 * angle of the head, and once the head has stayed put for {@link LookControl::HEAD_STABLE_DELAY} ticks the body turns
 * towards it over the next {@link LookControl::FACE_FORWARD_TICKS} ticks. A passenger carried by its vehicle counts as
 * standing still.
 */
final class LookControl{
	/**
	 * Vanilla Mob.getMaxHeadYRot()
	 * @internal
	 */
	public const MAX_HEAD_BODY_ANGLE = 75.0;
	/** The head counts as still while it stays within this angle of where it was (BodyRotationControl). */
	private const HEAD_STABLE_ANGLE = 15.0;
	private const HEAD_STABLE_DELAY = 10;
	private const FACE_FORWARD_TICKS = 10;

	private bool $hasWanted = false;
	private float $wantedX = 0.0;
	private float $wantedY = 0.0;
	private float $wantedZ = 0.0;
	private float $yawSpeed = 10.0;
	private float $pitchSpeed = 40.0;
	/** Head yaw when the head last moved by more than HEAD_STABLE_ANGLE (or the mob last moved). */
	private float $lastStableHeadYaw = 0.0;
	/** Ticks the head has stayed within HEAD_STABLE_ANGLE of $lastStableHeadYaw while the mob stood still. */
	private int $headStableTicks = 0;

	public function __construct(
		private Mob $mob
	){
		$this->lastStableHeadYaw = $mob->getHeadYaw();
	}

	public function setLookAt(float $x, float $y, float $z, float $yawSpeed = 10.0, float $pitchSpeed = 40.0) : void{
		$this->hasWanted = true;
		$this->wantedX = $x;
		$this->wantedY = $y;
		$this->wantedZ = $z;
		$this->yawSpeed = $yawSpeed;
		$this->pitchSpeed = $pitchSpeed;
	}

	public function setLookAtEntity(Entity $entity, float $yawSpeed = 10.0, float $pitchSpeed = 40.0) : void{
		//called every tick by the goals looking at something: no copy of the location (and setLookAt() written out)
		$pos = Mob::getLiveLocationOf($entity) ?? $entity->getLocation();
		$this->hasWanted = true;
		$this->wantedX = $pos->x;
		$this->wantedY = $pos->y + $entity->getEyeHeight();
		$this->wantedZ = $pos->z;
		$this->yawSpeed = $yawSpeed;
		$this->pitchSpeed = $pitchSpeed;
	}

	public function hasWanted() : bool{
		return $this->hasWanted;
	}

	public function tick() : void{
		$mob = $this->mob;
		$this->tickWith($mob->getLiveLocation(), $mob->getHeadYaw(), $mob->isMovingHorizontally(), $mob->getVehicle() !== null, $mob->getMoveControl(), $mob->getNavigation(), $mob->getPassengers());
	}

	/**
	 * @internal {@link LookControl::tick()} with the mob's state at hand: {@link Mob::tickAi()} passes its own fields
	 * (every mob on every tick), unless the mob's class overrides the getters tick() reads them with.
	 *
	 * @param Location $loc        the mob's live location ({@link Mob::getLiveLocation()})
	 * @param Entity[] $passengers
	 */
	public function tickWith(Location $loc, float $headYaw, bool $movingHorizontally, bool $riding, MoveControl $moveControl, PathNavigator $navigation, array $passengers) : void{
		$mob = $this->mob;
		$bodyYaw = $loc->yaw;
		$pitch = $loc->pitch;
		if(!$this->hasWanted && $pitch === 0.0 && $headYaw === $bodyYaw){
			//idle, nothing to turn (runs for most mobs every tick): only the head stability (BodyRotationControl) is kept
			//up to date
			if(($movingHorizontally && !$riding) || ($headYaw !== $this->lastStableHeadYaw && abs(MoveControl::wrapDegrees($headYaw - $this->lastStableHeadYaw)) > self::HEAD_STABLE_ANGLE)){
				$this->lastStableHeadYaw = $headYaw;
				$this->headStableTicks = 0;
			}elseif($this->headStableTicks <= self::HEAD_STABLE_DELAY + self::FACE_FORWARD_TICKS){
				++$this->headStableTicks;
			}
			return;
		}
		//the move control turns the body of a walking mob (vanilla keeps that yaw apart from the body until the mob has
		//moved; here it is the body already). A passenger moved by its vehicle doesn't walk: its move control doesn't run
		//(see Mob::tickAi()), so it turns its body like a mob standing still (Bedrock riders of chickens, striders, camel
		//husks... turn freely)
		$moving = !$riding && ($movingHorizontally || $moveControl->isMoving());

		if($this->hasWanted){
			$this->hasWanted = false;
			$dx = $this->wantedX - $loc->x;
			$dz = $this->wantedZ - $loc->z;
			$dy = $this->wantedY - ($loc->y + $mob->getEyeHeight());
			$horizontal = sqrt($dx * $dx + $dz * $dz);
			if($horizontal > 0.0001 || abs($dy) > 0.0001){
				//a point (almost) straight above or below gives no direction to turn to: only the pitch follows it
				if($horizontal > MoveControl::MIN_TURN_DISTANCE){
					//MoveControl::rotlerp() written out (every tick for every mob looking at something)
					$turn = fmod(atan2($dz, $dx) / M_PI * 180 - 90 - $headYaw, 360.0);
					if($turn >= 180.0){
						$turn -= 360.0;
					}elseif($turn < -180.0){
						$turn += 360.0;
					}
					$yawSpeed = $this->yawSpeed;
					if($turn > $yawSpeed){
						$turn = $yawSpeed;
					}elseif($turn < -$yawSpeed){
						$turn = -$yawSpeed;
					}
					$headYaw += $turn;
					if($headYaw < 0){
						$headYaw += 360;
					}elseif($headYaw >= 360){
						$headYaw -= 360;
					}
				}
				//approach() written out
				$targetPitch = -atan2($dy, $horizontal) / M_PI * 180;
				$pitchDiff = $targetPitch - $pitch;
				$pitchSpeed = $this->pitchSpeed;
				$pitch = $pitchDiff > $pitchSpeed ? $pitch + $pitchSpeed : ($pitchDiff < -$pitchSpeed ? $pitch - $pitchSpeed : $targetPitch);
			}
		}else{
			$headYaw = MoveControl::rotlerp($headYaw, $bodyYaw, 10.0);
			$pitch = self::approach($pitch, 0.0, 10.0);
		}

		if($moving){
			//the body follows the way the mob walks, the head turns within its range of it
			$headYaw = self::rotateIfNecessary($headYaw, $bodyYaw, self::MAX_HEAD_BODY_ANGLE);
			$this->lastStableHeadYaw = $headYaw;
			$this->headStableTicks = 0;
		}else{
			if(!$navigation->isDone()){
				//a mob following a path keeps its head within range of its body (Java clampHeadRotationToBody())
				$headYaw = self::rotateIfNecessary($headYaw, $bodyYaw, self::MAX_HEAD_BODY_ANGLE);
			}
			if(!(($passengers[0] ?? null) instanceof Mob)){
				//how far the head turned from where it was still (MoveControl::wrapDegrees() written out)
				$turned = fmod($headYaw - $this->lastStableHeadYaw, 360.0);
				if($turned >= 180.0){
					$turned -= 360.0;
				}elseif($turned < -180.0){
					$turned += 360.0;
				}
				if(abs($turned) > self::HEAD_STABLE_ANGLE){
					//the head turned: the body turns as far as needed to keep the head within its range
					$this->headStableTicks = 0;
					$this->lastStableHeadYaw = $headYaw;
					$bodyYaw = self::rotateIfNecessary($bodyYaw, $headYaw, self::MAX_HEAD_BODY_ANGLE);
				}elseif(++$this->headStableTicks > self::HEAD_STABLE_DELAY){
					//the head stays put: the body turns to face where it settled, gradually. Unlike Java, which then copies
					//every small head movement, the body ignores the head moving within HEAD_STABLE_ANGLE (BDS mobs next to
					//a fidgeting player keep facing it without twitching)
					$fraction = min(1.0, ($this->headStableTicks - self::HEAD_STABLE_DELAY) / self::FACE_FORWARD_TICKS);
					$bodyYaw = self::rotateIfNecessary($bodyYaw, $this->lastStableHeadYaw, self::MAX_HEAD_BODY_ANGLE * (1.0 - $fraction));
					if($this->headStableTicks > self::HEAD_STABLE_DELAY + self::FACE_FORWARD_TICKS){
						$this->headStableTicks = self::HEAD_STABLE_DELAY + self::FACE_FORWARD_TICKS; //no overflow
					}
				}
			}
		}

		$mob->setHeadRotation($headYaw, $bodyYaw, $pitch);
	}

	/**
	 * Returns the angle $current moved the least possible to be within $max degrees of $target (vanilla
	 * Mth.rotateIfNecessary()): $current itself when it already is, otherwise an angle in [0, 360).
	 */
	private static function rotateIfNecessary(float $current, float $target, float $max) : float{
		//MoveControl::wrapDegrees() written out: up to three times per tick for every mob turning its head
		$diff = fmod($target - $current, 360.0);
		if($diff >= 180.0){
			$diff -= 360.0;
		}elseif($diff < -180.0){
			$diff += 360.0;
		}
		if($diff >= -$max && $diff <= $max){
			return $current;
		}
		$result = $target - ($diff > 0 ? $max : -$max);
		if($result < 0){
			$result += 360;
		}elseif($result >= 360){
			$result -= 360;
		}
		return $result;
	}

	private static function approach(float $from, float $to, float $max) : float{
		$diff = $to - $from;
		if($diff > $max){
			return $from + $max;
		}
		if($diff < -$max){
			return $from - $max;
		}
		return $to;
	}
}
