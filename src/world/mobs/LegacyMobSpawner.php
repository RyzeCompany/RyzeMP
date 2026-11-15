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

use pocketmine\block\Grass;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\animal\Animal;
use pocketmine\entity\EntitySpawnRegistry;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Monster;
use pocketmine\entity\riding\Jockeys;
use pocketmine\entity\SpawnReason;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use pocketmine\YmlServerProperties;
use function cos;
use function count;
use function floor;
use function max;
use function min;
use function mt_rand;
use function sin;
use const M_PI;

/**
 * The simplified spawning used before the vanilla Bedrock algorithm (mobs.spawning.mode: legacy):
 * - packs spawn 24 to 44 blocks away from a player, in loaded chunks with calculated light, never within 24 blocks of
 *   any player;
 * - passive animals on grass blocks at the surface with light 9 or more;
 * - hostile mobs on solid opaque blocks with block light 0 and sky light (after the time of day) of 7 or less, i.e.
 *   at night or in caves, never in peaceful;
 * - each category has a cap per player, counting the mobs within 64 blocks of the player.
 *
 * Naturally spawned monsters are not persistent and despawn far from players (see {@link MobDespawner}). Animals are
 * persistent, as before.
 */
final class LegacyMobSpawner{
	private const MIN_DISTANCE = 24;
	private const MAX_DISTANCE = 44;
	private const CAP_RANGE = 64;
	private const ATTEMPTS_PER_PLAYER = 3;

	/**
	 * Entity identifier ({@link EntitySpawnRegistry}), weight, min pack, max pack.
	 * @phpstan-var list<array{string, int, int, int}>
	 */
	private const HOSTILE = [
		[EntityIds::ZOMBIE, 100, 2, 4],
		[EntityIds::SKELETON, 80, 1, 2],
		[EntityIds::CREEPER, 100, 1, 1],
		[EntityIds::SPIDER, 100, 1, 1]
	];
	/**
	 * @phpstan-var list<array{string, int, int, int}>
	 */
	private const PASSIVE = [
		[EntityIds::SHEEP, 12, 2, 3],
		[EntityIds::PIG, 10, 1, 3],
		[EntityIds::CHICKEN, 10, 2, 4],
		[EntityIds::COW, 8, 2, 3]
	];

	private int $hostileInterval;
	private int $passiveInterval;
	private int $hostileCap;
	private int $passiveCap;

	public function __construct(
		private World $world
	){
		$cfg = $world->getServer()->getConfigGroup();
		$this->hostileInterval = max(1, $cfg->getPropertyInt(YmlServerProperties::MOBS_SPAWNING_HOSTILE_INTERVAL, 20));
		$this->passiveInterval = max(1, $cfg->getPropertyInt(YmlServerProperties::MOBS_SPAWNING_PASSIVE_INTERVAL, 400));
		$this->hostileCap = max(0, $cfg->getPropertyInt(YmlServerProperties::MOBS_SPAWNING_HOSTILE_CAP, 20));
		$this->passiveCap = max(0, $cfg->getPropertyInt(YmlServerProperties::MOBS_SPAWNING_PASSIVE_CAP, 10));
	}

	public function tick(int $currentTick) : void{
		if($this->world->getDimension() !== Dimension::OVERWORLD){
			return;
		}
		$hostile = $currentTick % $this->hostileInterval === 0 && $this->hostileCap > 0 && $this->world->getDifficulty() !== World::DIFFICULTY_PEACEFUL;
		$passive = $currentTick % $this->passiveInterval === 0 && $this->passiveCap > 0;
		if(!$hostile && !$passive){
			return;
		}

		$players = [];
		foreach($this->world->getPlayers() as $player){
			if($player->isAlive() && !$player->isSpectator()){
				$players[] = $player;
			}
		}
		if(count($players) === 0){
			return;
		}

		$monsters = [];
		$animals = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof Monster){
				$monsters[] = $entity->getLocation();
			}elseif($entity instanceof Animal){
				$animals[] = $entity->getLocation();
			}
		}

		foreach($players as $player){
			if($hostile && self::countNear($monsters, $player) < $this->hostileCap){
				$this->spawnAround($player, $players, true);
			}
			if($passive && self::countNear($animals, $player) < $this->passiveCap){
				$this->spawnAround($player, $players, false);
			}
		}
	}

	/**
	 * @param Location[] $locations
	 */
	private static function countNear(array $locations, Player $player) : int{
		$pos = $player->getLocation();
		$count = 0;
		foreach($locations as $loc){
			if(($loc->x - $pos->x) ** 2 + ($loc->z - $pos->z) ** 2 <= self::CAP_RANGE ** 2){
				++$count;
			}
		}
		return $count;
	}

	/**
	 * @param Player[] $players
	 */
	private function spawnAround(Player $player, array $players, bool $hostile) : void{
		$center = $player->getLocation();
		for($attempt = 0; $attempt < self::ATTEMPTS_PER_PLAYER; ++$attempt){
			$angle = mt_rand(0, 359) / 180 * M_PI;
			$dist = mt_rand(self::MIN_DISTANCE, self::MAX_DISTANCE);
			$x = (int) floor($center->x + cos($angle) * $dist);
			$z = (int) floor($center->z + sin($angle) * $dist);
			$y = $hostile ? $this->findHostileY($x, (int) floor($center->y) + mt_rand(-16, 24), (int) floor($center->y) - 24, $z) : $this->findPassiveY($x, $z);
			if($y === null || !$this->farFromPlayers($x, $y, $z, $players)){
				continue;
			}
			[$typeId, , $min, $max] = self::pick($hostile ? self::HOSTILE : self::PASSIVE);
			$this->spawnPack($typeId, $x, $y, $z, mt_rand($min, $max), $hostile, $players);
			return;
		}
	}

	/**
	 * @phpstan-template T
	 * @phpstan-param list<array{T, int, int, int}> $table
	 * @phpstan-return array{T, int, int, int}
	 */
	private static function pick(array $table) : array{
		$total = 0;
		foreach($table as $entry){
			$total += $entry[1];
		}
		$r = mt_rand(0, $total - 1);
		foreach($table as $entry){
			$r -= $entry[1];
			if($r < 0){
				return $entry;
			}
		}
		return $table[0];
	}

	/**
	 * @param Player[] $players
	 */
	private function farFromPlayers(int $x, int $y, int $z, array $players) : bool{
		foreach($players as $player){
			$pos = $player->getLocation();
			if(($pos->x - $x) ** 2 + ($pos->y - $y) ** 2 + ($pos->z - $z) ** 2 < self::MIN_DISTANCE ** 2){
				return false;
			}
		}
		return true;
	}

	private function columnReady(int $x, int $z) : bool{
		$chunk = $this->world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		return $chunk !== null && $chunk->isLightPopulated() === true;
	}

	private function findPassiveY(int $x, int $z) : ?int{
		if(!$this->columnReady($x, $z)){
			return null;
		}
		$top = $this->world->getHighestBlockAt($x, $z);
		if($top === null){
			return null;
		}
		$y = $top + 1;
		return $this->canSpawnPassive($x, $y, $z) ? $y : null;
	}

	private function canSpawnPassive(int $x, int $y, int $z) : bool{
		return $this->world->getBlockAt($x, $y - 1, $z) instanceof Grass &&
			PathBlockTypes::isStandable($this->world, $x, $y, $z, 1, false) &&
			$this->world->getFullLightAt($x, $y, $z) >= 9;
	}

	/**
	 * Scans the column down from $fromY to $toY for a block where a monster can spawn.
	 */
	private function findHostileY(int $x, int $fromY, int $toY, int $z) : ?int{
		if(!$this->columnReady($x, $z)){
			return null;
		}
		$toY = max($this->world->getMinY() + 1, $toY);
		for($y = min($this->world->getMaxY() - 2, $fromY); $y >= $toY; --$y){
			if($this->canSpawnHostile($x, $y, $z)){
				return $y;
			}
		}
		return null;
	}

	private function canSpawnHostile(int $x, int $y, int $z) : bool{
		$below = PathBlockTypes::at($this->world, $x, $y - 1, $z);
		if(($below & PathBlockTypes::FLOOR) === 0 || ($below & PathBlockTypes::OPAQUE) === 0 || ($below & PathBlockTypes::DANGER) !== 0){
			return false;
		}
		if(!PathBlockTypes::isStandable($this->world, $x, $y, $z, 1, false)){
			return false;
		}
		return $this->world->getBlockLightAt($x, $y, $z) === 0 && $this->world->getRealBlockSkyLightAt($x, $y, $z) <= 7;
	}

	/**
	 * @param Player[] $players
	 */
	private function spawnPack(string $typeId, int $x, int $y, int $z, int $size, bool $hostile, array $players) : void{
		$cx = $x;
		$cz = $z;
		for($i = 0; $i < $size; ++$i){
			if($i > 0){
				//vanilla: each member is placed by a short random walk from the previous one
				$cx += mt_rand(-5, 5);
				$cz += mt_rand(-5, 5);
				$cy = $hostile ? $this->findHostileY($cx, $y + 3, $y - 4, $cz) : $this->findPassiveY($cx, $cz);
				if($cy === null || !$this->farFromPlayers($cx, $cy, $cz, $players)){
					continue;
				}
			}else{
				$cy = $y;
			}
			$location = new Location($cx + 0.5, $cy, $cz + 0.5, $this->world, mt_rand(0, 359), 0);
			$mob = EntitySpawnRegistry::getInstance()->create($typeId, $location);
			if(!$mob instanceof Mob){
				$mob?->close();
				return;
			}
			if($hostile){
				$mob->setPersistent(false);
			}
			$mob->setSpawnReason(SpawnReason::NATURAL);
			$mob->spawnToAll();
			//babies and jockeys (spider and chicken jockeys)
			Jockeys::onMobSpawned($mob, true);
		}
	}
}
