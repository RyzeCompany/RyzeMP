<?php

declare(strict_types=1);

namespace RyzeTests\Worldgen;

use pocketmine\block\BrushableBlock;
use pocketmine\block\tile\BrushableBlock as BrushableBlockTile;
use pocketmine\block\tile\Chest;
use pocketmine\block\tile\EndGateway;
use pocketmine\block\tile\ItemFrame;
use pocketmine\block\tile\MonsterSpawner;
use pocketmine\block\tile\TrialSpawner;
use pocketmine\block\tile\Vault;
use pocketmine\block\utils\VaultState;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Vault as VaultBlock;
use pocketmine\block\vault\VaultLogic;
use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\entity\Location;
use pocketmine\entity\object\EndCrystal;
use pocketmine\entity\object\ItemEntity;
use pocketmine\item\Brush;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\item\ItemTypeIds;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\serializer\ChunkSerializer;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\dimension\generator\VanillaGenerators;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\leveldb\LevelDB;
use pocketmine\world\dimension\portal\block\EndGateway as EndGatewayBlock;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\generator\vanilla\RyzeOverworldGenerator;
use pocketmine\world\generator\vanilla\VanillaDataIdentity;
use pocketmine\world\World;
use pocketmine\world\WorldCreationOptions;

final class Main extends PluginBase{
	private const SEED = 12345;
	private const WORLDS = [
		"world" => [VanillaGenerators::OVERWORLD_EXPERIMENTAL, DimensionIds::OVERWORLD, 0, 3],
		"world_nether" => [VanillaGenerators::NETHER_CORE, DimensionIds::NETHER, -1, 0],
		"world_the_end" => [VanillaGenerators::END_CORE, DimensionIds::THE_END, 72, 0]
	];
	private const STRUCTURE_CHUNKS = [
		"world" => [10, 55],
		"world_nether" => [7, 42],
		"world_the_end" => [62, 24]
	];
	private const END_GATEWAY = [-3793, 69, -407];
	private const END_SHIP_FRAME = [-164, 142, 1284];
	private const VAULT_REWARD_UUID = "90f3ea7f-d374-4f16-a350-e085218df5fd";

	protected function onEnable() : void{
		$cache = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		$profile = getenv("RYZE_WORLDGEN_TEST_PROFILE");
		if($cache === false || !in_array($profile, ["core-v1", "core-v4", "core-v5", "core-v6"], true)){
			throw new \RuntimeException("Worldgen fixture requires a data cache and a test profile");
		}
		$cacheFailure = getenv("RYZE_WORLDGEN_TEST_CACHE_FAILURE") === "1";
		$benchmarkSeed = getenv("RYZE_WORLDGEN_BENCHMARK_SEED");
		$seed = $benchmarkSeed === false ? self::SEED : (int) $benchmarkSeed;
		$preset = "$profile;data=$cache";
		if($profile !== "core-v1"){
			$preset .= ";sha256=" . VanillaDataIdentity::fingerprint($cache);
		}
		$server = $this->getServer();
		$snapshotPath = $server->getDataPath() . "worldgen-snapshot.json";
		$reopen = is_file($snapshotPath);
		if(!$reopen){
			if($profile === "core-v6"){
				$dimensions = $server->getDimensionManager()->getSettings();
				if($dimensions->getWorldgenPreset(DimensionIds::NETHER) !== $preset ||
					$dimensions->getWorldgenPreset(DimensionIds::THE_END) !== $preset){
					throw new \RuntimeException("Dimension configuration did not retain the pinned preset");
				}
			}
			foreach(self::WORLDS as $name => [$generator, $dimension]){
				if($profile === "core-v6" && $dimension !== DimensionIds::OVERWORLD){
					continue; // The server creates both configured dimensions through DimensionManager.
				}
				$entry = GeneratorManager::getInstance()->getGenerator($generator) ?? throw new \RuntimeException("Missing generator $generator");
				$entry->validateGeneratorOptions($preset);
				LevelDB::generate($server->getDataPath() . "worlds/$name", $name, WorldCreationOptions::create()
					->setGeneratorClass($entry->getGeneratorClass())
					->setGeneratorOptions($preset)
					->setSeed($seed));
			}
		}
		$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($cacheFailure, $cache, $profile, $preset, $snapshotPath, $reopen, $benchmarkSeed, $seed) : void{
			if($cacheFailure){
				$this->checkPinnedCacheFailure($cache);
			}elseif($benchmarkSeed !== false){
				$this->benchmark($seed);
			}else{
				$this->exerciseWorlds($profile, $preset, $snapshotPath, $reopen);
			}
		}), 1);
	}

	private function checkPinnedCacheFailure(string $cache) : void{
		$world = $this->getServer()->getWorldManager()->getDefaultWorld() ?? throw new \RuntimeException("Fixture world unavailable");
		$world->requestChunkPopulation(0, 3, null)->onCompletion(function(Chunk $chunk) use ($world, $cache) : void{
			$world->save(true);
			$before = self::snapshot($world, $chunk, "core-v1");
			$stamp = $cache . "/version.json";
			$original = file_get_contents($stamp);
			if($original === false || file_put_contents($stamp, $original . "\n") === false){
				throw new \RuntimeException("Could not change the benchmark's private copy of version.json");
			}

			$pending = 3;
			$onRejected = function() use ($world, $stamp, $original, $before, &$pending) : void{
				if(--$pending !== 0){
					return;
				}
				if(file_put_contents($stamp, $original) === false || !$world->isChunkPopulationBlocked()){
					throw new \RuntimeException("Worldgen cache rejection did not block the affected world");
				}
				if($world->getChunk(80, 80)?->isPopulated() || $world->getChunk(81, 80)?->isPopulated() ||
					$world->getChunk(110, 110)?->isPopulated() || $world->isChunkLocked(80, 80)){
					throw new \RuntimeException("A rejected chunk was published or left locked");
				}
				$world->requestChunkPopulation(0, 3, null)->onCompletion(function(Chunk $stored) use ($world, $before) : void{
					if(self::snapshot($world, $stored, "core-v1") !== $before){
						throw new \RuntimeException("A stored chunk changed after cache rejection");
					}
					$world->requestChunkPopulation(140, 140, null)->onCompletion(self::unexpectedPopulation(), function() use ($world) : void{
						$world->save(true);
						$this->getLogger()->info("PASS: cache mismatch rejected new chunks and preserved stored terrain");
						$this->getServer()->shutdown();
					});
				}, self::populationFailure("existing chunk after cache mismatch"));
			};
			foreach([[80, 80], [81, 80], [110, 110]] as [$x, $z]){
				$world->requestChunkPopulation($x, $z, null)->onCompletion(self::unexpectedPopulation(), $onRejected);
			}
		}, self::populationFailure("cache failure fixture setup"));
	}

	/** @return \Closure(): void */
	private static function unexpectedPopulation() : \Closure{
		return static function() : void{
			throw new \RuntimeException("A new chunk populated after a pinned cache mismatch");
		};
	}

	private function benchmark(int $seed) : void{
		$server = $this->getServer();
		$regionWidth = 4;
		$remaining = count(self::WORLDS) * $regionWidth ** 2;
		$latency = [];
		$biomes = [];
		$worlds = [];
		$tps = [];
		$peakQueue = 0;
		$sample = function() use ($server, &$tps, &$peakQueue) : void{
			$tps[] = $server->getTicksPerSecondAverage();
			$peakQueue = max($peakQueue, array_sum($server->getAsyncPool()->getTaskQueueSizes()));
		};
		$metrics = $this->getScheduler()->scheduleRepeatingTask(new ClosureTask($sample), 4);
		$cpuSeconds = static function() : float{
			$usage = getrusage();
			return $usage["ru_utime.tv_sec"] + $usage["ru_stime.tv_sec"] +
				($usage["ru_utime.tv_usec"] + $usage["ru_stime.tv_usec"]) / 1_000_000;
		};
		$cpuStart = $cpuSeconds();
		$start = hrtime(true);
		foreach(self::WORLDS as $name => $_){
			$world = $server->getWorldManager()->getWorldByName($name) ?? throw new \RuntimeException("Benchmark world $name is unavailable");
			$worlds[$name] = $world;
			[$originX, $originZ] = $seed === self::SEED ? self::STRUCTURE_CHUNKS[$name] : [-33, -17];
			for($dx = 0; $dx < $regionWidth; ++$dx){
				for($dz = 0; $dz < $regionWidth; ++$dz){
					$x = $originX + $dx;
					$z = $originZ + $dz;
					if($world->loadChunk($x, $z)?->isPopulated()){
						throw new \RuntimeException("Expected a cold benchmark chunk in $name at $x,$z");
					}
					$world->requestChunkPopulation($x, $z, null)->onCompletion(
						function(Chunk $chunk) use ($name, $seed, $start, $cpuStart, $cpuSeconds, $sample, $metrics, &$worlds, &$remaining, &$latency, &$biomes, &$tps, &$peakQueue) : void{
							if(!$chunk->isPopulated()){
								throw new \RuntimeException("Unpopulated benchmark result in $name");
							}
							$latency[$name][] = (hrtime(true) - $start) / 1_000_000;
							foreach($chunk->getSubChunks() as $section){
								foreach($section->getBiomeArray()->getPalette() as $biome){
									$biomes[$name][$biome] = true;
								}
							}
							if(--$remaining !== 0){
								return;
							}
							$sample();
							$metrics->cancel();
							$elapsed = (hrtime(true) - $start) / 1_000_000;
							$cpu = $cpuSeconds() - $cpuStart;
							$results = [];
							foreach($worlds as $worldName => $loadedWorld){
								$times = $latency[$worldName];
								sort($times);
								$n = count($times);
								$results[$worldName] = [
									"chunks" => $n,
									"p50_ms" => round($times[(int) ceil($n * 0.50) - 1], 1),
									"p95_ms" => round($times[(int) ceil($n * 0.95) - 1], 1),
									"biome_ids" => array_keys($biomes[$worldName])
								];
								$loadedWorld->save(true);
							}
							$this->getLogger()->info("BENCHMARK: " . json_encode([
								"seed" => $seed,
								"dimensions" => $results,
								"elapsed_ms" => round($elapsed, 1),
								"cpu_s" => round($cpu, 2),
								"peak_rss_kib" => getrusage()["ru_maxrss"],
								"mean_tps" => round(array_sum($tps) / count($tps), 2),
								"min_sampled_tps" => min($tps),
								"peak_worker_tasks" => $peakQueue
							], JSON_THROW_ON_ERROR));
							$this->getServer()->shutdown();
						},
						self::populationFailure("benchmark $name $x,$z")
					);
				}
			}
		}
		$sample();
	}

	private function exerciseWorlds(string $profile, string $preset, string $snapshotPath, bool $reopen) : void{
		$server = $this->getServer();
		$remaining = count(self::WORLDS) + (VanillaDataIdentity::hasStructures($preset) ? count(self::STRUCTURE_CHUNKS) : 0)
			+ (VanillaDataIdentity::hasStructureContent($preset) ? 5 : 0);
		$record = [];
		$expected = $reopen ? json_decode(file_get_contents($snapshotPath), true, flags: JSON_THROW_ON_ERROR) : [];
		$start = hrtime(true);
		$complete = function() use ($profile, $reopen, $snapshotPath, $start, &$record, &$remaining) : void{
			if(--$remaining === 0){
				if(!$reopen){
					file_put_contents($snapshotPath, json_encode($record, JSON_THROW_ON_ERROR));
				}
				$elapsed = (hrtime(true) - $start) / 1_000_000;
				$this->getLogger()->info(sprintf(
					"PASS: %s %s all three dimensions in %.0f ms, average server TPS %.2f",
					$profile, $reopen ? "reopened and extended" : "generated and saved", $elapsed,
					$this->getServer()->getTicksPerSecondAverage()
				));
				$this->getServer()->shutdown();
			}
		};
		foreach(self::WORLDS as $name => [$generator, $dimension, $x, $z]){
			$world = $server->getWorldManager()->getWorldByName($name) ?? throw new \RuntimeException("Missing world $name");
			$data = $world->getProvider()->getWorldData();
			if($data->getGenerator() !== $generator || $data->getGeneratorOptions() !== $preset || $world->getSeed() !== self::SEED ||
				$server->getDimensionManager()->getDimension($world) !== $dimension){
				throw new \RuntimeException("Worldgen metadata differs for $name");
			}
			$stored = $world->loadChunk($x, $z);
			if($reopen){
				if($stored === null || !$stored->isPopulated() || self::snapshot($world, $stored, $profile) !== $expected[$name]){
					throw new \RuntimeException("Stored worldgen chunk differs after restart for $name");
				}
				if($name === "world" && $profile !== "core-v1"){
					self::checkDungeonLoot($world);
				}
			}elseif($stored !== null){
				throw new \RuntimeException("First boot loaded a pre-existing chunk in $name");
			}

			// On restart populate a fresh chunk beyond the saved generation region.
			$world->requestChunkPopulation($reopen ? $x + 4 : $x, $z, null)->onCompletion(
				function(Chunk $chunk) use ($world, $name, $profile, $reopen, $complete, &$record) : void{
					if(!$chunk->isPopulated()){
						throw new \RuntimeException("Worldgen did not populate a chunk in $name");
					}
					if(!$reopen){
						if($name === "world" && $profile !== "core-v1"){
							$chest = $world->getTileAt(8, -39, 48);
							if(!$chest instanceof Chest){
								throw new \RuntimeException("Generated dungeon chest was not attached on the main thread");
							}
							// Test explicit inventory persistence alongside the generated loot seed.
							$chest->getRealInventory()->setItem(0, VanillaItems::DIAMOND()->setCount(3));
						}
						$record[$name] = self::snapshot($world, $chunk, $profile);
					}
					$world->save(true);
					$complete();
				},
				static function() use ($name) : void{
					throw new \RuntimeException("Worldgen population was rejected for $name");
				}
			);
			if(VanillaDataIdentity::hasStructures($preset)){
				[$structureX, $structureZ] = self::STRUCTURE_CHUNKS[$name];
				$key = "$name-structure";
				if($reopen){
					$structure = $world->loadChunk($structureX, $structureZ);
					if($structure === null || self::checkStructure($world, $structure) !== $expected[$key]){
						throw new \RuntimeException("Stored structure differs in $name after restart");
					}
					$complete();
				}else{
					$world->requestChunkPopulation($structureX, $structureZ, null)->onCompletion(
						static function(Chunk $chunk) use ($world, $key, $complete, &$record) : void{
							$record[$key] = self::checkStructure($world, $chunk);
							$world->save(true);
							$complete();
						},
						static function() use ($name) : void{
							throw new \RuntimeException("Structure population was rejected for $name");
						}
					);
				}
			}
		}
		if(VanillaDataIdentity::hasStructureContent($preset)){
			$end = $server->getWorldManager()->getWorldByName("world_the_end") ?? throw new \RuntimeException("End is unavailable");
			$overworld = $server->getWorldManager()->getWorldByName("world") ?? throw new \RuntimeException("Overworld is unavailable");
			$this->checkEndCrystal($end, $reopen, $complete);
			$this->checkEndGateway($end, $reopen, $complete);
			$this->checkEndShip($end, $reopen, $complete);
			$this->checkTrialChamber($overworld, $reopen, $complete);
			$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($overworld, $preset, $snapshotPath, $reopen, $complete) : void{
				$this->checkArchaeology($overworld, $preset, $snapshotPath, $reopen, $complete);
			}), 1);
		}
	}

	private function checkEndCrystal(World $world, bool $reopen, \Closure $complete) : void{
		$check = static function() use ($world, $complete) : void{
			$crystals = array_filter(
				$world->getChunkEntities(2, 0),
				static fn($entity) => $entity instanceof EndCrystal && $entity->getPosition()->distanceSquared(new Vector3(42.5, 98, 0.5)) < 0.01
			);
			if(count($crystals) !== 1 || $world->getBlockAt(42, 97, 0)->getTypeId() !== VanillaBlocks::BEDROCK()->getTypeId()){
				throw new \RuntimeException("The End spike crystal is missing or duplicated");
			}
			$world->save(true);
			$complete();
		};
		if($reopen){
			$world->loadChunk(2, 0) ?? throw new \RuntimeException("The End spike chunk did not reopen");
			$check();
		}else{
			$world->requestChunkPopulation(2, 0, null)->onCompletion($check, self::populationFailure("End spike"));
		}
	}

	private function checkEndGateway(World $world, bool $reopen, \Closure $complete) : void{
		[$x, $y, $z] = self::END_GATEWAY;
		$check = function(Chunk $chunk) use ($world, $x, $y, $z, $reopen, $complete) : void{
			$tile = $world->getTileAt($x, $y, $z);
			$gateway = $world->getBlockAt($x, $y, $z);
			if(!$gateway instanceof EndGatewayBlock || !$tile instanceof EndGateway ||
				!$tile->isExact() || !$tile->getExit()?->equals(new Vector3(100, 50, 0))){
				throw new \RuntimeException("Generated End gateway did not retain its exit at $x,$y,$z");
			}
			foreach([589, 2193, 2225] as $protocol){
				$converter = TypeConverter::getInstance($protocol);
				$serialized = ChunkSerializer::serializeFullChunk($chunk, DimensionIds::THE_END, $converter);
				$state = $converter->getBlockTranslator()->internalIdToNetworkStateData($gateway->getStateId());
				$elytra = StringToItemParser::getInstance()->parse("elytra");
				if($serialized === "" || $state->getName() !== "minecraft:end_gateway" || $elytra === null ||
					$converter->getItemTranslator()->toNetworkIdQuiet($elytra) === null){
					throw new \RuntimeException("End gateway or Elytra could not serialize for Bedrock $protocol");
				}
			}
			if(!$reopen){
				$world->save(true);
				$complete();
				return;
			}
			// A real server entity exercises the gateway's asynchronous destination path.
			$world->requestChunkPopulation(6, 0, null)->onCompletion(function() use ($world, $gateway, $x, $y, $z, $complete) : void{
				$entity = new ItemEntity(new Location($x + 0.5, $y, $z + 0.5, $world, 0, 0), VanillaItems::DIAMOND());
				$gateway->onEntityInside($entity);
				if(!$entity->getPosition()->equals(new Vector3(100.5, 50, 0.5))){
					throw new \RuntimeException("End gateway did not teleport the entity to its stored exit");
				}
				$entity->close();
				$complete();
			}, self::populationFailure("End gateway exit"));
		};
		if($reopen){
			$check($world->loadChunk($x >> 4, $z >> 4) ?? throw new \RuntimeException("The End gateway chunk did not reopen"));
		}else{
			$world->requestChunkPopulation($x >> 4, $z >> 4, null)->onCompletion($check, self::populationFailure("End gateway"));
		}
	}

	private function checkEndShip(World $world, bool $reopen, \Closure $complete) : void{
		[$x, $y, $z] = self::END_SHIP_FRAME;
		$check = static function() use ($world, $x, $y, $z, $complete) : void{
			$frame = $world->getTileAt($x, $y, $z);
			if(!$frame instanceof ItemFrame || $frame->getItem()->getTypeId() !== ItemTypeIds::ELYTRA){
				throw new \RuntimeException("Generated End Ship did not retain Elytra in its item frame");
			}
			$world->save(true);
			$complete();
		};
		if($reopen){
			$world->loadChunk($x >> 4, $z >> 4) ?? throw new \RuntimeException("The End Ship chunk did not reopen");
			$check();
		}else{
			$world->requestChunkPopulation($x >> 4, $z >> 4, null)->onCompletion($check, self::populationFailure("End Ship"));
		}
	}

	private function checkArchaeology(World $world, string $preset, string $snapshotPath, bool $reopen, \Closure $complete) : void{
		$recordPath = $snapshotPath . ".archaeology";
		$brush = StringToItemParser::getInstance()->parse("brush");
		if(!$brush instanceof Brush || !$this->getServer()->getCraftingManager()->matchRecipeByOutputs([$brush])->valid()){
			throw new \RuntimeException("Brush or its crafting recipe is not available");
		}
		if($reopen){
			[$x, $y, $z, $itemHash] = json_decode(file_get_contents($recordPath), true, flags: JSON_THROW_ON_ERROR);
			$world->loadChunk($x >> 4, $z >> 4) ?? throw new \RuntimeException("The archaeology chunk did not reopen");
			$tile = $world->getTileAt($x, $y, $z);
			if(!$tile instanceof BrushableBlockTile || !($tile->getBlock() instanceof BrushableBlock) ||
				$tile->getLootTable() !== null || $tile->getItem() === null ||
				hash("sha256", $tile->getItem()->nbtSerialize()->toString()) !== $itemHash){
				throw new \RuntimeException("Archaeology block or its revealed item changed after restart");
			}
			$complete();
			return;
		}

		$manager = (new RyzeOverworldGenerator(self::SEED, $preset))->getStructureManager();
		$found = $manager?->locate("minecraft:desert_pyramid", 0, 0, radius: 24);
		if($found === null || $manager === null){
			throw new \RuntimeException("Seed " . self::SEED . " has no nearby desert pyramid");
		}
		$start = $manager->getStart("minecraft:desert_pyramids", $found[1], $found[2]);
		if($start === null){
			throw new \RuntimeException("Desert pyramid has no structure start");
		}
		$box = $start->getPiecesBoundingBox();
		$this->getLogger()->info("Testing desert pyramid archaeology at chunk " . $found[1] . "," . $found[2]);
		$requests = [];
		for($x = $box->minX >> 4; $x <= $box->maxX >> 4; ++$x){
			for($z = $box->minZ >> 4; $z <= $box->maxZ >> 4; ++$z){
				$requests[] = [$x, $z];
			}
		}
		$inspect = static function(Chunk $chunk) use ($world, $recordPath, $brush) : bool{
			foreach($chunk->getTiles() as $tile){
				if(!$tile instanceof BrushableBlockTile || $tile->getLootTable() !== "loot_tables/entities/desert_pyramid_brushable_block.json" ||
					!(($block = $tile->getBlock()) instanceof BrushableBlock)){
					continue;
				}
				$pos = $tile->getPosition();
				if(!$block->onInteract($brush, Facing::UP, Vector3::zero())){
					throw new \RuntimeException("Generated suspicious sand cannot be brushed");
				}
				$item = $tile->getItem();
				if($item === null){
					continue; // Some Java loot items lack a runtime representation.
				}
				if($tile->getLootTable() !== null || $tile->getBrushCount() !== 1){
					throw new \RuntimeException("The generated loot seed was not consumed exactly once");
				}
				file_put_contents($recordPath, json_encode([
					$pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(),
					hash("sha256", $item->nbtSerialize()->toString())
				], JSON_THROW_ON_ERROR));
				$world->save(true);
				return true;
			}
			return false;
		};
		$this->inspectArchaeologyChunks($world, $requests, 0, $inspect, $complete);
	}

	/**
	 * @param list<array{int, int}> $requests
	 * @param \Closure(Chunk): bool $inspect
	 * @param \Closure(): void $complete
	 */
	private function inspectArchaeologyChunks(World $world, array $requests, int $index, \Closure $inspect, \Closure $complete) : void{
		[$x, $z] = $requests[$index] ?? throw new \RuntimeException("Desert pyramid archaeology produced no supported loot");
		$this->getLogger()->info("Checking archaeology in chunk $x,$z");
		$world->requestChunkPopulation($x, $z, null)->onCompletion(
			function(Chunk $chunk) use ($inspect, $complete, $world, $requests, $index) : void{
				if($inspect($chunk)){
					$complete();
				}else{
					$this->inspectArchaeologyChunks($world, $requests, $index + 1, $inspect, $complete);
				}
			},
			self::populationFailure("desert pyramid $x,$z")
		);
	}

	private function checkTrialChamber(World $world, bool $reopen, \Closure $complete) : void{
		$check = static function(Chunk $chunk) use ($world, $reopen, $complete) : void{
			$spawner = $world->getTileAt(4, -11, 211);
			$vault = $world->getTileAt(4, -10, 215);
			$normal = $world->getTileAt(6, -27, 211);
			$vaultBlock = $world->getBlockAt(4, -10, 215);
			$normalBlock = $world->getBlockAt(6, -27, 211);
			if(!$spawner instanceof TrialSpawner || !$vault instanceof Vault || !$normal instanceof Vault ||
				!$vaultBlock instanceof VaultBlock || !$vaultBlock->isOminous() ||
				!$normalBlock instanceof VaultBlock || $normalBlock->isOminous()){
				throw new \RuntimeException("Trial Chamber block or tile is missing");
			}
			$spawnTag = $spawner->saveNBT();
			$config = $vault->getConfig();
			if($spawnTag->getString(TrialSpawner::TAG_NORMAL_CONFIG) !== "minecraft:trial_chamber/breeze/normal" ||
				$spawnTag->getString(TrialSpawner::TAG_OMINOUS_CONFIG) !== "minecraft:trial_chamber/breeze/ominous" ||
				$config->getKeyItem()->getTypeId() !== ItemTypeIds::OMINOUS_TRIAL_KEY ||
				$normal->getConfig()->getKeyItem()->getTypeId() !== ItemTypeIds::TRIAL_KEY ||
				$config->getLootTable() !== "loot_tables/chests/trial_chambers/reward_ominous.json"){
				throw new \RuntimeException("Trial Chamber block entity configuration differs from its template");
			}
			self::checkTrialChunkProtocols($world, $chunk, $vault, $normal, $spawner);

			// Exercise the real loot table and the vault state machine without a game client.
			$host = $vault->getHost();
			$host->setBlock($vaultBlock);
			$taken = VaultLogic::tryInsertKey(VaultState::ACTIVE, $config, $vault->getData(), $host, self::VAULT_REWARD_UUID, -1, $config->getKeyItem());
			if($reopen){
				if($taken !== 0 || $vaultBlock->getVaultState() !== VaultState::UNLOCKING ||
					!$vault->getData()->hasRewardedPlayer(self::VAULT_REWARD_UUID) || $vault->getData()->getItemsToEject() === []){
					throw new \RuntimeException("Vault reward or queued ejection changed after restart");
				}
			}elseif($taken !== 1 || $vault->getData()->getItemsToEject() === []){
				throw new \RuntimeException("Ominous vault failed to roll rewards");
			}else{
				$world->setBlock($vault->getPosition(), $vaultBlock, false);
			}
			$world->save(true);
			$complete();
		};
		if($reopen){
			$check($world->loadChunk(0, 13) ?? throw new \RuntimeException("The Trial Chamber chunk did not reopen"));
		}else{
			$world->requestChunkPopulation(0, 13, null)->onCompletion($check, self::populationFailure("Trial Chamber"));
		}
	}

	private static function checkTrialChunkProtocols(World $world, Chunk $chunk, Vault $vault, Vault $normal, TrialSpawner $spawner) : void{
		$states = [
			[BlockTypeNames::VAULT, $vault, ProtocolInfo::PROTOCOL_1_21_0],
			[BlockTypeNames::VAULT, $normal, ProtocolInfo::PROTOCOL_1_20_70],
			[BlockTypeNames::TRIAL_SPAWNER, $spawner, ProtocolInfo::PROTOCOL_1_20_60]
		];
		$positions = [$vault->getPosition(), $normal->getPosition(), $spawner->getPosition()];
		$nbt = new NetworkNbtSerializer();
		foreach(ProtocolInfo::ACCEPTED_PROTOCOL as $protocol){
			$converter = TypeConverter::getInstance($protocol);
			$translator = $converter->getBlockTranslator();
			$tiles = [];
			foreach($nbt->readMultiple(ChunkSerializer::serializeTiles($chunk, $converter)) as $root){
				$tag = $root->mustGetCompoundTag();
				$tiles[World::blockHash($tag->getInt("x"), $tag->getInt("y"), $tag->getInt("z"))] = $tag->getString("id");
			}
			$updates = array_filter($world->createBlockUpdatePackets($converter, $positions), static fn($packet) => $packet instanceof BlockActorDataPacket);
			$count = 0;
			foreach($states as [$name, $tile, $minimum]){
				$supported = $protocol >= $minimum;
				$state = $translator->internalIdToNetworkStateData($tile->getBlock()->getStateId());
				$tileId = $tile->saveNBT()->getString("id");
				$pos = $tile->getPosition();
				$tileHash = World::blockHash($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
				if($state->getName() !== ($supported ? $name : BlockTypeNames::INFO_UPDATE) ||
					($tiles[$tileHash] ?? null) !== ($supported ? $tileId : null) || $tile->canSpawnTo($converter) !== $supported){
					throw new \RuntimeException("$name at $pos translated as " . $state->getName() . " on Bedrock $protocol");
				}
				$count += (int) $supported;
			}
			if(count($updates) !== $count ||
				ChunkSerializer::serializeFullChunk($chunk, DimensionIds::OVERWORLD, $converter) === ""){
				throw new \RuntimeException("Trial Chamber chunk or block updates failed on Bedrock $protocol");
			}
		}
	}

	/** @return \Closure(): void */
	private static function populationFailure(string $context) : \Closure{
		return static function() use ($context) : void{
			throw new \RuntimeException("Population rejected for $context");
		};
	}

	private static function checkDungeonLoot(World $world) : void{
		$chest = $world->getTileAt(8, -39, 48);
		if(!$chest instanceof Chest){
			throw new \RuntimeException("Generated chest did not reopen");
		}
		$contents = $chest->getInventory()->getContents();
		$second = $chest->getInventory()->getContents();
		if(count($contents) < 2 || $chest->saveNBT()->getTag("LootTable") !== null || count($second) !== count($contents)){
			throw new \RuntimeException("Dungeon loot was not rolled exactly once");
		}
		foreach($contents as $slot => $item){
			if(!isset($second[$slot]) || !$item->equalsExact($second[$slot])){
				throw new \RuntimeException("Dungeon loot changed on a second access");
			}
		}
	}

	/** @return array<string, mixed> */
	private static function checkStructure(World $world, Chunk $chunk) : array{
		switch($world->getFolderName()){
			case "world":
				if($world->getBlockAt(164, 100, 882)->getTypeId() !== VanillaBlocks::BELL()->getTypeId() ||
					$world->getTileAt(164, 100, 882) === null){
					throw new \RuntimeException("The village bell and its tile did not generate");
				}
				break;
			case "world_nether":
				$spawner = $world->getTileAt(117, 75, 681);
				if(!$spawner instanceof MonsterSpawner || $spawner->getEntityTypeId() !== "minecraft:blaze"){
					throw new \RuntimeException("The Nether fortress blaze spawner did not generate");
				}
				break;
			case "world_the_end":
				$chest = $world->getTileAt(997, 102, 397);
				if(!$chest instanceof Chest || $chest->saveNBT()->getString("LootTable", "") !== "loot_tables/chests/end_city_treasure.json"){
					throw new \RuntimeException("The End City chest did not generate");
				}
				break;
		}
		return self::snapshot($world, $chunk, "core-v1");
	}

	/** @return array<string, mixed> */
	private static function snapshot(World $world, Chunk $chunk, string $profile) : array{
		// LevelDB can compact palettes, so hash semantic block and biome IDs.
		$terrain = hash_init("sha256");
		foreach($chunk->getSubChunks() as $sub){
			for($x = 0; $x < 16; ++$x){
				for($z = 0; $z < 16; ++$z){
					for($y = 0; $y < 16; ++$y){
						hash_update($terrain, pack("V*", $sub->getBlockStateId($x, $y, $z), $sub->getBiomeArray()->get($x, $y, $z)));
						foreach($sub->getBlockLayers() as $index => $layer){
							if($index > 0 && $layer->get($x, $y, $z) !== $sub->getEmptyBlockId()){
								hash_update($terrain, pack("V*", $index, $layer->get($x, $y, $z)));
							}
						}
					}
				}
			}
		}
		$result = ["terrain" => hash_final($terrain)];
		if($world->getFolderName() === "world" && $profile !== "core-v1"){
			$chest = $world->getTileAt(8, -39, 48);
			$spawner = $world->getTileAt(8, -39, 50);
			if(!$chest instanceof Chest || !$spawner instanceof MonsterSpawner || $spawner->getEntityTypeId() !== "minecraft:skeleton"){
				throw new \RuntimeException("Generated dungeon tile types differ");
			}
			$tag = $chest->saveNBT();
			$item = $chest->getRealInventory()->getItem(0);
			if($tag->getString("LootTable", "") !== "loot_tables/chests/simple_dungeon.json" ||
				!$item->equals(VanillaItems::DIAMOND()) || $item->getCount() !== 3){
				throw new \RuntimeException("Dungeon chest loot metadata or saved inventory differs");
			}
			$result["lootSeed"] = $tag->getLong("LootTableSeedLong");
			$result["spawner"] = $spawner->getEntityTypeId();
		}
		return $result;
	}
}
