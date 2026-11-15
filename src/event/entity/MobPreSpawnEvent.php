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

namespace pocketmine\event\entity;

use pocketmine\entity\SpawnReason;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\event\Event;
use pocketmine\world\Position;

/**
 * Called before the natural spawner creates a mob (or a herd), like Paper's PreCreatureSpawnEvent: nothing has been
 * created yet, so cancelling costs nothing. A plugin with its own mobs can use it to stop the server's natural
 * spawning of some types, e.g. cancel every "minecraft:zombie" with reason {@link SpawnReason::NATURAL}.
 *
 * {@link EntityNaturalSpawnEvent} follows for each mob that is created.
 */
class MobPreSpawnEvent extends Event implements Cancellable{
	use CancellableTrait;

	public function __construct(
		private string $entityIdentifier,
		private Position $position,
		private string $reason
	){}

	/**
	 * The mob's identifier, e.g. "minecraft:zombie".
	 */
	public function getEntityIdentifier() : string{
		return $this->entityIdentifier;
	}

	/**
	 * Where the mob (or the first mob of the herd) would spawn.
	 */
	public function getPosition() : Position{
		return $this->position;
	}

	/**
	 * One of the {@link SpawnReason} constants.
	 */
	public function getReason() : string{
		return $this->reason;
	}
}
