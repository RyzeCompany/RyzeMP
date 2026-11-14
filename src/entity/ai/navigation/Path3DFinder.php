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

namespace pocketmine\entity\ai\navigation;

use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\World;
use function abs;
use function array_reverse;
use function ceil;
use function floor;
use function max;
use function min;
use function sqrt;

/**
 * A* search through open space for flying and swimming mobs (Bedrock navigation.fly / hover / float and
 * navigation.generic can_swim, Java FlyNodeEvaluator / SwimNodeEvaluator): nodes are blocks where the mob's body
 * fits, air for flyers (no water, nothing dangerous, not on top of a fence or a wall) and water for swimmers (water
 * where it is, water or air above), linked to their six neighbours. The search is bounded by the node count (see
 * {@link AiBudget::getMaxPathNodes()}) and by {@link Path3DFinder::MAX_RANGE}; when the target can't be reached it
 * returns the way to the node closest to it, like the walking {@link PathFinder}. Block flags are cached for the
 * search only.
 *
 * Like vanilla's search (Java caps it at 16 nodes per block of follow range), the node cap limits detours: the search
 * fills the space on the near side of a wall before it gets round it. With 400 nodes a fish in a 6 blocks deep tank
 * gets through an opening about 6 blocks to the side of the straight way, not 10; a flyer gets round the end of a wall
 * about 5 blocks to the side, not 9 (see Nav3dTest). Weighting the heuristic more doesn't change that: the nodes along
 * the wall are as close to the target as the way round it.
 *
 * Paths are made of the blocks the mob goes through; the mob is at the middle of a block (see
 * {@link Path3DFinder::nodeY()}) when it reaches its node. {@link Path3DFinder::isClear()} checks a straight
 * segment, used by {@link StraightLineNavigator} to search only when the straight way is blocked and to skip nodes.
 * @internal
 */
final class Path3DFinder{
	public const MEDIUM_AIR = 1;
	public const MEDIUM_WATER = 2;

	/** Farthest target (along any axis) a search is run for. */
	public const MAX_RANGE = 48;
	/** Blocks below a swimmer's target out of the water its path may end (see {@link Path3DFinder::resolveTarget()}). */
	public const MAX_TARGET_DROP = 4;

	/** Blocks a flyer doesn't go into besides solid ones. */
	private const AIR_BLOCKED = PathBlockTypes::WATER | PathBlockTypes::DANGER | PathBlockTypes::NEAR_DANGER;
	private const DANGER = PathBlockTypes::DANGER | PathBlockTypes::NEAR_DANGER;
	/** Weight of the heuristic: a greedier search visits far fewer nodes around obstacles, smoothing fixes the rest. */
	private const HEURISTIC_WEIGHT = 1.5;
	/** Distance between two points of a segment checked by {@link Path3DFinder::isClear()}. */
	private const CLEAR_STEP = 0.4;

	private const OFFSET = 512;
	/** Most nodes a search visits: the nodes it reaches stay within OFFSET blocks of the start. */
	public const MAX_NODES = 500;
	private const DIRECTIONS = [[1, 0, 0], [-1, 0, 0], [0, 0, 1], [0, 0, -1], [0, 1, 0], [0, -1, 0]];
	/** Neighbours: key offset, then X, Y and Z offsets. */
	private const NEIGHBOURS = [[1 << 20, 1, 0, 0], [-(1 << 20), -1, 0, 0], [1, 0, 0, 1], [-1, 0, 0, -1], [1 << 10, 0, 1, 0], [-(1 << 10), 0, -1, 0]];

	private int $originX = 0;
	private int $originY = 0;
	private int $originZ = 0;
	/**
	 * @var int[] key => block flags
	 * @phpstan-var array<int, int>
	 */
	private array $types = [];
	/**
	 * @var bool[] key => whether the mob fits with this block as its node
	 * @phpstan-var array<int, bool>
	 */
	private array $fits = [];
	private int $lastVisited = 0;

	public function __construct(
		private World $world,
		private int $medium,
		private int $headBlocks,
		private int $footprint,
		private int $maxNodes
	){
		$this->maxNodes = min($maxNodes, self::MAX_NODES);
	}

	public function setWorld(World $world) : void{
		$this->world = $world;
	}

	public function setMaxNodes(int $maxNodes) : void{
		$this->maxNodes = min($maxNodes, self::MAX_NODES);
	}

	public function setFootprint(int $footprint) : void{
		$this->footprint = $footprint;
	}

	public function getLastVisitedCount() : int{
		return $this->lastVisited;
	}

	/**
	 * Returns the block row of the node a mob of the given height is in when its feet are at $feetY: the row of the
	 * middle of its body for mobs smaller than a block, the row of its feet for taller ones.
	 */
	public static function nodeRow(float $feetY, float $height) : int{
		return (int) floor($height < 1.0 ? $feetY + $height / 2 : $feetY + 0.01);
	}

	/**
	 * Returns where the feet of a mob of the given height are when it is at the middle of the node in block row $y
	 * (the inverse of {@link Path3DFinder::nodeRow()}).
	 */
	public static function nodeY(int $y, float $height) : float{
		return $height < 1.0 ? $y + (1.0 - $height) / 2 : $y + 0.05;
	}

	private function type(int $x, int $y, int $z) : int{
		return $this->types[(($x - $this->originX + self::OFFSET) << 20) | (($y - $this->originY + self::OFFSET) << 10) | ($z - $this->originZ + self::OFFSET)] ??= PathBlockTypes::at($this->world, $x, $y, $z);
	}

	/**
	 * Returns whether a single block can hold part of the mob's body: $bottom for the lowest row of its body, where a
	 * swimmer needs water and a flyer can't be on top of a fence.
	 */
	private function isOpen(int $x, int $y, int $z, bool $bottom) : bool{
		$flags = $this->type($x, $y, $z);
		if(($flags & PathBlockTypes::PASSABLE) === 0){
			return false;
		}
		if($this->medium === self::MEDIUM_WATER){
			return ($flags & self::DANGER) === 0 && (!$bottom || ($flags & PathBlockTypes::WATER) !== 0);
		}
		return ($flags & self::AIR_BLOCKED) === 0 && (!$bottom || ($this->type($x, $y - 1, $z) & PathBlockTypes::TALL) === 0);
	}

	private function fits(int $x, int $y, int $z) : bool{
		return $this->fits[(($x - $this->originX + self::OFFSET) << 20) | (($y - $this->originY + self::OFFSET) << 10) | ($z - $this->originZ + self::OFFSET)] ??= $this->computeFits($x, $y, $z);
	}

	private function computeFits(int $x, int $y, int $z) : bool{
		for($fx = 0; $fx < $this->footprint; ++$fx){
			for($fz = 0; $fz < $this->footprint; ++$fz){
				for($i = 0; $i <= $this->headBlocks; ++$i){
					if(!$this->isOpen($x + $fx, $y + $i, $z + $fz, $i === 0)){
						return false;
					}
				}
			}
		}
		return true;
	}

	/**
	 * Returns whether the mob can stand still with the given block as its node (outside of a search).
	 */
	public function canBeAt(int $x, int $y, int $z) : bool{
		$this->originX = $x;
		$this->originY = $y;
		$this->originZ = $z;
		try{
			return $this->computeFits($x, $y, $z);
		}finally{
			$this->types = [];
		}
	}

	/**
	 * Returns the node to search a path to for the given target block: the block itself if the mob fits in it, else
	 * the open block next to it closest to the start (a hive, a block against a wall), else for swimmers the water up
	 * to {@link Path3DFinder::MAX_TARGET_DROP} blocks below it (a point above the surface: dolphins coming up to
	 * breathe). Null if there's none: the target is out of the mob's medium (a swimmer's target on land), a search
	 * would only go through every node it can reach.
	 *
	 * @return int[]|null
	 * @phpstan-return array{int, int, int}|null
	 */
	public function resolveTarget(int $tx, int $ty, int $tz, int $sx, int $sy, int $sz) : ?array{
		$this->originX = $tx;
		$this->originY = $ty;
		$this->originZ = $tz;
		try{
			if($this->computeFits($tx, $ty, $tz)){
				return [$tx, $ty, $tz];
			}
			$best = null;
			$bestDistance = 0;
			foreach(self::DIRECTIONS as [$dx, $dy, $dz]){
				$x = $tx + $dx;
				$y = $ty + $dy;
				$z = $tz + $dz;
				$distance = ($x - $sx) ** 2 + ($y - $sy) ** 2 + ($z - $sz) ** 2;
				if(($best === null || $distance < $bestDistance) && $this->computeFits($x, $y, $z)){
					$best = [$x, $y, $z];
					$bestDistance = $distance;
				}
			}
			if($best !== null || $this->medium !== self::MEDIUM_WATER){
				return $best;
			}
			for($y = $ty - 2; $y >= $ty - self::MAX_TARGET_DROP; --$y){
				if($this->computeFits($tx, $y, $tz)){
					return [$tx, $y, $tz];
				}
			}
			return null;
		}finally{
			$this->types = [];
		}
	}

	/**
	 * Returns the path from the start node to the target node, or to the node closest to it if it can't be reached
	 * (reachesTarget() false), null if there's no way to go at all. A target farther than $acceptDistance (in blocks,
	 * between the nodes) is reached when a node is that close to it; a target the mob doesn't fit in (a hive, a block
	 * the followed entity stands next to) is reached from the nodes next to it.
	 */
	public function findPath(int $sx, int $sy, int $sz, int $tx, int $ty, int $tz, float $acceptDistance = 0.0) : ?Path{
		$this->lastVisited = 0;
		if(abs($tx - $sx) > self::MAX_RANGE || abs($ty - $sy) > self::MAX_RANGE || abs($tz - $sz) > self::MAX_RANGE){
			return null;
		}
		$this->originX = $sx;
		$this->originY = $sy;
		$this->originZ = $sz;
		try{
			return $this->search($sx, $sy, $sz, $tx, $ty, $tz, $acceptDistance);
		}finally{
			//blocks change after the search
			$this->types = [];
			$this->fits = [];
		}
	}

	private function search(int $sx, int $sy, int $sz, int $tx, int $ty, int $tz, float $acceptDistance) : ?Path{
		if(!$this->fits($sx, $sy, $sz)){
			//against a wall or touching the ground: start from an open block next to it
			$moved = false;
			foreach(self::DIRECTIONS as [$dx, $dy, $dz]){
				if($this->fits($sx + $dx, $sy + $dy, $sz + $dz)){
					$sx += $dx;
					$sy += $dy;
					$sz += $dz;
					$moved = true;
					break;
				}
			}
			if(!$moved){
				return null;
			}
		}
		if(!$this->fits($tx, $ty, $tz)){
			$acceptDistance = max($acceptDistance, 1.0);
		}
		$acceptSq = $acceptDistance * $acceptDistance + 0.0001;

		$startKey = (($sx - $this->originX + self::OFFSET) << 20) | (($sy - $this->originY + self::OFFSET) << 10) | ($sz - $this->originZ + self::OFFSET);
		/** @phpstan-var array<int, float> $g */
		$g = [$startKey => 0.0];
		/** @phpstan-var array<int, int> $parent */
		$parent = [];
		/** @phpstan-var array<int, true> $closed */
		$closed = [];
		$open = new \SplPriorityQueue();
		$open->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
		$bestKey = $startKey;
		$bestH = abs($tx - $sx) + abs($ty - $sy) + abs($tz - $sz);
		$open->insert($startKey, 0.0);
		$found = false;
		$simple = $this->footprint === 1 && $this->headBlocks === 0;

		while(!$open->isEmpty()){
			/** @var int $key */
			$key = $open->extract();
			if(isset($closed[$key])){
				continue;
			}
			$closed[$key] = true;
			$x = ($key >> 20) - self::OFFSET + $this->originX;
			$y = (($key >> 10) & 0x3ff) - self::OFFSET + $this->originY;
			$z = ($key & 0x3ff) - self::OFFSET + $this->originZ;
			$dxT = $tx - $x;
			$dyT = $ty - $y;
			$dzT = $tz - $z;
			if($dxT * $dxT + $dyT * $dyT + $dzT * $dzT <= $acceptSq){
				$bestKey = $key;
				$found = true;
				break;
			}
			$h = abs($dxT) + abs($dyT) + abs($dzT);
			if($h < $bestH){
				$bestH = $h;
				$bestKey = $key;
			}
			if(++$this->lastVisited >= $this->maxNodes){
				break;
			}
			$newG = $g[$key] + 1.0;
			foreach(self::NEIGHBOURS as [$dKey, $dx, $dy, $dz]){
				$nKey = $key + $dKey;
				if(isset($closed[$nKey]) || (isset($g[$nKey]) && $g[$nKey] <= $newG)){
					continue;
				}
				$nx = $x + $dx;
				$ny = $y + $dy;
				$nz = $z + $dz;
				if(!($this->fits[$nKey] ??= ($simple ? $this->isOpen($nx, $ny, $nz, true) : $this->computeFits($nx, $ny, $nz)))){
					continue;
				}
				$g[$nKey] = $newG;
				$parent[$nKey] = $key;
				$nh = abs($tx - $nx) + abs($ty - $ny) + abs($tz - $nz);
				//ties broken towards the target: a straight line in open space instead of a front of equal nodes
				$open->insert($nKey, -($newG + $nh * self::HEURISTIC_WEIGHT + $nh * 0.001));
			}
		}

		if($bestKey === $startKey && !$found){
			return null;
		}
		$nodes = [];
		for($k = $bestKey; ; $k = $parent[$k]){
			$nodes[] = [($k >> 20) - self::OFFSET + $this->originX, (($k >> 10) & 0x3ff) - self::OFFSET + $this->originY, ($k & 0x3ff) - self::OFFSET + $this->originZ];
			if(!isset($parent[$k])){
				break;
			}
		}
		return new Path(array_reverse($nodes), $found, $tx, $ty, $tz);
	}

	/**
	 * Returns whether a mob with the given half width and height can move in a straight line from one position (of
	 * its feet) to the other: every block its body goes through is open (see {@link Path3DFinder::isOpen()}).
	 */
	public function isClear(float $fromX, float $fromY, float $fromZ, float $toX, float $toY, float $toZ, float $halfWidth, float $height) : bool{
		$dx = $toX - $fromX;
		$dy = $toY - $fromY;
		$dz = $toZ - $fromZ;
		$length = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
		if($length > self::MAX_RANGE * 2){
			return false;
		}
		$originX = (int) floor($fromX);
		$originY = (int) floor($fromY);
		$originZ = (int) floor($fromZ);
		$steps = max(1, (int) ceil($length / self::CLEAR_STEP));
		$top = $height - 0.001;
		$halfWidth -= 0.001;
		//isOpen() written out, with the blocks read from the chunk of their column (looked up again only when the column
		//is in another chunk) and their flags kept for this call only, like type() did: the world doesn't change during it
		$world = $this->world;
		$worldMinY = $world->getMinY();
		$worldMaxY = $world->getMaxY();
		$chunk = null;
		$chunkX = $chunkZ = null;
		/** @phpstan-var array<int, int> $flags block key => flags */
		$flags = [];
		$seen = [];
		$lastBox = -1;
		$subChunk = null;
		$subChunkY = 0;
		$layer = null;
		$water = $this->medium === self::MEDIUM_WATER;
		for($s = 0; $s <= $steps; ++$s){
			$px = $fromX + $dx * $s / $steps;
			$py = $fromY + $dy * $s / $steps;
			$pz = $fromZ + $dz * $s / $steps;
			//(int) floor() without the calls (several points per block for every swimming and flying mob checking its way):
			//the cast rounds towards zero, one less below zero unless already whole
			$v = $px - $halfWidth;
			$minX = (int) $v;
			if($minX > $v){
				--$minX;
			}
			$v = $px + $halfWidth;
			$maxX = (int) $v;
			if($maxX > $v){
				--$maxX;
			}
			$minY = (int) $py;
			if($minY > $py){
				--$minY;
			}
			$v = $py + $top;
			$maxY = (int) $v;
			if($maxY > $v){
				--$maxY;
			}
			$v = $pz - $halfWidth;
			$minZ = (int) $v;
			if($minZ > $v){
				--$minZ;
			}
			$v = $pz + $halfWidth;
			$maxZ = (int) $v;
			if($maxZ > $v){
				--$maxZ;
			}
			$bottomCheck = $water || $py - $minY < 0.5;
			//consecutive points mostly cover the same blocks: nothing new to check
			$box = ((($minX - $originX + self::OFFSET) << 20) | (($minY - $originY + self::OFFSET) << 10) | ($minZ - $originZ + self::OFFSET)) * 32 +
				($maxX - $minX) * 8 + ($maxZ - $minZ) * 2 + ($bottomCheck ? 1 : 0) + ($maxY - $minY) * 16;
			if($box === $lastBox){
				continue;
			}
			$lastBox = $box;
			for($bx = $minX; $bx <= $maxX; ++$bx){
				for($bz = $minZ; $bz <= $maxZ; ++$bz){
					if($bx >> Chunk::COORD_BIT_SIZE !== $chunkX || $bz >> Chunk::COORD_BIT_SIZE !== $chunkZ){
						$chunkX = $bx >> Chunk::COORD_BIT_SIZE;
						$chunkZ = $bz >> Chunk::COORD_BIT_SIZE;
						$chunk = $world->getChunk($chunkX, $chunkZ);
						$subChunk = null;
					}
					for($by = $minY; $by <= $maxY; ++$by){
						$bottom = $by === $minY && $bottomCheck;
						$blockKey = (($bx - $originX + self::OFFSET) << 20) | (($by - $originY + self::OFFSET) << 10) | ($bz - $originZ + self::OFFSET);
						$key = ($blockKey << 1) | ($bottom ? 1 : 0);
						if(isset($seen[$key])){
							continue;
						}
						$seen[$key] = true;
						$block = $flags[$blockKey] ?? null;
						if($block === null){
							if($chunk !== null && $by >= $worldMinY && $by < $worldMaxY){
								//PathBlockTypes::inColumn() with the subchunk and its block layer looked up once for the blocks
								//of the same subchunk (most of a check)
								if($subChunk === null || $by >> SubChunk::COORD_BIT_SIZE !== $subChunkY){
									$subChunkY = $by >> SubChunk::COORD_BIT_SIZE;
									$subChunk = $chunk->getSubChunk($subChunkY);
									$layer = $subChunk->getBlockLayers()[0] ?? null;
								}
								$block = $flags[$blockKey] = PathBlockTypes::inLayer($world, $subChunk, $layer, $bx, $by, $bz);
							}else{
								$block = $flags[$blockKey] = PathBlockTypes::inColumn($world, $chunk, $worldMinY, $worldMaxY, $bx, $by, $bz);
							}
						}
						if(($block & PathBlockTypes::PASSABLE) === 0){
							return false;
						}
						if($water){
							if(($block & self::DANGER) !== 0 || ($bottom && ($block & PathBlockTypes::WATER) === 0)){
								return false;
							}
						}elseif(($block & self::AIR_BLOCKED) !== 0 || ($bottom &&
							(($flags[$blockKey - (1 << 10)] ??= PathBlockTypes::inColumn($world, $chunk, $worldMinY, $worldMaxY, $bx, $by - 1, $bz)) & PathBlockTypes::TALL) !== 0
						)){
							return false;
						}
					}
				}
			}
		}
		return true;
	}
}
