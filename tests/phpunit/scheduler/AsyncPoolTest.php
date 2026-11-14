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

namespace pocketmine\scheduler;

use PHPUnit\Framework\TestCase;
use pmmp\thread\ThreadSafeArray;
use pocketmine\promise\PromiseResolver;
use pocketmine\snooze\SleeperHandler;
use pocketmine\thread\ThreadSafeClassLoader;
use pocketmine\utils\MainLogger;
use function define;
use function dirname;
use function microtime;
use function time;
use function usleep;

class AsyncPoolTest extends TestCase{

	/** @var AsyncPool */
	private $pool;
	/** @var MainLogger */
	private $mainLogger;

	public function setUp() : void{
		@define('pocketmine\\COMPOSER_AUTOLOADER_PATH', dirname(__DIR__, 3) . '/vendor/autoload.php');
		$this->mainLogger = new MainLogger(null, false, "Main", new \DateTimeZone('UTC'));
		$this->pool = new AsyncPool(2, 1024, new ThreadSafeClassLoader(), $this->mainLogger, new SleeperHandler());
	}

	public function tearDown() : void{
		$this->pool->shutdown();
	}

	public function testTaskLeak() : void{
		$start = microtime(true);
		$this->pool->submitTask(new LeakTestAsyncTask());
		while(!LeakTestAsyncTask::$destroyed && microtime(true) < $start + 30){
			usleep(50 * 1000);
			$this->pool->collectTasks();
		}
		self::assertTrue(LeakTestAsyncTask::$destroyed, "Task was not destroyed after 30 seconds");
	}

	public function testPublishProgressRace() : void{
		$task = new PublishProgressRaceAsyncTask();
		$this->pool->submitTask($task);
		while($this->pool->collectTasks()){
			usleep(50 * 1000);
		}
		self::assertTrue(PublishProgressRaceAsyncTask::$success, "Progress was not reported before task completion");
	}

	public function testThreadSafeSetResult() : void{
		/** @phpstan-var PromiseResolver<ThreadSafeArray<array-key, mixed>> $resolver */
		$resolver = new PromiseResolver();
		$resolver->getPromise()->onCompletion(
			function(ThreadSafeArray $result) : void{
				self::assertCount(1, $result);
				self::assertSame(["foo"], (array) $result);
			},
			function() : void{
				self::fail("Promise failed");
			}
		);
		$this->pool->submitTask(new ThreadSafeResultAsyncTask($resolver));
		while($this->pool->collectTasks()){
			usleep(50 * 1000);
		}
	}

	/**
	 * This test ensures that the fix for an exotic AsyncTask::__destruct() reentrancy bug has not regressed.
	 *
	 * Due to an unset() in the function body, other AsyncTask::__destruct() calls could be triggered during
	 * an AsyncTask's destruction. If done in the wrong way, this could lead to a crash.
	 *
	 * @doesNotPerformAssertions This test is checking for a crash condition, not a specific output.
	 */
	public function testTaskDestructorReentrancy() : void{
		$this->pool->submitTask(new class extends AsyncTask{
			public function __construct(){
				$this->storeLocal("task", new class extends AsyncTask{

					public function __construct(){
						$this->storeLocal("dummy", 1);
					}

					public function onRun() : void{
						//dummy
					}
				});
			}

			public function onRun() : void{
				//dummy
			}
		});
		while($this->pool->collectTasks()){
			usleep(50 * 1000);
		}
	}

	public function testNullComplexDataFetch() : void{
		$this->pool->submitTask(new class extends AsyncTask{
			public function __construct(){
				$this->storeLocal("null", null);
			}

			public function onRun() : void{
				//dummy
			}

			public function onCompletion() : void{
				AsyncPoolTest::assertNull($this->fetchLocal("null"));
			}
		});
		while($this->pool->collectTasks()){
			usleep(50 * 1000);
		}
	}

	public function testSelectWorkerAmong() : void{
		$pool = new AsyncPool(4, 1024, new ThreadSafeClassLoader(), $this->mainLogger, new SleeperHandler());
		try{
			self::assertSame(0, $pool->selectWorkerAmong(2));
			//tasks stay in a worker's queue until they are collected
			$pool->submitTaskToWorker(new LeakTestAsyncTask(), 0);
			self::assertSame(1, $pool->selectWorkerAmong(2), "a busy worker 0 must start worker 1");
			$pool->submitTaskToWorker(new LeakTestAsyncTask(), 1);
			self::assertContains($pool->selectWorkerAmong(2), [0, 1], "workers 2 and 3 are outside the first 2");
			self::assertSame(2, $pool->selectWorker(), "the whole pool may start worker 2");
			self::assertSame(2, $pool->selectWorkerAmong(10), "the count is clamped to the pool size");
			self::assertSame(0, $pool->selectWorkerAmong(0), "at least one worker");
		}finally{
			$pool->shutdown();
		}
	}

	private static function drain(AsyncPool $pool) : void{
		$start = microtime(true);
		while($pool->collectTasks() && microtime(true) < $start + 30){
			usleep(10 * 1000);
		}
	}

	private static function setLastUsed(AsyncPool $pool, int $workerId, int $lastUsed) : void{
		$workers = (new \ReflectionProperty(AsyncPool::class, "workers"))->getValue($pool);
		self::assertIsArray($workers);
		$entry = $workers[$workerId] ?? null;
		self::assertInstanceOf(AsyncPoolWorkerEntry::class, $entry);
		$entry->lastUsed = $lastUsed;
	}

	public function testSelectWorkerPrefersUnreservedWorkers() : void{
		$pool = new AsyncPool(4, 1024, new ThreadSafeClassLoader(), $this->mainLogger, new SleeperHandler());
		try{
			$pool->setReservedWorkerCount(2);
			self::assertSame(0, $pool->selectWorker(), "no worker is started for the preference");
			for($i = 0; $i < 3; ++$i){
				$pool->submitTaskToWorker(new GarbageCollectionTask(), $i);
			}
			self::drain($pool);
			self::assertSame([0, 1, 2], $pool->getRunningWorkers());

			self::assertSame(2, $pool->selectWorker(), "an idle worker outside the reserved ones comes first");
			self::assertSame(0, $pool->selectWorkerAmong(2), "selectWorkerAmong() is unchanged");

			$pool->submitTaskToWorker(new GarbageCollectionTask(), 2);
			self::assertSame(0, $pool->selectWorker(), "no idle unreserved worker: same as without reservation");

			$pool->setReservedWorkerCount(4);
			self::drain($pool);
			self::assertSame(0, $pool->selectWorker(), "no effect when not smaller than the pool size");
			$pool->setReservedWorkerCount(0);
			self::assertSame(0, $pool->selectWorker(), "no effect when 0");
		}finally{
			$pool->shutdown();
		}
	}

	public function testShutdownIdleWorkers() : void{
		for($i = 0; $i < 2; ++$i){
			$this->pool->submitTaskToWorker(new GarbageCollectionTask(), $i);
		}
		self::drain($this->pool);
		self::assertSame(0, $this->pool->shutdownUnusedWorkers(), "both workers were used just now");
		self::assertSame(0, $this->pool->shutdownWorkersIdleFor(60));

		self::setLastUsed($this->pool, 0, time() - 100);
		self::assertSame(0, $this->pool->shutdownUnusedWorkers(), "idle for less than 300 seconds");
		self::assertSame(1, $this->pool->shutdownWorkersIdleFor(60));
		self::assertSame([1], $this->pool->getRunningWorkers());
		self::assertFalse($this->pool->isWorkerRunning(0), "getRunningWorkers() is a list: [1][0] is set, but worker 0 is not running");
		self::assertTrue($this->pool->isWorkerRunning(1));

		self::setLastUsed($this->pool, 1, time() - 1000);
		self::assertSame(1, $this->pool->shutdownUnusedWorkers());
		self::assertSame([], $this->pool->getRunningWorkers());

		$this->pool->submitTaskToWorker(new GarbageCollectionTask(), 0);
		self::assertTrue($this->pool->isWorkerRunning(0), "started again when a task needs it");
		self::setLastUsed($this->pool, 0, time() - 1000);
		self::assertSame(0, $this->pool->shutdownUnusedWorkers(), "a worker with queued tasks is kept");
		self::drain($this->pool);
	}
}
