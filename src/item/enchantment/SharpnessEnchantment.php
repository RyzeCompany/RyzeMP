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

use pocketmine\entity\Entity;

/**
 * Sharpness: 1.25 extra damage per level against any mob (Bedrock; Java uses 0.5 x level + 0.5). Bedrock rounds the
 * weapon damage plus this bonus down to a whole number (see {@link \pocketmine\entity\CombatRules::getMeleeWeaponDamage()}).
 */
class SharpnessEnchantment extends MeleeWeaponEnchantment{

	public function isApplicableTo(Entity $victim) : bool{
		return true;
	}

	public function getDamageBonus(int $enchantmentLevel) : float{
		return 1.25 * $enchantmentLevel;
	}
}
