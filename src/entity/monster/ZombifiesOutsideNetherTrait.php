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

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\dimension\Dimension;

/**
 * Piglins, piglin brutes and hoglins turn into their zombified version after 15 seconds outside the Nether (vanilla
 * zombification_sensor + 15 s timer); going back to the Nether resets the timer. Mobs flagged immune (Java NBT
 * IsImmuneToZombification, e.g. set by map makers) never zombify.
 *
 * The using class calls {@link ZombifiesOutsideNetherTrait::tickZombification()} every tick, loads and saves the
 * immunity flag and implements {@link ZombifiesOutsideNetherTrait::zombify()}.
 */
trait ZombifiesOutsideNetherTrait{
	private int $outsideNetherTicks = 0;
	private bool $immuneToZombification = false;

	public static function getZombificationTicks() : int{
		return 300;
	}

	public function isImmuneToZombification() : bool{
		return $this->immuneToZombification;
	}

	public function setImmuneToZombification(bool $immune) : void{
		$this->immuneToZombification = $immune;
	}

	protected function loadZombificationImmunity(CompoundTag $nbt) : void{
		$this->immuneToZombification = $nbt->getByte("IsImmuneToZombification", 0) !== 0;
	}

	protected function saveZombificationImmunity(CompoundTag $nbt) : void{
		if($this->immuneToZombification){
			$nbt->setByte("IsImmuneToZombification", 1);
		}
	}

	/**
	 * Replaces the mob with its zombified version.
	 */
	abstract protected function zombify() : void;

	public function getOutsideNetherTicks() : int{
		return $this->outsideNetherTicks;
	}

	protected function tickZombification(int $tickDiff) : void{
		if($this->immuneToZombification || $this->getWorld()->getDimension() === Dimension::NETHER || $this->isFlaggedForDespawn()){
			if($this->outsideNetherTicks !== 0){
				$this->outsideNetherTicks = 0;
				$this->networkPropertiesDirty = true;
			}
			return;
		}
		if($this->outsideNetherTicks === 0){
			$this->networkPropertiesDirty = true;
		}
		$this->outsideNetherTicks += $tickDiff;
		if($this->outsideNetherTicks >= self::getZombificationTicks()){
			$this->zombify();
		}
	}

	/**
	 * Whether the mob is shaking because it is about to zombify (client: query.is_shaking).
	 */
	public function isZombifying() : bool{
		return $this->outsideNetherTicks > 0;
	}
}
