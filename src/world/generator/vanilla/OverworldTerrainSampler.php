<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use pocketmine\data\bedrock\BiomeIds;
use function abs;
use function crc32;
use function floor;
use function max;
use function min;
use function round;

/**
 * Experimental, stable 2D terrain profile for newly created worlds.
 * Not a replica of Mojang's multi-noise worldgen: cave/aquifer and structure stages are not implemented.
 * Every sample depends only on the seed and absolute block coordinates, not on chunk order.
 */
final class OverworldTerrainSampler{

	public function __construct(private readonly int $seed){}

	/** @return array{height: int, biome: int} */
	public function sample(int $x, int $z) : array{
		$continent = $this->noise($x, $z, 384, 1) * 0.75 + $this->noise($x, $z, 144, 2) * 0.25;
		$detail = $this->noise($x, $z, 64, 3);
		$mountains = max(0.0, $continent + 0.1) * max(0.0, $this->noise($x, $z, 112, 4));
		$height = (int) round(64 + $continent * 52 + $detail * 12 + $mountains * 55);
		$height = max(-32, min(196, $height));

		if($continent < -0.24){
			return ['height' => min($height, 58), 'biome' => BiomeIds::OCEAN];
		}
		if(abs($this->noise($x, $z, 156, 5)) < 0.035 && $continent < 0.38){
			return ['height' => min($height, 60), 'biome' => BiomeIds::RIVER];
		}
		if($height <= 65){
			return ['height' => $height, 'biome' => BiomeIds::BEACH];
		}
		if($height >= 108){
			return ['height' => $height, 'biome' => BiomeIds::EXTREME_HILLS];
		}

		$temperature = $this->noise($x, $z, 256, 6);
		$humidity = $this->noise($x, $z, 256, 7);
		$biome = match(true){
			$temperature < -0.52 => BiomeIds::ICE_PLAINS,
			$temperature < -0.20 => BiomeIds::TAIGA,
			$temperature > 0.38 && $humidity < -0.08 => BiomeIds::DESERT,
			$humidity > 0.1 => BiomeIds::FOREST,
			default => BiomeIds::PLAINS
		};
		return ['height' => $height, 'biome' => $biome];
	}

	/** 2D lattice noise, with smooth interpolation across cell and chunk boundaries. */
	private function noise(int $x, int $z, int $scale, int $salt) : float{
		$u = $x / $scale;
		$v = $z / $scale;
		$ix = (int) floor($u);
		$iz = (int) floor($v);
		$fx = $u - $ix;
		$fz = $v - $iz;
		$fx = $fx * $fx * (3 - 2 * $fx);
		$fz = $fz * $fz * (3 - 2 * $fz);
		$bottom = $this->lattice($ix, $iz, $salt) * (1 - $fx) + $this->lattice($ix + 1, $iz, $salt) * $fx;
		$top = $this->lattice($ix, $iz + 1, $salt) * (1 - $fx) + $this->lattice($ix + 1, $iz + 1, $salt) * $fx;
		return $bottom * (1 - $fz) + $top * $fz;
	}

	private function lattice(int $x, int $z, int $salt) : float{
		return ((crc32("{$this->seed}:$salt:$x:$z") & 0xffffffff) / 4294967295) * 2 - 1;
	}
}
