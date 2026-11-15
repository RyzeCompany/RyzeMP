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
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\StringTag;
use function in_array;
use function mb_strlen;
use function preg_match;

/**
 * Block entity of a jigsaw block (Bedrock ID "JigsawBlock", same fields as BDS 1.26.52): the name of this connection,
 * the name of the connection it attaches to, the template pool to pick the next piece from, the block it turns into
 * once the structure is generated, the joint type (vertical connections: "rollable" or "aligned") and the placement
 * and selection priorities.
 */
class Jigsaw extends Spawnable{
	public const TAG_NAME = "name";
	public const TAG_TARGET = "target";
	public const TAG_TARGET_POOL = "target_pool";
	public const TAG_FINAL_STATE = "final_state";
	public const TAG_JOINT = "joint";
	public const TAG_PLACEMENT_PRIORITY = "placement_priority";
	public const TAG_SELECTION_PRIORITY = "selection_priority";

	public const JOINT_ROLLABLE = "rollable";
	public const JOINT_ALIGNED = "aligned";
	public const EMPTY_NAME = "minecraft:empty";
	public const DEFAULT_FINAL_STATE = "minecraft:air";
	public const MAX_TEXT_LENGTH = 256;

	private string $name = self::EMPTY_NAME;
	private string $target = self::EMPTY_NAME;
	private string $targetPool = self::EMPTY_NAME;
	private string $finalState = self::DEFAULT_FINAL_STATE;
	private string $joint = self::JOINT_ROLLABLE;
	private int $placementPriority = 0;
	private int $selectionPriority = 0;

	public function readSaveData(CompoundTag $nbt) : void{
		$this->readJigsawData($nbt);
	}

	/**
	 * Reads the fields present in the given data (block entity data, or what the jigsaw screen sends). Invalid values
	 * are ignored. Returns whether anything changed.
	 */
	public function readJigsawData(CompoundTag $nbt) : bool{
		$changed = self::readText($nbt, self::TAG_NAME, $this->name);
		$changed = self::readText($nbt, self::TAG_TARGET, $this->target) || $changed;
		$changed = self::readText($nbt, self::TAG_TARGET_POOL, $this->targetPool) || $changed;
		$changed = self::readText($nbt, self::TAG_FINAL_STATE, $this->finalState) || $changed;
		$joint = $nbt->getTag(self::TAG_JOINT);
		if($joint instanceof StringTag && in_array($joint->getValue(), [self::JOINT_ROLLABLE, self::JOINT_ALIGNED], true) && $joint->getValue() !== $this->joint){
			$this->joint = $joint->getValue();
			$changed = true;
		}
		$changed = self::readInt($nbt, self::TAG_PLACEMENT_PRIORITY, $this->placementPriority) || $changed;
		return self::readInt($nbt, self::TAG_SELECTION_PRIORITY, $this->selectionPriority) || $changed;
	}

	private static function readText(CompoundTag $nbt, string $tagName, string &$field) : bool{
		$tag = $nbt->getTag($tagName);
		if(!$tag instanceof StringTag){
			return false;
		}
		$value = $tag->getValue();
		if($value === $field || mb_strlen($value) > self::MAX_TEXT_LENGTH || preg_match('/[\x00-\x1f\x7f]/', $value) !== 0){
			return false;
		}
		$field = $value;
		return true;
	}

	private static function readInt(CompoundTag $nbt, string $tagName, int &$field) : bool{
		$tag = $nbt->getTag($tagName);
		if(!$tag instanceof IntTag || $tag->getValue() === $field){
			return false;
		}
		$field = $tag->getValue();
		return true;
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->writeJigsawData($nbt);
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$this->writeJigsawData($nbt);
	}

	private function writeJigsawData(CompoundTag $nbt) : void{
		$nbt->setString(self::TAG_FINAL_STATE, $this->finalState);
		$nbt->setString(self::TAG_JOINT, $this->joint);
		$nbt->setString(self::TAG_NAME, $this->name);
		$nbt->setInt(self::TAG_PLACEMENT_PRIORITY, $this->placementPriority);
		$nbt->setInt(self::TAG_SELECTION_PRIORITY, $this->selectionPriority);
		$nbt->setString(self::TAG_TARGET, $this->target);
		$nbt->setString(self::TAG_TARGET_POOL, $this->targetPool);
	}

	public function getName() : string{ return $this->name; }

	public function setName(string $name) : void{ $this->name = $name; }

	public function getTarget() : string{ return $this->target; }

	public function setTarget(string $target) : void{ $this->target = $target; }

	public function getTargetPool() : string{ return $this->targetPool; }

	public function setTargetPool(string $targetPool) : void{ $this->targetPool = $targetPool; }

	public function getFinalState() : string{ return $this->finalState; }

	public function setFinalState(string $finalState) : void{ $this->finalState = $finalState; }

	public function getJoint() : string{ return $this->joint; }

	public function setJoint(string $joint) : void{
		if($joint !== self::JOINT_ROLLABLE && $joint !== self::JOINT_ALIGNED){
			throw new \InvalidArgumentException("Joint must be \"" . self::JOINT_ROLLABLE . "\" or \"" . self::JOINT_ALIGNED . "\"");
		}
		$this->joint = $joint;
	}

	public function getPlacementPriority() : int{ return $this->placementPriority; }

	public function setPlacementPriority(int $priority) : void{ $this->placementPriority = $priority; }

	public function getSelectionPriority() : int{ return $this->selectionPriority; }

	public function setSelectionPriority(int $priority) : void{ $this->selectionPriority = $priority; }
}
