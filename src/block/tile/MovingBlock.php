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

use pocketmine\block\Block;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\block\BlockStateDeserializeException;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\world\format\io\GlobalBlockStateHandlers;

/**
 * Block entity of a block being moved by a piston ("MovingBlock" in Bedrock). It sits at the block's destination while
 * the piston arm moves, and holds the moved block and its block entity.
 *
 * NBT (Minecraft Wiki "Bedrock Edition level format/Block entity format", PowerNukkitX BlockEntityMovingBlock, GeyserMC
 * PistonBlockEntity::buildMovingBlockTag): movingBlock and movingBlockExtra (block state compounds, main and liquid
 * layer), movingEntity (the moved block entity, optional), pistonPosX/Y/Z (int, the piston base), expanding (byte).
 *
 * @see \pocketmine\block\MovingBlock
 */
class MovingBlock extends Spawnable{
	private const TAG_MOVING_BLOCK = "movingBlock"; //compound
	private const TAG_MOVING_BLOCK_EXTRA = "movingBlockExtra"; //compound
	private const TAG_MOVING_ENTITY = "movingEntity"; //compound
	private const TAG_PISTON_POS_X = "pistonPosX"; //int
	private const TAG_PISTON_POS_Y = "pistonPosY"; //int
	private const TAG_PISTON_POS_Z = "pistonPosZ"; //int
	private const TAG_EXPANDING = "expanding"; //byte
	private const TAG_IS_MOVABLE = "isMovable"; //byte

	private ?Block $movingBlock = null;
	private ?Vector3 $pistonPos = null;
	private bool $expanding = true;
	/** Saved NBT of the moved block entity */
	private ?CompoundTag $movingEntity = null;
	/** Network NBT of the moved block entity, sent to clients so that they render it while it moves; not saved */
	private ?CompoundTag $movingEntitySpawnData = null;

	public function readSaveData(CompoundTag $nbt) : void{
		$this->movingBlock = null;
		if(($blockTag = $nbt->getCompoundTag(self::TAG_MOVING_BLOCK)) !== null){
			try{
				$stateData = GlobalBlockStateHandlers::getUpgrader()->upgradeBlockStateNbt($blockTag);
				$stateId = GlobalBlockStateHandlers::getDeserializer()->deserialize($stateData);
				$this->movingBlock = RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId);
			}catch(BlockStateDeserializeException){
				//unknown block: it will be lost when the movement ends
			}
		}
		$this->pistonPos = $nbt->getTag(self::TAG_PISTON_POS_X) !== null ? new Vector3(
			$nbt->getInt(self::TAG_PISTON_POS_X),
			$nbt->getInt(self::TAG_PISTON_POS_Y, 0),
			$nbt->getInt(self::TAG_PISTON_POS_Z, 0)
		) : null;
		$this->expanding = $nbt->getByte(self::TAG_EXPANDING, 1) !== 0;
		$this->movingEntity = $nbt->getCompoundTag(self::TAG_MOVING_ENTITY);
		$this->movingEntitySpawnData = null;
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$serializer = GlobalBlockStateHandlers::getSerializer();
		$nbt->setTag(self::TAG_MOVING_BLOCK, $serializer->serialize(($this->movingBlock ?? VanillaBlocks::AIR())->getStateId())->toNbt());
		$nbt->setTag(self::TAG_MOVING_BLOCK_EXTRA, $serializer->serialize(VanillaBlocks::AIR()->getStateId())->toNbt());
		$this->writeCommonData($nbt);
		if($this->movingEntity !== null){
			$nbt->setTag(self::TAG_MOVING_ENTITY, clone $this->movingEntity);
		}
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$translator = TypeConverter::getInstance()->getBlockTranslator();
		$nbt->setTag(self::TAG_MOVING_BLOCK, $translator->internalIdToNetworkStateData(($this->movingBlock ?? VanillaBlocks::AIR())->getStateId())->toNbt());
		$nbt->setTag(self::TAG_MOVING_BLOCK_EXTRA, $translator->internalIdToNetworkStateData(VanillaBlocks::AIR()->getStateId())->toNbt());
		$this->writeCommonData($nbt);
		$movingEntity = $this->movingEntitySpawnData ?? $this->movingEntity;
		if($movingEntity !== null){
			$nbt->setTag(self::TAG_MOVING_ENTITY, clone $movingEntity);
		}
	}

	protected function addAdditionalSpawnDataForProtocol(CompoundTag $nbt, TypeConverter $converter) : void{
		$translator = $converter->getBlockTranslator();
		$nbt->setTag(self::TAG_MOVING_BLOCK, $translator->internalIdToNetworkStateData(($this->movingBlock ?? VanillaBlocks::AIR())->getStateId())->toNbt());
		$nbt->setTag(self::TAG_MOVING_BLOCK_EXTRA, $translator->internalIdToNetworkStateData(VanillaBlocks::AIR()->getStateId())->toNbt());
	}

	private function writeCommonData(CompoundTag $nbt) : void{
		$pistonPos = $this->pistonPos ?? new Vector3(0, -1, 0);
		$nbt->setInt(self::TAG_PISTON_POS_X, $pistonPos->getFloorX());
		$nbt->setInt(self::TAG_PISTON_POS_Y, $pistonPos->getFloorY());
		$nbt->setInt(self::TAG_PISTON_POS_Z, $pistonPos->getFloorZ());
		$nbt->setByte(self::TAG_EXPANDING, $this->expanding ? 1 : 0);
		$nbt->setByte(self::TAG_IS_MOVABLE, 1);
	}

	public function getMovingBlock() : ?Block{
		return $this->movingBlock !== null ? clone $this->movingBlock : null;
	}

	public function getPistonPos() : ?Vector3{
		return $this->pistonPos;
	}

	public function isExpanding() : bool{
		return $this->expanding;
	}

	/**
	 * Returns the saved NBT of the moved block entity, if any.
	 */
	public function getMovingEntity() : ?CompoundTag{
		return $this->movingEntity !== null ? clone $this->movingEntity : null;
	}

	public function setMovement(Block $block, Vector3 $pistonPos, bool $expanding, ?CompoundTag $movingEntity, ?CompoundTag $movingEntitySpawnData) : void{
		$this->movingBlock = clone $block;
		$this->pistonPos = $pistonPos->floor();
		$this->expanding = $expanding;
		$this->movingEntity = $movingEntity;
		$this->movingEntitySpawnData = $movingEntitySpawnData;
		$this->clearSpawnCompoundCache();
	}
}
