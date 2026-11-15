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

use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\utils\RegistrySource;

/**
 * @internal
 * @phpstan-extends RegistrySource<Enchantment>
 */
final class VanillaEnchantmentsInputs extends RegistrySource{
	public function getTargetClassName() : string{
		return "VanillaEnchantments";
	}

	public function getTargetClassDocComment() : array{
		return ["Allows getting any vanilla enchantment implemented by PocketMine-MP"];
	}

	protected function setup() : void{
		self::register("PROTECTION", new ProtectionEnchantment(
			KnownTranslationFactory::enchantment_protect_all(),
			Rarity::COMMON,
			0,
			0,
			4,
			0.75,
			null,
			fn(int $level) : int => 11 * ($level - 1) + 1,
			20
		));
		self::register("FIRE_PROTECTION", new ProtectionEnchantment(
			KnownTranslationFactory::enchantment_protect_fire(),
			Rarity::UNCOMMON,
			0,
			0,
			4,
			1.25,
			[
				EntityDamageEvent::CAUSE_FIRE,
				EntityDamageEvent::CAUSE_FIRE_TICK,
				EntityDamageEvent::CAUSE_LAVA,
				EntityDamageEvent::CAUSE_MAGMA,
				EntityDamageEvent::CAUSE_CAMPFIRE,
				EntityDamageEvent::CAUSE_FIREBALL
			],
			fn(int $level) : int => 8 * ($level - 1) + 10,
			12
		));
		self::register("FEATHER_FALLING", new ProtectionEnchantment(
			KnownTranslationFactory::enchantment_protect_fall(),
			Rarity::UNCOMMON,
			0,
			0,
			4,
			2.5,
			[
				EntityDamageEvent::CAUSE_FALL,
				EntityDamageEvent::CAUSE_STALAGMITE
			],
			fn(int $level) : int => 6 * ($level - 1) + 5,
			10
		));
		self::register("BLAST_PROTECTION", new ProtectionEnchantment(
			KnownTranslationFactory::enchantment_protect_explosion(),
			Rarity::RARE,
			0,
			0,
			4,
			1.5,
			[
				EntityDamageEvent::CAUSE_BLOCK_EXPLOSION,
				EntityDamageEvent::CAUSE_ENTITY_EXPLOSION
			],
			fn(int $level) : int => 8 * ($level - 1) + 5,
			12
		));
		self::register("PROJECTILE_PROTECTION", new ProtectionEnchantment(
			KnownTranslationFactory::enchantment_protect_projectile(),
			Rarity::UNCOMMON,
			0,
			0,
			4,
			1.5,
			[
				EntityDamageEvent::CAUSE_PROJECTILE,
				EntityDamageEvent::CAUSE_FIREBALL,
				EntityDamageEvent::CAUSE_WITHER_SKULL
			],
			fn(int $level) : int => 6 * ($level - 1) + 3,
			15
		));
		self::register("THORNS", new Enchantment(
			KnownTranslationFactory::enchantment_thorns(),
			Rarity::MYTHIC,
			0,
			0,
			3,
			fn(int $level) : int => 20 * ($level - 1) + 10,
			50
		));
		self::register("RESPIRATION", new Enchantment(
			KnownTranslationFactory::enchantment_oxygen(),
			Rarity::RARE,
			0,
			0,
			3,
			fn(int $level) : int => 10 * $level,
			30
		));

		self::register("FROST_WALKER", new Enchantment(
			KnownTranslationFactory::enchantment_frostwalker(),
			Rarity::RARE,
			0,
			0,
			2,
			fn(int $level) : int => 10 * $level,
			15
		));
		self::register("AQUA_AFFINITY", new Enchantment(
			KnownTranslationFactory::enchantment_waterWorker(),
			Rarity::RARE,
			0,
			0,
			1,
			null,
			40
		));

		self::register("SHARPNESS", new SharpnessEnchantment(
			KnownTranslationFactory::enchantment_damage_all(),
			Rarity::COMMON,
			0,
			0,
			5,
			fn(int $level) : int => 11 * ($level - 1) + 1,
			20
		));
		self::register("KNOCKBACK", new KnockbackEnchantment(
			KnownTranslationFactory::enchantment_knockback(),
			Rarity::UNCOMMON,
			0,
			0,
			2,
			fn(int $level) : int => 20 * ($level - 1) + 5,
			50
		));
		self::register("FIRE_ASPECT", new FireAspectEnchantment(
			KnownTranslationFactory::enchantment_fire(),
			Rarity::RARE,
			0,
			0,
			2,
			fn(int $level) : int => 20 * ($level - 1) + 10,
			50
		));
		//https://minecraft.wiki/w/Smite and /Bane_of_Arthropods: max level V, weight 5, cost 5 + 8 per level, range 20
		self::register("SMITE", new SmiteEnchantment(
			KnownTranslationFactory::enchantment_damage_undead(),
			Rarity::UNCOMMON,
			0,
			0,
			5,
			fn(int $level) : int => 8 * ($level - 1) + 5,
			20
		));
		self::register("BANE_OF_ARTHROPODS", new BaneOfArthropodsEnchantment(
			KnownTranslationFactory::enchantment_damage_arthropods(),
			Rarity::UNCOMMON,
			0,
			0,
			5,
			fn(int $level) : int => 8 * ($level - 1) + 5,
			20
		));

		self::register("EFFICIENCY", new Enchantment(
			KnownTranslationFactory::enchantment_digging(),
			Rarity::COMMON,
			0,
			0,
			5,
			fn(int $level) : int => 10 * ($level - 1) + 1,
			50
		));
		self::register("FORTUNE", new Enchantment(
			KnownTranslationFactory::enchantment_lootBonusDigger(),
			Rarity::RARE,
			0,
			0,
			3,
			fn(int $level) : int => 9 * ($level - 1) + 15,
			50
		));
		self::register("SILK_TOUCH", new Enchantment(
			KnownTranslationFactory::enchantment_untouching(),
			Rarity::MYTHIC,
			0,
			0,
			1,
			fn(int $level) : int => 15,
			50
		));
		self::register("UNBREAKING", new Enchantment(
			KnownTranslationFactory::enchantment_durability(),
			Rarity::UNCOMMON,
			0,
			0,
			3,
			fn(int $level) : int => 8 * ($level - 1) + 5,
			50
		));

		self::register("POWER", new Enchantment(
			KnownTranslationFactory::enchantment_arrowDamage(),
			Rarity::COMMON,
			0,
			0,
			5,
			fn(int $level) : int => 10 * ($level - 1) + 1,
			15
		));
		self::register("PUNCH", new Enchantment(
			KnownTranslationFactory::enchantment_arrowKnockback(),
			Rarity::RARE,
			0,
			0,
			2,
			fn(int $level) : int => 20 * ($level - 1) + 12,
			25
		));
		self::register("FLAME", new Enchantment(
			KnownTranslationFactory::enchantment_arrowFire(),
			Rarity::RARE,
			0,
			0,
			1,
			fn(int $level) : int => 20,
			30
		));
		self::register("INFINITY", new Enchantment(
			KnownTranslationFactory::enchantment_arrowInfinite(),
			Rarity::MYTHIC,
			0,
			0,
			1,
			fn(int $level) : int => 20,
			30
		));

		self::register("MENDING", new Enchantment(
			KnownTranslationFactory::enchantment_mending(),
			Rarity::RARE,
			0,
			0,
			1,
			fn(int $level) : int => 25,
			50
		));

		self::register("VANISHING", new Enchantment(
			KnownTranslationFactory::enchantment_curse_vanishing(),
			Rarity::MYTHIC,
			0,
			0,
			1,
			fn(int $level) : int => 25,
			25
		));

		self::register("SWIFT_SNEAK", new Enchantment(
			KnownTranslationFactory::enchantment_swift_sneak(),
			Rarity::MYTHIC,
			0,
			0,
			3,
			fn(int $level) : int => 10 * $level,
			5
		));

		//treasure enchantment: only from piglin bartering and bastion chests
		self::register("SOUL_SPEED", new Enchantment(
			KnownTranslationFactory::enchantment_soul_speed(),
			Rarity::MYTHIC,
			0,
			0,
			3,
			fn(int $level) : int => 10 * $level,
			15
		));

		self::register("QUICK_CHARGE", new Enchantment(
			KnownTranslationFactory::enchantment_crossbowQuickCharge(),
			Rarity::UNCOMMON, //weight 5, anvil multiplier 2 (Java Enchantments bootstrap)
			0,
			0,
			3,
			fn(int $level) : int => 20 * ($level - 1) + 12,
			50
		));

		self::register("MULTISHOT", new Enchantment(
			KnownTranslationFactory::enchantment_crossbowMultishot(),
			Rarity::RARE,
			0,
			0,
			1,
			fn(int $level) : int => 20,
			30
		));

		//Mace enchantments. Enchanting costs follow Java (Enchantment.dynamicCost values in the 1.21 data pack).
		self::register("DENSITY", new Enchantment(
			KnownTranslationFactory::enchantment_heavy_weapon_density(),
			Rarity::UNCOMMON,
			0,
			0,
			5,
			fn(int $level) : int => 5 + 8 * ($level - 1),
			20
		));

		self::register("BREACH", new Enchantment(
			KnownTranslationFactory::enchantment_heavy_weapon_breach(),
			Rarity::RARE,
			0,
			0,
			4,
			fn(int $level) : int => 15 + 9 * ($level - 1),
			50
		));

		self::register("WIND_BURST", new Enchantment(
			KnownTranslationFactory::enchantment_heavy_weapon_windburst(),
			Rarity::RARE,
			0,
			0,
			3,
			fn(int $level) : int => 15 + 9 * ($level - 1),
			50
		));

		//Spear enchantment (https://minecraft.wiki/w/Lunge: max level III, weight 5)
		self::register("LUNGE", new Enchantment(
			KnownTranslationFactory::enchantment_lunge(),
			Rarity::UNCOMMON,
			0,
			0,
			3,
			fn(int $level) : int => 5 + 8 * ($level - 1),
			20
		));

		//Trident enchantment (https://minecraft.wiki/w/Channeling: max level I, weight 1, cost 25-50)
		self::register("CHANNELING", new Enchantment(
			KnownTranslationFactory::enchantment_tridentChanneling(),
			Rarity::MYTHIC,
			0,
			0,
			1,
			fn(int $level) : int => 25,
			25
		));

		//Boots (https://minecraft.wiki/w/Depth_Strider: max level III, weight 2, cost 10 per level, range 15). The client
		//moves players faster in water by itself.
		self::register("DEPTH_STRIDER", new Enchantment(
			KnownTranslationFactory::enchantment_waterWalker(),
			Rarity::RARE,
			0,
			0,
			3,
			fn(int $level) : int => 10 * $level,
			15
		));

		//Fishing rods (Luck of the Sea, Lure: max level III, weight 2, cost 15 + 9 per level, range 50)
		self::register("LUCK_OF_THE_SEA", new Enchantment(
			KnownTranslationFactory::enchantment_lootBonusFishing(),
			Rarity::RARE,
			0,
			0,
			3,
			fn(int $level) : int => 9 * ($level - 1) + 15,
			50
		));
		self::register("LURE", new Enchantment(
			KnownTranslationFactory::enchantment_fishingSpeed(),
			Rarity::RARE,
			0,
			0,
			3,
			fn(int $level) : int => 9 * ($level - 1) + 15,
			50
		));

		//Tridents (Impaling: V, weight 2, cost 1 + 8 per level, range 20; Riptide: III, weight 2, cost 10 + 7 per level,
		//up to 50; Loyalty: III, weight 5, cost 12 + 7 per level, up to 50)
		self::register("IMPALING", new ImpalingEnchantment(
			KnownTranslationFactory::enchantment_tridentImpaling(),
			Rarity::RARE,
			0,
			0,
			5,
			fn(int $level) : int => 8 * ($level - 1) + 1,
			20
		));
		self::register("RIPTIDE", new Enchantment(
			KnownTranslationFactory::enchantment_tridentRiptide(),
			Rarity::RARE,
			0,
			0,
			3,
			fn(int $level) : int => 7 * ($level - 1) + 10,
			25
		));
		self::register("LOYALTY", new Enchantment(
			KnownTranslationFactory::enchantment_tridentLoyalty(),
			Rarity::UNCOMMON,
			0,
			0,
			3,
			fn(int $level) : int => 7 * ($level - 1) + 12,
			25
		));

		//Crossbows (https://minecraft.wiki/w/Piercing: max level IV, weight 10, cost 1 + 10 per level, up to 50)
		self::register("PIERCING", new Enchantment(
			KnownTranslationFactory::enchantment_crossbowPiercing(),
			Rarity::COMMON,
			0,
			0,
			4,
			fn(int $level) : int => 10 * ($level - 1) + 1,
			30
		));

		//Treasure curse (https://minecraft.wiki/w/Curse_of_Binding: max level I, weight 1, cost 25-50): worn items can't
		//be taken off outside creative
		self::register("BINDING", new Enchantment(
			KnownTranslationFactory::enchantment_curse_binding(),
			Rarity::MYTHIC,
			0,
			0,
			1,
			fn(int $level) : int => 25,
			25
		));

		//Extra loot from mobs (https://minecraft.wiki/w/Looting: max level III, weight 2)
		self::register("LOOTING", new Enchantment(
			KnownTranslationFactory::enchantment_lootBonus(),
			Rarity::RARE,
			0,
			0,
			3,
			fn(int $level) : int => 9 * ($level - 1) + 15,
			50
		));
	}

	protected function register(string $name, Enchantment $member) : void{
		self::registerValue($name, $member);
	}
}
