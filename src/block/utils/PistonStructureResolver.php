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
use pocketmine\block\HoneyBlock;
use pocketmine\block\Slime;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function array_merge;
use function array_slice;
use function count;

/**
 * Finds the blocks moved and destroyed by a piston: the line of blocks in front of it, plus the blocks dragged along
 * by slime and honey blocks. Same algorithm as vanilla's piston structure resolver (also used by PowerNukkitX), with
 * Bedrock's limit of 12 moved blocks.
 */
final class PistonStructureResolver{
	public const MAX_PUSHED_BLOCKS = 12;

	private int $pushDirection;
	private Vector3 $startPos;
	private Vector3 $headPos;

	/**
	 * @var Vector3[] blocks to move, in the order vanilla moves them
	 * @phpstan-var list<Vector3>
	 */
	private array $toPush = [];
	/**
	 * @var int[] world block hash => true
	 * @phpstan-var array<int, true>
	 */
	private array $toPushIndex = [];
	/**
	 * @var Vector3[]
	 * @phpstan-var list<Vector3>
	 */
	private array $toDestroy = [];

	public function __construct(
		private World $world,
		private Vector3 $pistonPos,
		private int $pistonFacing,
		private bool $extending
	){
		$this->pistonPos = $pistonPos->floor();
		$this->headPos = $this->pistonPos->getSide($pistonFacing);
		if($extending){
			$this->pushDirection = $pistonFacing;
			$this->startPos = $this->headPos;
		}else{
			$this->pushDirection = Facing::opposite($pistonFacing);
			$this->startPos = $this->pistonPos->getSide($pistonFacing, 2);
		}
	}

	public function getPushDirection() : int{
		return $this->pushDirection;
	}

	/**
	 * @return Vector3[]
	 * @phpstan-return list<Vector3>
	 */
	public function getBlocksToPush() : array{
		return $this->toPush;
	}

	/**
	 * @return Vector3[]
	 * @phpstan-return list<Vector3>
	 */
	public function getBlocksToDestroy() : array{
		return $this->toDestroy;
	}

	private function blockAt(Vector3 $pos) : Block{
		if(!$this->extending && $pos->equals($this->headPos)){
			//the head of a retracting piston is removed before blocks are pulled
			return VanillaBlocks::AIR();
		}
		return $this->world->getBlockAt($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
	}

	private static function isAir(Block $block) : bool{
		return $block->getTypeId() === BlockTypeIds::AIR;
	}

	private static function isSticky(Block $block) : bool{
		return $block instanceof Slime || $block instanceof HoneyBlock;
	}

	private static function canStickToEachOther(Block $a, Block $b) : bool{
		if(($a instanceof HoneyBlock && $b instanceof Slime) || ($a instanceof Slime && $b instanceof HoneyBlock)){
			return false;
		}
		return self::isSticky($a) || self::isSticky($b);
	}

	/**
	 * @param int $movementDirection direction the block would move in
	 * @param int $lineDirection     direction of the line being followed: blocks which can only be pushed only move
	 *                               when it is the piston's facing (not when pulled or dragged sideways)
	 */
	private function isPushable(Block $block, Vector3 $pos, int $movementDirection, bool $allowDestroy, int $lineDirection) : bool{
		$y = $pos->getFloorY();
		if($y < $this->world->getMinY() || $y >= $this->world->getMaxY()){
			return false;
		}
		if(self::isAir($block)){
			return true;
		}
		if(($movementDirection === Facing::DOWN && $y === $this->world->getMinY()) || ($movementDirection === Facing::UP && $y === $this->world->getMaxY() - 1)){
			return false;
		}
		if(!$block->getBreakInfo()->isBreakable()){
			return false;
		}
		return match($block->getPistonPushReaction()){
			PistonPushReaction::NORMAL => true,
			PistonPushReaction::PUSH_ONLY => $lineDirection === $this->pistonFacing,
			PistonPushReaction::BREAK => $allowDestroy,
			PistonPushReaction::BLOCK => false,
		};
	}

	/**
	 * Computes the blocks to move and destroy. Returns false if the piston can't move them (immovable block in the
	 * way, more than 12 blocks, or the edge of the world).
	 */
	public function resolve() : bool{
		$this->toPush = [];
		$this->toPushIndex = [];
		$this->toDestroy = [];

		$block = $this->blockAt($this->startPos);
		if(!$this->isPushable($block, $this->startPos, $this->pushDirection, false, $this->pistonFacing)){
			if($this->extending && $block->getPistonPushReaction() === PistonPushReaction::BREAK && $block->getBreakInfo()->isBreakable()){
				$this->toDestroy[] = $this->startPos;
				return true;
			}
			return false;
		}
		if(!$this->addBlockLine($this->startPos, $this->pushDirection)){
			return false;
		}
		for($i = 0; $i < count($this->toPush); ++$i){
			$pos = $this->toPush[$i];
			if(self::isSticky($this->blockAt($pos)) && !$this->addBranchingBlocks($pos)){
				return false;
			}
		}

		//never move blocks into or out of unloaded terrain
		foreach($this->getBlocksToPush() as $pos){
			if(!$this->isLoaded($pos) || !$this->isLoaded($pos->getSide($this->pushDirection))){
				return false;
			}
		}
		foreach($this->getBlocksToDestroy() as $pos){
			if(!$this->isLoaded($pos)){
				return false;
			}
		}
		return true;
	}

	private function isLoaded(Vector3 $pos) : bool{
		return $this->world->isInWorld($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()) &&
			$this->world->isChunkLoaded($pos->getFloorX() >> Chunk::COORD_BIT_SIZE, $pos->getFloorZ() >> Chunk::COORD_BIT_SIZE);
	}

	private function contains(Vector3 $pos) : bool{
		return isset($this->toPushIndex[World::blockHash($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())]);
	}

	private function add(Vector3 $pos) : void{
		$this->toPush[] = $pos;
		$this->toPushIndex[World::blockHash($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())] = true;
	}

	private function addBlockLine(Vector3 $origin, int $lineDirection) : bool{
		$block = $this->blockAt($origin);
		if(self::isAir($block)){
			return true;
		}
		if(!$this->isPushable($block, $origin, $this->pushDirection, false, $lineDirection)){
			return true;
		}
		if($origin->equals($this->pistonPos) || $this->contains($origin)){
			return true;
		}

		//blocks dragged behind a sticky block
		$back = Facing::opposite($this->pushDirection);
		$length = 1;
		if($length + count($this->toPush) > self::MAX_PUSHED_BLOCKS){
			return false;
		}
		while(self::isSticky($block)){
			$pos = $origin->getSide($back, $length);
			$previous = $block;
			$block = $this->blockAt($pos);
			if(
				self::isAir($block) ||
				!self::canStickToEachOther($previous, $block) ||
				!$this->isPushable($block, $pos, $this->pushDirection, false, $back) ||
				$pos->equals($this->pistonPos)
			){
				break;
			}
			if(++$length + count($this->toPush) > self::MAX_PUSHED_BLOCKS){
				return false;
			}
		}

		$added = 0;
		for($k = $length - 1; $k >= 0; --$k){
			$this->add($origin->getSide($back, $k));
			++$added;
		}

		//blocks in front, pushed by this line
		for($k = 1; ; ++$k){
			$pos = $origin->getSide($this->pushDirection, $k);
			$index = $this->indexOf($pos);
			if($index !== null){
				$this->reorderListAtCollision($added, $index);
				for($m = 0; $m <= $index + $added; ++$m){
					$movedPos = $this->toPush[$m];
					if(self::isSticky($this->blockAt($movedPos)) && !$this->addBranchingBlocks($movedPos)){
						return false;
					}
				}
				return true;
			}

			$block = $this->blockAt($pos);
			if(self::isAir($block)){
				return true;
			}
			if(!$this->isPushable($block, $pos, $this->pushDirection, true, $this->pushDirection) || $pos->equals($this->pistonPos)){
				return false;
			}
			if($block->getPistonPushReaction() === PistonPushReaction::BREAK){
				$this->toDestroy[] = $pos;
				return true;
			}
			if(count($this->toPush) >= self::MAX_PUSHED_BLOCKS){
				return false;
			}
			$this->add($pos);
			++$added;
		}
	}

	private function indexOf(Vector3 $pos) : ?int{
		if(!$this->contains($pos)){
			return null;
		}
		foreach($this->toPush as $i => $p){
			if($p->equals($pos)){
				return $i;
			}
		}
		return null;
	}

	/**
	 * Moves the last $count blocks (a line which ran into blocks already in the list) before the block at $index, so
	 * that blocks are always moved before the blocks behind them.
	 */
	private function reorderListAtCollision(int $count, int $index) : void{
		$total = count($this->toPush);
		$this->toPush = array_merge(
			array_slice($this->toPush, 0, $index),
			array_slice($this->toPush, $total - $count, $count),
			array_slice($this->toPush, $index, $total - $count - $index)
		);
	}

	private function addBranchingBlocks(Vector3 $from) : bool{
		$block = $this->blockAt($from);
		$pushAxis = Facing::axis($this->pushDirection);
		foreach(Facing::ALL as $face){
			if(Facing::axis($face) === $pushAxis){
				continue;
			}
			$pos = $from->getSide($face);
			if(self::canStickToEachOther($this->blockAt($pos), $block) && !$this->addBlockLine($pos, $face)){
				return false;
			}
		}
		return true;
	}
}
