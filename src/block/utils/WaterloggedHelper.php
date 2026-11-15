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

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Conduit;
use pocketmine\block\Liquid;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Water;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;

/**
 * Shared behaviour of {@link Waterlogged} blocks, and of the blocks holding water in the liquid layer (see
 * {@link LiquidLayer}).
 */
final class WaterloggedHelper{

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns whether the block is a still water source, the only thing underwater plants can be placed into.
	 */
	public static function isWaterSource(Block $block) : bool{
		return ($block instanceof Water && $block->isSource()) || ($block instanceof Waterlogged && $block->isWaterlogged());
	}

	/**
	 * Returns the water that a waterlogged block holds (a {@link Waterlogged} block, or water in the liquid layer), as a
	 * block of the given liquid type, or null if it holds none. Used by liquids to treat waterlogged neighbours as
	 * sources.
	 */
	public static function getHeldLiquid(Block $block, Liquid $liquidType) : ?Liquid{
		if($liquidType instanceof Water && !$block instanceof Liquid && $block->getTypeId() !== BlockTypeIds::AIR && LiquidLayer::holdsWater($block)){
			return VanillaBlocks::WATER();
		}
		return null;
	}

	/**
	 * Returns whether a water bucket or a dispenser can put water into the block (taken from the world): a waterloggable
	 * block (see {@link LiquidLayer::canContainWater()}) which holds no water yet. Like Java's
	 * LiquidBlockContainer.canPlaceLiquid(), {@link Waterlogged} blocks which are always waterlogged (kelp, seagrass)
	 * never take water this way.
	 *
	 * @internal
	 */
	public static function canWaterlog(Block $block) : bool{
		if($block instanceof Conduit){
			return !$block->isWaterlogged();
		}
		if($block instanceof Waterlogged || $block instanceof Liquid || !$block->getPosition()->isValid()){
			return false;
		}
		$position = $block->getPosition();
		return
			LiquidLayer::canContainWater($block->getStateId()) &&
			!LiquidLayer::hasLayerWaterAt($position->getWorld(), $position->getFloorX(), $position->getFloorY(), $position->getFloorZ());
	}

	/**
	 * Returns whether an empty bucket or a dispenser can take the water held by the block (taken from the world), like
	 * Java's BucketPickup: a waterloggable block holding water in the liquid layer, or a waterlogged conduit.
	 *
	 * @internal
	 */
	public static function canDrain(Block $block) : bool{
		if($block instanceof Conduit){
			return $block->isWaterlogged();
		}
		return !$block instanceof Waterlogged && $block->getPosition()->isValid() && LiquidLayer::holdsWater($block);
	}

	/**
	 * Returns the block which a water bucket used on $blockClicked waterlogs, or null if it places its water normally.
	 * Like Java's BucketItem, the clicked block takes the water if it can, else the block on the clicked face.
	 *
	 * @internal
	 */
	public static function getWaterBucketTarget(Block $blockClicked, Block $blockReplace) : ?Block{
		if(self::canWaterlog($blockClicked)){
			return $blockClicked;
		}
		if(self::canWaterlog($blockReplace)){
			return $blockReplace;
		}
		return null;
	}

	/**
	 * Puts a water source into the block (taken from the world, see {@link WaterloggedHelper::canWaterlog()}). The
	 * block itself doesn't change; the clients are sent its liquid layer and the water starts flowing out of it.
	 *
	 * @internal
	 */
	public static function waterlog(Block $block) : void{
		self::setHeldWater($block, true);
	}

	/**
	 * Takes the water held by the block (taken from the world, see {@link WaterloggedHelper::canDrain()}), leaving the
	 * block dry.
	 *
	 * @internal
	 */
	public static function drain(Block $block) : void{
		self::setHeldWater($block, false);
	}

	private static function setHeldWater(Block $block, bool $water) : void{
		$position = $block->getPosition();
		$world = $position->getWorld();
		if($block instanceof Conduit){
			$world->setBlock($position, $block->setWaterlogged($water));
			return;
		}
		$x = $position->getFloorX();
		$y = $position->getFloorY();
		$z = $position->getFloorZ();
		$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		if($chunk === null){
			return;
		}
		$chunk->getSubChunk($y >> SubChunk::COORD_BIT_SIZE)->setLiquidLayerStateId(
			$x & SubChunk::COORD_MASK,
			$y & SubChunk::COORD_MASK,
			$z & SubChunk::COORD_MASK,
			//vanilla stores still water there (minecraft:water, not flowing_water)
			$water ? VanillaBlocks::WATER()->getStillForm()->getStateId() : Block::EMPTY_STATE_ID
		);
		//setting the same block again keeps the liquid layer as it is now (the block can hold water), and does the rest:
		//caches, the block and its liquid layer sent to the clients, neighbour updates (which let the water flow)
		$world->setBlockAt($x, $y, $z, $block);
	}

	/**
	 * Replaces a broken waterlogged block with the water it held.
	 */
	public static function leaveWater(Block $block) : void{
		$position = $block->getPosition();
		$world = $position->getWorld();
		if(($tile = $world->getTile($position)) !== null){
			$tile->onBlockDestroyed();
		}
		$world->setBlock($position, VanillaBlocks::WATER());
	}

	/**
	 * Schedules the water held by the block to spread into its neighbours, like a water source would.
	 */
	public static function scheduleFlow(Block $block) : void{
		$block->getPosition()->getWorld()->scheduleDelayedBlockUpdate($block->getPosition(), VanillaBlocks::WATER()->tickRate());
	}

	/**
	 * Lets the water held by the block flow out of it, as if a water source stood in its place.
	 */
	public static function flow(Block $block) : void{
		$position = $block->getPosition();
		$water = VanillaBlocks::WATER();
		$water->position($position->getWorld(), $position->getFloorX(), $position->getFloorY(), $position->getFloorZ());
		$water->onScheduledUpdate();
	}
}
