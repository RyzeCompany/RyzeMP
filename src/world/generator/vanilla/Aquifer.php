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

use pocketmine\world\generator\vanilla\density\DensityFunction;
use pocketmine\world\generator\vanilla\random\PositionalRandomFactory;
use function abs;
use function floor;
use function intdiv;
use function max;
use function min;
use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * Noise-based aquifers: local water/lava levels in 16x12x16 cells with barriers between them
 * (vanilla 1.18+ behaviour; structure follows misode/deepslate's NoiseAquifer, MIT, with Java 26.3's semantics where
 * deepslate 0.27.2 differs: both section offsets when sampling the surface, floorDiv(z, 16) and the x10 spread for
 * fluid level cells).
 *
 * Java 26.3 details checked against a real server: aquifer centers are spread by nextInt(10), nextInt(9), nextInt(10);
 * the center column is sampled first when looking for the surface; a center at the same distance as a previous one
 * takes its place in the nearest-three ordering; the density samplers return floats (so every noise value read here
 * is rounded to single precision, and the spread is multiplied by 10 in single precision), while the similarity and
 * barrier pressure math is done in double precision.
 *
 * Materials are the MATERIAL_* constants; -1 means "keep the default block" (stone).
 */
final class Aquifer{
	public const MATERIAL_AIR = 0;
	public const MATERIAL_STONE = 1;
	public const MATERIAL_WATER = 2;
	public const MATERIAL_LAVA = 3;

	/** Vanilla "wait for solid height" level: DimensionType.MIN_Y << 4 (no fluid in this aquifer). */
	private const MIN_LEVEL = -2032 << 4;
	/** Section offsets sampled around an aquifer center, in vanilla's order (the center first). */
	private const SURFACE_SAMPLING = [[0, 0], [-2, -1], [-1, -1], [0, -1], [1, -1], [-3, 0], [-2, 0], [-1, 0], [1, 0], [-2, 1], [-1, 1], [0, 1], [1, 1]];

	/** @phpstan-var array<int, array{int, int, int}> grid key => block position */
	private array $locationCache = [];
	/** @phpstan-var array<int, array{int, int}> grid key => [level, material] */
	private array $statusCache = [];
	/** @phpstan-var array<int, int> */
	private array $surfaceCache = [];
	private int $skipSamplingAboveY;
	private int $anchorX = PHP_INT_MIN;
	private int $anchorY = PHP_INT_MIN;
	private int $anchorZ = PHP_INT_MIN;
	/**
	 * @var int[]
	 * @phpstan-var list<int>
	 */
	private array $anchorPoints = [];
	/**
	 * @var int[]
	 * @phpstan-var list<int>
	 */
	private array $anchorKeys = [];

	public function __construct(
		private NoiseRouter $router,
		private PositionalRandomFactory $random,
		int $chunkX,
		int $chunkZ,
		private DensityFunction $barrier,
		private DensityFunction $floodedness,
		private DensityFunction $spread,
		private DensityFunction $lava,
		private DensityFunction $exclusion,
		private DensityFunction $surfaceLevel
	){
		$minGridX = self::floorDiv($chunkX << 4, 16) - 1;
		$maxGridX = self::floorDiv(($chunkX << 4) + 15, 16) + 1;
		$minGridZ = self::floorDiv($chunkZ << 4, 16) - 1;
		$maxGridZ = self::floorDiv(($chunkZ << 4) + 15, 16) + 1;
		$maxSurface = PHP_INT_MIN;
		for($z = $minGridZ * 16; $z <= $maxGridZ * 16 + 9; $z += 4){
			for($x = $minGridX * 16; $x <= $maxGridX * 16 + 9; $x += 4){
				$maxSurface = max($maxSurface, $this->surfaceLevelAt($x, $z));
			}
		}
		$adjusted = $maxSurface + 8;
		$this->skipSamplingAboveY = (self::floorDiv($adjusted + 12, 12) + 1) * 12 + 11 - 1;
	}

	public static function floorDiv(int $a, int $b) : int{
		$q = intdiv($a, $b);
		return ($a % $b !== 0 && (($a < 0) !== ($b < 0))) ? $q - 1 : $q;
	}

	public function getSkipSamplingAboveY() : int{
		return $this->skipSamplingAboveY;
	}

	private static function fround(float $x) : float{
		$c = $x * 536870913.0;
		return $c - ($c - $x);
	}

	/**
	 * Global fluid: lava below y=-54, sea water below sea level, air above.
	 * @return int[]
	 * @phpstan-return array{int, int}
	 */
	private function globalFluid(int $y) : array{
		$sea = $this->router->seaLevel;
		if($y < min(-54, $sea)){
			return [-54, self::MATERIAL_LAVA];
		}
		return [$sea, self::MATERIAL_WATER];
	}

	private function surfaceLevelAt(int $x, int $z) : int{
		$qx = ($x >> 2) << 2;
		$qz = ($z >> 2) << 2;
		$key = (($qx & 0xffffffff) << 32) | ($qz & 0xffffffff);
		return $this->surfaceCache[$key] ??= (int) floor($this->surfaceLevel->compute($qx, 0, $qz));
	}

	/**
	 * Returns one of the MATERIAL_* constants, or -1 when the block stays solid (default block).
	 */
	public function computeSubstance(int $x, int $y, int $z, float $density) : int{
		if($density > 0){
			return -1;
		}
		[$globalLevel, $globalType] = $this->globalFluid($y);
		if($y > $this->skipSamplingAboveY){
			return $y < $globalLevel ? $globalType : self::MATERIAL_AIR;
		}
		if($y < $globalLevel && $globalType === self::MATERIAL_LAVA){
			return self::MATERIAL_LAVA;
		}

		$gx = ($x - 5) >> 4;
		$gy = self::floorDiv($y + 1, 12);
		$gz = ($z - 5) >> 4;
		if($gx !== $this->anchorX || $gy !== $this->anchorY || $gz !== $this->anchorZ){
			$this->loadAnchor($gx, $gy, $gz);
		}
		$points = $this->anchorPoints;
		$keys = $this->anchorKeys;
		$d1 = $d2 = $d3 = PHP_INT_MAX;
		$k1 = $k2 = $k3 = 0;
		for($i = 0, $o = 0; $i < 12; ++$i, $o += 3){
			$dx = $points[$o] - $x;
			$dy = $points[$o + 1] - $y;
			$dz = $points[$o + 2] - $z;
			$d = $dx * $dx + $dy * $dy + $dz * $dz;
			//vanilla: a center at the same distance as a previous one goes before it
			if($d1 >= $d){
				$d3 = $d2;
				$k3 = $k2;
				$d2 = $d1;
				$k2 = $k1;
				$d1 = $d;
				$k1 = $keys[$i];
			}elseif($d2 >= $d){
				$d3 = $d2;
				$k3 = $k2;
				$d2 = $d;
				$k2 = $keys[$i];
			}elseif($d3 >= $d){
				$d3 = $d;
				$k3 = $keys[$i];
			}
		}

		[$level1, $type1] = $this->status($k1);
		$state = $y < $level1 ? $type1 : self::MATERIAL_AIR;
		$sim12 = self::similarity($d1, $d2);
		if($sim12 <= 0){
			return $state;
		}
		if($state === self::MATERIAL_WATER){
			[$l, $t] = $this->globalFluid($y - 1);
			if($y - 1 < $l && $t === self::MATERIAL_LAVA){
				return $state;
			}
		}
		$barrier = null;
		[$level2, $type2] = $this->status($k2);
		if($density + $sim12 * $this->pressure($x, $y, $z, $barrier, $level1, $type1, $level2, $type2) > 0){
			return -1;
		}
		[$level3, $type3] = $this->status($k3);
		$sim13 = self::similarity($d1, $d3);
		if($sim13 > 0 && $density + $sim12 * $sim13 * $this->pressure($x, $y, $z, $barrier, $level1, $type1, $level3, $type3) > 0){
			return -1;
		}
		$sim23 = self::similarity($d2, $d3);
		if($sim23 > 0 && $density + $sim12 * $sim23 * $this->pressure($x, $y, $z, $barrier, $level2, $type2, $level3, $type3) > 0){
			return -1;
		}
		return $state;
	}

	/**
	 * Loads the 12 aquifer centers around a grid anchor (same order as vanilla: x, then y from -1, then z).
	 */
	private function loadAnchor(int $gx, int $gy, int $gz) : void{
		$this->anchorX = $gx;
		$this->anchorY = $gy;
		$this->anchorZ = $gz;
		$points = [];
		$keys = [];
		for($ox = 0; $ox <= 1; ++$ox){
			for($oy = -1; $oy <= 1; ++$oy){
				for($oz = 0; $oz <= 1; ++$oz){
					$cx = $gx + $ox;
					$cy = $gy + $oy;
					$cz = $gz + $oz;
					$key = (($cx & 0x1fffff) << 42) | (($cy & 0x1fffff) << 21) | ($cz & 0x1fffff);
					if(!isset($this->locationCache[$key])){
						$r = $this->random->at($cx, $cy, $cz);
						$this->locationCache[$key] = [
							$cx * 16 + $r->nextBoundedInt(10),
							$cy * 12 + $r->nextBoundedInt(9),
							$cz * 16 + $r->nextBoundedInt(10)
						];
					}
					[$lx, $ly, $lz] = $this->locationCache[$key];
					$points[] = $lx;
					$points[] = $ly;
					$points[] = $lz;
					$keys[] = $key;
				}
			}
		}
		$this->anchorPoints = $points;
		$this->anchorKeys = $keys;
	}

	private static function similarity(int $a, int $b) : float{
		//$b >= $a (sorted distances)
		return 1.0 - ($b - $a) / 25.0;
	}

	private function pressure(int $x, int $y, int $z, ?float &$barrier, int $level1, int $type1, int $level2, int $type2) : float{
		$s1 = $y < $level1 ? $type1 : self::MATERIAL_AIR;
		$s2 = $y < $level2 ? $type2 : self::MATERIAL_AIR;
		if(($s1 === self::MATERIAL_LAVA && $s2 === self::MATERIAL_WATER) || ($s1 === self::MATERIAL_WATER && $s2 === self::MATERIAL_LAVA)){
			return 2.0;
		}
		$diff = abs($level1 - $level2);
		if($diff === 0){
			return 0.0;
		}
		$scaled = ($y + 0.5) - 0.5 * ($level1 + $level2);
		$o = $diff / 2.0 - abs($scaled);
		if($scaled > 0){
			$q = $o > 0 ? $o / 1.5 : $o / 2.5;
		}else{
			$p = 3.0 + $o;
			$q = $p > 0 ? $p / 3.0 : $p / 10.0;
		}
		if($q >= -2.0 && $q <= 2.0){
			$barrier ??= self::fround($this->barrier->compute($x, $y, $z));
			$r = $barrier;
		}else{
			$r = 0.0;
		}
		return 2.0 * ($r + $q);
	}

	/**
	 * @return int[]
	 * @phpstan-return array{int, int}
	 */
	private function status(int $key) : array{
		if(isset($this->statusCache[$key])){
			return $this->statusCache[$key];
		}
		[$x, $y, $z] = $this->locationCache[$key];
		return $this->statusCache[$key] = $this->computeFluid($x, $y, $z);
	}

	/**
	 * @return int[]
	 * @phpstan-return array{int, int}
	 */
	private function computeFluid(int $x, int $y, int $z) : array{
		$global = $this->globalFluid($y);
		$lowest = PHP_INT_MAX;
		$centerUnderFluid = false;
		foreach(self::SURFACE_SAMPLING as [$ox, $oz]){
			$sx = $x + ($ox << 4);
			$sz = $z + ($oz << 4);
			$surface = $this->surfaceLevelAt($sx, $sz);
			$adjusted = $surface + 8;
			$start = $ox === 0 && $oz === 0;
			if($start && $y - 12 > $adjusted){
				return $global;
			}
			$above = $y + 12 > $adjusted;
			if($above || $start){
				[$l, $t] = $this->globalFluid($adjusted);
				if($adjusted < $l && $t !== self::MATERIAL_AIR){
					if($start){
						$centerUnderFluid = true;
					}
					if($above){
						return [$l, $t];
					}
				}
			}
			$lowest = min($lowest, $surface);
		}

		$level = $this->fluidSurfaceLevel($x, $y, $z, $global[0], $lowest, $centerUnderFluid);
		$type = $global[1];
		if($level <= -10 && $level !== self::MIN_LEVEL && $global[1] !== self::MATERIAL_LAVA){
			$lava = self::fround($this->lava->compute(self::floorDiv($x, 64), self::floorDiv($y, 40), self::floorDiv($z, 64)));
			if(abs($lava) > 0.3){
				$type = self::MATERIAL_LAVA;
			}
		}
		return [$level, $type];
	}

	private function fluidSurfaceLevel(int $x, int $y, int $z, int $globalLevel, int $lowestSurface, bool $centerUnderFluid) : int{
		//exact position: vanilla's cached erosion/offset volumes only answer for positions on their quart grid, where
		//the cached value is the exact one
		if(self::fround($this->exclusion->compute($x, $y, $z)) > 0){
			$partially = -1.0;
			$fully = -1.0;
		}else{
			$distanceBelowSurface = $lowestSurface + 8 - $y;
			if($centerUnderFluid){
				$t = $distanceBelowSurface / 64.0;
				$factor = $t < 0 ? 1.0 : ($t > 1 ? 0.0 : 1.0 + $t * (0.0 - 1.0));
			}else{
				$factor = 0.0;
			}
			$noise = self::fround($this->floodedness->compute($x, $y, $z));
			$noise = $noise < -1 ? -1.0 : ($noise > 1 ? 1.0 : $noise);
			$t = ($factor - 1.0) / (0.0 - 1.0);
			$fullyThreshold = -0.3 + $t * (0.8 - -0.3);
			$partiallyThreshold = -0.8 + $t * (0.4 - -0.8);
			$partially = $noise - $partiallyThreshold;
			$fully = $noise - $fullyThreshold;
		}
		if($fully > 0){
			return $globalLevel;
		}
		if($partially > 0){
			$cellX = self::floorDiv($x, 16);
			$cellY = self::floorDiv($y, 40);
			$cellZ = self::floorDiv($z, 16);
			$middle = $cellY * 40 + 20;
			//sampled as a float and multiplied by 10f
			$spread = self::fround(self::fround($this->spread->compute($cellX, $cellY, $cellZ)) * 10.0);
			$quantized = (int) floor($spread / 3.0) * 3;
			return min($lowestSurface, $middle + $quantized);
		}
		return self::MIN_LEVEL;
	}
}
