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

namespace pocketmine\world\dimension;

use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\boss\EnderDragon;
use pocketmine\entity\Location;
use pocketmine\entity\object\EndCrystal;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\promise\Promise;
use pocketmine\Server;
use pocketmine\utils\Filesystem;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;
use pocketmine\world\ChunkLoader;
use pocketmine\world\Explosion;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\end\TheEnd;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\generator\vanilla\EndSpike;
use pocketmine\world\generator\vanilla\EndSpikes;
use pocketmine\world\generator\vanilla\VanillaEndGenerator;
use pocketmine\world\Position;
use pocketmine\world\sound\MobSound;
use pocketmine\world\World;
use function abs;
use function array_pop;
use function count;
use function file_exists;
use function intdiv;
use function is_a;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use const JSON_THROW_ON_ERROR;

/**
 * The ender dragon fight of each End world (vanilla keeps it in the "DragonFight" record of the End's level data;
 * PocketMine keeps one table for all worlds in dragon_fights.json in the server data folder).
 *
 * The fight starts the first time a player arrives in the End: the empty exit portal is built at 0,0, End crystals
 * are placed on the obsidian pillars and the dragon appears above the island. When the dragon dies in the End, the
 * exit portal is activated (its portal blocks lead back to the Overworld) and the dragon egg appears on the first two
 * kills only (Bedrock), and one of the 20 End gateways around the island appears (see {@link EndGateways}). Dragons killed
 * after the first give 500 XP instead of 12000.
 *
 * Once the dragon is dead, placing an End crystal on each of the four sides of the exit portal (on the bedrock rim,
 * three blocks from the centre) starts the respawn sequence (see {@link DragonRespawnStage}): the exit portal closes,
 * the crystals' beams rebuild the pillars one by one with new crystals, then the four crystals explode and a new
 * dragon appears. Destroying one of the four crystals aborts it (the portal opens again). The sequence is saved and
 * continues after a restart.
 *
 * @phpstan-type RespawnState array{stage: string, time: int, crystals: list<array{int, int, int}>}
 * @phpstan-type FightState array{started: bool, kills: int, killed: bool, portal: list<int>|null, gateways: list<int>|null, respawn: RespawnState|null}
 */
final class DragonFight{
	use SingletonTrait;

	private const FILE_NAME = "dragon_fights.json";
	public const DRAGON_SPAWN_Y = 128;
	/** Chunks around 0,0 loaded when the fight starts (the pillars are within 42+5 blocks). */
	private const START_CHUNK_RADIUS = 4;
	/** Ticks between two checks for players in an End whose fight hasn't started. */
	private const START_CHECK_INTERVAL = 20;
	/**
	 * Number of kills that leave a dragon egg: Bedrock places one after the first and the second dragon (minecraft.wiki
	 * Dragon Egg, Bedrock Edition: "two dragon eggs generate"; Java only after the first).
	 */
	public const EGG_KILLS = 2;

	/** Distance of the respawn crystals from the centre of the exit portal (one on each side, on the bedrock rim). */
	public const RESPAWN_CRYSTAL_DISTANCE = 3;
	/** Ticks spent on each pillar while summoning them (the beam aims at it, then it is rebuilt). */
	public const PILLAR_TICKS = 40;
	/** Radius of the cube cleared around the top of a pillar before it is rebuilt. */
	private const PILLAR_CLEAR_RADIUS = 10;
	private const PILLAR_EXPLOSION_POWER = 5.0;
	/** The respawn sequence is saved every this many ticks (and on every stage change). */
	private const RESPAWN_SAVE_INTERVAL = 20;
	/** Players within this distance of the portal hear the dragon growls of the respawn sequence. */
	private const GROWL_RANGE = 192.0;

	private ?string $path;
	private bool $loaded = false;
	/**
	 * @var mixed[][] world folder name => state
	 * @phpstan-var array<string, FightState>
	 */
	private array $fights = [];
	/**
	 * Respawn crystals found in each world, by index of their saved position.
	 * @var EndCrystal[][]
	 * @phpstan-var array<string, array<int, EndCrystal>>
	 */
	private array $respawnCrystals = [];

	/**
	 * @param string|null $path JSON file the fights are saved to; null for the default in the server data folder,
	 *                          "" to keep them in memory only
	 */
	public function __construct(?string $path = null){
		$this->path = $path ?? Server::getInstance()->getDataPath() . self::FILE_NAME;
		if($this->path === ""){
			$this->path = null;
		}
	}

	/**
	 * Returns the fight table, or null outside a running server (no server data folder to create the default one
	 * in, e.g. in unit tests that didn't set one).
	 */
	private static function getInstanceIfAvailable() : ?self{
		if(self::$instance !== null){
			return self::$instance;
		}
		try{
			return self::getInstance();
		}catch(\RuntimeException $e){
			return null;
		}
	}

	/**
	 * @internal Called by End worlds every tick: starts the fight once players are there, and runs the dragon respawn
	 * sequence.
	 */
	public static function tickWorld(World $world) : void{
		$fight = self::getInstanceIfAvailable();
		if($fight === null){
			return;
		}
		if($world->getServer()->getTick() % self::START_CHECK_INTERVAL === 0 && count($world->getPlayers()) > 0){
			//players who didn't come through an End portal (logged in there, teleported by a command or a plugin) start
			//it too, like vanilla, where the fight ticks with the End whatever brought the players there
			$fight->onPlayerArrived($world);
		}
		$fight->tickRespawn($world);
	}

	/**
	 * @internal Called when an End crystal is destroyed.
	 */
	public static function notifyCrystalDestroyed(EndCrystal $crystal) : void{
		if($crystal->getWorld()->getDimension() === Dimension::END){
			self::getInstanceIfAvailable()?->onCrystalDestroyed($crystal);
		}
	}

	/**
	 * @phpstan-return FightState
	 */
	private static function defaultState() : array{
		return ["started" => false, "kills" => 0, "killed" => false, "portal" => null, "gateways" => null, "respawn" => null];
	}

	private function load() : void{
		if($this->loaded){
			return;
		}
		$this->loaded = true;
		if($this->path === null || !file_exists($this->path)){
			return;
		}
		try{
			$data = json_decode(Filesystem::fileGetContents($this->path), true, 8, JSON_THROW_ON_ERROR);
		}catch(\JsonException $e){
			\GlobalLogger::get()->error("Ignoring corrupted dragon fight file " . $this->path . ": " . $e->getMessage());
			return;
		}
		if(!is_array($data)){
			return;
		}
		foreach(Utils::promoteKeys($data) as $world => $entry){
			if(!is_array($entry)){
				continue;
			}
			$portal = $entry["portal"] ?? null;
			$gateways = null;
			if(is_array($entry["gateways"] ?? null)){
				$gateways = [];
				foreach($entry["gateways"] as $index){
					if(is_int($index) && $index >= 0 && $index < EndGateways::COUNT){
						$gateways[] = $index;
					}
				}
			}
			$kills = is_int($entry["kills"] ?? null) ? $entry["kills"] : 0;
			$this->fights[(string) $world] = [
				"started" => is_bool($entry["started"] ?? null) ? $entry["started"] : false,
				"kills" => $kills,
				//files from before the respawn existed: the dragon is dead once it was killed
				"killed" => is_bool($entry["killed"] ?? null) ? $entry["killed"] : $kills > 0,
				"portal" => self::readPosition($portal),
				"gateways" => $gateways,
				"respawn" => self::readRespawn($entry["respawn"] ?? null)
			];
		}
	}

	/**
	 * @phpstan-return array{int, int, int}|null
	 */
	private static function readPosition(mixed $pos) : ?array{
		return is_array($pos) && count($pos) === 3 && is_int($pos[0] ?? null) && is_int($pos[1] ?? null) && is_int($pos[2] ?? null) ? [$pos[0], $pos[1], $pos[2]] : null;
	}

	/**
	 * @phpstan-return RespawnState|null
	 */
	private static function readRespawn(mixed $respawn) : ?array{
		if(!is_array($respawn) || !is_string($respawn["stage"] ?? null) || DragonRespawnStage::tryFrom($respawn["stage"]) === null || !is_array($respawn["crystals"] ?? null)){
			return null;
		}
		$crystals = [];
		foreach($respawn["crystals"] as $pos){
			$pos = self::readPosition($pos);
			if($pos !== null){
				$crystals[] = $pos;
			}
		}
		return [
			"stage" => $respawn["stage"],
			"time" => is_int($respawn["time"] ?? null) ? $respawn["time"] : 0,
			"crystals" => $crystals
		];
	}

	private function save() : void{
		if($this->path !== null){
			Filesystem::safeFilePutContents($this->path, json_encode((object) $this->fights, JSON_THROW_ON_ERROR));
		}
	}

	/**
	 * @phpstan-return FightState
	 */
	private function get(World $world) : array{
		$this->load();
		return $this->fights[$world->getFolderName()] ?? self::defaultState();
	}

	/**
	 * @phpstan-param FightState $state
	 */
	private function set(World $world, array $state, bool $save = true) : void{
		$this->fights[$world->getFolderName()] = $state;
		if($save){
			$this->save();
		}
	}

	public function isStarted(World $world) : bool{
		return $this->get($world)["started"];
	}

	/**
	 * Number of dragons killed in this world.
	 */
	public function getKills(World $world) : int{
		return $this->get($world)["kills"];
	}

	/**
	 * Whether the dragon of this world was killed and not respawned yet (it can be respawned with End crystals).
	 */
	public function isDragonKilled(World $world) : bool{
		return $this->get($world)["killed"];
	}

	/**
	 * Returns the stage of the dragon respawn sequence running in this world, or null when there is none.
	 */
	public function getRespawnStage(World $world) : ?DragonRespawnStage{
		$respawn = $this->get($world)["respawn"];
		return $respawn !== null ? DragonRespawnStage::tryFrom($respawn["stage"]) : null;
	}

	/**
	 * Returns the centre of the exit portal's portal layer, deciding it (on the island surface at 0,0) if it was never
	 * built.
	 */
	public function getExitPortalOrigin(World $world) : Vector3{
		$state = $this->get($world);
		if($state["portal"] !== null){
			[$x, $y, $z] = $state["portal"];
			return new Vector3($x, $y, $z);
		}
		$origin = EndExitPortal::findOrigin($world);
		if($world->isChunkLoaded(EndExitPortal::X >> Chunk::COORD_BIT_SIZE, EndExitPortal::Z >> Chunk::COORD_BIT_SIZE)){
			$state["portal"] = [$origin->getFloorX(), $origin->getFloorY(), $origin->getFloorZ()];
			$this->set($world, $state);
		}
		return $origin;
	}

	/**
	 * Called when a player arrives in an End world: starts the fight the first time.
	 */
	public function onPlayerArrived(World $world) : void{
		if($world->getDimension() !== Dimension::END || $this->isStarted($world)){
			return;
		}
		$state = $this->get($world);
		$state["started"] = true;
		$this->set($world, $state);

		$loader = new class implements ChunkLoader{};
		$r = self::START_CHUNK_RADIUS;
		$promises = [];
		for($cx = -$r; $cx < $r; ++$cx){
			for($cz = -$r; $cz < $r; ++$cz){
				$world->registerChunkLoader($loader, $cx, $cz);
				$promises[] = $world->requestChunkPopulation($cx, $cz, $loader);
			}
		}
		$release = function() use ($world, $loader, $r) : void{
			if(!$world->isLoaded()){
				return;
			}
			for($cx = -$r; $cx < $r; ++$cx){
				for($cz = -$r; $cz < $r; ++$cz){
					$world->unregisterChunkLoader($loader, $cx, $cz);
				}
			}
		};
		Promise::all($promises)->onCompletion(
			function() use ($world, $release) : void{
				try{
					if($world->isLoaded()){
						$this->start($world);
					}
				}finally{
					$release();
				}
			},
			function() use ($world, $release) : void{
				$state = $this->get($world);
				$state["started"] = false; //try again next time
				$this->set($world, $state);
				$release();
			}
		);
	}

	/**
	 * Sets up the fight in a loaded End: empty exit portal, crystals on the pillars, and the dragon.
	 */
	public function start(World $world) : EnderDragon{
		$state = $this->get($world);
		$state["started"] = true;
		$state["killed"] = false;
		$this->set($world, $state);

		EndExitPortal::build($world, $this->getExitPortalOrigin($world), false);
		self::placeCrystals($world);
		return self::spawnDragon($world);
	}

	/**
	 * Spawns a new dragon above the exit portal (vanilla: at 0,128,0 with a random yaw).
	 */
	private static function spawnDragon(World $world) : EnderDragon{
		$dragon = new EnderDragon(new Location(EndExitPortal::X + 0.5, self::DRAGON_SPAWN_Y, EndExitPortal::Z + 0.5, $world, Utils::getRandomFloat() * 360, 0));
		$dragon->setFightDragon(true);
		$dragon->spawnToAll();
		return $dragon;
	}

	/**
	 * The obsidian spikes of an End world: vanilla's spikes for the seed when the world is generated by the vanilla
	 * End generator, otherwise the pillars of the built-in End generator (without iron bar cages).
	 *
	 * @return EndSpike[]
	 * @phpstan-return list<EndSpike>
	 */
	public static function getSpikes(World $world) : array{
		$generatorName = $world->getProvider()->getWorldData()->getGenerator();
		$generator = GeneratorManager::getInstance()->getGenerator($generatorName);
		if($generator !== null && is_a($generator->getGeneratorClass(), VanillaEndGenerator::class, true)){
			return EndSpikes::forSeed($world->getSeed());
		}
		$spikes = [];
		foreach(TheEnd::computePillars($world->getSeed()) as [$x, $z, $radius, $height]){
			$spikes[] = new EndSpike($x, $z, $radius, $height, false);
		}
		return $spikes;
	}

	/**
	 * The obsidian pillars of an End world (see {@link DragonFight::getSpikes()}).
	 *
	 * @return int[][] x, z, height (y of the bedrock block the crystal stands on)
	 * @phpstan-return list<array{int, int, int}>
	 */
	public static function getPillars(World $world) : array{
		$pillars = [];
		foreach(self::getSpikes($world) as $spike){
			$pillars[] = [$spike->centerX, $spike->centerZ, $spike->height];
		}
		return $pillars;
	}

	/**
	 * Places an End crystal on top of each obsidian pillar (on its bedrock block), like vanilla's spike feature.
	 *
	 * @return EndCrystal[]
	 */
	public static function placeCrystals(World $world) : array{
		$crystals = [];
		foreach(self::getPillars($world) as [$x, $z, $height]){
			if(!$world->isChunkLoaded($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)){
				continue;
			}
			$crystal = new EndCrystal(new Location($x + 0.5, $height + 1, $z + 0.5, $world, 0, 0));
			$crystal->setShowBase(true);
			$crystal->spawnToAll();
			$crystals[] = $crystal;
		}
		return $crystals;
	}

	/**
	 * Called when a dragon dies in an End world: activates the exit portal, places the egg on the first kills and
	 * counts the kill.
	 */
	public function onDragonKilled(World $world) : void{
		//the portal spans chunks -1..0 on both axes: make sure they are there even if nobody is around
		for($cx = -1; $cx <= 0; ++$cx){
			for($cz = -1; $cz <= 0; ++$cz){
				if(!$world->isChunkLoaded($cx, $cz) && $world->loadChunk($cx, $cz) === null){
					\GlobalLogger::get()->warning("Cannot build the End exit portal of " . $world->getFolderName() . ": chunk $cx $cz is not generated");
					return;
				}
			}
		}
		$origin = $this->getExitPortalOrigin($world);
		$state = $this->get($world);
		EndExitPortal::build($world, $origin, true);
		if($state["kills"] < self::EGG_KILLS){
			EndExitPortal::placeDragonEgg($world, $origin);
		}
		$state["started"] = true;
		$state["killed"] = true;
		$state["respawn"] = null;
		++$state["kills"];
		$this->set($world, $state);
		$this->spawnNextGateway($world);
	}

	/**
	 * Spawns the next of the 20 End gateways around the main island (vanilla: one per dragon kill, see
	 * {@link EndGateways}). Returns its position, or null when all 20 are there.
	 */
	public function spawnNextGateway(World $world) : ?Vector3{
		$state = $this->get($world);
		$gateways = $state["gateways"] ?? EndGateways::getRingOrder($world->getSeed());
		$index = array_pop($gateways);
		$state["gateways"] = $gateways;
		$this->set($world, $state);
		if($index === null){
			return null;
		}
		$pos = EndGateways::getRingPosition($index);
		//the frame reaches one block around the gateway, possibly into the next chunks
		$loader = new class implements ChunkLoader{};
		$chunks = [];
		$promises = [];
		for($cx = ($pos->getFloorX() - 1) >> Chunk::COORD_BIT_SIZE; $cx <= ($pos->getFloorX() + 1) >> Chunk::COORD_BIT_SIZE; ++$cx){
			for($cz = ($pos->getFloorZ() - 1) >> Chunk::COORD_BIT_SIZE; $cz <= ($pos->getFloorZ() + 1) >> Chunk::COORD_BIT_SIZE; ++$cz){
				$chunks[] = [$cx, $cz];
				$world->registerChunkLoader($loader, $cx, $cz);
				$promises[] = $world->requestChunkPopulation($cx, $cz, $loader);
			}
		}
		$release = function() use ($world, $loader, $chunks) : void{
			if($world->isLoaded()){
				foreach($chunks as [$cx, $cz]){
					$world->unregisterChunkLoader($loader, $cx, $cz);
				}
			}
		};
		Promise::all($promises)->onCompletion(
			function() use ($world, $pos, $release) : void{
				try{
					if($world->isLoaded()){
						EndGateways::build($world, $pos, null, false);
					}
				}finally{
					$release();
				}
			},
			function() use ($world, $pos, $release) : void{
				$release();
				\GlobalLogger::get()->warning("Cannot spawn the End gateway at $pos in " . $world->getFolderName() . ": its chunks could not be generated");
			}
		);
		return $pos;
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Respawn
	// ---------------------------------------------------------------------------------------------------------------

	/**
	 * Returns a living ender dragon of the world, if there is one.
	 */
	private static function findLivingDragon(World $world) : ?EnderDragon{
		foreach($world->getEntities() as $entity){
			if($entity instanceof EnderDragon && $entity->isAlive() && !$entity->isClosed() && !$entity->isFlaggedForDespawn()){
				return $entity;
			}
		}
		return null;
	}

	/**
	 * Returns the living End crystals touching the given block.
	 *
	 * @return EndCrystal[]
	 * @phpstan-return list<EndCrystal>
	 */
	private static function findCrystalsAt(World $world, int $x, int $y, int $z) : array{
		$crystals = [];
		foreach($world->getNearbyEntities(new AxisAlignedBB($x, $y, $z, $x + 1, $y + 1, $z + 1)) as $entity){
			if($entity instanceof EndCrystal && $entity->isAlive() && !$entity->isClosed() && !$entity->isFlaggedForDespawn()){
				$crystals[] = $entity;
			}
		}
		return $crystals;
	}

	/**
	 * Starts the dragon respawn sequence if the dragon of this End world is dead and there is an End crystal on each
	 * of the four sides of the exit portal (vanilla tryRespawn(), called when a crystal is placed). Returns whether it
	 * started.
	 */
	public function tryRespawn(World $world) : bool{
		if($world->getDimension() !== Dimension::END){
			return false;
		}
		$state = $this->get($world);
		if(!$state["killed"] || $state["respawn"] !== null || self::findLivingDragon($world) !== null){
			return false;
		}
		$center = $this->getExitPortalOrigin($world)->add(0, 1, 0);
		$crystals = [];
		foreach(Facing::HORIZONTAL as $facing){
			$pos = $center->getSide($facing, self::RESPAWN_CRYSTAL_DISTANCE);
			$found = self::findCrystalsAt($world, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
			if(count($found) === 0){
				return false;
			}
			foreach($found as $crystal){
				$crystals[] = $crystal;
			}
		}
		$this->startRespawn($world, $crystals);
		return true;
	}

	/**
	 * @param EndCrystal[] $crystals
	 * @phpstan-param list<EndCrystal> $crystals
	 */
	private function startRespawn(World $world, array $crystals) : void{
		$folder = $world->getFolderName();
		$positions = [];
		$this->respawnCrystals[$folder] = [];
		foreach($crystals as $i => $crystal){
			$pos = $crystal->getPosition();
			$positions[] = [$pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()];
			$this->respawnCrystals[$folder][$i] = $crystal;
		}
		$state = $this->get($world);
		$state["respawn"] = ["stage" => DragonRespawnStage::START->value, "time" => 0, "crystals" => $positions];
		$this->set($world, $state);
		//vanilla closes the exit portal as soon as the sequence starts
		EndExitPortal::build($world, $this->getExitPortalOrigin($world), false);
	}

	/**
	 * Returns the respawn crystals that are still there (found again by their position after a restart or after their
	 * chunk was reloaded).
	 *
	 * @phpstan-param list<array{int, int, int}> $positions
	 * @return EndCrystal[]
	 * @phpstan-return list<EndCrystal>
	 */
	private function getRespawnCrystals(World $world, array $positions) : array{
		$folder = $world->getFolderName();
		$crystals = [];
		foreach($positions as $i => [$x, $y, $z]){
			$crystal = $this->respawnCrystals[$folder][$i] ?? null;
			if($crystal === null || $crystal->isClosed() || !$crystal->isAlive() || $crystal->isFlaggedForDespawn()){
				$crystal = self::findCrystalsAt($world, $x, $y, $z)[0] ?? null;
				if($crystal === null){
					unset($this->respawnCrystals[$folder][$i]);
					continue;
				}
				$this->respawnCrystals[$folder][$i] = $crystal;
			}
			$crystals[] = $crystal;
		}
		return $crystals;
	}

	/**
	 * Runs one tick of the respawn sequence of this world, if there is one. It waits while the exit portal's chunk is
	 * not loaded (vanilla only runs it while players are around).
	 */
	public function tickRespawn(World $world) : void{
		$state = $this->get($world);
		$respawn = $state["respawn"];
		if($respawn === null){
			return;
		}
		$origin = $this->getExitPortalOrigin($world);
		if(!$world->isChunkLoaded($origin->getFloorX() >> Chunk::COORD_BIT_SIZE, $origin->getFloorZ() >> Chunk::COORD_BIT_SIZE)){
			return;
		}
		$stage = DragonRespawnStage::tryFrom($respawn["stage"]);
		$crystals = $this->getRespawnCrystals($world, $respawn["crystals"]);
		if($stage === null || count($crystals) === 0){
			$this->abortRespawn($world);
			return;
		}
		//like vanilla, the time of a stage counts from 1 on its first tick
		$time = $respawn["time"] + 1;
		$respawn["time"] = $time;
		$state["respawn"] = $respawn;
		$this->set($world, $state, $time % self::RESPAWN_SAVE_INTERVAL === 0);

		$this->tickStage($world, $stage, $time, $crystals);
	}

	/**
	 * Vanilla DragonRespawnStage.tick(). Vanilla's counter never shows 0 to the stages (it is incremented first), so
	 * its "time 0" actions (aiming the beams at the first pillar, and back above the portal before the dragon comes)
	 * never run; here they run on the first tick of the stage.
	 *
	 * @param EndCrystal[] $crystals
	 * @phpstan-param list<EndCrystal> $crystals
	 */
	private function tickStage(World $world, DragonRespawnStage $stage, int $time, array $crystals) : void{
		$aboveCenter = new Vector3(EndExitPortal::X, self::DRAGON_SPAWN_Y, EndExitPortal::Z);
		switch($stage){
			case DragonRespawnStage::START:
				foreach($crystals as $crystal){
					$crystal->setBeamTarget($aboveCenter);
				}
				$this->setRespawnStage($world, DragonRespawnStage::PREPARING_TO_SUMMON_PILLARS, $crystals);
				break;
			case DragonRespawnStage::PREPARING_TO_SUMMON_PILLARS:
				if($time >= 100){
					$this->setRespawnStage($world, DragonRespawnStage::SUMMONING_PILLARS, $crystals);
				}elseif($time === 1 || $time === 50 || $time === 51 || $time === 52 || $time >= 95){
					$this->growl($world);
				}
				break;
			case DragonRespawnStage::SUMMONING_PILLARS:
				$startOfBeam = $time % self::PILLAR_TICKS === 0 || $time === 1;
				$endOfBeam = $time % self::PILLAR_TICKS === self::PILLAR_TICKS - 1;
				if(!$startOfBeam && !$endOfBeam){
					break;
				}
				$spikes = self::getSpikes($world);
				$index = intdiv($time, self::PILLAR_TICKS);
				if($index < count($spikes)){
					$spike = $spikes[$index];
					if($startOfBeam){
						foreach($crystals as $crystal){
							$crystal->setBeamTarget(new Vector3($spike->centerX, $spike->height + 1, $spike->centerZ));
						}
					}else{
						self::rebuildSpike($world, $spike, $aboveCenter);
					}
				}elseif($startOfBeam){
					$this->setRespawnStage($world, DragonRespawnStage::SUMMONING_DRAGON, $crystals);
				}
				break;
			case DragonRespawnStage::SUMMONING_DRAGON:
				if($time >= 100){
					$this->setRespawnStage($world, DragonRespawnStage::END, $crystals);
				}elseif($time >= 80){
					$this->growl($world);
				}elseif($time === 1){
					foreach($crystals as $crystal){
						$crystal->setBeamTarget($aboveCenter);
					}
				}elseif($time < 5){
					$this->growl($world);
				}
				break;
			case DragonRespawnStage::END:
				break;
		}
	}

	/**
	 * @param EndCrystal[] $crystals
	 * @phpstan-param list<EndCrystal> $crystals
	 */
	private function setRespawnStage(World $world, DragonRespawnStage $stage, array $crystals) : void{
		$state = $this->get($world);
		$respawn = $state["respawn"];
		if($respawn === null){
			return;
		}
		if($stage === DragonRespawnStage::END){
			$this->finishRespawn($world, $crystals);
			return;
		}
		$respawn["stage"] = $stage->value;
		$respawn["time"] = 0;
		$state["respawn"] = $respawn;
		$this->set($world, $state);
	}

	/**
	 * End of the sequence: the four crystals explode (without breaking blocks) and the new dragon appears.
	 *
	 * @param EndCrystal[] $crystals
	 * @phpstan-param list<EndCrystal> $crystals
	 */
	private function finishRespawn(World $world, array $crystals) : void{
		$state = $this->get($world);
		$state["respawn"] = null;
		$state["killed"] = false;
		$this->set($world, $state);
		unset($this->respawnCrystals[$world->getFolderName()]);

		self::resetSpikeCrystals($world);
		//they must not destroy each other (which would make them explode again, breaking blocks)
		foreach($crystals as $crystal){
			$crystal->setInvulnerable(true);
			$crystal->setBeamTarget(null);
		}
		foreach($crystals as $crystal){
			$explosion = new Explosion($crystal->getPosition(), EndCrystal::EXPLOSION_POWER, $crystal);
			$explosion->explodeB();
			$crystal->flagForDespawn();
		}
		self::spawnDragon($world);
	}

	/**
	 * Stops the respawn sequence of this world (vanilla: when one of the four crystals is destroyed): the exit portal
	 * opens again and the rebuilt pillars' crystals become vulnerable.
	 */
	public function abortRespawn(World $world) : void{
		$state = $this->get($world);
		$respawn = $state["respawn"];
		if($respawn === null){
			return;
		}
		$crystals = $this->getRespawnCrystals($world, $respawn["crystals"]);
		$state["respawn"] = null;
		$this->set($world, $state);
		unset($this->respawnCrystals[$world->getFolderName()]);

		foreach($crystals as $crystal){
			$crystal->setBeamTarget(null);
		}
		self::resetSpikeCrystals($world);
		$origin = $this->getExitPortalOrigin($world);
		if($world->isChunkLoaded($origin->getFloorX() >> Chunk::COORD_BIT_SIZE, $origin->getFloorZ() >> Chunk::COORD_BIT_SIZE)){
			EndExitPortal::build($world, $origin, true);
		}
	}

	/**
	 * Called when an End crystal is destroyed: destroying one of the crystals of the respawn sequence aborts it.
	 */
	public function onCrystalDestroyed(EndCrystal $crystal) : void{
		$world = $crystal->getWorld();
		$respawn = $this->get($world)["respawn"];
		if($respawn === null){
			return;
		}
		$pos = $crystal->getPosition();
		foreach($respawn["crystals"] as $i => [$x, $y, $z]){
			if(($this->respawnCrystals[$world->getFolderName()][$i] ?? null) === $crystal || ($pos->getFloorX() === $x && $pos->getFloorY() === $y && $pos->getFloorZ() === $z)){
				$this->abortRespawn($world);
				return;
			}
		}
	}

	/**
	 * The crystals on top of the pillars become vulnerable again and stop aiming their beams (vanilla
	 * resetSpikeCrystals()).
	 */
	private static function resetSpikeCrystals(World $world) : void{
		$spikes = self::getSpikes($world);
		foreach($world->getEntities() as $entity){
			if(!$entity instanceof EndCrystal){
				continue;
			}
			$pos = $entity->getPosition();
			foreach($spikes as $spike){
				if(abs($pos->x - ($spike->centerX + 0.5)) <= $spike->radius + 1 && abs($pos->z - ($spike->centerZ + 0.5)) <= $spike->radius + 1 && $pos->y >= $spike->height){
					$entity->setInvulnerable(false);
					$entity->setBeamTarget(null);
					break;
				}
			}
		}
	}

	/**
	 * The dragon growls heard while the sequence runs (vanilla level event 3001, heard from very far): played at each
	 * player of the world near the fight.
	 */
	private function growl(World $world) : void{
		$center = new Vector3(EndExitPortal::X, self::DRAGON_SPAWN_Y, EndExitPortal::Z);
		$sound = new MobSound(LevelSoundEvent::AMBIENT, EntityIds::ENDER_DRAGON);
		foreach($world->getPlayers() as $player){
			$pos = $player->getPosition();
			if($pos->distance($center) <= self::GROWL_RANGE){
				$world->addSound($pos, $sound, [$player]);
			}
		}
	}

	/**
	 * Rebuilds a pillar during the respawn sequence (vanilla SUMMONING_PILLARS): the blocks within 10 of its top are
	 * removed, an explosion goes off there, and the spike is placed again like the world generator does (obsidian
	 * column, iron bar cage on the guarded ones, bedrock and fire on top) with an invulnerable crystal aiming at the
	 * given target. Returns the new crystal, or null if the spike's chunks are not generated.
	 */
	public static function rebuildSpike(World $world, EndSpike $spike, ?Vector3 $beamTarget) : ?EndCrystal{
		$cx = $spike->centerX;
		$cz = $spike->centerZ;
		$top = $spike->height;
		$r = self::PILLAR_CLEAR_RADIUS;
		$reach = max($r, $spike->radius);
		for($chunkX = ($cx - $reach) >> Chunk::COORD_BIT_SIZE; $chunkX <= ($cx + $reach) >> Chunk::COORD_BIT_SIZE; ++$chunkX){
			for($chunkZ = ($cz - $reach) >> Chunk::COORD_BIT_SIZE; $chunkZ <= ($cz + $reach) >> Chunk::COORD_BIT_SIZE; ++$chunkZ){
				if(!$world->isChunkLoaded($chunkX, $chunkZ) && $world->loadChunk($chunkX, $chunkZ) === null){
					\GlobalLogger::get()->warning("Cannot rebuild the End spike at $cx $cz in " . $world->getFolderName() . ": chunk $chunkX $chunkZ is not generated");
					return null;
				}
			}
		}

		//the old crystal goes away with the blocks (vanilla removes it without an explosion)
		$box = new AxisAlignedBB($cx - $r, $top - $r, $cz - $r, $cx + $r + 1, $top + $r + 1, $cz + $r + 1);
		foreach($world->getNearbyEntities($box) as $entity){
			if($entity instanceof EndCrystal){
				$entity->flagForDespawn();
			}
		}
		$air = VanillaBlocks::AIR();
		for($x = $cx - $r; $x <= $cx + $r; ++$x){
			for($z = $cz - $r; $z <= $cz + $r; ++$z){
				for($y = $top - $r; $y <= $top + $r; ++$y){
					self::setBlockIfDifferent($world, $x, $y, $z, $air);
				}
			}
		}
		$explosion = new Explosion(new Position($cx + 0.5, $top, $cz + 0.5, $world), self::PILLAR_EXPLOSION_POWER);
		$explosion->explodeA();
		$explosion->explodeB();

		//vanilla EndSpikeFeature.placeSpike()
		$obsidian = VanillaBlocks::OBSIDIAN();
		$radius = $spike->radius;
		$limit = $radius * $radius + 1;
		$minY = $world->getMinY();
		for($x = $cx - $radius; $x <= $cx + $radius; ++$x){
			for($z = $cz - $radius; $z <= $cz + $radius; ++$z){
				$dx = $x - $cx;
				$dz = $z - $cz;
				$inside = $dx * $dx + $dz * $dz <= $limit;
				for($y = $minY; $y <= $top + 10; ++$y){
					if($inside && $y < $top){
						self::setBlockIfDifferent($world, $x, $y, $z, $obsidian);
					}elseif($y > 65){
						self::setBlockIfDifferent($world, $x, $y, $z, $air);
					}
				}
			}
		}
		if($spike->guarded){
			$bars = VanillaBlocks::IRON_BARS();
			for($dx = -2; $dx <= 2; ++$dx){
				for($dz = -2; $dz <= 2; ++$dz){
					for($dy = 0; $dy <= 3; ++$dy){
						if(abs($dx) === 2 || abs($dz) === 2 || $dy === 3){
							$world->setBlockAt($cx + $dx, $top + $dy, $cz + $dz, $bars);
						}
					}
				}
			}
		}
		$world->setBlockAt($cx, $top, $cz, VanillaBlocks::BEDROCK());
		$world->setBlockAt($cx, $top + 1, $cz, VanillaBlocks::FIRE());

		$crystal = new EndCrystal(new Location($cx + 0.5, $top + 1, $cz + 0.5, $world, Utils::getRandomFloat() * 360, 0));
		$crystal->setShowBase(true);
		$crystal->setBeamTarget($beamTarget);
		$crystal->setInvulnerable(true);
		$crystal->spawnToAll();
		return $crystal;
	}

	private static function setBlockIfDifferent(World $world, int $x, int $y, int $z, Block $block) : void{
		if(!$world->isInWorld($x, $y, $z)){
			return;
		}
		$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		if($chunk !== null && $chunk->getBlockStateId($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK) !== $block->getStateId()){
			$world->setBlockAt($x, $y, $z, $block, false);
		}
	}
}
