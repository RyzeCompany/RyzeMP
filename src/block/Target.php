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

namespace pocketmine\block;

use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\projectile\Projectile;
use pocketmine\entity\projectile\Trident;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use function abs;
use function ceil;
use function floor;
use function max;
use function min;

/**
 * Emits a redstone pulse when hit by a projectile: the closer to the centre of the face, the stronger. Bedrock has no
 * block state for the output, so it only lives in the redstone engine (lost on chunk unload).
 */
class Target extends Opaque{
	public const ARROW_PULSE_TICKS = 20;
	public const PROJECTILE_PULSE_TICKS = 8;

	/**
	 * Returns the power (1-15) for a hit on the given face at the given position (absolute or relative to the block).
	 */
	public static function calculateHitPower(int $hitFace, Vector3 $hitVector) : int{
		$dx = abs($hitVector->x - floor($hitVector->x) - 0.5);
		$dy = abs($hitVector->y - floor($hitVector->y) - 0.5);
		$dz = abs($hitVector->z - floor($hitVector->z) - 0.5);
		$distance = match(Facing::axis($hitFace)){
			Axis::Y => max($dx, $dz),
			Axis::Z => max($dx, $dy),
			default => max($dy, $dz),
		};
		return max(1, (int) ceil(15 * min(1.0, max(0.0, (0.5 - $distance) / 0.5))));
	}

	public function onProjectileHit(Projectile $projectile, RayTraceResult $hitResult) : void{
		parent::onProjectileHit($projectile, $hitResult);
		$world = $this->position->getWorld();
		$redstone = $world->getRedstone();
		if($redstone === null){
			return;
		}
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$power = self::calculateHitPower($hitResult->getHitFace(), $hitResult->getHitVector());
		$redstone->setTransientPower($x, $y, $z, $power);
		$redstone->updateAround($x, $y, $z);
		$world->scheduleDelayedBlockUpdate($this->position, $projectile instanceof Arrow || $projectile instanceof Trident ? self::ARROW_PULSE_TICKS : self::PROJECTILE_PULSE_TICKS);
	}

	public function onScheduledUpdate() : void{
		$redstone = $this->position->getWorld()->getRedstone();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		if($redstone !== null && $redstone->getTransientPower($x, $y, $z) > 0){
			$redstone->setTransientPower($x, $y, $z, 0);
			$redstone->updateAround($x, $y, $z);
		}
	}

	public function isRedstoneSource() : bool{
		return true;
	}

	public function getWeakPower(int $face) : int{
		return $this->position->getWorld()->getRedstone()?->getTransientPower($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ()) ?? 0;
	}

	public function pausesScheduledUpdatesOutsideTickingChunks() : bool{
		return true; //redstone components only update in ticking chunks, like vanilla block ticks
	}
}
