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

use pocketmine\block\VanillaBlocks;
use pocketmine\utils\Random;
use pocketmine\world\ChunkManager;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use function in_array;

/**
 * Fills the water sources above the soul sand and magma blocks of a generated chunk with bubble columns.
 *
 * Vanilla (Java) marks the block above soul sand / magma for post-processing during generation, and the water there
 * builds its column when the chunk becomes ticking. Generated chunks never get those updates in PocketMine, so the
 * columns are written directly at population time (same end result, without the 20 tick delay).
 */
final class BubbleColumns implements Populator{

	public function populate(ChunkManager $world, int $chunkX, int $chunkZ, Random $random) : void{
		$chunk = $world->getChunk($chunkX, $chunkZ);
		if($chunk !== null){
			self::fillChunk($chunk, $world->getMinY(), $world->getMaxY());
		}
	}

	/**
	 * Writes the columns of one chunk. Returns how many column blocks were written.
	 */
	public static function fillChunk(Chunk $chunk, int $minY, int $maxY) : int{
		$soulSand = VanillaBlocks::SOUL_SAND()->getStateId();
		$magma = VanillaBlocks::MAGMA()->getStateId();
		$stillWater = VanillaBlocks::WATER()->getStillForm()->getStateId();
		$sources = [$stillWater, VanillaBlocks::WATER()->getFlowingForm()->getStateId()];
		$up = VanillaBlocks::BUBBLE_COLUMN()->setDragDown(false)->getStateId();
		$down = VanillaBlocks::BUBBLE_COLUMN()->setDragDown(true)->getStateId();

		$written = 0;
		for($subY = $minY >> SubChunk::COORD_BIT_SIZE, $maxSubY = ($maxY - 1) >> SubChunk::COORD_BIT_SIZE; $subY <= $maxSubY; ++$subY){
			$layers = $chunk->getSubChunk($subY)->getBlockLayers();
			if(!isset($layers[0])){
				continue;
			}
			$palette = $layers[0]->getPalette();
			if(!in_array($soulSand, $palette, true) && !in_array($magma, $palette, true)){
				continue;
			}
			$baseY = $subY << SubChunk::COORD_BIT_SIZE;
			for($y = 0; $y < SubChunk::EDGE_LENGTH; ++$y){
				$worldY = $baseY + $y;
				if($worldY + 1 >= $maxY){
					break;
				}
				for($x = 0; $x < SubChunk::EDGE_LENGTH; ++$x){
					for($z = 0; $z < SubChunk::EDGE_LENGTH; ++$z){
						$state = $chunk->getBlockStateId($x, $worldY, $z);
						if($state !== $soulSand && $state !== $magma){
							continue;
						}
						$column = $state === $magma ? $down : $up;
						for($cy = $worldY + 1; $cy < $maxY; ++$cy){
							if(!in_array($chunk->getBlockStateId($x, $cy, $z), $sources, true)){
								break;
							}
							$chunk->setBlockStateId($x, $cy, $z, $column);
							$chunk->getSubChunk($cy >> SubChunk::COORD_BIT_SIZE)->setLiquidLayerStateId($x, $cy & SubChunk::COORD_MASK, $z, $stillWater);
							++$written;
						}
					}
				}
			}
		}
		return $written;
	}
}
