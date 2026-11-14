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

use pocketmine\entity\effect\Effect;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\item\Item;
use pocketmine\item\PotionType;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use function min;

/**
 * Bedrock values (parched.json, desert skeleton): 16 health, movement 0.25, an arrow every 3.5 seconds (2.5 on hard)
 * tipped with Weakness (aux_val 35 = weakness: 11 seconds on hit). Unlike other skeletons it doesn't burn in
 * daylight (no minecraft:burns_in_daylight). Melee damage 2 in melee mode (see {@link Skeleton}).
 * Loot: 0-2 arrows, 0-2 bones (+0-1 each per Looting level), and 0-2 arrows of Weakness when killed by a player.
 */
class Parched extends TippedArrowSkeleton{
	public static function getNetworkTypeId() : string{ return EntityIds::PARCHED; }

	public function getName() : string{
		return "Parched";
	}

	public function isImmuneToEffect(Effect $effect) : bool{
		return $effect === VanillaEffects::WEAKNESS() || parent::isImmuneToEffect($effect); //parched.json mob_effect_immunity
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->setMaxHealth(16);
		$this->setHealth(min($this->getHealth(), 16));
	}

	protected function getAttackIntervals() : array{
		return [70, 50];
	}

	public function burnsInDaylight() : bool{
		return false;
	}

	protected function getArrowPotion() : PotionType{
		return PotionType::WEAKNESS;
	}

	protected function getDropPotion() : PotionType{
		return PotionType::WEAKNESS;
	}

	protected function getMaxTippedArrowDrops() : int{
		return 2;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::PARCHED_SPAWN_EGG();
	}
}
