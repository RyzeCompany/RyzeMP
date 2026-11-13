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

use pocketmine\utils\Utils;
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\structure\placement\BedrockStructurePlacements;
use pocketmine\world\generator\vanilla\structure\placement\ConcentricRingsPlacement;
use pocketmine\world\generator\vanilla\structure\placement\RandomSpreadPlacement;
use pocketmine\world\generator\vanilla\structure\placement\StructurePlacement;
use pocketmine\world\locate\LocateTimeoutException;
use function array_key_first;
use function count;
use function fnmatch;
use function intdiv;
use function max;
use function min;
use function sqrt;
use function str_contains;
use function usort;
use const M_SQRT2;
use const PHP_INT_MAX;

/**
 * The structures of one generator (dimension): where structure starts are (placement + start generation, a pure
 * function of the seed so every worker thread agrees), and their placement chunk by chunk during decoration
 * (vanilla's STRUCTURE_STARTS / STRUCTURE_REFERENCES statuses and the structure part of applyBiomeDecoration()).
 *
 * Starts are computed on demand and kept in a bounded cache; nothing is shared between threads.
 */
final class StructureManager{
	/** Valid starts kept (least recently used dropped first). */
	private const START_CACHE_SIZE = 256;
	/**
	 * Pieces kept in the start cache: code-built starts are big (a mansion has ~500 pieces, a stronghold ~200), and
	 * generator workers have a 256 MB limit.
	 */
	private const START_CACHE_PIECES = 3000;
	/** Chunks known to have no start of a set (cleared when full). */
	private const NO_START_CACHE_SIZE = 8192;
	private const REFERENCE_RANGE = 8;

	/** @phpstan-var array<string, StructurePlacement> set id => placement used */
	private array $placements = [];
	/** @phpstan-var array<string, list<array{StructureDefinition, int}>> set id => enabled structures and weights */
	private array $setStructures = [];
	/** @phpstan-var array<string, int> set id => reach in chunks */
	private array $setReach = [];
	/** @phpstan-var array<string, true> sets with a structure that adapts the terrain (Beardifier) */
	private array $adaptingSets = [];
	/** @phpstan-var array<int, list<array{int, StructureDefinition}>> step => [index in the step, structure] */
	private array $stepStructures = [];
	/** @phpstan-var array<string, StructureStart> "set:x:z" => start */
	private array $starts = [];
	/** @phpstan-var array<string, true> "set:x:z" without start */
	private array $noStarts = [];
	/** @phpstan-var array<string, bool> "set:x:z" => isStructureChunk() */
	private array $structureChunks = [];

	public int $startsComputed = 0;
	private int $cachedPieces = 0;

	/**
	 * @param string[] $disabled        structure or structure set ids (or fnmatch patterns, "minecraft:" optional) not to generate
	 * @param string[] $dimensionBiomes biome names the dimension's biome source can produce
	 * @phpstan-param list<string> $disabled
	 * @phpstan-param list<string> $dimensionBiomes
	 */
	public function __construct(
		public readonly StructureEnvironment $env,
		string $positionMode = BedrockStructurePlacements::MODE_BEDROCK,
		array $disabled = [],
		array $dimensionBiomes = []
	){
		$registry = $env->registry;
		$biomeSet = [];
		foreach($dimensionBiomes as $biome){
			$biomeSet[$biome] = true;
		}
		$isDisabled = function(string $id) use ($disabled) : bool{
			foreach($disabled as $pattern){
				$pattern = str_contains($pattern, ":") ? $pattern : "minecraft:" . $pattern;
				if($pattern === $id || fnmatch($pattern, $id)){
					return true;
				}
			}
			return false;
		};
		foreach(Utils::stringifyKeys($registry->getSets()) as $setId => [$placement, $entries]){
			if($isDisabled($setId)){
				continue;
			}
			$structures = [];
			$reach = 0;
			foreach($entries as [$structureId, $weight]){
				$structure = $registry->getStructure($structureId);
				if($structure === null || $isDisabled($structureId)){
					continue;
				}
				if(count($biomeSet) > 0){
					$possible = false;
					foreach(Utils::stringifyKeys($structure->biomes) as $biome => $_){
						if(isset($biomeSet[$biome])){
							$possible = true;
							break;
						}
					}
					if(!$possible){
						continue;
					}
				}
				$structures[] = [$structure, $weight];
				if($structure->terrainAdaptation !== StructureDefinition::ADAPTATION_NONE){
					$this->adaptingSets[$setId] = true;
				}
				$reach = max($reach, min(self::REFERENCE_RANGE, $structure->type->getReach()));
			}
			if(count($structures) === 0){
				continue;
			}
			if($placement instanceof ConcentricRingsPlacement){
				//strongholds: Java's rings in both position modes (Bedrock's stronghold placement is not known)
				$placement = $placement->bind($env->seed, fn(int $x, int $y, int $z) : string => $env->getBiome($x, $y, $z));
			}
			$this->placements[$setId] = BedrockStructurePlacements::select($setId, $placement, $positionMode);
			$this->setStructures[$setId] = $structures;
			$this->setReach[$setId] = $reach;
		}
		//the decoration random of a structure uses its index among all structures of its step (registry order,
		//including the structures that are not implemented)
		$indexes = [];
		foreach(Utils::stringifyKeys($registry->getStructureSteps()) as $id => $step){
			$index = $indexes[$step] ?? 0;
			$indexes[$step] = $index + 1;
			$structure = $registry->getStructure($id);
			if($structure !== null){
				$this->stepStructures[$step][] = [$index, $structure];
			}
		}
	}

	public function getPlacement(string $setId) : ?StructurePlacement{
		return $this->placements[$setId] ?? null;
	}

	/**
	 * @phpstan-return array<string, StructurePlacement>
	 */
	public function getPlacements() : array{
		return $this->placements;
	}

	/**
	 * @phpstan-return list<array{StructureDefinition, int}>
	 */
	public function getSetStructures(string $setId) : array{
		return $this->setStructures[$setId] ?? [];
	}

	/**
	 * StructurePlacement.isStructureChunk() for a set (cached).
	 */
	public function isStructureChunk(string $setId, int $chunkX, int $chunkZ) : bool{
		$placement = $this->placements[$setId] ?? null;
		if($placement === null){
			return false;
		}
		$key = "$setId:$chunkX:$chunkZ";
		if(isset($this->structureChunks[$key])){
			return $this->structureChunks[$key];
		}
		$result = $placement->isStructureChunk($this->env->seed, $chunkX, $chunkZ, \Closure::fromCallable([$this, 'hasStructureChunkInRange']));
		if(count($this->structureChunks) >= self::NO_START_CACHE_SIZE){
			$this->structureChunks = [];
		}
		return $this->structureChunks[$key] = $result;
	}

	/**
	 * ChunkGeneratorStructureState.hasStructureChunkInRange()
	 */
	public function hasStructureChunkInRange(string $setId, int $chunkX, int $chunkZ, int $range) : bool{
		$placement = $this->placements[$setId] ?? null;
		if($placement === null){
			return false;
		}
		$seed = $this->env->seed;
		foreach($placement->placementChunksIn($seed, $chunkX - $range, $chunkZ - $range, $chunkX + $range, $chunkZ + $range) as [$x, $z]){
			if($this->isStructureChunk($setId, $x, $z)){
				return true;
			}
		}
		return false;
	}

	/**
	 * The start of a structure set in a chunk, or null (ChunkGenerator.createStructures() for one set).
	 */
	public function getStart(string $setId, int $chunkX, int $chunkZ) : ?StructureStart{
		$key = "$setId:$chunkX:$chunkZ";
		if(isset($this->noStarts[$key])){
			return null;
		}
		if(isset($this->starts[$key])){
			$start = $this->starts[$key];
			unset($this->starts[$key]);
			$this->starts[$key] = $start;
			return $start;
		}
		$start = $this->computeStart($setId, $chunkX, $chunkZ);
		if($start === null){
			if(count($this->noStarts) >= self::NO_START_CACHE_SIZE){
				$this->noStarts = [];
			}
			$this->noStarts[$key] = true;
			return null;
		}
		$this->starts[$key] = $start;
		$this->cachedPieces += count($start->pieces);
		while(count($this->starts) > 1 && (count($this->starts) > self::START_CACHE_SIZE || $this->cachedPieces > self::START_CACHE_PIECES)){
			$oldest = array_key_first($this->starts);
			$this->cachedPieces -= count($this->starts[$oldest]->pieces);
			unset($this->starts[$oldest]);
		}
		return $start;
	}

	private function computeStart(string $setId, int $chunkX, int $chunkZ) : ?StructureStart{
		$entries = $this->setStructures[$setId] ?? [];
		if(count($entries) === 0 || !$this->isStructureChunk($setId, $chunkX, $chunkZ)){
			return null;
		}
		++$this->startsComputed;
		if(count($entries) === 1){
			return $this->tryGenerate($entries[0][0], $chunkX, $chunkZ);
		}
		$random = StructureRandom::largeFeature($this->env->seed, $chunkX, $chunkZ);
		$total = 0;
		foreach($entries as [, $weight]){
			$total += $weight;
		}
		while(count($entries) > 0){
			$roll = $random->nextBoundedInt($total);
			$index = 0;
			foreach($entries as [, $weight]){
				$roll -= $weight;
				if($roll < 0){
					break;
				}
				++$index;
			}
			[$structure, $weight] = $entries[$index];
			$start = $this->tryGenerate($structure, $chunkX, $chunkZ);
			if($start !== null){
				return $start;
			}
			unset($entries[$index]);
			$entries = [...$entries];
			$total -= $weight;
		}
		return null;
	}

	/**
	 * Structure.generate(): finds the generation point, checks its biome and builds the pieces.
	 */
	public function tryGenerate(StructureDefinition $structure, int $chunkX, int $chunkZ) : ?StructureStart{
		$context = new StructureGenContext($this->env, $chunkX, $chunkZ);
		$stub = $structure->type->findGenerationPoint($context);
		if($stub === null){
			return null;
		}
		[$x, $y, $z, $builder] = $stub;
		if(!$structure->isValidBiome($this->env->getBiome($x, $y, $z))){
			return null;
		}
		$start = new StructureStart($structure, $chunkX, $chunkZ, $builder());
		return $start->isValid() ? $start : null;
	}

	/**
	 * The starts whose bounding box touches the chunk (vanilla's structure references of the chunk).
	 * @phpstan-return list<StructureStart>
	 */
	public function getStartsAffecting(int $chunkX, int $chunkZ, bool $adaptingOnly = false) : array{
		$result = [];
		$seed = $this->env->seed;
		$minX = $chunkX << 4;
		$minZ = $chunkZ << 4;
		foreach(Utils::stringifyKeys($this->placements) as $setId => $placement){
			if($adaptingOnly && !isset($this->adaptingSets[$setId])){
				continue;
			}
			$reach = $this->setReach[$setId];
			foreach($placement->placementChunksIn($seed, $chunkX - $reach, $chunkZ - $reach, $chunkX + $reach, $chunkZ + $reach) as [$x, $z]){
				$start = $this->getStart($setId, $x, $z);
				if($start !== null && $start->getBoundingBox()->intersectsXZ($minX, $minZ, $minX + 15, $minZ + 15)){
					$result[] = $start;
				}
			}
		}
		return $result;
	}

	/**
	 * The terrain adaptation of the structures referenced by a chunk (Beardifier.forStructuresInChunk()), for the
	 * terrain filler, or null if there is none. Starts are computed if needed (from the terrain without adaptation, like
	 * vanilla's STRUCTURE_STARTS before NOISE).
	 */
	public function getBeardifier(int $chunkX, int $chunkZ) : ?Beardifier{
		if(count($this->adaptingSets) === 0){
			return null;
		}
		$starts = $this->getStartsAffecting($chunkX, $chunkZ, true);
		return count($starts) > 0 ? Beardifier::forStructuresInChunk($starts, $chunkX, $chunkZ) : null;
	}

	/**
	 * Whether placeStep() would place anything (and so use the random) for these starts in a step.
	 *
	 * @phpstan-param list<StructureStart> $starts
	 */
	public function placesInStep(array $starts, int $step) : bool{
		if(count($starts) === 0 || !isset($this->stepStructures[$step])){
			return false;
		}
		foreach($this->stepStructures[$step] as [$_, $structure]){
			foreach($starts as $start){
				if($start->structure->id === $structure->id){
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Places the structures of a decoration step in a chunk (before the step's features), like
	 * ChunkGenerator.applyBiomeDecoration(): each structure gets the random setFeatureSeed(decorationSeed, index, step).
	 *
	 * @phpstan-param list<StructureStart> $starts the starts affecting the chunk (getStartsAffecting())
	 */
	public function placeStep(StructurePlaceContext $c, array $starts, int $step, int $chunkX, int $chunkZ, int $decorationSeed, FeatureRandom $random) : void{
		if(count($starts) === 0 || !isset($this->stepStructures[$step])){
			return;
		}
		$byStructure = [];
		foreach($starts as $start){
			$byStructure[$start->structure->id][] = $start;
		}
		$chunkBox = BoundingBox::chunk($chunkX, $chunkZ, $this->env->minY + 1, $this->env->getMaxY());
		foreach($this->stepStructures[$step] as [$index, $structure]){
			$list = $byStructure[$structure->id] ?? null;
			if($list === null){
				continue;
			}
			$random->setFeatureSeed($decorationSeed, $index, $step);
			usort($list, fn(StructureStart $a, StructureStart $b) : int => [$a->chunkX, $a->chunkZ] <=> [$b->chunkX, $b->chunkZ]);
			foreach($list as $start){
				$start->placeInChunk($c, $chunkBox, $chunkX, $chunkZ);
			}
		}
	}

	/**
	 * Finds a start of a structure (an id, a pattern like "village_*", or a structure set id) around a block
	 * position like Java Edition's /locate (ChunkGenerator.getNearestGeneratedStructure()): placement chunks are
	 * searched ring by ring of spacing-sized regions, up to $radius rings, and the first start found in a ring is the
	 * candidate of its set; the nearest candidate of all sets wins. Returns
	 * [structure id, start chunk x, start chunk z, locate x, locate y, locate z] or null.
	 *
	 * With $maxChunks, the number of rings of each set is derived from that distance in chunks instead (rounded up to
	 * whole regions; ring-placed starts farther than that are skipped). $accept, if given, filters starts by their
	 * start chunk (e.g. only chunks that are not generated yet). $deadlineNs (an hrtime() value) bounds the time spent:
	 * past it a {@link LocateTimeoutException} is thrown.
	 *
	 * @phpstan-param (\Closure(int, int) : bool)|null $accept
	 * @phpstan-return array{string, int, int, int, int, int}|null
	 * @throws LocateTimeoutException
	 */
	public function locate(string $structure, int $x, int $z, int $radius = 100, ?int $maxChunks = null, ?\Closure $accept = null, ?int $deadlineNs = null) : ?array{
		$structure = str_contains($structure, ":") ? $structure : "minecraft:" . $structure;
		$matches = fn(string $id) : bool => $id === $structure || fnmatch($structure, $id);
		$chunkX = $x >> 4;
		$chunkZ = $z >> 4;
		$best = null;
		$bestDistance = PHP_INT_MAX;
		foreach(Utils::stringifyKeys($this->setStructures) as $setId => $entries){
			$wanted = $setId === $structure;
			foreach($entries as [$definition]){
				$wanted = $wanted || $matches($definition->id);
			}
			if(!$wanted){
				continue;
			}
			$placement = $this->placements[$setId];
			if($placement instanceof ConcentricRingsPlacement){
				//ChunkGenerator.getNearestGeneratedStructure() for rings: the nearest valid ring position (distance
				//to the chunk centre). Positions are visited by the distance of their position before the biome search
				//(a final position is at most MAX_SHIFT_CHUNKS away from it), so the biome searches and starts of
				//positions that cannot be nearer than the best one found are skipped.
				$order = [];
				foreach($placement->getInitialRingPositions() as $index => [$ix, $iz]){
					$order[] = [$index, sqrt(((($ix << 4) + 8 - $x) ** 2) + ((($iz << 4) + 8 - $z) ** 2))];
				}
				usort($order, fn(array $a, array $b) : int => $a[1] <=> $b[1]); //stable: ring order among equal distances
				foreach($order as [$index, $initialDistance]){
					$lowerBound = max(0.0, $initialDistance - ConcentricRingsPlacement::MAX_SHIFT_CHUNKS * 16 * M_SQRT2);
					if($lowerBound ** 2 > $bestDistance || ($maxChunks !== null && $lowerBound > $maxChunks << 4)){
						break;
					}
					LocateTimeoutException::checkDeadline($deadlineNs);
					[$cx, $cz] = $placement->resolveRingPosition($index);
					$distance = (($cx << 4) + 8 - $x) ** 2 + (($cz << 4) + 8 - $z) ** 2;
					if($distance >= $bestDistance || ($maxChunks !== null && $distance > ($maxChunks << 4) ** 2) || ($accept !== null && !$accept($cx, $cz))){
						continue;
					}
					$start = $this->getStart($setId, $cx, $cz);
					if($start !== null && ($setId === $structure || $matches($start->structure->id))){
						[$lx, $ly, $lz] = $placement->getLocatePos($cx, $cz);
						$best = [$start->structure->id, $cx, $cz, $lx, $ly, $lz];
						$bestDistance = $distance;
					}
				}
				continue;
			}
			$spacing = $placement->getSpacing();
			$rings = $maxChunks !== null ? intdiv(max(0, $maxChunks) + $spacing - 1, $spacing) : $radius;
			$found = null;
			for($ring = 0; $ring <= $rings && $found === null; ++$ring){
				for($k = -$ring; $k <= $ring && $found === null; ++$k){
					$edgeK = $k === -$ring || $k === $ring;
					for($l = -$ring; $l <= $ring; ++$l){
						if(!$edgeK && $l !== -$ring && $l !== $ring){
							continue;
						}
						//the placement chunk of the region holding (chunkX + spacing * k, chunkZ + spacing * l)
						$regionX = RandomSpreadPlacement::floorDiv($chunkX + $spacing * $k, $spacing) * $spacing;
						$regionZ = RandomSpreadPlacement::floorDiv($chunkZ + $spacing * $l, $spacing) * $spacing;
						$candidates = $placement->placementChunksIn($this->env->seed, $regionX, $regionZ, $regionX + $spacing - 1, $regionZ + $spacing - 1);
						foreach($candidates as [$cx, $cz]){
							if($accept !== null && !$accept($cx, $cz)){
								continue;
							}
							LocateTimeoutException::checkDeadline($deadlineNs);
							$start = $this->getStart($setId, $cx, $cz);
							if($start !== null && ($setId === $structure || $matches($start->structure->id))){
								$found = [$start, $cx, $cz];
								break 2;
							}
						}
					}
				}
			}
			if($found !== null){
				[$start, $cx, $cz] = $found;
				[$lx, $ly, $lz] = $placement->getLocatePos($cx, $cz);
				$distance = ($lx - $x) ** 2 + ($lz - $z) ** 2;
				if($distance < $bestDistance){
					$best = [$start->structure->id, $cx, $cz, $lx, $ly, $lz];
					$bestDistance = $distance;
				}
			}
		}
		return $best;
	}
}
