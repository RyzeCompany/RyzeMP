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

namespace pocketmine\world\generator\vanilla\density;

use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\noise\SimplexNoise;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use function abs;
use function count;
use function fmod;
use function intdiv;
use function sqrt;

/**
 * "minecraft:end_outer_islands": the small islands of the outer End (more than 64 chunks from 0,0), one candidate per
 * chunk where a seeded simplex noise is low enough, each a cone of seed-independent size. The central island is a
 * separate part of the density function. Computed with Java's int division and float arithmetic.
 */
final class EndIslandsFunction extends DensityFunction{
	/** Height value when no island is near (the clamp minimum). */
	private const NO_ISLAND = -100.0;
	private const ISLAND_THRESHOLD = -0.8999999761581421; //(double) -0.9F

	private SimplexNoise $islandNoise;
	/**
	 * Noise threshold results per island chunk ((x << 32) | (z & 0xffffffff)): islands are sparse and every section
	 * looks at 25x25 chunks, so this saves most of the noise samples of a chunk.
	 * @var bool[]
	 * @phpstan-var array<int, bool>
	 */
	private array $islandCache = [];

	public function __construct(int $seed){
		parent::__construct(-0.84375, 0.5625);
		$random = new LegacyRandom($seed);
		$random->consume(17292);
		$this->islandNoise = new SimplexNoise($random);
	}

	public function compute(float $x, float $y, float $z) : float{
		return ($this->heightValue(intdiv((int) $x, 8), intdiv((int) $z, 8)) - 8.0) / 128.0;
	}

	private function isIsland(int $chunkX, int $chunkZ) : bool{
		$key = ($chunkX << 32) | ($chunkZ & 0xffffffff);
		if(isset($this->islandCache[$key])){
			return $this->islandCache[$key];
		}
		if(count($this->islandCache) >= 65536){
			$this->islandCache = [];
		}
		return $this->islandCache[$key] = $chunkX * $chunkX + $chunkZ * $chunkZ > 4096 && $this->islandNoise->getValue2D($chunkX, $chunkZ) < self::ISLAND_THRESHOLD;
	}

	/**
	 * Highest island cone value (float) at a section (8x8 block column), or NO_ISLAND.
	 */
	public function heightValue(int $sectionX, int $sectionZ) : float{
		$chunkX = intdiv($sectionX, 2);
		$chunkZ = intdiv($sectionZ, 2);
		$subX = $sectionX % 2;
		$subZ = $sectionZ % 2;
		$value = self::NO_ISLAND;
		for($ox = -12; $ox <= 12; ++$ox){
			for($oz = -12; $oz <= 12; ++$oz){
				$islandX = $chunkX + $ox;
				$islandZ = $chunkZ + $oz;
				if(!$this->isIsland($islandX, $islandZ)){
					continue;
				}
				//(abs((float) x) * 3439.0F + abs((float) z) * 147.0F) % 13.0F + 9.0F
				$a = FloatMath::fround(FloatMath::fround((float) abs($islandX)) * 3439.0);
				$b = FloatMath::fround(FloatMath::fround((float) abs($islandZ)) * 147.0);
				$size = FloatMath::fround(fmod(FloatMath::fround($a + $b), 13.0) + 9.0);
				$dx = (float) ($subX - $ox * 2);
				$dz = (float) ($subZ - $oz * 2);
				$distance = FloatMath::fround(sqrt(FloatMath::fround(FloatMath::fround($dx * $dx) + FloatMath::fround($dz * $dz))));
				$island = FloatMath::fround(100.0 - FloatMath::fround($distance * $size));
				if($island > 80.0){
					$island = 80.0;
				}elseif($island < -100.0){
					$island = -100.0;
				}
				if($island > $value){
					$value = $island;
				}
			}
		}
		return $value;
	}

	public function children() : array{
		return [];
	}

	public function dependsOnY() : bool{
		return false;
	}
}
