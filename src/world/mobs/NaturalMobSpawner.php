<?php
declare(strict_types=1);

namespace pocketmine\world\mobs;

use pocketmine\block\Water;
use pocketmine\data\bedrock\LegacyBiomeIdToStringIdMap;
use pocketmine\entity\ai\navigation\GroundPathFinder;
use pocketmine\entity\animal\Animal;
use pocketmine\entity\animal\Chicken;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Pig;
use pocketmine\entity\animal\Sheep;
use pocketmine\entity\Creeper;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Skeleton;
use pocketmine\entity\SpawnReason;
use pocketmine\entity\SpawnerEntityRegistry;
use pocketmine\entity\Squid;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityNaturalSpawnEvent;
use pocketmine\event\entity\MobPreSpawnEvent;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\player\Player;
use pocketmine\world\GameRules;
use pocketmine\world\mobs\spawn\MobSpawningSettings;
use pocketmine\world\mobs\spawn\NaturalSpawnPolicy as Policy;
use pocketmine\world\World;

/**
 * Bounded natural spawning of RyzeMP's currently supported mobs.
 *
 * The "vanilla" config mode samples ticking chunks at the configured
 * Bedrock-style chance and enforces surface/cave 9x9 population caps.
 * Full VanillaMine rules, structure spawns and the complete mob catalog
 * are NOT implemented here and are never falsely claimed.
 */
final class NaturalMobSpawner{
	/** @var \WeakMap<Living, int> Natural mob => ticks spent far away */
	private \WeakMap $natural;
	/** @var \WeakMap<Living, bool> Natural mob => on surface */
	private \WeakMap $naturalSurface;
	private int $attempted = 0;
	private int $spawned = 0;

	public function __construct(
		private World $world,
		private MobSpawningSettings $settings
	){
		$this->natural = new \WeakMap();
		$this->naturalSurface = new \WeakMap();
	}

	public function getAttemptCount() : int{ return $this->attempted; }
	public function getSpawnCount() : int{ return $this->spawned; }

	public function tick(int $currentTick) : void{
		if(!$this->settings->enabled){return;}
		if($currentTick % 20 === 0){$this->despawnFar();}
		$world = $this->world;
		if(!$world->getGameRules()->getBool(GameRules::DO_MOB_SPAWNING) ||
			$world->getServer()->getDimensionManager()->getDimension($world) !== DimensionIds::OVERWORLD){
			return;
		}
		$players = [];
		foreach($world->getPlayers() as $player){
			if($player->isAlive() && !$player->isSpectator()){
				$players[] = $player;
			}
		}
		if($players === [] || $this->settings->globalCap === 0){return;}

		/** @var list<array{int, int}> $columns */
		$columns = [];
		$allowAnimals = true;
		$allowMonsters = true;
		if($this->settings->mode === MobSpawningSettings::MODE_VANILLA){
			$ticking = $world->getTickingChunks();
			foreach(Policy::selectTickingChunks(count($ticking), $this->settings->chunkChance, $this->settings->maxAttempts) as $index){
				World::getXZ($ticking[$index], $cx, $cz);
				$columns[] = [($cx << 4) + mt_rand(0, 15), ($cz << 4) + mt_rand(0, 15)];
			}
		}else{
			$allowAnimals = $currentTick % $this->settings->passiveInterval === 0;
			$allowMonsters = $currentTick % $this->settings->hostileInterval === 0;
			if(!$allowAnimals && !$allowMonsters){return;}
			$attempts = min($this->settings->maxAttempts, count($players) * 3);
			for($i = 0; $i < $attempts; ++$i){
				$p = $players[mt_rand(0, count($players) - 1)]->getPosition();
				$distance = $this->settings->maxDistance;
				$columns[] = [$p->getFloorX() + mt_rand(-$distance, $distance),
					$p->getFloorZ() + mt_rand(-$distance, $distance)];
			}
		}
		if($columns === []){return;}

		/** @var array<string, array<string, int>> $byChunk */
		$byChunk = [];
		/** @var list<Living> $counted */
		$counted = [];
		$count = 0;
		foreach($world->getEntities() as $entity){
			if(!$entity instanceof Living || $entity->isClosed() || $entity->isFlaggedForDespawn()){
				continue;
			}
			$category = self::category($entity);
			if($category === null || (!$this->settings->countAll && !isset($this->natural[$entity]))){
				continue;
			}
			$counted[] = $entity;
			++$count;
			$p = $entity->getPosition();
			$key = ($p->getFloorX() >> 4) . ":" . ($p->getFloorZ() >> 4);
			$surface = $this->naturalSurface[$entity] ?? true;
			$group = $category . ($surface ? ":surface" : ":cave");
			$byChunk[$key][$group] = ($byChunk[$key][$group] ?? 0) + 1;
		}
		if($count >= $this->settings->globalCap){return;}

		$created = 0;
		foreach($columns as [$x, $z]){
			if($created >= 2 || $count >= $this->settings->globalCap){break;}
			++$this->attempted;
			$chunk = $world->getChunk($x >> 4, $z >> 4);
			if($chunk === null || !$chunk->isPopulated() || $chunk->isLightPopulated() !== true ||
				$world->isChunkLocked($x >> 4, $z >> 4)){continue;}
			$spawn = $world->getSpawnLocation();
			if(($x - $spawn->x) ** 2 + ($z - $spawn->z) ** 2 < 16 ** 2){continue;}
			$height = $world->getHighestBlockAt($x, $z);
			if($height === null || $height < $world->getMinY() + 2 ||
				$height > $world->getMaxY() - 3){continue;}

			$identifier = null;
			$surface = true;
			$category = "animal";
			$y = $height + 1;
			if($world->getBlockAt($x, $height, $z) instanceof Water){
				$y = $height - 2;
				if(!($world->getBlockAt($x, $y, $z) instanceof Water)){continue;}
				$biome = LegacyBiomeIdToStringIdMap::getInstance()->legacyToString(
					$chunk->getBiomeId($x & 15, $y, $z & 15)
				);
				if(!Policy::canSpawnSquidBiome($biome)){continue;}
				$category = "water_animal";
				$identifier = Squid::getNetworkTypeId();
			}elseif($allowMonsters || $allowAnimals){
				$underground = mt_rand(0, 3) === 0;
				if($underground){
					$maxY = $height - 3;
					if($maxY <= $world->getMinY() + 2){continue;}
					$y = mt_rand($world->getMinY() + 2, $maxY);
				}
				$surface = !$underground;
				if(!$world->isInWorld($x, $y, $z) ||
					!GroundPathFinder::walkable($world, $x, $y, $z)){continue;}
				$light = $world->getFullLightAt($x, $y, $z);
				if($allowMonsters && Policy::canSpawnZombie($world->getDifficulty(), $light)){
					$category = "monster";
					$identifier = match(mt_rand(0, 4)){
						0 => Skeleton::getNetworkTypeId(),
						1 => Creeper::getNetworkTypeId(),
						default => Zombie::getNetworkTypeId()
					};
				}elseif($allowAnimals && !$underground && Policy::canSpawnAnimal($light) &&
					Policy::canSpawnFarmAnimalBiome(LegacyBiomeIdToStringIdMap::getInstance()->legacyToString(
						$chunk->getBiomeId($x & 15, $y, $z & 15)
					))){
					$type = match(mt_rand(0, 3)){
						0 => Cow::class, 1 => Pig::class, 2 => Sheep::class,
						default => Chicken::class
					};
					$identifier = $type::getNetworkTypeId();
				}
			}
			if($identifier === null){continue;}
			$pos = new Vector3($x + 0.5, $y, $z + 0.5);
			$eligible = false;
			foreach($players as $player){
				$distanceSquared = $player->getPosition()->distanceSquared($pos);
				if($distanceSquared < Policy::MIN_PLAYER_DISTANCE ** 2){
					$eligible = false;
					break;
				}
				if(Policy::withinPlayerRange($distanceSquared, $this->settings->maxDistance)){
					$eligible = true;
				}
			}
			if(!$eligible){continue;}

			$localCap = $this->settings->populationCap($category, $surface);
			if($this->settings->mode === MobSpawningSettings::MODE_LEGACY){
				// Legacy caps are per player, measured within 64 blocks.
				$nearCount = 0;
				foreach($counted as $existing){
					if(self::category($existing) === $category &&
						$existing->getPosition()->distanceSquared($pos) <= 64 ** 2){
						++$nearCount;
					}
				}
			}else{
				$nearCount = Policy::populationInChunkRadiusForGroup($byChunk,
					$x >> 4, $z >> 4, $category, $surface);
			}
			if(!Policy::underCap($nearCount, $localCap)){continue;}

			$location = new Location($x + 0.5, $y, $z + 0.5, $world, 0.0, 0.0);
			if(MobPreSpawnEvent::hasHandlers()){
				$event = new MobPreSpawnEvent($identifier, $location, SpawnReason::NATURAL);
				$event->call();
				if($event->isCancelled()){continue;}
			}
			$mob = SpawnerEntityRegistry::getInstance()->spawn($identifier, $location);
			if(!$mob instanceof Living){
				$mob?->close();
				continue;
			}
			$mob->setCanSaveWithChunk(false);
			if(EntityNaturalSpawnEvent::hasHandlers()){
				$event = new EntityNaturalSpawnEvent($mob, EntityNaturalSpawnEvent::CAUSE_NATURAL, $surface);
				$event->call();
				if($event->isCancelled()){
					$mob->close();
					continue;
				}
			}
			$mob->spawnToAll();
			$this->natural[$mob] = 0;
			$this->naturalSurface[$mob] = $surface;
			$counted[] = $mob;
			$key = ($x >> 4) . ":" . ($z >> 4);
			$group = $category . ($surface ? ":surface" : ":cave");
			$byChunk[$key][$group] = ($byChunk[$key][$group] ?? 0) + 1;
			++$count;
			++$created;
			++$this->spawned;
		}
	}

	private static function category(Living $entity) : ?string{
		return match(true){
			$entity instanceof Animal => "animal",
			$entity instanceof Squid => "water_animal",
			$entity instanceof Zombie || $entity instanceof Skeleton ||
				$entity instanceof Creeper => "monster",
			default => null
		};
	}

	private function despawnFar() : void{
		$players = $this->world->getPlayers();
		foreach($this->natural as $mob => $farTicks){
			if($mob->isClosed() || $mob->isFlaggedForDespawn()){
				unset($this->natural[$mob], $this->naturalSurface[$mob]);
				continue;
			}
			$nearest = PHP_INT_MAX;
			foreach($players as $player){
				if($player->isSpectator()){continue;}
				$nearest = min($nearest, $mob->getPosition()->distanceSquared($player->getPosition()));
			}
			if($nearest > $this->settings->despawnDistance ** 2){
				$mob->flagForDespawn();
				unset($this->natural[$mob], $this->naturalSurface[$mob]);
				continue;
			}
			$farTicks = $nearest > 32 ** 2 ? $farTicks + 20 : 0;
			if($farTicks >= 600 && mt_rand(0, 39) === 0){
				$mob->flagForDespawn();
				unset($this->natural[$mob], $this->naturalSurface[$mob]);
			}else{
				$this->natural[$mob] = $farTicks;
			}
		}
	}
}
