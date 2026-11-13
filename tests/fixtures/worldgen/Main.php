<?php

declare(strict_types=1);

namespace RyzeTests\Worldgen;

use pocketmine\block\tile\Chest;
use pocketmine\block\tile\MonsterSpawner;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\dimension\generator\VanillaGenerators;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\leveldb\LevelDB;
use pocketmine\world\generator\GeneratorManager;
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

	protected function onEnable() : void{
		$cache = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		$profile = getenv("RYZE_WORLDGEN_TEST_PROFILE");
		if($cache === false || !in_array($profile, ["core-v1", "core-v4"], true)){
			throw new \RuntimeException("Worldgen fixture requires a data cache and a test profile");
		}
		$preset = "$profile;data=$cache";
		if($profile !== "core-v1"){
			$preset .= ";sha256=" . VanillaDataIdentity::fingerprint($cache);
		}
		$server = $this->getServer();
		$snapshotPath = $server->getDataPath() . "worldgen-snapshot.json";
		$reopen = is_file($snapshotPath);
		if(!$reopen){
			foreach(self::WORLDS as $name => [$generator]){
				$entry = GeneratorManager::getInstance()->getGenerator($generator) ?? throw new \RuntimeException("Missing generator $generator");
				$entry->validateGeneratorOptions($preset);
				LevelDB::generate($server->getDataPath() . "worlds/$name", $name, WorldCreationOptions::create()
					->setGeneratorClass($entry->getGeneratorClass())
					->setGeneratorOptions($preset)
					->setSeed(self::SEED));
			}
		}
		$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($profile, $preset, $snapshotPath, $reopen) : void{
			$this->exerciseWorlds($profile, $preset, $snapshotPath, $reopen);
		}), 1);
	}

	private function exerciseWorlds(string $profile, string $preset, string $snapshotPath, bool $reopen) : void{
		$server = $this->getServer();
		$remaining = count(self::WORLDS);
		$record = [];
		$expected = $reopen ? json_decode(file_get_contents($snapshotPath), true, flags: JSON_THROW_ON_ERROR) : [];
		$start = hrtime(true);
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
			}elseif($stored !== null){
				throw new \RuntimeException("First boot loaded a pre-existing chunk in $name");
			}

			// On restart populate a fresh chunk beyond the saved generation region.
			$world->requestChunkPopulation($reopen ? $x + 4 : $x, $z, null)->onCompletion(
				function(Chunk $chunk) use ($world, $name, $profile, $snapshotPath, $reopen, $start, &$remaining, &$record) : void{
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
					if(--$remaining === 0){
						if(!$reopen){
							file_put_contents($snapshotPath, json_encode($record, JSON_THROW_ON_ERROR));
						}
						$elapsed = (hrtime(true) - $start) / 1_000_000;
						$this->getLogger()->info(sprintf(
							"PASS: %s %s all three dimensions in %.0f ms, average server TPS %.2f",
							$profile,
							$reopen ? "reopened and extended" : "generated and saved",
							$elapsed,
							$this->getServer()->getTicksPerSecondAverage()
						));
						$this->getServer()->shutdown();
					}
				},
				static function() use ($name) : void{
					throw new \RuntimeException("Worldgen population was rejected for $name");
				}
			);
		}
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
