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

use pocketmine\entity\Entity;
use pocketmine\nbt\tag\CompoundTag;

/**
 * @internal
 * The timers of a mob turning into another one after some time underwater (vanilla zombie start_drowned_transformation
 * and husk start_zombie_transformation): an environment_sensor is_underwater starts a 30 second timer, which leaving the
 * water resets; when it runs out the mob shakes (is_shaking) and a transformation with a 15 second delay starts, which
 * nothing stops any more.
 */
final class UnderwaterConversionTimer{
	public const TAG_UNDERWATER_TICKS = "UnderwaterTicks"; //TAG_Int (PocketMine)
	public const TAG_CONVERSION_TICKS = "ConversionTicks"; //TAG_Int (PocketMine)

	public const UNDERWATER_TICKS = 600;
	public const CONVERSION_TICKS = 300;

	/** Not converting (out of the water, or the underwater timer is running). */
	public const STATE_NONE = 0;
	/** The underwater timer ran out on this tick: the mob starts shaking. */
	public const STATE_STARTED = 1;
	/** Shaking, waiting for the transformation delay. */
	public const STATE_CONVERTING = 2;
	/** The transformation delay ran out on this tick: the mob must be replaced now. */
	public const STATE_DONE = 3;

	private int $underwaterTicks = 0;
	/** Ticks left before the transformation, -1 when not converting. */
	private int $conversionTicks = -1;

	public static function fromNBT(CompoundTag $nbt) : self{
		$timer = new self();
		$timer->underwaterTicks = $nbt->getInt(self::TAG_UNDERWATER_TICKS, 0);
		$timer->conversionTicks = $nbt->getInt(self::TAG_CONVERSION_TICKS, -1);
		return $timer;
	}

	public function saveNBT(CompoundTag $nbt) : void{
		$nbt->setInt(self::TAG_UNDERWATER_TICKS, $this->underwaterTicks);
		$nbt->setInt(self::TAG_CONVERSION_TICKS, $this->conversionTicks);
	}

	public function isConverting() : bool{
		return $this->conversionTicks >= 0;
	}

	public function getUnderwaterTicks() : int{
		return $this->underwaterTicks;
	}

	public function getConversionTicks() : int{
		return $this->conversionTicks;
	}

	/**
	 * Advances the timers of the entity by $tickDiff ticks and returns one of the STATE_* constants. Only a counter
	 * and the cheap {@link Entity::isUnderwater()} check: run from the entity's base tick.
	 */
	public function tick(Entity $entity, int $tickDiff) : int{
		if($this->conversionTicks >= 0){
			$this->conversionTicks -= $tickDiff;
			return $this->conversionTicks < 0 ? self::STATE_DONE : self::STATE_CONVERTING;
		}
		if(!$entity->isUnderwater()){
			$this->underwaterTicks = 0;
			return self::STATE_NONE;
		}
		$this->underwaterTicks += $tickDiff;
		if($this->underwaterTicks < self::UNDERWATER_TICKS){
			return self::STATE_NONE;
		}
		$this->conversionTicks = self::CONVERSION_TICKS;
		return self::STATE_STARTED;
	}
}
