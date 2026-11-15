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

namespace pocketmine\world\mobs\spawn;

use function max;
use function mt_rand;

/**
 * One entry of a spawn rule's "minecraft:herd" component: how many mobs spawn together, and the entity events applied
 * to them ("event" to the members after the first "event_skip_count", "initial_event" to the first
 * "initial_event_count").
 */
final class SpawnHerd{
	public function __construct(
		public readonly int $minSize,
		public readonly int $maxSize,
		public readonly ?string $event = null,
		public readonly int $eventSkipCount = 0,
		public readonly ?string $initialEvent = null,
		public readonly int $initialEventCount = 0
	){}

	public function rollSize() : int{
		return mt_rand(max(1, $this->minSize), max(1, $this->minSize, $this->maxSize));
	}

	/**
	 * Returns the event applied to the herd member at this index (0 = the first one spawned), if any.
	 */
	public function getEventFor(int $index) : ?string{
		if($this->initialEvent !== null && $index < $this->initialEventCount){
			return $this->initialEvent;
		}
		if($this->event !== null && $index >= $this->eventSkipCount){
			return $this->event;
		}
		return null;
	}
}
