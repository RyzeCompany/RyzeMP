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

namespace pocketmine\world\map;

use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use function count;
use function intdiv;

/**
 * Draws the terrain seen from above into a map, one map column at a time, the way vanilla does while a player holds
 * the map: pixels within a radius of the player are refreshed, with height shading (brighter when the ground rises
 * towards the south, darker when it falls) and water depth shading. Unloaded chunks are left as they are.
 *
 * At scales above 0 a pixel covers several blocks; vanilla uses the most common colour of the area, this samples the
 * block in the middle of it to keep the cost per pixel constant.
 */
final class MapRenderer{
	/** Highest number of water blocks counted below the surface for depth shading */
	private const MAX_WATER_DEPTH = 10;

	/**
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $colorCache = [];
	private ?int $dirtColor = null;
	private ?int $stoneColor = null;

	public function __construct(
		private BlockMapColors $colors
	){}

	private function getColor(int $stateId) : int{
		return $this->colorCache[$stateId] ??= $this->colors->getColor($stateId);
	}

	/**
	 * Returns the block layers of a chunk that may show on maps, from the top down: subchunks that only contain blocks
	 * without a map colour (air, glass...) are left out, so columns don't have to be scanned through them.
	 *
	 * @return PalettedBlockArray[]
	 * @phpstan-return array<int, PalettedBlockArray> subchunk Y index => block layer
	 */
	public function getVisibleLayers(Chunk $chunk) : array{
		$layers = [];
		for($subY = Chunk::MAX_SUBCHUNK_INDEX; $subY >= Chunk::MIN_SUBCHUNK_INDEX; --$subY){
			$blockLayers = $chunk->getSubChunk($subY)->getBlockLayers();
			if(count($blockLayers) === 0){
				continue;
			}
			$layer = $blockLayers[0];
			foreach($layer->getPalette() as $stateId){
				if($this->getColor($stateId) !== BlockMapColors::NONE){
					$layers[$subY] = $layer;
					break;
				}
			}
		}
		return $layers;
	}

	/**
	 * Finds the topmost block of a chunk column that shows on maps.
	 *
	 * @param PalettedBlockArray[]|null $layers result of getVisibleLayers() for this chunk, to reuse it between columns
	 * @phpstan-param array<int, PalettedBlockArray>|null $layers
	 *
	 * @return int[]|null [y, base colour, water depth], or null if the column is empty
	 * @phpstan-return array{int, int, int}|null
	 */
	public function sampleColumn(Chunk $chunk, int $x, int $z, ?array $layers = null) : ?array{
		$layers ??= $this->getVisibleLayers($chunk);
		$colorCache = &$this->colorCache;
		$height = null;
		$color = BlockMapColors::NONE;
		foreach($layers as $subY => $layer){
			for($y = SubChunk::EDGE_LENGTH - 1; $y >= 0; --$y){
				$stateId = $layer->get($x, $y, $z);
				$color = $colorCache[$stateId] ?? $this->getColor($stateId);
				if($color !== BlockMapColors::NONE){
					$height = ($subY << SubChunk::COORD_BIT_SIZE) | $y;
					break 2;
				}
			}
		}
		if($height === null){
			return null;
		}
		if($color !== BlockMapColors::WATER){
			return [$height, $color, 0];
		}
		$depth = 1;
		$minY = Chunk::MIN_SUBCHUNK_INDEX << SubChunk::COORD_BIT_SIZE;
		for($belowY = $height - 1; $belowY >= $minY && $depth < self::MAX_WATER_DEPTH; --$belowY){
			if($this->getColor($chunk->getBlockStateId($x, $belowY, $z)) !== BlockMapColors::WATER){
				break;
			}
			++$depth;
		}
		return [$height, $color, $depth];
	}

	/**
	 * Returns the shading brightness of a pixel (vanilla MapItem#update): water gets darker with depth, land is
	 * brighter when it is higher than the pixel to the north and darker when lower, with a checkerboard dither.
	 */
	public static function getBrightness(int $height, int $northHeight, int $waterDepth, int $pixelX, int $pixelY, int $scale) : int{
		$checker = ($pixelX + $pixelY) & 1;
		if($waterDepth > 0){
			$value = $waterDepth * 0.1 + $checker * 0.2;
			return $value < 0.5 ? BlockMapColors::SHADE_HIGH : ($value > 0.9 ? BlockMapColors::SHADE_LOW : BlockMapColors::SHADE_NORMAL);
		}
		$value = ($height - $northHeight) * 4.0 / ((1 << $scale) + 4) + ($checker - 0.5) * 0.4;
		return $value > 0.6 ? BlockMapColors::SHADE_HIGH : ($value < -0.6 ? BlockMapColors::SHADE_LOW : BlockMapColors::SHADE_NORMAL);
	}

	/**
	 * Returns the radius (in pixels) of the area refreshed around a player holding the map.
	 */
	public static function getUpdateRadius(int $scale) : int{
		return intdiv(MapData::SIZE, 1 << $scale);
	}

	/**
	 * Returns the radius (in pixels) of the area refreshed around a player holding the given map: half the usual one
	 * for maps of a dimension with a ceiling (the Nether), as in vanilla.
	 */
	public static function getMapUpdateRadius(MapData $map) : int{
		$radius = self::getUpdateRadius($map->getScale());
		return self::hasCeiling($map) ? intdiv($radius, 2) : $radius;
	}

	private static function hasCeiling(MapData $map) : bool{
		return $map->getDimension() === DimensionIds::NETHER;
	}

	private static function toInt32(int $value) : int{
		$value &= 0xffffffff;
		return $value >= 0x80000000 ? $value - 0x100000000 : $value;
	}

	/**
	 * Returns the base colour of a map pixel in a dimension with a ceiling: vanilla doesn't look at the terrain there
	 * and draws a fixed dirt and stone noise (Java MapItem#update, hasCeiling branch, 32-bit arithmetic).
	 *
	 * @param int $minX lowest X coordinate of the area covered by the pixel
	 * @param int $minZ lowest Z coordinate of the area covered by the pixel
	 */
	public function getCeilingColor(int $minX, int $minZ) : int{
		$noise = self::toInt32($minX + $minZ * 231871);
		$noise = self::toInt32(self::toInt32($noise * $noise) * 31287121 + $noise * 11);
		if((($noise >> 20) & 1) === 0){
			return $this->dirtColor ??= $this->colors->getColorByName(BlockTypeNames::DIRT);
		}
		return $this->stoneColor ??= $this->colors->getColorByName(BlockTypeNames::STONE);
	}

	/**
	 * Refreshes one column of map pixels around a player, like one step of vanilla MapItem#update.
	 *
	 * @param \Closure $getChunk returns the loaded chunk at the given chunk coordinates, or null
	 * @phpstan-param \Closure(int, int) : ?Chunk $getChunk
	 * @param int $pixelX       column to refresh
	 * @param int $playerPixelX map pixel the player is on (may be outside the map)
	 *
	 * @return int number of pixels that changed
	 */
	public function renderColumn(MapData $map, \Closure $getChunk, int $pixelX, int $playerPixelX, int $playerPixelY) : int{
		if($pixelX < 0 || $pixelX >= MapData::SIZE){
			return 0;
		}
		$scale = $map->getScale();
		$blocksPerPixel = 1 << $scale;
		$radius = self::getMapUpdateRadius($map);
		$ceiling = self::hasCeiling($map);
		$dx = $pixelX - $playerPixelX;
		if($dx * $dx >= $radius * $radius){
			return 0;
		}
		$offset = intdiv($blocksPerPixel, 2);
		$worldX = (intdiv($map->getCenterX(), $blocksPerPixel) + $pixelX - (MapData::SIZE >> 1)) * $blocksPerPixel + $offset;
		$chunkX = $worldX >> Chunk::COORD_BIT_SIZE;
		$localX = $worldX & Chunk::COORD_MASK;

		$changed = 0;
		$northHeight = null;
		$lastChunkZ = null;
		$chunk = null;
		$layers = [];
		$startY = $playerPixelY - $radius - 1;
		if($startY < -1){
			$startY = -1;
		}
		$endY = $playerPixelY + $radius - 1;
		if($endY > MapData::SIZE - 1){
			$endY = MapData::SIZE - 1;
		}
		$radiusSquared = $radius * $radius;
		$innerRadiusSquared = ($radius - 2) * ($radius - 2);
		for($pixelY = $startY; $pixelY <= $endY; ++$pixelY){
			$dy = $pixelY - $playerPixelY;
			$distanceSquared = $dx * $dx + $dy * $dy;

			$worldZ = (intdiv($map->getCenterZ(), $blocksPerPixel) + $pixelY - (MapData::SIZE >> 1)) * $blocksPerPixel + $offset;
			$chunkZ = $worldZ >> Chunk::COORD_BIT_SIZE;
			if($chunkZ !== $lastChunkZ){
				$lastChunkZ = $chunkZ;
				$chunk = $getChunk($chunkX, $chunkZ);
				$layers = $chunk !== null && !$ceiling ? $this->getVisibleLayers($chunk) : [];
			}
			if($chunk === null){
				$northHeight = null;
				continue;
			}
			if($ceiling){
				[$height, $color, $waterDepth] = [100, $this->getCeilingColor($worldX - $offset, $worldZ - $offset), 0];
			}else{
				$sample = $this->sampleColumn($chunk, $localX, $worldZ & Chunk::COORD_MASK, $layers);
				if($sample === null){
					$northHeight = null;
					continue;
				}
				[$height, $color, $waterDepth] = $sample;
			}

			//pixels at the edge of the circle are only drawn on a checkerboard, as in vanilla
			if(
				$pixelY >= 0 && $distanceSquared < $radiusSquared &&
				($distanceSquared <= $innerRadiusSquared || (($pixelX + $pixelY) & 1) !== 0)
			){
				$brightness = self::getBrightness($height, $northHeight ?? $height, $waterDepth, $pixelX, $pixelY, $scale);
				if($map->setPixel($pixelX, $pixelY, BlockMapColors::shade($color, $brightness))){
					++$changed;
				}
			}
			$northHeight = $height;
		}
		return $changed;
	}
}
