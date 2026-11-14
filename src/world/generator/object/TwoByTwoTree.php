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
use function floor;

/**
 * A tree with a 2x2 trunk, grown from a 2x2 square of saplings. x, z is the north-west sapling; the centre of the trunk
 * is at x + 1, z + 1 (block corners).
 */
abstract class TwoByTwoTree extends Tree{

	public function canPlaceObject(ChunkManager $world, int $x, int $y, int $z, Random $random) : bool{
		//the trunk itself, and room for the canopy around it
		for($yy = 0; $yy < $this->treeHeight + 2; ++$yy){
			$radius = $yy === 0 ? 0 : ($yy < 2 ? 1 : 2);
			for($xx = -$radius; $xx <= 1 + $radius; ++$xx){
				for($zz = -$radius; $zz <= 1 + $radius; ++$zz){
					if(!$this->canOverride($world->getBlockAt($x + $xx, $y + $yy, $z + $zz))){
						return false;
					}
				}
			}
		}
		return true;
	}

	protected function placeTrunk(int $x, int $y, int $z, Random $random, int $trunkHeight, BlockTransaction $transaction) : void{
		for($dx = 0; $dx <= 1; ++$dx){
			for($dz = 0; $dz <= 1; ++$dz){
				$transaction->addBlockAt($x + $dx, $y - 1, $z + $dz, VanillaBlocks::DIRT());
				for($yy = 0; $yy < $trunkHeight; ++$yy){
					if($this->canOverride($transaction->fetchBlockAt($x + $dx, $y + $yy, $z + $dz))){
						$transaction->addBlockAt($x + $dx, $y + $yy, $z + $dz, $this->trunkBlock);
					}
				}
			}
		}
	}

	/**
	 * Places a disc of leaves at height y, centred on the point (cx, cz) (block corners, e.g. the centre of a 2x2
	 * trunk). Leaves at the edge of the disc are left out at random when $ragged is true.
	 *
	 * @return int[][] positions of the placed leaves
	 * @phpstan-return list<array{int, int, int}>
	 */
	protected function placeLeafDisc(BlockTransaction $transaction, float $cx, int $y, float $cz, float $radius, Random $random, bool $ragged = true) : array{
		$placed = [];
		$r = (int) $radius + 1;
		for($xx = (int) floor($cx) - $r; $xx <= (int) floor($cx) + $r; ++$xx){
			for($zz = (int) floor($cz) - $r; $zz <= (int) floor($cz) + $r; ++$zz){
				$dx = $xx + 0.5 - $cx;
				$dz = $zz + 0.5 - $cz;
				$distanceSquared = $dx * $dx + $dz * $dz;
				if($distanceSquared > $radius * $radius){
					continue;
				}
				if($ragged && $distanceSquared > ($radius - 1) * ($radius - 1) && $random->nextBoundedInt(3) === 0){
					continue;
				}
				if(!$transaction->fetchBlockAt($xx, $y, $zz)->isSolid()){
					$transaction->addBlockAt($xx, $y, $zz, $this->leafBlock);
					$placed[] = [$xx, $y, $zz];
				}
			}
		}
		return $placed;
	}
}
