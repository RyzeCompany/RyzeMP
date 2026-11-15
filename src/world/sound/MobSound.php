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

/**
 * A sound made by a mob (ambient, hurt, death, milking, shearing, fuse...). The client picks the actual sound from
 * the entity type, e.g. "ambient" for "minecraft:cow" is a moo.
 *
 * @see \pocketmine\network\mcpe\protocol\types\LevelSoundEvent
 */
final class MobSound implements Sound{

	public function __construct(
		private string $sound,
		private string $entityTypeId,
		private bool $baby = false,
		private int $extraData = -1
	){}

	public function getSound() : string{ return $this->sound; }

	public function encode(Vector3 $pos) : array{
		return [LevelSoundEventPacket::create(
			$this->sound,
			$pos,
			$this->extraData,
			$this->entityTypeId,
			$this->baby,
			false,
			-1,
			null
		)];
	}
}
