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

use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\utils\Random;
use pocketmine\world\BlockTransaction;
use pocketmine\world\ChunkManager;
use function min;

/**
 * Giant spruce, grown from a 2x2 square of spruce saplings (x, z is the north-west sapling).
 *
 * Vanilla (https://minecraft.wiki/w/Spruce, Java's MegaPine foliage): a 2x2 trunk 13-28 blocks tall with a narrow
 * conical crown over its top half, and podzol around the base. The crown here grows one block wider every three layers
 * down, up to a radius of 4.
 */
class MegaSpruceTree extends TwoByTwoTree{

	public function __construct(){
		parent::__construct(VanillaBlocks::SPRUCE_LOG(), VanillaBlocks::SPRUCE_LEAVES(), 15);
	}

	public function getBlockTransaction(ChunkManager $world, int $x, int $y, int $z, Random $random) : ?BlockTransaction{
		$this->treeHeight = 13 + $random->nextBoundedInt(3) + $random->nextBoundedInt(14);
		return parent::getBlockTransaction($world, $x, $y, $z, $random);
	}

	protected function generateTrunkHeight(Random $random) : int{
		return $this->treeHeight - 1;
	}

	protected function placeCanopy(int $x, int $y, int $z, Random $random, BlockTransaction $transaction) : void{
		$top = $y + $this->treeHeight;
		$crownHeight = min($this->treeHeight - 4, 13 + $random->nextBoundedInt(5));
		for($depth = 0; $depth < $crownHeight; ++$depth){
			//a tip one leaf wide, then layers that alternate between a bit wider and a bit narrower, like vanilla
			$radius = $depth === 0 ? 0.71 : min(4.0, 1.0 + (int) ($depth / 3) + ($depth % 2 === 0 ? 0.5 : 0.0));
			$this->placeLeafDisc($transaction, $x + 1, $top - $depth, $z + 1, $radius + 0.5, $random, $depth > 0);
		}

		//podzol on the ground around the trunk
		for($dx = -2; $dx <= 3; ++$dx){
			for($dz = -2; $dz <= 3; ++$dz){
				if(($dx < -1 || $dx > 2) && ($dz < -1 || $dz > 2)){
					continue; //round off the corners
				}
				for($dy = -2; $dy <= 1; ++$dy){
					$ground = $transaction->fetchBlockAt($x + $dx, $y + $dy, $z + $dz);
					$groundId = $ground->getTypeId();
					if(($groundId === BlockTypeIds::GRASS || $groundId === BlockTypeIds::DIRT) && $transaction->fetchBlockAt($x + $dx, $y + $dy + 1, $z + $dz)->canBeReplaced()){
						$transaction->addBlockAt($x + $dx, $y + $dy, $z + $dz, VanillaBlocks::PODZOL());
						break;
					}
				}
			}
		}
	}
}
