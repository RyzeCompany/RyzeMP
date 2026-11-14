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

use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Living;
use pocketmine\entity\Zombie;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use function mt_rand;

/**
 * Bedrock values (husk.json): a zombie that doesn't burn in daylight and gives Hunger for 30 seconds with its hits.
 * After 30 seconds underwater it starts converting and becomes a zombie 15 seconds later. Loot as zombies.
 *
 * Camel husk riders (spawn_as_rider, family "husk_rider"): the husks spawned riding a camel husk hold an iron spear and
 * maybe wear armor (zombie_rider_equipment; dropped like spawned equipment) and drop
 * loot_tables/entities/husk_rider.json. Only they (and players and parched) may ride camel husks.
 */
class Husk extends Zombie{
	public const TAG_UNDERWATER_TICKS = "UnderwaterTicks"; //TAG_Int (PocketMine)
	public const TAG_CONVERSION_TICKS = "ConversionTicks"; //TAG_Int (PocketMine)
	public const TAG_CAMEL_HUSK_RIDER = "CamelHuskRider"; //TAG_Byte (PocketMine)

	private bool $camelHuskRider = false;

	public const HUNGER_TICKS = 600;
	public const UNDERWATER_TICKS_BEFORE_CONVERSION = UnderwaterConversionTimer::UNDERWATER_TICKS;
	public const CONVERSION_TICKS = UnderwaterConversionTimer::CONVERSION_TICKS;

	private UnderwaterConversionTimer $zombieConversion;

	public static function getNetworkTypeId() : string{ return EntityIds::HUSK; }

	public function getName() : string{
		return "Husk";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->zombieConversion = UnderwaterConversionTimer::fromNBT($nbt);
		$this->camelHuskRider = $nbt->getByte(self::TAG_CAMEL_HUSK_RIDER, 0) !== 0;
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$this->zombieConversion->saveNBT($nbt);
		$nbt->setByte(self::TAG_CAMEL_HUSK_RIDER, $this->camelHuskRider ? 1 : 0);
		return $nbt;
	}

	/**
	 * Whether this husk is a camel husk rider (vanilla family "husk_rider"), which may ride camel husks.
	 */
	public function isCamelHuskRider() : bool{
		return $this->camelHuskRider;
	}

	/**
	 * Makes this husk a camel husk rider (vanilla spawn_as_rider: an adult with zombie_rider_equipment, an iron spear
	 * and maybe armor), or a normal husk.
	 */
	public function setCamelHuskRider(bool $rider) : void{
		$this->camelHuskRider = $rider;
		if($rider){
			$this->setBaby(false);
			$this->equipAsRider();
		}
	}

	public function getDrops() : array{
		if(!$this->camelHuskRider){
			return parent::getDrops();
		}
		//husk_rider.json: 0-2 rotten flesh or 0-1 rabbit's foot (+0-1 per Looting level), and the rare zombie drops
		$looting = $this->lootingBonus();
		$drops = [mt_rand(0, 1) === 0 ? VanillaItems::ROTTEN_FLESH()->setCount(mt_rand(0, 2) + $looting) : VanillaItems::RABBIT_FOOT()->setCount(mt_rand(0, 1) + $looting)];
		if($this->wasRecentlyHitByPlayer() && mt_rand(0, 999) < 25 + 10 * $this->getLootingLevel()){
			$drops[] = match(mt_rand(0, 2)){
				0 => VanillaItems::IRON_INGOT(),
				1 => VanillaItems::CARROT(),
				default => VanillaItems::POTATO()
			};
		}
		return [...$drops, ...$this->getEquipmentDrops()];
	}

	public function burnsInDaylight() : bool{
		return false;
	}

	public function canBreathe() : bool{
		return true;
	}

	public function attackMelee(Living $target, float $baseDamage) : bool{
		$hit = parent::attackMelee($target, $baseDamage);
		if($hit && $target->isAlive()){
			$target->getEffects()->add(new EffectInstance(VanillaEffects::HUNGER(), self::HUNGER_TICKS));
		}
		return $hit;
	}

	public function isConverting() : bool{
		return $this->zombieConversion->isConverting();
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->closed || !$this->isAlive()){
			return $hasUpdate;
		}
		switch($this->zombieConversion->tick($this, $tickDiff)){
			case UnderwaterConversionTimer::STATE_STARTED:
				$this->networkPropertiesDirty = true;
				return true;
			case UnderwaterConversionTimer::STATE_CONVERTING:
				return true;
			case UnderwaterConversionTimer::STATE_DONE:
				$this->convertToZombie();
				return true;
		}
		return $hasUpdate;
	}

	private function convertToZombie() : void{
		//vanilla turns it into minecraft:zombie<minecraft:as_adult>: no can_break_doors roll (that is entity_spawned only);
		//picked up items still always drop
		$zombie = new Zombie($this->location->asLocation(), MobEquipment::getConversionData($this)->setByte("CanBreakDoors", 0));
		$zombie->setBaby($this->isBaby());
		$zombie->setMainHandItem($this->getMainHandItem());
		$zombie->setOffHandItem($this->getOffHandItem());
		MobConversion::copyState($this, $zombie);
		$zombie->spawnToAll();
		$zombie->playMobSound(LevelSoundEvent::MOB_HUSK_CONVERT_TO_ZOMBIE);
		$this->flagForDespawn();
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::HUSK_SPAWN_EGG();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		//flag 40 is ActorFlags::SHAKING (gophertunnel EntityDataFlagShaking), named VIBRATING in BedrockProtocol
		$properties->setGenericFlag(EntityMetadataFlags::VIBRATING, $this->isConverting());
	}
}
