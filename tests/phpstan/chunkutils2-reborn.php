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

namespace pocketmine\world\format;

use pocketmine\math\AxisAlignedBB;
use pocketmine\world\World;

//Classes added by the chunkutils2 extension of PocketMine-MP Reborn (0.3.6+). PocketMine uses them only when they exist
//(class_exists()), so they are declared here for static analysis with PHP builds that don't have them. Scanned by
//PHPStan only (phpstan.neon.dist scanFiles), never loaded.

/**
 * chunkutils2 >= 0.3.6: native random tick picks, see World::tickChunk().
 */
final class RandomTickSampler{
	public function setTicked(int $stateId, bool $ticked) : void{}

	public function clear() : void{}

	/** chunkutils2 >= 0.3.14 */
	public function setLavaState(int $stateId, bool $lava) : void{}

	public function hasTickedState(PalettedBlockArray $layer) : bool{ return false; }

	/**
	 * @param PalettedBlockArray[] $layers
	 * @phpstan-param array<int, PalettedBlockArray> $layers
	 *
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	public function sample(array $layers, int $count) : array{ return []; }

	/**
	 * chunkutils2 >= 0.3.9: same picks as sample() over a Chunk's subchunk storage (see Chunk::getSubChunkStorage()),
	 * result keys are index + $yOffset.
	 *
	 * @phpstan-param \SplFixedArray<SubChunk> $subChunks
	 *
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	public function sampleSubChunks(\SplFixedArray $subChunks, int $count, int $yOffset) : array{ return []; }

	public const TICK_DONE = 0;
	public const TICK_ENTITIES = 1;
	public const TICK_WEATHER = 2;
	public const TICK_PICKS = 3;

	/**
	 * chunkutils2 >= 0.3.14: the loop of World::tickChunks() over the ticking chunks, natively up to the next chunk which
	 * needs PHP (see World::tickChunksNative()).
	 *
	 * @phpstan-param array<int, int>            $order
	 * @phpstan-param array<int, Chunk>          $chunks
	 * @phpstan-param array<int, array<int, \pocketmine\entity\Entity>> $entitiesByChunk
	 * @phpstan-param \WeakMap<SubChunk, array{int, bool}>|null $lavaIgnition
	 * @phpstan-param list<int>|null             $picks
	 * @phpstan-param-out list<int>|null         $picks
	 *
	 * @return int one of the TICK_* constants
	 */
	public function tickChunks(array $order, array $chunks, array $entitiesByChunk, int $count, int $yOffset, int $weatherChance, ?\WeakMap $lavaIgnition, int $worldAge, int $resumeStage, int &$position, int &$chunkHash, ?array &$picks) : int{ return self::TICK_DONE; }
}

/**
 * chunkutils2 >= 0.3.7: native A* of PathFinder.
 */
final class NativePathFinder{
	public function setStateFlags(int $stateId, int $flags) : void{}

	/** chunkutils2 >= 0.3.11 */
	public function setStateDanger(int $stateId, int $flags) : void{}

	/**
	 * @phpstan-param \Closure(int, int) : (array<int, SubChunk>|null) $columnProvider
	 *
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	public function findPath(\Closure $columnProvider, int $minY, int $maxY, int $headBlocks, int $maxDrop, bool $canSwim, bool $avoidWater, int $maxNodes, int $sx, int $sy, int $sz, int $tx, int $ty, int $tz, float $acceptDistance, int $footprint = 1, int $dangerMask = 8, int $floorMask = 2, int $liquidMask = 0, float $liquidCost = 0.0, int $nearBlockMask = 0, int $nearCostMask = 0, float $nearCost = 0.0, float $heuristicWeight = 1.0) : array{ return []; }
}

/**
 * chunkutils2 >= 0.3.12: entity physics in C++ with the results of the PHP code, see World::clipEntityMovement().
 */
final class NativeEntityPhysics{
	/**
	 * @param AxisAlignedBB[][][] $boxCache
	 * @phpstan-param array<int, array<int, list<AxisAlignedBB>>> $boxCache
	 *
	 * @return int[]|float[]|bool[]
	 * @phpstan-return list<int|float|bool>
	 */
	public function clipMovement(array $boxCache, AxisAlignedBB $boundingBox, float $dx, float $dy, float $dz, bool $onGround, float $stepHeight, ?World $world = null) : array{ return []; }

	/** -1 below the world, -2 above it, -3 in an unloaded chunk, -4 when PHP must look it up */
	public function stateAt(World $world, int $x, int $y, int $z) : int{ return 0; }

	/**
	 * @param int[] $flagsByStateId
	 * @phpstan-param array<int, int> $flagsByStateId
	 */
	public function blockFlags(World $world, array $flagsByStateId, int $x, int $y, int $z) : ?int{ throw new \LogicException("stub"); }

	/**
	 * @param int[] $flagsByStateId
	 * @phpstan-param array<int, int> $flagsByStateId
	 *
	 * @return int[]|null
	 * @phpstan-return list<int>|null
	 */
	public function entityInsideBlocks(World $world, array $flagsByStateId, int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ) : ?array{ throw new \LogicException("stub"); }
}
