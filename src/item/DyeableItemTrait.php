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

use pocketmine\color\Color;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\utils\Binary;

/**
 * Colour storage of a {@link DyeableItem}: the "customColor" int tag (ARGB) also used by leather armour and BDS.
 */
trait DyeableItemTrait{
	protected ?Color $customColor = null;

	public function getCustomColor() : ?Color{
		return $this->customColor;
	}

	/**
	 * @return $this
	 */
	public function setCustomColor(Color $color) : self{
		$this->customColor = $color;
		return $this;
	}

	/**
	 * @return $this
	 */
	public function clearCustomColor() : self{
		$this->customColor = null;
		return $this;
	}

	protected function deserializeCompoundTag(CompoundTag $tag) : void{
		parent::deserializeCompoundTag($tag);
		$colorTag = $tag->getTag(Armor::TAG_CUSTOM_COLOR);
		$this->customColor = $colorTag instanceof IntTag ? Color::fromARGB(Binary::unsignInt($colorTag->getValue())) : null;
	}

	protected function serializeCompoundTag(CompoundTag $tag) : void{
		parent::serializeCompoundTag($tag);
		$this->customColor !== null ?
			$tag->setInt(Armor::TAG_CUSTOM_COLOR, Binary::signInt($this->customColor->toARGB())) :
			$tag->removeTag(Armor::TAG_CUSTOM_COLOR);
	}
}
