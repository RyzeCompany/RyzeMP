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

namespace pocketmine\crafting;

use pocketmine\block\utils\DyeColor;
use pocketmine\item\Dye;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;

/**
 * Dye lookup shared by the hardcoded crafting recipes (fireworks, banner patterns).
 *
 * @internal
 */
final class DyeItems{

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the dye colour of a dye item, including the legacy Bedrock dye items (ink sac, cocoa beans, lapis lazuli,
	 * bone meal) which the client accepts as black, brown, blue and white dye.
	 */
	public static function colorOf(Item $item) : ?DyeColor{
		if($item instanceof Dye){
			return $item->getColor();
		}
		return match($item->getTypeId()){
			ItemTypeIds::INK_SAC => DyeColor::BLACK,
			ItemTypeIds::COCOA_BEANS => DyeColor::BROWN,
			ItemTypeIds::LAPIS_LAZULI => DyeColor::BLUE,
			ItemTypeIds::BONE_MEAL => DyeColor::WHITE,
			default => null
		};
	}
}
