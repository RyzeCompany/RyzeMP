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

use function str_contains;
use function str_ends_with;
use function str_starts_with;

/**
 * Blocks whose Java "solid" flag is forced (forceSolidOn / forceSolidOff in Java's block properties) instead of being
 * derived from their collision shape, as of Java 26.3. Blocks with a dynamic shape (no shape cache) are not solid.
 */
final class SolidityRules{
	private const FORCED_OFF = [
		"azalea" => true, "big_dripleaf" => true, "chorus_flower" => true, "chorus_plant" => true, "end_rod" => true,
		"flowering_azalea" => true, "ladder" => true, "snow" => true,
	];
	private const FORCED_ON = [
		"amethyst_cluster" => true, "bamboo" => true, "bamboo_sapling" => true, "bell" => true, "cake" => true,
		"cobweb" => true, "conduit" => true, "dried_ghast" => true, "lantern" => true, "soul_lantern" => true,
		"oak_fence" => true, "pointed_dripstone" => true, "sculk_vein" => true, "sulfur_spike" => true,
		"turtle_egg" => true, "moving_piston" => true, "iron_chain" => true,
	];
	private const DYNAMIC_SHAPE = [
		"scaffolding" => true, "piston_head" => false,
	];

	private function __construct(){
		//NOOP
	}

	public static function forced(string $name) : ?bool{
		if(isset(self::FORCED_OFF[$name])){
			return false;
		}
		if(isset(self::FORCED_ON[$name])){
			return true;
		}
		if(
			str_ends_with($name, "_fence_gate") || str_ends_with($name, "_sign") || str_ends_with($name, "_pressure_plate") ||
			str_ends_with($name, "_wall") || str_ends_with($name, "shulker_box") || str_ends_with($name, "_banner") ||
			str_ends_with($name, "_chain") || str_ends_with($name, "_lantern") || str_ends_with($name, "_chest") && str_contains($name, "copper") ||
			(str_starts_with($name, "dead_") && str_contains($name, "coral"))
		){
			return true;
		}
		if((self::DYNAMIC_SHAPE[$name] ?? false) === true){
			return false;
		}
		return null;
	}
}
