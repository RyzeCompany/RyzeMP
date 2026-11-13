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

use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\WorldgenData;
use function cos;
use function count;
use function floor;
use function intdiv;
use function min;
use function sin;
use const M_PI;

/**
 * Java Edition's ConcentricRingsStructurePlacement (strongholds): `count` positions on rings around the origin
 * (ChunkGeneratorStructureState.generateRingPositions()), each snapped to a random preferred biome within 112 blocks.
 *
 * The positions depend on the seed and the biome source, so a placement read from the data is unbound; the structure
 * manager of each generator binds it (bind()). The biome search of a position (57x57 noise biome samples) is done
 * lazily, only for positions that can be near the chunks asked about: every position has its own forked random, so
 * the order does not matter.
 */
final class ConcentricRingsPlacement extends StructurePlacement{
	/** Biome search radius in blocks (findBiomeHorizontal(..., 112, ...)). */
	public const SEARCH_RADIUS = 112;
	/** How far (in chunks) the biome search can move a position. */
	public const MAX_SHIFT_CHUNKS = 8;

	private int $boundSeed = 0;
	/** @phpstan-var (\Closure(int, int, int) : string)|null block position => noise biome name */
	private ?\Closure $biomeAt = null;
	/**
	 * Initial chunk position and biome search random seed of each ring position.
	 * @phpstan-var list<array{int, int, int}>|null
	 */
	private ?array $initial = null;
	/** @phpstan-var array<int, array{int, int}> index => final chunk position */
	private array $resolved = [];

	/**
	 * @param string[] $preferredBiomes
	 * @phpstan-param array<string, true> $preferredBiomes biome name set
	 * @phpstan-param array{int, int, int} $locateOffset
	 */
	public function __construct(
		int $salt,
		public readonly int $distance,
		public readonly int $spread,
		public readonly int $count,
		public readonly array $preferredBiomes,
		float $frequency = 1.0,
		int $frequencyReduction = self::REDUCTION_DEFAULT,
		?string $exclusionSet = null,
		int $exclusionChunks = 0,
		array $locateOffset = [0, 0, 0]
	){
		parent::__construct($salt, $frequency, $frequencyReduction, $exclusionSet, $exclusionChunks, $locateOffset);
	}

	/**
	 * @param mixed[] $json
	 * @phpstan-param \Closure(mixed) : list<string> $resolveBiomes biome holder set => biome names
	 */
	public static function fromJson(array $json, \Closure $resolveBiomes) : self{
		[$frequency, $reduction, $exclusionSet, $exclusionChunks, $offset] = self::parseCommon($json);
		$preferred = [];
		foreach($resolveBiomes($json["preferred_biomes"] ?? []) as $biome){
			$preferred[$biome] = true;
		}
		return new self(
			WorldgenData::int($json, "salt", 0),
			WorldgenData::int($json, "distance"),
			WorldgenData::int($json, "spread"),
			WorldgenData::int($json, "count"),
			$preferred,
			$frequency,
			$reduction,
			$exclusionSet,
			$exclusionChunks,
			$offset
		);
	}

	/**
	 * A copy of this placement for one generator (ChunkGeneratorStructureState.createForNormal(): the rings seed is
	 * the level seed).
	 * @phpstan-param \Closure(int, int, int) : string $biomeAt block position => noise biome name
	 */
	public function bind(int $seed, \Closure $biomeAt) : self{
		$bound = new self($this->salt, $this->distance, $this->spread, $this->count, $this->preferredBiomes, $this->frequency, $this->frequencyReduction, $this->exclusionSet, $this->exclusionChunks, $this->locateOffset);
		$bound->boundSeed = $seed;
		$bound->biomeAt = $biomeAt;
		return $bound;
	}

	/**
	 * The ring positions before the biome search, with the seed of each search's random (generateRingPositions()).
	 * @phpstan-return list<array{int, int, int}>
	 */
	private function getInitialPositions() : array{
		if($this->initial !== null){
			return $this->initial;
		}
		$result = [];
		if($this->count > 0){
			$random = new LegacyRandom($this->boundSeed);
			$angle = $random->nextDouble() * M_PI * 2.0;
			$positionInCircle = 0;
			$circle = 0;
			$spread = $this->spread;
			$distance = $this->distance;
			for($i = 0; $i < $this->count; ++$i){
				$dist = (4 * $distance + $distance * $circle * 6) + ($random->nextDouble() - 0.5) * ($distance * 2.5);
				$x = (int) floor(cos($angle) * $dist + 0.5);
				$z = (int) floor(sin($angle) * $dist + 0.5);
				//random.fork() of a legacy random: new LegacyRandomSource(nextLong())
				$result[] = [$x, $z, $random->nextLong()];
				$angle += M_PI * 2.0 / $spread;
				if(++$positionInCircle === $spread){
					++$circle;
					$positionInCircle = 0;
					$spread += intdiv(2 * $spread, $circle + 1);
					$spread = min($spread, $this->count - $i);
					$angle += $random->nextDouble() * M_PI * 2.0;
				}
			}
		}
		return $this->initial = $result;
	}

	/**
	 * The final chunk position of a ring position: BiomeSource.findBiomeHorizontal(x, 0, z, 112, preferred, random)
	 * (a full 57x57 square of noise biomes at quart y 0, one picked at random among the preferred ones).
	 * @phpstan-return array{int, int}
	 */
	private function resolve(int $index) : array{
		if(isset($this->resolved[$index])){
			return $this->resolved[$index];
		}
		[$initialX, $initialZ, $forkSeed] = $this->getInitialPositions()[$index];
		$biomeAt = $this->biomeAt ?? throw new \LogicException("Placement is not bound to a generator");
		$random = new LegacyRandom($forkSeed);
		$centerX = (($initialX << 4) + 8) >> 2;
		$centerZ = (($initialZ << 4) + 8) >> 2;
		$radius = self::SEARCH_RADIUS >> 2;
		$result = null;
		$found = 0;
		for($z = -$radius; $z <= $radius; ++$z){
			for($x = -$radius; $x <= $radius; ++$x){
				$noiseX = $centerX + $x;
				$noiseZ = $centerZ + $z;
				if(isset($this->preferredBiomes[$biomeAt($noiseX << 2, 0, $noiseZ << 2)])){
					if($result === null || $random->nextBoundedInt($found + 1) === 0){
						$result = [($noiseX << 2) >> 4, ($noiseZ << 2) >> 4];
					}
					++$found;
				}
			}
		}
		$position = $result ?? [$initialX, $initialZ];
		return $this->resolved[$index] = $position;
	}

	/**
	 * Chunk positions of the ring positions before their biome search (each final position is within
	 * {@link MAX_SHIFT_CHUNKS} of it), indexed like {@link resolveRingPosition()}.
	 * @phpstan-return list<array{int, int}>
	 */
	public function getInitialRingPositions() : array{
		$result = [];
		foreach($this->getInitialPositions() as [$x, $z]){
			$result[] = [$x, $z];
		}
		return $result;
	}

	/**
	 * The final chunk position of one ring position (runs its biome search the first time).
	 * @phpstan-return array{int, int}
	 */
	public function resolveRingPosition(int $index) : array{
		return $this->resolve($index);
	}

	/**
	 * Every ring position (runs all the biome searches: slow, ~0.3 s each with the PHP biome source).
	 * @phpstan-return list<array{int, int}>
	 */
	public function getRingPositions() : array{
		$result = [];
		for($i = 0, $n = count($this->getInitialPositions()); $i < $n; ++$i){
			$result[] = $this->resolve($i);
		}
		return $result;
	}

	public function isPlacementChunk(int $seed, int $chunkX, int $chunkZ) : bool{
		return count($this->placementChunksIn($seed, $chunkX, $chunkZ, $chunkX, $chunkZ)) > 0;
	}

	public function placementChunksIn(int $seed, int $minChunkX, int $minChunkZ, int $maxChunkX, int $maxChunkZ) : array{
		$result = [];
		$seen = [];
		foreach($this->getInitialPositions() as $index => [$x, $z]){
			if(
				$x < $minChunkX - self::MAX_SHIFT_CHUNKS || $x > $maxChunkX + self::MAX_SHIFT_CHUNKS ||
				$z < $minChunkZ - self::MAX_SHIFT_CHUNKS || $z > $maxChunkZ + self::MAX_SHIFT_CHUNKS
			){
				continue;
			}
			[$fx, $fz] = $this->resolve($index);
			if($fx >= $minChunkX && $fx <= $maxChunkX && $fz >= $minChunkZ && $fz <= $maxChunkZ && !isset($seen["$fx:$fz"])){
				//two positions can snap to the same chunk: one start
				$seen["$fx:$fz"] = true;
				$result[] = [$fx, $fz];
			}
		}
		return $result;
	}

	public function getSpacing() : int{
		return $this->distance;
	}
}
