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

namespace pocketmine\world\generator\vanilla;

use function array_fill;
use function array_values;
use function count;
use function strlen;
use function unpack;

/**
 * Output of the terrain stage for one chunk: base materials per block and biome per 4x4x4 cell.
 */
final class TerrainChunk{
	/**
	 * Material per block (Aquifer::MATERIAL_*), index ((x << 4) | z) * height + (y - minY).
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	public array $materials = [];
	/**
	 * Biome index (ClimateBiomeTree names) per quart cell, index ((qx << 2) | qz) * (height >> 2) + (qy - minY / 4).
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	public array $biomes = [];
	/**
	 * Blocks visited by the carvers (vanilla's carving mask), same index as $materials; empty before the carver stage.
	 * @var true[]
	 * @phpstan-var array<int, true>
	 */
	public array $carvingMask = [];
	/** Generation stage the materials have reached (STAGE_*): the native core can return later stages. */
	public int $stage = self::STAGE_TERRAIN;
	/**
	 * Java block ids of the material ids, when they are not the generator's own material palette (native results).
	 * @var string[]|null
	 * @phpstan-var list<string>|null
	 */
	public ?array $palette = null;
	/** The materials as one byte per block (same index as $materials), from the native core: then $materials is empty. */
	public ?string $materialBytes = null;
	/**
	 * Subchunks packed by the native core, index 0 = subchunk minY >> 4: [bits per block, word array, palette of
	 * material ids] for PalettedBlockArray::fromData(), or null for a subchunk of air only. Then $materials is empty.
	 *
	 * @var array<int, array{int, string, int[]}|null>|null
	 * @phpstan-var array<int, array{int, string, list<int>}|null>|null
	 */
	public ?array $sections = null;
	/**
	 * Biome arrays of the subchunks packed by the native core (FLAG_PACKED_BIOMES), index 0 = subchunk minY >> 4: [bits per
	 * block, word array, palette of Bedrock biome ids] for PalettedBlockArray::fromData(). Only valid while $biomes is
	 * the native core's biomes.
	 *
	 * @var array<int, array{int, string, int[]}>|null
	 * @phpstan-var array<int, array{int, string, list<int>}>|null
	 */
	public ?array $biomeSections = null;
	/**
	 * Terrain heights packed like FeatureDecorator::recordTerrain() does, computed by the native core from the final
	 * materials (null if it did not send them).
	 */
	public ?string $terrainHeights = null;
	/**
	 * The biome cells as u16 little endian values (same index as $biomes) when the native core sent them packed
	 * (FLAG_PACKED_BIOMES): $biomes stays empty until unpackBiomes().
	 */
	public ?string $biomeBytes = null;
	/**
	 * The carving mask as the native core sent it ((u32 start, u16 length) runs, little endian) when it was not
	 * unpacked into $carvingMask (see getCarvingMask()).
	 */
	public ?string $carvingMaskRuns = null;

	/** Base materials: Aquifer::MATERIAL_* (air, stone, water, lava). */
	public const STAGE_TERRAIN = 0;
	/** After the material rules (surface, bedrock, deepslate, ore veins...). */
	public const STAGE_MATERIALS = 1;
	/** After the carvers (final blocks before features). */
	public const STAGE_CARVED = 2;

	public function __construct(
		public readonly int $chunkX,
		public readonly int $chunkZ,
		public readonly int $minY,
		public readonly int $height
	){}

	/**
	 * Fills $biomes from $biomeBytes if it was left packed.
	 */
	public function unpackBiomes() : void{
		if($this->biomeBytes !== null && count($this->biomes) === 0){
			$count = strlen($this->biomeBytes) >> 1;
			$values = $count > 0 ? unpack("v$count", $this->biomeBytes) : [];
			if($values === false){
				throw new \UnexpectedValueException("Bad packed biomes");
			}
			/** @phpstan-var array<int, int> $values */
			$this->biomes = array_values($values);
		}
	}

	/**
	 * The carving mask (see $carvingMask), unpacked from $carvingMaskRuns if needed.
	 * @return true[]
	 * @phpstan-return array<int, true>
	 */
	public function getCarvingMask() : array{
		return $this->carvingMaskRuns !== null ? self::unpackCarvingMask($this->carvingMaskRuns, 256 * $this->height) : $this->carvingMask;
	}

	/**
	 * @return true[]
	 * @phpstan-return array<int, true>
	 */
	public static function unpackCarvingMask(string $runs, int $size) : array{
		$mask = [];
		for($offset = 0, $length = strlen($runs); $offset + 6 <= $length; $offset += 6){
			$run = unpack("Vstart/vlength", $runs, $offset);
			if($run === false || $run["length"] <= 0 || $run["start"] + $run["length"] > $size){
				throw new \UnexpectedValueException("Bad carving mask run");
			}
			$mask += array_fill($run["start"], $run["length"], true);
		}
		return $mask;
	}
}
