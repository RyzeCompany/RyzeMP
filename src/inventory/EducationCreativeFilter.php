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

namespace pocketmine\inventory;

use function in_array;
use function str_starts_with;

/**
 * Recognises the creative inventory entries that vanilla Bedrock only shows in worlds with the "Education Edition"
 * option: the Chemistry content of Minecraft Education (https://minecraft.wiki/w/Minecraft_Education).
 *
 * The vanilla creative data keeps them in four groups (elements, chemistry tables, compounds and products); the item
 * IDs are also checked, in case an entry is ever moved out of those groups. Education-only blocks that aren't in
 * Bedrock's creative inventory at all (allow, deny, border, chalkboard, camera, portfolio, photo, agent and NPC spawn
 * eggs) need no filtering.
 */
final class EducationCreativeFilter{

	private function __construct(){
		//NOOP
	}

	public const GROUPS = [
		"itemGroup.name.element",
		"itemGroup.name.chemistrytable",
		"itemGroup.name.compounds",
		"itemGroup.name.products",
	];

	public const ITEM_IDS = [
		"minecraft:balloon",
		"minecraft:bleach",
		"minecraft:chemical_heat",
		"minecraft:chemistry_table",
		"minecraft:compound",
		"minecraft:compound_creator",
		"minecraft:element_constructor",
		"minecraft:glow_stick",
		"minecraft:ice_bomb",
		"minecraft:lab_table",
		"minecraft:material_reducer",
		"minecraft:medicine",
		"minecraft:rapid_fertilizer",
		"minecraft:sparkler",
		"minecraft:underwater_tnt",
		"minecraft:underwater_torch",
	];

	public const ITEM_ID_PREFIXES = [
		"minecraft:element_", //element_0 ... element_118
		"minecraft:hard_", //hardened glass, stained glass and panes (not hardened_clay)
		"minecraft:colored_torch_",
	];

	public static function isEducationGroup(string $groupName) : bool{
		return in_array($groupName, self::GROUPS, true);
	}

	public static function isEducationItem(string $itemId) : bool{
		if(in_array($itemId, self::ITEM_IDS, true)){
			return true;
		}
		foreach(self::ITEM_ID_PREFIXES as $prefix){
			if(str_starts_with($itemId, $prefix)){
				return true;
			}
		}
		return false;
	}
}
