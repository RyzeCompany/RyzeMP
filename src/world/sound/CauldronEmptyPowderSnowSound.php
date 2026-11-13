<?php

/*
 * Adapted from VanillaMine/PocketMine-MP (LGPL-3.0-or-later).
 */
declare(strict_types=1);

namespace pocketmine\world\sound;

use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelEvent;

final class CauldronEmptyPowderSnowSound implements Sound{
	public function encode(Vector3 $pos) : array{
		return [LevelEventPacket::create(LevelEvent::SOUND_CAULDRON_TAKE_POWDER_SNOW, 0, $pos)];
	}
}
