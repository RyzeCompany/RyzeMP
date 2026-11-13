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

use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\math\Int64;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\StructureRandom;
use pocketmine\world\generator\vanilla\WorldgenData;
use function is_array;
use function is_int;

/**
 * Where the structures of a structure set may start (vanilla's StructurePlacement): the placement chunks of the
 * concrete placement, then the frequency reduction and the exclusion zone.
 */
abstract class StructurePlacement{
	public const REDUCTION_DEFAULT = 0;
	public const REDUCTION_LEGACY_1 = 1;
	public const REDUCTION_LEGACY_2 = 2;
	public const REDUCTION_LEGACY_3 = 3;

	/**
	 * @phpstan-param array{int, int, int} $locateOffset
	 */
	public function __construct(
		public readonly int $salt,
		public readonly float $frequency = 1.0,
		public readonly int $frequencyReduction = self::REDUCTION_DEFAULT,
		public readonly ?string $exclusionSet = null,
		public readonly int $exclusionChunks = 0,
		public readonly array $locateOffset = [0, 0, 0]
	){}

	/**
	 * Parses the common fields of a placement ("frequency", "frequency_reduction_method", "exclusion_zone",
	 * "locate_offset").
	 * @param mixed[] $json
	 * @phpstan-return array{float, int, ?string, int, array{int, int, int}}
	 */
	protected static function parseCommon(array $json) : array{
		//a Java float
		$frequency = FloatMath::fround(WorldgenData::float($json, "frequency", 1.0));
		$reduction = match(WorldgenData::stripNamespace(WorldgenData::string($json, "frequency_reduction_method", "default"))){
			"legacy_type_1" => self::REDUCTION_LEGACY_1,
			"legacy_type_2" => self::REDUCTION_LEGACY_2,
			"legacy_type_3" => self::REDUCTION_LEGACY_3,
			default => self::REDUCTION_DEFAULT
		};
		$exclusion = is_array($json["exclusion_zone"] ?? null) ? $json["exclusion_zone"] : null;
		$offset = [0, 0, 0];
		if(is_array($json["locate_offset"] ?? null)){
			$values = $json["locate_offset"];
			$offset = [is_int($values[0] ?? null) ? $values[0] : 0, is_int($values[1] ?? null) ? $values[1] : 0, is_int($values[2] ?? null) ? $values[2] : 0];
		}
		return [
			$frequency,
			$reduction,
			$exclusion !== null ? WorldgenData::string($exclusion, "other_set") : null,
			$exclusion !== null ? WorldgenData::int($exclusion, "chunk_count") : 0,
			$offset
		];
	}

	/**
	 * Whether the chunk is a placement chunk of this placement (before frequency and exclusion checks).
	 */
	abstract public function isPlacementChunk(int $seed, int $chunkX, int $chunkZ) : bool;

	/**
	 * The placement chunks inside the given chunk area (inclusive), as [chunkX, chunkZ] pairs.
	 * @phpstan-return list<array{int, int}>
	 */
	abstract public function placementChunksIn(int $seed, int $minChunkX, int $minChunkZ, int $maxChunkX, int $maxChunkZ) : array;

	/**
	 * Typical distance in chunks between two placement chunks (used to size searches).
	 */
	abstract public function getSpacing() : int;

	/**
	 * StructurePlacement.isStructureChunk()
	 * @phpstan-param \Closure(string, int, int, int) : bool $hasStructureChunkInRange checks another set's placement
	 */
	public function isStructureChunk(int $seed, int $chunkX, int $chunkZ, \Closure $hasStructureChunkInRange) : bool{
		if(!$this->isPlacementChunk($seed, $chunkX, $chunkZ)){
			return false;
		}
		return $this->passesRestrictions($seed, $chunkX, $chunkZ, $hasStructureChunkInRange);
	}

	/**
	 * The frequency and exclusion zone checks of isStructureChunk(), for a known placement chunk.
	 * @phpstan-param \Closure(string, int, int, int) : bool $hasStructureChunkInRange
	 */
	public function passesRestrictions(int $seed, int $chunkX, int $chunkZ, \Closure $hasStructureChunkInRange) : bool{
		if($this->frequency < 1.0 && !$this->passesFrequency($seed, $chunkX, $chunkZ)){
			return false;
		}
		if($this->exclusionSet !== null && $hasStructureChunkInRange($this->exclusionSet, $chunkX, $chunkZ, $this->exclusionChunks)){
			return false;
		}
		return true;
	}

	/**
	 * StructurePlacement.FrequencyReductionMethod
	 */
	public function passesFrequency(int $seed, int $chunkX, int $chunkZ) : bool{
		$frequency = $this->frequency;
		switch($this->frequencyReduction){
			case self::REDUCTION_LEGACY_1:
				$i = $chunkX >> 4;
				$j = $chunkZ >> 4;
				$random = new LegacyRandom(Int64::toInt32($i ^ ($j << 4)) ^ $seed);
				$random->nextInt();
				return $random->nextBoundedInt((int) FloatMath::fround(1.0 / $frequency)) === 0;
			case self::REDUCTION_LEGACY_2:
				return StructureRandom::largeFeatureWithSalt($seed, $chunkX, $chunkZ, 10387320)->nextFloat() < $frequency;
			case self::REDUCTION_LEGACY_3:
				return StructureRandom::largeFeature($seed, $chunkX, $chunkZ)->nextDouble() < $frequency;
			default:
				//vanilla passes (seed, salt, x, z) to setLargeFeatureWithSalt(seed, x, z, salt)
				return StructureRandom::largeFeatureWithSalt($seed, $this->salt, $chunkX, $chunkZ)->nextFloat() < $frequency;
		}
	}

	/**
	 * The block position /locate reports for a start chunk (StructurePlacement.getLocatePos()).
	 * @phpstan-return array{int, int, int}
	 */
	public function getLocatePos(int $chunkX, int $chunkZ) : array{
		return [($chunkX << 4) + $this->locateOffset[0], $this->locateOffset[1], ($chunkZ << 4) + $this->locateOffset[2]];
	}
}
