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
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\World;
use function array_fill;
use function count;
use function intdiv;
use function spl_object_id;
use function unpack;

/**
 * The paths being followed in a world, by the chunks they cross, so that a block change near a path makes its mob
 * look for a new one (vanilla PathNavigation.shouldRecomputePath(), which Java asks every navigating mob of the level
 * on every block change). A block change in a chunk without paths costs one lookup; changes which don't matter to
 * paths (crops growing, water flowing, redstone power) are skipped by comparing the block flags.
 *
 * It also keeps, for the path searches in the world, which columns of chunk sections have something a danger margin
 * looks at (see {@link PathRecomputeWatch::getDangerRows()}).
 *
 * @internal
 */
final class PathRecomputeWatch{
	/** Flags whose change makes paths through the block or next to it stale. */
	private const RELEVANT_FLAGS = PathBlockTypes::PASSABLE | PathBlockTypes::FLOOR | PathBlockTypes::LOW | PathBlockTypes::DANGER |
		PathBlockTypes::WATER | PathBlockTypes::TALL | PathBlockTypes::CLOSED_DOOR | PathBlockTypes::LAVA | PathBlockTypes::FIRE |
		PathBlockTypes::HURTS | PathBlockTypes::DAMAGE_BLOCK | PathBlockTypes::POWDER_SNOW | PathBlockTypes::TRAPDOOR;

	/**
	 * @var PathNavigator[][] chunk hash => navigator ID => navigator
	 * @phpstan-var array<int, array<int, PathNavigator>>
	 */
	private array $byChunk = [];
	/**
	 * @var int[][] navigator ID => chunk hashes it is registered in
	 * @phpstan-var array<int, list<int>>
	 */
	private array $chunksOf = [];

	/**
	 * Where the blocks with {@link PathBlockTypes::NEAR_DANGER} kinds of danger are in chunk sections (see
	 * {@link PathRecomputeWatch::getDangerRows()}), by block layer: forgotten with the layer (chunk unloaded or replaced)
	 * and when such a block appears or goes away in its section through the world (blocks written straight into a loaded
	 * chunk, bypassing World::setBlockAt(), aren't seen until the chunk is loaded again).
	 * @phpstan-var \WeakMap<PalettedBlockArray, array<int, int>>
	 */
	private \WeakMap $dangerRows;

	/**
	 * The sections of a chunk with a door mobs open in their palette (see {@link PathRecomputeWatch::getDoorSections()}),
	 * forgotten with the chunk (unloaded or replaced). A door placed through the world sets its bit; a door going away
	 * leaves it set (palettes keep unused states too), which only costs a PHP search.
	 * @phpstan-var \WeakMap<Chunk, int>
	 */
	private \WeakMap $doorSections;

	public function __construct(
		private World $world
	){
		$this->dangerRows = new \WeakMap();
		$this->doorSections = new \WeakMap();
	}

	/**
	 * Returns the sections of a chunk which have a door mobs open (not iron, see
	 * {@link PathBlockTypes::isOpenableDoorState()}) in their palette, one bit per section from
	 * {@link Chunk::MIN_SUBCHUNK_INDEX}. Worked out once per chunk, so that path searches of mobs which open or break
	 * doors (see PathFinder::hasDoorsAround()) test a few bits instead of walking palettes on every search.
	 */
	public function getDoorSections(Chunk $chunk) : int{
		return $this->doorSections[$chunk] ?? ($this->doorSections[$chunk] = self::computeDoorSections($chunk));
	}

	private static function computeDoorSections(Chunk $chunk) : int{
		$sections = 0;
		foreach($chunk->getSubChunks() as $y => $subChunk){
			$layers = $subChunk->getBlockLayers();
			if(count($layers) === 0){
				continue;
			}
			foreach($layers[0]->getPalette() as $stateId){
				if(PathBlockTypes::isOpenableDoorState($stateId)){
					$sections |= 1 << ($y - Chunk::MIN_SUBCHUNK_INDEX);
					break;
				}
			}
		}
		return $sections;
	}

	/**
	 * Returns where the blocks with {@link PathBlockTypes::NEAR_DANGER} kinds of danger are in a chunk section (its block
	 * layer): 256 rows along X, by Y << 4 | Z, each with 16 bits by X for each kind of danger (LAVA from bit 0, FIRE from
	 * bit 16, HURTS from bit 32, DAMAGE_BLOCK from bit 48). Worked out once from the layer (its palette and packed words:
	 * no block by block reads where nothing is dangerous) and kept while it stays the same, so that the danger margin of
	 * path searches tests a few bits instead of reading the blocks next to each node.
	 *
	 * @return int[]
	 * @phpstan-return array<int, int>
	 */
	public function getDangerRows(PalettedBlockArray $layer) : array{
		return $this->dangerRows[$layer] ??= self::computeDangerRows($layer);
	}

	/**
	 * Returns the bits of the danger rows (see {@link PathRecomputeWatch::getDangerRows()}) for the given kinds of danger,
	 * with $bits (bits by X, from X = 0) in each of their fields.
	 */
	public static function dangerRowBits(int $kinds, int $bits) : int{
		return
			(($kinds & PathBlockTypes::LAVA) !== 0 ? $bits : 0) |
			(($kinds & PathBlockTypes::FIRE) !== 0 ? $bits << 16 : 0) |
			(($kinds & PathBlockTypes::HURTS) !== 0 ? $bits << 32 : 0) |
			(($kinds & PathBlockTypes::DAMAGE_BLOCK) !== 0 ? $bits << 48 : 0);
	}

	/**
	 * @return int[]
	 * @phpstan-return array<int, int>
	 */
	public static function computeDangerRows(PalettedBlockArray $layer) : array{
		$rows = array_fill(0, 256, 0);
		/** @phpstan-var array<int, int> $kinds palette index => danger row bits of X = 0 */
		$kinds = [];
		foreach($layer->getPalette() as $index => $stateId){
			$danger = PathBlockTypes::dangerOfState($stateId) & PathBlockTypes::NEAR_DANGER;
			if($danger !== 0){
				$bits = $layer->getBitsPerBlock();
				if($bits === 0){
					//the whole section is this dangerous block state
					return array_fill(0, 256, self::dangerRowBits($danger, 0xffff));
				}
				$kinds[$index] = self::dangerRowBits($danger, 1);
			}
		}
		if(count($kinds) === 0){
			return $rows;
		}
		$bits = $layer->getBitsPerBlock();
		$perWord = intdiv(32, $bits);
		$fieldMask = (1 << $bits) - 1;
		$lows = 0;
		for($k = 0; $k < $perWord; ++$k){
			$lows |= 1 << ($k * $bits);
		}
		$highs = $lows << ($bits - 1);
		/** @phpstan-var array<int, int> $words */
		$words = unpack("V*", $layer->getWordArray());
		foreach($kinds as $index => $kindBits){
			//xored with the index repeated, a word has a zero field only if one of its blocks is that index (SWAR test)
			$pattern = $lows * $index;
			foreach($words as $w => $word){
				$v = $word ^ $pattern;
				if((($v - $lows) & ~$v & $highs) === 0){
					continue;
				}
				//blocks are packed by X, then Z, then Y (index X << 8 | Z << 4 | Y), words are numbered from 1
				for($k = 0, $block = ($w - 1) * $perWord; $k < $perWord && $block < 4096; ++$k, ++$block){
					if((($word >> ($k * $bits)) & $fieldMask) === $index){
						$rows[(($block & 15) << 4) | (($block >> 4) & 15)] |= $kindBits << ($block >> 8);
					}
				}
			}
		}
		return $rows;
	}

	/**
	 * Registers the path of a navigator, which crosses the blocks from (minX, minZ) to (maxX, maxZ), replacing any
	 * earlier registration.
	 */
	public function register(PathNavigator $navigator, int $minX, int $minZ, int $maxX, int $maxZ) : void{
		$id = spl_object_id($navigator);
		$this->unregisterId($id);
		$hashes = [];
		for($cx = $minX >> Chunk::COORD_BIT_SIZE, $cxMax = $maxX >> Chunk::COORD_BIT_SIZE; $cx <= $cxMax; ++$cx){
			for($cz = $minZ >> Chunk::COORD_BIT_SIZE, $czMax = $maxZ >> Chunk::COORD_BIT_SIZE; $cz <= $czMax; ++$cz){
				$hash = World::chunkHash($cx, $cz);
				$this->byChunk[$hash][$id] = $navigator;
				$hashes[] = $hash;
			}
		}
		$this->chunksOf[$id] = $hashes;
	}

	public function unregister(PathNavigator $navigator) : void{
		$this->unregisterId(spl_object_id($navigator));
	}

	private function unregisterId(int $id) : void{
		if(!isset($this->chunksOf[$id])){
			return;
		}
		foreach($this->chunksOf[$id] as $hash){
			unset($this->byChunk[$hash][$id]);
			if(count($this->byChunk[$hash]) === 0){
				unset($this->byChunk[$hash]);
			}
		}
		unset($this->chunksOf[$id]);
	}

	/**
	 * Number of paths registered (for tests).
	 */
	public function getCount() : int{
		return count($this->chunksOf);
	}

	/**
	 * Called by the world when the block state at the given position changed.
	 */
	public function onBlockChanged(int $x, int $y, int $z, int $oldStateId, int $newStateId) : void{
		if(count($this->dangerRows) > 0 &&
			((PathBlockTypes::dangerOfState($oldStateId) & PathBlockTypes::NEAR_DANGER) === 0) !== ((PathBlockTypes::dangerOfState($newStateId) & PathBlockTypes::NEAR_DANGER) === 0)
		){
			$layer = $this->world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)?->getSubChunk($y >> Chunk::COORD_BIT_SIZE)->getBlockLayers()[0] ?? null;
			if($layer !== null){
				unset($this->dangerRows[$layer]);
			}
		}
		if(count($this->doorSections) > 0 && PathBlockTypes::isOpenableDoorState($newStateId)){
			$chunk = $this->world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
			if($chunk !== null && isset($this->doorSections[$chunk])){
				$this->doorSections[$chunk] |= 1 << (($y >> Chunk::COORD_BIT_SIZE) - Chunk::MIN_SUBCHUNK_INDEX);
			}
		}
		$navigators = $this->byChunk[World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)] ?? null;
		if($navigators === null){
			return;
		}
		//the flags of the state, as the path finder reads them (water of the liquid layer aside)
		$newFlags = PathBlockTypes::ofState($this->world, $newStateId, $x, $y, $z);
		$oldFlags = PathBlockTypes::cachedFlagsOfState($oldStateId);
		//flags that changed, all of them if the old state was never classified
		$changed = $oldFlags !== null ? ($oldFlags ^ $newFlags) & self::RELEVANT_FLAGS : self::RELEVANT_FLAGS;
		if($changed === 0){
			return;
		}
		foreach($navigators as $navigator){
			$navigator->onBlockChanged($this->world, $x, $y, $z, $newFlags, $changed);
		}
	}
}
