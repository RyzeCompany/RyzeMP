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

namespace pocketmine\world\particle;

use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelEvent;

/**
 * Smoke puff in front of a dispenser or dropper which dispensed an item (LevelEvent 2000). The data is the horizontal
 * direction as (x + 1) + (z + 1) * 3, as in the legacy Java level event this one comes from; 4 (centre) for up/down.
 */
class DispenseParticle implements Particle{
	public function __construct(private int $facing){}

	public function encode(Vector3 $pos) : array{
		[$dx, , $dz] = Facing::OFFSET[$this->facing];
		return [LevelEventPacket::create(LevelEvent::PARTICLE_SHOOT, ($dx + 1) + ($dz + 1) * 3, $pos)];
	}
}
