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

/**
 * Pale oak tree, grown from a 2x2 square of pale oak saplings (x, z is the north-west sapling). Simplified shape: a 2x2
 * trunk under a dark-oak-like canopy, with pale hanging moss below some of the lowest leaves.
 */
class PaleOakTree extends DarkOakTree{

	public function __construct(){
		parent::__construct(VanillaBlocks::PALE_OAK_LOG(), VanillaBlocks::PALE_OAK_LEAVES());
	}

	protected function pickHeight(Random $random) : int{
		return 7 + $random->nextBoundedInt(3);
	}

	protected function decorateLowestLeaves(array $lowest, Random $random, BlockTransaction $transaction) : void{
		foreach($lowest as [$lx, $ly, $lz]){
			if($random->nextBoundedInt(6) === 0 && $transaction->fetchBlockAt($lx, $ly - 1, $lz)->canBeReplaced()){
				$transaction->addBlockAt($lx, $ly - 1, $lz, VanillaBlocks::PALE_HANGING_MOSS()->setTip(true));
			}
		}
	}
}
