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

namespace pocketmine\world;

use pocketmine\data\bedrock\BiomeIds;
use pocketmine\entity\Entity;
use pocketmine\event\world\WorldInitEvent;
use pocketmine\event\world\WorldLoadEvent;
use pocketmine\event\world\WorldUnloadEvent;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\math\Vector3;
use pocketmine\player\ChunkSelector;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\exception\CorruptedChunkException;
use pocketmine\world\format\io\exception\CorruptedWorldException;
use pocketmine\world\format\io\exception\UnsupportedWorldFormatException;
use pocketmine\world\format\io\FormatConverter;
use pocketmine\world\format\io\WorldProviderManager;
use pocketmine\world\format\io\WritableWorldProvider;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\generator\GeneratorManagerEntry;
use pocketmine\world\generator\InvalidGeneratorOptionsException;
use Symfony\Component\Filesystem\Path;
use function array_keys;
use function array_shift;
use function assert;
use function ceil;
use function count;
use function floor;
use function implode;
use function intdiv;
use function iterator_to_array;
use function max;
use function microtime;
use function min;
use function round;
use function sprintf;
use function str_ends_with;
use function strlen;
use function strval;
use function substr;
use function trim;
use const INF;

class WorldManager{
	public const TICKS_PER_AUTOSAVE = 300 * Server::TARGET_TICKS_PER_SECOND;

	/**
	 * @var World[]
	 * @phpstan-var array<int, World>
	 */
	private array $worlds = [];
	private ?World $defaultWorld = null;

	private bool $autoSave = true;
	private int $autoSaveTicks = self::TICKS_PER_AUTOSAVE;
	private int $autoSaveTicker = 0;

	/**
	 * Default time per tick (seconds) an autosave may spend saving chunks and players, see
	 * {@link WorldManager::setAutoSaveTickBudget()}.
	 */
	public const DEFAULT_AUTOSAVE_TICK_BUDGET = 0.005;
	/**
	 * Point of the tick (seconds after its start) up to which an autosave may use the time left before the next tick,
	 * when that is more than the budget: an idle server finishes its saves sooner without being slowed down.
	 */
	private const AUTOSAVE_IDLE_TIME_END = 0.035;
	/** Highest usage (percent of a tick) of the previous tick for which that time left may be used. */
	private const AUTOSAVE_IDLE_MAX_TICK_USAGE = 50;
	/**
	 * Longest an autosave may spend on chunks, in ticks: if the budget is not enough, more chunks are saved per tick
	 * so that every chunk is saved within this time (or a fifth of the autosave interval, if that is shorter).
	 */
	private const AUTOSAVE_MAX_CHUNK_TICKS = 60 * Server::TARGET_TICKS_PER_SECOND;

	private const AUTOSAVE_PHASE_CHUNKS = 1;
	private const AUTOSAVE_PHASE_PLAYERS = 2;

	private float $autoSaveTickBudget = self::DEFAULT_AUTOSAVE_TICK_BUDGET;
	/** Phase of the autosave in progress (AUTOSAVE_PHASE_*), or null if none. */
	private ?int $autoSavePhase = null;
	private int $autoSaveStartTick = 0;
	private float $autoSaveStartTime = 0;
	/**
	 * Worlds of the autosave in progress whose autosave was started (their world data is saved at the end).
	 * @var World[]
	 * @phpstan-var array<int, World>
	 */
	private array $autoSaveWorlds = [];
	/**
	 * Worlds of the autosave in progress whose chunks are not all done yet.
	 * @var World[]
	 * @phpstan-var array<int, World>
	 */
	private array $autoSaveChunkWorlds = [];
	/**
	 * Players of the autosave in progress still to save.
	 * @var Player[]
	 * @phpstan-var list<Player>
	 */
	private array $autoSavePlayers = [];
	/** Set when the autosave interval ends while an autosave is still in progress: the next one starts after it. */
	private bool $autoSaveDue = false;

	/**
	 * @var Dimension[] world folder name => dimension
	 * @phpstan-var array<string, Dimension>
	 */
	private array $dimensionOverrides = [];
	private bool $dimensionsEnabled = false;

	public function __construct(
		private Server $server,
		private string $dataPath,
		private WorldProviderManager $providerManager
	){}

	/**
	 * Sets the dimension a world will be presented as when it is next loaded or generated, overriding the dimension of
	 * its generator. Null removes the override. Has no effect on worlds that are already loaded.
	 */
	public function setWorldDimension(string $name, ?Dimension $dimension) : void{
		if($dimension === null){
			unset($this->dimensionOverrides[$name]);
		}else{
			$this->dimensionOverrides[$name] = $dimension;
		}
	}

	/**
	 * Whether Nether and End worlds linked to Overworld worlds may be generated automatically (pocketmine.yml
	 * dimensions.enabled).
	 */
	public function areDimensionsEnabled() : bool{
		return $this->dimensionsEnabled;
	}

	public function setDimensionsEnabled(bool $enabled) : void{
		$this->dimensionsEnabled = $enabled;
	}

	/**
	 * Dimension of a world: the configured override if there is one, otherwise the dimension of its generator,
	 * otherwise the Overworld.
	 */
	private function resolveDimension(string $name, ?GeneratorManagerEntry $generator) : Dimension{
		return $this->dimensionOverrides[$name] ?? $generator?->getDimension() ?? Dimension::OVERWORLD;
	}

	/**
	 * Warns once when a world loaded as an Overworld looks like a Nether or End (its name ends with "_nether" or
	 * "_the_end", or its spawn chunk has the hell or the_end biome): a world made with a generator of no dimension (another
	 * plugin's, an old world) gets the Overworld's sky, fog and mob spawns (zombies and creepers in the End). It is not
	 * changed automatically: the dimension also sets the height limits, and a world saved with the Overworld's could lose
	 * blocks.
	 */
	private function warnIfOtherDimension(World $world) : void{
		if($world->getDimension() !== Dimension::OVERWORLD){
			return;
		}
		$name = $world->getFolderName();
		$looksLike = null;
		$reason = "";
		foreach([Dimension::END, Dimension::NETHER] as $candidate){
			if(str_ends_with($name, $candidate->getWorldNameSuffix())){
				$looksLike = $candidate;
				$reason = "its name ends with \"" . $candidate->getWorldNameSuffix() . "\"";
				break;
			}
		}
		if($looksLike === null){
			$spawn = $world->getSpawnLocation();
			$x = $spawn->getFloorX();
			$z = $spawn->getFloorZ();
			$y = max($world->getMinY(), min($world->getMaxY() - 1, $spawn->getFloorY()));
			try{
				//read from the provider: loading it into the world would fire chunk events before WorldLoadEvent
				$subChunk = $world->getProvider()->loadChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)?->getData()->getSubChunks()[$y >> Chunk::COORD_BIT_SIZE] ?? null;
			}catch(CorruptedChunkException){
				$subChunk = null;
			}
			if($subChunk !== null){
				$biome = $subChunk->getBiomeArray()->get($x & Chunk::COORD_MASK, $y & Chunk::COORD_MASK, $z & Chunk::COORD_MASK);
				$looksLike = match($biome){
					BiomeIds::THE_END => Dimension::END,
					BiomeIds::HELL => Dimension::NETHER,
					default => null
				};
				$reason = "its spawn is in the " . ($looksLike === Dimension::END ? "the_end" : "hell") . " biome";
			}
		}
		if($looksLike !== null){
			$dimension = $looksLike === Dimension::END ? "end" : "nether";
			$this->server->getLogger()->warning("World \"$name\" is loaded as an Overworld (its generator has no dimension) but looks like the " .
				($looksLike === Dimension::END ? "End" : "Nether") . ": $reason. If it is, set its dimension in pocketmine.yml " .
				"(worlds: $name: dimension: $dimension) and restart; otherwise it gets the Overworld's sky and mob spawns. " .
				"Make a backup first: the dimension also sets the world's height limits.");
		}
	}

	/**
	 * Returns the name of the world of the target dimension linked to the given world. Worlds are linked by name like
	 * on the vanilla Java server: "world" (Overworld), "world_nether" (Nether) and "world_the_end" (End).
	 */
	public static function getLinkedWorldName(string $worldName, Dimension $worldDimension, Dimension $target) : string{
		$suffix = $worldDimension->getWorldNameSuffix();
		$base = $suffix !== "" && str_ends_with($worldName, $suffix) ? substr($worldName, 0, -strlen($suffix)) : $worldName;
		return $base . $target->getWorldNameSuffix();
	}

	/**
	 * Returns the world of the target dimension linked to the given world (see getLinkedWorldName()), loading it if
	 * needed. If it doesn't exist and dimensions are enabled, it is generated with the seed of the given world.
	 * Returns null if there is no such world.
	 */
	public function getLinkedWorld(World $world, Dimension $target) : ?World{
		if($world->getDimension() === $target){
			return $world;
		}
		$name = self::getLinkedWorldName($world->getFolderName(), $world->getDimension(), $target);
		$linked = $this->getWorldByName($name);
		if($linked !== null){
			return $linked;
		}
		if($this->isWorldGenerated($name)){
			if(!$this->loadWorld($name, true)){
				return null;
			}
		}elseif($this->dimensionsEnabled){
			$generatorManager = GeneratorManager::getInstance();
			$worldData = $world->getProvider()->getWorldData();
			$generatorOptions = "";
			$generator = null;
			$linkedGeneratorName = $generatorManager->getDimensionGenerator($worldData->getGenerator(), $target);
			if($linkedGeneratorName !== null){
				$generator = $generatorManager->getGenerator($linkedGeneratorName);
				$generatorOptions = $worldData->getGeneratorOptions();
				try{
					$generator?->validateGeneratorOptions($generatorOptions);
				}catch(InvalidGeneratorOptionsException $e){
					$this->server->getLogger()->warning("Cannot use the generator \"$linkedGeneratorName\" for the " . $target->name . " world of \"" . $world->getFolderName() . "\" (" . $e->getMessage() . "), using the default one");
					$generator = null;
					$generatorOptions = "";
				}
			}
			$generator ??= $generatorManager->getGenerator($target->getDefaultGeneratorName());
			if($generator === null){
				return null;
			}
			$this->setWorldDimension($name, $this->dimensionOverrides[$name] ?? $target);
			$this->generateWorld($name, WorldCreationOptions::create()
				->setGeneratorClass($generator->getGeneratorClass())
				->setGeneratorOptions($generatorOptions)
				->setSeed($world->getSeed())
				->setDifficulty($world->getDifficulty())
				->setSpawnPosition($target === Dimension::END ? new Vector3(100, 50, 0) : new Vector3(0, 70, 0))
			);
		}else{
			return null;
		}
		$linked = $this->getWorldByName($name);
		return $linked !== null && $linked->getDimension() === $target ? $linked : null;
	}

	public function getProviderManager() : WorldProviderManager{
		return $this->providerManager;
	}

	/**
	 * @return World[]
	 * @phpstan-return array<int, World>
	 */
	public function getWorlds() : array{
		return $this->worlds;
	}

	public function getDefaultWorld() : ?World{
		return $this->defaultWorld;
	}

	/**
	 * Sets the default world to a different world
	 * This won't change the level-name property,
	 * it only affects the server on runtime
	 */
	public function setDefaultWorld(?World $world) : void{
		if($world === null || ($this->isWorldLoaded($world->getFolderName()) && $world !== $this->defaultWorld)){
			$this->defaultWorld = $world;
		}
	}

	public function isWorldLoaded(string $name) : bool{
		return $this->getWorldByName($name) instanceof World;
	}

	public function getWorld(int $worldId) : ?World{
		return $this->worlds[$worldId] ?? null;
	}

	/**
	 * NOTE: This matches worlds based on the FOLDER name, NOT the display name.
	 */
	public function getWorldByName(string $name) : ?World{
		foreach($this->worlds as $world){
			if($world->getFolderName() === $name){
				return $world;
			}
		}

		return null;
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public function unloadWorld(World $world, bool $forceUnload = false) : bool{
		if($world === $this->getDefaultWorld() && !$forceUnload){
			throw new \InvalidArgumentException("The default world cannot be unloaded while running, please switch worlds.");
		}
		if($world->isDoingTick()){
			throw new \InvalidArgumentException("Cannot unload a world during world tick");
		}

		$ev = new WorldUnloadEvent($world);
		$ev->call();

		if(!$forceUnload && $ev->isCancelled()){
			return false;
		}

		$this->server->getLogger()->info($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_level_unloading($world->getDisplayName())));
		if(count($world->getPlayers()) !== 0){
			try{
				$safeSpawn = $this->defaultWorld !== null && $this->defaultWorld !== $world ? $this->defaultWorld->getSafeSpawn() : null;
			}catch(WorldException $e){
				$safeSpawn = null;
			}
			foreach($world->getPlayers() as $player){
				if($safeSpawn === null){
					$player->disconnect("Forced default world unload");
				}else{
					$player->teleport($safeSpawn);
				}
			}
		}

		if($world === $this->defaultWorld){
			$this->defaultWorld = null;
		}
		unset($this->worlds[$world->getId()]);

		$world->onUnload();
		return true;
	}

	/**
	 * Loads a world from the data directory
	 *
	 * @param bool $autoUpgrade Converts worlds to the default format if the world's format is not writable / deprecated
	 *
	 * @throws WorldException
	 */
	public function loadWorld(string $name, bool $autoUpgrade = false) : bool{
		if(trim($name) === ""){
			throw new \InvalidArgumentException("Invalid empty world name");
		}
		if($this->isWorldLoaded($name)){
			return true;
		}elseif(!$this->isWorldGenerated($name)){
			return false;
		}

		$path = $this->getWorldPath($name);

		$providers = $this->providerManager->getMatchingProviders($path);
		if(count($providers) !== 1){
			$this->server->getLogger()->error($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_level_loadError(
				$name,
				count($providers) === 0 ?
					KnownTranslationFactory::pocketmine_level_unknownFormat() :
					KnownTranslationFactory::pocketmine_level_ambiguousFormat(implode(", ", array_keys($providers)))
			)));
			return false;
		}
		$providerClass = array_shift($providers);

		try{
			$provider = $providerClass->fromPath($path, new \PrefixedLogger($this->server->getLogger(), "World Provider: $name"));
		}catch(CorruptedWorldException $e){
			$this->server->getLogger()->error($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_level_loadError(
				$name,
				KnownTranslationFactory::pocketmine_level_corrupted($e->getMessage())
			)));
			return false;
		}catch(UnsupportedWorldFormatException $e){
			$this->server->getLogger()->error($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_level_loadError(
				$name,
				KnownTranslationFactory::pocketmine_level_unsupportedFormat($e->getMessage())
			)));
			return false;
		}

		$generatorEntry = GeneratorManager::getInstance()->getGenerator($provider->getWorldData()->getGenerator());
		if($generatorEntry === null){
			$this->server->getLogger()->error($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_level_loadError(
				$name,
				KnownTranslationFactory::pocketmine_level_unknownGenerator($provider->getWorldData()->getGenerator())
			)));
			return false;
		}
		try{
			$generatorEntry->validateGeneratorOptions($provider->getWorldData()->getGeneratorOptions());
		}catch(InvalidGeneratorOptionsException $e){
			$this->server->getLogger()->error($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_level_loadError(
				$name,
				KnownTranslationFactory::pocketmine_level_invalidGeneratorOptions(
					$provider->getWorldData()->getGeneratorOptions(),
					$provider->getWorldData()->getGenerator(),
					$e->getMessage()
				)
			)));
			return false;
		}
		if(!($provider instanceof WritableWorldProvider)){
			if(!$autoUpgrade){
				throw new UnsupportedWorldFormatException("World \"$name\" is in an unsupported format and needs to be upgraded");
			}
			$this->server->getLogger()->notice($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_level_conversion_start($name)));

			$providerClass = $this->providerManager->getDefault();
			$converter = new FormatConverter($provider, $providerClass, Path::join($this->server->getDataPath(), "backups", "worlds"), $this->server->getLogger());
			$converter->execute();
			$provider = $providerClass->fromPath($path, new \PrefixedLogger($this->server->getLogger(), "World Provider: $name"));

			$this->server->getLogger()->notice($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_level_conversion_finish($name, $converter->getBackupPath())));
		}

		$world = new World($this->server, $name, $provider, $this->server->getAsyncPool(), $this->resolveDimension($name, $generatorEntry));

		$this->worlds[$world->getId()] = $world;
		$world->setAutoSave($this->autoSave);
		if(!isset($this->dimensionOverrides[$name])){
			$this->warnIfOtherDimension($world);
		}

		(new WorldLoadEvent($world))->call();

		//a bonus chest whose spawn chunk was not ready when the server stopped
		BonusChest::onWorldLoad($world, false);

		return true;
	}

	/**
	 * Generates a new world if it does not exist
	 *
	 * @throws \InvalidArgumentException
	 */
	public function generateWorld(string $name, WorldCreationOptions $options, bool $backgroundGeneration = true) : bool{
		if(trim($name) === "" || $this->isWorldGenerated($name)){
			return false;
		}

		$providerEntry = $this->providerManager->getDefault();

		$path = $this->getWorldPath($name);
		$providerEntry->generate($path, $name, $options);

		$generatorManager = GeneratorManager::getInstance();
		$dimension = $this->resolveDimension($name, $generatorManager->getGenerator($generatorManager->getGeneratorName($options->getGeneratorClass())));
		$world = new World($this->server, $name, $providerEntry->fromPath($path, new \PrefixedLogger($this->server->getLogger(), "World Provider: $name")), $this->server->getAsyncPool(), $dimension);
		$this->worlds[$world->getId()] = $world;

		$world->setAutoSave($this->autoSave);

		(new WorldInitEvent($world))->call();

		(new WorldLoadEvent($world))->call();

		BonusChest::onWorldLoad($world, $options->hasBonusChest());

		if($backgroundGeneration){
			$this->server->getLogger()->notice($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_level_backgroundGeneration($name)));

			$spawnLocation = $world->getSpawnLocation();
			$centerX = $spawnLocation->getFloorX() >> Chunk::COORD_BIT_SIZE;
			$centerZ = $spawnLocation->getFloorZ() >> Chunk::COORD_BIT_SIZE;

			$selected = iterator_to_array((new ChunkSelector())->selectChunks(8, $centerX, $centerZ), preserve_keys: false);
			$done = 0;
			$total = count($selected);
			foreach($selected as $index){
				World::getXZ($index, $chunkX, $chunkZ);
				$world->orderChunkPopulation($chunkX, $chunkZ, null)->onCompletion(
					static function() use ($world, &$done, $total) : void{
						$oldProgress = (int) floor(($done / $total) * 100);
						$newProgress = (int) floor((++$done / $total) * 100);
						if(intdiv($oldProgress, 10) !== intdiv($newProgress, 10) || $done === $total || $done === 1){
							$world->getLogger()->info($world->getServer()->getLanguage()->translate(KnownTranslationFactory::pocketmine_level_spawnTerrainGenerationProgress(strval($done), strval($total), strval($newProgress))));
						}
					},
					static function() : void{
						//NOOP: we don't care if the world was unloaded
					});
			}
		}

		return true;
	}

	private function getWorldPath(string $name) : string{
		return Path::join($this->dataPath, $name) . "/"; //TODO: check if we still need the trailing dirsep (I'm a little scared to remove it)
	}

	public function isWorldGenerated(string $name) : bool{
		if(trim($name) === ""){
			return false;
		}
		$path = $this->getWorldPath($name);
		if(!($this->getWorldByName($name) instanceof World)){
			return count($this->providerManager->getMatchingProviders($path)) > 0;
		}

		return true;
	}

	/**
	 * Searches all worlds for the entity with the specified ID.
	 * Useful for tracking entities across multiple worlds without needing strong references.
	 */
	public function findEntity(int $entityId) : ?Entity{
		foreach($this->worlds as $world){
			assert($world->isLoaded());
			if(($entity = $world->getEntity($entityId)) instanceof Entity){
				return $entity;
			}
		}

		return null;
	}

	public function tick(int $currentTick) : void{
		foreach($this->worlds as $k => $world){
			if(!isset($this->worlds[$k])){
				// World unloaded during the tick of a world earlier in this loop, perhaps by plugin
				continue;
			}

			$worldTime = microtime(true);
			$world->doTick($currentTick);
			$tickMs = (microtime(true) - $worldTime) * 1000;
			$world->tickRateTime = $tickMs;
			if($tickMs >= Server::TARGET_SECONDS_PER_TICK * 1000){
				$world->getLogger()->debug(sprintf("Tick took too long: %gms (%g ticks)", $tickMs, round($tickMs / (Server::TARGET_SECONDS_PER_TICK * 1000), 2)));
			}
		}

		if($this->autoSave && ++$this->autoSaveTicker >= $this->autoSaveTicks){
			$this->autoSaveTicker = 0;
			if($this->autoSavePhase !== null){
				$this->autoSaveDue = true;
			}elseif($this->autoSaveTickBudget <= 0){
				$this->server->getLogger()->debug("[Auto Save] Saving worlds...");
				$start = microtime(true);
				$this->doAutoSave();
				$time = microtime(true) - $start;
				$this->server->getLogger()->debug("[Auto Save] Save completed in " . ($time >= 1 ? round($time, 3) . "s" : round($time * 1000) . "ms"));
			}else{
				$this->startAutoSave($currentTick);
			}
		}

		if($this->autoSavePhase !== null){
			if(!$this->autoSave){
				$this->stopAutoSave(); //save-off: no further saves, as before
			}else{
				$this->continueAutoSave($currentTick, $this->autoSaveTickBudget <= 0);
			}
		}
	}

	/**
	 * Starts an autosave spread over the next ticks (see {@link WorldManager::setAutoSaveTickBudget()}): first the
	 * changed chunks of every world, then the players, then the world data.
	 */
	private function startAutoSave(int $currentTick) : void{
		$this->server->getLogger()->debug("[Auto Save] Saving worlds...");
		$this->autoSavePhase = self::AUTOSAVE_PHASE_CHUNKS;
		$this->autoSaveStartTick = $currentTick;
		$this->autoSaveStartTime = microtime(true);
		$this->autoSaveWorlds = [];
		$this->autoSavePlayers = [];
		foreach($this->worlds as $id => $world){
			if($world->startAutoSave()){
				$this->autoSaveWorlds[$id] = $world;
			}
		}
		$this->autoSaveChunkWorlds = $this->autoSaveWorlds;
	}

	private function stopAutoSave() : void{
		foreach($this->autoSaveWorlds as $world){
			if($world->isLoaded()){
				$world->cancelAutoSave();
			}
		}
		$this->autoSavePhase = null;
		$this->autoSaveWorlds = [];
		$this->autoSaveChunkWorlds = [];
		$this->autoSavePlayers = [];
		$this->autoSaveDue = false;
	}

	/**
	 * Does the work of the autosave in progress for this tick, or all of the remaining work with $complete.
	 */
	private function continueAutoSave(int $currentTick, bool $complete) : void{
		if($complete){
			$deadline = INF;
		}else{
			$deadline = microtime(true) + $this->autoSaveTickBudget;
			if($this->server->getTickUsage() < self::AUTOSAVE_IDLE_MAX_TICK_USAGE){
				//the server has time to spare (the previous tick was short)
				$deadline = max($deadline, $this->server->getTickStartTime() + self::AUTOSAVE_IDLE_TIME_END);
			}
		}
		$ticksLeft = max(1, min(self::AUTOSAVE_MAX_CHUNK_TICKS, intdiv($this->autoSaveTicks, 5)) - ($currentTick - $this->autoSaveStartTick));

		if($this->autoSavePhase === self::AUTOSAVE_PHASE_CHUNKS){
			$chunksLeft = 0;
			foreach($this->autoSaveChunkWorlds as $world){
				$chunksLeft += $world->isLoaded() ? $world->getAutoSaveChunksLeft() : 0;
			}
			//enough chunks per tick to finish in time, even if the budget is too small
			$minChunks = (int) ceil($chunksLeft / $ticksLeft);
			foreach($this->autoSaveChunkWorlds as $id => $world){
				if($world->isLoaded() && !$world->continueAutoSave($deadline, $minChunks, $processed)){
					return;
				}
				$minChunks = max(0, $minChunks - ($processed ?? 0));
				unset($this->autoSaveChunkWorlds[$id]);
			}

			$this->autoSavePhase = self::AUTOSAVE_PHASE_PLAYERS;
			foreach($this->worlds as $world){
				foreach($world->getPlayers() as $player){
					$this->autoSavePlayers[] = $player;
				}
			}
		}

		if($this->autoSavePhase === self::AUTOSAVE_PHASE_PLAYERS){
			while(($player = array_shift($this->autoSavePlayers)) !== null){
				//players who left meanwhile were saved when they quit
				if($player->spawned && $player->isConnected()){
					$player->save();
				}
				//at least one player per tick
				if(microtime(true) >= $deadline && count($this->autoSavePlayers) > 0){
					return;
				}
			}

			foreach($this->autoSaveWorlds as $world){
				if($world->isLoaded()){
					$world->finishAutoSave();
				}
			}
			$this->autoSavePhase = null;
			$this->autoSaveWorlds = [];
			$this->autoSaveChunkWorlds = [];
			$time = microtime(true) - $this->autoSaveStartTime;
			$this->server->getLogger()->debug("[Auto Save] Save completed in " . ($time >= 1 ? round($time, 3) . "s" : round($time * 1000) . "ms") . " (" . ($currentTick - $this->autoSaveStartTick + 1) . " ticks)");

			if($this->autoSaveDue){
				$this->autoSaveDue = false;
				$this->startAutoSave($currentTick);
			}
		}
	}

	/**
	 * Returns the time per tick (seconds) an autosave may spend saving chunks and player data.
	 */
	public function getAutoSaveTickBudget() : float{
		return $this->autoSaveTickBudget;
	}

	/**
	 * Sets the time per tick (seconds) an autosave may spend saving chunks and player data. The autosave is spread
	 * over as many ticks as needed (more is done per tick if it would otherwise take longer than a minute, or a fifth
	 * of the autosave interval), so that it does not stop the server for seconds. Chunks that did not change since
	 * they were last saved are skipped. 0 saves everything in one tick, as before.
	 *
	 * Stopping the server, unloading a world and {@link World::save()} (/save-all) always save everything at once.
	 */
	public function setAutoSaveTickBudget(float $seconds) : void{
		if($seconds < 0){
			throw new \InvalidArgumentException("Autosave tick budget must not be negative");
		}
		$this->autoSaveTickBudget = $seconds;
	}

	/**
	 * Returns whether an autosave spread over several ticks is in progress.
	 */
	public function isAutoSaveInProgress() : bool{
		return $this->autoSavePhase !== null;
	}

	public function getAutoSave() : bool{
		return $this->autoSave;
	}

	public function setAutoSave(bool $value) : void{
		$this->autoSave = $value;
		foreach($this->worlds as $world){
			$world->setAutoSave($this->autoSave);
		}
	}

	/**
	 * Returns the period in ticks after which loaded worlds will be automatically saved to disk.
	 */
	public function getAutoSaveInterval() : int{
		return $this->autoSaveTicks;
	}

	public function setAutoSaveInterval(int $autoSaveTicks) : void{
		if($autoSaveTicks <= 0){
			throw new \InvalidArgumentException("Autosave ticks must be positive");
		}
		$this->autoSaveTicks = $autoSaveTicks;
	}

	private function doAutoSave() : void{
		foreach($this->worlds as $world){
			foreach($world->getPlayers() as $player){
				if($player->spawned){
					$player->save();
				}
			}
			$world->save(false);
		}
	}
}
