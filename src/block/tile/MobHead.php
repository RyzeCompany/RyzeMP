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

use pocketmine\block\utils\MobHeadType;
use pocketmine\data\bedrock\MobHeadTypeIdMap;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use function floor;

/**
 * @deprecated
 * @see \pocketmine\block\MobHead
 */
class MobHead extends Spawnable{

	private const TAG_SKULL_TYPE = "SkullType"; //TAG_Byte
	private const TAG_ROTATION = "Rotation"; //TAG_Float (1.10+, represents yaw in degrees)
	private const TAG_ROT = "Rot"; //TAG_Byte (legacy, pre-1.10, 0-15)
	private const TAG_MOUTH_MOVING = "MouthMoving"; //TAG_Byte (legacy name)
	private const TAG_DOING_ANIMATION = "DoingAnimation"; //TAG_Byte (BDS 1.26.52)
	private const TAG_MOUTH_TICK_COUNT = "MouthTickCount"; //TAG_Int

	private MobHeadType $mobHeadType = MobHeadType::SKELETON;
	private int $rotation = 0;
	/** Whether a powered dragon or piglin head moves its mouth (the client animates it) */
	private bool $doingAnimation = false;
	/** Ticks the mouth moved before the current animation, like BDS counts them */
	private int $mouthTickCount = 0;
	/** Server tick the current animation started at */
	private int $animationStartTick = 0;

	public function readSaveData(CompoundTag $nbt) : void{
		if(($skullTypeTag = $nbt->getTag(self::TAG_SKULL_TYPE)) instanceof ByteTag){
			$mobHeadType = MobHeadTypeIdMap::getInstance()->fromId($skullTypeTag->getValue());
			if($mobHeadType === null){
				throw new SavedDataLoadingException("Invalid skull type tag value " . $skullTypeTag->getValue());
			}
			$this->mobHeadType = $mobHeadType;
		}

		if($nbt->getTag(self::TAG_ROTATION) instanceof FloatTag){
			$yaw = $nbt->getFloat(self::TAG_ROTATION, 0.0);
			$this->rotation = ((int) floor(($yaw * 16 / 360) + 0.5)) & 0xf;
		}else{
			$rotation = $nbt->getByte(self::TAG_ROT, 0);
			if($rotation >= 0 && $rotation <= 15){
				$this->rotation = $rotation;
			}
		}
		$this->doingAnimation = $nbt->getByte(self::TAG_DOING_ANIMATION, $nbt->getByte(self::TAG_MOUTH_MOVING, 0)) !== 0;
		$this->mouthTickCount = $nbt->getInt(self::TAG_MOUTH_TICK_COUNT, 0);
		$this->animationStartTick = $this->position->isValid() ? $this->position->getWorld()->getServer()->getTick() : 0;
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$nbt->setByte(self::TAG_SKULL_TYPE, MobHeadTypeIdMap::getInstance()->toId($this->mobHeadType));
		$nbt->setFloat(self::TAG_ROTATION, $this->rotation * 360.0 / 16.0);
		$this->writeAnimation($nbt);
	}

	private function writeAnimation(CompoundTag $nbt) : void{
		$nbt->setByte(self::TAG_DOING_ANIMATION, $this->doingAnimation ? 1 : 0);
		$nbt->setInt(self::TAG_MOUTH_TICK_COUNT, $this->getMouthTickCount());
	}

	public function setMobHeadType(MobHeadType $type) : void{
		$this->mobHeadType = $type;
	}

	public function getMobHeadType() : MobHeadType{
		return $this->mobHeadType;
	}

	public function getRotation() : int{
		return $this->rotation;
	}

	public function setRotation(int $rotation) : void{
		$this->rotation = $rotation;
	}

	/**
	 * Returns whether the head moves its mouth (dragon and piglin heads while powered).
	 */
	public function isDoingAnimation() : bool{
		return $this->doingAnimation;
	}

	/**
	 * Starts or stops the mouth animation. The tick count isn't ticked: it is worked out from the server tick when sent.
	 */
	public function setDoingAnimation(bool $doingAnimation, int $currentTick) : void{
		if($doingAnimation === $this->doingAnimation){
			return;
		}
		if($doingAnimation){
			$this->animationStartTick = $currentTick;
		}else{
			$this->mouthTickCount += $currentTick - $this->animationStartTick;
		}
		$this->doingAnimation = $doingAnimation;
		$this->clearSpawnCompoundCache();
	}

	private function getMouthTickCount() : int{
		if(!$this->doingAnimation || !$this->position->isValid()){
			return $this->mouthTickCount;
		}
		return $this->mouthTickCount + $this->position->getWorld()->getServer()->getTick() - $this->animationStartTick;
	}

	/**
	 * Sends the block entity data to the players viewing the head, like BDS does when the animation starts or stops.
	 */
	public function broadcastState() : void{
		$this->position->getWorld()->broadcastPacketToViewers($this->position, BlockActorDataPacket::create(
			BlockPosition::fromVector3($this->position),
			$this->getSerializedSpawnCompound()
		));
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$nbt->setByte(self::TAG_SKULL_TYPE, MobHeadTypeIdMap::getInstance()->toId($this->mobHeadType));
		$nbt->setFloat(self::TAG_ROTATION, $this->rotation * 360.0 / 16.0);
		$this->writeAnimation($nbt);
	}
}
