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

namespace pocketmine\world\generator\vanilla\biome;

use pocketmine\world\generator\vanilla\density\DensityFunction;

/**
 * The End's biome source (vanilla TheEndBiomeSource): the_end within 64 chunks of 0,0, elsewhere a biome chosen by
 * the height of the End islands density function at the centre of the chunk (highlands, midlands, small islands or
 * barrens). The biome only depends on the chunk column.
 */
final class TheEndBiomeSource{
	public const THE_END = 0;
	public const END_HIGHLANDS = 1;
	public const END_MIDLANDS = 2;
	public const SMALL_END_ISLANDS = 3;
	public const END_BARRENS = 4;

	/** Biome names in index order. */
	public const NAMES = [
		self::THE_END => "minecraft:the_end",
		self::END_HIGHLANDS => "minecraft:end_highlands",
		self::END_MIDLANDS => "minecraft:end_midlands",
		self::SMALL_END_ISLANDS => "minecraft:small_end_islands",
		self::END_BARRENS => "minecraft:end_barrens",
	];

	/**
	 * @param DensityFunction $islands the noise router's erosion (the End islands height)
	 */
	public function __construct(
		private readonly DensityFunction $islands
	){}

	/**
	 * Biome index (see NAMES) of a quart (4x4x4 cell) position.
	 */
	public function getNoiseBiome(int $quartX, int $quartY, int $quartZ) : int{
		return $this->getChunkBiome($quartX >> 2, $quartZ >> 2, $quartY << 2);
	}

	public function getChunkBiome(int $chunkX, int $chunkZ, int $blockY = 0) : int{
		if($chunkX * $chunkX + $chunkZ * $chunkZ <= 4096){
			return self::THE_END;
		}
		$height = $this->islands->compute((float) (($chunkX * 2 + 1) * 8), (float) $blockY, (float) (($chunkZ * 2 + 1) * 8));
		if($height > 0.25){
			return self::END_HIGHLANDS;
		}
		if($height >= -0.0625){
			return self::END_MIDLANDS;
		}
		return $height < -0.21875 ? self::SMALL_END_ISLANDS : self::END_BARRENS;
	}

	/**
	 * Biome per quart cell of a chunk, in TerrainChunk::$biomes layout.
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	public function fillChunk(int $chunkX, int $chunkZ, int $quartsY) : array{
		$biome = $this->getChunkBiome($chunkX, $chunkZ);
		$biomes = [];
		for($i = 0, $count = 16 * $quartsY; $i < $count; ++$i){
			$biomes[] = $biome;
		}
		return $biomes;
	}
}
