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

namespace pocketmine\world\generator\executor;

use PHPUnit\Framework\TestCase;
use pocketmine\scheduler\AsyncPool;
use pocketmine\scheduler\AsyncPoolWorkerEntry;
use pocketmine\scheduler\GarbageCollectionTask;
use pocketmine\snooze\SleeperHandler;
use pocketmine\thread\ThreadSafeClassLoader;
use pocketmine\utils\MainLogger;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\Flat;
use function define;
use function dirname;
use function microtime;
use function time;
use function usleep;

/**
 * A worker that was shut down while a worker with a higher ID kept running must get the generator again before its
 * next PopulationTask. AsyncPool::getRunningWorkers() is a list of IDs: indexing it by worker ID took such a stopped
 * worker for a running one, no AsyncGeneratorRegisterTask was sent, and the PopulationTask crashed the server.
 */
final class AsyncGeneratorExecutorTest extends TestCase{
	private MainLogger $logger;
	private int $completed = 0;

	protected function setUp() : void{
		@define('pocketmine\\COMPOSER_AUTOLOADER_PATH', dirname(__DIR__, 5) . '/vendor/autoload.php');
		$this->logger = new MainLogger(null, false, "Main", new \DateTimeZone('UTC'));
		$this->completed = 0;
	}

	protected function tearDown() : void{
		AsyncGeneratorExecutor::setMaxWorkers(0);
	}

	/**
	 * @phpstan-return \Closure(Chunk, array<int, Chunk>) : void
	 */
	private function onCompletion() : \Closure{
		return function(Chunk $centerChunk, array $adjacentChunks) : void{
			$this->completed++;
		};
	}

	private function waitForPopulation(AsyncPool $pool, int $count) : void{
		$start = microtime(true);
		while($this->completed < $count && microtime(true) < $start + 60){
			$pool->collectTasks();
			usleep(10 * 1000);
		}
		self::assertSame($count, $this->completed, "PopulationTasks did not complete within 60 seconds");
	}

	private static function getWorkerEntry(AsyncPool $pool, int $workerId) : AsyncPoolWorkerEntry{
		$workers = (new \ReflectionProperty(AsyncPool::class, "workers"))->getValue($pool);
		self::assertIsArray($workers);
		$entry = $workers[$workerId] ?? null;
		self::assertInstanceOf(AsyncPoolWorkerEntry::class, $entry, "worker $workerId is not running");
		return $entry;
	}

	/**
	 * @return string[] short class names of the tasks queued on the worker (submitted and not collected yet), oldest first
	 * @phpstan-return list<string>
	 */
	private static function getQueuedTasks(AsyncPool $pool, int $workerId) : array{
		$names = [];
		foreach(self::getWorkerEntry($pool, $workerId)->tasks as $task){
			$names[] = (new \ReflectionClass($task))->getShortName();
		}
		return $names;
	}

	private function createExecutor(AsyncPool $pool) : AsyncGeneratorExecutor{
		return new AsyncGeneratorExecutor($this->logger, $pool, new GeneratorExecutorSetupParameters(0, 255, 1234, Flat::class, ""));
	}

	/**
	 * @return \Generator<string, array{int}, void, void>
	 */
	public static function maxWorkersProvider() : \Generator{
		yield "max-workers 2" => [2];
		yield "max-workers 0 (all)" => [0];
	}

	/**
	 * Worker 0 idle for longer than the timeout is shut down while worker 1, used more recently, keeps running.
	 *
	 * @dataProvider maxWorkersProvider
	 */
	public function testStoppedWorkerBelowRunningOneGetsGeneratorAgain(int $maxWorkers) : void{
		$pool = new AsyncPool(2, 1024, new ThreadSafeClassLoader(), $this->logger, new SleeperHandler());
		AsyncGeneratorExecutor::setMaxWorkers($maxWorkers);
		$executor = $this->createExecutor($pool);
		try{
			$executor->populate(0, 0, null, [], $this->onCompletion());
			$executor->populate(5, 5, null, [], $this->onCompletion());
			self::assertSame(["AsyncGeneratorRegisterTask", "PopulationTask"], self::getQueuedTasks($pool, 0));
			self::assertSame(["AsyncGeneratorRegisterTask", "PopulationTask"], self::getQueuedTasks($pool, 1));
			$this->waitForPopulation($pool, 2);

			self::getWorkerEntry($pool, 0)->lastUsed = time() - 1000;
			self::assertSame(1, $pool->shutdownUnusedWorkers());
			self::assertSame([1], $pool->getRunningWorkers());
			self::assertFalse($pool->isWorkerRunning(0));

			//worker 1 still has its generator: no second copy
			$executor->populate(10, 10, null, [], $this->onCompletion());
			self::assertSame(["PopulationTask"], self::getQueuedTasks($pool, 1));

			//worker 1 is busy (tasks stay queued until collected), so this starts worker 0 again
			$executor->populate(15, 15, null, [], $this->onCompletion());
			self::assertSame(["AsyncGeneratorRegisterTask", "PopulationTask"], self::getQueuedTasks($pool, 0));
			$this->waitForPopulation($pool, 4);
		}finally{
			$executor->shutdown();
			$pool->shutdown();
		}
	}

	/**
	 * Workers 0 and 1 (the generation workers) are shut down while worker 2 keeps running.
	 */
	public function testStoppedGenerationWorkersWhileHigherWorkerRuns() : void{
		$pool = new AsyncPool(4, 1024, new ThreadSafeClassLoader(), $this->logger, new SleeperHandler());
		AsyncGeneratorExecutor::setMaxWorkers(2);
		$executor = $this->createExecutor($pool);
		try{
			$executor->populate(0, 0, null, [], $this->onCompletion());
			$executor->populate(5, 5, null, [], $this->onCompletion());
			$this->waitForPopulation($pool, 2);
			$pool->submitTaskToWorker(new GarbageCollectionTask(), 2);
			while($pool->collectTasks()){
				usleep(10 * 1000);
			}

			self::getWorkerEntry($pool, 0)->lastUsed = time() - 1000;
			self::getWorkerEntry($pool, 1)->lastUsed = time() - 1000;
			self::assertSame(2, $pool->shutdownUnusedWorkers());
			self::assertSame([2], $pool->getRunningWorkers());

			$executor->populate(10, 10, null, [], $this->onCompletion());
			self::assertSame(["AsyncGeneratorRegisterTask", "PopulationTask"], self::getQueuedTasks($pool, 0));
			$this->waitForPopulation($pool, 3);
		}finally{
			$executor->shutdown();
			$pool->shutdown();
		}
	}
}
