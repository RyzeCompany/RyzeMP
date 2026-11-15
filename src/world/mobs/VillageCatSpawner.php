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

namespace pocketmine\world\mobs;

use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\animal\Animal;
use pocketmine\entity\animal\Cat;
use pocketmine\entity\Location;
use pocketmine\entity\SpawnReason;
use pocketmine\event\entity\EntityNaturalSpawnEvent;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\raid\VillageScanner;
use pocketmine\world\World;
use function floor;
use function intdiv;
use function min;
use function mt_rand;

/**
 * Spawns stray cats in villages (Bedrock population "cat", https://minecraft.wiki/w/Cat#Spawning): a village wants one
 * cat per 4 claimed beds, at most 5; every {@link VillageCatSpawner::INTERVAL} ticks, each village a player is in that
 * has fewer cats inside its boundary tries to spawn one in the 17x13x17 blocks around its centre, a baby 25% of the
 * time. Untamed village cats despawn far from players like other animals (Bedrock cat.json: despawn_from_distance);
 * taming makes them persistent.
 */
final class VillageCatSpawner{
	public const INTERVAL = 1200;
	public const BEDS_PER_CAT = 4;
	public const MAX_CATS = 5;
	public const BABY_CHANCE = 25;
	private const ATTEMPTS = 4;

	public function __construct(
		private World $world
	){}

	public function tick(int $currentTick) : void{
		if($currentTick % self::INTERVAL !== 0 || $this->world->getDimension() !== Dimension::OVERWORLD){
			return;
		}
		$done = [];
		foreach($this->world->getPlayers() as $player){
			if($player->isSpectator() || !$player->isAlive()){
				continue;
			}
			$village = VillageScanner::findVillageAt($this->world, $player->getPosition());
			if($village === null){
				continue;
			}
			$center = $village->getCenter();
			$key = World::blockHash((int) floor($center->x), (int) floor($center->y), (int) floor($center->z));
			if(isset($done[$key])){
				continue;
			}
			$done[$key] = true;
			$wanted = min(self::MAX_CATS, intdiv($village->getClaimedBedCount(), self::BEDS_PER_CAT));
			$cats = 0;
			foreach($this->world->getNearbyEntities($village->getBounds()) as $entity){
				if($entity instanceof Cat && $entity->isAlive()){
					++$cats;
				}
			}
			if($cats < $wanted){
				$this->spawnCat((int) floor($center->x), (int) floor($center->y), (int) floor($center->z));
			}
		}
	}

	private function spawnCat(int $cx, int $cy, int $cz) : ?Cat{
		for($i = 0; $i < self::ATTEMPTS; ++$i){
			$x = $cx + mt_rand(-8, 8);
			$z = $cz + mt_rand(-8, 8);
			for($y = $cy + 6; $y >= $cy - 6; --$y){
				if($this->world->isInWorld($x, $y, $z) && $this->world->isChunkLoaded($x >> 4, $z >> 4) &&
					PathBlockTypes::isStandable($this->world, $x, $y, $z, 1, false)
				){
					$cat = new Cat(new Location($x + 0.5, $y, $z + 0.5, $this->world, mt_rand(0, 359), 0));
					if(mt_rand(0, 99) < self::BABY_CHANCE){
						$cat->setAge(Animal::BABY_AGE);
					}
					$cat->setPersistent(false);
					$cat->setSpawnReason(SpawnReason::VILLAGE_CAT);
					$ev = new EntityNaturalSpawnEvent($cat, EntityNaturalSpawnEvent::CAUSE_VILLAGE_CAT, true);
					$ev->call();
					if($ev->isCancelled() || $cat->isClosed()){
						$cat->close();
						return null;
					}
					$cat->spawnToAll();
					return $cat;
				}
			}
		}
		return null;
	}
}
