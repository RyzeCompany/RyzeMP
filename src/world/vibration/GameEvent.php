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

namespace pocketmine\world\vibration;

/**
 * Game events which cause vibrations that sculk sensors can detect. Each one has a frequency (1-15), which a
 * comparator reading an active sculk sensor outputs, and which a calibrated sculk sensor can filter.
 *
 * Frequencies are the Bedrock ones from the Minecraft Wiki "Vibration frequencies" table.
 */
enum GameEvent{
	case STEP;
	case SWIM;
	case FLAP;
	case PROJECTILE_LAND;
	case HIT_GROUND;
	case SPLASH;
	case ITEM_INTERACT_FINISH;
	case PROJECTILE_SHOOT;
	case INSTRUMENT_PLAY;
	case ENTITY_ACTION;
	case ELYTRA_GLIDE;
	case UNEQUIP;
	case ENTITY_DISMOUNT;
	case EQUIP;
	case ENTITY_MOUNT;
	case ENTITY_INTERACT;
	case SHEAR;
	case ENTITY_DAMAGE;
	case DRINK;
	case EAT;
	case CONTAINER_CLOSE;
	case BLOCK_CLOSE;
	case BLOCK_DEACTIVATE;
	case BLOCK_DETACH;
	case CONTAINER_OPEN;
	case BLOCK_OPEN;
	case BLOCK_ACTIVATE;
	case BLOCK_ATTACH;
	case PRIME_FUSE;
	case NOTE_BLOCK_PLAY;
	case BLOCK_CHANGE;
	case BLOCK_DESTROY;
	case FLUID_PICKUP;
	case BLOCK_PLACE;
	case FLUID_PLACE;
	case ENTITY_PLACE;
	case LIGHTNING_STRIKE;
	case TELEPORT;
	case ENTITY_DIE;
	case EXPLODE;
	/** A sculk shrieker shrieking: only wardens hear it (Java GameEvent.SHRIEK, not a vibration frequency). */
	case SHRIEK;
	/**
	 * An amethyst block next to an activating sculk sensor repeating the frequency the sensor detected (Java
	 * GameEvent.RESONATE_1..15): calibrated sensors far away can pick a frequency out of it (wireless redstone).
	 */
	case RESONATE_1;
	case RESONATE_2;
	case RESONATE_3;
	case RESONATE_4;
	case RESONATE_5;
	case RESONATE_6;
	case RESONATE_7;
	case RESONATE_8;
	case RESONATE_9;
	case RESONATE_10;
	case RESONATE_11;
	case RESONATE_12;
	case RESONATE_13;
	case RESONATE_14;
	case RESONATE_15;

	/**
	 * Returns the amethyst resonance event with the given frequency (1-15) (Java
	 * VibrationSystem.getResonanceEventByFrequency()).
	 */
	public static function resonance(int $frequency) : self{
		return match($frequency){
			1 => self::RESONATE_1,
			2 => self::RESONATE_2,
			3 => self::RESONATE_3,
			4 => self::RESONATE_4,
			5 => self::RESONATE_5,
			6 => self::RESONATE_6,
			7 => self::RESONATE_7,
			8 => self::RESONATE_8,
			9 => self::RESONATE_9,
			10 => self::RESONATE_10,
			11 => self::RESONATE_11,
			12 => self::RESONATE_12,
			13 => self::RESONATE_13,
			14 => self::RESONATE_14,
			15 => self::RESONATE_15,
			default => throw new \InvalidArgumentException("Frequency must be in range 1 ... 15, got $frequency")
		};
	}

	/**
	 * Returns the vibration frequency (1-15) of this event, 0 for an event sculk sensors don't hear.
	 */
	public function getFrequency() : int{
		return match($this){
			self::STEP, self::SWIM, self::FLAP => 1,
			self::PROJECTILE_LAND, self::HIT_GROUND, self::SPLASH => 2,
			self::ITEM_INTERACT_FINISH, self::PROJECTILE_SHOOT, self::INSTRUMENT_PLAY => 3,
			self::ENTITY_ACTION, self::ELYTRA_GLIDE, self::UNEQUIP => 4,
			self::ENTITY_DISMOUNT, self::EQUIP => 5,
			self::ENTITY_MOUNT, self::ENTITY_INTERACT, self::SHEAR => 6,
			self::ENTITY_DAMAGE => 7,
			self::DRINK, self::EAT => 8,
			self::CONTAINER_CLOSE, self::BLOCK_CLOSE, self::BLOCK_DEACTIVATE, self::BLOCK_DETACH => 9,
			self::CONTAINER_OPEN, self::BLOCK_OPEN, self::BLOCK_ACTIVATE, self::BLOCK_ATTACH, self::PRIME_FUSE, self::NOTE_BLOCK_PLAY => 10,
			self::BLOCK_CHANGE => 11,
			self::BLOCK_DESTROY, self::FLUID_PICKUP => 12,
			self::BLOCK_PLACE, self::FLUID_PLACE => 13,
			self::ENTITY_PLACE, self::LIGHTNING_STRIKE, self::TELEPORT => 14,
			self::ENTITY_DIE, self::EXPLODE => 15,
			self::SHRIEK => 0,
			self::RESONATE_1 => 1,
			self::RESONATE_2 => 2,
			self::RESONATE_3 => 3,
			self::RESONATE_4 => 4,
			self::RESONATE_5 => 5,
			self::RESONATE_6 => 6,
			self::RESONATE_7 => 7,
			self::RESONATE_8 => 8,
			self::RESONATE_9 => 9,
			self::RESONATE_10 => 10,
			self::RESONATE_11 => 11,
			self::RESONATE_12 => 12,
			self::RESONATE_13 => 13,
			self::RESONATE_14 => 14,
			self::RESONATE_15 => 15,
		};
	}

	/**
	 * Returns whether sculk sensors detect this event (otherwise only listeners such as wardens hear it).
	 */
	public function isHeardBySensors() : bool{
		return $this !== self::SHRIEK;
	}

	/**
	 * Returns whether a sneaking entity causing this event makes no vibration.
	 */
	public function isIgnoredWhenSneaking() : bool{
		return match($this){
			self::STEP, self::SWIM, self::FLAP, self::HIT_GROUND, self::SPLASH, self::ITEM_INTERACT_FINISH, self::PROJECTILE_SHOOT => true,
			default => false,
		};
	}

	/**
	 * Returns whether this event makes no vibration when it happens on wool or a carpet (footsteps, landing).
	 */
	public function isDampenedByWool() : bool{
		return match($this){
			self::STEP, self::HIT_GROUND, self::PROJECTILE_LAND => true,
			default => false,
		};
	}
}
