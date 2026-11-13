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

use pocketmine\block\utils\RedstonePowerSource;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\math\Facing;
use function count;

abstract class SimplePressurePlate extends PressurePlate implements RedstonePowerSource{
	protected bool $pressed = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->bool($this->pressed);
	}

	public function isPressed() : bool{ return $this->pressed; }

	public function getWeakPower(int $face) : int{
		return $this->pressed ? 15 : 0;
	}

	public function getStrongPower(int $face) : int{
		return $face === Facing::DOWN ? $this->getWeakPower($face) : 0;
	}

	/** @return $this */
	public function setPressed(bool $pressed) : self{
		$this->pressed = $pressed;
		return $this;
	}

	protected function hasOutputSignal() : bool{
		return $this->pressed;
	}

	protected function calculatePlateState(array $entities) : array{
		$newPressed = count($entities) > 0;
		if($newPressed === $this->pressed){
			return [$this, null];
		}
		return [
			(clone $this)->setPressed($newPressed),
			$newPressed
		];
	}
}

