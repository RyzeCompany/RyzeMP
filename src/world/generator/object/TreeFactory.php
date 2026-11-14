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

namespace pocketmine\world\generator\object;

use pocketmine\block\VanillaBlocks;
use pocketmine\utils\Random;

final class TreeFactory{

	/**
	 * @param TreeType|null $type default oak
	 */
	public static function get(Random $random, ?TreeType $type = null) : ?Tree{
		return match($type){
			null, TreeType::OAK => new OakTree(), //TODO: big oak has a 1/10 chance
			TreeType::SPRUCE => new SpruceTree(),
			TreeType::JUNGLE => new JungleTree(),
			TreeType::ACACIA => new AcaciaTree(),
			TreeType::BIRCH => new BirchTree($random->nextBoundedInt(39) === 0),
			TreeType::AZALEA => new AzaleaTree(),
			TreeType::POPLAR => new PoplarTree($random),
			TreeType::CHERRY => new CherryTree(),
			TreeType::PALE_OAK => new PaleOakTree(),
			TreeType::CRIMSON => new NetherTree(VanillaBlocks::CRIMSON_STEM(), VanillaBlocks::NETHER_WART_BLOCK(), VanillaBlocks::SHROOMLIGHT(), ($random->nextBoundedInt(9) + 4) * ($random->nextBoundedInt(12) === 0 ? 2 : 1), hasVines: true, huge: $random->nextFloat() < 0.06),
			TreeType::WARPED => new NetherTree(VanillaBlocks::WARPED_STEM(), VanillaBlocks::WARPED_WART_BLOCK(), VanillaBlocks::SHROOMLIGHT(), ($random->nextBoundedInt(9) + 4) * ($random->nextBoundedInt(12) === 0 ? 2 : 1), hasVines: false, huge: $random->nextFloat() < 0.06),
			default => null,
		};
	}

	/**
	 * Returns the tree grown from a 2x2 square of saplings of the given type, or null if that type has no such tree.
	 */
	public static function getTwoByTwo(Random $random, TreeType $type) : ?Tree{
		return match($type){
			TreeType::SPRUCE => new MegaSpruceTree(),
			TreeType::JUNGLE => new MegaJungleTree(),
			TreeType::DARK_OAK => new DarkOakTree(),
			TreeType::PALE_OAK => new PaleOakTree(),
			default => null,
		};
	}
}
