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

namespace pocketmine\entity\projectile;

use pocketmine\entity\animal\Animal;
use pocketmine\entity\animal\Chicken;
use pocketmine\entity\animal\ClimateVariant;
use pocketmine\entity\Location;
use pocketmine\entity\property\EntityPropertyRegistry;
use pocketmine\entity\SpawnReason;
use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\world\particle\ItemBreakParticle;
use function mt_rand;

/**
 * Vanilla egg.json: on impact it has a 1/8 chance (first_spawn_chance 0.125) to hatch a baby chicken, and 1/32 of those
 * hatches (second_spawn_chance 0.03125) are 4 chicks. Brown eggs hatch warm chicks, blue eggs cold ones
 * (minecraft:hatch_warm / hatch_cold), plain eggs temperate ones. The thrown egg shows its colour through the actor
 * property minecraft:climate_variant.
 */
class Egg extends Throwable{
	private const HATCH_CHANCE = 8;
	private const FOUR_CHICKS_CHANCE = 32;

	private const TAG_CLIMATE_VARIANT = "ClimateVariant"; //TAG_String

	public static function getNetworkTypeId() : string{ return EntityIds::EGG; }

	/** The egg item this was thrown from (normal, blue or brown), used for the break particles. */
	private ?Item $eggItem = null;

	public function getEggItem() : Item{
		return $this->eggItem !== null ? clone $this->eggItem : VanillaItems::EGG();
	}

	public function setEggItem(Item $item) : void{
		$this->eggItem = (clone $item)->setCount(1);
		$this->networkPropertiesDirty = true;
	}

	public function getClimateVariant() : ClimateVariant{
		return $this->eggItem !== null ? ClimateVariant::fromEggItem($this->eggItem) : ClimateVariant::TEMPERATE;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$climate = ClimateVariant::tryFrom($nbt->getString(self::TAG_CLIMATE_VARIANT, ""));
		if($climate !== null && $climate !== ClimateVariant::TEMPERATE){
			$this->eggItem = $climate->getEggItem();
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setString(self::TAG_CLIMATE_VARIANT, $this->getClimateVariant()->value);
		return $nbt;
	}

	public function getNetworkSyncedProperties() : PropertySyncData{
		$registry = EntityPropertyRegistry::getInstance();
		$index = $registry->getIndex(EntityIds::EGG, "minecraft:climate_variant");
		if($index === null){
			return parent::getNetworkSyncedProperties();
		}
		return new PropertySyncData([$index => $registry->encodeValue(EntityIds::EGG, $index, $this->getClimateVariant()->value)], []);
	}

	protected function onHit(ProjectileHitEvent $event) : void{
		$item = $this->getEggItem();
		for($i = 0; $i < 6; ++$i){
			$this->getWorld()->addParticle($this->location, new ItemBreakParticle($item));
		}
		$count = self::rollHatchCount();
		if($count > 0){
			$this->hatch($count);
		}
	}

	/**
	 * Returns how many chicks an egg hatches when it lands: 0 (7/8), 1 or 4 (1/32 of the hatches).
	 *
	 * @internal
	 */
	public static function rollHatchCount() : int{
		if(mt_rand(0, self::HATCH_CHANCE - 1) !== 0){
			return 0;
		}
		return mt_rand(0, self::FOUR_CHICKS_CHANCE - 1) === 0 ? 4 : 1;
	}

	/**
	 * Hatches baby chickens of the egg's climate where it landed.
	 *
	 * @return Chicken[]
	 * @phpstan-return list<Chicken>
	 */
	private function hatch(int $count) : array{
		$climate = $this->getClimateVariant();
		$chicks = [];
		for($i = 0; $i < $count; ++$i){
			$chick = new Chicken(Location::fromObject($this->location, $this->getWorld(), $this->location->yaw, 0.0));
			$chick->setAge(Animal::BABY_AGE);
			$chick->setClimateVariant($climate);
			$chick->setSpawnReason(SpawnReason::BREEDING); //entity_born
			$chick->spawnToAll();
			$chicks[] = $chick;
		}
		return $chicks;
	}
}
