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

use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Utils;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\NativePathFinder;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use pocketmine\world\World;
use function abs;
use function array_key_exists;
use function array_reverse;
use function array_slice;
use function class_exists;
use function count;
use function floor;
use function get_debug_type;
use function implode;
use function is_array;
use function max;
use function min;
use function sqrt;
use const M_SQRT2;

/**
 * A* over the block grid for walking mobs.
 *
 * A node is the block a mob's feet are in, or for mobs wider than a block the corner with the lowest X and Z of the
 * square of blocks they take (the footprint, {@link PathFinder::footprintOf()}, vanilla Java WalkNodeEvaluator's
 * entityWidth): the mob stands in the middle of that square. A node is standable when the mob fits (feet and head
 * blocks of every column passable), something is there to stand on (a floor below one of the columns, or a low block
 * like a slab, or water when the mob swims) and nothing is dangerous for this mob (fire, lava, cactus..., see
 * {@link PathFinder::setDangerMask()}). From a node the mob can walk to the 8 neighbours on the same level, step/jump
 * up one block, or drop down up to {@link PathFinder::$maxDrop} blocks. Diagonal moves can't cut corners.
 *
 * Searches are bounded by a node limit; when the target can't be reached the path to the explored node closest to it
 * is returned (like vanilla), so mobs at least move towards it.
 */
final class PathFinder{
	public const DEFAULT_MAX_NODES = 400;
	/** Blocks around the start and the target of a search looked at for doors (see {@link PathFinder::hasDoorsAround()}) */
	private const DOOR_SCAN_MARGIN = 12;

	private const COST_JUMP = 0.5;
	private const COST_DROP_PER_BLOCK = 0.25;
	private const COST_WATER = 2.0;
	private const COST_WATER_AVOID = 8.0;

	private const OFFSET = 512;

	/** Bits of {@link PathFinder::near()}. */
	private const NEAR_BLOCKED = 1;
	private const NEAR_COSTLY = 2;

	/** Neighbour offsets: 4 cardinal first, then 4 diagonal. */
	private const DIRECTIONS = [[1, 0], [-1, 0], [0, 1], [0, -1], [1, 1], [1, -1], [-1, 1], [-1, -1]];

	/**
	 * Per-search cache of block flags, by local key.
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $types = [];

	/**
	 * Per-search caches of fits() and isStandable(), by local key: the A* asks both several times for the same block
	 * (as a neighbour of several nodes, and for diagonal corner checks), and the world doesn't change during a search.
	 * @var bool[]
	 * @phpstan-var array<int, bool>
	 */
	private array $fitsCache = [];
	/**
	 * @var bool[]
	 * @phpstan-var array<int, bool>
	 */
	private array $standableCache = [];
	/**
	 * Per-search cache of whether a single column fits, for footprints wider than a block.
	 * @var bool[]
	 * @phpstan-var array<int, bool>
	 */
	private array $cellFitsCache = [];
	/**
	 * Per-search cache of near(), by local key.
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $nearCache = [];
	/**
	 * Per-search cache of nodeCost(), by local key.
	 * @var float[]
	 * @phpstan-var array<int, float>
	 */
	private array $costCache = [];
	/**
	 * Per-search cache of the kinds of danger in the palette of each chunk section, by section key (chunk X and Z and
	 * section index).
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $sectionDanger = [];
	/**
	 * Per-search caches of the block layer of the sections sectionDanger() looked at, and of where the dangerous blocks of
	 * those sections are (see {@link PathRecomputeWatch::getDangerRows()}), by section key.
	 * @var PalettedBlockArray[]
	 * @phpstan-var array<int, PalettedBlockArray>
	 */
	private array $sectionLayers = [];
	/**
	 * @var int[][]
	 * @phpstan-var array<int, array<int, int>>
	 */
	private array $sectionRows = [];
	/**
	 * Whether the masks of the danger margin are kinds of danger the danger rows of sections have
	 * ({@link PathBlockTypes::NEAR_DANGER}), and the bits of those rows near() tests: the rows in front of and behind the
	 * footprint (the footprint and a block on both sides), the rows beside it (a block on both sides).
	 */
	private bool $dangerRowMasks = false;
	private int $blockRowBits = 0;
	private int $costRowBits = 0;
	private int $blockSideBits = 0;
	private int $costSideBits = 0;

	/**
	 * @var (Chunk|null)[]
	 * @phpstan-var array<int, Chunk|null>
	 */
	private array $chunks = [];

	private int $minY = 0;
	private int $maxY = 0;

	private int $originX = 0;
	private int $originY = 0;
	private int $originZ = 0;

	/** Number of nodes expanded by the last search (for profiling and tests). */
	private int $lastVisited = 0;
	private bool $canOpenDoors = false;
	private bool $canBreakDoors = false;

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
	){
		$this->minY = $world->getMinY();
		$this->maxY = $world->getMaxY();
	}

	/**
	 * Returns the footprint of a mob of the given width: the number of blocks it takes along X and Z (vanilla Java
	 * WalkNodeEvaluator: floor(width + 1), so 1 for mobs narrower than a block, 2 for spiders, iron golems, horses,
	 * ravagers..., 3 from 2 blocks wide), at most 8.
	 */
	public static function footprintOf(float $width) : int{
		return max(1, min(8, (int) floor($width + 1)));
	}

	/**
	 * Returns the node coordinate (X or Z) of a mob with the given footprint whose middle is at $centre: the column it
	 * stands in for a 1-wide footprint, otherwise the lowest column of the footprint centred closest to it.
	 */
	public static function nodeCoordinate(float $centre, int $footprint) : int{
		return $footprint === 1 ? (int) floor($centre) : (int) floor($centre - $footprint * 0.5 + 0.5);
	}

	public function setWorld(World $world) : void{
		$this->world = $world;
		$this->minY = $world->getMinY();
		$this->maxY = $world->getMaxY();
	}

	public function setMaxNodes(int $maxNodes) : void{
		$this->maxNodes = $maxNodes;
	}

	/**
	 * Returns the number of nodes a search may visit before it gives up (keeping the closest partial path).
	 */
	public function getMaxNodes() : int{
		return $this->maxNodes;
	}

	/**
	 * Sets the number of blocks the mob takes along X and Z (see {@link PathFinder::footprintOf()}).
	 */
	public function setFootprint(int $footprint) : void{
		if($footprint < 1 || $footprint > 8){
			throw new \InvalidArgumentException("Footprint must be between 1 and 8, got $footprint");
		}
		$this->footprint = $footprint;
		$this->updateDangerRowBits();
	}

	public function getFootprint() : int{
		return $this->footprint;
	}

	/**
	 * Returns the footprint searches of this finder use for a mob of the given footprint: the same, except with the
	 * native search of chunkutils2 0.3.7 to 0.3.10, which only knows 1-wide footprints. Checking its paths against wide
	 * mobs (and searching again in PHP, or cutting them, where they don't fit) costs half as much again as the search on
	 * open ground and several times it in cluttered places, so there wide mobs keep the 1-wide paths of before until the
	 * server runs chunkutils2 0.3.11.
	 */
	public function getEffectiveFootprint(int $footprint) : int{
		return $footprint > 1 && !$this->passesDoors() && self::nativeFinder() !== null && !self::nativeHasOptions() ? 1 : $footprint;
	}

	/**
	 * Sets the {@link PathBlockTypes} flags of the blocks the mob's paths never go into, onto or through: by default
	 * DANGER (anything that hurts); mobs set the kinds of danger they avoid (see {@link NavigationOptions::getDangerMask()}).
	 */
	public function setDangerMask(int $dangerMask) : void{
		$this->dangerMask = $dangerMask;
	}

	/**
	 * Sets the flags of the blocks the mob stands on: FLOOR by default, also LAVA for striders (vanilla can_walk_in_lava:
	 * they walk on its surface). Blocks with these flags are a floor even if the danger mask has some of them.
	 */
	public function setFloorMask(int $floorMask) : void{
		$this->floorMask = $floorMask;
	}

	/**
	 * Sets flags of liquids the mob wades through (lava for mobs with vanilla can_path_over_lava, which have no danger
	 * mask for it) and the cost of a node with its feet in them. Like any other node, it needs a floor below.
	 */
	public function setLiquid(int $liquidMask, float $liquidCost) : void{
		$this->liquidMask = $liquidMask;
		$this->liquidCost = $liquidCost;
	}

	/**
	 * Sets what the mob avoids next to its nodes, in the blocks around the column(s) it stands in: nodes next to a block
	 * with flags of $blockMask at the mob's feet level are not standable (mobs which avoid damage blocks don't walk
	 * between two fires, BDS), nodes next to a block with flags of $costMask at the feet level or the level below cost
	 * $cost more (vanilla Java FIRE_IN_NEIGHBOR and DAMAGING_IN_NEIGHBOR: 8), so paths keep clear of fire and lava
	 * when they can. Nodes in water or another liquid of the mob don't get that cost.
	 */
	public function setDangerMargin(int $blockMask, int $costMask, float $cost) : void{
		$this->nearBlockMask = $blockMask;
		$this->nearCostMask = $costMask;
		$this->nearCost = $cost;
		$this->updateDangerRowBits();
	}

	/**
	 * Sets the weight of the distance to the target in the search (1 by default: the shortest paths). Vanilla Java
	 * weighs it 1.5 (PathFinder.FUDGING): searches expand far fewer nodes, mostly around the way to the target, and
	 * still find it across water or lava which costs a lot more than going round, for a path a little longer than the
	 * shortest at times.
	 */
	public function setHeuristicWeight(float $weight) : void{
		$this->heuristicWeight = $weight;
	}

	/**
	 * Applies the navigation options of a mob: whether it swims, avoids water, which dangers it avoids, lava, fire, danger
	 * margin, and the vanilla weight of the distance to the target ({@link NavigationOptions::HEURISTIC_WEIGHT}).
	 */
	public function applyOptions(NavigationOptions $options) : void{
		$this->heuristicWeight = NavigationOptions::HEURISTIC_WEIGHT;
		$this->canSwim = $options->canSwim();
		$this->avoidWater = $options->avoidsWater();
		$this->dangerMask = $options->getDangerMask();
		$this->floorMask = PathBlockTypes::FLOOR | ($options->canWalkInLava() ? PathBlockTypes::LAVA : 0);
		$costly = $options->getCostlyMask();
		if($options->canPathOverLava() && !$options->canWalkInLava()){
			$this->setLiquid(PathBlockTypes::LAVA, NavigationOptions::LAVA_COST);
		}elseif($costly !== 0){
			//fire: walked through like a liquid, at a cost (nothing else has these flags)
			$this->setLiquid($costly, NavigationOptions::FIRE_COST);
		}else{
			$this->setLiquid(0, 0.0);
		}
		$this->setDangerMargin(
			$options->avoidsDamageBlocks() ? PathBlockTypes::DAMAGE_BLOCK : 0,
			($this->dangerMask | $costly) & PathBlockTypes::NEAR_DANGER & ~$this->floorMask,
			NavigationOptions::DANGER_MARGIN_COST
		);
	}

	/**
	 * Lets the paths go through closed doors other than iron doors (villagers open them, vanilla
	 * navigation can_open_doors). These searches run in PHP: the native finder classifies block states once for all
	 * mobs.
	 */
	public function setCanOpenDoors(bool $canOpenDoors) : void{
		$this->canOpenDoors = $canOpenDoors;
	}

	public function canOpenDoors() : bool{
		return $this->canOpenDoors;
	}

	/**
	 * Lets the paths go through closed doors other than iron doors, for mobs which break them on their way (vanilla
	 * navigation can_break_doors with annotation.break_door: some zombies, vindicators in raids, see
	 * {@link \pocketmine\entity\ai\goal\BreakDoorGoal}). Like {@link PathFinder::setCanOpenDoors()}, these searches run
	 * in PHP.
	 */
	public function setCanBreakDoors(bool $canBreakDoors) : void{
		$this->canBreakDoors = $canBreakDoors;
	}

	public function canBreakDoors() : bool{
		return $this->canBreakDoors;
	}

	/**
	 * Whether the paths go through closed doors other than iron doors (the mob opens or breaks them).
	 */
	public function passesDoors() : bool{
		return $this->canOpenDoors || $this->canBreakDoors;
	}

	public function getLastVisitedCount() : int{
		return $this->lastVisited;
	}

	private function key(int $x, int $y, int $z) : int{
		return (($x - $this->originX + self::OFFSET) << 20) | (($y - $this->originY + self::OFFSET) << 10) | ($z - $this->originZ + self::OFFSET);
	}

	private function type(int $x, int $y, int $z) : int{
		$key = (($x - $this->originX + self::OFFSET) << 20) | (($y - $this->originY + self::OFFSET) << 10) | ($z - $this->originZ + self::OFFSET);
		if(isset($this->types[$key])){
			return $this->types[$key];
		}
		$chunkHash = World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		if(!array_key_exists($chunkHash, $this->chunks)){
			$this->chunks[$chunkHash] = $this->world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		}
		//same as PathBlockTypes::inChunk(), with the world height read once per search
		$chunk = $this->chunks[$chunkHash];
		if($chunk === null || $y < $this->minY){
			return $this->types[$key] = PathBlockTypes::FLOOR | PathBlockTypes::OPAQUE;
		}
		if($y >= $this->maxY){
			return $this->types[$key] = PathBlockTypes::PASSABLE;
		}
		$flags = PathBlockTypes::ofState($this->world, $chunk->getBlockStateId($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK), $x, $y, $z);
		if(($this->canOpenDoors || $this->canBreakDoors) && ($flags & (PathBlockTypes::CLOSED_DOOR | PathBlockTypes::OPENABLE_DOOR)) === (PathBlockTypes::CLOSED_DOOR | PathBlockTypes::OPENABLE_DOOR)){
			$flags = ($flags & ~(PathBlockTypes::CLOSED_DOOR | PathBlockTypes::TALL)) | PathBlockTypes::PASSABLE;
		}
		return $this->types[$key] = $flags;
	}

	/**
	 * Returns whether the mob's body fits with its feet in the given block (ignoring the floor): every column of its
	 * footprint.
	 */
	private function fits(int $x, int $y, int $z) : bool{
		return $this->fitsCache[(($x - $this->originX + self::OFFSET) << 20) | (($y - $this->originY + self::OFFSET) << 10) | ($z - $this->originZ + self::OFFSET)] ??= ($this->footprint === 1 ? $this->computeFits($x, $y, $z) : $this->computeFootprintFits($x, $y, $z));
	}

	private function computeFootprintFits(int $x, int $y, int $z) : bool{
		$yKey = ($y - $this->originY + self::OFFSET) << 10;
		for($i = 0; $i < $this->footprint; ++$i){
			$xKey = ($x + $i - $this->originX + self::OFFSET) << 20;
			for($j = 0; $j < $this->footprint; ++$j){
				if(!($this->cellFitsCache[$xKey | $yKey | ($z + $j - $this->originZ + self::OFFSET)] ??= $this->computeFits($x + $i, $y, $z + $j))){
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Returns whether one column of the mob fits with its feet in the given block.
	 */
	private function computeFits(int $x, int $y, int $z) : bool{
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
	 * Returns whether the mob can stand with its feet in the given block: fits() and something to stand on.
	 */
	public function isStandable(int $x, int $y, int $z) : bool{
		return $this->standableCache[(($x - $this->originX + self::OFFSET) << 20) | (($y - $this->originY + self::OFFSET) << 10) | ($z - $this->originZ + self::OFFSET)] ??= $this->computeStandable($x, $y, $z);
	}

	private function computeStandable(int $x, int $y, int $z) : bool{
		if(!$this->fits($x, $y, $z)){
			return false;
		}
		$standOn = PathBlockTypes::LOW | PathBlockTypes::WATER;
		//what the mob stands on may be dangerous for other mobs (lava for striders)
		$floorDanger = $this->dangerMask & ~$this->floorMask;
		if($this->footprint === 1){
			if(($this->type($x, $y, $z) & $standOn) === 0){
				$below = $this->type($x, $y - 1, $z);
				if(($below & $this->floorMask) === 0 || ($below & $floorDanger) !== 0){
					return false;
				}
			}
		}else{
			//one column with something to stand on is enough (vanilla Java: the footprint is walkable when any of its
			//blocks is), but none may stand on something dangerous
			$supported = false;
			//fits() typed the feet blocks: read them from the cache without a call
			$feetKey = ($y - $this->originY + self::OFFSET) << 10;
			$belowKey = ($y - 1 - $this->originY + self::OFFSET) << 10;
			for($i = 0; $i < $this->footprint; ++$i){
				$xKey = ($x + $i - $this->originX + self::OFFSET) << 20;
				for($j = 0; $j < $this->footprint; ++$j){
					$zKey = $z + $j - $this->originZ + self::OFFSET;
					if((($this->types[$xKey | $feetKey | $zKey] ?? $this->type($x + $i, $y, $z + $j)) & $standOn) !== 0){
						$supported = true;
						continue;
					}
					$below = $this->types[$xKey | $belowKey | $zKey] ?? $this->type($x + $i, $y - 1, $z + $j);
					if(($below & $floorDanger) !== 0){
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
		}
		return $this->nearBlockMask === 0 || ($this->near($x, $y, $z) & self::NEAR_BLOCKED) === 0;
	}

	private function nodeCost(int $x, int $y, int $z) : float{
		//asked again for every parent a node is reached from
		return $this->costCache[(($x - $this->originX + self::OFFSET) << 20) | (($y - $this->originY + self::OFFSET) << 10) | ($z - $this->originZ + self::OFFSET)] ??= $this->computeNodeCost($x, $y, $z);
	}

	private function computeNodeCost(int $x, int $y, int $z) : float{
		if($this->footprint === 1){
			$feet = $this->type($x, $y, $z);
			if(($feet & PathBlockTypes::WATER) !== 0){
				return $this->avoidWater ? self::COST_WATER_AVOID : self::COST_WATER;
			}
			if(($feet & $this->liquidMask) !== 0){
				return $this->liquidCost;
			}
			return $this->nearCostMask !== 0 && ($this->near($x, $y, $z) & self::NEAR_COSTLY) !== 0 ? $this->nearCost : 0.0;
		}
		//the most expensive column, like vanilla Java for wide mobs
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
		if(!$inLiquid && $this->nearCostMask !== 0 && ($this->near($x, $y, $z) & self::NEAR_COSTLY) !== 0){
			return $this->nearCost;
		}
		return $cost;
	}

	/**
	 * @internal Returns whether the mob avoids something next to the given node (see {@link PathFinder::setDangerMargin()}),
	 * reading the blocks of the world as they are now.
	 */
	public function isNearDanger(int $x, int $y, int $z) : bool{
		$blockMask = $this->nearBlockMask;
		$costMask = $this->nearCostMask;
		if(($blockMask | $costMask) === 0){
			return false;
		}
		$world = $this->world;
		$footprint = $this->footprint;
		for($i = $x - 1; $i <= $x + $footprint; ++$i){
			for($j = $z - 1; $j <= $z + $footprint; ++$j){
				if($i >= $x && $i < $x + $footprint && $j >= $z && $j < $z + $footprint){
					continue;
				}
				$feet = PathBlockTypes::at($world, $i, $y, $j);
				if(($feet & ($blockMask | $costMask)) !== 0 || ($costMask !== 0 && (PathBlockTypes::at($world, $i, $y - 1, $j) & $costMask) !== 0)){
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Returns what is in the blocks around the footprint of a node (see {@link PathFinder::setDangerMargin()}):
	 * NEAR_BLOCKED, NEAR_COSTLY.
	 */
	private function near(int $x, int $y, int $z) : int{
		//asked for most nodes the search looks at: the blocks around them in one section without anything dangerous (most
		//of a world) is answered here, without caching it
		$cx = $x >> Chunk::COORD_BIT_SIZE;
		$cz = $z >> Chunk::COORD_BIT_SIZE;
		$sy = $y >> SubChunk::COORD_BIT_SIZE;
		if(
			(($x - 1) >> Chunk::COORD_BIT_SIZE) === $cx && (($x + $this->footprint) >> Chunk::COORD_BIT_SIZE) === $cx &&
			(($z - 1) >> Chunk::COORD_BIT_SIZE) === $cz && (($z + $this->footprint) >> Chunk::COORD_BIT_SIZE) === $cz &&
			(($y - 1) >> SubChunk::COORD_BIT_SIZE) === $sy &&
			(($this->sectionDanger[(($cx & 0x3fffff) << 32) | (($cz & 0x3fffff) << 10) | ($sy & 0x3ff)] ?? $this->sectionDanger($cx, $sy, $cz)) & ($this->nearBlockMask | $this->nearCostMask)) === 0
		){
			return 0;
		}
		return $this->nearCache[(($x - $this->originX + self::OFFSET) << 20) | (($y - $this->originY + self::OFFSET) << 10) | ($z - $this->originZ + self::OFFSET)] ??= $this->computeNear($x, $y, $z);
	}

	private function computeNear(int $x, int $y, int $z) : int{
		$mask = $this->nearBlockMask | $this->nearCostMask;
		$footprint = $this->footprint;
		//most of the world has nothing dangerous: skip the blocks around when no chunk section they are in has any in its
		//palette (the answer is the same, the native search reads the blocks)
		$cxMin = ($x - 1) >> Chunk::COORD_BIT_SIZE;
		$cxMax = ($x + $footprint) >> Chunk::COORD_BIT_SIZE;
		$czMin = ($z - 1) >> Chunk::COORD_BIT_SIZE;
		$czMax = ($z + $footprint) >> Chunk::COORD_BIT_SIZE;
		$syMin = ($y - 1) >> SubChunk::COORD_BIT_SIZE;
		$syMax = $y >> SubChunk::COORD_BIT_SIZE;
		if($cxMin === $cxMax && $czMin === $czMax && $syMin === $syMax){
			//most nodes: the blocks around are in one section
			$sectionKey = (($cxMin & 0x3fffff) << 32) | (($czMin & 0x3fffff) << 10) | ($syMin & 0x3ff);
			$any = $this->sectionDanger[$sectionKey] ?? $this->sectionDanger($cxMin, $syMin, $czMin);
			if(($any & $mask) === 0){
				return 0;
			}
			if($this->dangerRowMasks){
				//something dangerous in the section: test the bits of where it is (see PathRecomputeWatch::getDangerRows()),
				//the rows along X in front of and behind the footprint whole, the rows beside it only on both sides
				$rows = $this->sectionRows[$sectionKey] ??= $this->sectionRows($sectionKey);
				$lx = ($x - 1) & Chunk::COORD_MASK;
				$lz = ($z - 1) & Chunk::COORD_MASK;
				$feet = ($y & SubChunk::COORD_MASK) << 4;
				$below = $feet - 16; //y - 1 is in this section too
				$last = $footprint + 1;
				$f = $rows[$feet | $lz] >> $lx;
				$blocked = $f & $this->blockRowBits;
				$costly = ($f | ($rows[$below | $lz] >> $lx)) & $this->costRowBits;
				$f = $rows[$feet | ($lz + $last)] >> $lx;
				$blocked |= $f & $this->blockRowBits;
				$costly |= ($f | ($rows[$below | ($lz + $last)] >> $lx)) & $this->costRowBits;
				for($j = 1; $j < $last; ++$j){
					$f = $rows[$feet | ($lz + $j)] >> $lx;
					$blocked |= $f & $this->blockSideBits;
					$costly |= ($f | ($rows[$below | ($lz + $j)] >> $lx)) & $this->costSideBits;
				}
				return ($blocked !== 0 ? self::NEAR_BLOCKED : 0) | ($costly !== 0 ? self::NEAR_COSTLY : 0);
			}
		}else{
			$any = 0;
			for($cx = $cxMin; $cx <= $cxMax; ++$cx){
				for($cz = $czMin; $cz <= $czMax; ++$cz){
					for($sy = $syMin; $sy <= $syMax; ++$sy){
						$any |= $this->sectionDanger($cx, $sy, $cz);
					}
				}
			}
		}
		if(($any & $mask) === 0){
			return 0;
		}
		$result = 0;
		//once both bits are found the rest can't change the answer
		$all = ($this->nearBlockMask !== 0 ? self::NEAR_BLOCKED : 0) | ($this->nearCostMask !== 0 ? self::NEAR_COSTLY : 0);
		$feetKey = ($y - $this->originY + self::OFFSET) << 10;
		$belowKey = ($y - 1 - $this->originY + self::OFFSET) << 10;
		for($i = $x - 1; $i <= $x + $footprint; ++$i){
			$xKey = ($i - $this->originX + self::OFFSET) << 20;
			for($j = $z - 1; $j <= $z + $footprint; ++$j){
				if($i >= $x && $i < $x + $footprint && $j >= $z && $j < $z + $footprint){
					continue;
				}
				//the blocks around were mostly typed already as the feet or floor of other nodes: no call for them
				$zKey = $j - $this->originZ + self::OFFSET;
				$feet = $this->types[$xKey | $feetKey | $zKey] ?? $this->type($i, $y, $j);
				if(($feet & $this->nearBlockMask) !== 0){
					$result |= self::NEAR_BLOCKED;
				}
				if((($feet | ($this->types[$xKey | $belowKey | $zKey] ?? $this->type($i, $y - 1, $j))) & $this->nearCostMask) !== 0){
					$result |= self::NEAR_COSTLY;
				}
				if($result === $all){
					return $result;
				}
			}
		}
		return $result;
	}

	/**
	 * Returns the kinds of danger (see {@link PathBlockTypes::dangerOfState()}) of the block states in the palette of
	 * a chunk section, 0 for unloaded chunks and outside the world (what type() returns there has no danger).
	 */
	private function sectionDanger(int $chunkX, int $sectionY, int $chunkZ) : int{
		$sectionKey = (($chunkX & 0x3fffff) << 32) | (($chunkZ & 0x3fffff) << 10) | ($sectionY & 0x3ff);
		if(isset($this->sectionDanger[$sectionKey])){
			return $this->sectionDanger[$sectionKey];
		}
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		if(!array_key_exists($chunkHash, $this->chunks)){
			$this->chunks[$chunkHash] = $this->world->getChunk($chunkX, $chunkZ);
		}
		$chunk = $this->chunks[$chunkHash];
		$danger = 0;
		if($chunk !== null && ($sectionY << SubChunk::COORD_BIT_SIZE) >= $this->minY && ($sectionY << SubChunk::COORD_BIT_SIZE) < $this->maxY){
			$layers = $chunk->getSubChunk($sectionY)->getBlockLayers();
			if(count($layers) > 0){
				foreach($layers[0]->getPalette() as $stateId){
					$danger |= PathBlockTypes::dangerOfState($stateId);
				}
				$this->sectionLayers[$sectionKey] = $layers[0];
			}
		}
		return $this->sectionDanger[$sectionKey] = $danger;
	}

	/**
	 * Returns where the dangerous blocks are in a section sectionDanger() found some in (see
	 * {@link PathRecomputeWatch::getDangerRows()}).
	 *
	 * @return int[]
	 * @phpstan-return array<int, int>
	 */
	private function sectionRows(int $sectionKey) : array{
		$layer = $this->sectionLayers[$sectionKey] ?? null;
		if($layer === null){
			throw new AssumptionFailedError("Sections with dangerous blocks have a block layer");
		}
		return $this->world->getPathRecomputeWatch()->getDangerRows($layer);
	}

	/**
	 * Finds a path from the block (sx, sy, sz) to the block (tx, ty, tz). For a footprint wider than a block, both are
	 * nodes (the corner of the footprint, see {@link PathFinder::nodeCoordinate()}).
	 *
	 * @param float $acceptDistance the search succeeds on reaching a node at most this far (horizontally, and within 1
	 *                              block vertically) from the target
	 */
	public function findPath(int $sx, int $sy, int $sz, int $tx, int $ty, int $tz, float $acceptDistance = 0.0) : ?Path{
		try{
			//the native search sees closed doors as walls: mobs which open or break doors search in PHP where there are any
			$native = self::nativeFinder();
			if($native !== null && ($this->canOpenDoors || $this->canBreakDoors) && $this->hasDoorsAround($sx, $sy, $sz, $tx, $ty, $tz)){
				$native = null;
			}
			if($native !== null && (self::nativeHasOptions() || $this->fitsLegacyNativeSearch())){
				try{
					$path = $this->findPathNative($native, $sx, $sy, $sz, $tx, $ty, $tz, $acceptDistance);
					if(($path === null || !$path->reachesTarget()) && !self::nativeHasOptions() && $this->hasFireToCrossAround($sx, $sy, $sz, $tx, $ty, $tz)){
						//the older native search avoids all fire: when it finds no way, a mob which walks through fire (or
						//crosses it at a cost) may still find one across it
						return $this->findPathPhp($sx, $sy, $sz, $tx, $ty, $tz, $acceptDistance);
					}
					return $path;
				}catch(\RuntimeException $e){
					//a chunk section the native search can't read must not crash the server: report it and use the PHP
					//search (same paths)
					$this->reportNativeFailure($e, $sx, $sz, $tx, $tz);
				}
			}
			return $this->findPathPhp($sx, $sy, $sz, $tx, $ty, $tz, $acceptDistance);
		}finally{
			//what the caches hold is only right during a search (blocks change after it), and they keep chunks alive
			$this->releaseCaches();
		}
	}

	/**
	 * Returns whether a door mobs open (not iron) is in the palette of a chunk section a search between the two points
	 * could reach: those around both points and between them, {@link PathFinder::DOOR_SCAN_MARGIN} blocks further
	 * around. Searches of mobs which open or break doors only need the PHP search there: elsewhere (open fields, the
	 * nether, most of a world) the native one finds the same paths. Unloaded chunks have no doors. Which sections have
	 * doors is kept per chunk (see {@link PathRecomputeWatch::getDoorSections()}): a lookup per chunk once worked out.
	 */
	private function hasDoorsAround(int $sx, int $sy, int $sz, int $tx, int $ty, int $tz) : bool{
		$watch = $this->world->getPathRecomputeWatch();
		$minSection = max(Chunk::MIN_SUBCHUNK_INDEX, $this->minY >> SubChunk::COORD_BIT_SIZE);
		$maxSection = min(Chunk::MAX_SUBCHUNK_INDEX, ($this->maxY - 1) >> SubChunk::COORD_BIT_SIZE);
		$syMin = max($minSection, (min($sy, $ty) - self::DOOR_SCAN_MARGIN) >> SubChunk::COORD_BIT_SIZE);
		$syMax = min($maxSection, (max($sy, $ty) + self::DOOR_SCAN_MARGIN) >> SubChunk::COORD_BIT_SIZE);
		if($syMin > $syMax){
			return false;
		}
		$sectionMask = ((1 << ($syMax - $syMin + 1)) - 1) << ($syMin - Chunk::MIN_SUBCHUNK_INDEX);
		for($cx = (min($sx, $tx) - self::DOOR_SCAN_MARGIN) >> Chunk::COORD_BIT_SIZE, $cxMax = (max($sx, $tx) + self::DOOR_SCAN_MARGIN) >> Chunk::COORD_BIT_SIZE; $cx <= $cxMax; ++$cx){
			for($cz = (min($sz, $tz) - self::DOOR_SCAN_MARGIN) >> Chunk::COORD_BIT_SIZE, $czMax = (max($sz, $tz) + self::DOOR_SCAN_MARGIN) >> Chunk::COORD_BIT_SIZE; $cz <= $czMax; ++$cz){
				$chunk = $this->world->getChunk($cx, $cz);
				if($chunk !== null && ($watch->getDoorSections($chunk) & $sectionMask) !== 0){
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Returns whether the mob's paths may go through fire (see {@link NavigationOptions::getCostlyMask()}) and there is
	 * fire in the palette of a chunk section a search between the two points could reach (as far around as for doors,
	 * see {@link PathFinder::hasDoorsAround()}): what the older native searches (see
	 * {@link PathFinder::fitsLegacyNativeSearch()}) get wrong. Unloaded chunks have no fire.
	 */
	private function hasFireToCrossAround(int $sx, int $sy, int $sz, int $tx, int $ty, int $tz) : bool{
		//fire is a damage block too
		if(($this->dangerMask & (PathBlockTypes::FIRE | PathBlockTypes::DAMAGE_BLOCK)) !== 0){
			return false;
		}
		$syMin = max($this->minY, min($sy, $ty) - self::DOOR_SCAN_MARGIN) >> SubChunk::COORD_BIT_SIZE;
		$syMax = (min($this->maxY - 1, max($sy, $ty) + self::DOOR_SCAN_MARGIN)) >> SubChunk::COORD_BIT_SIZE;
		for($cx = (min($sx, $tx) - self::DOOR_SCAN_MARGIN) >> Chunk::COORD_BIT_SIZE, $cxMax = (max($sx, $tx) + self::DOOR_SCAN_MARGIN) >> Chunk::COORD_BIT_SIZE; $cx <= $cxMax; ++$cx){
			for($cz = (min($sz, $tz) - self::DOOR_SCAN_MARGIN) >> Chunk::COORD_BIT_SIZE, $czMax = (max($sz, $tz) + self::DOOR_SCAN_MARGIN) >> Chunk::COORD_BIT_SIZE; $cz <= $czMax; ++$cz){
				for($cy = $syMin; $cy <= $syMax; ++$cy){
					if(($this->sectionDanger($cx, $cy, $cz) & PathBlockTypes::FIRE) !== 0){
						return true;
					}
				}
			}
		}
		return false;
	}

	private function findPathPhp(int $sx, int $sy, int $sz, int $tx, int $ty, int $tz, float $acceptDistance) : ?Path{
		$this->resetCaches($sx, $sy, $sz);
		$this->lastVisited = 0;

		if(abs($tx - $sx) >= self::OFFSET - 2 || abs($ty - $sy) >= self::OFFSET - 2 || abs($tz - $sz) >= self::OFFSET - 2){
			return null;
		}

		//mobs standing on the edge of a block or mid-jump: start from the block below if this one has no floor
		if(!$this->isStandable($sx, $sy, $sz)){
			if($this->isStandable($sx, $sy - 1, $sz)){
				$sy--;
			}elseif($this->footprint > 1){
				//a wide mob is in several footprints at once: the one centred closest to it may lack a floor or be against
				//a wall while the one next to it is fine
				foreach(self::DIRECTIONS as $i => [$dx, $dz]){
					if($i < 4 && $this->isStandable($sx + $dx, $sy, $sz + $dz)){
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

	private function resetCaches(int $sx, int $sy, int $sz) : void{
		$this->releaseCaches();
		$this->minY = $this->world->getMinY();
		$this->maxY = $this->world->getMaxY();
		$this->originX = $sx;
		$this->originY = $sy;
		$this->originZ = $sz;
	}

	private function updateDangerRowBits() : void{
		$mask = $this->nearBlockMask | $this->nearCostMask;
		$this->dangerRowMasks = $mask !== 0 && ($mask & ~PathBlockTypes::NEAR_DANGER) === 0;
		$whole = (1 << ($this->footprint + 2)) - 1;
		$sides = 1 | (1 << ($this->footprint + 1));
		$this->blockRowBits = PathRecomputeWatch::dangerRowBits($this->nearBlockMask, $whole);
		$this->costRowBits = PathRecomputeWatch::dangerRowBits($this->nearCostMask, $whole);
		$this->blockSideBits = PathRecomputeWatch::dangerRowBits($this->nearBlockMask, $sides);
		$this->costSideBits = PathRecomputeWatch::dangerRowBits($this->nearCostMask, $sides);
	}

	private function releaseCaches() : void{
		$this->types = [];
		$this->fitsCache = [];
		$this->standableCache = [];
		$this->cellFitsCache = [];
		$this->nearCache = [];
		$this->costCache = [];
		$this->sectionDanger = [];
		$this->sectionLayers = [];
		$this->sectionRows = [];
		$this->chunks = [];
	}

	/**
	 * Returns the Y of the node the mob reaches walking from (x, y, z) to the column (nx, nz), or null if it can't go
	 * there.
	 */
	private function findNeighbourY(int $x, int $y, int $z, int $nx, int $nz) : ?int{
		if($this->isStandable($nx, $y, $nz)){
			return $y;
		}
		$footprint = $this->footprint;
		if($footprint === 1){
			return $this->findNeighbourYOneBlock($x, $y, $z, $nx, $nz);
		}
		$obstacle = false;
		for($i = 0; $i < $footprint; ++$i){
			for($j = 0; $j < $footprint; ++$j){
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
			for($i = 0; $i < $footprint; ++$i){
				for($j = 0; $j < $footprint; ++$j){
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
			for($i = 0; $i < $footprint; ++$i){
				for($j = 0; $j < $footprint; ++$j){
					$t = $this->type($nx + $i, $ny, $nz + $j);
					if(($t & PathBlockTypes::PASSABLE) === 0 || ($t & $this->dangerMask) !== 0){
						return null;
					}
				}
			}
		}
		return null;
	}

	/**
	 * findNeighbourY() for a mob one block wide (most searches), when it can't stand at the same level.
	 */
	private function findNeighbourYOneBlock(int $x, int $y, int $z, int $nx, int $nz) : ?int{
		$feet = $this->type($nx, $y, $nz);
		if(($feet & (PathBlockTypes::PASSABLE | PathBlockTypes::LOW)) === 0){
			//obstacle: jump on it if it's at most one block tall and there's room above our head to jump
			if(($feet & PathBlockTypes::TALL) !== 0 || ($feet & $this->dangerMask) !== 0){
				return null;
			}
			$above = $this->type($x, $y + $this->headBlocks + 1, $z);
			if(($above & PathBlockTypes::PASSABLE) === 0){
				return null;
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
			$t = $this->type($nx, $ny, $nz);
			if(($t & PathBlockTypes::PASSABLE) === 0 || ($t & $this->dangerMask) !== 0){
				return null;
			}
		}
		return null;
	}

	/** Native search failures reported so far (only the first few are logged). */
	private static int $nativeFailures = 0;

	/**
	 * Logs a failed native search with the chunk sections around it that the native search could not read.
	 */
	private function reportNativeFailure(\RuntimeException $e, int $sx, int $sz, int $tx, int $tz) : void{
		if(++self::$nativeFailures > 5){
			return;
		}
		$problems = [];
		$blockLayers = new \ReflectionProperty(SubChunk::class, "blockLayers");
		for($cx = (min($sx, $tx) >> Chunk::COORD_BIT_SIZE) - 1; $cx <= (max($sx, $tx) >> Chunk::COORD_BIT_SIZE) + 1; ++$cx){
			for($cz = (min($sz, $tz) >> Chunk::COORD_BIT_SIZE) - 1; $cz <= (max($sz, $tz) >> Chunk::COORD_BIT_SIZE) + 1; ++$cz){
				$chunk = $this->world->getChunk($cx, $cz);
				if($chunk === null){
					continue;
				}
				foreach($chunk->getSubChunks() as $y => $subChunk){
					$layers = $blockLayers->getValue($subChunk);
					if(!is_array($layers)){
						$problems[] = "chunk $cx,$cz section $y: layers are " . get_debug_type($layers);
						continue;
					}
					foreach(Utils::promoteKeys($layers) as $i => $layer){
						if(!$layer instanceof PalettedBlockArray){
							$problems[] = "chunk $cx,$cz section $y: layer $i is " . get_debug_type($layer);
						}
					}
				}
			}
		}
		\GlobalLogger::get()->warning("Native path search failed in world " . $this->world->getFolderName() . " ($sx,$sz -> $tx,$tz): " . $e->getMessage() . "; using the PHP search. " .
			(count($problems) > 0 ? "Unreadable sections: " . implode("; ", array_slice($problems, 0, 10)) : "All sections around look valid."));
	}

	/** Native A* of chunkutils2 >= 0.3.7 (one per thread), null with older extensions. */
	private static ?NativePathFinder $native = null;
	private static bool $nativeChecked = false;
	/** Whether findPath() of the native search has the optional parameters of chunkutils2 0.3.11 */
	private static bool $nativeHasOptionsDetected = false;
	private static bool $nativeOptionsEnabled = true;
	private static bool $nativeEnabled = true;

	private static function nativeFinder() : ?NativePathFinder{
		if(!self::$nativeChecked){
			self::$nativeChecked = true;
			if(class_exists(NativePathFinder::class)){
				self::$native = new NativePathFinder();
				//the extra parameters are optional and trailing: count them rather than trusting version numbers
				$class = new \ReflectionClass(NativePathFinder::class);
				self::$nativeHasOptionsDetected = $class->getMethod("findPath")->getNumberOfParameters() >= 24 && $class->hasMethod("setStateDanger");
			}
		}
		return self::$nativeEnabled ? self::$native : null;
	}

	/**
	 * Whether the native search takes the footprint, danger masks, liquids and danger margin (chunkutils2 0.3.11): older
	 * ones only run searches which fit {@link PathFinder::fitsLegacyNativeSearch()}.
	 */
	private static function nativeHasOptions() : bool{
		return self::$nativeHasOptionsDetected && self::$nativeOptionsEnabled;
	}

	/**
	 * @internal for tests and benchmarks: false makes every search run in PHP
	 */
	public static function setNativeEnabled(bool $enabled) : void{
		self::$nativeEnabled = $enabled;
	}

	/**
	 * @internal for tests: false makes the native search of chunkutils2 0.3.11 run like the older ones (one block wide,
	 * without the navigation options)
	 */
	public static function setNativeOptionsEnabled(bool $enabled) : void{
		self::$nativeOptionsEnabled = $enabled;
	}

	/**
	 * @internal for tests: whether searches run natively (null: in PHP), and whether with all the options
	 * @phpstan-return array{bool, bool}
	 */
	public static function getNativeSupport() : array{
		return [self::nativeFinder() !== null, self::nativeHasOptions()];
	}

	/**
	 * Whether a native search of chunkutils2 0.3.7 to 0.3.10 (no footprint, danger masks, liquids, danger margin or
	 * heuristic weight) still makes sense for this mob: one block wide (see {@link PathFinder::getEffectiveFootprint()}),
	 * not walking on lava, nothing extra to avoid. It then avoids every DANGER block (also the ones some mobs walk or wade
	 * through, like lava for zombified piglins, as before; powder snow, which goats avoid, is DANGER too; when it finds
	 * no way and there is fire around which the mob may cross, the PHP search looks again) without a danger margin, and finds the shortest paths; the few mobs which need more (striders, breezes) search
	 * in PHP until the server runs a newer chunkutils2. Searching for common mobs like zombified piglins in PHP would make
	 * them several times dearer than today.
	 *
	 * @internal for tests
	 */
	public function fitsLegacyNativeSearch() : bool{
		return $this->footprint === 1 && $this->floorMask === PathBlockTypes::FLOOR && ($this->dangerMask & PathBlockTypes::TRAPDOOR) === 0;
	}

	/**
	 * Same search as findPath() (same paths, same visited nodes), run natively by chunkutils2. Chunk sections are
	 * handed over when the search reaches a chunk; block states met for the first time are classified here
	 * (PathBlockTypes, at the position where the search meets them, like the PHP search) and the search runs again.
	 */
	private function findPathNative(NativePathFinder $native, int $sx, int $sy, int $sz, int $tx, int $ty, int $tz, float $acceptDistance) : ?Path{
		//the PHP caches start empty for this search, like findPath() leaves them before searching
		$this->resetCaches($sx, $sy, $sz);

		$world = $this->world;
		//the native search reads the sections it needs from the SubChunks (layer 0, or the empty block ID)
		$columns = static fn(int $chunkX, int $chunkZ) : ?array => $world->getChunk($chunkX, $chunkZ)?->getSubChunks();
		while(true){
			if(self::nativeHasOptions()){
				$result = $native->findPath($columns, $this->minY, $this->maxY, $this->headBlocks, $this->maxDrop, $this->canSwim, $this->avoidWater, $this->maxNodes, $sx, $sy, $sz, $tx, $ty, $tz, $acceptDistance,
					$this->footprint, $this->dangerMask, $this->floorMask, $this->liquidMask, $this->liquidCost, $this->nearBlockMask, $this->nearCostMask, $this->nearCost, $this->heuristicWeight);
			}else{
				$result = $native->findPath($columns, $this->minY, $this->maxY, $this->headBlocks, $this->maxDrop, $this->canSwim, $this->avoidWater, $this->maxNodes, $sx, $sy, $sz, $tx, $ty, $tz, $acceptDistance);
			}
			if($result[0] === -2){
				//states in the palette of a section the danger margin looks at, without danger flags yet
				for($i = 1, $count = count($result); $i < $count; ++$i){
					$native->setStateDanger($result[$i], PathBlockTypes::dangerOfState($result[$i]));
				}
				continue;
			}
			if($result[0] !== -1){
				break;
			}
			[, $stateId, $x, $y, $z] = $result;
			$native->setStateFlags($stateId, PathBlockTypes::ofState($world, $stateId, $x, $y, $z));
		}
		$this->lastVisited = $result[1];
		if($result[0] === 0){
			return null;
		}
		$nodes = [];
		for($i = 2, $count = count($result); $i < $count; $i += 3){
			$nodes[] = [$result[$i], $result[$i + 1], $result[$i + 2]];
		}
		return new Path($nodes, $result[0] === 2, $tx, $ty, $tz);
	}

	private function heuristic(int $x, int $y, int $z, int $tx, int $ty, int $tz) : float{
		$dx = $tx - $x;
		$dy = $ty - $y;
		$dz = $tz - $z;
		return sqrt($dx * $dx + $dy * $dy + $dz * $dz);
	}
}
