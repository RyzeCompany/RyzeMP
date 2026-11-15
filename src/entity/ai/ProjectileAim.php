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

use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\math\Vector3;
use pocketmine\utils\Utils;
use function atan2;
use function sqrt;
use const M_PI;

/**
 * Aiming of thrown projectiles (snowballs of snow golems, llama spit): like Java's throwers, aims at a third of the
 * target's height plus 20% of the horizontal distance upwards to make up for gravity.
 */
final class ProjectileAim{

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the spawn location (at the mob's eyes, facing the shot) and the motion of a projectile shot at the
	 * target, or null if the target is at the mob's eyes.
	 *
	 * @phpstan-return array{Location, Vector3}|null
	 */
	public static function aim(Mob $shooter, Living $target, float $speed, float $inaccuracy) : ?array{
		$eye = $shooter->getEyePos()->subtract(0, 0.1, 0);
		$targetLoc = $target->getLocation();
		$dx = $targetLoc->x - $eye->x;
		$dy = $targetLoc->y + $target->getSize()->getHeight() / 3 - $eye->y;
		$dz = $targetLoc->z - $eye->z;
		$horizontal = sqrt($dx * $dx + $dz * $dz);
		$dir = new Vector3($dx, $dy + $horizontal * 0.2, $dz);
		$len = $dir->length();
		if($len < 0.0001){
			return null;
		}
		$dir = $dir->divide($len)->add(self::gaussian() * $inaccuracy, self::gaussian() * $inaccuracy, self::gaussian() * $inaccuracy);
		$motion = $dir->multiply($speed);
		$yaw = atan2($motion->z, $motion->x) / M_PI * 180 - 90;
		$pitch = -atan2($motion->y, sqrt($motion->x ** 2 + $motion->z ** 2)) / M_PI * 180;
		return [Location::fromObject($eye, $shooter->getWorld(), $yaw, $pitch), $motion];
	}

	private static function gaussian() : float{
		return (Utils::getRandomFloat() + Utils::getRandomFloat() + Utils::getRandomFloat() - 1.5) * 2;
	}
}
