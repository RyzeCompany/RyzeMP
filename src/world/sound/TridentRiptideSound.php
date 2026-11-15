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

namespace pocketmine\world\sound;

use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;

/**
 * Played when a player launches itself with a Riptide trident (one sound per enchantment level, 1-3).
 */
final class TridentRiptideSound implements Sound{

	public function __construct(private int $level){}

	public function getLevel() : int{ return $this->level; }

	public function encode(Vector3 $pos) : array{
		$sound = match(true){
			$this->level >= 3 => LevelSoundEvent::ITEM_TRIDENT_RIPTIDE_3,
			$this->level === 2 => LevelSoundEvent::ITEM_TRIDENT_RIPTIDE_2,
			default => LevelSoundEvent::ITEM_TRIDENT_RIPTIDE_1
		};
		return [LevelSoundEventPacket::nonActorSound($sound, $pos, false)];
	}
}
