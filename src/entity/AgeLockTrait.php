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

namespace pocketmine\entity;

use pocketmine\block\BlockTypeIds;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use pocketmine\world\particle\MobGrowthParticle;
use function max;

/**
 * Golden dandelion age lock of baby mobs (Bedrock minecraft:ageable pause_growth_items / reset_growth_items, Java
 * AgeableMob): using a golden dandelion on a baby stops it from growing up (and makes it persistent), using another
 * one lets it grow again. Both restart its growth from the beginning. A locked baby doesn't grow and feeding doesn't
 * age it. After each use the dandelion can't be used on it for 2 seconds, while particles show above it.
 *
 * For {@link Mob} subclasses: the using class restarts the growth in {@link AgeLockTrait::restartGrowth()} and stops
 * growing while {@link AgeLockTrait::isAgeLocked()}.
 */
trait AgeLockTrait{
	public const TAG_AGE_LOCKED = "AgeLocked"; //TAG_Byte
	public const AGE_LOCK_COOLDOWN_TICKS = 40;

	private bool $ageLocked = false;
	/** Ticks left of the lock/unlock particles; the dandelion can't be used again until 0. */
	private int $ageLockTicks = 0;

	/**
	 * Returns whether a golden dandelion stopped this baby from growing up.
	 */
	public function isAgeLocked() : bool{
		return $this->ageLocked;
	}

	public function setAgeLocked(bool $locked) : void{
		$this->ageLocked = $locked;
	}

	/**
	 * Returns whether a golden dandelion can lock the age of this mob (Bedrock: the golden dandelion is in its
	 * pause_growth_items).
	 */
	protected function canBeAgeLocked() : bool{
		return true;
	}

	/**
	 * Makes the baby start growing up again from the beginning.
	 */
	abstract protected function restartGrowth() : void;

	protected function loadAgeLock(CompoundTag $nbt) : void{
		$this->ageLocked = $nbt->getByte(self::TAG_AGE_LOCKED, 0) !== 0;
	}

	protected function saveAgeLock(CompoundTag $nbt) : void{
		if($this->ageLocked){
			$nbt->setByte(self::TAG_AGE_LOCKED, 1);
		}
	}

	public static function isGoldenDandelion(Item $item) : bool{
		return $item->getTypeId() === ItemTypeIds::fromBlockTypeId(BlockTypeIds::GOLDEN_DANDELION);
	}

	/**
	 * Locks or unlocks the age of this baby if the player uses a golden dandelion on it. Returns whether it did.
	 */
	protected function tryUseGoldenDandelion(Player $player, Item $item) : bool{
		if($this->ageLockTicks > 0 || !$this->isBaby() || !self::isGoldenDandelion($item) || !$this->canBeAgeLocked()){
			return false;
		}
		$this->ageLocked = !$this->ageLocked;
		$this->restartGrowth();
		$this->ageLockTicks = self::AGE_LOCK_COOLDOWN_TICKS;
		$this->consumeHeldItem($player);
		if($this->ageLocked){
			$this->setPersistent(true);
		}
		$this->playMobSound($this->ageLocked ? LevelSoundEvent::PAUSE_GROWTH : LevelSoundEvent::RESET_GROWTH);
		return true;
	}

	/**
	 * Returns the ticks left before the golden dandelion can be used on this baby again.
	 */
	public function getAgeLockCooldown() : int{
		return $this->ageLockTicks;
	}

	/**
	 * Shows the lock/unlock particles (one every other tick, as Java) and runs the cooldown down. Only called while
	 * {@link AgeLockTrait::getAgeLockCooldown()} is above 0.
	 */
	protected function tickAgeLock(int $tickDiff) : void{
		if($this->ageLockTicks % 2 === 0 && !$this->closed){
			$width = $this->size->getWidth();
			$height = $this->size->getHeight();
			$this->getWorld()->addParticle($this->location->add(
				$width * (Utils::getRandomFloat() * 2 - 1),
				$height + $height * 0.2 * (Utils::getRandomFloat() * 2 - 1) + ($this->ageLocked ? 0.2 : 0),
				$width * (Utils::getRandomFloat() * 2 - 1)
			), new MobGrowthParticle($this->ageLocked));
		}
		$this->ageLockTicks = max(0, $this->ageLockTicks - $tickDiff);
	}
}
