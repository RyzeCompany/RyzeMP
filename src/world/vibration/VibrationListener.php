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

namespace pocketmine\world\vibration;

use pocketmine\block\Block;
use pocketmine\entity\Entity;
use pocketmine\math\Vector3;

/**
 * Something other than a sculk sensor block that hears vibrations, e.g. a warden. Listeners are registered in the
 * {@link VibrationManager} of their world; they receive every vibration emitted within their range that isn't
 * blocked by wool, at once (the listener decides when the vibration "arrives").
 */
interface VibrationListener{

	/**
	 * Position the listener hears from.
	 */
	public function getListenerPosition() : Vector3;

	/**
	 * Maximum distance in blocks at which the listener hears vibrations.
	 */
	public function getListenerRange() : int;

	/**
	 * Called when a vibration is emitted within range.
	 *
	 * @param Vector3     $pos      where the vibration was emitted
	 * @param Entity|null $source   the entity which caused it (for projectiles, the projectile itself)
	 * @param Block|null  $affected the block placed or broken, if any
	 * @param float       $distance distance between the vibration and the listener
	 */
	public function onVibration(Vector3 $pos, GameEvent $event, ?Entity $source, ?Block $affected, float $distance) : void;
}
