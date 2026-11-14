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

namespace pocketmine\block;

use pocketmine\block\utils\FacesOppositePlacingPlayerTrait;
use pocketmine\block\utils\HorizontalFacing;
use pocketmine\block\utils\HorizontalFacingTrait;
use pocketmine\data\runtime\RuntimeDataDescriber;

/**
 * A sculk sensor with twice the range and a shorter active phase. A redstone signal entering its input side (the
 * amethyst side, facing the player who placed it) makes it only listen to vibrations of that frequency. It outputs on
 * every side except the input.
 */
class CalibratedSculkSensor extends SculkSensor implements HorizontalFacing{
	use HorizontalFacingTrait;
	use FacesOppositePlacingPlayerTrait;

	public const RANGE = 16;
	public const ACTIVE_TICKS = 10;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		parent::describeBlockOnlyState($w);
		$w->horizontalFacing($this->facing);
	}

	public function getRange() : int{
		return self::RANGE;
	}

	protected function getActiveTicks() : int{
		return self::ACTIVE_TICKS;
	}

	/**
	 * Returns the redstone power received on the input side (0 if redstone is disabled).
	 */
	public function getInputPower() : int{
		$redstone = $this->position->getWorld()->getRedstone();
		return $redstone?->getPowerFrom($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ(), $this->facing) ?? 0;
	}

	public function canDetect(int $frequency) : bool{
		if(!parent::canDetect($frequency)){
			return false;
		}
		$input = $this->getInputPower();
		return $input === 0 || $input === $frequency;
	}

	public function getWeakPower(int $face) : int{
		return $face === $this->facing ? 0 : parent::getWeakPower($face);
	}

	public function getStrongPower(int $face) : int{
		return $face === $this->facing ? 0 : parent::getStrongPower($face);
	}

	public function connectsToRedstoneWire(int $face) : bool{
		return true;
	}
}
