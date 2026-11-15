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

namespace pocketmine\block\utils;

use pocketmine\block\RuntimeBlockStateRegistry;

/**
 * Oxidation of the unwaxed copper block states, by state ID, for the weathering neighbour check.
 *
 * @internal
 */
final class CopperWeathering{
	/** Cache value of states that are not unwaxed copper (waxed copper and every other block). */
	public const NOT_WEATHERING_COPPER = -1;

	/**
	 * @var int[] state ID => {@link CopperOxidation} value of unwaxed copper, or NOT_WEATHERING_COPPER
	 * @phpstan-var array<int, int>
	 */
	private static array $oxidationByStateId = [];

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the oxidation value of the state if it is unwaxed copper, {@link self::NOT_WEATHERING_COPPER} otherwise.
	 */
	public static function getUnwaxedOxidation(int $stateId) : int{
		if(isset(self::$oxidationByStateId[$stateId])){
			return self::$oxidationByStateId[$stateId];
		}
		$block = RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId);
		return self::$oxidationByStateId[$stateId] = $block instanceof CopperMaterial && !$block->isWaxed() ?
			$block->getOxidation()->value :
			self::NOT_WEATHERING_COPPER;
	}
}
