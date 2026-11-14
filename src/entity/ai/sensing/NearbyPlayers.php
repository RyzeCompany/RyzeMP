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

namespace pocketmine\entity\ai\sensing;

use pocketmine\entity\Entity;
use pocketmine\entity\Mob;
use pocketmine\math\AxisAlignedBB;
use pocketmine\player\Player;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function count;
use function floor;
use function usort;

/**
 * @internal
 * The players {@link World::getNearbyEntities()} returns, in the same order, worked out from the world's players: for
 * target searches that can only accept players while the world has none of the other classes they look at (slimes and
 * breezes with no golems around...). Their search box holds every mob around, but rarely more than one player.
 */
final class NearbyPlayers{

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the players World::getNearbyEntities($bb, $exclude) would return, in its order (chunk by chunk, X then Z,
	 * and in the order of the chunk's entity list), or null when the entity index doesn't have a player in the chunk of
	 * its position (callers then search the box as usual).
	 *
	 * @return Player[]|null
	 * @phpstan-return list<Player>|null
	 */
	public static function inBox(World $world, AxisAlignedBB $bb, ?Entity $exclude = null) : ?array{
		//the chunks getNearbyEntities() looks at
		$minX = ((int) floor($bb->minX - 2)) >> Chunk::COORD_BIT_SIZE;
		$maxX = ((int) floor($bb->maxX + 2)) >> Chunk::COORD_BIT_SIZE;
		$minZ = ((int) floor($bb->minZ - 2)) >> Chunk::COORD_BIT_SIZE;
		$maxZ = ((int) floor($bb->maxZ + 2)) >> Chunk::COORD_BIT_SIZE;

		/** @phpstan-var array<int, array{int, int, list<Player>}> $byChunk */
		$byChunk = [];
		$found = 0;
		$last = null;
		foreach($world->getPlayers() as $player){
			//the same test as getNearbyEntities()
			if($player === $exclude || !$player->boundingBox->intersectsWith($bb)){
				continue;
			}
			//the entity index keeps every entity in the list of exactly one chunk, the one of its last indexed position:
			//normally its position now, which is checked
			$pos = Mob::getLiveLocationOf($player) ?? $player->getPosition();
			$chunkX = ((int) floor($pos->x)) >> Chunk::COORD_BIT_SIZE;
			$chunkZ = ((int) floor($pos->z)) >> Chunk::COORD_BIT_SIZE;
			if(($world->getChunkEntities($chunkX, $chunkZ)[$player->getId()] ?? null) !== $player){
				return null;
			}
			if($chunkX < $minX || $chunkX > $maxX || $chunkZ < $minZ || $chunkZ > $maxZ){
				continue;
			}
			$hash = World::chunkHash($chunkX, $chunkZ);
			if(isset($byChunk[$hash])){
				$byChunk[$hash][2][] = $player;
			}else{
				$byChunk[$hash] = [$chunkX, $chunkZ, [$player]];
			}
			++$found;
			$last = $player;
		}
		if($found <= 1){
			return $last === null ? [] : [$last];
		}

		//several players: in the order getNearbyEntities() visits them
		usort($byChunk, fn(array $a, array $b) : int => $a[0] !== $b[0] ? $a[0] <=> $b[0] : $a[1] <=> $b[1]);
		$result = [];
		foreach($byChunk as [$chunkX, $chunkZ, $players]){
			if(count($players) === 1){
				$result[] = $players[0];
				continue;
			}
			foreach($world->getChunkEntities($chunkX, $chunkZ) as $entity){
				foreach($players as $player){
					if($entity === $player){
						$result[] = $player;
						break;
					}
				}
			}
		}
		return $result;
	}
}
