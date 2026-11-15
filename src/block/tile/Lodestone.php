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

namespace pocketmine\block\tile;

use pocketmine\nbt\tag\CompoundTag;

/**
 * Lodestone block entity. Stores the position tracking handle that lodestone compasses linked to it refer to.
 */
class Lodestone extends Tile{
	public const TAG_TRACKING_HANDLE = "trackingHandle"; //TAG_Int, optional

	private ?int $trackingHandle = null;

	public function getTrackingHandle() : ?int{
		return $this->trackingHandle;
	}

	public function setTrackingHandle(?int $trackingHandle) : void{
		$this->trackingHandle = $trackingHandle;
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$handle = $nbt->getInt(self::TAG_TRACKING_HANDLE, 0);
		$this->trackingHandle = $handle > 0 ? $handle : null;
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		if($this->trackingHandle !== null){
			$nbt->setInt(self::TAG_TRACKING_HANDLE, $this->trackingHandle);
		}
	}
}
