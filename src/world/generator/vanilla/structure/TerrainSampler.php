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

use pocketmine\world\generator\vanilla\Aquifer;
use pocketmine\world\generator\vanilla\TerrainChunk;
use pocketmine\world\World;
use function array_key_first;
use function chr;
use function count;
use function ord;
use function pack;
use function str_repeat;
use function strlen;
use function strtr;

/**
 * The terrain as structure starts see it (vanilla's ChunkGenerator.getBaseHeight() / getBaseColumn(): the noise
 * terrain of a column, without generating the chunk). Built from the terrain filler (the native core when it runs),
 * one chunk at a time, and kept in small least-recently-used caches: a packed column kind per block
 * (KIND_*: air, solid, water, lava) and the WORLD_SURFACE_WG / OCEAN_FLOOR_WG heights.
 */
final class TerrainSampler{
	public const KIND_AIR = 0;
	public const KIND_SOLID = 1;
	public const KIND_WATER = 2;
	public const KIND_LAVA = 3;

	public const WORLD_SURFACE_WG = 0;
	public const OCEAN_FLOOR_WG = 1;

	private const KINDS_CACHE = 8;
	private const HEIGHTS_CACHE = 1024;

	/** @phpstan-var array<int, string> chunk hash => kind per block, index ((x << 4) | z) * height + (y - minY) */
	private array $kinds = [];
	/**
	 * chunk hash => packed first free y - minY per column (x | z << 4), 2 bytes each: 256 surface then 256 ocean floor
	 * @phpstan-var array<int, string>
	 */
	private array $heights = [];

	public int $chunkFills = 0;

	/**
	 * @phpstan-param \Closure(int, int) : TerrainChunk $fill
	 * @phpstan-param (\Closure(int, int) : ?array{list<int>, list<int>})|null $knownHeights heights of chunks the
	 *                generator already made (first free y per column x | z << 4), or null
	 */
	public function __construct(
		private \Closure $fill,
		public readonly int $minY,
		public readonly int $height,
		private ?\Closure $knownHeights = null
	){}

	/**
	 * Kind per block of a chunk's terrain (packed string).
	 */
	public function chunkKinds(int $chunkX, int $chunkZ) : string{
		$hash = World::chunkHash($chunkX, $chunkZ);
		$kinds = $this->kinds[$hash] ?? null;
		if($kinds !== null){
			unset($this->kinds[$hash]);
			return $this->kinds[$hash] = $kinds;
		}
		++$this->chunkFills;
		$terrain = ($this->fill)($chunkX, $chunkZ);
		$map = self::kindMap($terrain);
		$size = 256 * $this->height;
		if($terrain->materialBytes !== null && strlen($terrain->materialBytes) === $size){
			//native core: one byte per block, mapped in one call (unknown materials count as solid, like below)
			$from = "";
			$to = "";
			for($material = 0; $material < 256; ++$material){
				$from .= chr($material);
				$to .= chr($map[$material] ?? self::KIND_SOLID);
			}
			$kinds = strtr($terrain->materialBytes, $from, $to);
		}else{
			$materials = $terrain->materials;
			$kinds = str_repeat("\x00", $size);
			for($i = 0; $i < $size; ++$i){
				$kind = $map[$materials[$i] ?? 0] ?? self::KIND_SOLID;
				if($kind !== self::KIND_AIR){
					$kinds[$i] = chr($kind);
				}
			}
		}
		$this->kinds[$hash] = $kinds;
		if(count($this->kinds) > self::KINDS_CACHE){
			unset($this->kinds[array_key_first($this->kinds)]);
		}
		return $kinds;
	}

	/**
	 * @phpstan-return array<int, int> material id => kind
	 */
	private static function kindMap(TerrainChunk $terrain) : array{
		if($terrain->palette === null){
			return [Aquifer::MATERIAL_AIR => self::KIND_AIR, Aquifer::MATERIAL_STONE => self::KIND_SOLID, Aquifer::MATERIAL_WATER => self::KIND_WATER, Aquifer::MATERIAL_LAVA => self::KIND_LAVA];
		}
		$map = [];
		foreach($terrain->palette as $id => $name){
			$map[$id] = match($name){
				"minecraft:air", "minecraft:cave_air", "minecraft:void_air" => self::KIND_AIR,
				"minecraft:water" => self::KIND_WATER,
				"minecraft:lava" => self::KIND_LAVA,
				default => self::KIND_SOLID
			};
		}
		$map[0] ??= self::KIND_AIR;
		return $map;
	}

	/**
	 * Packed heights of a chunk (see $heights).
	 */
	private function chunkHeights(int $chunkX, int $chunkZ) : string{
		$hash = World::chunkHash($chunkX, $chunkZ);
		if(isset($this->heights[$hash])){
			return $this->heights[$hash];
		}
		$known = $this->knownHeights !== null ? ($this->knownHeights)($chunkX, $chunkZ) : null;
		if($known !== null){
			$values = [];
			foreach([...$known[0], ...$known[1]] as $y){
				$values[] = $y - $this->minY;
			}
			$packed = pack("v*", ...$values);
		}else{
			$kinds = $this->chunkKinds($chunkX, $chunkZ);
			$height = $this->height;
			$surface = [];
			$floor = [];
			for($i = 0; $i < 256; ++$i){
				$column = ((($i & 15) << 4) | ($i >> 4)) * $height;
				$s = null;
				$f = null;
				for($y = $height - 1; $y >= 0; --$y){
					$kind = ord($kinds[$column + $y]);
					if($kind === self::KIND_AIR){
						continue;
					}
					$s ??= $y + 1;
					if($kind === self::KIND_SOLID){
						$f = $y + 1;
						break;
					}
				}
				$surface[] = $s ?? 0;
				$floor[] = $f ?? 0;
			}
			$packed = pack("v*", ...$surface, ...$floor);
		}
		$this->heights[$hash] = $packed;
		if(count($this->heights) > self::HEIGHTS_CACHE){
			unset($this->heights[array_key_first($this->heights)]);
		}
		return $packed;
	}

	/**
	 * ChunkGenerator.getFirstFreeHeight() / getBaseHeight(): first free y above the terrain of the column.
	 */
	public function getFirstFreeHeight(int $x, int $z, int $type) : int{
		$packed = $this->chunkHeights($x >> 4, $z >> 4);
		$offset = ((($x & 15) | (($z & 15) << 4)) + ($type === self::OCEAN_FLOOR_WG ? 256 : 0)) << 1;
		return (ord($packed[$offset]) | (ord($packed[$offset + 1]) << 8)) + $this->minY;
	}

	/**
	 * ChunkGenerator.getFirstOccupiedHeight(): the highest terrain block of the column.
	 */
	public function getFirstOccupiedHeight(int $x, int $z, int $type) : int{
		return $this->getFirstFreeHeight($x, $z, $type) - 1;
	}

	/**
	 * Kind of the terrain block at a position (getBaseColumn()).
	 */
	public function getKind(int $x, int $y, int $z) : int{
		if($y < $this->minY || $y >= $this->minY + $this->height){
			return self::KIND_AIR;
		}
		$kinds = $this->chunkKinds($x >> 4, $z >> 4);
		return ord($kinds[((($x & 15) << 4) | ($z & 15)) * $this->height + ($y - $this->minY)]);
	}
}
