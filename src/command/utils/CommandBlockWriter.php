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

namespace pocketmine\command\utils;

use pocketmine\block\Block;
use pocketmine\block\Conduit;
use pocketmine\block\Door;
use pocketmine\block\DoublePlant;
use pocketmine\block\PointedDripstone;
use pocketmine\block\SmallDripleaf;
use pocketmine\block\TallSeagrass;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\block\utils\Waterlogged;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\particle\BlockBreakParticle;
use pocketmine\world\World;

/**
 * Sets blocks for /setblock, /fill and similar commands the way Bedrock's commands do:
 * - only the main block layer is written: a waterloggable block set where there is water (a water source, or water
 *   already in the liquid layer) keeps that water in the liquid layer;
 * - the block is set as given and gets a block update like its neighbours (blocks that check their support or take
 *   their state from their surroundings do), except pointed dripstone, which keeps the thickness it is given like
 *   BDS;
 * - "destroy" breaks the old block first, with its drops and break particles.
 */
final class CommandBlockWriter{

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns whether the chunk of the position exists (loading it if needed): blocks can't be set in terrain that
	 * was never generated.
	 */
	public static function prepareChunk(World $world, int $x, int $z) : bool{
		return $world->isChunkLoaded($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE) ||
			$world->loadChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE) !== null;
	}

	/**
	 * Sets a block. Returns false if the block was already there (nothing changed), unless $destroy is true.
	 * The chunk must be loaded (see {@link prepareChunk()}).
	 */
	public static function set(World $world, int $x, int $y, int $z, Block $block, bool $destroy = false) : bool{
		$old = $world->getBlockAt($x, $y, $z, addToCache: false);
		$newStateId = $block->getStateId();
		if(!$destroy && $old->getStateId() === $newStateId){
			return false;
		}
		if($destroy && $old->getTypeId() !== VanillaBlocks::AIR()->getTypeId()){
			self::destroy($world, $old, $x, $y, $z);
		}

		//a waterloggable block put in a water source keeps the water in the liquid layer, like vanilla
		$oldStateId = $old->getStateId();
		if($block instanceof Conduit && !$block->isWaterlogged() && LiquidLayer::isWaterSourceState($oldStateId)){
			//the conduit keeps its water itself
			$block = (clone $block)->setWaterlogged(true);
			$newStateId = $block->getStateId();
		}elseif(!$block instanceof Waterlogged && LiquidLayer::isWaterSourceState($oldStateId) && LiquidLayer::canContainWater($newStateId)){
			$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
			$chunk?->getSubChunk($y >> SubChunk::COORD_BIT_SIZE)->setLiquidLayerStateId(
				$x & SubChunk::COORD_MASK, $y & SubChunk::COORD_MASK, $z & SubChunk::COORD_MASK,
				VanillaBlocks::WATER()->getStillForm()->getStateId()
			);
		}

		$world->setBlockAt($x, $y, $z, $block, false);
		$world->updateAllLight($x, $y, $z);
		foreach(Facing::OFFSET as [$dx, $dy, $dz]){
			$world->scheduleNeighbourBlockUpdateAt($x + $dx, $y + $dy, $z + $dz);
		}
		//Bedrock runs the placed block's own placement logic (e.g. potent sulfur takes the state of its geyser), but
		//pointed dripstone only reshapes when a neighbour changes: set as frustum, it stays frustum
		if(!$block instanceof PointedDripstone){
			$world->scheduleNeighbourBlockUpdateAt($x, $y, $z);
		}
		$redstone = $world->getRedstone();
		if($redstone !== null){
			if($oldStateId !== $newStateId){
				$redstone->notifyObservers($x, $y, $z);
			}
			$redstone->onBlockSet($x, $y, $z, $oldStateId, $newStateId);
		}
		return true;
	}

	/**
	 * Sets a block like {@link set()}; the lower half of a two-block plant or door also gets its upper half above it,
	 * like /setblock.
	 */
	public static function setWithUpperHalf(World $world, int $x, int $y, int $z, Block $block, bool $destroy = false) : bool{
		$changed = self::set($world, $x, $y, $z, $block, $destroy);
		$upper = self::getUpperHalf($block);
		if($upper !== null && $world->isInWorld($x, $y + 1, $z)){
			$changed = self::set($world, $x, $y + 1, $z, $upper, $destroy) || $changed;
		}
		return $changed;
	}

	/**
	 * Returns the upper half of a two-block block (doors, double plants, small dripleaf, tall seagrass) given its
	 * lower half, or null.
	 */
	public static function getUpperHalf(Block $block) : ?Block{
		if(($block instanceof Door || $block instanceof DoublePlant || $block instanceof SmallDripleaf || $block instanceof TallSeagrass) && !$block->isTop()){
			return (clone $block)->setTop(true);
		}
		return null;
	}

	private static function destroy(World $world, Block $old, int $x, int $y, int $z) : void{
		$pos = new Vector3($x, $y, $z);
		$center = $pos->add(0.5, 0.5, 0.5);
		$world->getTile($pos)?->onBlockDestroyed();
		foreach($old->getDrops(VanillaItems::AIR()) as $drop){
			$world->dropItem($center, $drop);
		}
		$world->addParticle($center, new BlockBreakParticle($old));
	}
}
