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

namespace pocketmine\entity\ai\reference;

use pocketmine\entity\ai\navigation\Path;
use pocketmine\entity\ai\navigation\PathBlockTypes;

use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function abs;
use function array_key_exists;
use function array_reverse;
use function max;
use function min;
use function sqrt;
use const M_SQRT2;

/**
 * The A* path finder written as plainly as possible, without the caches of fits/standable, the shortcuts for 1-wide
 * footprints and the palette check of PathFinder: the optimized PHP search and the native one must find exactly the
 * same paths (and expand the same nodes).
 *
 * A node is the block a mob's feet are in (the corner with the lowest X and Z of its footprint for wide mobs). A node
 * is standable when every column of the footprint fits (feet and head blocks passable, nothing of the danger mask),
 * one of them has something to stand on (a floor below, a low block, water when it swims, a liquid of the liquid
 * mask) and none stands on something of the danger mask, and no block around the footprint at the feet level has
 * flags of the "near block" mask. From a node the mob can walk to the 8 neighbours on the same level, step/jump up one
 * block, or drop down up to maxDrop blocks. Diagonal moves can't cut corners. A node costs the water cost (2, 8 when
 * avoiding water) or liquid cost when a column is in it, otherwise the "near" cost when a block around the footprint
 * at the feet level or the level below has flags of the "near cost" mask.
 */
final class ReferencePathFinder{
	public const DEFAULT_MAX_NODES = 400;

	private const COST_JUMP = 0.5;
	private const COST_DROP_PER_BLOCK = 0.25;
	private const COST_WATER = 2.0;
	private const COST_WATER_AVOID = 8.0;

	private const OFFSET = 512;

	/** Neighbour offsets: 4 cardinal first, then 4 diagonal. */
	private const DIRECTIONS = [[1, 0], [-1, 0], [0, 1], [0, -1], [1, 1], [1, -1], [-1, 1], [-1, -1]];

	/**
	 * Per-search cache of block flags, by local key.
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $types = [];

	/**
	 * @var (Chunk|null)[]
	 * @phpstan-var array<int, Chunk|null>
	 */
	private array $chunks = [];

	private int $originX = 0;
	private int $originY = 0;
	private int $originZ = 0;

	/** Number of nodes expanded by the last search (for profiling and tests). */
	private int $lastVisited = 0;

	private int $footprint = 1;
	private int $dangerMask = PathBlockTypes::DANGER;
	private int $floorMask = PathBlockTypes::FLOOR;
	private int $liquidMask = 0;
	private float $liquidCost = 0.0;
	private int $nearBlockMask = 0;
	private int $nearCostMask = 0;
	private float $nearCost = 0.0;
	private float $heuristicWeight = 1.0;

	/**
	 * @param int $headBlocks number of blocks above the feet block the mob needs free (ceil(height) - 1)
	 */
	public function __construct(
		private World $world,
		private int $headBlocks = 1,
		private int $maxDrop = 3,
		private bool $canSwim = true,
		private bool $avoidWater = false,
		private int $maxNodes = self::DEFAULT_MAX_NODES
	){}

	public function setWorld(World $world) : void{
		$this->world = $world;
	}

	public function setMaxNodes(int $maxNodes) : void{
		$this->maxNodes = $maxNodes;
	}

	public function setFootprint(int $footprint) : void{
		$this->footprint = $footprint;
	}

	public function setDangerMask(int $dangerMask) : void{
		$this->dangerMask = $dangerMask;
	}

	public function setFloorMask(int $floorMask) : void{
		$this->floorMask = $floorMask;
	}

	public function setLiquid(int $liquidMask, float $liquidCost) : void{
		$this->liquidMask = $liquidMask;
		$this->liquidCost = $liquidCost;
	}

	public function setDangerMargin(int $blockMask, int $costMask, float $cost) : void{
		$this->nearBlockMask = $blockMask;
		$this->nearCostMask = $costMask;
		$this->nearCost = $cost;
	}

	public function setHeuristicWeight(float $weight) : void{
		$this->heuristicWeight = $weight;
	}

	public function getLastVisitedCount() : int{
		return $this->lastVisited;
	}

	private function key(int $x, int $y, int $z) : int{
		return (($x - $this->originX + self::OFFSET) << 20) | (($y - $this->originY + self::OFFSET) << 10) | ($z - $this->originZ + self::OFFSET);
	}

	private function type(int $x, int $y, int $z) : int{
		$key = $this->key($x, $y, $z);
		if(isset($this->types[$key])){
			return $this->types[$key];
		}
		$chunkHash = World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		if(!array_key_exists($chunkHash, $this->chunks)){
			$this->chunks[$chunkHash] = $this->world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		}
		return $this->types[$key] = PathBlockTypes::inChunk($this->world, $this->chunks[$chunkHash], $x, $y, $z);
	}

	private function columnFits(int $x, int $y, int $z) : bool{
		$feet = $this->type($x, $y, $z);
		if(($feet & (PathBlockTypes::PASSABLE | PathBlockTypes::LOW)) === 0 || ($feet & $this->dangerMask) !== 0){
			return false;
		}
		if(($feet & PathBlockTypes::WATER) !== 0 && !$this->canSwim){
			return false;
		}
		for($i = 1; $i <= $this->headBlocks; ++$i){
			$head = $this->type($x, $y + $i, $z);
			if(($head & PathBlockTypes::PASSABLE) === 0 || ($head & $this->dangerMask) !== 0){
				return false;
			}
		}
		return true;
	}

	/**
	 * Returns whether the mob's body fits with its feet in the given block (ignoring the floor).
	 */
	private function fits(int $x, int $y, int $z) : bool{
		for($i = 0; $i < $this->footprint; ++$i){
			for($j = 0; $j < $this->footprint; ++$j){
				if(!$this->columnFits($x + $i, $y, $z + $j)){
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Returns whether the mob can stand with its feet in the given block: fits() and something to stand on.
	 */
	public function isStandable(int $x, int $y, int $z) : bool{
		if(!$this->fits($x, $y, $z)){
			return false;
		}
		$supported = false;
		for($i = 0; $i < $this->footprint; ++$i){
			for($j = 0; $j < $this->footprint; ++$j){
				if(($this->type($x + $i, $y, $z + $j) & (PathBlockTypes::LOW | PathBlockTypes::WATER)) !== 0){
					$supported = true;
					continue;
				}
				$below = $this->type($x + $i, $y - 1, $z + $j);
				if(($below & $this->dangerMask & ~$this->floorMask) !== 0){
					return false;
				}
				if(($below & $this->floorMask) !== 0){
					$supported = true;
				}
			}
		}
		if(!$supported){
			return false;
		}
		foreach($this->around($x, $z) as [$i, $j]){
			if(($this->type($i, $y, $j) & $this->nearBlockMask) !== 0){
				return false;
			}
		}
		return true;
	}

	/**
	 * @return int[][] columns around the footprint
	 * @phpstan-return list<array{int, int}>
	 */
	private function around(int $x, int $z) : array{
		$columns = [];
		for($i = $x - 1; $i <= $x + $this->footprint; ++$i){
			for($j = $z - 1; $j <= $z + $this->footprint; ++$j){
				if($i < $x || $i >= $x + $this->footprint || $j < $z || $j >= $z + $this->footprint){
					$columns[] = [$i, $j];
				}
			}
		}
		return $columns;
	}

	private function nodeCost(int $x, int $y, int $z) : float{
		$cost = 0.0;
		$inLiquid = false;
		for($i = 0; $i < $this->footprint; ++$i){
			for($j = 0; $j < $this->footprint; ++$j){
				$feet = $this->type($x + $i, $y, $z + $j);
				if(($feet & PathBlockTypes::WATER) !== 0){
					$cost = max($cost, $this->avoidWater ? self::COST_WATER_AVOID : self::COST_WATER);
					$inLiquid = true;
				}elseif(($feet & $this->liquidMask) !== 0){
					$cost = max($cost, $this->liquidCost);
					$inLiquid = true;
				}
			}
		}
		if(!$inLiquid && $this->nearCostMask !== 0){
			foreach($this->around($x, $z) as [$i, $j]){
				if((($this->type($i, $y, $j) | $this->type($i, $y - 1, $j)) & $this->nearCostMask) !== 0){
					return $this->nearCost;
				}
			}
		}
		return $cost;
	}

	/**
	 * Finds a path from the block (sx, sy, sz) to the block (tx, ty, tz).
	 *
	 * @param float $acceptDistance the search succeeds on reaching a node at most this far (horizontally, and within 1
	 *                              block vertically) from the target
	 */
	public function findPath(int $sx, int $sy, int $sz, int $tx, int $ty, int $tz, float $acceptDistance = 0.0) : ?Path{
		$this->types = [];
		$this->chunks = [];
		$this->originX = $sx;
		$this->originY = $sy;
		$this->originZ = $sz;
		$this->lastVisited = 0;

		if(abs($tx - $sx) >= self::OFFSET - 2 || abs($ty - $sy) >= self::OFFSET - 2 || abs($tz - $sz) >= self::OFFSET - 2){
			return null;
		}

		//mobs standing on the edge of a block or mid-jump: start from the block below if this one has no floor; a wide mob
		//may start from a footprint next to the one centred closest to it
		if(!$this->isStandable($sx, $sy, $sz)){
			if($this->isStandable($sx, $sy - 1, $sz)){
				$sy--;
			}elseif($this->footprint > 1){
				foreach([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dz]){
					if($this->isStandable($sx + $dx, $sy, $sz + $dz)){
						$sx += $dx;
						$sz += $dz;
						break;
					}
				}
			}
		}

		$startKey = $this->key($sx, $sy, $sz);
		/** @phpstan-var array<int, float> $g */
		$g = [$startKey => 0.0];
		/** @phpstan-var array<int, int> $parent */
		$parent = [];
		/** @phpstan-var array<int, array{int, int, int}> $coords */
		$coords = [$startKey => [$sx, $sy, $sz]];
		/** @phpstan-var array<int, true> $closed */
		$closed = [];
		$open = new \SplPriorityQueue();
		$open->setExtractFlags(\SplPriorityQueue::EXTR_DATA);

		$bestKey = $startKey;
		$bestH = $this->heuristic($sx, $sy, $sz, $tx, $ty, $tz);
		$open->insert($startKey, -$bestH);
		$acceptSq = $acceptDistance * $acceptDistance;
		$found = false;

		while(!$open->isEmpty()){
			/** @var int $key */
			$key = $open->extract();
			if(isset($closed[$key])){
				continue;
			}
			$closed[$key] = true;
			[$x, $y, $z] = $coords[$key];

			$dxT = $tx - $x;
			$dzT = $tz - $z;
			$dyT = $ty - $y;
			if(($dxT === 0 && $dzT === 0 && $dyT === 0) || ($acceptSq > 0 && $dxT * $dxT + $dzT * $dzT <= $acceptSq && abs($dyT) <= 1)){
				$bestKey = $key;
				$found = true;
				break;
			}
			$h = $this->heuristic($x, $y, $z, $tx, $ty, $tz);
			if($h < $bestH){
				$bestH = $h;
				$bestKey = $key;
			}

			if(++$this->lastVisited >= $this->maxNodes){
				break;
			}

			$baseG = $g[$key];
			foreach(self::DIRECTIONS as $i => [$dx, $dz]){
				$nx = $x + $dx;
				$nz = $z + $dz;
				$ny = $this->findNeighbourY($x, $y, $z, $nx, $nz);
				if($ny === null){
					continue;
				}
				if($i >= 4){
					//diagonal: both side cells must be walkable at both levels, so the mob doesn't clip corners
					$lo = min($y, $ny);
					$hi = max($y, $ny);
					if(!$this->fits($x + $dx, $lo, $z) || !$this->fits($x, $lo, $z + $dz) ||
						($hi !== $lo && (!$this->fits($x + $dx, $hi, $z) || !$this->fits($x, $hi, $z + $dz)))
					){
						continue;
					}
				}
				$nKey = $this->key($nx, $ny, $nz);
				if(isset($closed[$nKey])){
					continue;
				}
				$step = $i >= 4 ? M_SQRT2 : 1.0;
				if($ny > $y){
					$step += self::COST_JUMP;
				}elseif($ny < $y){
					$step += ($y - $ny) * self::COST_DROP_PER_BLOCK;
				}
				$newG = $baseG + $step + $this->nodeCost($nx, $ny, $nz);
				if(!isset($g[$nKey]) || $newG < $g[$nKey]){
					$g[$nKey] = $newG;
					$parent[$nKey] = $key;
					$coords[$nKey] = [$nx, $ny, $nz];
					$open->insert($nKey, -($newG + $this->heuristic($nx, $ny, $nz, $tx, $ty, $tz) * $this->heuristicWeight));
				}
			}
		}

		if($bestKey === $startKey && !$found){
			return null;
		}

		$nodes = [];
		for($k = $bestKey; ; $k = $parent[$k]){
			$nodes[] = $coords[$k];
			if(!isset($parent[$k])){
				break;
			}
		}
		$nodes = array_reverse($nodes);
		return new Path($nodes, $found, $tx, $ty, $tz);
	}

	/**
	 * Returns the Y of the node the mob reaches walking from (x, y, z) to the column (nx, nz), or null if it can't go
	 * there.
	 */
	private function findNeighbourY(int $x, int $y, int $z, int $nx, int $nz) : ?int{
		if($this->isStandable($nx, $y, $nz)){
			return $y;
		}
		$obstacle = false;
		for($i = 0; $i < $this->footprint; ++$i){
			for($j = 0; $j < $this->footprint; ++$j){
				$feet = $this->type($nx + $i, $y, $nz + $j);
				if(($feet & (PathBlockTypes::PASSABLE | PathBlockTypes::LOW)) === 0){
					if(($feet & PathBlockTypes::TALL) !== 0 || ($feet & $this->dangerMask) !== 0){
						return null;
					}
					$obstacle = true;
				}
			}
		}
		if($obstacle){
			//jump on it if it's at most one block tall and there's room above our head to jump
			for($i = 0; $i < $this->footprint; ++$i){
				for($j = 0; $j < $this->footprint; ++$j){
					if(($this->type($x + $i, $y + $this->headBlocks + 1, $z + $j) & PathBlockTypes::PASSABLE) === 0){
						return null;
					}
				}
			}
			return $this->isStandable($nx, $y + 1, $nz) ? $y + 1 : null;
		}
		if(!$this->fits($nx, $y, $nz)){
			return null;
		}
		//open space without a floor: fall
		for($d = 1; $d <= $this->maxDrop; ++$d){
			$ny = $y - $d;
			if($this->isStandable($nx, $ny, $nz)){
				return $ny;
			}
			for($i = 0; $i < $this->footprint; ++$i){
				for($j = 0; $j < $this->footprint; ++$j){
					$t = $this->type($nx + $i, $ny, $nz + $j);
					if(($t & PathBlockTypes::PASSABLE) === 0 || ($t & $this->dangerMask) !== 0){
						return null;
					}
				}
			}
		}
		return null;
	}

	private function heuristic(int $x, int $y, int $z, int $tx, int $ty, int $tz) : float{
		$dx = $tx - $x;
		$dy = $ty - $y;
		$dz = $tz - $z;
		return sqrt($dx * $dx + $dy * $dy + $dz * $dz);
	}
}
