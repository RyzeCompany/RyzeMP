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

namespace pocketmine\world\generator\vanilla\feature;

use pocketmine\block\tile\Tile;
use pocketmine\block\tile\TileFactory;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\BlockTransaction;
use pocketmine\world\World;

/**
 * @internal
 *
 * A feature placed by {@link WorldFeatureGrower}: the changed blocks and the block entity data of the new blocks (the
 * bees of a bee nest).
 */
final class FeatureGrowth{

	/**
	 * @param CompoundTag[] $tiles
	 * @phpstan-param list<CompoundTag> $tiles
	 */
	public function __construct(
		public readonly BlockTransaction $transaction,
		private readonly array $tiles
	){}

	/**
	 * Applies the blocks, then gives the new blocks their block entity data. Returns whether anything changed.
	 */
	public function apply(World $world) : bool{
		if(!$this->transaction->apply()){
			return false;
		}
		$factory = TileFactory::getInstance();
		foreach($this->tiles as $nbt){
			$x = $nbt->getInt(Tile::TAG_X);
			$y = $nbt->getInt(Tile::TAG_Y);
			$z = $nbt->getInt(Tile::TAG_Z);
			if(!$world->isInWorld($x, $y, $z)){
				continue;
			}
			try{
				$tile = $factory->createFromData($world, $nbt);
			}catch(SavedDataLoadingException){
				continue;
			}
			$expected = $world->getBlockAt($x, $y, $z, addToCache: false)->getIdInfo()->getTileClass();
			if($tile === null || $expected === null || !($tile instanceof $expected)){
				continue;
			}
			$world->getTileAt($x, $y, $z)?->close();
			$world->addTile($tile);
		}
		return true;
	}
}
