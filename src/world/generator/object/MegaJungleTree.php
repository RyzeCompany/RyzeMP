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
use pocketmine\math\Facing;
use pocketmine\utils\Random;
use pocketmine\world\BlockTransaction;
use pocketmine\world\ChunkManager;
use function cos;
use function floor;
use function sin;
use const M_PI;

/**
 * Large jungle tree, grown from a 2x2 square of jungle saplings (x, z is the north-west sapling).
 *
 * Vanilla (https://minecraft.wiki/w/Jungle_tree, Java's MegaJungleTrunkPlacer): a 2x2 trunk 10-31 blocks tall with a
 * round crown on top, branches every few blocks on the upper part of the trunk that end in small clusters of leaves, and
 * vines on the trunk.
 */
class MegaJungleTree extends TwoByTwoTree{

	public function __construct(){
		parent::__construct(VanillaBlocks::JUNGLE_LOG(), VanillaBlocks::JUNGLE_LEAVES(), 15);
	}

	public function getBlockTransaction(ChunkManager $world, int $x, int $y, int $z, Random $random) : ?BlockTransaction{
		$this->treeHeight = 10 + $random->nextBoundedInt(3) + $random->nextBoundedInt(20);
		return parent::getBlockTransaction($world, $x, $y, $z, $random);
	}

	protected function generateTrunkHeight(Random $random) : int{
		return $this->treeHeight;
	}

	protected function placeCanopy(int $x, int $y, int $z, Random $random, BlockTransaction $transaction) : void{
		$top = $y + $this->treeHeight;
		$cx = $x + 1;
		$cz = $z + 1;
		foreach([[-2, 3.5], [-1, 4.0], [0, 3.5], [1, 2.5]] as [$yOff, $radius]){
			$this->placeLeafDisc($transaction, $cx, $top + $yOff, $cz, $radius, $random);
		}

		//branches on the upper part of the trunk
		for($by = $top - 2 - $random->nextBoundedInt(4); $by > $y + $this->treeHeight / 2; $by -= 2 + $random->nextBoundedInt(4)){
			$angle = $random->nextFloat() * M_PI * 2;
			$length = 2 + $random->nextBoundedInt(3);
			$end = null;
			for($i = 1; $i <= $length; ++$i){
				$bx = (int) floor($cx + cos($angle) * (0.5 + $i));
				$bz = (int) floor($cz + sin($angle) * (0.5 + $i));
				$branchY = $by + ($i >> 1);
				if(!$this->canOverride($transaction->fetchBlockAt($bx, $branchY, $bz))){
					break;
				}
				$transaction->addBlockAt($bx, $branchY, $bz, $this->trunkBlock);
				$end = [$bx, $branchY, $bz];
			}
			if($end !== null){
				[$endX, $endY, $endZ] = $end;
				$this->placeLeafDisc($transaction, $endX + 0.5, $endY, $endZ + 0.5, 2.5, $random);
				$this->placeLeafDisc($transaction, $endX + 0.5, $endY + 1, $endZ + 0.5, 1.5, $random);
			}
		}

		//vines on the sides of the trunk
		$sides = [
			Facing::WEST => [[-1, 0], [-1, 1]],
			Facing::EAST => [[2, 0], [2, 1]],
			Facing::NORTH => [[0, -1], [1, -1]],
			Facing::SOUTH => [[0, 2], [1, 2]],
		];
		for($yy = $y; $yy < $top - 1; ++$yy){
			foreach($sides as $side => $positions){
				foreach($positions as [$dx, $dz]){
					if($random->nextBoundedInt(3) > 0 || !$transaction->fetchBlockAt($x + $dx, $yy, $z + $dz)->canBeReplaced()){
						continue;
					}
					//the vine hangs on the trunk, which is on the opposite side
					$transaction->addBlockAt($x + $dx, $yy, $z + $dz, VanillaBlocks::VINES()->setFace(Facing::opposite($side), true));
				}
			}
		}
	}
}
