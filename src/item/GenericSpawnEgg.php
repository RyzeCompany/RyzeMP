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

namespace pocketmine\item;

/**
 * Spawn egg identified only by the Bedrock identifier of its mob. Using it spawns the entity registered for that
 * identifier in {@link \pocketmine\entity\EntitySpawnRegistry}, or does nothing if there is none.
 */
class GenericSpawnEgg extends SpawnEgg{

	public function __construct(
		ItemIdentifier $identifier,
		string $name,
		private string $entityTypeId
	){
		parent::__construct($identifier, $name);
	}

	/**
	 * Returns the Bedrock identifier of the mob in this egg, e.g. "minecraft:cow".
	 */
	public function getEntityTypeId() : string{
		return $this->entityTypeId;
	}
}
