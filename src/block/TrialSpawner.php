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

use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;

/** Block and states used by trial chamber templates. */
class TrialSpawner extends Transparent{
	public const STATE_INACTIVE = 0;
	public const STATE_WAITING_FOR_PLAYERS = 1;
	public const STATE_ACTIVE = 2;
	public const STATE_WAITING_FOR_REWARD_EJECTION = 3;
	public const STATE_EJECTING_REWARD = 4;
	public const STATE_COOLDOWN = 5;

	protected int $spawnerState = self::STATE_INACTIVE;
	protected bool $ominous = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(self::STATE_INACTIVE, self::STATE_COOLDOWN, $this->spawnerState);
		$w->bool($this->ominous);
	}

	public function getSpawnerState() : int{ return $this->spawnerState; }

	/** @return $this */
	public function setSpawnerState(int $state) : self{
		if($state < self::STATE_INACTIVE || $state > self::STATE_COOLDOWN){
			throw new \InvalidArgumentException("Trial spawner state must be in range " . self::STATE_INACTIVE . " ... " . self::STATE_COOLDOWN);
		}
		$this->spawnerState = $state;
		return $this;
	}

	public function isOminous() : bool{ return $this->ominous; }

	/** @return $this */
	public function setOminous(bool $ominous) : self{
		$this->ominous = $ominous;
		return $this;
	}

	public function getLightLevel() : int{
		return match($this->spawnerState){
			self::STATE_INACTIVE, self::STATE_COOLDOWN => 4,
			default => 8
		};
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	public function isAffectedBySilkTouch() : bool{
		return false;
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}
}
