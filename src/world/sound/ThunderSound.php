<?php

/*
 * VanillaMine weather integration for RyzeMP (LGPL-3.0-or-later).
 */
declare(strict_types=1);

namespace pocketmine\world\sound;

use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;

/** Bedrock's standard thunder sound, encoded by each player's multiversion session. */
final class ThunderSound implements Sound{
	public function encode(Vector3 $pos) : array{
		return [LevelSoundEventPacket::nonActorSound(LevelSoundEvent::THUNDER, $pos, false)];
	}
}
