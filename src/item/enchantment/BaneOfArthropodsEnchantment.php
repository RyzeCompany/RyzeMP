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

namespace pocketmine\item\enchantment;

use pocketmine\entity\animal\Bee;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\monster\Endermite;
use pocketmine\entity\monster\Silverfish;
use pocketmine\entity\monster\Spider;
use function mt_rand;

/**
 * Bane of Arthropods: 2.5 extra damage per level against spiders, cave spiders, bees, silverfish and endermites, which
 * also get Slowness IV for 1 to 1.5 seconds per level (vanilla: 20 + random(10 * level) ticks).
 */
class BaneOfArthropodsEnchantment extends MeleeWeaponEnchantment{

	public function isApplicableTo(Entity $victim) : bool{
		return $victim instanceof Spider || $victim instanceof Bee || $victim instanceof Silverfish || $victim instanceof Endermite;
	}

	public function getDamageBonus(int $enchantmentLevel) : float{
		return 2.5 * $enchantmentLevel;
	}

	public function onPostAttack(Entity $attacker, Entity $victim, int $enchantmentLevel) : void{
		if($victim instanceof Living && $this->isApplicableTo($victim)){
			$victim->getEffects()->add(new EffectInstance(VanillaEffects::SLOWNESS(), 20 + mt_rand(0, 10 * $enchantmentLevel - 1), 3));
		}
	}
}
