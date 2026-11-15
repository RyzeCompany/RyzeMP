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

namespace pocketmine\entity\animal;

use pocketmine\entity\Living;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * An animal that a player can tame (wolves, cats, parrots, horses...). A tamed animal has an owner, can be ordered to
 * sit, and its owner is shown to clients (OWNER_EID, TAMED and SITTING flags).
 *
 * The owner is saved by UUID ("OwnerUUID", PocketMine-specific: vanilla saves the owner's actor unique ID, which
 * PocketMine doesn't keep across sessions).
 */
abstract class TameableAnimal extends Animal{
	public const TAG_OWNER_UUID = "OwnerUUID"; //TAG_String
	public const TAG_TAMED = "IsTamed"; //TAG_Byte
	public const TAG_SITTING = "Sitting"; //TAG_Byte

	protected ?UuidInterface $ownerUuid = null;
	protected bool $sitting = false;
	/** Last seen owner entity, refreshed when needed. */
	private ?Player $ownerCache = null;

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$uuid = $nbt->getString(self::TAG_OWNER_UUID, "");
		if($uuid !== "" && Uuid::isValid($uuid)){
			$this->ownerUuid = Uuid::fromString($uuid);
		}
		$this->sitting = $this->ownerUuid !== null && $nbt->getByte(self::TAG_SITTING, 0) !== 0;
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		if($this->ownerUuid !== null){
			$nbt->setString(self::TAG_OWNER_UUID, $this->ownerUuid->toString());
		}
		$nbt->setByte(self::TAG_TAMED, $this->isTamed() ? 1 : 0);
		$nbt->setByte(self::TAG_SITTING, $this->sitting ? 1 : 0);
		return $nbt;
	}

	public function isTamed() : bool{
		return $this->ownerUuid !== null;
	}

	public function getOwnerUuid() : ?UuidInterface{
		return $this->ownerUuid;
	}

	/**
	 * Returns the owner if it is online and in the same world.
	 */
	public function getOwner() : ?Player{
		if($this->ownerUuid === null){
			return null;
		}
		$cached = $this->ownerCache;
		if($cached === null || !$cached->isConnected() || !$cached->getUniqueId()->equals($this->ownerUuid)){
			$cached = $this->server->getPlayerByUUID($this->ownerUuid);
			if($cached !== $this->ownerCache){
				$this->ownerCache = $cached;
				$this->networkPropertiesDirty = true;
			}
		}
		return $cached !== null && $cached->getWorld() === $this->getWorld() ? $cached : null;
	}

	public function isOwner(Living $entity) : bool{
		return $entity instanceof Player && $this->ownerUuid !== null && $entity->getUniqueId()->equals($this->ownerUuid);
	}

	/**
	 * Tames the animal for the player (no chance roll, no particles).
	 */
	public function setOwner(?Player $owner) : void{
		$this->ownerUuid = $owner?->getUniqueId();
		$this->ownerCache = $owner;
		$this->persistent = true;
		if($owner === null){
			$this->sitting = false;
		}
		$this->onTamedChanged();
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Called when the animal is tamed or untamed (health and goals of tamed wolves and cats change).
	 */
	protected function onTamedChanged() : void{

	}

	/**
	 * Feeding attempt with a taming item: succeeds with the given chance (vanilla 1/3). Shows hearts or smoke.
	 * Returns whether the animal was tamed.
	 */
	public function tryTame(Player $player, float $chance) : bool{
		if(Utils::getRandomFloat() < $chance){
			$this->setOwner($player);
			$this->setSitting(true);
			$this->getNavigation()->stop();
			$this->setAttackTarget(null);
			$this->broadcastActorEvent(ActorEvent::TAME_SUCCESS);
			return true;
		}
		$this->broadcastActorEvent(ActorEvent::TAME_FAIL);
		return false;
	}

	public function isSitting() : bool{
		return $this->sitting;
	}

	public function setSitting(bool $sitting) : void{
		$this->sitting = $sitting && $this->isTamed();
		if($this->sitting){
			$this->getNavigation()->stop();
		}
		$this->networkPropertiesDirty = true;
	}

	public function setAttackTarget(?Living $target) : void{
		//tamed animals never turn on their owner
		parent::setAttackTarget($target !== null && $this->isOwner($target) ? null : $target);
	}

	public function canMate(Animal $other) : bool{
		return parent::canMate($other) && (!$this->requiresTameToBreed() || ($this->isTamed() && $other instanceof TameableAnimal && $other->isTamed()));
	}

	/**
	 * Whether only tamed animals of this kind breed (wolves, cats, llamas, horses).
	 */
	protected function requiresTameToBreed() : bool{
		return true;
	}

	protected function canFallInLove(Player $player) : bool{
		return !$this->requiresTameToBreed() || $this->isTamed();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::TAMED, $this->isTamed());
		$properties->setGenericFlag(EntityMetadataFlags::SITTING, $this->sitting);
		$owner = $this->ownerCache;
		if($owner !== null && !$owner->isClosed()){
			$properties->setLong(EntityMetadataProperties::OWNER_EID, $owner->getId());
		}
	}

	protected function onDispose() : void{
		$this->ownerCache = null;
		parent::onDispose();
	}
}
