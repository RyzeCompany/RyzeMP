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

use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelEventPacket;

/**
 * Sculk charge moving through sculk spread by a sculk catalyst (LevelEvent SculkCharge, 2037), or popping when the
 * charge runs out (SculkChargePop, 2038). Neither is in BedrockProtocol's LevelEvent list.
 *
 * The data is encoded like Java's level event 3006: (particle count << 6) | faces, the faces being the sculk vein
 * faces the charge sits on (bit per face in Bedrock multi_face_direction_bits order: down, up, south, west, north,
 * east), 0 for a full sculk block.
 */
class SculkChargeParticle implements Particle{
	public const LEVEL_EVENT_SCULK_CHARGE = 2037;
	public const LEVEL_EVENT_SCULK_CHARGE_POP = 2038;

	public function __construct(
		private int $count,
		private int $faces = 0,
		private bool $pop = false
	){}

	public function encode(Vector3 $pos) : array{
		return [$this->pop ?
			LevelEventPacket::create(self::LEVEL_EVENT_SCULK_CHARGE_POP, 0, $pos) :
			LevelEventPacket::create(self::LEVEL_EVENT_SCULK_CHARGE, ($this->count << 6) | $this->faces, $pos)
		];
	}
}
