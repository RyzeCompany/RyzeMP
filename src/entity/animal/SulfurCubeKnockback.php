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

namespace pocketmine\entity\animal;

use pocketmine\math\Vector3;
use function abs;
use function atan2;
use function cos;
use function deg2rad;
use function max;
use function min;
use function sin;
use function sqrt;

/**
 * How far a sulfur cube holding a block flies when a player hits it, like a ball (Java SulfurCube.knockback(), with
 * the Bedrock powers of player.json). The ball starts off away from the player and is deflected by 1.6 times the angle
 * between the player's aim and the cube's centre, so hitting it off-centre sends it further sideways than the aim;
 * aiming low lifts it and aiming high flattens it, and hitting it from below or above tilts the shot. The power grows
 * with the square root of the damage of the hit and with negative knockback resistance.
 *
 * Bedrock's horizontal_power is Java's horizontal power after its final x0.4 (0.165 = 0.4125 x 0.4): the hit angle
 * rules work on Java's power (horizontal_power / 0.4) and the x0.4 comes last, as in Java. vertical_power is used as
 * it is, without Java's final x1.2 (its values are Java's vertical powers before that scale, regular aside).
 *
 * @internal
 */
final class SulfurCubeKnockback{
	private const HORIZONTAL_HIT_ANGLE_SCALE = 1.6;
	private const VERTICAL_HIT_ANGLE_SCALE = 0.5;
	private const VERTICAL_POSITION_ANGLE_SCALE = 0.8;
	/** Java's final scale of the horizontal power, already in Bedrock's horizontal_power. */
	private const HORIZONTAL_POWER_SCALE = 0.4;
	/** Extra knockback multiplies the power by a quarter of its strength (Java comesFromEffect). */
	private const EXTRA_KNOCKBACK_SCALE = 0.25;

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the velocity added to the cube by the hit itself. The ball starts off along the horizontal direction
	 * from the player to the cube (Java dealDefaultKnockback()); without one (the player right above or below it) it
	 * only goes up or down.
	 *
	 * @param Vector3 $attackerEye  eye position of the player
	 * @param Vector3 $look         direction the player looks in (normalized)
	 * @param Vector3 $attackerFeet feet position of the player
	 * @param Vector3 $cubeFeet     position of the cube (bottom centre)
	 */
	public static function compute(SulfurCubeArchetype $archetype, Vector3 $attackerEye, Vector3 $look, Vector3 $attackerFeet, Vector3 $cubeFeet, float $cubeHeight, float $damage) : Vector3{
		return self::computeFrom($archetype, $attackerEye, $look, $attackerFeet, $cubeFeet, $cubeHeight, $cubeFeet->x - $attackerFeet->x, $cubeFeet->z - $attackerFeet->z, sqrt(max(0.0, $damage)));
	}

	/**
	 * Returns the velocity added to the cube by an extra knockback right after the hit, such as the Knockback
	 * enchantment (Java comesFromEffect; Bedrock extra_knockback_approach "multiply_reduced"): the ball starts off where
	 * the player faces, and a quarter of the extra knockback's strength multiplies the power of the hit.
	 *
	 * @param float $attackerYaw yaw of the player, in degrees
	 * @param float $strength    strength of the extra knockback (Knockback enchantment: half its level)
	 */
	public static function computeExtra(SulfurCubeArchetype $archetype, Vector3 $attackerEye, Vector3 $look, float $attackerYaw, Vector3 $attackerFeet, Vector3 $cubeFeet, float $cubeHeight, float $damage, float $strength) : Vector3{
		$yaw = deg2rad($attackerYaw);
		return self::computeFrom($archetype, $attackerEye, $look, $attackerFeet, $cubeFeet, $cubeHeight, -sin($yaw), cos($yaw), sqrt(max(0.0, $damage)) * $strength * self::EXTRA_KNOCKBACK_SCALE);
	}

	/**
	 * @param float $dirX       horizontal direction the ball starts off in, before the deflection (any length)
	 * @param float $multiplier multiplier of the powers (square root of the damage, extra knockback)
	 */
	private static function computeFrom(SulfurCubeArchetype $archetype, Vector3 $attackerEye, Vector3 $look, Vector3 $attackerFeet, Vector3 $cubeFeet, float $cubeHeight, float $dirX, float $dirZ, float $multiplier) : Vector3{
		$originalHorizontal = $archetype->getHitHorizontalPower() / self::HORIZONTAL_POWER_SCALE;
		$originalVertical = $archetype->getHitVerticalPower();
		$center = $cubeFeet->add(0, $cubeHeight / 2, 0);

		//deflection: the angle between the aim and the cube's centre, times 1.6
		$length = sqrt($dirX * $dirX + $dirZ * $dirZ);
		if($length > 1.0e-5){
			$toCenterX = $center->x - $attackerEye->x;
			$toCenterZ = $center->z - $attackerEye->z;
			$angle = atan2($look->x * $toCenterZ - $look->z * $toCenterX, $look->x * $toCenterX + $look->z * $toCenterZ) * self::HORIZONTAL_HIT_ANGLE_SCALE;
			$cos = cos($angle);
			$sin = sin($angle);
			$dirX /= $length;
			$dirZ /= $length;
			[$dirX, $dirZ] = [$dirX * $cos - $dirZ * $sin, $dirX * $sin + $dirZ * $cos];
		}else{
			$dirX = $dirZ = 0.0;
		}

		[$horizontal, $vertical] = self::transferVerticalHitAngle($originalHorizontal, $originalVertical, $attackerEye, $look, $center, $cubeHeight);
		[$horizontal, $vertical] = self::rotateByPosition($horizontal, $vertical, $originalHorizontal, $originalVertical, $attackerFeet, $cubeFeet);

		$scale = $multiplier * (1.0 - $archetype->getKnockbackResistance());
		$horizontal = max(-128.0, min(128.0, $horizontal * $scale * self::HORIZONTAL_POWER_SCALE));
		$vertical = max(-128.0, min(128.0, $vertical * $scale));
		return new Vector3($dirX * $horizontal, $vertical, $dirZ * $horizontal);
	}

	/**
	 * Aiming at the bottom of the cube moves power from horizontal to vertical (up to half of it), aiming at the top
	 * the other way round.
	 *
	 * @return float[]
	 * @phpstan-return array{float, float}
	 */
	public static function transferVerticalHitAngle(float $horizontal, float $vertical, Vector3 $attackerEye, Vector3 $look, Vector3 $center, float $height) : array{
		$toTopY = self::normalizedY($center->x - $attackerEye->x, $center->y + $height / 2 - $attackerEye->y, $center->z - $attackerEye->z);
		$toBottomY = self::normalizedY($center->x - $attackerEye->x, $center->y - $height / 2 - $attackerEye->y, $center->z - $attackerEye->z);
		//clampedMap(look.y, toTop.y, toBottom.y, -1, 1)
		$factor = abs($toBottomY - $toTopY) < 1.0e-9 ? 0.0 : -1.0 + 2.0 * max(0.0, min(1.0, ($look->y - $toTopY) / ($toBottomY - $toTopY)));
		$ratio = abs($factor * self::VERTICAL_HIT_ANGLE_SCALE);
		if($factor < 0){
			$ratio = -$ratio;
		}
		return [$horizontal * (1.0 - $ratio), $vertical * (1.0 + $ratio)];
	}

	/**
	 * A player below the cube shoots it upwards, a player above it downwards: the power vector is rotated by 0.8 times
	 * the angle between them, without growing beyond the original powers.
	 *
	 * @return float[]
	 * @phpstan-return array{float, float}
	 */
	public static function rotateByPosition(float $horizontal, float $vertical, float $originalHorizontal, float $originalVertical, Vector3 $attackerFeet, Vector3 $cubeFeet) : array{
		$dx = $cubeFeet->x - $attackerFeet->x;
		$dy = $cubeFeet->y - $attackerFeet->y;
		$dz = $cubeFeet->z - $attackerFeet->z;
		$angle = -atan2(-$dy, sqrt($dx * $dx + $dz * $dz)) * self::VERTICAL_POSITION_ANGLE_SCALE;
		$cos = cos($angle);
		$sin = sin($angle);
		$h = $horizontal * $cos - $vertical * $sin;
		$v = $horizontal * $sin + $vertical * $cos;
		$horizontalRatio = $originalHorizontal > 0 ? abs($h) / $originalHorizontal : 0.0;
		$verticalRatio = $originalVertical > 0 ? abs($v) / $originalVertical : 0.0;
		$maxRatio = max($horizontalRatio, $verticalRatio);
		if($maxRatio > 1.0){
			$h /= $maxRatio;
			$v /= $maxRatio;
		}
		return [$h, $v];
	}

	private static function normalizedY(float $x, float $y, float $z) : float{
		$length = sqrt($x * $x + $y * $y + $z * $z);
		return $length > 0 ? $y / $length : 0.0;
	}
}
