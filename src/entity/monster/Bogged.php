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

namespace pocketmine\entity\monster;

use pocketmine\block\VanillaBlocks;
use pocketmine\item\Item;
use pocketmine\item\PotionType;
use pocketmine\item\Shears;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\vibration\GameEvent;
use function min;
use function mt_rand;

/**
 * Bedrock values (bogged.json): 16 health, movement 0.25, an arrow every 3.5 seconds (2.5 on hard) tipped with
 * Poison (aux_val 26 = poison: 5.6 seconds on hit), burns in daylight. Shears take its mushrooms once: two, each red or
 * brown.
 * Loot: 0-2 arrows, 0-2 bones (+0-1 each per Looting level), and 0-1 arrow of Poison when killed by a player.
 */
class Bogged extends TippedArrowSkeleton{
	public const TAG_SHEARED = "Sheared"; //TAG_Byte

	private bool $sheared = false;

	public static function getNetworkTypeId() : string{ return EntityIds::BOGGED; }

	public function getName() : string{
		return "Bogged";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->setMaxHealth(16);
		$this->setHealth(min($this->getHealth(), 16));
		$this->sheared = $nbt->getByte(self::TAG_SHEARED, 0) !== 0;
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_SHEARED, $this->sheared ? 1 : 0);
		return $nbt;
	}

	protected function getAttackIntervals() : array{
		return [70, 50];
	}

	protected function getArrowPotion() : PotionType{
		return PotionType::POISON;
	}

	protected function getDropPotion() : PotionType{
		return PotionType::POISON;
	}

	public function isSheared() : bool{
		return $this->sheared;
	}

	/**
	 * Shears the mushrooms off (players and dispensers). Returns false if it can't be sheared now.
	 */
	public function shear() : bool{
		if($this->sheared || !$this->isAlive()){
			return false;
		}
		$this->sheared = true;
		$this->networkPropertiesDirty = true;
		$world = $this->getWorld();
		$pos = $this->location->add(0, 1, 0);
		//bogged_shear.json: 2 rolls of {brown mushroom 1, red mushroom 1}
		for($i = 0; $i < 2; ++$i){
			$world->dropItem($pos, (mt_rand(0, 1) === 0 ? VanillaBlocks::BROWN_MUSHROOM() : VanillaBlocks::RED_MUSHROOM())->asItem());
		}
		$this->playMobSound(LevelSoundEvent::SHEAR);
		return true;
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if($item instanceof Shears && $this->shear()){
			$this->getWorld()->addGameEvent($this->location, GameEvent::SHEAR, $player);
			if($player->hasFiniteResources()){
				$item->applyDamage(1);
				$player->getInventory()->setItemInHand($item);
			}
			return true;
		}
		return parent::onInteractWithItem($player, $item);
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::BOGGED_SPAWN_EGG();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::SHEARED, $this->sheared);
	}
}
