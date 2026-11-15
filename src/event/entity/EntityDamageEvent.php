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

namespace pocketmine\event\entity;

use pocketmine\entity\Entity;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use function array_sum;
use function max;

/**
 * Called when an entity takes damage.
 * @phpstan-extends EntityEvent<Entity>
 */
class EntityDamageEvent extends EntityEvent implements Cancellable{
	use CancellableTrait;

	public const MODIFIER_ARMOR = 1;
	public const MODIFIER_STRENGTH = 2;
	public const MODIFIER_WEAKNESS = 3;
	public const MODIFIER_RESISTANCE = 4;
	public const MODIFIER_ABSORPTION = 5;
	public const MODIFIER_ARMOR_ENCHANTMENTS = 6;
	public const MODIFIER_CRITICAL = 7;
	public const MODIFIER_TOTEM = 8;
	public const MODIFIER_WEAPON_ENCHANTMENTS = 9;
	public const MODIFIER_PREVIOUS_DAMAGE_COOLDOWN = 10;
	public const MODIFIER_ARMOR_HELMET = 11;
	/** Extra damage of a mace smash attack (fall distance and Density). */
	public const MODIFIER_SMASH = 12;

	public const CAUSE_CONTACT = 0;
	public const CAUSE_ENTITY_ATTACK = 1;
	public const CAUSE_PROJECTILE = 2;
	public const CAUSE_SUFFOCATION = 3;
	public const CAUSE_FALL = 4;
	public const CAUSE_FIRE = 5;
	public const CAUSE_FIRE_TICK = 6;
	public const CAUSE_LAVA = 7;
	public const CAUSE_DROWNING = 8;
	public const CAUSE_BLOCK_EXPLOSION = 9;
	public const CAUSE_ENTITY_EXPLOSION = 10;
	public const CAUSE_VOID = 11;
	public const CAUSE_SUICIDE = 12;
	public const CAUSE_MAGIC = 13;
	public const CAUSE_CUSTOM = 14;
	public const CAUSE_STARVATION = 15;
	public const CAUSE_FALLING_BLOCK = 16;
	/** Flying into a wall while gliding with an elytra (kinetic energy). */
	public const CAUSE_FLY_INTO_WALL = 17;
	/** Struck by a lightning bolt. */
	public const CAUSE_LIGHTNING = 18;
	/** Freezing in powder snow. */
	public const CAUSE_FREEZE = 19;
	/** The Wither effect (bypasses armour). */
	public const CAUSE_WITHER = 20;
	/** The Thorns enchantment of the armour of the entity that was attacked (reduced by armour). */
	public const CAUSE_THORNS = 21;
	/** Standing on a magma block or touching a hot sulfur cube ("hot floor", fire damage). */
	public const CAUSE_MAGMA = 22;
	/** Standing in a lit campfire or soul campfire (fire damage). */
	public const CAUSE_CAMPFIRE = 23;
	/** Moving through a sweet berry bush. */
	public const CAUSE_SWEET_BERRY_BUSH = 24;
	/** Hit by a falling pointed dripstone stalactite. */
	public const CAUSE_STALACTITE = 25;
	/** Falling onto a pointed dripstone stalagmite (fall damage that bypasses armour). */
	public const CAUSE_STALAGMITE = 26;
	/** The Warden's sonic boom (bypasses armour and protection enchantments). */
	public const CAUSE_SONIC_BOOM = 27;
	/** Dragon's breath clouds (bypasses armour). */
	public const CAUSE_DRAGON_BREATH = 28;
	/** Hit by a blaze or ghast fireball, or a fire charge (projectile and fire damage). */
	public const CAUSE_FIREBALL = 29;
	/** Hit by a wither skull (projectile damage). */
	public const CAUSE_WITHER_SKULL = 30;

	private float $baseDamage;
	private float $originalBase;

	/** @var float[] */
	private array $originals;
	private int $attackCooldown = 10;

	/**
	 * @param float[] $modifiers
	 */
	public function __construct(
		Entity $entity,
		private int $cause,
		float $damage,
		private array $modifiers = []
	){
		$this->entity = $entity;
		$this->baseDamage = $this->originalBase = $damage;
		$this->originals = $modifiers;
	}

	public function getCause() : int{
		return $this->cause;
	}

	/**
	 * Returns the base amount of damage applied, before modifiers.
	 */
	public function getBaseDamage() : float{
		return $this->baseDamage;
	}

	/**
	 * Sets the base amount of damage applied, optionally recalculating modifiers.
	 *
	 * TODO: add ability to recalculate modifiers when this is set
	 */
	public function setBaseDamage(float $damage) : void{
		$this->baseDamage = $damage;
	}

	/**
	 * Returns the original base amount of damage applied, before alterations by plugins.
	 */
	public function getOriginalBaseDamage() : float{
		return $this->originalBase;
	}

	/**
	 * @return float[]
	 */
	public function getOriginalModifiers() : array{
		return $this->originals;
	}

	public function getOriginalModifier(int $type) : float{
		return $this->originals[$type] ?? 0.0;
	}

	/**
	 * @return float[]
	 */
	public function getModifiers() : array{
		return $this->modifiers;
	}

	public function getModifier(int $type) : float{
		return $this->modifiers[$type] ?? 0.0;
	}

	public function setModifier(float $damage, int $type) : void{
		$this->modifiers[$type] = $damage;
	}

	public function isApplicable(int $type) : bool{
		return isset($this->modifiers[$type]);
	}

	public function getFinalDamage() : float{
		return max(0, $this->baseDamage + array_sum($this->modifiers));
	}

	/**
	 * Returns whether an entity can use armour points to reduce this type of damage.
	 */
	public function canBeReducedByArmor() : bool{
		switch($this->cause){
			case self::CAUSE_FIRE_TICK:
			case self::CAUSE_SUFFOCATION:
			case self::CAUSE_DROWNING:
			case self::CAUSE_STARVATION:
			case self::CAUSE_FALL:
			case self::CAUSE_FLY_INTO_WALL:
			case self::CAUSE_VOID:
			case self::CAUSE_MAGIC:
			case self::CAUSE_SUICIDE:
			case self::CAUSE_WITHER:
			case self::CAUSE_STALAGMITE:
			case self::CAUSE_SONIC_BOOM:
			case self::CAUSE_DRAGON_BREATH:
				return false;

		}

		return true;
	}

	/**
	 * Returns whether protection enchantments on the worn armour can reduce this type of damage. Only the Warden's sonic
	 * boom bypasses them (vanilla bypasses_enchantments).
	 */
	public function canBeReducedByArmorEnchantments() : bool{
		return $this->cause !== self::CAUSE_SONIC_BOOM;
	}

	/**
	 * Returns whether the given cause is fire damage (vanilla is_fire): fire immune entities ignore it, the Fire
	 * Resistance effect cancels it, Fire Protection reduces it and the firedamage game rule turns it off for players.
	 */
	public static function isFireCause(int $cause) : bool{
		return match($cause){
			self::CAUSE_FIRE,
			self::CAUSE_FIRE_TICK,
			self::CAUSE_LAVA,
			self::CAUSE_MAGMA,
			self::CAUSE_CAMPFIRE,
			self::CAUSE_FIREBALL => true,
			default => false
		};
	}

	/**
	 * Returns whether the given cause is a projectile hit (vanilla is_projectile): shields block it and Projectile
	 * Protection reduces it.
	 */
	public static function isProjectileCause(int $cause) : bool{
		return $cause === self::CAUSE_PROJECTILE || $cause === self::CAUSE_FIREBALL || $cause === self::CAUSE_WITHER_SKULL;
	}

	/**
	 * Returns the cooldown in ticks before the target entity can be attacked again.
	 */
	public function getAttackCooldown() : int{
		return $this->attackCooldown;
	}

	/**
	 * Sets the cooldown in ticks before the target entity can be attacked again.
	 *
	 * NOTE: This value is not used in non-Living entities
	 */
	public function setAttackCooldown(int $attackCooldown) : void{
		$this->attackCooldown = $attackCooldown;
	}
}
