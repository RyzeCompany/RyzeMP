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

use pocketmine\data\bedrock\BiomeIds;
use pocketmine\entity\ai\sensing\Sensing;
use pocketmine\entity\animal\Animal;
use pocketmine\entity\animal\Horse;
use pocketmine\entity\aquatic\TropicalFish;
use pocketmine\entity\BabySpawning;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySpawnRegistry;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Slime;
use pocketmine\entity\riding\Jockeys;
use pocketmine\entity\SpawnReason;
use pocketmine\entity\Squid;
use pocketmine\entity\utils\MobBiomeInfo;
use pocketmine\event\entity\EntityNaturalSpawnEvent;
use pocketmine\event\entity\MobPreSpawnEvent;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\format\Chunk;
use pocketmine\world\GameRules;
use pocketmine\world\mobs\spawn\PopulationCaps;
use pocketmine\world\mobs\spawn\SlimeChunk;
use pocketmine\world\mobs\spawn\SpawnBlockInfo;
use pocketmine\world\mobs\spawn\SpawnCondition;
use pocketmine\world\mobs\spawn\SpawnContext;
use pocketmine\world\mobs\spawn\SpawnHerd;
use pocketmine\world\mobs\spawn\SpawnRules;
use pocketmine\world\mobs\spawn\SpawnSpot;
use pocketmine\world\mobs\spawn\StructureSpawnArea;
use pocketmine\world\mobs\spawn\StructureSpawns;
use pocketmine\world\mobs\spawn\VanillaSpawnRules;
use pocketmine\world\Position;
use pocketmine\world\World;
use pocketmine\YmlServerProperties;
use function array_keys;
use function cos;
use function count;
use function floor;
use function in_array;
use function intdiv;
use function log;
use function max;
use function min;
use function mt_getrandmax;
use function mt_rand;
use function sqrt;
use function str_starts_with;
use function strtolower;

/**
 * Natural mob spawning with Bedrock's algorithm and Mojang's spawn rules (see {@link VanillaSpawnRules}, shipped as
 * {@link \pocketmine\world\mobs\spawn\BundledSpawnRules}). Sources: https://minecraft.wiki/w/Mob_spawning (Bedrock Edition)
 * and the spawn rule documentation of the Bedrock add-on API.
 *
 * Each tick, every ticking chunk (the chunk tick radius around players, Bedrock's simulation distance) runs a cluster
 * spawn attempt with a chance of 11 in 2000 (the chunks are sampled, not looped over: about 9 attempts per tick for 20
 * players). An attempt picks a random column of the chunk and tries:
 * - the surface: the position above the highest block with collision (through water and plants), if that block has
 *   a full solid top (under leaves there is no surface spawn);
 * - every other spawnable position below it ("underground": caves, under trees) within reach of the players.
 * A position is spawnable with the feet in a block without collision (or water) above a full solid top, or in air
 * above lava. It must be 24 to 44 blocks from the nearest player (distance_filter overrides it, e.g. 12-32 for fish;
 * with a chunk tick radius over 4, up to 128).
 *
 * The rules matching the biome and the position (placement, brightness, difficulty, height, blocks...) whose population
 * cap is not reached are candidates; one is picked by weight. Population caps count the naturally spawned mobs of each
 * population type in the 9x9 chunks around the attempt, separately for surface and underground mobs
 * ({@link PopulationCaps}); density limits count the mob's own type the same way and let it spawn with a chance of
 * (limit - count) / limit. At most {@link PopulationCaps::GLOBAL_CAP} naturally spawned mobs per world.
 * The herd (pack) spawns around the position: each member is checked again and gets the herd events (babies, horse
 * coat) and jockeys/riders ({@link Jockeys::onMobSpawned()} with naturalSpawn).
 *
 * Bedrock specifics implemented in code (not in the rule files): slimes only in slime chunks below Y 40 or in swamps
 * between Y 51 and 69 by moon phase ({@link SlimeChunk}), phantoms only above players who haven't rested for 3 days
 * ({@link Player::getTimeSinceRest()}), strays turning into skeletons underground or 20% of the time (spawn_event),
 * structure spawns ({@link StructureSpawns}), wandering traders ({@link WanderingTraderSpawner}) and village cats
 * ({@link VillageCatSpawner}). Pillager patrols have their own spawner ({@link PatrolSpawner}).
 *
 * Naturally spawned mobs are not persistent: they despawn far from players ({@link MobDespawner}).
 *
 * Nothing spawns while the world's domobspawning gamerule is off ({@link World::setMobSpawningEnabled()}); mobs still
 * despawn. Plugins can change or cancel each spawned mob with {@link EntityNaturalSpawnEvent}; rules for mobs without
 * a registered implementation ({@link EntitySpawnRegistry}) are skipped.
 *
 * With mobs.spawning.mode: legacy, the old simplified spawner is used instead ({@link LegacyMobSpawner}).
 */
final class NaturalMobSpawner{
	/** Chance per tick that a ticking chunk runs a spawn attempt: 11 in 2000 (Bedrock). */
	public const CHUNK_CHANCE = 11;
	public const CHUNK_CHANCE_DIVISOR = 2000;
	/** Ticks without resting after which phantoms spawn around a player (Bedrock "Time Since Last Rest"). */
	public const PHANTOM_INSOMNIA_TICKS = 72000;
	/** Phantoms spawn in a 21x15x21 box centred this many blocks above the player. */
	public const PHANTOM_HEIGHT = 28;
	/** Slimes spawn in slime chunks below this Y. */
	public const SLIME_CHUNK_MAX_Y = 40;
	public const SLIME_SWAMP_MIN_Y = 51;
	public const SLIME_SWAMP_MAX_Y = 69;
	/** Herd members spawn this many blocks around the first one (horizontally). */
	public const HERD_SPREAD = 4;
	private const HERD_MEMBER_ATTEMPTS = 4;
	/** Heights tried for a herd member, relative to the previous one. */
	private const HERD_DY = [0, 1, -1, 2, -2];
	/** Structure herds: each member is a random step of rand(6) - rand(6) blocks from the previous one (Java). */
	private const STRUCTURE_HERD_STEP = 6;
	private const STRUCTURE_HERD_ATTEMPTS = 3;
	private const REFRESH_INTERVAL = 20;
	/** Moon brightness by phase (0 = full moon). */
	private const MOON_BRIGHTNESS = [1.0, 0.75, 0.5, 0.25, 0.0, 0.25, 0.5, 0.75];
	/** Horse coat by herd event (horse spawn rules: the whole herd has the same coat). */
	private const HORSE_COAT_EVENTS = [
		"minecraft:make_white" => 0,
		"minecraft:make_creamy" => 1,
		"minecraft:make_chestnut" => 2,
		"minecraft:make_brown" => 3,
		"minecraft:make_black" => 4,
		"minecraft:make_gray" => 5,
		"minecraft:make_darkbrown" => 6,
	];

	private ?LegacyMobSpawner $legacy = null;
	private ?WanderingTraderSpawner $traderSpawner = null;
	private ?VillageCatSpawner $catSpawner = null;
	private bool $useVanillaRules;
	private int $chunkChance;
	private int $maxAttemptsPerTick;
	private int $globalCap;
	/** mobs.spawning.count-all-mobs-for-spawning */
	private bool $countAllMobs;
	/**
	 * mobs.spawning.spawn-limits: population => cap replacing the vanilla ones
	 * @var int[]
	 * @phpstan-var array<string, int>
	 */
	private array $spawnLimits = [];
	private int $maxDistance;
	private bool $phantoms;

	private ?SpawnRules $rules = null;
	private int $lastRulesCheck = -1000;

	/**
	 * @var int[] ticking chunk hashes, refreshed every second
	 * @phpstan-var list<int>
	 */
	private array $chunks = [];
	private int $lastRefresh = -1000;
	private int $naturalCount = 0;

	/**
	 * @var Player[]
	 * @phpstan-var list<Player>
	 */
	private array $players = [];

	/**
	 * Counts per chunk for the current tick: chunk hash => [population|surface => count, type|surface => count].
	 * @phpstan-var array<int, array{array<string, int>, array<string, int>}>
	 */
	private array $chunkCounts = [];
	private int $countsTick = -1;
	/**
	 * Sums over 9x9 chunks computed this tick (cleared when a mob spawns).
	 * @phpstan-var array<string, int>
	 */
	private array $areaCounts = [];
	/**
	 * Ticks spent far from players by naturally spawned living entities that are neither {@link Mob}s nor squids, by
	 * entity ID.
	 * @phpstan-var array<int, int>
	 */
	private array $farTicks = [];

	/**
	 * Whether each naturally spawned mob was spawned on the surface (true) or underground (false).
	 * @phpstan-var \WeakMap<Entity, bool>
	 */
	private \WeakMap $natural;

	private ?SpawnContext $context = null;

	/** Statistics: cluster attempts and spawned mobs since the world was loaded. */
	private int $attemptCount = 0;
	private int $spawnCount = 0;

	public function __construct(
		private World $world
	){
		$cfg = $world->getServer()->getConfigGroup();
		$this->natural = new \WeakMap();
		if(strtolower($cfg->getPropertyString(YmlServerProperties::MOBS_SPAWNING_MODE, "vanilla")) === "legacy"){
			$this->legacy = new LegacyMobSpawner($world);
		}
		$this->useVanillaRules = $cfg->getPropertyBool(YmlServerProperties::MOBS_SPAWNING_VANILLA_RULES, true);
		$this->chunkChance = max(0, min(self::CHUNK_CHANCE_DIVISOR, $cfg->getPropertyInt(YmlServerProperties::MOBS_SPAWNING_CHUNK_CHANCE, self::CHUNK_CHANCE)));
		$this->maxAttemptsPerTick = max(1, $cfg->getPropertyInt(YmlServerProperties::MOBS_SPAWNING_MAX_ATTEMPTS_PER_TICK, 32));
		$this->globalCap = max(0, $cfg->getPropertyInt(YmlServerProperties::MOBS_SPAWNING_GLOBAL_CAP, PopulationCaps::GLOBAL_CAP));
		$this->countAllMobs = $cfg->getPropertyBool(YmlServerProperties::MOBS_SPAWNING_COUNT_ALL_MOBS_FOR_SPAWNING, true);
		foreach([
			SpawnCondition::POPULATION_ANIMAL => YmlServerProperties::MOBS_SPAWNING_SPAWN_LIMITS_ANIMAL,
			SpawnCondition::POPULATION_MONSTER => YmlServerProperties::MOBS_SPAWNING_SPAWN_LIMITS_MONSTER,
			SpawnCondition::POPULATION_AMBIENT => YmlServerProperties::MOBS_SPAWNING_SPAWN_LIMITS_AMBIENT,
			SpawnCondition::POPULATION_WATER_ANIMAL => YmlServerProperties::MOBS_SPAWNING_SPAWN_LIMITS_WATER_ANIMAL,
			SpawnCondition::POPULATION_PILLAGER => YmlServerProperties::MOBS_SPAWNING_SPAWN_LIMITS_PILLAGER,
		] as $population => $property){
			$limit = $cfg->getPropertyInt($property, -1);
			if($limit >= 0){
				$this->spawnLimits[$population] = $limit;
			}
		}
		$configuredDistance = $cfg->getPropertyInt(YmlServerProperties::MOBS_SPAWNING_MAX_DISTANCE, 0);
		$this->maxDistance = $configuredDistance > 0 ? max(SpawnCondition::DEFAULT_MIN_DISTANCE + 1, $configuredDistance) :
			($world->getChunkTickRadius() <= 4 ? SpawnCondition::DEFAULT_MAX_DISTANCE : min(128, $world->getChunkTickRadius() * 16));
		$this->phantoms = $cfg->getPropertyBool(YmlServerProperties::MOBS_SPAWNING_PHANTOMS, true);
		if($cfg->getPropertyBool(YmlServerProperties::MOBS_SPAWNING_WANDERING_TRADERS, true)){
			$this->traderSpawner = new WanderingTraderSpawner($world);
		}
		if($cfg->getPropertyBool(YmlServerProperties::MOBS_SPAWNING_VILLAGE_CATS, true)){
			$this->catSpawner = new VillageCatSpawner($world);
		}
	}

	public function getWanderingTraderSpawner() : ?WanderingTraderSpawner{
		return $this->traderSpawner;
	}

	/**
	 * Returns the spawn rules in use (Mojang's once downloaded, the built-in ones until then), or null in legacy mode.
	 */
	public function getRules() : ?SpawnRules{
		if($this->legacy !== null){
			return null;
		}
		$now = $this->world->getServer()->getTick();
		if($this->rules === null || ($this->useVanillaRules && $this->rules->getSource() === "built-in" && $now - $this->lastRulesCheck >= 200)){
			$this->lastRulesCheck = $now;
			$vanilla = $this->useVanillaRules ? VanillaSpawnRules::get($this->world->getServer()) : null;
			$this->rules = $vanilla ?? $this->rules ?? SpawnRules::builtin();
		}
		return $this->rules;
	}

	/**
	 * Replaces the spawn rules of this world (plugins, tests).
	 */
	public function setRules(SpawnRules $rules) : void{
		$this->rules = $rules;
		$this->useVanillaRules = false;
	}

	public function getAttemptCount() : int{ return $this->attemptCount; }

	public function getSpawnCount() : int{ return $this->spawnCount; }

	/**
	 * Number of naturally spawned mobs in the world counted for the global cap (refreshed every second).
	 */
	public function getNaturalMobCount() : int{ return $this->naturalCount; }

	public function getMaxDistance() : int{ return $this->maxDistance; }

	public function tick(int $currentTick) : void{
		if(!$this->world->areMobsEnabled()){
			return;
		}
		$spawning = $this->world->isMobSpawningEnabled();
		if($spawning){
			if($this->world->getGameRule(GameRules::DO_TRADER_SPAWNING) !== false){
				$this->traderSpawner?->tick();
			}
			$this->catSpawner?->tick($currentTick);
		}
		if($this->legacy !== null){
			if($spawning){
				$this->legacy->tick($currentTick);
			}
			return;
		}
		$this->players = [];
		foreach($this->world->getPlayers() as $player){
			if($player->isAlive() && !$player->isSpectator()){
				$this->players[] = $player;
			}
		}
		if(count($this->players) === 0){
			return;
		}
		$rules = $this->getRules();
		if($rules === null){
			return;
		}
		if($currentTick - $this->lastRefresh >= self::REFRESH_INTERVAL){
			$this->lastRefresh = $currentTick;
			$this->chunks = $this->world->getTickingChunks();
			$this->naturalCount = $this->countNaturalMobs($rules);
			$this->despawnOtherMobs();
		}
		$chunkCount = count($this->chunks);
		if(!$spawning || $this->chunkChance <= 0 || $chunkCount === 0 || $this->naturalCount >= $this->globalCap){
			return;
		}
		$this->context = null;

		//each chunk has an independent 11/2000 chance: sample the chunks that pass by geometric skipping
		$p = $this->chunkChance / self::CHUNK_CHANCE_DIVISOR;
		$logQ = $p >= 1 ? 0.0 : log(1 - $p);
		$index = -1;
		$attempts = 0;
		while(true){
			if($logQ === 0.0){
				++$index;
			}else{
				$u = (mt_rand() + 1) / (mt_getrandmax() + 2);
				$index += 1 + (int) floor(log($u) / $logQ);
			}
			if($index >= $chunkCount){
				break;
			}
			World::getXZ($this->chunks[$index], $chunkX, $chunkZ);
			$this->clusterAttempt($rules, $chunkX, $chunkZ);
			if(++$attempts >= $this->maxAttemptsPerTick || $this->naturalCount >= $this->globalCap){
				break;
			}
		}
	}

	private function getContext() : SpawnContext{
		if($this->context === null){
			$world = $this->world;
			$timeReduction = 15;
			if($world->getDimension() === Dimension::OVERWORLD){
				$percentage = max(0, min(1, -(cos($world->getSunAngleRadians()) * 2 - 0.5)));
				$timeReduction = (int) ($percentage * 11);
			}
			$this->context = new SpawnContext(
				$world->getDifficulty(),
				$world->getSkyLightReduction(),
				$timeReduction,
				$world->getDimension(),
				SpawnCondition::DEFAULT_MIN_DISTANCE,
				$this->maxDistance
			);
		}
		return $this->context;
	}

	/**
	 * One Bedrock cluster spawn attempt in a random column of the chunk.
	 */
	public function clusterAttempt(SpawnRules $rules, int $chunkX, int $chunkZ) : void{
		++$this->attemptCount;
		$world = $this->world;
		$chunk = $world->getChunk($chunkX, $chunkZ);
		if($chunk === null || $chunk->isLightPopulated() !== true){
			return;
		}
		$lx = mt_rand(0, Chunk::EDGE_LENGTH - 1);
		$lz = mt_rand(0, Chunk::EDGE_LENGTH - 1);
		$x = ($chunkX << Chunk::COORD_BIT_SIZE) | $lx;
		$z = ($chunkZ << Chunk::COORD_BIT_SIZE) | $lz;

		//vertical window: positions within reach of a player
		$reach = $this->maxDistance;
		$minWindow = null;
		$maxWindow = null;
		foreach($this->players as $player){
			$pos = $player->getPosition();
			$dx = $pos->x - $x - 0.5;
			$dz = $pos->z - $z - 0.5;
			$horizontal = $dx * $dx + $dz * $dz;
			if($horizontal > $reach * $reach){
				continue;
			}
			$vertical = sqrt($reach * $reach - $horizontal);
			$lo = (int) floor($pos->y - $vertical);
			$hi = (int) floor($pos->y + $vertical);
			$minWindow = $minWindow === null ? $lo : min($minWindow, $lo);
			$maxWindow = $maxWindow === null ? $hi : max($maxWindow, $hi);
		}
		if($minWindow === null || $maxWindow === null){
			return;
		}
		$minY = max($world->getMinY() + 1, $minWindow);
		$top = $chunk->getHighestBlockAt($lx, $lz);
		if($top === null){
			return;
		}

		//the highest block with collision (or lava): water and plants above it are part of the surface spot
		$y = $top;
		$worldMinY = $world->getMinY();
		while($y >= $worldMinY && (SpawnBlockInfo::flags($world, $chunk->getBlockStateId($lx, $y, $lz), $x, $y, $z) & SpawnBlockInfo::SOLID) === 0){
			--$y;
		}
		if($y < $worldMinY){
			return;
		}
		$highestSolid = $y;
		$found = false;
		$surfaceY = $highestSolid + 1;
		if($surfaceY >= $minY && $surfaceY <= $maxWindow && $surfaceY < $world->getMaxY()){
			$medium = $this->spotMedium($chunk, $lx, $lz, $x, $surfaceY, $z);
			if($medium !== null){
				$found = true;
				$this->trySpawn($rules, $chunk, $chunkX, $chunkZ, $x, $surfaceY, $z, true, $medium);
			}
		}
		//underground: every spawnable position below the highest solid block (Bedrock keeps going after a spawn)
		for($feetY = min($highestSolid - 1, $maxWindow); $feetY >= $minY; --$feetY){
			$medium = $this->spotMedium($chunk, $lx, $lz, $x, $feetY, $z);
			if($medium !== null){
				$found = true;
				$this->trySpawn($rules, $chunk, $chunkX, $chunkZ, $x, $feetY, $z, false, $medium);
				--$feetY; //the block below is solid
			}
		}
		if($found && StructureSpawns::hasProviders()){
			foreach(StructureSpawns::getSpawnAreas($world, $x, $z) as $area){
				$this->structureAttempt($area, $chunk, $chunkX, $chunkZ, $lx, $lz, $x, $z);
			}
		}
	}

	/**
	 * Returns the SpawnSpot medium if a mob can spawn with its feet at this position, otherwise null.
	 */
	private function spotMedium(Chunk $chunk, int $lx, int $lz, int $x, int $y, int $z) : ?int{
		$world = $this->world;
		$feet = SpawnBlockInfo::flags($world, $chunk->getBlockStateId($lx, $y, $lz), $x, $y, $z);
		if(($feet & SpawnBlockInfo::PASSABLE) === 0 || ($feet & SpawnBlockInfo::DANGER) !== 0){
			return null;
		}
		$below = SpawnBlockInfo::flags($world, $chunk->getBlockStateId($lx, $y - 1, $lz), $x, $y - 1, $z);
		if(($below & SpawnBlockInfo::SOLID_TOP) !== 0){
			return ($feet & SpawnBlockInfo::WATER) !== 0 ? SpawnSpot::MEDIUM_WATER : SpawnSpot::MEDIUM_AIR;
		}
		if(($below & SpawnBlockInfo::LAVA) !== 0 && ($feet & SpawnBlockInfo::WATER) === 0){
			return SpawnSpot::MEDIUM_LAVA;
		}
		return null;
	}

	private function nearestPlayerDistance(float $x, float $y, float $z) : float{
		$nearest = null;
		foreach($this->players as $player){
			$pos = $player->getPosition();
			$d = ($pos->x - $x) ** 2 + ($pos->y - $y) ** 2 + ($pos->z - $z) ** 2;
			if($nearest === null || $d < $nearest){
				$nearest = $d;
			}
		}
		return $nearest === null ? 1e9 : sqrt($nearest);
	}

	private function makeSpot(Chunk $chunk, int $x, int $y, int $z, bool $surface, int $medium, ?float $distance = null) : SpawnSpot{
		$lx = $x & Chunk::COORD_MASK;
		$lz = $z & Chunk::COORD_MASK;
		$world = $this->world;
		return new SpawnSpot(
			$x, $y, $z,
			$surface,
			$medium,
			SpawnBlockInfo::name($chunk->getBlockStateId($lx, $y - 1, $lz)),
			SpawnBlockInfo::name($chunk->getBlockStateId($lx, $y, $lz)),
			$world->getBlockLightAt($x, $y, $z),
			$world->getPotentialBlockSkyLightAt($x, $y, $z),
			$this->spawnBiomeId($chunk->getBiomeId($lx, $y, $lz)),
			$distance ?? $this->nearestPlayerDistance($x + 0.5, $y, $z + 0.5)
		);
	}

	/**
	 * The biome whose spawn rules apply: in the Nether and the End, a biome of another dimension (chunks of an old world
	 * or of another generator without biomes) counts as the dimension's default one, so overworld monsters (zombies,
	 * creepers...) don't spawn there.
	 */
	private function spawnBiomeId(int $biomeId) : int{
		return match($this->world->getDimension()){
			Dimension::NETHER => in_array("nether", MobBiomeInfo::getTagsById($biomeId), true) ? $biomeId : BiomeIds::HELL,
			Dimension::END => in_array("the_end", MobBiomeInfo::getTagsById($biomeId), true) ? $biomeId : BiomeIds::THE_END,
			default => $biomeId
		};
	}

	private function trySpawn(SpawnRules $rules, Chunk $chunk, int $chunkX, int $chunkZ, int $x, int $y, int $z, bool $surface, int $medium) : void{
		$distance = $this->nearestPlayerDistance($x + 0.5, $y, $z + 0.5);
		if($distance < 12 || $distance > $this->maxDistance){
			return; //no rule can spawn here (the closest distance_filter is 12)
		}
		$spot = $this->makeSpot($chunk, $x, $y, $z, $surface, $medium, $distance);
		$context = $this->getContext();
		$candidates = [];
		$totalWeight = 0;
		$registry = EntitySpawnRegistry::getInstance();
		foreach($rules->forBiome($spot->biomeId) as $condition){
			//mobs without an implementation (or unregistered by a plugin) are skipped, not picked and wasted
			if(!$registry->isRegistered($condition->identifier) || !$condition->matchesSpot($spot, $context) || !$this->passesSpecialRules($condition, $spot)){
				continue;
			}
			$cap = $this->getPopulationCap($condition->population, $surface);
			if($cap <= 0 || $this->countPopulation($chunkX, $chunkZ, $condition->population, $surface) >= $cap){
				continue;
			}
			$candidates[] = $condition;
			$totalWeight += $condition->weight;
		}
		if($totalWeight <= 0){
			return;
		}
		$roll = mt_rand(0, $totalWeight - 1);
		$picked = $candidates[0];
		foreach($candidates as $condition){
			$roll -= $condition->weight;
			if($roll < 0){
				$picked = $condition;
				break;
			}
		}
		$limit = $picked->getDensityLimit($surface);
		if($limit !== null){
			$count = $this->countType($chunkX, $chunkZ, $picked->identifier, $surface);
			if($count >= $limit || mt_rand(0, $limit - 1) >= $limit - $count){
				return;
			}
		}
		if(MobPreSpawnEvent::hasHandlers()){
			$ev = new MobPreSpawnEvent($picked->identifier, new Position($x + 0.5, $y, $z + 0.5, $this->world), SpawnReason::NATURAL);
			$ev->call();
			if($ev->isCancelled()){
				return;
			}
		}
		$this->spawnHerd($rules, $picked, $spot);
	}

	/**
	 * Population cap for the position. Some vanilla rules spawn underground where the published Bedrock cap table has
	 * no underground cap (axolotls, glow squids, cave tropical fish): they use the surface cap instead.
	 */
	public function getPopulationCap(string $population, bool $surface) : int{
		if(isset($this->spawnLimits[$population])){
			return $this->spawnLimits[$population];
		}
		$dimension = $this->world->getDimension();
		$cap = PopulationCaps::get($dimension, $population, $surface);
		if($cap === 0 && !$surface){
			$cap = PopulationCaps::get($dimension, $population, true);
		}
		return $cap;
	}

	/**
	 * Conditions Bedrock checks in code rather than in the spawn rule files.
	 */
	private function passesSpecialRules(SpawnCondition $condition, SpawnSpot $spot) : bool{
		return match($condition->identifier){
			EntityIds::SLIME => $this->canSlimeSpawn($spot),
			EntityIds::PHANTOM => $this->findInsomniac($spot) !== null,
			default => true
		};
	}

	private function canSlimeSpawn(SpawnSpot $spot) : bool{
		if($this->world->getDimension() !== Dimension::OVERWORLD){
			return false;
		}
		if($spot->y < self::SLIME_CHUNK_MAX_Y && SlimeChunk::isSlimeChunk($spot->x >> Chunk::COORD_BIT_SIZE, $spot->z >> Chunk::COORD_BIT_SIZE)){
			return true;
		}
		$tags = MobBiomeInfo::getTagsById($spot->biomeId);
		if(($spot->y >= self::SLIME_SWAMP_MIN_Y && $spot->y <= self::SLIME_SWAMP_MAX_Y) &&
			(in_array("swamp", $tags, true) || in_array("mangrove_swamp", $tags, true))
		){
			$moon = self::MOON_BRIGHTNESS[intdiv($this->world->getTime(), World::TIME_FULL) % 8];
			return $spot->getBrightness($this->getContext()->skyReductionWeather) <= mt_rand(0, 7) &&
				$moon > mt_rand(0, 999) / 1000 && mt_rand(0, 1) === 0;
		}
		return false;
	}

	/**
	 * Returns the player within spawning reach of the position who hasn't rested for 3 days, if any.
	 */
	private function findInsomniac(SpawnSpot $spot) : ?Player{
		if(!$this->phantoms || $this->world->getGameRule(GameRules::DO_INSOMNIA) === false){
			return null;
		}
		foreach($this->players as $player){
			if($player->getTimeSinceRest() >= self::PHANTOM_INSOMNIA_TICKS &&
				$player->getPosition()->distanceSquared(new Vector3($spot->x + 0.5, $spot->y, $spot->z + 0.5)) <= $this->maxDistance ** 2
			){
				return $player;
			}
		}
		return null;
	}

	private function spawnHerd(SpawnRules $rules, SpawnCondition $condition, SpawnSpot $spot) : void{
		$herd = $condition->pickHerd();
		$size = $herd->rollSize();
		$phantomTarget = null;
		if($condition->identifier === EntityIds::PHANTOM){
			$phantomTarget = $this->findInsomniac($spot);
			if($phantomTarget === null){
				return;
			}
			//Bedrock: 1-2 on easy, 1-3 on normal, 1-4 on hard
			$size = mt_rand(1, 1 + max(1, $this->world->getDifficulty()));
		}
		$current = $spot;
		for($i = 0; $i < $size; ++$i){
			if($phantomTarget !== null){
				$pos = $phantomTarget->getPosition();
				$x = (int) floor($pos->x) + mt_rand(-10, 10);
				$y = (int) floor($pos->y) + self::PHANTOM_HEIGHT + mt_rand(-7, 7);
				$z = (int) floor($pos->z) + mt_rand(-10, 10);
				$location = new Location($x + 0.5, $y, $z + 0.5, $this->world, mt_rand(0, 359), 0);
			}else{
				if($i > 0){
					$next = $this->findHerdPosition($rules, $condition, $current);
					if($next === null){
						continue;
					}
					$current = $next;
				}
				$location = new Location($current->x + 0.5, $current->y, $current->z + 0.5, $this->world, mt_rand(0, 359), 0);
			}
			$entity = $this->createMob($condition, $current, $location);
			if($entity === null){
				continue;
			}
			if(!$this->fits($entity, $condition->underwater)){
				$entity->close();
				continue;
			}
			$this->applyHerdEvent($entity, $herd, $i);
			if($this->addNaturalMob($entity, $current->surface)){
				Jockeys::onMobSpawned($entity, true);
			}
		}
	}

	/**
	 * Registers a mob spawned by the world: not persistent, counted towards the caps, then shown to players. Returns
	 * false (and closes the mob) if a plugin cancelled the {@link EntityNaturalSpawnEvent}.
	 */
	private function addNaturalMob(Living $entity, bool $surface, bool $persistent = false, int $cause = EntityNaturalSpawnEvent::CAUSE_NATURAL) : bool{
		if($entity instanceof Mob || $entity instanceof Squid){
			$entity->setPersistent($persistent);
		}
		$entity->setSpawnReason(match($cause){
			EntityNaturalSpawnEvent::CAUSE_STRUCTURE => SpawnReason::STRUCTURE,
			EntityNaturalSpawnEvent::CAUSE_WANDERING_TRADER => SpawnReason::WANDERING_TRADER,
			EntityNaturalSpawnEvent::CAUSE_VILLAGE_CAT => SpawnReason::VILLAGE_CAT,
			default => SpawnReason::NATURAL
		});
		if($cause === EntityNaturalSpawnEvent::CAUSE_NATURAL){
			//Bedrock minecraft:entity_spawned (herd events such as polar bear cubs were applied before)
			BabySpawning::rollSpawnBaby($entity);
		}
		$ev = new EntityNaturalSpawnEvent($entity, $cause, $surface);
		$ev->call();
		if($ev->isCancelled()){
			$entity->close();
			return false;
		}
		if($entity->isClosed()){
			return false;
		}
		$this->natural[$entity] = $surface;
		$entity->spawnToAll();
		++$this->spawnCount;
		++$this->naturalCount;
		$this->areaCounts = [];
		$pos = $entity->getPosition();
		$hash = World::chunkHash(((int) floor($pos->x)) >> Chunk::COORD_BIT_SIZE, ((int) floor($pos->z)) >> Chunk::COORD_BIT_SIZE);
		if(isset($this->chunkCounts[$hash])){
			$this->addToCounts($this->chunkCounts[$hash], $entity, $surface);
		}
		return true;
	}

	private function createMob(SpawnCondition $condition, SpawnSpot $spot, Location $location) : ?Living{
		$type = $condition->pickEntityType();
		if($condition->spawnEvent === "change_to_skeleton" && (!$spot->surface || mt_rand(1, 100) <= 20)){
			$type = EntityIds::SKELETON; //stray spawn rules: underground, or 20% of the time
		}
		if($type === EntityIds::SLIME){
			return new Slime($location, null, $this->rollSlimeSize());
		}
		$entity = EntitySpawnRegistry::getInstance()->create($type, $location);
		if($entity instanceof Living){
			return $entity;
		}
		$entity?->close();
		return null;
	}

	/**
	 * Slime sizes 1, 2 and 4 (Bedrock 0, 1 and 3): even odds on easy, 16/33/50% on hard.
	 */
	private function rollSlimeSize() : int{
		$roll = mt_rand(0, 99);
		[$small, $medium] = match($this->world->getDifficulty()){
			World::DIFFICULTY_HARD => [16, 33],
			World::DIFFICULTY_NORMAL => [25, 33],
			default => [34, 33]
		};
		return $roll < $small ? 1 : ($roll < $small + $medium ? 2 : 4);
	}

	private function applyHerdEvent(Living $entity, SpawnHerd $herd, int $index) : void{
		$event = $herd->getEventFor($index);
		if($event === null){
			return;
		}
		if($event === "minecraft:entity_born" && $entity instanceof Animal){
			$entity->setAge(Animal::BABY_AGE);
		}elseif(isset(self::HORSE_COAT_EVENTS[$event]) && $entity instanceof Horse){
			$entity->setAppearance(self::HORSE_COAT_EVENTS[$event], $entity->getMarkings());
		}elseif($entity instanceof TropicalFish){
			//minecraft:become_<variety>: the whole herd is one named variety
			$entity->applyVarietyEvent($event);
		}
		//spawn_with_rider (camel husk), as_rider (drowned), spawn_adult_with_rider (zombie horse): Jockeys
	}

	/**
	 * Finds where the next member of a herd spawns: around the previous one, at a spawnable position allowed by the
	 * same rule.
	 */
	private function findHerdPosition(SpawnRules $rules, SpawnCondition $condition, SpawnSpot $from) : ?SpawnSpot{
		$context = $this->getContext();
		for($attempt = 0; $attempt < self::HERD_MEMBER_ATTEMPTS; ++$attempt){
			$x = $from->x + mt_rand(-self::HERD_SPREAD, self::HERD_SPREAD);
			$z = $from->z + mt_rand(-self::HERD_SPREAD, self::HERD_SPREAD);
			$chunk = $this->world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
			if($chunk === null || $chunk->isLightPopulated() !== true){
				continue;
			}
			foreach(self::HERD_DY as $dy){
				$y = $from->y + $dy;
				if($y <= $this->world->getMinY() || $y >= $this->world->getMaxY()){
					continue;
				}
				$medium = $this->spotMedium($chunk, $x & Chunk::COORD_MASK, $z & Chunk::COORD_MASK, $x, $y, $z);
				if($medium === null){
					continue;
				}
				$spot = $this->makeSpot($chunk, $x, $y, $z, $from->surface, $medium);
				if($condition->matchesSpot($spot, $context) && in_array($condition, $rules->forBiome($spot->biomeId), true)){
					return $spot;
				}
			}
		}
		return null;
	}

	/**
	 * Whether the mob's bounding box is free of blocks with collision (and of lava; aquatic mobs need water at their
	 * feet, checked by the rule).
	 */
	private function fits(Entity $entity, bool $aquatic) : bool{
		$bb = $entity->getBoundingBox();
		$world = $this->world;
		$minX = (int) floor($bb->minX);
		$maxX = (int) floor($bb->maxX - 0.001);
		$minY = (int) floor($bb->minY);
		$maxY = (int) floor($bb->maxY - 0.001);
		$minZ = (int) floor($bb->minZ);
		$maxZ = (int) floor($bb->maxZ - 0.001);
		if($minY < $world->getMinY() || $maxY >= $world->getMaxY()){
			return false;
		}
		for($x = $minX; $x <= $maxX; ++$x){
			for($z = $minZ; $z <= $maxZ; ++$z){
				$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
				if($chunk === null){
					return false;
				}
				for($y = $minY; $y <= $maxY; ++$y){
					$flags = SpawnBlockInfo::flags($world, $chunk->getBlockStateId($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK), $x, $y, $z);
					if(($flags & SpawnBlockInfo::PASSABLE) === 0 || ($flags & SpawnBlockInfo::LAVA) !== 0){
						return false;
					}
				}
			}
		}
		return true;
	}

	/**
	 * A structure spawn attempt (see {@link StructureSpawnArea}) in the column of a successful cluster attempt.
	 * Structure mobs are all hostile: nothing spawns in peaceful. The spot must be 24 blocks or more from every player
	 * and dark enough for the picked mob; the herd members spread out from it like Java's
	 * NaturalSpawner.spawnCategoryForPosition() (a random walk of up to 5 blocks per member on the same level, each
	 * spot checked again).
	 *
	 * @return Living[]
	 * @phpstan-return list<Living>
	 */
	private function structureAttempt(StructureSpawnArea $area, Chunk $chunk, int $chunkX, int $chunkZ, int $lx, int $lz, int $x, int $z) : array{
		if($this->world->getDifficulty() === World::DIFFICULTY_PEACEFUL){
			return [];
		}
		$minY = max($this->world->getMinY() + 1, $area->minY);
		for($y = min($area->maxY, $this->world->getMaxY() - 1); $y >= $minY; --$y){
			$medium = $this->spotMedium($chunk, $lx, $lz, $x, $y, $z);
			if($medium === null){
				continue;
			}
			if($this->nearestPlayerDistance($x + 0.5, $y, $z + 0.5) < SpawnCondition::DEFAULT_MIN_DISTANCE){
				return [];
			}
			//structure mobs count as underground mobs, with a cap one higher
			if($this->countPopulation($chunkX, $chunkZ, $area->population, false) >= $this->getPopulationCap($area->population, false) + 1){
				return [];
			}
			$pick = $area->pickMob();
			if($pick === null){
				return [];
			}
			[$identifier, $size, $maxBrightness] = $pick;
			$maxBrightness ??= $area->maxBrightness;
			if(!$this->isDarkEnough($x, $y, $z, $maxBrightness)){
				return [];
			}
			$spawned = [];
			$used = [];
			$cx = $x;
			$cz = $z;
			for($i = 0; $i < $size; ++$i){
				if($i > 0){
					$next = $this->findStructureHerdPosition($area, $cx, $y, $cz, $maxBrightness, $used);
					if($next === null){
						continue;
					}
					[$cx, $cz, $medium] = $next;
				}
				$used[World::chunkHash($cx, $cz)] = true;
				$entity = EntitySpawnRegistry::getInstance()->create($identifier, new Location($cx + 0.5, $y, $cz + 0.5, $this->world, mt_rand(0, 359), 0));
				if(!$entity instanceof Living || !$this->fits($entity, $medium === SpawnSpot::MEDIUM_WATER)){
					$entity?->close();
					continue;
				}
				if(!$this->addNaturalMob($entity, false, $area->persistent, EntityNaturalSpawnEvent::CAUSE_STRUCTURE)){
					return $spawned;
				}
				Jockeys::onMobSpawned($entity, true);
				$spawned[] = $entity;
			}
			return $spawned;
		}
		return [];
	}

	private function isDarkEnough(int $x, int $y, int $z, ?int $maxBrightness) : bool{
		return $maxBrightness === null || max($this->world->getBlockLightAt($x, $y, $z), $this->world->getPotentialBlockSkyLightAt($x, $y, $z) - $this->getContext()->skyReductionWeather) <= $maxBrightness;
	}

	/**
	 * The next member of a structure herd: a random step from the previous one (Java: x += rand(6) - rand(6), same for
	 * z, same Y), inside the area, on a spawnable block not used by another member, far enough from players and dark
	 * enough. Returns [x, z, medium] or null.
	 *
	 * @phpstan-param array<int, true> $used
	 * @phpstan-return array{int, int, int}|null
	 */
	private function findStructureHerdPosition(StructureSpawnArea $area, int $fromX, int $y, int $fromZ, ?int $maxBrightness, array $used) : ?array{
		for($attempt = 0; $attempt < self::STRUCTURE_HERD_ATTEMPTS; ++$attempt){
			$x = $fromX + mt_rand(0, self::STRUCTURE_HERD_STEP - 1) - mt_rand(0, self::STRUCTURE_HERD_STEP - 1);
			$z = $fromZ + mt_rand(0, self::STRUCTURE_HERD_STEP - 1) - mt_rand(0, self::STRUCTURE_HERD_STEP - 1);
			if(isset($used[World::chunkHash($x, $z)]) || !$area->containsColumn($x, $z)){
				continue;
			}
			$chunk = $this->world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
			if($chunk === null || $chunk->isLightPopulated() !== true){
				continue;
			}
			$medium = $this->spotMedium($chunk, $x & Chunk::COORD_MASK, $z & Chunk::COORD_MASK, $x, $y, $z);
			if($medium === null || !$this->isDarkEnough($x, $y, $z, $maxBrightness) ||
				$this->nearestPlayerDistance($x + 0.5, $y, $z + 0.5) < SpawnCondition::DEFAULT_MIN_DISTANCE
			){
				continue;
			}
			return [$x, $z, $medium];
		}
		return null;
	}

	/**
	 * Runs a structure spawn attempt of the area in the column (for tests and plugins): the population cap, peaceful,
	 * light and player distance checks apply, the cluster spawn chance does not. Returns the spawned mobs.
	 *
	 * @return Living[]
	 * @phpstan-return list<Living>
	 */
	public function structureAttemptAt(StructureSpawnArea $area, int $x, int $z) : array{
		$chunkX = $x >> Chunk::COORD_BIT_SIZE;
		$chunkZ = $z >> Chunk::COORD_BIT_SIZE;
		$chunk = $this->world->getChunk($chunkX, $chunkZ);
		if($chunk === null || !$area->containsColumn($x, $z) || $this->getRules() === null){
			return [];
		}
		$this->players = [];
		foreach($this->world->getPlayers() as $player){
			if($player->isAlive() && !$player->isSpectator()){
				$this->players[] = $player;
			}
		}
		$this->context = null;
		return $this->structureAttempt($area, $chunk, $chunkX, $chunkZ, $x & Chunk::COORD_MASK, $z & Chunk::COORD_MASK, $x, $z);
	}

	/**
	 * Mobs and squids despawn by themselves ({@link Mob::checkDespawn()}, {@link Squid::canDespawn()}); other
	 * naturally spawned living entities (none in vanilla, maybe a plugin's) are checked here every second.
	 */
	private function despawnOtherMobs() : void{
		$alive = [];
		foreach($this->natural as $entity => $_){
			if($entity instanceof Mob || $entity instanceof Squid || $entity->isClosed() || $entity->isFlaggedForDespawn() || !$entity instanceof Living){
				continue;
			}
			$id = $entity->getId();
			$far = $this->farTicks[$id] ?? 0;
			if(MobDespawner::shouldDespawn($entity, $far, self::REFRESH_INTERVAL)){
				$entity->flagForDespawn();
				continue;
			}
			$alive[$id] = $far;
		}
		$this->farTicks = $alive;
	}

	private function countNaturalMobs(SpawnRules $rules) : int{
		$count = 0;
		foreach($this->world->getEntities() as $entity){
			if($this->isCounted($entity) && $rules->getPopulation($entity::getNetworkTypeId()) !== null){
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Whether the entity counts towards the caps (like Paper's count-all-mobs-for-spawning):
	 * - persistent mobs never count, nor plugin NPCs ({@link Sensing::looksLikePluginNpc()}: named, standing still or
	 *   not saved), like name-tagged mobs in vanilla: a lobby full of NPCs must not stop animals from spawning nearby;
	 * - with count-all-mobs-for-spawning (default) every other mob counts, including the other mobs of plugins (by
	 *   their network type), so that a plugin with its own spawning (e.g. Mobs) shares the caps instead of doubling
	 *   the mobs;
	 * - without it, only naturally spawned mobs count ({@link SpawnReason::NATURAL_REASONS}).
	 */
	private function isCounted(Entity $entity) : bool{
		if(!$entity instanceof Living || $entity instanceof Player || !$entity->isAlive() || $entity->isFlaggedForDespawn()){
			return false;
		}
		if(($entity instanceof Mob || $entity instanceof Squid) && $entity->isPersistent()){
			return false;
		}
		if(!str_starts_with($entity::class, "pocketmine\\")){
			return $this->countAllMobs && !Sensing::looksLikePluginNpc($entity);
		}
		if(isset($this->natural[$entity])){
			return true;
		}
		if(!$this->countAllMobs){
			return in_array($entity->getSpawnReason(), SpawnReason::NATURAL_REASONS, true);
		}
		//squids are no mobs, but despawn like them: a squid loaded from disk counts too
		return $entity instanceof Mob || $entity instanceof Squid;
	}

	/**
	 * @phpstan-param array{array<string, int>, array<string, int>} $counts
	 * @phpstan-param-out array{array<string, int>, array<string, int>} $counts
	 */
	private function addToCounts(array &$counts, Entity $entity, ?bool $surface = null) : void{
		$type = $entity::getNetworkTypeId();
		$population = $this->rules?->getPopulation($type);
		if($population === null){
			return;
		}
		$surface ??= $this->natural[$entity] ?? $this->isOnSurface($entity);
		$suffix = $surface ? "|1" : "|0";
		$counts[0][$population . $suffix] = ($counts[0][$population . $suffix] ?? 0) + 1;
		$counts[1][$type . $suffix] = ($counts[1][$type . $suffix] ?? 0) + 1;
	}

	/**
	 * Mobs loaded from disk lose their spawn classification: those at or above the highest block of their column
	 * count as surface mobs.
	 */
	private function isOnSurface(Entity $entity) : bool{
		$pos = $entity->getPosition();
		$top = $this->world->getHighestBlockAt((int) floor($pos->x), (int) floor($pos->z));
		return $top === null || $pos->y >= $top;
	}

	/**
	 * @phpstan-return array{array<string, int>, array<string, int>}
	 */
	private function getChunkCounts(int $chunkX, int $chunkZ) : array{
		$tick = $this->world->getServer()->getTick();
		if($this->countsTick !== $tick){
			$this->countsTick = $tick;
			$this->chunkCounts = [];
			$this->areaCounts = [];
		}
		$hash = World::chunkHash($chunkX, $chunkZ);
		if(!isset($this->chunkCounts[$hash])){
			$counts = [[], []];
			foreach($this->world->getChunkEntities($chunkX, $chunkZ) as $entity){
				if($this->isCounted($entity)){
					$this->addToCounts($counts, $entity);
				}
			}
			$this->chunkCounts[$hash] = $counts;
		}
		return $this->chunkCounts[$hash];
	}

	/**
	 * Naturally spawned mobs of the population type in the 9x9 chunks around the chunk.
	 */
	public function countPopulation(int $chunkX, int $chunkZ, string $population, bool $surface) : int{
		$key = $population . ($surface ? "|1" : "|0");
		$this->getChunkCounts($chunkX, $chunkZ);
		$memo = "p$chunkX:$chunkZ:$key";
		if(isset($this->areaCounts[$memo])){
			return $this->areaCounts[$memo];
		}
		$count = 0;
		$r = PopulationCaps::AREA_RADIUS_CHUNKS;
		for($cx = $chunkX - $r; $cx <= $chunkX + $r; ++$cx){
			for($cz = $chunkZ - $r; $cz <= $chunkZ + $r; ++$cz){
				$count += $this->getChunkCounts($cx, $cz)[0][$key] ?? 0;
			}
		}
		return $this->areaCounts[$memo] = $count;
	}

	/**
	 * Naturally spawned mobs of the type in the 9x9 chunks around the chunk (density limits).
	 */
	public function countType(int $chunkX, int $chunkZ, string $type, bool $surface) : int{
		$key = $type . ($surface ? "|1" : "|0");
		$count = 0;
		$r = PopulationCaps::AREA_RADIUS_CHUNKS;
		for($cx = $chunkX - $r; $cx <= $chunkX + $r; ++$cx){
			for($cz = $chunkZ - $r; $cz <= $chunkZ + $r; ++$cz){
				$count += $this->getChunkCounts($cx, $cz)[1][$key] ?? 0;
			}
		}
		return $count;
	}

	/**
	 * Spawns a mob of the rule at the position as the spawner would, without the distance, cap and chance checks (for
	 * tests and plugins). Returns the spawned mobs.
	 *
	 * @return Living[]
	 * @phpstan-return list<Living>
	 */
	public function spawnRuleAt(SpawnCondition $condition, int $x, int $y, int $z, bool $surface = true) : array{
		$location = new Location($x + 0.5, $y, $z + 0.5, $this->world, mt_rand(0, 359), 0);
		$chunk = $this->world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		if($chunk === null){
			return [];
		}
		$spot = $this->makeSpot($chunk, $x, $y, $z, $surface, SpawnSpot::MEDIUM_AIR);
		$entity = $this->createMob($condition, $spot, $location);
		if($entity === null){
			return [];
		}
		if(!$this->addNaturalMob($entity, $surface)){
			return [];
		}
		Jockeys::onMobSpawned($entity, true);
		return [$entity];
	}

	/**
	 * Debug helper: the spawn rules (identifiers) that could spawn at the position right now, ignoring caps.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getCandidatesAt(int $x, int $y, int $z, bool $surface) : array{
		$rules = $this->getRules();
		$chunk = $this->world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		if($rules === null || $chunk === null){
			return [];
		}
		$this->players = [];
		foreach($this->world->getPlayers() as $player){
			if($player->isAlive() && !$player->isSpectator()){
				$this->players[] = $player;
			}
		}
		$this->context = null;
		$medium = $this->spotMedium($chunk, $x & Chunk::COORD_MASK, $z & Chunk::COORD_MASK, $x, $y, $z);
		if($medium === null){
			return [];
		}
		$spot = $this->makeSpot($chunk, $x, $y, $z, $surface, $medium);
		$result = [];
		foreach($rules->forBiome($spot->biomeId) as $condition){
			if($condition->matchesSpot($spot, $this->getContext()) && $this->passesSpecialRules($condition, $spot)){
				$result[$condition->identifier] = true;
			}
		}
		return array_keys($result);
	}
}
