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

namespace pocketmine\item;

use pocketmine\nbt\tag\CompoundTag;

/**
 * Compass linked to a lodestone. The client asks the server where the lodestone is using the tracking handle
 * (see PositionTrackingDB).
 */
class LodestoneCompass extends Item{
	public const TAG_TRACKING_HANDLE = "trackingHandle"; //TAG_Int

	private int $trackingHandle = 0;

	/**
	 * Returns the position tracking handle of the linked lodestone, 0 if not linked.
	 */
	public function getTrackingHandle() : int{
		return $this->trackingHandle;
	}

	/** @return $this */
	public function setTrackingHandle(int $trackingHandle) : self{
		$this->trackingHandle = $trackingHandle;
		return $this;
	}

	protected function deserializeCompoundTag(CompoundTag $tag) : void{
		parent::deserializeCompoundTag($tag);
		$this->trackingHandle = $tag->getInt(self::TAG_TRACKING_HANDLE, 0);
	}

	protected function serializeCompoundTag(CompoundTag $tag) : void{
		parent::serializeCompoundTag($tag);
		if($this->trackingHandle !== 0){
			$tag->setInt(self::TAG_TRACKING_HANDLE, $this->trackingHandle);
		}else{
			$tag->removeTag(self::TAG_TRACKING_HANDLE);
		}
	}
}
