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

namespace pocketmine\world\generator\vanilla\structure\placement;

use pocketmine\world\generator\vanilla\math\Int64;
use function intdiv;

/**
 * Bedrock Edition's random spread placement: like Java's (one placement chunk per spacing x spacing region), but the
 * offset comes from a Mersenne Twister seeded with the region coordinates, the world seed and the structure salt.
 *
 * The seeding constants come from public Bedrock seed tools and are NOT verified against BDS yet (see
 * STRUCTURES-PLAN.md): compare with /locate on a Bedrock Dedicated Server before relying on exact positions.
 */
final class BedrockSpreadPlacement extends StructurePlacement{
	public const REGION_X_MULTIPLIER = -1683231919;
	public const REGION_Z_MULTIPLIER = -1100435783;

	/**
	 * @param int $range number of possible offsets per axis (Java's spacing - separation)
	 * @phpstan-param array{int, int, int} $locateOffset
	 */
	public function __construct(
		int $salt,
		public readonly int $spacing,
		public readonly int $range,
		public readonly bool $triangular,
		float $frequency = 1.0,
		int $frequencyReduction = self::REDUCTION_DEFAULT,
		?string $exclusionSet = null,
		int $exclusionChunks = 0,
		array $locateOffset = [0, 0, 0]
	){
		if($range < 1 || $range > $spacing){
			throw new \InvalidArgumentException("Range must be in [1, spacing]");
		}
		parent::__construct($salt, $frequency, $frequencyReduction, $exclusionSet, $exclusionChunks, $locateOffset);
	}

	/**
	 * @phpstan-return array{int, int}
	 */
	public function getPotentialChunk(int $seed, int $regionX, int $regionZ) : array{
		$mtSeed = Int64::add(Int64::add(Int64::add(Int64::mul($regionX, self::REGION_X_MULTIPLIER), Int64::mul($regionZ, self::REGION_Z_MULTIPLIER)), $seed), $this->salt);
		$mt = new MersenneTwister($mtSeed & 0xffffffff);
		$range = $this->range;
		if($this->triangular){
			$x = intdiv($mt->nextBoundedInt($range) + $mt->nextBoundedInt($range), 2);
			$z = intdiv($mt->nextBoundedInt($range) + $mt->nextBoundedInt($range), 2);
		}else{
			$x = $mt->nextBoundedInt($range);
			$z = $mt->nextBoundedInt($range);
		}
		return [$regionX * $this->spacing + $x, $regionZ * $this->spacing + $z];
	}

	public function isPlacementChunk(int $seed, int $chunkX, int $chunkZ) : bool{
		[$x, $z] = $this->getPotentialChunk($seed, RandomSpreadPlacement::floorDiv($chunkX, $this->spacing), RandomSpreadPlacement::floorDiv($chunkZ, $this->spacing));
		return $x === $chunkX && $z === $chunkZ;
	}

	public function placementChunksIn(int $seed, int $minChunkX, int $minChunkZ, int $maxChunkX, int $maxChunkZ) : array{
		$result = [];
		for($rx = RandomSpreadPlacement::floorDiv($minChunkX, $this->spacing), $maxRx = RandomSpreadPlacement::floorDiv($maxChunkX, $this->spacing); $rx <= $maxRx; ++$rx){
			for($rz = RandomSpreadPlacement::floorDiv($minChunkZ, $this->spacing), $maxRz = RandomSpreadPlacement::floorDiv($maxChunkZ, $this->spacing); $rz <= $maxRz; ++$rz){
				[$x, $z] = $this->getPotentialChunk($seed, $rx, $rz);
				if($x >= $minChunkX && $x <= $maxChunkX && $z >= $minChunkZ && $z <= $maxChunkZ){
					$result[] = [$x, $z];
				}
			}
		}
		return $result;
	}

	public function getSpacing() : int{
		return $this->spacing;
	}
}
