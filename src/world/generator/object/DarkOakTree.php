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

use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\utils\Random;
use pocketmine\world\BlockTransaction;
use pocketmine\world\ChunkManager;

/**
 * Dark oak tree, grown from a 2x2 square of dark oak saplings (x, z is the north-west sapling).
 *
 * Simplified from vanilla's dark oak (https://minecraft.wiki/w/Dark_Oak): a straight 2x2 trunk 6-8 blocks tall under a
 * wide, flat canopy, with a short branch of logs and leaves sticking out at random. Vanilla's leaning trunk is not
 * reproduced.
 */
class DarkOakTree extends TwoByTwoTree{

	public function __construct(?Block $trunkBlock = null, ?Block $leafBlock = null){
		parent::__construct($trunkBlock ?? VanillaBlocks::DARK_OAK_LOG(), $leafBlock ?? VanillaBlocks::DARK_OAK_LEAVES(), 7);
	}

	protected function pickHeight(Random $random) : int{
		return 6 + $random->nextBoundedInt(3);
	}

	public function getBlockTransaction(ChunkManager $world, int $x, int $y, int $z, Random $random) : ?BlockTransaction{
		$this->treeHeight = $this->pickHeight($random);
		return parent::getBlockTransaction($world, $x, $y, $z, $random);
	}

	protected function placeCanopy(int $x, int $y, int $z, Random $random, BlockTransaction $transaction) : void{
		$top = $y + $this->treeHeight - 1;
		$cx = $x + 1;
		$cz = $z + 1;
		$lowest = [];
		//[y offset from the top of the trunk, radius around the centre of the trunk]
		foreach([[-2, 3.5], [-1, 4.0], [0, 3.5], [1, 2.5]] as [$yOff, $radius]){
			$placed = $this->placeLeafDisc($transaction, $cx, $top + $yOff, $cz, $radius, $random);
			if($yOff === -2){
				$lowest = $placed;
			}
		}

		//a few short branches below the canopy, each ending in a small cluster of leaves
		$branches = $random->nextBoundedInt(3);
		for($i = 0; $i < $branches; ++$i){
			[$dx, $dz] = [[-1, 0], [2, 0], [0, -1], [0, 2]][$random->nextBoundedInt(4)];
			$bx = $x + ($dx < 0 ? -1 : ($dx > 1 ? 2 : $random->nextBoundedInt(2)));
			$bz = $z + ($dz < 0 ? -1 : ($dz > 1 ? 2 : $random->nextBoundedInt(2)));
			$by = $top - 3 - $random->nextBoundedInt(2);
			if($by <= $y + 1 || !$this->canOverride($transaction->fetchBlockAt($bx, $by, $bz))){
				continue;
			}
			$transaction->addBlockAt($bx, $by, $bz, $this->trunkBlock);
			$this->placeLeafDisc($transaction, $bx + 0.5, $by + 1, $bz + 0.5, 1.5, $random, false);
		}

		$this->decorateLowestLeaves($lowest, $random, $transaction);
	}

	/**
	 * @param int[][] $lowest positions of the leaves in the lowest layer of the canopy
	 * @phpstan-param list<array{int, int, int}> $lowest
	 */
	protected function decorateLowestLeaves(array $lowest, Random $random, BlockTransaction $transaction) : void{

	}
}
