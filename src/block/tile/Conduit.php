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

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;

/**
 * Block entity of a conduit. The client animates the conduit from it: "Active" (TAG_Byte) makes the eye open and
 * spin, "Target" (TAG_Long, the actor unique ID of the hostile mob being attacked, -1 for none) draws the attack
 * particles (same tags as PowerNukkitX's BlockEntityConduit).
 *
 * The frame is recomputed every 40 ticks by {@link \pocketmine\block\Conduit}, so the runtime fields below are not
 * saved, except "Active" which is kept so that a reloaded conduit doesn't play its activation sound again.
 */
class Conduit extends Spawnable{
	private const TAG_ACTIVE = "Active"; //TAG_Byte
	private const TAG_TARGET = "Target"; //TAG_Long: unique ID of the attacked mob, -1 for none

	private bool $active = false;
	private int $targetId = -1;
	/** Number of frame blocks found by the last update; not saved */
	private int $frameBlocks = 0;
	/** Server tick of the last update, or -1 if it never ran since the tile was created or loaded */
	private int $lastUpdateTick = -1;
	/** Server tick after which the next short ambient sound may play */
	private int $nextAmbientShortTick = 0;

	public function isActive() : bool{ return $this->active; }

	public function setActive(bool $active) : void{
		if($active !== $this->active){
			$this->active = $active;
			$this->clearSpawnCompoundCache();
		}
	}

	/**
	 * Returns the ID of the hostile mob being attacked, or -1 if none.
	 */
	public function getTargetId() : int{ return $this->targetId; }

	public function setTargetId(int $targetId) : void{
		if($targetId !== $this->targetId){
			$this->targetId = $targetId;
			$this->clearSpawnCompoundCache();
		}
	}

	public function getFrameBlocks() : int{ return $this->frameBlocks; }

	public function setFrameBlocks(int $frameBlocks) : void{ $this->frameBlocks = $frameBlocks; }

	public function getLastUpdateTick() : int{ return $this->lastUpdateTick; }

	public function setLastUpdateTick(int $lastUpdateTick) : void{ $this->lastUpdateTick = $lastUpdateTick; }

	public function getNextAmbientShortTick() : int{ return $this->nextAmbientShortTick; }

	public function setNextAmbientShortTick(int $tick) : void{ $this->nextAmbientShortTick = $tick; }

	/**
	 * Sends the current state (active, target) to the players who can see the conduit.
	 */
	public function broadcastState() : void{
		$this->position->getWorld()->broadcastPacketToViewers($this->position, BlockActorDataPacket::create(
			BlockPosition::fromVector3($this->position),
			$this->getSerializedSpawnCompound()
		));
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->active = $nbt->getByte(self::TAG_ACTIVE, 0) !== 0;
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$nbt->setByte(self::TAG_ACTIVE, $this->active ? 1 : 0);
		$nbt->setLong(self::TAG_TARGET, -1);
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$nbt->setByte(self::TAG_ACTIVE, $this->active ? 1 : 0);
		$nbt->setLong(self::TAG_TARGET, $this->targetId);
	}
}
