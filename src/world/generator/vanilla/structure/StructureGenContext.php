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

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\utils\Utils;
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\template\Rotations;
use function intdiv;
use function max;
use function min;

/**
 * Vanilla's Structure.GenerationContext: what a structure type sees while it computes a start in a chunk.
 */
final class StructureGenContext{
	public readonly LegacyRandom $random;

	public function __construct(
		public readonly StructureEnvironment $env,
		public readonly int $chunkX,
		public readonly int $chunkZ
	){
		$this->random = StructureRandom::largeFeature($env->seed, $chunkX, $chunkZ);
	}

	public function getMinBlockX() : int{
		return $this->chunkX << 4;
	}

	public function getMinBlockZ() : int{
		return $this->chunkZ << 4;
	}

	/**
	 * Structure.onTopOfChunkCenter(): position on the terrain at the centre of the chunk.
	 * @phpstan-return array{int, int, int}
	 */
	public function onTopOfChunkCenter(int $heightmap) : array{
		$x = ($this->chunkX << 4) + 8;
		$z = ($this->chunkZ << 4) + 8;
		return [$x, $this->env->terrain->getFirstOccupiedHeight($x, $z, $heightmap), $z];
	}

	/**
	 * Structure.getLowestY(context, x, z, width, depth): the lowest terrain of the four corners of the area.
	 */
	public function getLowestY(int $x, int $z, int $width, int $depth) : int{
		$terrain = $this->env->terrain;
		return min(
			$terrain->getFirstOccupiedHeight($x, $z, TerrainSampler::WORLD_SURFACE_WG),
			$terrain->getFirstOccupiedHeight($x, $z + $depth, TerrainSampler::WORLD_SURFACE_WG),
			$terrain->getFirstOccupiedHeight($x + $width, $z, TerrainSampler::WORLD_SURFACE_WG),
			$terrain->getFirstOccupiedHeight($x + $width, $z + $depth, TerrainSampler::WORLD_SURFACE_WG)
		);
	}

	/**
	 * Structure.getLowestYIn5by5Box(): the lowest terrain of the corners of the 5x5 area the rotation points to.
	 */
	public function getLowestYIn5by5Box(int $blockX, int $blockZ, int $rotation) : int{
		$offsetX = 5;
		$offsetZ = 5;
		if($rotation === Rotations::CLOCKWISE_90){
			$offsetX = -5;
		}elseif($rotation === Rotations::CLOCKWISE_180){
			$offsetX = -5;
			$offsetZ = -5;
		}elseif($rotation === Rotations::COUNTERCLOCKWISE_90){
			$offsetZ = -5;
		}
		return $this->getLowestY($blockX, $blockZ, $offsetX, $offsetZ);
	}

	/**
	 * Structure.getMeanFirstOccupiedHeight(context, x, z, width, depth): the mean terrain of the four corners.
	 */
	public function getMeanFirstOccupiedHeight(int $x, int $z, int $width, int $depth, int $heightmap) : int{
		$terrain = $this->env->terrain;
		return intdiv(
			$terrain->getFirstOccupiedHeight($x, $z, $heightmap) +
			$terrain->getFirstOccupiedHeight($x, $z + $depth, $heightmap) +
			$terrain->getFirstOccupiedHeight($x + $width, $z, $heightmap) +
			$terrain->getFirstOccupiedHeight($x + $width, $z + $depth, $heightmap),
			4
		);
	}

	/**
	 * The random a structure's piece gets in postProcess() when the start chunk is decorated (the chunk's decoration
	 * seed with the structure's index in its decoration step). Vanilla reads some heights and offsets in the first
	 * chunk of a piece that is decorated; these values are taken as if it is the start chunk, so every chunk agrees.
	 */
	public function startChunkDecorationRandom(string $structureId) : FeatureRandom{
		$step = 0;
		$index = 0;
		foreach(Utils::stringifyKeys($this->env->registry->getStructureSteps()) as $id => $structureStep){
			if($id === $structureId){
				$step = $structureStep;
				break;
			}
		}
		foreach(Utils::stringifyKeys($this->env->registry->getStructureSteps()) as $id => $structureStep){
			if($id === $structureId){
				break;
			}
			if($structureStep === $step){
				++$index;
			}
		}
		$random = new FeatureRandom();
		$random->setFeatureSeed($random->setDecorationSeed($this->env->seed, $this->chunkX << 4, $this->chunkZ << 4), $index, $step);
		return $random;
	}

	/**
	 * ScatteredFeaturePiece.updateAverageGroundHeight() run in the start chunk: the mean terrain height of the box
	 * columns inside the start chunk.
	 */
	public function averageHeightInStartChunk(BoundingBox $box, int $heightmap) : int{
		$minX = $this->chunkX << 4;
		$minZ = $this->chunkZ << 4;
		return $this->averageHeight(new BoundingBox(
			max($box->minX, $minX), $box->minY, max($box->minZ, $minZ),
			min($box->maxX, $minX + 15), $box->maxY, min($box->maxZ, $minZ + 15)
		), $heightmap);
	}

	/**
	 * The mean first free height of the columns of a box (ScatteredFeaturePiece.updateAverageGroundHeight(), computed
	 * over the whole box at start time so every chunk sees the same height).
	 */
	public function averageHeight(BoundingBox $box, int $heightmap) : int{
		$terrain = $this->env->terrain;
		$sum = 0;
		$count = 0;
		for($z = $box->minZ; $z <= $box->maxZ; ++$z){
			for($x = $box->minX; $x <= $box->maxX; ++$x){
				$sum += $terrain->getFirstFreeHeight($x, $z, $heightmap);
				++$count;
			}
		}
		return $count === 0 ? $box->minY : intdiv($sum, $count);
	}
}
