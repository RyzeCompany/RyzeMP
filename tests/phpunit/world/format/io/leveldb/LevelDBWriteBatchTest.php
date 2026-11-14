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

namespace pocketmine\world\format\io\leveldb;

use PHPUnit\Framework\TestCase;
use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\utils\Filesystem;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\ChunkData;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use pocketmine\world\redstone\NullThreadSafeLogger;
use pocketmine\world\WorldCreationOptions;
use function bin2hex;
use function count;
use function dirname;
use function escapeshellarg;
use function extension_loaded;
use function fgets;
use function file_exists;
use function file_put_contents;
use function intdiv;
use function is_dir;
use function is_resource;
use function iterator_to_array;
use function microtime;
use function mt_rand;
use function mt_srand;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function sprintf;
use function stream_get_contents;
use function stream_set_blocking;
use function substr;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;
use function usleep;
use const PHP_BINARY;

/**
 * Batched chunk writes (WriteBatchWorldProvider) write exactly what unbatched writes write, reads see kept writes,
 * a chunk is never written in part, and a process killed in the middle of batched writes leaves only whole chunks.
 */
final class LevelDBWriteBatchTest extends TestCase{
	/** @var string[] */
	private array $paths = [];
	/** @var LevelDB[] */
	private array $providers = [];

	protected function setUp() : void{
		if(!extension_loaded("leveldb")){
			self::markTestSkipped("leveldb extension not loaded");
		}
	}

	protected function tearDown() : void{
		foreach($this->providers as $provider){
			$provider->close();
		}
		$this->providers = [];
		foreach($this->paths as $path){
			if(is_dir($path)){
				Filesystem::recursiveUnlink($path);
			}elseif(file_exists($path)){
				unlink($path);
			}
		}
	}

	/**
	 * @param-out string $path
	 */
	private function newProvider(?string &$path = null) : LevelDB{
		$path = sys_get_temp_dir() . "/pm-write-batch-" . uniqid();
		$this->paths[] = $path;
		LevelDB::generate($path, "write_batch", WorldCreationOptions::create());
		return $this->providers[] = new LevelDB($path, new NullThreadSafeLogger());
	}

	/**
	 * A chunk whose blocks, tiles, entities and scheduled updates all depend on the version, so that a chunk read back
	 * can be checked to be whole (all of it from the same version).
	 */
	private static function chunkData(int $chunkX, int $chunkZ, int $version, bool $withExtras = true) : ChunkData{
		$chunk = new Chunk([], true);
		$blocks = [VanillaBlocks::STONE(), VanillaBlocks::DIRT(), VanillaBlocks::OAK_PLANKS(), VanillaBlocks::GLASS(), VanillaBlocks::REDSTONE_REPEATER()];
		$chunk->setBlockStateId(0, 0, 0, $blocks[$version % count($blocks)]->getStateId());
		for($i = 0; $i < 40; ++$i){
			$chunk->setBlockStateId(mt_rand(0, 15), mt_rand(-64, 100), mt_rand(0, 15), $blocks[mt_rand(0, count($blocks) - 1)]->getStateId());
		}
		$chunk->setBlockStateId(5, 5, 5, VanillaBlocks::REDSTONE_REPEATER()->getStateId());
		$x = $chunkX * 16;
		$z = $chunkZ * 16;
		$tiles = $withExtras ? [CompoundTag::create()->setString("id", "Test")->setInt("x", $x)->setInt("y", 1)->setInt("z", $z)->setInt("version", $version)] : [];
		$entities = $withExtras ? [CompoundTag::create()->setString("identifier", "test:entity")->setInt("version", $version)] : [];
		$ticks = $withExtras ? [[$x + 5, 5, $z + 5, $version]] : [];
		return new ChunkData($chunk->getSubChunks(), $version % 2 === 0, $entities, $tiles, $ticks);
	}

	/**
	 * @return string[] key (hex) => value (hex) of every record of the database
	 * @phpstan-return array<string, string>
	 */
	private static function dump(LevelDB $provider) : array{
		$result = [];
		foreach($provider->getDatabase()->getIterator() as $key => $value){
			$result[bin2hex((string) $key)] = bin2hex((string) $value);
		}
		return $result;
	}

	public function testBatchedWritesEqualDirectWrites() : void{
		$direct = $this->newProvider();
		$batched = $this->newProvider();
		foreach([$direct, $batched] as $provider){
			mt_srand(1234);
			$isBatched = $provider === $batched;
			for($round = 0; $round < 3; ++$round){
				if($isBatched){
					$provider->beginWriteBatch();
					$provider->beginWriteBatch(); //nested
				}
				for($i = 0; $i < 30; ++$i){
					//the second round drops the extras (deletes their records), the third writes the same chunks again
					$provider->saveChunk($i % 6, intdiv($i, 6), self::chunkData($i % 6, intdiv($i, 6), $round, $round !== 1), $round === 2 && $i % 2 === 0 ? Chunk::DIRTY_FLAGS_NONE : Chunk::DIRTY_FLAGS_ALL);
				}
				if($isBatched){
					$provider->commitWriteBatch();
					$provider->commitWriteBatch();
				}
			}
		}
		$expected = self::dump($direct);
		self::assertGreaterThan(30 * 5, count($expected));
		self::assertSame($expected, self::dump($batched));
	}

	public function testWritesWaitForOutermostCommit() : void{
		$provider = $this->newProvider();
		$pending = new \ReflectionProperty(LevelDB::class, "pendingWrites");
		$provider->beginWriteBatch();
		$provider->beginWriteBatch();
		$provider->saveChunk(0, 0, self::chunkData(0, 0, 1), Chunk::DIRTY_FLAGS_ALL);
		$provider->commitWriteBatch();
		self::assertNotNull($pending->getValue($provider), "kept until the outermost commit");
		$provider->commitWriteBatch();
		self::assertNull($pending->getValue($provider));
		self::assertNotNull($provider->loadChunk(0, 0));
	}

	public function testReadsSeeKeptWrites() : void{
		$provider = $this->newProvider();
		$provider->saveChunk(0, 0, self::chunkData(0, 0, 1), Chunk::DIRTY_FLAGS_ALL);
		$provider->beginWriteBatch();
		try{
			$provider->saveChunk(0, 0, self::chunkData(0, 0, 2), Chunk::DIRTY_FLAGS_ALL);
			$provider->saveChunk(1, 0, self::chunkData(1, 0, 2), Chunk::DIRTY_FLAGS_ALL);

			$loaded = $provider->loadChunk(0, 0);
			self::assertNotNull($loaded);
			self::assertSame(2, $loaded->getData()->getTileNBT()[0]->getInt("version"));
			self::assertSame(2, $provider->calculateChunkCount());

			$provider->saveChunk(2, 0, self::chunkData(2, 0, 2), Chunk::DIRTY_FLAGS_ALL);
			self::assertNotFalse($provider->getDatabase()->get(LevelDB::chunkIndex(2, 0) . ChunkDataKey::NEW_VERSION));

			$provider->saveChunk(3, 0, self::chunkData(3, 0, 2), Chunk::DIRTY_FLAGS_ALL);
			self::assertCount(4, iterator_to_array($provider->getAllChunks(), false));
		}finally{
			$provider->commitWriteBatch();
		}
	}

	public function testCommitWithoutBatchThrows() : void{
		$provider = $this->newProvider();
		$this->expectException(\LogicException::class);
		$provider->commitWriteBatch();
	}

	public function testFailedChunkLeavesNothingInTheBatch() : void{
		$provider = $this->newProvider();
		$bad = new Chunk([], true);
		//a state ID no block has: serializing the palette fails half way through the chunk
		$bad->setSubChunk(3, new SubChunk(Block::EMPTY_STATE_ID, [new PalettedBlockArray(0x7ffffff0)], new PalettedBlockArray(0)));

		$provider->beginWriteBatch();
		try{
			$provider->saveChunk(0, 0, self::chunkData(0, 0, 1), Chunk::DIRTY_FLAGS_ALL);
			try{
				$provider->saveChunk(1, 0, new ChunkData($bad->getSubChunks(), true, [CompoundTag::create()->setInt("x", 1)], [], []), Chunk::DIRTY_FLAGS_ALL);
				self::fail("serializing an unknown block state should fail");
			}catch(\Exception){
				//expected
			}
			$provider->saveChunk(2, 0, self::chunkData(2, 0, 1), Chunk::DIRTY_FLAGS_ALL);
		}finally{
			$provider->commitWriteBatch();
		}

		self::assertNotNull($provider->loadChunk(0, 0));
		self::assertNotNull($provider->loadChunk(2, 0));
		$prefix = LevelDB::chunkIndex(1, 0);
		foreach($provider->getDatabase()->getIterator() as $key => $_){
			self::assertNotSame($prefix, substr((string) $key, 0, 8), "nothing of the failed chunk may be written");
		}
	}

	public function testCloseWritesKeptWrites() : void{
		$provider = $this->newProvider($path);
		$provider->beginWriteBatch();
		$provider->saveChunk(0, 0, self::chunkData(0, 0, 3), Chunk::DIRTY_FLAGS_ALL);
		$provider->close(); //a batch left open (bug or exception) is still written on close

		$reopened = $this->providers[] = new LevelDB($path, new NullThreadSafeLogger());
		$loaded = $reopened->loadChunk(0, 0);
		self::assertNotNull($loaded);
		self::assertSame(3, $loaded->getData()->getTileNBT()[0]->getInt("version"));
	}

	/**
	 * A child process writes versions of a set of chunks in batches (like autosave and chunk unloads do) and is killed
	 * at a random point, like a crash. Every chunk on disk must then be whole (all of its records from the same
	 * version) and at least as new as the last batch the child reported as committed.
	 */
	public function testKilledProcessLeavesWholeChunks() : void{
		$root = dirname(__DIR__, 6);
		$autoload = $root . "/vendor/autoload.php";
		if(!file_exists($autoload)){
			self::markTestSkipped("vendor/autoload.php not found");
		}
		for($run = 0; $run < 3; ++$run){
			$path = sys_get_temp_dir() . "/pm-write-batch-crash-" . uniqid();
			$this->paths[] = $path;
			LevelDB::generate($path, "write_batch_crash", WorldCreationOptions::create());
			$script = $path . "-child.php";
			$this->paths[] = $script;
			file_put_contents($script, self::childScript($autoload, $path));

			$process = proc_open([PHP_BINARY, "-d", "opcache.enable_cli=0", "-d", "opcache.cache_id=pm-write-batch-child-" . uniqid(), $script], [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
			self::assertTrue(is_resource($process));
			stream_set_blocking($pipes[1], true);
			//let it commit a few versions, then kill it at a random point of a batch
			$committed = -1;
			$killAfter = 2 + $run;
			$deadline = microtime(true) + 60;
			while(microtime(true) < $deadline && ($line = fgets($pipes[1])) !== false){
				$committed = (int) trim($line);
				if($committed >= $killAfter){
					break;
				}
			}
			usleep(mt_rand(0, 30_000));
			proc_terminate($process, 9);
			for($i = 0; $i < 100 && proc_get_status($process)["running"]; ++$i){
				usleep(20_000);
			}
			$errors = $committed < $killAfter ? (string) stream_get_contents($pipes[2]) : "";
			proc_close($process);
			self::assertGreaterThanOrEqual($killAfter, $committed, "the child did not commit enough versions: " . $errors);

			$provider = $this->providers[] = new LevelDB($path, new NullThreadSafeLogger());
			for($i = 0; $i < 128; ++$i){
				$loaded = $provider->loadChunk($i % 8, intdiv($i, 8));
				self::assertNotNull($loaded, "chunk $i was written by the first batch");
				$data = $loaded->getData();
				$version = $data->getTileNBT()[0]->getInt("version");
				self::assertGreaterThanOrEqual($committed, $version, "chunk $i is at least as new as the last committed batch");
				self::assertSame($version, $data->getEntityNBT()[0]->getInt("version"), sprintf("chunk %d: entities of version %d", $i, $version));
				self::assertSame($version % 2 === 0, $data->isPopulated(), "chunk $i: populated flag of version $version");
				self::assertSame([[($i % 8) * 16 + 5, 5, intdiv($i, 8) * 16 + 5, $version]], $data->getScheduledBlockUpdates(), "chunk $i: scheduled updates of version $version");
				$blocks = [VanillaBlocks::STONE(), VanillaBlocks::DIRT(), VanillaBlocks::OAK_PLANKS(), VanillaBlocks::GLASS(), VanillaBlocks::REDSTONE_REPEATER()];
				$subChunk = $data->getSubChunks()[0];
				self::assertSame($blocks[$version % count($blocks)]->getStateId(), $subChunk->getBlockStateId(0, 0, 0), "chunk $i: blocks of version $version");
			}
			$provider->close();
		}
	}

	private static function childScript(string $autoload, string $path) : string{
		$autoload = escapeshellarg($autoload);
		$path = escapeshellarg($path);
		return <<<PHP
<?php
declare(strict_types=1);
require {$autoload};
use pocketmine\\block\\VanillaBlocks;
use pocketmine\\nbt\\tag\\CompoundTag;
use pocketmine\\world\\format\\Chunk;
use pocketmine\\world\\format\\io\\ChunkData;
use pocketmine\\world\\format\\io\\leveldb\\LevelDB;
use pocketmine\\world\\redstone\\NullThreadSafeLogger;
\$provider = new LevelDB({$path}, new NullThreadSafeLogger());
\$blocks = [VanillaBlocks::STONE(), VanillaBlocks::DIRT(), VanillaBlocks::OAK_PLANKS(), VanillaBlocks::GLASS(), VanillaBlocks::REDSTONE_REPEATER()];
for(\$version = 0; ; ++\$version){
	\$provider->beginWriteBatch();
	for(\$i = 0; \$i < 128; ++\$i){
		\$x = \$i % 8;
		\$z = intdiv(\$i, 8);
		\$chunk = new Chunk([], true);
		\$chunk->setBlockStateId(0, 0, 0, \$blocks[\$version % count(\$blocks)]->getStateId());
		for(\$j = 0; \$j < 200; ++\$j){
			\$chunk->setBlockStateId(mt_rand(0, 15), mt_rand(16, 300), mt_rand(0, 15), \$blocks[mt_rand(0, 4)]->getStateId());
		}
		\$tiles = [CompoundTag::create()->setString("id", "Test")->setInt("x", \$x * 16)->setInt("y", 1)->setInt("z", \$z * 16)->setInt("version", \$version)];
		\$entities = [CompoundTag::create()->setString("identifier", "test:entity")->setInt("version", \$version)];
		\$provider->saveChunk(\$x, \$z, new ChunkData(\$chunk->getSubChunks(), \$version % 2 === 0, \$entities, \$tiles, [[\$x * 16 + 5, 5, \$z * 16 + 5, \$version]]), Chunk::DIRTY_FLAGS_ALL);
	}
	\$provider->commitWriteBatch();
	echo \$version, "\\n";
	fflush(STDOUT);
}
PHP;
	}
}
