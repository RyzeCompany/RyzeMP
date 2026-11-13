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

namespace pocketmine\world\generator\vanilla\feature\block;

use pocketmine\world\generator\vanilla\feature\FeatureLevel;

/**
 * Placement rules of pointed dripstone and sulfur spikes (Java's SpeleothemBlock).
 */
final class SpeleothemRules{

	private function __construct(){
		//NOOP
	}

	/**
	 * Whether the state is a speleothem (pointed dripstone, sulfur spike) pointing in that direction (UP or DOWN).
	 */
	public static function isWithDirection(JavaBlocks $blocks, int $state, int $direction) : bool{
		return $blocks->isTagged($state, "speleothems") && $blocks->getProperty($state, "vertical_direction") === ($direction === Direction::UP ? "up" : "down");
	}

	/**
	 * SpeleothemBlock.isValidSpeleothemPlacement(): the block behind the tip is sturdy, or a speleothem of the same
	 * block pointing the same way.
	 */
	public static function isValidPlacement(FeatureLevel $level, JavaBlocks $blocks, int $x, int $y, int $z, int $tipDirection, ?int $block = null) : bool{
		$by = $tipDirection === Direction::UP ? $y - 1 : $y + 1;
		$behind = $level->getState($x, $by, $z);
		if($blocks->isFaceSturdy($behind, $tipDirection)){
			return true;
		}
		return self::isWithDirection($blocks, $behind, $tipDirection) && ($block === null || $blocks->blockOf[$behind] === $block);
	}
}
