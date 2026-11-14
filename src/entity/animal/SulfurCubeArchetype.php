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

use pocketmine\data\bedrock\item\ItemTypeSerializeException;
use pocketmine\data\bedrock\ItemTagToIdMap;
use pocketmine\item\Item;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use function array_key_exists;
use function max;
use function min;

/**
 * What a sulfur cube becomes when it absorbs a block (values of the actor property minecraft:sulfur_cube_archetype,
 * in definition order). The block decides it through the Bedrock item tags minecraft:sulfur_cube_archetype_<name>
 * (planks and logs: bouncy, stone: slow bouncy, TNT: explosive, magma: hot...). Physics values from the archetype
 * component groups of sulfur_cube.json; the knockback taken from players from the apply_knockback_rules of player.json.
 */
enum SulfurCubeArchetype : string{
	case NONE = "none";
	case BOUNCY = "bouncy";
	case REGULAR = "regular";
	case SLOW_BOUNCY = "slow_bouncy";
	case SLOW_FLAT = "slow_flat";
	case FAST_FLAT = "fast_flat";
	case LIGHT = "light";
	case FAST_SLIDING = "fast_sliding";
	case SLOW_SLIDING = "slow_sliding";
	case STICKY = "sticky";
	case HIGH_RESISTANCE = "high_resistance";
	case EXPLOSIVE = "explosive";
	case HOT = "hot";

	/**
	 * Returns the archetype a sulfur cube takes when it absorbs this item, or null if it can't absorb it.
	 */
	public static function fromItem(Item $item) : ?self{
		/**
		 * item state ID => archetype (null: not swallowable); enums can't have static properties
		 * @phpstan-var array<int, self|null> $byItemState
		 */
		static $byItemState = [];
		if($item->isNull()){
			return null;
		}
		$state = $item->getStateId();
		if(!array_key_exists($state, $byItemState)){
			$byItemState[$state] = self::lookUp($item);
		}
		return $byItemState[$state];
	}

	private static function lookUp(Item $item) : ?self{
		try{
			$id = GlobalItemDataHandlers::getSerializer()->serializeType($item)->getName();
		}catch(ItemTypeSerializeException){
			return null;
		}
		$tags = ItemTagToIdMap::getInstance();
		foreach(self::cases() as $archetype){
			if($archetype !== self::NONE && $tags->tagContainsId($archetype->getItemTag(), $id)){
				return $archetype;
			}
		}
		return null;
	}

	/**
	 * Bedrock item tag of the blocks giving this archetype.
	 */
	public function getItemTag() : string{
		return "minecraft:sulfur_cube_archetype_" . $this->value;
	}

	/**
	 * Share of the speed kept when bouncing off a block (minecraft:bounciness).
	 */
	public function getBounciness() : float{
		return match($this){
			self::NONE, self::STICKY => 0.0,
			self::BOUNCY => 0.9,
			self::REGULAR, self::FAST_FLAT, self::EXPLOSIVE, self::HOT => 0.5,
			self::SLOW_BOUNCY => 0.6,
			self::SLOW_FLAT => 0.4,
			self::LIGHT => 1.0,
			self::FAST_SLIDING, self::SLOW_SLIDING => 0.1,
			self::HIGH_RESISTANCE => 0.2,
		};
	}

	/**
	 * Multiplier of the friction of the block under the cube (minecraft:friction_modifier).
	 */
	public function getFrictionModifier() : float{
		return match($this){
			self::NONE, self::HIGH_RESISTANCE => 1.0,
			self::BOUNCY, self::REGULAR, self::SLOW_BOUNCY, self::LIGHT, self::EXPLOSIVE, self::HOT => 0.3,
			self::SLOW_FLAT => 0.4,
			self::FAST_FLAT => 0.2,
			self::FAST_SLIDING, self::SLOW_SLIDING => 0.05,
			self::STICKY => 2.0,
		};
	}

	/**
	 * Multiplier of the air drag (minecraft:air_drag_modifier).
	 */
	public function getAirDragModifier() : float{
		return match($this){
			self::NONE => 1.0,
			self::BOUNCY, self::FAST_FLAT, self::FAST_SLIDING, self::SLOW_SLIDING, self::STICKY, self::HIGH_RESISTANCE => 0.01,
			self::REGULAR, self::SLOW_FLAT, self::HOT => 0.1,
			self::SLOW_BOUNCY => 0.05,
			self::LIGHT => 1.8,
			self::EXPLOSIVE => 0.3,
		};
	}

	/**
	 * minecraft:knockback_resistance: negative values make the cube fly further than a normal mob.
	 */
	public function getKnockbackResistance() : float{
		return match($this){
			self::NONE => 0.0,
			self::BOUNCY, self::STICKY => -2.0,
			self::REGULAR, self::FAST_FLAT, self::LIGHT, self::EXPLOSIVE, self::HOT => -1.0,
			self::SLOW_BOUNCY => 0.4,
			self::SLOW_FLAT, self::FAST_SLIDING => 0.5,
			self::SLOW_SLIDING => 0.8,
			self::HIGH_RESISTANCE => 0.7,
		};
	}

	/**
	 * How hard players push the cube by walking into it (push_scale_self of the "ball" pushable_by_entity preset).
	 */
	public function getPushScale() : float{
		return match($this){
			self::NONE => 0.0,
			self::BOUNCY, self::FAST_FLAT, self::STICKY => 3.0,
			self::REGULAR, self::LIGHT, self::EXPLOSIVE, self::HOT => 2.0,
			self::SLOW_BOUNCY, self::SLOW_FLAT, self::HIGH_RESISTANCE => 0.3,
			self::FAST_SLIDING => 0.5,
			self::SLOW_SLIDING => 0.2,
		};
	}

	/**
	 * Speed above which a push plays the pushed_by_player sound (play_sound_impulse_threshold).
	 */
	public function getPushSoundThreshold() : float{
		return match($this){
			self::BOUNCY, self::FAST_FLAT => 0.2,
			self::REGULAR, self::LIGHT, self::STICKY, self::HOT => 0.1,
			default => 0.0,
		};
	}

	/**
	 * Ticks between two pushed_by_player sounds (play_sound_cooldown_in_seconds).
	 */
	public function getPushSoundCooldown() : int{
		return match($this){
			self::FAST_SLIDING => 8,
			self::SLOW_SLIDING, self::STICKY => 20,
			self::HIGH_RESISTANCE => 40,
			default => 14,
		};
	}

	/**
	 * Horizontal power of a player's hit (player.json apply_knockback_rules horizontal_power).
	 */
	public function getHitHorizontalPower() : float{
		return match($this){
			self::FAST_FLAT => 0.365,
			self::FAST_SLIDING => 0.265,
			default => 0.165,
		};
	}

	/**
	 * Vertical power of a player's hit (player.json apply_knockback_rules vertical_power).
	 */
	public function getHitVerticalPower() : float{
		return match($this){
			self::BOUNCY, self::REGULAR, self::SLOW_FLAT, self::HOT => 0.105,
			self::SLOW_BOUNCY => 0.24,
			self::LIGHT => 0.18,
			default => 0.09,
		};
	}

	/**
	 * Whether the cube floats in water and lava (minecraft:buoyant).
	 */
	public function isBuoyant() : bool{
		return match($this){
			self::BOUNCY, self::REGULAR, self::LIGHT, self::EXPLOSIVE, self::HOT => true,
			default => false,
		};
	}

	/**
	 * Vanilla friction formula with a modifier: 1 - (1 - friction) * modifier, clamped to 0-1.
	 */
	public static function modifyFriction(float $friction, float $modifier) : float{
		return max(0.0, min(1.0, 1.0 - (1.0 - $friction) * $modifier));
	}
}
