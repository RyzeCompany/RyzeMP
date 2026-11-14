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

namespace pocketmine\block\utils;

use pocketmine\block\VanillaBlocks;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\World;
use function in_array;

/**
 * Allow and deny blocks (Bedrock world builder blocks, https://minecraft.wiki/w/Allow and /w/Deny) decide whether
 * players may place and break blocks anywhere above them: the highest allow or deny block under a position wins.
 * Deny forbids building there; allow lets adventure mode players build there. Operators in creative mode (vanilla
 * "world builders") are never restricted.
 *
 * Only worlds known to contain allow or deny blocks are checked column by column: a world is marked when one is placed,
 * or when the first build check in a chunk finds one in that chunk's palettes (each loaded chunk is scanned at most
 * once, so chunk loading and worlds without these blocks pay nearly nothing).
 *
 * @internal
 */
final class BuildPermissionBlocks{

	/** @phpstan-var \WeakMap<World, true>|null */
	private static ?\WeakMap $worlds = null;
	/**
	 * Chunks of unmarked worlds whose palettes were already scanned (a chunk loaded again is a new object).
	 * @phpstan-var \WeakMap<Chunk, bool>|null
	 */
	private static ?\WeakMap $scannedChunks = null;
	private static int $allowStateId = -1;
	private static int $denyStateId = -1;

	private function __construct(){
		//NOOP
	}

	private static function loadStateIds() : void{
		if(self::$allowStateId === -1){
			self::$allowStateId = VanillaBlocks::ALLOW()->getStateId();
			self::$denyStateId = VanillaBlocks::DENY()->getStateId();
		}
	}

	/**
	 * Marks the world as containing allow or deny blocks.
	 */
	public static function markWorld(World $world) : void{
		self::$worlds ??= new \WeakMap();
		self::$worlds[$world] = true;
	}

	public static function hasBlocks(World $world) : bool{
		return self::$worlds !== null && isset(self::$worlds[$world]);
	}

	/**
	 * Returns whether allow or deny blocks may apply in the chunk: always true in a marked world, otherwise the
	 * chunk's palettes are scanned (once per loaded chunk) and the world is marked if they hold one.
	 */
	public static function chunkHasBlocks(World $world, Chunk $chunk) : bool{
		if(self::hasBlocks($world)){
			return true;
		}
		$scanned = self::$scannedChunks ??= new \WeakMap();
		if(isset($scanned[$chunk])){
			return $scanned[$chunk];
		}
		self::loadStateIds();
		$found = false;
		foreach($chunk->getSubChunks() as $subChunk){
			if(!$subChunk->isEmptyFast() && self::paletteHasBlocks($subChunk)){
				$found = true;
				break;
			}
		}
		$scanned[$chunk] = $found;
		if($found){
			self::markWorld($world);
		}
		return $found;
	}

	private static function paletteHasBlocks(SubChunk $subChunk) : bool{
		$layers = $subChunk->getBlockLayers();
		if(!isset($layers[0])){
			return false;
		}
		$palette = $layers[0]->getPalette();
		return in_array(self::$allowStateId, $palette, true) || in_array(self::$denyStateId, $palette, true);
	}

	/**
	 * Returns what the allow and deny blocks say about building at the given position: true if the highest one under
	 * it is an allow block, false if it is a deny block, null if there is none.
	 */
	public static function getRule(World $world, int $x, int $y, int $z) : ?bool{
		$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		if($chunk === null || !self::chunkHasBlocks($world, $chunk)){
			return null;
		}
		self::loadStateIds();
		$localX = $x & Chunk::COORD_MASK;
		$localZ = $z & Chunk::COORD_MASK;
		$minY = $world->getMinY();
		$y = $y - 1;
		while($y >= $minY){
			$subChunkY = $y >> SubChunk::COORD_BIT_SIZE;
			$subChunk = $chunk->getSubChunk($subChunkY);
			$bottom = $subChunkY << SubChunk::COORD_BIT_SIZE;
			if(self::paletteHasBlocks($subChunk)){
				for(; $y >= $bottom && $y >= $minY; --$y){
					$stateId = $subChunk->getBlockStateId($localX, $y & SubChunk::COORD_MASK, $localZ);
					if($stateId === self::$allowStateId){
						return true;
					}
					if($stateId === self::$denyStateId){
						return false;
					}
				}
			}
			$y = $bottom - 1;
		}
		return null;
	}

	/**
	 * Returns whether the player may build at the position according to the allow and deny blocks (true: allowed
	 * even in adventure mode, false: denied), or null when they don't apply.
	 */
	public static function canBuild(World $world, Player $player, Vector3 $pos) : ?bool{
		return self::canBuildAt($world, $player, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
	}

	/**
	 * Same as {@link self::canBuild()} with block coordinates.
	 */
	public static function canBuildAt(World $world, Player $player, int $x, int $y, int $z) : ?bool{
		$rule = self::getRule($world, $x, $y, $z);
		return $rule === null || GameMasterBlocks::canUse($player) ? null : $rule;
	}
}
