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

namespace pocketmine\entity;

/**
 * An entity a lead can be put on (vanilla minecraft:leashable, see {@link LeashTrait}): every {@link Mob}, squids,
 * which are {@link WaterAnimal}s rather than mobs, and boats.
 */
interface Leashable{

	/**
	 * Whether a lead can be put on it.
	 */
	public function canBeLeashed() : bool;

	public function isLeashed() : bool;

	/**
	 * Returns the player, knot or mob holding the lead, if it is loaded.
	 */
	public function getLeashHolder() : ?Entity;

	/**
	 * Whether the given entity holds the lead (or is the saved mob holder not found again yet).
	 */
	public function isLeashedTo(Entity $holder) : bool;

	public function setLeashHolder(Entity $holder) : void;

	/**
	 * Removes the lead; it drops as an item if asked.
	 */
	public function unleash(bool $dropLead) : void;
}
