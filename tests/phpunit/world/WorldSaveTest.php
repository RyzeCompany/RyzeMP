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

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use pocketmine\ActiveResourceProfile;
use pocketmine\block\Button;
use pocketmine\block\VanillaBlocks;
use pocketmine\crafting\CraftingManager;
use pocketmine\entity\Location;
use pocketmine\entity\object\ItemEntity;
use pocketmine\item\VanillaItems;
use pocketmine\lang\Language;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\MemoryManager;
use pocketmine\ResourceProfile;
use pocketmine\scheduler\AsyncPool;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use pocketmine\ServerConfigGroup;
use pocketmine\utils\Config;
use pocketmine\utils\Filesystem;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\ChunkData;
use pocketmine\world\format\io\leveldb\LevelDB;
use pocketmine\world\format\io\WorldProviderManager;
use pocketmine\world\format\SubChunk;
use pocketmine\world\generator\Flat;
use pocketmine\world\light\LightPopulationTask;
use pocketmine\world\redstone\NullThreadSafeLogger;
use function bin2hex;
use function count;
use function extension_loaded;
use function file_exists;
use function gc_collect_cycles;
use function in_array;
use function intdiv;
use function is_dir;
use function microtime;
use function sort;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use const INF;

/**
 * Chunk saves of a world backed by a real LevelDB database: saves skip only chunks that would be written exactly as
 * they are stored, everything is still saved after any change, autosaves spread over ticks save every chunk, and the
 * stored world is the same as with full saves.
 */
final class WorldSaveTest extends TestCase{
	private const RADIUS = 2;

	private string $path;
	/** @var string[] */
	private array $tempFiles = [];
	private ?World $world = null;
	private ?CountingLevelDB $provider = null;
	private int $tick = 1;
	private ChunkLoader $loader;
	/** @var (Server&MockObject)|null */
	private ?Server $server = null;
	/** @var AsyncTask[] */
	private array $submittedTasks = [];
	private ResourceProfile $profile = ResourceProfile::PERFORMANCE;

	protected function setUp() : void{
		if(!extension_loaded("leveldb")){
			self::markTestSkipped("leveldb extension not loaded");
		}
		$this->path = sys_get_temp_dir() . "/pm-world-save-" . uniqid();
		LevelDB::generate($this->path, "world_save", WorldCreationOptions::create()->setGeneratorClass(Flat::class));
		$this->openWorld();
		$this->loader = new class implements ChunkLoader{};
		for($cx = -self::RADIUS; $cx <= self::RADIUS; ++$cx){
			for($cz = -self::RADIUS; $cz <= self::RADIUS; ++$cz){
				$this->world()->registerChunkLoader($this->loader, $cx, $cz, false);
				$this->world()->setChunk($cx, $cz, new Chunk([], true));
			}
		}
		$stone = VanillaBlocks::STONE();
		for($x = -16; $x < 32; ++$x){
			$this->world()->setBlockAt($x, 0, 0, $stone, false);
		}
	}

	private function openWorld() : void{
		$ymlPath = sys_get_temp_dir() . "/pm-world-save-" . uniqid() . ".yml";
		$propertiesPath = sys_get_temp_dir() . "/pm-world-save-" . uniqid() . ".properties";
		$this->tempFiles[] = $ymlPath;
		$this->tempFiles[] = $propertiesPath;
		$config = new ServerConfigGroup(
			new Config($ymlPath, Config::YAML, ["chunk-ticking" => ["simulation-distance" => 0, "tick-radius" => 0], "redstone" => ["enabled" => true]]),
			new Config($propertiesPath, Config::PROPERTIES, [])
		);
		$server = $this->createMock(Server::class);
		$server->method("getLogger")->willReturn(new NullThreadSafeLogger());
		$server->method("getLanguage")->willReturn($this->createMock(Language::class));
		$server->method("getConfigGroup")->willReturn($config);
		$server->method("getViewDistance")->willReturn(8);
		$server->method("getTick")->willReturnCallback(fn() => $this->tick);
		$server->method("getCraftingManager")->willReturn(new CraftingManager());
		ActiveResourceProfile::set($this->profile);
		$worldManager = $this->createMock(WorldManager::class);
		$worldManager->method("findEntity")->willReturnCallback(fn(int $id) => $this->world?->getEntity($id));
		$server->method("getWorldManager")->willReturn($worldManager);
		$this->server = $server;

		$this->provider = new CountingLevelDB($this->path, new NullThreadSafeLogger());
		$pool = $this->createMock(AsyncPool::class);
		$pool->method("submitTask")->willReturnCallback(function(AsyncTask $task) : int{
			$this->submittedTasks[] = $task;
			return 0;
		});
		$this->world = new World($server, "world_save", $this->provider, $pool, Dimension::OVERWORLD);
	}

	protected function tearDown() : void{
		ActiveResourceProfile::set(ResourceProfile::PERFORMANCE);
		if($this->world !== null && $this->world->isLoaded()){
			$this->world->onUnload();
		}
		$this->world = null;
		$this->provider = null;
		foreach($this->tempFiles as $file){
			if(file_exists($file)){
				unlink($file);
			}
		}
		if(isset($this->path) && is_dir($this->path)){
			Filesystem::recursiveUnlink($this->path);
		}
	}

	private function world() : World{
		self::assertNotNull($this->world);
		return $this->world;
	}

	private function provider() : CountingLevelDB{
		self::assertNotNull($this->provider);
		return $this->provider;
	}

	/**
	 * Saves the chunks and returns the chunks written (by hash).
	 *
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	private function saveChunks() : array{
		$this->provider()->saved = [];
		$this->world()->saveChunks();
		return $this->provider()->saved;
	}

	/**
	 * @return string[] key (hex) => value (hex) of every record of the database
	 * @phpstan-return array<string, string>
	 */
	private function dump() : array{
		$result = [];
		foreach($this->provider()->getDatabase()->getIterator() as $key => $value){
			$result[bin2hex((string) $key)] = bin2hex((string) $value);
		}
		return $result;
	}

	/**
	 * Saves every loaded chunk in full, as if nothing was known about what is stored.
	 */
	private function saveEverythingInFull() : void{
		(new \ReflectionProperty(World::class, "cleanSavedChunks"))->setValue($this->world(), []);
		foreach($this->world()->getLoadedChunks() as $chunk){
			$chunk->setTerrainDirty();
		}
		$this->world()->saveChunks();
	}

	public function testUnchangedChunksAreNotWrittenAgain() : void{
		self::assertCount((2 * self::RADIUS + 1) ** 2, $this->saveChunks(), "the first save writes every chunk");
		self::assertSame([], $this->saveChunks(), "nothing changed");
		$this->world()->setBlockAt(1, 1, 1, VanillaBlocks::DIRT());
		self::assertSame([World::chunkHash(0, 0)], $this->saveChunks());
		self::assertSame([], $this->saveChunks());
	}

	/**
	 * @phpstan-return \Generator<string, array{\Closure(World) : void, list<array{int, int}>}, void, void>
	 */
	public static function changeProvider() : \Generator{
		yield "block" => [fn(World $w) => $w->setBlockAt(17, 1, 1, VanillaBlocks::DIRT()), [[1, 0]]];
		yield "biome" => [fn(World $w) => $w->setBiomeId(17, 1, 1, 5), [[1, 0]]];
		yield "populated flag" => [fn(World $w) => $w->getChunk(1, 0)?->setPopulated(false), [[1, 0]]];
		yield "replaced chunk" => [fn(World $w) => $w->setChunk(1, 0, new Chunk([], true)), [[1, 0]]];
		yield "tile" => [fn(World $w) => $w->setBlockAt(17, 1, 1, VanillaBlocks::CHEST()), [[1, 0]]];
		yield "entity" => [function(World $w) : void{
			new ItemEntity(Location::fromObject(new Vector3(17.5, 1, 1.5), $w), VanillaItems::STICK());
		}, [[1, 0]]];
		yield "scheduled update" => [fn(World $w) => $w->scheduleDelayedBlockUpdate(new Vector3(17, 0, 0), 50), [[1, 0]]];
		yield "two chunks" => [function(World $w) : void{
			$w->setBlockAt(-1, 1, 1, VanillaBlocks::DIRT());
			$w->setBlockAt(33, 1, 33, VanillaBlocks::DIRT());
		}, [[-1, 0], [2, 2]]];
	}

	/**
	 * @dataProvider changeProvider
	 * @phpstan-param \Closure(World) : void $change
	 * @phpstan-param list<array{int, int}> $expected
	 */
	public function testChangedChunksAreWrittenAgain(\Closure $change, array $expected) : void{
		$this->saveChunks();
		$change($this->world());
		$expectedHashes = [];
		foreach($expected as [$x, $z]){
			$expectedHashes[] = World::chunkHash($x, $z);
		}
		$saved = $this->saveChunks();
		sort($saved);
		sort($expectedHashes);
		self::assertSame($expectedHashes, $saved);
	}

	public function testChunksWithEntitiesTilesOrUpdatesAreAlwaysWritten() : void{
		$this->world()->setBlockAt(17, 1, 1, VanillaBlocks::CHEST());
		$item = new ItemEntity(Location::fromObject(new Vector3(1.5, 1, 1.5), $this->world()), VanillaItems::STICK());
		$this->world()->scheduleDelayedBlockUpdate(new Vector3(33, 0, 0), 500);
		$this->saveChunks();
		$saved = $this->saveChunks();
		sort($saved);
		$expected = [World::chunkHash(0, 0), World::chunkHash(1, 0), World::chunkHash(2, 0)];
		sort($expected);
		self::assertSame($expected, $saved, "their data may change without the chunk knowing (tile contents, entity positions, ticks left)");

		//the entity leaves: its old chunk is written once more (without it), then skipped
		$item->teleport(new Vector3(-30, 1, 1.5));
		$saved = $this->saveChunks();
		self::assertContains(World::chunkHash(0, 0), $saved);
		self::assertContains(World::chunkHash(-2, 0), $saved);
		self::assertNotContains(World::chunkHash(0, 0), $this->saveChunks());
	}

	public function testSkippedSavesStoreTheSameAsFullSaves() : void{
		$this->world()->setBlockAt(17, 1, 1, VanillaBlocks::CHEST());
		new ItemEntity(Location::fromObject(new Vector3(1.5, 1, 1.5), $this->world()), VanillaItems::STICK());
		$this->saveChunks();
		$this->world()->setBlockAt(-20, 1, -20, VanillaBlocks::GLASS());
		$this->world()->setBiomeId(5, 1, 30, 7);
		$this->world()->getChunk(-2, 2)?->setPopulated(false);
		self::assertNotSame([], $this->saveChunks());
		self::assertCount(2, $this->saveChunks(), "only the chunks with a tile or an entity");
		$skipped = $this->dump();

		$this->saveEverythingInFull();
		self::assertSame($skipped, $this->dump(), "a full save stores exactly what the skipping saves stored");
	}

	public function testUnloadSkipsUnchangedChunksAndReloadsTheSame() : void{
		$this->world()->setBlockAt(1, 2, 1, VanillaBlocks::GLASS());
		$this->world()->setBlockAt(17, 2, 1, VanillaBlocks::GLASS());
		$this->saveChunks();
		$this->world()->setBlockAt(17, 3, 1, VanillaBlocks::DIRT());

		$this->provider()->saved = [];
		self::assertTrue($this->world()->unloadChunk(0, 0, false));
		self::assertTrue($this->world()->unloadChunk(1, 0, false));
		self::assertSame([World::chunkHash(1, 0)], $this->provider()->saved, "only the changed chunk is written");

		self::assertNotNull($this->world()->loadChunk(0, 0));
		self::assertNotNull($this->world()->loadChunk(1, 0));
		self::assertSame(VanillaBlocks::GLASS()->getStateId(), $this->world()->getBlockAt(1, 2, 1)->getStateId());
		self::assertSame(VanillaBlocks::GLASS()->getStateId(), $this->world()->getBlockAt(17, 2, 1)->getStateId());
		self::assertSame(VanillaBlocks::DIRT()->getStateId(), $this->world()->getBlockAt(17, 3, 1)->getStateId());

		//chunks loaded from the provider are written by their next save, as before
		$saved = $this->saveChunks();
		self::assertContains(World::chunkHash(0, 0), $saved);
		self::assertContains(World::chunkHash(1, 0), $saved);
	}

	public function testScheduledUpdateSurvivesUnloadAfterSkippedSave() : void{
		$this->world()->setBlockAt(1, 1, 1, VanillaBlocks::STONE_BUTTON()->setFacing(Facing::UP));
		$this->saveChunks();
		$this->world()->getBlockAt(1, 1, 1)->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0.5, 0.5, 0.5));
		self::assertTrue($this->world()->isBlockUpdateScheduled(1, 1, 1));
		self::assertTrue($this->world()->unloadChunk(0, 0, false));
		self::assertNotNull($this->world()->loadChunk(0, 0));
		self::assertTrue($this->world()->isBlockUpdateScheduled(1, 1, 1), "the pressed button's update was saved");
		$button = $this->world()->getBlockAt(1, 1, 1);
		self::assertInstanceOf(Button::class, $button);
		self::assertTrue($button->isPressed());
	}

	public function testAutoSaveSpreadOverCallsSavesEveryChangedChunk() : void{
		$this->saveChunks();
		$changed = [];
		foreach([[-2, -2], [0, 0], [1, 2], [2, -1]] as [$x, $z]){
			$this->world()->setBlockAt($x * 16 + 3, 4, $z * 16 + 3, VanillaBlocks::GOLD());
			$changed[] = World::chunkHash($x, $z);
		}
		$this->world()->setTime(12345);

		$this->provider()->saved = [];
		self::assertTrue($this->world()->startAutoSave());
		self::assertSame((2 * self::RADIUS + 1) ** 2, $this->world()->getAutoSaveChunksLeft());
		$calls = 0;
		while(!$this->world()->continueAutoSave(0, 1, $processed)){
			self::assertGreaterThanOrEqual(1, $processed);
			//changes made meanwhile are saved by this autosave if their chunk is not done yet, or by the next one
			++$calls;
		}
		self::assertGreaterThan(1, $calls, "spread over several calls");
		$saved = $this->provider()->saved;
		sort($saved);
		sort($changed);
		self::assertSame($changed, $saved);
		self::assertSame(0, $this->world()->getAutoSaveChunksLeft());

		$this->world()->finishAutoSave();
		self::assertSame(12345, $this->provider()->getWorldData()->getTime());
		self::assertSame([], $this->saveChunks(), "everything was saved");
	}

	public function testAutoSaveStopsWhenDisabled() : void{
		$this->world()->setBlockAt(3, 4, 3, VanillaBlocks::GOLD());
		self::assertTrue($this->world()->startAutoSave());
		$this->world()->setAutoSave(false);
		$this->provider()->saved = [];
		self::assertTrue($this->world()->continueAutoSave(INF, 1000));
		self::assertSame([], $this->provider()->saved);
		self::assertFalse($this->world()->startAutoSave());
	}

	/**
	 * A crash between two ticks of an autosave: the chunks saved so far are stored (whole), the others keep their
	 * previous save.
	 */
	public function testCrashDuringAutoSaveKeepsSavedChunks() : void{
		$this->saveChunks();
		for($x = -self::RADIUS; $x <= self::RADIUS; ++$x){
			for($z = -self::RADIUS; $z <= self::RADIUS; ++$z){
				$this->world()->setBlockAt($x * 16 + 3, 4, $z * 16 + 3, VanillaBlocks::GOLD());
			}
		}
		$this->provider()->saved = [];
		self::assertTrue($this->world()->startAutoSave());
		for($i = 0; $i < 3; ++$i){
			self::assertFalse($this->world()->continueAutoSave(0, 4));
		}
		$saved = $this->provider()->saved;
		self::assertCount(12, $saved);

		//crash: the process dies, nothing else is written
		$provider = $this->provider();
		$provider->simulateCrash();
		$this->world = null;
		$this->provider = null;
		gc_collect_cycles();

		$reopened = new LevelDB($this->path, new NullThreadSafeLogger());
		try{
			for($x = -self::RADIUS; $x <= self::RADIUS; ++$x){
				for($z = -self::RADIUS; $z <= self::RADIUS; ++$z){
					$data = $reopened->loadChunk($x, $z)?->getData();
					self::assertInstanceOf(ChunkData::class, $data);
					$stateId = $data->getSubChunks()[0]->getBlockStateId(3, 4, 3);
					$expected = in_array(World::chunkHash($x, $z), $saved, true) ? VanillaBlocks::GOLD()->getStateId() : VanillaBlocks::AIR()->getStateId();
					self::assertSame($expected, $stateId, "chunk $x $z");
				}
			}
		}finally{
			$reopened->close();
		}
	}

	public function testFailedBatchMakesChunksDirtyAgain() : void{
		$this->saveChunks();
		$this->world()->setBlockAt(3, 4, 3, VanillaBlocks::GOLD());
		$this->provider()->failNextCommit = true;
		try{
			$this->world()->saveChunks();
			self::fail("the commit should have failed");
		}catch(\LevelDBException){
			//expected
		}
		$chunk = $this->world()->getChunk(0, 0);
		self::assertNotNull($chunk);
		self::assertTrue($chunk->isTerrainDirty(), "written again in full by the next save");
		self::assertContains(World::chunkHash(0, 0), $this->saveChunks());
	}

	public function testUnloadChunksHasATimeBudget() : void{
		$world = $this->world();
		//many chunks queued long ago, all changed
		for($x = 10; $x < 40; ++$x){
			for($z = 0; $z < 10; ++$z){
				$chunk = new Chunk([], true);
				for($y = 0; $y < 200; ++$y){
					$chunk->setBlockStateId($y & 15, $y, ($y * 7) & 15, VanillaBlocks::DIRT()->getStateId());
				}
				$world->setChunk($x, $z, $chunk);
			}
		}
		$queue = new \ReflectionProperty(World::class, "unloadQueue");
		$old = [];
		for($x = 10; $x < 40; ++$x){
			for($z = 0; $z < 10; ++$z){
				$old[World::chunkHash($x, $z)] = 0.0;
			}
		}
		$queue->setValue($world, $old);

		$this->provider()->saved = [];
		$world->unloadChunks();
		$unloaded = count($this->provider()->saved);
		self::assertGreaterThanOrEqual(4, $unloaded, "a few chunks unload every tick whatever the time");
		self::assertLessThanOrEqual(96, $unloaded);
		$left = $queue->getValue($world);
		self::assertIsArray($left);
		self::assertCount(300 - $unloaded, $left, "the rest stays queued");

		$world->unloadChunks(true);
		self::assertSame([], $queue->getValue($world), "forced unloads ignore the budget");
		self::assertCount(300, $this->provider()->saved);
		foreach([[10, 0], [39, 9]] as [$x, $z]){
			self::assertFalse($world->isChunkLoaded($x, $z));
			self::assertNotNull($world->loadChunk($x, $z));
			self::assertSame(VanillaBlocks::DIRT()->getStateId(), $world->getBlockAt($x * 16 + 5, 5, $z * 16 + 3)->getStateId());
		}
	}

	public function testPopulatedLightKeepsNoAllZeroArrays() : void{
		$world = $this->world();
		$chunk = new Chunk([], true);
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$chunk->setBlockStateId($x, 60, $z, VanillaBlocks::STONE()->getStateId());
			}
		}
		$chunk->setBlockStateId(3, 61, 3, VanillaBlocks::TORCH()->getStateId());
		$world->setChunk(5, 5, $chunk);
		self::assertFalse($chunk->isLightPopulated());

		$this->submittedTasks = [];
		(new \ReflectionMethod(World::class, "orderLightPopulation"))->invoke($world, 5, 5);
		self::assertCount(1, $this->submittedTasks);
		$task = $this->submittedTasks[0];
		self::assertInstanceOf(LightPopulationTask::class, $task);
		$task->onRun();
		$task->onCompletion();
		self::assertTrue($chunk->isLightPopulated());

		$stored = fn(SubChunk $subChunk) : array => \Closure::bind(fn() => [$this->skyLight, $this->blockLight], $subChunk, SubChunk::class)();
		$kept = 0;
		foreach($chunk->getSubChunks() as $y => $subChunk){
			[$sky, $block] = $stored($subChunk);
			foreach([$sky, $block] as $array){
				if($array !== null){
					++$kept;
					self::assertFalse($array->isUniform(0), "subchunk $y keeps an all-zero light array");
				}
			}
		}
		self::assertGreaterThan(0, $kept);
		//the light itself is unchanged: zero where no array is kept, the torch lights its surroundings, sky above
		self::assertSame(14, $world->getBlockLightAt(83, 61, 83));
		self::assertSame(5, $world->getBlockLightAt(83, 61, 92));
		self::assertSame(0, $world->getBlockLightAt(83, 40, 83));
		self::assertSame(0, $world->getBlockLightAt(83, 20, 83));
		self::assertSame(15, $world->getPotentialBlockSkyLightAt(83, 100, 83));
		self::assertSame(0, $world->getPotentialBlockSkyLightAt(83, 20, 83));
	}

	public function testLowMemoryUnloadsUnusedChunksWithoutTheDelay() : void{
		$world = $this->world();
		for($x = 10; $x < 20; ++$x){
			$world->setChunk($x, 0, new Chunk([], true)); //queued for unload now: nobody uses them
		}
		$queue = new \ReflectionProperty(World::class, "unloadQueue");
		$left = $queue->getValue($world);
		self::assertIsArray($left);
		self::assertCount(10, $left);

		$world->unloadChunks();
		self::assertTrue($world->isChunkLoaded(10, 0), "unused chunks stay loaded for a while normally");

		$memoryManager = $this->createMock(MemoryManager::class);
		$memoryManager->method("isLowMemory")->willReturn(true);
		self::assertNotNull($this->server);
		$this->server->method("getMemoryManager")->willReturn($memoryManager);
		$world->unloadChunks();
		self::assertSame([], $queue->getValue($world), "on low memory they go at once");
		for($x = 10; $x < 20; ++$x){
			self::assertFalse($world->isChunkLoaded($x, 0));
		}
	}

	public function testUnloadDelayFollowsTheResourceProfile() : void{
		$queue = new \ReflectionProperty(World::class, "unloadQueue");
		foreach([[ResourceProfile::PERFORMANCE, true], [ResourceProfile::ECONOMY, false]] as [$profile, $stays]){
			$this->profile = $profile;
			$this->world()->onUnload();
			$this->openWorld();
			$world = $this->world();
			$world->setChunk(10, 0, new Chunk([], true)); //nobody uses it
			//unused for 15 seconds: performance keeps such chunks 30 s, economy 10 s
			$queue->setValue($world, [World::chunkHash(10, 0) => microtime(true) - 15]);
			$world->unloadChunks();
			self::assertSame($stays, $world->isChunkLoaded(10, 0), $profile->name);
		}
	}

	public function testLevelDBWriteBufferSizeIsSettable() : void{
		self::assertSame(ResourceProfile::PERFORMANCE->getLevelDBWriteBufferSize(), LevelDB::getWriteBufferSize());
		try{
			LevelDB::setWriteBufferSize(ResourceProfile::ECONOMY->getLevelDBWriteBufferSize());
			self::assertSame(4 * 1024 * 1024, LevelDB::getWriteBufferSize());
			//a world opened with the smaller memtable reads and writes the same data
			$this->world()->setBlockAt(1, 2, 3, VanillaBlocks::DIRT(), false);
			$this->world()->onUnload();
			$this->openWorld();
			self::assertNotNull($this->world()->loadChunk(0, 0));
			self::assertSame(VanillaBlocks::DIRT()->getStateId(), $this->world()->getBlockAt(1, 2, 3)->getStateId());
		}finally{
			LevelDB::setWriteBufferSize(ResourceProfile::PERFORMANCE->getLevelDBWriteBufferSize());
		}
	}

	private function newWorldManager() : WorldManager{
		self::assertNotNull($this->server);
		$manager = new WorldManager($this->server, $this->path, new WorldProviderManager());
		(new \ReflectionProperty(WorldManager::class, "worlds"))->setValue($manager, [$this->world()->getId() => $this->world()]);
		return $manager;
	}

	private function triggerAutoSave(WorldManager $manager) : void{
		(new \ReflectionProperty(WorldManager::class, "autoSaveTicker"))->setValue($manager, $manager->getAutoSaveInterval() - 1);
	}

	/**
	 * @return int[] hashes of the chunks changed
	 * @phpstan-return list<int>
	 */
	private function changeChunks(int $count) : array{
		$changed = [];
		for($i = 0; $i < $count; ++$i){
			$x = ($i % 5) - 2;
			$z = intdiv($i, 5) - 2;
			$this->world()->setBlockAt($x * 16 + 3, 4, $z * 16 + 3, VanillaBlocks::GOLD());
			$changed[] = World::chunkHash($x, $z);
		}
		sort($changed);
		return $changed;
	}

	public function testWorldManagerSpreadsAutoSave() : void{
		$manager = $this->newWorldManager();
		$manager->setAutoSaveInterval(1000);
		$manager->setAutoSaveTickBudget(0.000001);
		$this->saveChunks();
		$changed = $this->changeChunks(10);
		$this->world()->setTime(4242);
		$this->provider()->saved = [];

		$this->triggerAutoSave($manager);
		$manager->tick(++$this->tick);
		self::assertTrue($manager->isAutoSaveInProgress());
		$ticks = 1;
		while($manager->isAutoSaveInProgress() && $ticks < 1000){
			$manager->tick(++$this->tick);
			++$ticks;
		}
		self::assertFalse($manager->isAutoSaveInProgress());
		self::assertGreaterThan(2, $ticks, "spread over several ticks");
		self::assertLessThanOrEqual(1000 / 5, $ticks, "within a fifth of the interval");
		$saved = $this->provider()->saved;
		sort($saved);
		self::assertSame($changed, $saved);
		self::assertGreaterThan(4242, $this->world()->getTime());
		self::assertSame($this->world()->getTime(), $this->provider()->getWorldData()->getTime(), "world data saved at the end");
		self::assertSame([], $this->saveChunks());
	}

	public function testWorldManagerFinishesInTimeWhateverTheBudget() : void{
		$manager = $this->newWorldManager();
		$manager->setAutoSaveInterval(50); //10 ticks for the chunks
		$manager->setAutoSaveTickBudget(0.000001);
		$changed = $this->changeChunks(25);
		$this->provider()->saved = [];
		$this->triggerAutoSave($manager);
		for($i = 0; $i < 11 && ($i === 0 || $manager->isAutoSaveInProgress()); ++$i){
			$manager->tick(++$this->tick);
		}
		self::assertFalse($manager->isAutoSaveInProgress());
		$saved = $this->provider()->saved;
		sort($saved);
		self::assertSame($changed, $saved);
	}

	public function testWorldManagerWithoutBudgetSavesInOneTick() : void{
		$manager = $this->newWorldManager();
		$manager->setAutoSaveTickBudget(0);
		$changed = $this->changeChunks(7);
		$this->saveChunks();
		$changed = $this->changeChunks(7);
		$this->provider()->saved = [];
		$this->triggerAutoSave($manager);
		$manager->tick(++$this->tick);
		self::assertFalse($manager->isAutoSaveInProgress());
		$saved = $this->provider()->saved;
		sort($saved);
		self::assertSame($changed, $saved);
	}

	public function testWorldManagerStopsAutoSaveOnSaveOff() : void{
		$manager = $this->newWorldManager();
		$manager->setAutoSaveTickBudget(0.000001);
		$this->saveChunks();
		$this->changeChunks(20);
		$this->provider()->saved = [];
		$this->triggerAutoSave($manager);
		$manager->tick(++$this->tick);
		self::assertTrue($manager->isAutoSaveInProgress());
		$savedBefore = count($this->provider()->saved);
		$manager->setAutoSave(false);
		$manager->tick(++$this->tick);
		self::assertFalse($manager->isAutoSaveInProgress());
		self::assertCount($savedBefore, $this->provider()->saved, "nothing saved after save-off");
	}

	public function testWorldUnloadSavesEverything() : void{
		$this->world()->setBlockAt(3, 4, 3, VanillaBlocks::GOLD());
		$this->world()->setBlockAt(-29, 4, 3, VanillaBlocks::GOLD());
		$this->world()->setTime(777);
		$this->world()->onUnload();
		$this->world = null;
		$this->provider = null;
		gc_collect_cycles();

		$reopened = new LevelDB($this->path, new NullThreadSafeLogger());
		try{
			self::assertSame(777, $reopened->getWorldData()->getTime());
			foreach([[0, 0, 3, 3], [-2, 0, 3, 3]] as [$cx, $cz, $lx, $lz]){
				$data = $reopened->loadChunk($cx, $cz)?->getData();
				self::assertInstanceOf(ChunkData::class, $data);
				self::assertSame(VanillaBlocks::GOLD()->getStateId(), $data->getSubChunks()[0]->getBlockStateId($lx, 4, $lz));
			}
		}finally{
			$reopened->close();
		}
	}
}

/**
 * LevelDB recording the chunks it is asked to save, and able to fail a commit.
 */
final class CountingLevelDB extends LevelDB{
	/**
	 * @var int[]
	 * @phpstan-var list<int>
	 */
	public array $saved = [];
	public bool $failNextCommit = false;

	/**
	 * Closes the database without writing anything kept by the provider, as if the process died.
	 */
	public function simulateCrash() : void{
		\Closure::bind(function() : void{
			$this->pendingWrites = null;
		}, $this, LevelDB::class)();
		$this->close();
	}

	public function saveChunk(int $chunkX, int $chunkZ, ChunkData $chunkData, int $dirtyFlags) : void{
		$this->saved[] = World::chunkHash($chunkX, $chunkZ);
		parent::saveChunk($chunkX, $chunkZ, $chunkData, $dirtyFlags);
	}

	public function commitWriteBatch() : void{
		if($this->failNextCommit){
			$this->failNextCommit = false;
			parent::commitWriteBatch(); //the batch is written: the failure is only reported
			throw new \LevelDBException("simulated write failure");
		}
		parent::commitWriteBatch();
	}
}
