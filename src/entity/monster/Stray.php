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
use pocketmine\item\Item;
use pocketmine\item\PotionType;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

/**
 * Bedrock values (stray.json): a skeleton (20 health, movement 0.25, an arrow every 3 seconds, 2 on hard, burns in
 * daylight) whose arrows are tipped with Slowness (shooter aux_val 19 = long slowness: 30 seconds on hit).
 * In melee mode (see {@link Skeleton}) it hits for 3 and gives Slowness for 10 seconds.
 * Loot: 0-2 arrows, 0-2 bones (+0-1 each per Looting level), and 0-1 arrow of Slowness when killed by a player.
 */
class Stray extends TippedArrowSkeleton{
	/** Slowness given by its melee hits (stray.json melee attack effect_duration 10). */
	public const MELEE_SLOWNESS_TICKS = 200;

	public static function getNetworkTypeId() : string{ return EntityIds::STRAY; }

	public function canFreeze() : bool{
		return false; //vanilla freeze_immune_entity_types
	}

	public function getName() : string{
		return "Stray";
	}

	public function attackMelee(Living $target, float $baseDamage) : bool{
		$hit = parent::attackMelee($target, $baseDamage);
		if($hit && $target->isAlive()){
			$target->getEffects()->add(new EffectInstance(VanillaEffects::SLOWNESS(), self::MELEE_SLOWNESS_TICKS));
		}
		return $hit;
	}

	protected function getArrowPotion() : PotionType{
		return PotionType::LONG_SLOWNESS;
	}

	protected function getDropPotion() : PotionType{
		return PotionType::SLOWNESS;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::STRAY_SPAWN_EGG();
	}
}
