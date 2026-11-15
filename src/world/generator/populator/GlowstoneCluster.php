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

namespace pocketmine\world\generator\populator;

use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\utils\Random;
use pocketmine\world\ChunkManager;
use pocketmine\world\format\Chunk;

/**
 * Hanging glowstone clusters under Nether ceilings, grown like vanilla's glowstone feature: start under a netherrack
 * block and add glowstone to air blocks that touch exactly one glowstone block.
 */
class GlowstoneCluster implements Populator{

	public function __construct(
		private int $attemptsPerChunk = 10,
		private int $growthIterations = 300
	){}

	public function populate(ChunkManager $world, int $chunkX, int $chunkZ, Random $random) : void{
		$glowstone = VanillaBlocks::GLOWSTONE();
		for($i = 0; $i < $this->attemptsPerChunk; ++$i){
			$x = ($chunkX << Chunk::COORD_BIT_SIZE) + $random->nextBoundedInt(Chunk::EDGE_LENGTH);
			$z = ($chunkZ << Chunk::COORD_BIT_SIZE) + $random->nextBoundedInt(Chunk::EDGE_LENGTH);
			$y = 4 + $random->nextBoundedInt(116);
			if($world->getBlockAt($x, $y, $z)->getTypeId() !== BlockTypeIds::AIR || $world->getBlockAt($x, $y + 1, $z)->getTypeId() !== BlockTypeIds::NETHERRACK){
				continue;
			}
			$world->setBlockAt($x, $y, $z, $glowstone);
			for($j = 0; $j < $this->growthIterations; ++$j){
				//stay within the 3x3 chunks available during population
				$px = $x + $random->nextBoundedInt(8) - $random->nextBoundedInt(8);
				$py = $y - $random->nextBoundedInt(12);
				$pz = $z + $random->nextBoundedInt(8) - $random->nextBoundedInt(8);
				if(!$world->isInWorld($px, $py, $pz) || $world->getBlockAt($px, $py, $pz)->getTypeId() !== BlockTypeIds::AIR){
					continue;
				}
				$touching = 0;
				foreach(Facing::ALL as $face){
					$side = (new Vector3($px, $py, $pz))->getSide($face);
					if($world->getBlockAt($side->getFloorX(), $side->getFloorY(), $side->getFloorZ())->getTypeId() === BlockTypeIds::GLOWSTONE){
						++$touching;
					}
				}
				if($touching === 1){
					$world->setBlockAt($px, $py, $pz, $glowstone);
				}
			}
		}
	}
}
