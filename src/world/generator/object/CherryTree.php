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
 * Cherry tree. Vanilla cherry trees have a bent trunk with up to three branches; this is a simplified shape: a straight
 * trunk under a wide, flat, rounded canopy of cherry leaves.
 */
class CherryTree extends Tree{

	public function __construct(){
		parent::__construct(VanillaBlocks::CHERRY_LOG(), VanillaBlocks::CHERRY_LEAVES(), 7);
	}

	public function getBlockTransaction(ChunkManager $world, int $x, int $y, int $z, Random $random) : ?BlockTransaction{
		$this->treeHeight = $random->nextBoundedInt(3) + 6;
		return parent::getBlockTransaction($world, $x, $y, $z, $random);
	}

	protected function placeCanopy(int $x, int $y, int $z, Random $random, BlockTransaction $transaction) : void{
		$top = $y + $this->treeHeight - 1;
		//[y offset from the top of the trunk, radius]
		foreach([[-1, 3.5], [0, 3.5], [1, 2.5], [2, 1.5]] as [$yOff, $radius]){
			$yy = $top + $yOff;
			$r = (int) $radius;
			for($xx = -$r; $xx <= $r; ++$xx){
				for($zz = -$r; $zz <= $r; ++$zz){
					$distanceSquared = $xx * $xx + $zz * $zz;
					if($distanceSquared > $radius * $radius || ($distanceSquared > ($radius - 1) * ($radius - 1) && $random->nextBoundedInt(4) === 0)){
						continue;
					}
					if(!$transaction->fetchBlockAt($x + $xx, $yy, $z + $zz)->isSolid()){
						$transaction->addBlockAt($x + $xx, $yy, $z + $zz, $this->leafBlock);
					}
				}
			}
		}
	}
}
