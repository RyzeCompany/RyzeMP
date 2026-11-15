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

namespace pocketmine\network\mcpe\compression;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\types\CompressionAlgorithm;
use pocketmine\snooze\SleeperHandler;
use pocketmine\thread\ThreadSafeClassLoader;
use pocketmine\timings\Timings;
use pocketmine\utils\MainLogger;
use function chr;
use function count;
use function define;
use function dirname;
use function microtime;
use function ord;
use function random_bytes;
use function str_repeat;
use function substr;
use function usleep;

final class CompressionThreadDispatcherTest extends TestCase{
	private SleeperHandler $sleeper;
	private ZlibCompressor $compressor;
	private CompressionThreadDispatcher $dispatcher;

	protected function setUp() : void{
		@define('pocketmine\\COMPOSER_AUTOLOADER_PATH', dirname(__DIR__, 5) . '/vendor/autoload.php');
		Timings::init();
		$this->sleeper = new SleeperHandler();
		$this->compressor = new ZlibCompressor(6, 256, ZlibCompressor::DEFAULT_MAX_DECOMPRESSION_SIZE);
		$this->dispatcher = new CompressionThreadDispatcher(
			$this->compressor,
			$this->sleeper,
			new MainLogger(null, false, "Main", new \DateTimeZone('UTC')),
			[new ThreadSafeClassLoader()]
		);
	}

	protected function tearDown() : void{
		$this->dispatcher->shutdown();
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	private static function sampleBatches() : array{
		$batches = [];
		for($i = 0; $i < 60; $i++){
			//some below the 256-byte compression threshold (level 0 wrapping), some compressible, some random
			$batches[] = match($i % 3){
				0 => str_repeat("ab", $i * 7 + 1),
				1 => random_bytes(100 + $i * 20),
				default => str_repeat(chr($i), 300) . random_bytes(50)
			};
		}
		return $batches;
	}

	public function testBatchesComeBackInOrderAndDecompress() : void{
		$batches = self::sampleBatches();
		$resolved = [];
		foreach($batches as $index => $batch){
			$this->dispatcher->submit($batch)->onResolve(function(CompressBatchPromise $promise) use (&$resolved, $index) : void{
				$resolved[] = [$index, $promise->getResult()];
			});
		}

		$deadline = microtime(true) + 20;
		while(count($resolved) < count($batches) && microtime(true) < $deadline){
			$this->sleeper->processNotifications();
			usleep(1000);
		}
		self::assertCount(count($batches), $resolved, "the thread did not compress every batch in time");
		self::assertSame(0, $this->dispatcher->getPendingCount());

		foreach($resolved as $position => [$index, $result]){
			self::assertSame($position, $index, "batches must come back in submission order");
			self::assertSame(CompressionAlgorithm::ZLIB, ord($result[0]));
			self::assertSame($batches[$index], $this->compressor->decompress(substr($result, 1)));
			//same bytes as the main-thread path
			self::assertSame(chr(CompressionAlgorithm::ZLIB) . $this->compressor->compress($batches[$index]), $result);
		}
	}

	public function testShutdownCompressesWhatIsStillQueued() : void{
		$batches = self::sampleBatches();
		$promises = [];
		foreach($batches as $batch){
			$promises[] = $this->dispatcher->submit($batch);
		}
		$this->dispatcher->shutdown();
		self::assertFalse($this->dispatcher->isAvailable());
		self::assertSame(0, $this->dispatcher->getPendingCount());
		foreach($promises as $index => $promise){
			self::assertTrue($promise->hasResult(), "batch $index was lost at shutdown");
			self::assertSame($batches[$index], $this->compressor->decompress(substr($promise->getResult(), 1)));
		}
	}
}
