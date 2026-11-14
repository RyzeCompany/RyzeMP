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

use function floor;
use function max;
use function min;
use function pow;

/**
 * @internal
 * Vanilla armour and combat formulas shared by all living entities.
 */
final class CombatRules{

	private function __construct(){
		//NOOP
	}

	/** Bedrock caps the armour value (natural + worn) at 20. */
	public const MAX_ARMOR_POINTS = 20;

	/**
	 * Returns the share of the damage (0-0.8) that armour with the given points and toughness absorbs.
	 * Vanilla (Bedrock since 1.16, Java CombatRules.getDamageAfterAbsorb, checked on BDS 1.26.52 with zombies in full
	 * diamond and netherite): min(20, max(armour / 5, armour - damage / (2 + toughness / 4))) / 25. Toughness only
	 * matters for big hits: small hits get the flat 4% per armour point.
	 * The armour value itself is capped at 20 first (BDS 1.26.60: a zombie, 2 natural armour, in full diamond takes the
	 * same damage as a skeleton in full diamond).
	 */
	public static function getArmorReduction(float $damage, float $armorPoints, float $toughness) : float{
		if($armorPoints <= 0){
			return 0.0;
		}
		$armorPoints = min(self::MAX_ARMOR_POINTS, $armorPoints);
		$effectiveArmor = min(20.0, max($armorPoints / 5, $armorPoints - $damage / (2 + $toughness / 4)));
		return $effectiveArmor / 25;
	}

	/**
	 * Returns the damage of a melee hit before effects and critical hits: Bedrock rounds the weapon's attack damage plus
	 * the enchantment bonus (Sharpness 1.25 per level, Smite, Bane of Arthropods...) down to a whole number.
	 * BDS 1.26.60: an iron sword (7) with Sharpness I-V hits for 8, 9, 10, 12, 13; a wooden sword (5) with Sharpness I
	 * for 6.
	 */
	public static function getMeleeWeaponDamage(float $attackPoints, float $enchantmentBonus) : float{
		return floor($attackPoints + $enchantmentBonus);
	}

	/**
	 * Returns melee damage after the attacker's Strength effect (Bedrock): every level multiplies by 1.3 and adds 1, so
	 * level n gives damage x 1.3^n + (1.3^n - 1) / 0.3. BDS 1.26.60: an iron sword (7) hits for 10.1 with Strength I and
	 * 14.13 with Strength II, a fist for 2.3 and 3.99.
	 */
	public static function applyStrength(float $damage, int $level) : float{
		if($level <= 0){
			return $damage;
		}
		$factor = pow(1.3, $level);
		return $damage * $factor + ($factor - 1) / 0.3;
	}

	/**
	 * Returns melee damage after the attacker's Weakness effect (Bedrock): every level multiplies by 0.8 and takes 0.5
	 * away, so level n gives damage x 0.8^n - 2.5 x (1 - 0.8^n), never below 0. BDS 1.26.60: an iron sword (7) hits for
	 * 5.1 with Weakness I and 3.58 with Weakness II, a fist for 0.3.
	 */
	public static function applyWeakness(float $damage, int $level) : float{
		if($level <= 0){
			return $damage;
		}
		$factor = pow(0.8, $level);
		return max(0.0, $damage * $factor - 2.5 * (1 - $factor));
	}
}
