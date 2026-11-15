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
use pocketmine\world\BlockTransaction;
use pocketmine\world\ChunkManager;

/**
 * Poplar tree (Bedrock 1.26.40). Vanilla poplars are tall and narrow; this uses the oak shape with a taller trunk and a
 * random autumn leaf colour until a dedicated shape is implemented.
 */
class PoplarTree extends Tree{

	public function __construct(Random $random){
		$leaves = match($random->nextBoundedInt(3)){
			0 => VanillaBlocks::ORANGE_POPLAR_LEAVES(),
			1 => VanillaBlocks::RED_POPLAR_LEAVES(),
			default => VanillaBlocks::YELLOW_POPLAR_LEAVES()
		};
		parent::__construct(VanillaBlocks::POPLAR_LOG(), $leaves);
	}

	public function getBlockTransaction(ChunkManager $world, int $x, int $y, int $z, Random $random) : ?BlockTransaction{
		$this->treeHeight = $random->nextBoundedInt(3) + 6;
		return parent::getBlockTransaction($world, $x, $y, $z, $random);
	}
}
