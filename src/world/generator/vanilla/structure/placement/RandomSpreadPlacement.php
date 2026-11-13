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

use pocketmine\world\generator\vanilla\structure\StructureRandom;
use pocketmine\world\generator\vanilla\WorldgenData;
use function intdiv;

/**
 * Java Edition's RandomSpreadStructurePlacement: one placement chunk per spacing x spacing region, at a random
 * offset in [0, spacing - separation) from the region corner (linear or triangular spread).
 */
final class RandomSpreadPlacement extends StructurePlacement{

	/**
	 * @phpstan-param array{int, int, int} $locateOffset
	 */
	public function __construct(
		int $salt,
		public readonly int $spacing,
		public readonly int $separation,
		public readonly bool $triangular,
		float $frequency = 1.0,
		int $frequencyReduction = self::REDUCTION_DEFAULT,
		?string $exclusionSet = null,
		int $exclusionChunks = 0,
		array $locateOffset = [0, 0, 0]
	){
		if($spacing <= $separation || $spacing < 1){
			throw new \InvalidArgumentException("Spacing must be larger than separation");
		}
		parent::__construct($salt, $frequency, $frequencyReduction, $exclusionSet, $exclusionChunks, $locateOffset);
	}

	/**
	 * @param mixed[] $json
	 */
	public static function fromJson(array $json) : self{
		[$frequency, $reduction, $exclusionSet, $exclusionChunks, $offset] = self::parseCommon($json);
		return new self(
			WorldgenData::int($json, "salt"),
			WorldgenData::int($json, "spacing"),
			WorldgenData::int($json, "separation"),
			WorldgenData::stripNamespace(WorldgenData::string($json, "spread_type", "linear")) === "triangular",
			$frequency,
			$reduction,
			$exclusionSet,
			$exclusionChunks,
			$offset
		);
	}

	public static function floorDiv(int $a, int $b) : int{
		$q = intdiv($a, $b);
		return ($a % $b !== 0 && (($a < 0) !== ($b < 0))) ? $q - 1 : $q;
	}

	/**
	 * RandomSpreadStructurePlacement.getPotentialStructureChunk()
	 * @phpstan-return array{int, int}
	 */
	public function getPotentialChunk(int $seed, int $regionX, int $regionZ) : array{
		$random = StructureRandom::largeFeatureWithSalt($seed, $regionX, $regionZ, $this->salt);
		$range = $this->spacing - $this->separation;
		if($this->triangular){
			$x = intdiv($random->nextBoundedInt($range) + $random->nextBoundedInt($range), 2);
			$z = intdiv($random->nextBoundedInt($range) + $random->nextBoundedInt($range), 2);
		}else{
			$x = $random->nextBoundedInt($range);
			$z = $random->nextBoundedInt($range);
		}
		return [$regionX * $this->spacing + $x, $regionZ * $this->spacing + $z];
	}

	public function isPlacementChunk(int $seed, int $chunkX, int $chunkZ) : bool{
		[$x, $z] = $this->getPotentialChunk($seed, self::floorDiv($chunkX, $this->spacing), self::floorDiv($chunkZ, $this->spacing));
		return $x === $chunkX && $z === $chunkZ;
	}

	public function placementChunksIn(int $seed, int $minChunkX, int $minChunkZ, int $maxChunkX, int $maxChunkZ) : array{
		$result = [];
		for($rx = self::floorDiv($minChunkX, $this->spacing), $maxRx = self::floorDiv($maxChunkX, $this->spacing); $rx <= $maxRx; ++$rx){
			for($rz = self::floorDiv($minChunkZ, $this->spacing), $maxRz = self::floorDiv($maxChunkZ, $this->spacing); $rz <= $maxRz; ++$rz){
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
