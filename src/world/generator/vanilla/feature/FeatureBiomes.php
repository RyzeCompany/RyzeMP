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

namespace pocketmine\world\generator\vanilla\feature;

use pocketmine\world\format\Chunk;
use pocketmine\world\generator\vanilla\math\Int64;
use pocketmine\world\generator\vanilla\surface\SurfaceBiomes;
use function count;
use const INF;

/**
 * Java biome lookups during decoration (WorldGenRegion.getBiome -> BiomeManager): the fuzzy-zoomed biome of a block,
 * from the biome cells stored in the 3x3 chunks of the decoration region. Chunks store Bedrock biome ids; they are
 * mapped back to Java biomes, and the few Bedrock ids shared by several Java biomes (and cells outside the region,
 * like vanilla's uncached noise biome) are resolved by sampling the climate.
 */
final class FeatureBiomes{
	private const LCG_MULTIPLIER = 6364136223846793005;
	private const LCG_INCREMENT = 1442695040888963407;

	private readonly int $zoomSeed;
	private readonly int $minQuart;
	private readonly int $quartsY;
	private int $baseQuartX = 0;
	private int $baseQuartZ = 0;
	/** @var Chunk[] by (dx + 1) * 3 + (dz + 1) */
	private array $chunks = [];
	/** @phpstan-var array<int, int> cell biome by ((lqx + 1) * 14 + lqz + 1) * quartsY + qy */
	private array $cells = [];
	/** @phpstan-var array<int, float> */
	private array $fiddleX = [];
	/** @phpstan-var array<int, float> */
	private array $fiddleY = [];
	/** @phpstan-var array<int, float> */
	private array $fiddleZ = [];

	/**
	 * @param int[][] $bedrockToJava Java biome indexes per Bedrock biome id (more than one = ambiguous)
	 * @phpstan-param array<int, list<int>> $bedrockToJava
	 * @phpstan-param \Closure(int, int, int) : int $sampleCell Java biome index of a cell (quart coordinates)
	 */
	public function __construct(
		int $seed,
		int $minY,
		int $height,
		private readonly array $bedrockToJava,
		private readonly \Closure $sampleCell
	){
		$this->zoomSeed = SurfaceBiomes::obfuscateSeed($seed);
		$this->minQuart = $minY >> 2;
		$this->quartsY = $height >> 2;
	}

	/**
	 * @param Chunk[] $chunks by (dx + 1) * 3 + (dz + 1)
	 */
	public function begin(int $centerChunkX, int $centerChunkZ, array $chunks) : void{
		$this->baseQuartX = ($centerChunkX - 1) << 2;
		$this->baseQuartZ = ($centerChunkZ - 1) << 2;
		$this->chunks = $chunks;
		$this->cells = [];
		$this->fiddleX = [];
		$this->fiddleY = [];
		$this->fiddleZ = [];
	}

	public function finish() : void{
		$this->chunks = [];
	}

	/**
	 * Java biome index of the cell at quart coordinates (y clamped to the world like ChunkAccess.getNoiseBiome()).
	 */
	public function cell(int $qx, int $qy, int $qz) : int{
		$iy = $qy - $this->minQuart;
		if($iy < 0){
			$iy = 0;
		}elseif($iy >= $this->quartsY){
			$iy = $this->quartsY - 1;
		}
		$lqx = $qx - $this->baseQuartX;
		$lqz = $qz - $this->baseQuartZ;
		$index = (($lqx + 1) * 14 + $lqz + 1) * $this->quartsY + $iy;
		if($lqx >= -1 && $lqx <= 12 && $lqz >= -1 && $lqz <= 12 && isset($this->cells[$index])){
			return $this->cells[$index];
		}
		$biome = -1;
		if($lqx >= 0 && $lqx < 12 && $lqz >= 0 && $lqz < 12){
			$chunk = $this->chunks[($lqx >> 2) * 3 + ($lqz >> 2)] ?? null;
			if($chunk !== null){
				$y = ($this->minQuart + $iy) << 2;
				$bedrock = $chunk->getBiomeId(($lqx & 3) << 2, $y, ($lqz & 3) << 2);
				$candidates = $this->bedrockToJava[$bedrock] ?? null;
				if($candidates !== null && count($candidates) === 1){
					$biome = $candidates[0];
				}
			}
		}
		if($biome === -1){
			$biome = ($this->sampleCell)($qx, $this->minQuart + $iy, $qz);
		}
		if($lqx >= -1 && $lqx <= 12 && $lqz >= -1 && $lqz <= 12){
			$this->cells[$index] = $biome;
		}
		return $biome;
	}

	/**
	 * BiomeManager.getBiome(): Java biome index at a block.
	 */
	public function getBiome(int $x, int $y, int $z) : int{
		$absX = $x - 2;
		$absY = $y - 2;
		$absZ = $z - 2;
		$px = $absX >> 2;
		$py = $absY >> 2;
		$pz = $absZ >> 2;
		$fx = ($absX & 3) / 4.0;
		$fy = ($absY & 3) / 4.0;
		$fz = ($absZ & 3) / 4.0;
		$best = 0;
		$bestDistance = INF;
		for($i = 0; $i < 8; ++$i){
			$xEven = ($i & 4) === 0;
			$yEven = ($i & 2) === 0;
			$zEven = ($i & 1) === 0;
			$cx = $xEven ? $px : $px + 1;
			$cy = $yEven ? $py : $py + 1;
			$cz = $zEven ? $pz : $pz + 1;
			$key = (($cx & 0xfffff) << 40) | (($cz & 0xfffff) << 20) | ($cy & 0xfffff);
			if(!isset($this->fiddleX[$key])){
				$this->computeFiddle($key, $cx, $cy, $cz);
			}
			$dx = ($xEven ? $fx : $fx - 1.0) + $this->fiddleX[$key];
			$dy = ($yEven ? $fy : $fy - 1.0) + $this->fiddleY[$key];
			$dz = ($zEven ? $fz : $fz - 1.0) + $this->fiddleZ[$key];
			$distance = $dz * $dz + $dy * $dy + $dx * $dx;
			if($bestDistance > $distance){
				$best = $i;
				$bestDistance = $distance;
			}
		}
		return $this->cell(
			($best & 4) === 0 ? $px : $px + 1,
			($best & 2) === 0 ? $py : $py + 1,
			($best & 1) === 0 ? $pz : $pz + 1
		);
	}

	private function computeFiddle(int $key, int $x, int $y, int $z) : void{
		$seed = $this->zoomSeed;
		$r = self::lcg($seed, $x);
		$r = self::lcg($r, $y);
		$r = self::lcg($r, $z);
		$r = self::lcg($r, $x);
		$r = self::lcg($r, $y);
		$r = self::lcg($r, $z);
		$this->fiddleX[$key] = self::fiddle($r);
		$r = self::lcg($r, $seed);
		$this->fiddleY[$key] = self::fiddle($r);
		$r = self::lcg($r, $seed);
		$this->fiddleZ[$key] = self::fiddle($r);
	}

	private static function lcg(int $value, int $add) : int{
		return Int64::add(Int64::mul($value, Int64::add(Int64::mul($value, self::LCG_MULTIPLIER), self::LCG_INCREMENT)), $add);
	}

	private static function fiddle(int $value) : float{
		return ((($value >> 24) & 1023) / 1024.0 - 0.5) * 0.9;
	}
}
