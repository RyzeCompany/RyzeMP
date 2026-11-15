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

use pocketmine\block\Leaves;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Pillager;
use pocketmine\entity\SpawnReason;
use pocketmine\entity\utils\MobBiomeInfo;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\format\Chunk;
use pocketmine\world\GameRules;
use pocketmine\world\mobs\spawn\SpawnCondition;
use pocketmine\world\raid\VillageScanner;
use pocketmine\world\World;
use pocketmine\YmlServerProperties;
use function abs;
use function cos;
use function count;
use function floor;
use function max;
use function min;
use function mt_rand;
use function sin;
use const M_PI;

/**
 * Spawns pillager patrols (vanilla Bedrock spawn_rules/pillager_patrol.json):
 * - once the world is 120000 ticks old (6000 s of world age, world_age_filter: {@link World::getWorldAge()}, which /time
 *   does not change), every 12000-13200 ticks an attempt is made, which goes ahead 20% of the time, in the Overworld
 *   and not in peaceful;
 * - a random player (not a spectator) is picked; the patrol spawns on the surface 24-48 blocks away from them (48-64
 *   when they are in a village), where the light level is 7 or less, outside mushroom fields;
 * - it is 2-5 pillagers: the first is the captain (ominous banner), the others follow it;
 * - no patrol spawns where the "pillager" population cap (pillager.json population_control) is already reached in the
 *   9x9 chunks around the spot.
 *
 * Patrollers are not persistent: they despawn like other monsters.
 */
final class PatrolSpawner{
	public const MIN_DISTANCE = 24;
	public const MAX_DISTANCE = 48;
	public const VILLAGE_MAX_DISTANCE = 64;
	public const MIN_SIZE = 2;
	public const MAX_SIZE = 5;
	public const MAX_LIGHT = 7;
	private const ATTEMPTS = 10;

	private int $minInterval;
	private int $maxInterval;
	private int $chance;
	private int $minWorldTime;
	private int $delay;

	public function __construct(
		private World $world
	){
		$cfg = $world->getServer()->getConfigGroup();
		$this->minInterval = max(1, $cfg->getPropertyInt(YmlServerProperties::MOBS_SPAWNING_PATROLS_MIN_INTERVAL, 12000));
		$this->maxInterval = max($this->minInterval, $cfg->getPropertyInt(YmlServerProperties::MOBS_SPAWNING_PATROLS_MAX_INTERVAL, 13200));
		$this->chance = min(100, max(0, $cfg->getPropertyInt(YmlServerProperties::MOBS_SPAWNING_PATROLS_CHANCE, 20)));
		$this->minWorldTime = max(0, $cfg->getPropertyInt(YmlServerProperties::MOBS_SPAWNING_PATROLS_MIN_WORLD_TIME, 120000));
		$this->delay = mt_rand($this->minInterval, $this->maxInterval);
	}

	/**
	 * Ticks left before the next spawn attempt.
	 */
	public function getDelay() : int{
		return $this->delay;
	}

	public function tick(int $currentTick) : void{
		if(--$this->delay > 0){
			return;
		}
		$this->delay = mt_rand($this->minInterval, $this->maxInterval);
		if(!$this->world->areMobsEnabled() || $this->world->getGameRule(GameRules::DO_PATROL_SPAWNING) === false || $this->world->getDimension() !== Dimension::OVERWORLD || $this->world->getDifficulty() === World::DIFFICULTY_PEACEFUL){
			return;
		}
		if(!$this->isWorldOldEnough() || mt_rand(1, 100) > $this->chance){
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
		$this->spawnPatrol($players[mt_rand(0, count($players) - 1)]);
	}

	/**
	 * Whether the world is old enough for patrols (world age, not the world time).
	 */
	public function isWorldOldEnough() : bool{
		return $this->world->getWorldAge() >= $this->minWorldTime;
	}

	/**
	 * Whether the "pillager" population is at its cap around the position (patrol pillagers count towards it).
	 */
	public function isPillagerCapReached(int $x, int $z) : bool{
		$spawner = $this->world->getNaturalMobSpawner();
		if($spawner === null || $spawner->getRules() === null){
			return false;
		}
		$cap = $spawner->getPopulationCap(SpawnCondition::POPULATION_PILLAGER, true);
		return $cap <= 0 || $spawner->countPopulation($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE, SpawnCondition::POPULATION_PILLAGER, true) >= $cap;
	}

	/**
	 * Tries to spawn a patrol around the player. With $checkConditions false, the light level, the biome and the population
	 * cap are not checked (for tests and plugins). Returns the patrol, captain first (empty if no spot was found).
	 *
	 * @return Pillager[]
	 * @phpstan-return list<Pillager>
	 */
	public function spawnPatrol(Player $player, bool $checkConditions = true) : array{
		$center = $player->getPosition();
		$inVillage = VillageScanner::findVillageAt($this->world, $center) !== null;
		for($attempt = 0; $attempt < self::ATTEMPTS; ++$attempt){
			$angle = mt_rand(0, 359) / 180 * M_PI;
			$dist = $inVillage ? mt_rand(self::MAX_DISTANCE, self::VILLAGE_MAX_DISTANCE) : mt_rand(self::MIN_DISTANCE, self::MAX_DISTANCE);
			$x = (int) floor($center->x + cos($angle) * $dist);
			$z = (int) floor($center->z + sin($angle) * $dist);
			$y = $this->findSurfaceY($x, $z, $checkConditions);
			if($y === null){
				continue;
			}
			if($checkConditions && $this->isPillagerCapReached($x, $z)){
				return [];
			}
			$patrol = [];
			$size = mt_rand(self::MIN_SIZE, self::MAX_SIZE);
			$px = $x;
			$pz = $z;
			$py = $y;
			for($i = 0; $i < $size; ++$i){
				if($i > 0){
					//vanilla herd: each member near the previous one
					$nx = $px + mt_rand(-3, 3);
					$nz = $pz + mt_rand(-3, 3);
					$ny = $this->findSurfaceY($nx, $nz, false);
					if($ny !== null && abs($ny - $py) <= 4){
						$px = $nx;
						$pz = $nz;
						$py = $ny;
					}
				}
				$pillager = new Pillager(new Location($px + 0.5, $py, $pz + 0.5, $this->world, mt_rand(0, 359), 0));
				$pillager->setPersistent(false);
				$pillager->setPatrolling(true);
				if($i === 0){
					$pillager->setPatrolLeader(true);
				}
				$pillager->setSpawnReason(SpawnReason::PATROL);
				$pillager->spawnToAll();
				$patrol[] = $pillager;
			}
			return $patrol;
		}
		return [];
	}

	/**
	 * Returns the height a patrol member spawns at in this column (on the top block, not on leaves), or null.
	 */
	private function findSurfaceY(int $x, int $z, bool $checkConditions) : ?int{
		$chunk = $this->world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		if($chunk === null || $chunk->isLightPopulated() !== true){
			return null;
		}
		$top = $this->world->getHighestBlockAt($x, $z);
		if($top === null){
			return null;
		}
		$y = $top + 1;
		if($y >= $this->world->getMaxY() - 1 || $this->world->getBlockAt($x, $top, $z) instanceof Leaves || !PathBlockTypes::isStandable($this->world, $x, $y, $z, 1, false)){
			return null;
		}
		if($checkConditions){
			$light = max($this->world->getBlockLightAt($x, $y, $z), $this->world->getRealBlockSkyLightAt($x, $y, $z));
			if($light > self::MAX_LIGHT || MobBiomeInfo::hasTag($this->world, new Vector3($x, $y, $z), "mooshroom_island")){
				return null;
			}
		}
		return $y;
	}
}
