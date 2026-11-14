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

use pocketmine\block\BaseCoral;
use pocketmine\block\Block;
use pocketmine\block\Liquid;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\Water;
use pocketmine\data\bedrock\block\BlockStateSerializeException;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\format\SubChunk;
use pocketmine\world\World;

/**
 * Water held in the liquid layer of the subchunk (block layer 1), where vanilla Bedrock keeps the water of waterlogged
 * blocks: stairs, slabs, fences, corals, glow lichen and many more (see {@link WaterloggableBlockNames}). Worlds made
 * by vanilla and the vanilla generator write water there; the server treats it as a water source in the block.
 *
 * Everything is answered from block state IDs read straight from the chunk, with a per-state cache, and the liquid
 * layer is only read for states which can hold water, so ordinary blocks cost a single array lookup.
 */
final class LiquidLayer{
	/** The block state can hold water in the liquid layer. */
	private const CAN_CONTAIN_WATER = 1;
	/** The block itself is water: a water block, or a {@link Waterlogged} block whose state holds water. */
	private const IS_WATER = 2;
	/** A water source block (not falling). */
	private const IS_WATER_SOURCE = 4;
	/** Takes the water of the source it replaces into the liquid layer (corals). */
	private const TAKES_REPLACED_WATER = 8;

	/**
	 * @var int[] state ID => flags
	 * @phpstan-var array<int, int>
	 */
	private static array $flags = [];

	private function __construct(){
		//NOOP
	}

	private static function computeFlags(int $stateId) : int{
		$registry = RuntimeBlockStateRegistry::getInstance();
		if(!$registry->hasStateId($stateId)){
			return 0;
		}
		$block = $registry->fromStateId($stateId);
		$flags = 0;
		if($block instanceof Water){
			$flags |= self::IS_WATER | ($block->isSource() ? self::IS_WATER_SOURCE : 0);
		}elseif($block instanceof Waterlogged && $block->isWaterlogged()){
			$flags |= self::IS_WATER;
		}
		try{
			if(isset(WaterloggableBlockNames::NAMES[GlobalBlockStateHandlers::getSerializer()->serialize($stateId)->getName()])){
				$flags |= self::CAN_CONTAIN_WATER;
			}
		}catch(BlockStateSerializeException){
			//not a vanilla block
		}
		if(($flags & self::CAN_CONTAIN_WATER) !== 0 && $block instanceof BaseCoral){
			//other waterloggable blocks only keep it when they are placed by World::useItemOn(); corals keep it however
			//they are set into water
			$flags |= self::TAKES_REPLACED_WATER;
		}
		return $flags;
	}

	//the methods below read the cache inline: some run for every block change or neighbour update

	/**
	 * Returns whether the block state can hold water in the liquid layer (waterloggable blocks).
	 */
	public static function canContainWater(int $stateId) : bool{
		return ((self::$flags[$stateId] ??= self::computeFlags($stateId)) & self::CAN_CONTAIN_WATER) !== 0;
	}

	/**
	 * Returns whether the block state is water itself: a water block (source or flowing), or a {@link Waterlogged}
	 * block whose state holds water (kelp, seagrass...).
	 */
	public static function isWaterState(int $stateId) : bool{
		return ((self::$flags[$stateId] ??= self::computeFlags($stateId)) & self::IS_WATER) !== 0;
	}

	/**
	 * Returns whether the block state is a water source block.
	 */
	public static function isWaterSourceState(int $stateId) : bool{
		return ((self::$flags[$stateId] ??= self::computeFlags($stateId)) & self::IS_WATER_SOURCE) !== 0;
	}

	/**
	 * Returns whether a block of the given state takes the water of a water source it replaces into the liquid layer.
	 *
	 * @internal
	 */
	public static function takesReplacedWater(int $stateId) : bool{
		return ((self::$flags[$stateId] ??= self::computeFlags($stateId)) & self::TAKES_REPLACED_WATER) !== 0;
	}

	/**
	 * Returns whether the liquid layer of the subchunk holds water at the given coordinates (relative to the subchunk).
	 * This doesn't check that the block in the main layer can hold water.
	 */
	public static function isWaterInSubChunk(SubChunk $subChunk, int $x, int $y, int $z) : bool{
		$stateId = $subChunk->getLiquidLayerStateId($x, $y, $z);
		return $stateId !== Block::EMPTY_STATE_ID && ((self::$flags[$stateId] ??= self::computeFlags($stateId)) & self::IS_WATER) !== 0;
	}

	/**
	 * Returns whether the block at the given coordinates of the subchunk (relative to it) is water or holds water: see
	 * {@link LiquidLayer::hasWaterAt()}.
	 */
	public static function hasWaterInSubChunk(SubChunk $subChunk, int $x, int $y, int $z) : bool{
		$flags = self::$flags[$stateId = $subChunk->getBlockStateId($x, $y, $z)] ??= self::computeFlags($stateId);
		if(($flags & self::IS_WATER) !== 0){
			return true;
		}
		return ($flags & self::CAN_CONTAIN_WATER) !== 0 && self::isWaterInSubChunk($subChunk, $x, $y, $z);
	}

	/**
	 * Returns whether the block at the given position holds water in the liquid layer: a waterloggable block with
	 * water in layer 1. Water blocks themselves and {@link Waterlogged} blocks whose state holds water return false.
	 */
	public static function hasLayerWaterAt(World $world, int $x, int $y, int $z) : bool{
		if(!$world->isInWorld($x, $y, $z)){
			return false;
		}
		$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		if($chunk === null){
			return false;
		}
		$subChunk = $chunk->getSubChunk($y >> SubChunk::COORD_BIT_SIZE);
		$x &= SubChunk::COORD_MASK;
		$y &= SubChunk::COORD_MASK;
		$z &= SubChunk::COORD_MASK;
		//most subchunks have no liquid layer at all: check it before the main layer
		return self::isWaterInSubChunk($subChunk, $x, $y, $z) && self::canContainWater($subChunk->getBlockStateId($x, $y, $z));
	}

	/**
	 * Returns whether there is water at the given position: a water block (source or flowing), a {@link Waterlogged}
	 * block holding water (kelp, seagrass...) or a waterloggable block with water in the liquid layer.
	 */
	public static function hasWaterAt(World $world, int $x, int $y, int $z) : bool{
		if(!$world->isInWorld($x, $y, $z)){
			return false;
		}
		$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		if($chunk === null){
			return false;
		}
		return self::hasWaterInSubChunk($chunk->getSubChunk($y >> SubChunk::COORD_BIT_SIZE), $x & SubChunk::COORD_MASK, $y & SubChunk::COORD_MASK, $z & SubChunk::COORD_MASK);
	}

	/**
	 * Returns whether the block (taken from the world) holds a water source of its own: a waterlogged
	 * {@link Waterlogged} block, or a waterloggable block with water in the liquid layer. Water blocks return false.
	 */
	public static function holdsWater(Block $block) : bool{
		if($block instanceof Waterlogged){
			return $block->isWaterlogged();
		}
		if($block instanceof Liquid){
			return false;
		}
		$position = $block->getPosition();
		return $position->isValid() && self::hasLayerWaterAt($position->getWorld(), $position->getFloorX(), $position->getFloorY(), $position->getFloorZ());
	}

	/**
	 * Returns whether the block (taken from the world) is water or holds water: water blocks, {@link Waterlogged}
	 * blocks holding water and waterloggable blocks with water in the liquid layer.
	 */
	public static function isWater(Block $block) : bool{
		return $block instanceof Water || self::holdsWater($block);
	}

	/**
	 * @internal for tests
	 */
	public static function clearCache() : void{
		self::$flags = [];
	}
}
