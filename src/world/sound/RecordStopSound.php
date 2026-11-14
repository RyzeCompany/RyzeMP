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

use pocketmine\block\utils\RecordType;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\StopSoundPacket;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;

class RecordStopSound implements Sound{

	/**
	 * @param RecordType|null $recordType the record that was playing: 1.26 clients ignore record.null and keep playing,
	 *                                    so its sound is also stopped by name
	 */
	public function __construct(private ?RecordType $recordType = null){}

	public function encode(Vector3 $pos) : array{
		$packets = [LevelSoundEventPacket::nonActorSound(LevelSoundEvent::RECORD_NULL, $pos, false)];
		if($this->recordType !== null){
			$packets[] = StopSoundPacket::create(RecordSound::getSoundName($this->recordType), false, false);
		}
		return $packets;
	}
}
