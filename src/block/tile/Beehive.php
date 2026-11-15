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

use pocketmine\item\Item;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;

/**
 * Beehive and bee nest block entity, holding the bees inside in the vanilla "Occupants" list (entries
 * {ActorIdentifier, SaveData, TicksLeftToStay}). Bees enter with {@link Beehive::addOccupant()}; the hive block counts
 * their time down and releases them (see {@link \pocketmine\block\Beehive::onScheduledUpdate()}).
 */
class Beehive extends Spawnable{
	public const TAG_OCCUPANTS = "Occupants"; //TAG_List<TAG_Compound>
	public const TAG_SHOULD_SPAWN_BEES = "ShouldSpawnBees"; //TAG_Byte
	public const TAG_ACTOR_IDENTIFIER = "ActorIdentifier"; //TAG_String
	public const TAG_SAVE_DATA = "SaveData"; //TAG_Compound
	public const TAG_TICKS_LEFT = "TicksLeftToStay"; //TAG_Int

	public const MAX_OCCUPANTS = 3;

	private ?ListTag $occupants = null;
	private bool $shouldSpawnBees = false;

	public function hasOccupants() : bool{
		return $this->occupants !== null && $this->occupants->count() > 0;
	}

	public function getOccupantCount() : int{
		return $this->occupants !== null ? $this->occupants->count() : 0;
	}

	public function isFull() : bool{
		return $this->getOccupantCount() >= self::MAX_OCCUPANTS;
	}

	/**
	 * Puts a bee (its saved data) in the hive for the given number of ticks. Returns false if the hive is full.
	 */
	public function addOccupant(string $actorIdentifier, CompoundTag $saveData, int $ticksLeftToStay) : bool{
		if($this->isFull()){
			return false;
		}
		$this->occupants ??= new ListTag([], NBT::TAG_Compound);
		$this->occupants->push(CompoundTag::create()
			->setString(self::TAG_ACTOR_IDENTIFIER, $actorIdentifier)
			->setTag(self::TAG_SAVE_DATA, $saveData)
			->setInt(self::TAG_TICKS_LEFT, $ticksLeftToStay)
		);
		return true;
	}

	/**
	 * Counts the time of the bees inside down and removes those whose time is up (or all of them with $all).
	 * Returns the saved data of the removed bees. With $hold, the bees whose time is up stay inside (at night or in
	 * the rain) and leave on the first call without it.
	 *
	 * @return CompoundTag[]
	 * @phpstan-return list<CompoundTag>
	 */
	public function takeLeavingOccupants(int $ticks, bool $all = false, bool $hold = false) : array{
		if($this->occupants === null){
			return [];
		}
		$leaving = [];
		$staying = [];
		foreach($this->occupants as $occupant){
			if(!$occupant instanceof CompoundTag){
				continue;
			}
			$left = $occupant->getInt(self::TAG_TICKS_LEFT, 0) - $ticks;
			if($hold && !$all && $left < 0){
				$left = 0;
			}
			$saveData = $occupant->getCompoundTag(self::TAG_SAVE_DATA);
			if($all || ($left <= 0 && !$hold)){
				if($saveData !== null){
					$leaving[] = $saveData;
				}
			}else{
				$staying[] = (clone $occupant)->setInt(self::TAG_TICKS_LEFT, $left);
			}
		}
		$this->occupants = new ListTag($staying, NBT::TAG_Compound);
		return $leaving;
	}

	/**
	 * Returns whether a bee's time inside is up once the given number of ticks has passed.
	 * @internal
	 */
	public function hasOccupantDue(int $ticks) : bool{
		if($this->occupants !== null){
			foreach($this->occupants as $occupant){
				if($occupant instanceof CompoundTag && $occupant->getInt(self::TAG_TICKS_LEFT, 0) - $ticks <= 0){
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Returns a copy of the saved bees (the vanilla "Occupants" list), or null if there are none. Used to keep them in
	 * the item of a hive mined with Silk Touch.
	 * @internal
	 */
	public function getOccupantsTag() : ?ListTag{
		return $this->hasOccupants() && $this->occupants !== null ? clone $this->occupants : null;
	}

	public function copyDataFromItem(Item $item) : void{
		parent::copyDataFromItem($item);
		//Bedrock keeps the bees of a silk-touched hive in the root of the item tag, like a shulker box's items
		$occupants = $item->getNamedTag()->getListTag(self::TAG_OCCUPANTS);
		if($occupants !== null && $occupants->getTagType() === NBT::TAG_Compound){
			$this->occupants = new ListTag([], NBT::TAG_Compound);
			foreach($occupants as $occupant){
				if($occupant instanceof CompoundTag && $this->occupants->count() < self::MAX_OCCUPANTS && $occupant->getCompoundTag(self::TAG_SAVE_DATA) !== null){
					$this->occupants->push(clone $occupant);
				}
			}
		}
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->occupants = $nbt->getListTag(self::TAG_OCCUPANTS);
		$this->shouldSpawnBees = $nbt->getByte(self::TAG_SHOULD_SPAWN_BEES, 0) !== 0;
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$nbt->setTag(self::TAG_OCCUPANTS, $this->occupants !== null ? clone $this->occupants : new ListTag([], NBT::TAG_Compound));
		$nbt->setByte(self::TAG_SHOULD_SPAWN_BEES, $this->shouldSpawnBees ? 1 : 0);
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		//NOOP: the client doesn't need anything
	}
}
