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

namespace pocketmine\world\raid;

use pocketmine\block\Block;
use pocketmine\math\Vector3;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function floor;

/**
 * The village points of interest of a world (beds, bells, job sites) and which villager claimed each one.
 *
 * POIs are found per chunk with {@link VillageScanner::findPoiBlocks()} and cached: a chunk is scanned again when a
 * POI block is placed or removed in it (see {@link PoiManager::onBlockChanged()}, called by the world for every
 * block change, a single array lookup) or after {@link PoiManager::CACHE_TICKS}. Scans of loaded chunks without POIs
 * cost one palette check per sub-chunk.
 *
 * Claims (Bedrock "minecraft:dweller"): a bed or job site belongs to at most one villager, identified by its runtime
 * entity ID. The villager keeps the claimed positions in its NBT and registers them again when it is loaded; claims
 * are released when it dies or is unloaded.
 */
final class PoiManager{
	/** Ticks after which the POIs of a chunk are scanned again even if no POI block changed. */
	public const CACHE_TICKS = 6000;
	/** Ticks between two clean-ups of the expired cache entries. */
	private const CLEANUP_INTERVAL = 1200;

	/**
	 * @var array[] chunk hash => [expiry tick, POIs]
	 * @phpstan-var array<int, array{int, list<array{PoiType, Vector3, int}>}>
	 */
	private array $chunks = [];
	/**
	 * @var int[] block hash => runtime ID of the villager that claimed it
	 * @phpstan-var array<int, int>
	 */
	private array $claims = [];
	private int $scans = 0;
	/**
	 * @var int[] area hash => tick before which its iron golem conditions aren't checked again
	 * @phpstan-var array<int, int>
	 */
	private array $golemChecks = [];
	/**
	 * @var int[] block hash of a village centre => tick before which its iron golem conditions aren't checked again
	 * @phpstan-var array<int, int>
	 */
	private array $villageGolemChecks = [];

	public function __construct(
		private World $world
	){}

	/**
	 * Called by the world when a block changes: the POIs of the chunk are scanned again if a POI block appeared or
	 * disappeared.
	 */
	public function onBlockChanged(int $chunkHash, int $oldStateId, int $newStateId) : void{
		if(!isset($this->chunks[$chunkHash])){
			return;
		}
		$oldType = $oldStateId >> Block::INTERNAL_STATE_DATA_BITS;
		$newType = $newStateId >> Block::INTERNAL_STATE_DATA_BITS;
		if($oldType === $newType){
			return; //same block, other state (an occupied bed, a filling composter): the POIs don't change
		}
		$types = VillageScanner::getPoiTypes();
		if(isset($types[$oldType]) || isset($types[$newType])){
			unset($this->chunks[$chunkHash]);
		}
	}

	/**
	 * Forgets the cached POIs of a chunk.
	 */
	public function invalidateChunk(int $chunkX, int $chunkZ) : void{
		unset($this->chunks[World::chunkHash($chunkX, $chunkZ)]);
	}

	/**
	 * Number of chunk scans done so far (for tests and benchmarks).
	 */
	public function getScanCount() : int{
		return $this->scans;
	}

	/**
	 * Returns the POIs of the loaded chunks inside the box (beds at their head), with their block type IDs.
	 *
	 * @phpstan-return list<array{PoiType, Vector3, int}>
	 */
	public function getPois(int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ) : array{
		$now = $this->world->getServer()->getTick();
		$result = [];
		for($cx = $minX >> Chunk::COORD_BIT_SIZE; $cx <= $maxX >> Chunk::COORD_BIT_SIZE; ++$cx){
			for($cz = $minZ >> Chunk::COORD_BIT_SIZE; $cz <= $maxZ >> Chunk::COORD_BIT_SIZE; ++$cz){
				$hash = World::chunkHash($cx, $cz);
				$entry = $this->chunks[$hash] ?? null;
				if($entry === null || $entry[0] < $now){
					if(!$this->world->isChunkLoaded($cx, $cz)){
						unset($this->chunks[$hash]);
						continue;
					}
					++$this->scans;
					$baseX = $cx << Chunk::COORD_BIT_SIZE;
					$baseZ = $cz << Chunk::COORD_BIT_SIZE;
					$entry = [$now + self::CACHE_TICKS, VillageScanner::findPoiBlocks($this->world, $baseX, $this->world->getMinY(), $baseZ, $baseX + Chunk::EDGE_LENGTH - 1, $this->world->getMaxY() - 1, $baseZ + Chunk::EDGE_LENGTH - 1)];
					$this->chunks[$hash] = $entry;
				}
				foreach($entry[1] as $poi){
					$pos = $poi[1];
					if($pos->x >= $minX && $pos->x <= $maxX && $pos->y >= $minY && $pos->y <= $maxY && $pos->z >= $minZ && $pos->z <= $maxZ){
						$result[] = $poi;
					}
				}
			}
		}
		return $result;
	}

	/**
	 * Returns the unclaimed POI of the type nearest to the position within the given distances, if any.
	 *
	 * @param \Closure|null $filter takes the block type ID, returns whether the POI is acceptable
	 * @phpstan-param (\Closure(int) : bool)|null $filter
	 * @param \Closure|null $positionFilter takes the POI position, returns whether the POI is acceptable
	 * @phpstan-param (\Closure(Vector3) : bool)|null $positionFilter
	 */
	public function findNearestFree(PoiType $type, Vector3 $pos, int $horizontal, int $vertical, ?\Closure $filter = null, ?\Closure $positionFilter = null) : ?Vector3{
		$x = (int) floor($pos->x);
		$y = (int) floor($pos->y);
		$z = (int) floor($pos->z);
		$best = null;
		$bestDist = 0.0;
		foreach($this->getPois($x - $horizontal, $y - $vertical, $z - $horizontal, $x + $horizontal, $y + $vertical, $z + $horizontal) as [$poiType, $poiPos, $typeId]){
			if($poiType !== $type || $this->isClaimed($poiPos) || ($filter !== null && !$filter($typeId)) || ($positionFilter !== null && !$positionFilter($poiPos))){
				continue;
			}
			$d = $poiPos->distanceSquared($pos);
			if($best === null || $d < $bestDist){
				$best = $poiPos;
				$bestDist = $d;
			}
		}
		return $best;
	}

	/**
	 * Counts the beds (claimed and free) in the box.
	 *
	 * @return int[] [beds, free beds]
	 * @phpstan-return array{int, int}
	 */
	public function countBeds(int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ) : array{
		$beds = 0;
		$free = 0;
		foreach($this->getPois($minX, $minY, $minZ, $maxX, $maxY, $maxZ) as [$type, $pos]){
			if($type === PoiType::BED){
				++$beds;
				if(!$this->isClaimed($pos)){
					++$free;
				}
			}
		}
		return [$beds, $free];
	}

	private static function hash(Vector3 $pos) : int{
		return World::blockHash((int) floor($pos->x), (int) floor($pos->y), (int) floor($pos->z));
	}

	/**
	 * Claims the POI for the entity. Returns false if another entity claimed it already.
	 */
	public function claim(Vector3 $pos, int $entityId) : bool{
		$hash = self::hash($pos);
		$owner = $this->claims[$hash] ?? null;
		if($owner !== null && $owner !== $entityId){
			if($this->world->getEntity($owner) !== null){
				return false;
			}
			//stale claim of an entity which is gone
		}
		$this->claims[$hash] = $entityId;
		return true;
	}

	/**
	 * Releases the POI if the entity claimed it.
	 */
	public function release(Vector3 $pos, int $entityId) : void{
		$hash = self::hash($pos);
		if(($this->claims[$hash] ?? null) === $entityId){
			unset($this->claims[$hash]);
		}
	}

	/**
	 * Runtime ID of the entity that claimed the POI, or null.
	 */
	public function getClaimant(Vector3 $pos) : ?int{
		return $this->claims[self::hash($pos)] ?? null;
	}

	public function isClaimed(Vector3 $pos) : bool{
		return isset($this->claims[self::hash($pos)]);
	}

	/**
	 * @return int[] block hash => entity runtime ID
	 * @phpstan-return array<int, int>
	 */
	public function getClaims() : array{
		return $this->claims;
	}

	/**
	 * Whether the iron golem summoning conditions of the 32x32 area around the position may be checked now (at most
	 * once every $interval ticks per area, so that crowds of villagers don't count each other over and over).
	 */
	public function tryGolemCheck(int $x, int $z, int $currentTick, int $interval) : bool{
		$key = World::chunkHash($x >> 5, $z >> 5);
		if(($this->golemChecks[$key] ?? -1) > $currentTick){
			return false;
		}
		$this->golemChecks[$key] = $currentTick + $interval;
		return true;
	}

	/**
	 * Whether the iron golem summoning conditions of the village with the given centre may be checked now (at most
	 * once every $interval ticks per village, Bedrock: every 700 ticks).
	 *
	 * @internal
	 */
	public function tryVillageGolemCheck(Vector3 $center, int $currentTick, int $interval) : bool{
		$key = self::hash($center);
		if(($this->villageGolemChecks[$key] ?? -1) > $currentTick){
			return false;
		}
		$this->villageGolemChecks[$key] = $currentTick + $interval;
		return true;
	}

	public function tick(int $currentTick) : void{
		if($currentTick % self::CLEANUP_INTERVAL !== 0){
			return;
		}
		foreach($this->golemChecks as $key => $until){
			if($until <= $currentTick){
				unset($this->golemChecks[$key]);
			}
		}
		foreach($this->villageGolemChecks as $key => $until){
			if($until <= $currentTick){
				unset($this->villageGolemChecks[$key]);
			}
		}
		foreach($this->chunks as $hash => [$expiry]){
			if($expiry < $currentTick){
				unset($this->chunks[$hash]);
			}
		}
	}
}
