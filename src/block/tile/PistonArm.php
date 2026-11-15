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

namespace pocketmine\block\tile;

use pocketmine\math\Vector3;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use function count;
use function max;
use function min;

/**
 * Block entity of pistons and sticky pistons ("PistonArm" in Bedrock). It holds the movement state of the arm; the
 * client animates the arm and the moving blocks from it.
 *
 * NBT (Minecraft Wiki "Bedrock Edition level format/Block entity format", PowerNukkitX BlockEntityPistonArm, GeyserMC
 * PistonBlockEntity): State/NewState (byte, 0 retracted, 1 extending, 2 extended, 3 retracting), Progress and
 * LastProgress (float, 0.0/0.5/1.0), Sticky (byte), AttachedBlocks and BreakBlocks (list of int x, y, z triplets of the
 * moved blocks' starting positions and of the destroyed blocks).
 *
 * @see \pocketmine\block\Piston
 */
class PistonArm extends Spawnable{
	public const STATE_RETRACTED = 0;
	public const STATE_EXTENDING = 1;
	public const STATE_EXTENDED = 2;
	public const STATE_RETRACTING = 3;

	/** Progress made by the arm each game tick (a move takes 2 game ticks). */
	public const PROGRESS_STEP = 0.5;

	private const TAG_STATE = "State"; //byte
	private const TAG_NEW_STATE = "NewState"; //byte
	private const TAG_PROGRESS = "Progress"; //float
	private const TAG_LAST_PROGRESS = "LastProgress"; //float
	private const TAG_STICKY = "Sticky"; //byte
	private const TAG_ATTACHED_BLOCKS = "AttachedBlocks"; //list of int
	private const TAG_BREAK_BLOCKS = "BreakBlocks"; //list of int
	private const TAG_IS_MOVABLE = "isMovable"; //byte

	private int $state = self::STATE_RETRACTED;
	private float $progress = 0.0;
	private float $lastProgress = 0.0;
	private bool $sticky = false;
	/** @var Vector3[] */
	private array $attachedBlocks = [];
	/** @var Vector3[] */
	private array $breakBlocks = [];

	/** Power change waiting for the piston's start delay, not saved. */
	private ?bool $pendingPower = null;
	/** Server tick of the last movement step, not saved. */
	private int $lastMoveTick = -1;

	public function readSaveData(CompoundTag $nbt) : void{
		$state = $nbt->getByte(self::TAG_STATE, self::STATE_RETRACTED);
		$this->state = $state >= self::STATE_RETRACTED && $state <= self::STATE_RETRACTING ? $state : self::STATE_RETRACTED;
		$this->progress = $nbt->getFloat(self::TAG_PROGRESS, $this->state === self::STATE_EXTENDED ? 1.0 : 0.0);
		$this->lastProgress = $nbt->getFloat(self::TAG_LAST_PROGRESS, $this->progress);
		$this->sticky = $nbt->getByte(self::TAG_STICKY, 0) !== 0;
		$this->attachedBlocks = self::readPositions($nbt, self::TAG_ATTACHED_BLOCKS);
		$this->breakBlocks = self::readPositions($nbt, self::TAG_BREAK_BLOCKS);
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->writeData($nbt);
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$this->writeData($nbt);
	}

	private function writeData(CompoundTag $nbt) : void{
		$nbt->setByte(self::TAG_STATE, $this->state);
		$nbt->setByte(self::TAG_NEW_STATE, $this->state);
		$nbt->setFloat(self::TAG_PROGRESS, $this->progress);
		$nbt->setFloat(self::TAG_LAST_PROGRESS, $this->lastProgress);
		$nbt->setByte(self::TAG_STICKY, $this->sticky ? 1 : 0);
		$nbt->setTag(self::TAG_ATTACHED_BLOCKS, self::writePositions($this->attachedBlocks));
		$nbt->setTag(self::TAG_BREAK_BLOCKS, self::writePositions($this->breakBlocks));
		$nbt->setByte(self::TAG_IS_MOVABLE, $this->state === self::STATE_RETRACTED ? 1 : 0);
	}

	/**
	 * @return Vector3[]
	 */
	private static function readPositions(CompoundTag $nbt, string $name) : array{
		$list = $nbt->getListTag($name, IntTag::class);
		if($list === null){
			return [];
		}
		$values = [];
		foreach($list as $tag){
			$values[] = $tag->getValue();
		}
		$result = [];
		for($i = 0; $i + 2 < count($values); $i += 3){
			$result[] = new Vector3($values[$i], $values[$i + 1], $values[$i + 2]);
		}
		return $result;
	}

	/**
	 * @param Vector3[] $positions
	 * @phpstan-return ListTag<IntTag>
	 */
	private static function writePositions(array $positions) : ListTag{
		$tags = [];
		foreach($positions as $pos){
			$tags[] = new IntTag($pos->getFloorX());
			$tags[] = new IntTag($pos->getFloorY());
			$tags[] = new IntTag($pos->getFloorZ());
		}
		return new ListTag($tags, NBT::TAG_Int);
	}

	public function getState() : int{ return $this->state; }

	public function isMoving() : bool{
		return $this->state === self::STATE_EXTENDING || $this->state === self::STATE_RETRACTING;
	}

	public function isExtending() : bool{ return $this->state === self::STATE_EXTENDING; }

	public function getProgress() : float{ return $this->progress; }

	public function getLastProgress() : float{ return $this->lastProgress; }

	public function isSticky() : bool{ return $this->sticky; }

	public function setSticky(bool $sticky) : void{
		$this->sticky = $sticky;
		$this->clearSpawnCompoundCache();
	}

	/**
	 * Returns the starting positions of the blocks moved by the current movement.
	 *
	 * @return Vector3[]
	 */
	public function getAttachedBlocks() : array{ return $this->attachedBlocks; }

	/**
	 * @return Vector3[]
	 */
	public function getBreakBlocks() : array{ return $this->breakBlocks; }

	public function getPendingPower() : ?bool{ return $this->pendingPower; }

	public function setPendingPower(?bool $pendingPower) : void{
		$this->pendingPower = $pendingPower;
	}

	public function getLastMoveTick() : int{ return $this->lastMoveTick; }

	public function setLastMoveTick(int $tick) : void{
		$this->lastMoveTick = $tick;
	}

	/**
	 * Starts a movement: the arm's progress goes from 0 to 1 (extending) or 1 to 0 (retracting).
	 *
	 * @param Vector3[] $attachedBlocks
	 * @param Vector3[] $breakBlocks
	 */
	public function startMove(bool $extending, array $attachedBlocks, array $breakBlocks) : void{
		$this->state = $extending ? self::STATE_EXTENDING : self::STATE_RETRACTING;
		$this->progress = $this->lastProgress = $extending ? 0.0 : 1.0;
		$this->attachedBlocks = $attachedBlocks;
		$this->breakBlocks = $breakBlocks;
		$this->clearSpawnCompoundCache();
	}

	/**
	 * Advances the arm by one game tick. Returns true once the arm reached the end of its movement.
	 */
	public function step() : bool{
		$this->lastProgress = $this->progress;
		if($this->state === self::STATE_EXTENDING){
			$this->progress = min(1.0, $this->progress + self::PROGRESS_STEP);
			$done = $this->progress >= 1.0;
		}elseif($this->state === self::STATE_RETRACTING){
			$this->progress = max(0.0, $this->progress - self::PROGRESS_STEP);
			$done = $this->progress <= 0.0;
		}else{
			$done = true;
		}
		$this->clearSpawnCompoundCache();
		return $done;
	}

	/**
	 * Ends the current movement (or forces the given resting state). LastProgress keeps the value of the last step,
	 * like vanilla, so that clients finish animating the last half of the movement.
	 */
	public function finishMove(bool $extended) : void{
		$this->state = $extended ? self::STATE_EXTENDED : self::STATE_RETRACTED;
		$this->progress = $extended ? 1.0 : 0.0;
		$this->attachedBlocks = [];
		$this->breakBlocks = [];
		$this->clearSpawnCompoundCache();
	}

	/**
	 * Sends the arm's current state to the players who can see it, so that their clients animate it.
	 */
	public function broadcastState() : void{
		$world = $this->position->getWorld();
		$world->broadcastPacketToViewers($this->position, BlockActorDataPacket::create(
			BlockPosition::fromVector3($this->position),
			$this->getSerializedSpawnCompound()
		));
	}
}
