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

use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\npc\WanderingTrader;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;

/**
 * Bedrock values (entities/trader_llama.json): a llama with the wandering trader's decor (MARK_VARIANT 1). It defends
 * its trader: whoever hurts the wandering trader whose lead it is on becomes its target (see
 * {@link WanderingTrader::attack()}); it doesn't get angry at other trader llamas. Breeds with llamas and trader llamas;
 * the baby is a trader llama.
 *
 * The two llamas a wandering trader comes with (from_wandering_trader, see {@link WanderingTrader::spawnTraderLlamas()})
 * are adults on its leads, which can't be ridden or tamed, and don't despawn while leashed. Once off the lead (the
 * trader died, or the lead broke) their environment_sensor tames them, with no owner (on_tame), and they may despawn
 * again (remove_persistence). If the trader despawns, they go with it.
 */
class TraderLlama extends Llama{
	/** Whether it came with a wandering trader (llama_wandering_trader component group, PocketMine-specific tag). */
	public const TAG_FROM_WANDERING_TRADER = "FromWanderingTrader"; //TAG_Byte

	private bool $fromWanderingTrader = false;
	/** Tamed when it left its trader: tamed but without an owner. */
	private bool $tamedWithoutOwner = false;

	public static function getNetworkTypeId() : string{ return EntityIds::TRADER_LLAMA; }

	public function getName() : string{
		return "Trader Llama";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->fromWanderingTrader = $nbt->getByte(self::TAG_FROM_WANDERING_TRADER, 0) !== 0;
		$this->tamedWithoutOwner = $this->getOwnerUuid() === null && $nbt->getByte(self::TAG_TAMED, 0) !== 0;
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_FROM_WANDERING_TRADER, $this->fromWanderingTrader ? 1 : 0);
		return $nbt;
	}

	/**
	 * Whether it came with a wandering trader (see the class doc).
	 */
	public function isFromWanderingTrader() : bool{
		return $this->fromWanderingTrader;
	}

	public function setFromWanderingTrader(bool $value) : void{
		$this->fromWanderingTrader = $value;
	}

	public function isTamed() : bool{
		return $this->tamedWithoutOwner || parent::isTamed();
	}

	public function setOwner(?Player $owner) : void{
		$this->tamedWithoutOwner = false;
		parent::setOwner($owner);
	}

	/**
	 * Whether it still belongs to its wandering trader (llama_wandering_trader without llama_wild: no tamemount, no
	 * rideable).
	 */
	private function isTradersLlama() : bool{
		return $this->fromWanderingTrader && !$this->isTamed();
	}

	public function canBeRiddenBy(Entity $entity) : bool{
		return !$this->isTradersLlama() && parent::canBeRiddenBy($entity);
	}

	protected function onUnleashed() : void{
		parent::onUnleashed();
		if(!$this->isTradersLlama()){
			return;
		}
		//environment_sensor: not leashed and not tamed -> on_tame; not leashed and persistent -> remove_persistence
		$this->tamedWithoutOwner = true;
		$this->setPersistent(false);
		$this->networkPropertiesDirty = true;
	}

	protected function getIgnoredAttackers() : array{
		return [TraderLlama::class]; //damage_sensor and hurt_by_target: not angry at trader llamas
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		$baby = new TraderLlama($location);
		$baby->setCoat($this->getCoat());
		$this->inheritStats($baby, $partner);
		return $baby;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::MARK_VARIANT, 1);
	}
}
