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

namespace pocketmine\entity\animal;

use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\riding\RideableSeat;
use pocketmine\item\Item;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use function mt_rand;

/**
 * Bedrock values (entities/mule.json): 15-30 health, movement 0.175, jump strength 0.5, 1.4x1.6. Born from a horse
 * and a donkey; can't breed. A tamed adult takes a chest (15 slots, see {@link AbstractHorse}).
 * Loot (loot_tables/entities/horse.json): 0-2 leather (+0-1 per Looting level); XP 1-3.
 */
class Mule extends AbstractHorse{

	public function canHaveChest() : bool{
		return true;
	}

	public static function getNetworkTypeId() : string{ return EntityIds::MULE; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.6, 1.4, 1.52);
	}

	public function getName() : string{
		return "Mule";
	}

	public function getSpawnBabyChance() : int{
		return 20; //entity_spawned: 80 adult / 20 baby
	}

	protected function randomStats() : array{
		return [mt_rand(15, 30), 0.175, 0.5];
	}

	public function isBreedingItem(Item $item) : bool{
		return false;
	}

	protected function getRiderSeat() : RideableSeat{
		return RideableSeat::at(0, 0.975, -0.2);
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		return new Mule($location);
	}
}
