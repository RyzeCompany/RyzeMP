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

namespace pocketmine\block\trialspawner;

use function array_keys;
use function str_contains;

/**
 * The vanilla trial spawner configs ("minecraft:trial_chamber/melee/zombie/normal"...) that trial chamber spawners
 * refer to by name.
 */
final class TrialSpawnerConfigs{
	/** @phpstan-var array<string, TrialSpawnerConfig> */
	private static array $configs = [];
	private static ?TrialSpawnerConfig $default = null;

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the vanilla config with the given name, or null if there is none.
	 */
	public static function get(string $name) : ?TrialSpawnerConfig{
		$name = str_contains($name, ":") ? $name : "minecraft:" . $name;
		if(isset(self::$configs[$name])){
			return self::$configs[$name];
		}
		$data = TrialSpawnerConfigData::CONFIGS[$name] ?? null;
		return $data !== null ? self::$configs[$name] = TrialSpawnerConfig::fromArray($data) : null;
	}

	/**
	 * The config of a spawner that has none (spawns nothing until a spawn egg is used on it).
	 */
	public static function getDefault() : TrialSpawnerConfig{
		return self::$default ??= new TrialSpawnerConfig();
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function getNames() : array{
		return array_keys(TrialSpawnerConfigData::CONFIGS);
	}
}
