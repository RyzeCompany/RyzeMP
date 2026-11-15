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

namespace pocketmine\world\redstone;

use PHPUnit\Framework\TestCase;
use pocketmine\block\Block;
use pocketmine\block\RedstoneWire;
use pocketmine\block\VanillaBlocks;
use pocketmine\crafting\CraftingManager;
use pocketmine\item\VanillaItems;
use pocketmine\lang\Language;
use pocketmine\math\Vector3;
use pocketmine\scheduler\AsyncPool;
use pocketmine\Server;
use pocketmine\ServerConfigGroup;
use pocketmine\utils\Config;
use pocketmine\world\ChunkLoader;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\ChunkData;
use pocketmine\world\format\io\LoadedChunkData;
use pocketmine\world\format\io\ScheduledBlockUpdatesWorldProvider;
use pocketmine\world\format\io\WorldData;
use pocketmine\world\format\io\WritableWorldProvider;
use pocketmine\world\World;
use pocketmine\world\WorldManager;
use function file_exists;
use function is_array;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Runs redstone circuits in a real {@link World} backed by in-memory chunks, ticking it by hand.
 */
abstract class RedstoneTestCase extends TestCase{
	/** Y of the stone floor */
	protected const FLOOR_Y = 0;

	protected World $world;
	protected int $tick = 0;
	/** Difficulty reported by the world (hostile mobs are removed in peaceful). */
	protected int $difficulty = World::DIFFICULTY_NORMAL;

	/** @var string[] */
	private array $tempFiles = [];

	/**
	 * @return mixed[]
	 */
	protected function getPocketmineYml() : array{
		return ["redstone" => ["enabled" => true, "max-wire-updates-per-tick" => 1_000_000]];
	}

	/**
	 * Chunks -R..R around the origin are loaded.
	 */
	protected function getChunkRadius() : int{
		return 2;
	}

	/**
	 * Chunks saved by the world when {@link RedstoneTestCase::keepsSavedChunks()} is on, by chunk hash.
	 * @var ChunkData[]
	 * @phpstan-var array<int, ChunkData>
	 */
	protected array $savedChunks = [];

	/**
	 * Whether the world's provider keeps the chunks it saves in memory and loads them back. Otherwise it saves nothing
	 * and loading a chunk finds nothing.
	 */
	protected function keepsSavedChunks() : bool{
		return false;
	}

	/**
	 * Whether the provider of {@link RedstoneTestCase::keepsSavedChunks()} also keeps the scheduled block updates of
	 * the chunks it saves, like LevelDB (other providers drop them).
	 */
	protected function keepsScheduledBlockUpdates() : bool{
		return true;
	}

	/**
	 * Dimension of the test world.
	 */
	protected function getDimension() : Dimension{
		return Dimension::OVERWORLD;
	}

	private ?CraftingManager $craftingManager = null;

	/**
	 * Crafting manager returned by the mocked server: empty unless a test registers recipes in it.
	 */
	protected function getCraftingManager() : CraftingManager{
		return $this->craftingManager ??= new CraftingManager();
	}

	protected function setUp() : void{
		$ymlPath = sys_get_temp_dir() . "/pm-redstone-test-" . uniqid() . ".yml";
		$propertiesPath = sys_get_temp_dir() . "/pm-redstone-test-" . uniqid() . ".properties";
		$this->tempFiles = [$ymlPath, $propertiesPath];
		$yml = $this->getPocketmineYml();
		//test worlds have no players: unless a test asks for a simulation distance, every entity is ticked
		if(!isset($yml["chunk-ticking"]) || !is_array($yml["chunk-ticking"]) || !isset($yml["chunk-ticking"]["simulation-distance"])){
			$yml["chunk-ticking"] = ["simulation-distance" => 0] + (isset($yml["chunk-ticking"]) && is_array($yml["chunk-ticking"]) ? $yml["chunk-ticking"] : []);
		}
		//no chunk is ticked without players: unless a test asks for a tick radius, block updates that wait for ticking
		//chunks (redstone, fire) run everywhere
		if(!isset($yml["chunk-ticking"]["tick-radius"])){
			$yml["chunk-ticking"]["tick-radius"] = 0;
		}
		$config = new ServerConfigGroup(
			new Config($ymlPath, Config::YAML, $yml),
			new Config($propertiesPath, Config::PROPERTIES, [])
		);

		$server = $this->createMock(Server::class);
		$server->method("getLogger")->willReturn(new NullThreadSafeLogger());
		$server->method("getLanguage")->willReturn($this->createMock(Language::class));
		$server->method("getConfigGroup")->willReturn($config);
		$server->method("getViewDistance")->willReturn(8);
		$server->method("getTick")->willReturnCallback(fn() => $this->tick);
		$server->method("getCraftingManager")->willReturnCallback(fn() => $this->getCraftingManager());
		//entity lookups by ID (e.g. the damager of an EntityDamageByEntityEvent) search the test world
		$worldManager = $this->createMock(WorldManager::class);
		$worldManager->method("findEntity")->willReturnCallback(fn(int $id) => $this->world->getEntity($id));
		$server->method("getWorldManager")->willReturn($worldManager);

		$worldData = $this->createMock(WorldData::class);
		$worldData->method("getName")->willReturn("redstone_test");
		$worldData->method("getGenerator")->willReturn("flat");
		$worldData->method("getGeneratorOptions")->willReturn("");
		$worldData->method("getSpawn")->willReturn(new Vector3(0, 1, 0));
		$worldData->method("getDifficulty")->willReturnCallback(fn() => $this->difficulty);

		$provider = $this->keepsSavedChunks() && $this->keepsScheduledBlockUpdates() ?
			$this->createMockForIntersectionOfInterfaces([WritableWorldProvider::class, ScheduledBlockUpdatesWorldProvider::class]) :
			$this->createMock(WritableWorldProvider::class);
		$provider->method("getWorldMinY")->willReturn(-64);
		$provider->method("getWorldMaxY")->willReturn(320);
		$provider->method("getWorldData")->willReturn($worldData);
		if($this->keepsSavedChunks()){
			$provider->method("saveChunk")->willReturnCallback(function(int $chunkX, int $chunkZ, ChunkData $data, int $dirtyFlags) : void{
				$this->savedChunks[World::chunkHash($chunkX, $chunkZ)] = $this->keepsScheduledBlockUpdates() ? $data :
					new ChunkData($data->getSubChunks(), $data->isPopulated(), $data->getEntityNBT(), $data->getTileNBT());
			});
			$provider->method("loadChunk")->willReturnCallback(function(int $chunkX, int $chunkZ) : ?LoadedChunkData{
				$data = $this->savedChunks[World::chunkHash($chunkX, $chunkZ)] ?? null;
				return $data !== null ? new LoadedChunkData($data, false, 0) : null;
			});
		}else{
			$provider->method("loadChunk")->willReturn(null);
		}

		$this->world = new World($server, "redstone_test", $provider, $this->createMock(AsyncPool::class), $this->getDimension());

		$loader = new class implements ChunkLoader{};
		$radius = $this->getChunkRadius();
		for($cx = -$radius; $cx <= $radius; ++$cx){
			for($cz = -$radius; $cz <= $radius; ++$cz){
				$this->world->registerChunkLoader($loader, $cx, $cz, false);
				$this->world->setChunk($cx, $cz, new Chunk([], true));
			}
		}
		$this->tick = 1;
	}

	protected function tearDown() : void{
		foreach($this->tempFiles as $file){
			if(file_exists($file)){
				unlink($file);
			}
		}
	}

	protected function redstone() : RedstoneEngine{
		$engine = $this->world->getRedstone();
		self::assertNotNull($engine);
		return $engine;
	}

	/**
	 * Fills the floor (stone at FLOOR_Y) for the given horizontal area.
	 */
	protected function floor(int $minX, int $minZ, int $maxX, int $maxZ) : void{
		$stone = VanillaBlocks::STONE();
		for($x = $minX; $x <= $maxX; ++$x){
			for($z = $minZ; $z <= $maxZ; ++$z){
				$this->world->setBlockAt($x, self::FLOOR_Y, $z, $stone, false);
			}
		}
	}

	protected function set(int $x, int $y, int $z, Block $block) : void{
		$this->world->setBlockAt($x, $y, $z, $block);
	}

	protected function get(int $x, int $y, int $z) : Block{
		return $this->world->getBlockAt($x, $y, $z);
	}

	protected function breakBlock(int $x, int $y, int $z) : void{
		$item = VanillaItems::AIR();
		$this->world->useBreakOn(new Vector3($x, $y, $z), $item, null, false);
	}

	protected function interact(int $x, int $y, int $z) : void{
		$this->get($x, $y, $z)->onInteract(VanillaItems::AIR(), 1, new Vector3(0.5, 0.5, 0.5));
	}

	/**
	 * Runs world ticks.
	 */
	protected function runTicks(int $ticks) : void{
		for($i = 0; $i < $ticks; ++$i){
			$this->world->doTick(++$this->tick);
		}
	}

	protected function wirePower(int $x, int $y, int $z) : int{
		$wire = $this->get($x, $y, $z);
		self::assertInstanceOf(RedstoneWire::class, $wire);
		return $wire->getOutputSignalStrength();
	}
}
