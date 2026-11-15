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
 * Why a mob came into the world (like Paper's CreatureSpawnEvent.SpawnReason). It is kept with the mob
 * ({@link Living::getSpawnReason()}) and saved with it. The natural spawner uses it for its population caps
 * (mobs.spawning.count-all-mobs-for-spawning in pocketmine.yml).
 *
 * Plugins may use their own reasons (any string); mobs of plugin classes count as {@link SpawnReason::CUSTOM}.
 */
final class SpawnReason{
	/** The reason was not recorded (mobs created before reasons existed, or by code that does not set one). */
	public const DEFAULT = "default";
	/** Natural spawning around players with the spawn rules. */
	public const NATURAL = "natural";
	/** Spawns of a structure (witch huts, nether fortresses, ocean monuments...). */
	public const STRUCTURE = "structure";
	/** A wandering trader and its llamas. */
	public const WANDERING_TRADER = "wandering_trader";
	/** A stray cat of a village. */
	public const VILLAGE_CAT = "village_cat";
	/** A pillager patrol. */
	public const PATROL = "patrol";
	/** A raid wave. */
	public const RAID = "raid";
	/** A spawn egg used by a player or a dispenser. */
	public const SPAWN_EGG = "spawn_egg";
	/** A monster spawner block. */
	public const SPAWNER = "spawner";
	/** A trial spawner block (its mobs are persistent). */
	public const TRIAL_SPAWNER = "trial_spawner";
	/** Two mobs bred. */
	public const BREEDING = "breeding";
	/** A plugin spawned the mob. */
	public const CUSTOM = "custom";

	/** Reasons that count as natural spawning when count-all-mobs-for-spawning is false. */
	public const NATURAL_REASONS = [self::NATURAL, self::STRUCTURE, self::WANDERING_TRADER, self::VILLAGE_CAT, self::PATROL];

	private function __construct(){
		//NOOP
	}
}
