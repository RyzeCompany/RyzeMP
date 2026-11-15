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

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\Durable;
use pocketmine\math\Facing;
use pocketmine\world\sound\ItemUseOnBlockSound;

/**
 * Shovels flatten grass, dirt, coarse dirt, rooted dirt, podzol and mycelium into dirt paths, and hoes till dirt paths
 * into farmland. Both need air above the block and a click on any face but the bottom (vanilla).
 *
 * @internal
 */
final class DirtPathHelper{

	private function __construct(){
		//NOOP
	}

	/**
	 * Turns the block into a dirt path. Returns false (nothing done) when the face or the block above doesn't allow it.
	 */
	public static function flatten(Block $block, Durable $item, int $face) : bool{
		return self::convert($block, $item, $face, VanillaBlocks::GRASS_PATH());
	}

	/**
	 * Turns the block into farmland. Returns false (nothing done) when the face or the block above doesn't allow it.
	 */
	public static function till(Block $block, Durable $item, int $face) : bool{
		return self::convert($block, $item, $face, VanillaBlocks::FARMLAND());
	}

	private static function convert(Block $block, Durable $item, int $face, Block $newBlock) : bool{
		if($face === Facing::DOWN || $block->getSide(Facing::UP)->getTypeId() !== BlockTypeIds::AIR){
			return false;
		}
		$position = $block->getPosition();
		$world = $position->getWorld();
		$item->applyDamage(1);
		$world->addSound($position->add(0.5, 0.5, 0.5), new ItemUseOnBlockSound($newBlock));
		$world->setBlock($position, $newBlock);
		return true;
	}
}
