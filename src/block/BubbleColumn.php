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

namespace pocketmine\block;

use pocketmine\block\utils\AlwaysWaterloggedTrait;
use pocketmine\block\utils\SupportType;
use pocketmine\block\utils\Waterlogged;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function count;
use function max;
use function min;

/**
 * Bubble column (https://minecraft.wiki/w/Bubble_Column): fills the water sources above soul sand (pushes up) or a
 * magma block (drags down, "whirlpool"). Like vanilla it holds a water source (kept in the liquid layer) and is
 * created, extended and removed by {@link BubbleColumn::updateColumn()}: water sources over soul sand/magma run it
 * when they are updated, column blocks run it 5 ticks after a neighbour change.
 *
 * The bubbles and sounds are drawn by the client from the block; players are moved by their own client, other
 * entities are pushed here with the vanilla velocities.
 */
class BubbleColumn extends Transparent implements Waterlogged{
	use AlwaysWaterloggedTrait {
		onScheduledUpdate as private flowWater;
	}

	/** Ticks between a neighbour change and the column check (vanilla CHECK_PERIOD). */
	public const CHECK_DELAY = 5;

	/** Velocity change per tick inside an upward column, and its cap (blocks/tick). */
	public const PUSH_UP_ACCELERATION = 0.06;
	public const PUSH_UP_MAX_SPEED = 0.7;
	/** Same for the topmost block of an upward column (nothing above it): entities are thrown out of the water. */
	public const SURFACE_PUSH_UP_ACCELERATION = 0.1;
	public const SURFACE_PUSH_UP_MAX_SPEED = 1.8;
	/** Velocity change per tick inside a whirlpool, and its cap (downwards). */
	public const DRAG_DOWN_ACCELERATION = 0.03;
	public const DRAG_DOWN_MAX_SPEED = 0.3;
	public const SURFACE_DRAG_DOWN_MAX_SPEED = 0.9;

	protected bool $dragDown = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->bool($this->dragDown);
	}

	/**
	 * Whether the column drags entities down (above a magma block) instead of pushing them up (above soul sand).
	 */
	public function isDragDown() : bool{ return $this->dragDown; }

	/** @return $this */
	public function setDragDown(bool $dragDown) : self{
		$this->dragDown = $dragDown;
		return $this;
	}

	public function canBeReplaced() : bool{
		return true;
	}

	public function isSolid() : bool{
		return false;
	}

	protected function recalculateCollisionBoxes() : array{
		return [];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	public function isAffectedBySilkTouch() : bool{
		return false;
	}

	public function getPickedItem(bool $addUserData = false) : Item{
		return VanillaBlocks::WATER()->asItem();
	}

	public function onNearbyBlockChange() : void{
		$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, self::CHECK_DELAY);
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		self::updateColumn($world, $this->position, $world->getBlock($this->position->getSide(Facing::DOWN)));
		if($world->getBlock($this->position) instanceof BubbleColumn){
			//the water it holds still flows into empty neighbours
			$this->flowWater();
		}
	}

	/**
	 * Same as water, plus the push of the column. Players are left to their client, which simulates the column
	 * itself (pushing them here as well would double the effect).
	 */
	public function onEntityInside(Entity $entity) : bool{
		VanillaBlocks::WATER()->onEntityInside($entity);
		if(!$entity instanceof Player){
			$above = $this->position->getWorld()->getBlock($this->position->getSide(Facing::UP));
			$surface = count($above->getCollisionBoxes()) === 0 && !$above instanceof Liquid && !($above instanceof Waterlogged && $above->isWaterlogged());
			$motion = $entity->getMotion();
			$entity->setMotion(new Vector3($motion->x, self::pushedVerticalSpeed($motion->y, $this->dragDown, $surface), $motion->z));
		}
		return true;
	}

	/**
	 * Vertical speed (blocks/tick) of an entity after one tick in a bubble column block. $surface is true for the top
	 * block of a column, which has nothing (no collision, no liquid) above it.
	 */
	public static function pushedVerticalSpeed(float $speed, bool $dragDown, bool $surface) : float{
		if($dragDown){
			return max($surface ? -self::SURFACE_DRAG_DOWN_MAX_SPEED : -self::DRAG_DOWN_MAX_SPEED, $speed - self::DRAG_DOWN_ACCELERATION);
		}
		return $surface ?
			min(self::SURFACE_PUSH_UP_MAX_SPEED, $speed + self::SURFACE_PUSH_UP_ACCELERATION) :
			min(self::PUSH_UP_MAX_SPEED, $speed + self::PUSH_UP_ACCELERATION);
	}

	/**
	 * Whether a bubble column can take the place of this block: another column, or a water source (not one held by a
	 * waterlogged block, which stops the column like in vanilla).
	 */
	public static function canOccupy(Block $block) : bool{
		return $block instanceof BubbleColumn || ($block instanceof Water && $block->isSource());
	}

	/**
	 * Whether this block below a water source starts a bubble column (soul sand or a magma block).
	 */
	public static function startsColumn(Block $block) : bool{
		return $block instanceof SoulSand || $block instanceof Magma;
	}

	/**
	 * What a column-capable block should be with the given block below it: the column continues the one below, soul
	 * sand starts an upward column, a magma block a whirlpool, and anything else turns a column back into still water.
	 */
	public static function getColumnState(Block $below, Block $occupied) : Block{
		if($below instanceof BubbleColumn){
			return VanillaBlocks::BUBBLE_COLUMN()->setDragDown($below->dragDown);
		}
		if($below instanceof SoulSand){
			return VanillaBlocks::BUBBLE_COLUMN()->setDragDown(false);
		}
		if($below instanceof Magma){
			return VanillaBlocks::BUBBLE_COLUMN()->setDragDown(true);
		}
		if($occupied instanceof BubbleColumn){
			return VanillaBlocks::WATER()->getStillForm();
		}
		return $occupied;
	}

	/**
	 * Vanilla's BubbleColumnBlock.updateColumn(): sets the block at $pos (if a column can be there) to what the block
	 * below calls for, and the same state in every column-capable block above it, up to the first one that is not.
	 * Creates, extends, flips and removes columns.
	 */
	public static function updateColumn(World $world, Vector3 $pos, Block $below) : void{
		$x = $pos->getFloorX();
		$y = $pos->getFloorY();
		$z = $pos->getFloorZ();
		$occupied = $world->getBlockAt($x, $y, $z);
		if(!self::canOccupy($occupied)){
			return;
		}
		$state = self::getColumnState($below, $occupied);
		$maxY = $world->getMaxY();
		for(; $y < $maxY; ++$y){
			$current = $y === $pos->getFloorY() ? $occupied : $world->getBlockAt($x, $y, $z);
			if(!self::canOccupy($current)){
				break;
			}
			if($current->getStateId() !== $state->getStateId()){
				$world->setBlockAt($x, $y, $z, $state);
			}
		}
	}

	/**
	 * Random tick of soul sand and magma blocks: builds the column above them if it is missing (or has the wrong
	 * direction). Vanilla builds it from block updates only, but chunks generated before bubble columns existed (or
	 * by generators that don't place them) have plain water there that nothing would update.
	 */
	/**
	 * @var bool[] block state ID => whether a block of this state can hold a bubble column (see canOccupy())
	 * @phpstan-var array<int, bool>
	 */
	private static array $canOccupyByStateId = [];

	public static function onColumnSourceRandomTick(Block $source) : void{
		$position = $source->getPosition();
		$world = $position->getWorld();
		$x = $position->getFloorX();
		$y = $position->getFloorY();
		$z = $position->getFloorZ();
		if($y + 1 >= $world->getMaxY()){
			return;
		}
		//random ticks of every soul sand and magma block: most have no water above, check the state ID before
		//building the block (canOccupy() only depends on the block state)
		$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		if($chunk === null){
			return;
		}
		$aboveStateId = $chunk->getBlockStateId($x & Chunk::COORD_MASK, $y + 1, $z & Chunk::COORD_MASK);
		if(!(self::$canOccupyByStateId[$aboveStateId] ??= self::canOccupy(RuntimeBlockStateRegistry::getInstance()->fromStateId($aboveStateId)))){
			return;
		}
		$abovePos = $position->getSide(Facing::UP);
		$above = $world->getBlock($abovePos);
		if(self::canOccupy($above) && self::getColumnState($source, $above)->getStateId() !== $above->getStateId()){
			self::updateColumn($world, $abovePos, $source);
		}
	}

	/**
	 * Called by water sources when they are updated: starts a column if one is over soul sand or a magma block.
	 */
	public static function onWaterSourceUpdate(Water $water) : void{
		$position = $water->getPosition();
		$world = $position->getWorld();
		if(!$position->isValid() || $position->getFloorY() <= $world->getMinY()){
			return;
		}
		$below = $world->getBlock($position->getSide(Facing::DOWN));
		if(self::startsColumn($below)){
			self::updateColumn($world, $position, $below);
		}
	}
}
