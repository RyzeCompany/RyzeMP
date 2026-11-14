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

use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\map\MapManager;

/**
 * Using an empty map creates a new map of the area around the player. An empty locator map (made with a compass)
 * creates a map that shows the player markers.
 */
class EmptyMap extends Item{
	/** Item meta of the plain empty map */
	public const META_EMPTY = 0;
	/**
	 * Item meta that BedrockData recipes use as an input next to a compass; it behaves like the plain empty map.
	 */
	public const META_EMPTY_LEGACY = 1;
	/** Item meta of the empty locator map */
	public const META_LOCATOR = 2;

	private int $mapMeta = self::META_EMPTY;

	protected function describeState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(self::META_EMPTY, self::META_LOCATOR, $this->mapMeta);
	}

	public function getMapMeta() : int{ return $this->mapMeta; }

	/** @return $this */
	public function setMapMeta(int $meta) : self{
		if($meta !== self::META_EMPTY && $meta !== self::META_EMPTY_LEGACY && $meta !== self::META_LOCATOR){
			throw new \InvalidArgumentException("Unknown empty map meta $meta");
		}
		$this->mapMeta = $meta;
		return $this;
	}

	public function isLocator() : bool{
		return $this->mapMeta === self::META_LOCATOR;
	}

	/** @return $this */
	public function setLocator(bool $locator) : self{
		$this->mapMeta = $locator ? self::META_LOCATOR : self::META_EMPTY;
		return $this;
	}

	public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems) : ItemUseResult{
		$position = $player->getPosition();
		$mapData = MapManager::getInstance()->createMap($player->getWorld(), $position->getFloorX(), $position->getFloorZ());

		$this->pop();
		$returnedItems[] = VanillaItems::FILLED_MAP()
			->setMapType($this->isLocator() ? FilledMap::TYPE_LOCATOR : FilledMap::TYPE_MAP)
			->setMapId($mapData->getId())
			->setScale($mapData->getScale())
			->setDisplayPlayers($this->isLocator());

		return ItemUseResult::SUCCESS;
	}
}
