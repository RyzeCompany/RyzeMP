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

namespace pocketmine\world\generator\vanilla\structure\template;

use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;

/** Java entity IDs stored in structure spawners, converted to Bedrock identifiers. */
final class EntityConversion{
	/** Java entity ids whose Bedrock identifier differs. */
	private const BEDROCK_IDS = [
		"minecraft:villager" => "minecraft:villager_v2",
		"minecraft:zombie_villager" => "minecraft:zombie_villager_v2",
		"minecraft:zombified_piglin" => "minecraft:zombie_pigman",
		"minecraft:evoker" => "minecraft:evocation_illager",
		"minecraft:snow_golem" => "minecraft:snow_golem",
	];

	private function __construct(){
		//NOOP
	}

	public static function bedrockId(string $javaId) : string{
		$javaId = JavaBlocks::namespaced($javaId);
		return self::BEDROCK_IDS[$javaId] ?? $javaId;
	}

}
