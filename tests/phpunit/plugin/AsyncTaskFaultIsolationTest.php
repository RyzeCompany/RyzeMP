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

namespace pocketmine\plugin;

use PHPUnit\Framework\TestCase;
use pocketmine\plugin\faulty\FaultyAsyncTask;
use pocketmine\plugin\faulty\FaultyTestPlugin;
use pocketmine\scheduler\AsyncPool;
use pocketmine\Server;
use pocketmine\snooze\SleeperHandler;
use pocketmine\thread\ThreadCrashException;
use pocketmine\thread\ThreadSafeClassLoader;
use function define;
use function dirname;
use function microtime;
use function usleep;

final class AsyncTaskFaultIsolationTest extends TestCase{
	private AsyncPool $pool;
	private CapturingLogger $logger;
	private FaultyTestPlugin $plugin;

	protected function setUp() : void{
		@define('pocketmine\\COMPOSER_AUTOLOADER_PATH', dirname(__DIR__, 3) . '/vendor/autoload.php');
		$this->pool = new AsyncPool(1, 1024, new ThreadSafeClassLoader(), new NullThreadSafeLogger(), new SleeperHandler());

		$server = $this->createMock(Server::class);
		$server->method("getLogger")->willReturn(new NullThreadSafeLogger());
		$this->plugin = FaultyTestPlugin::create($server, $this->createMock(PluginLoader::class), $this->createMock(ResourceProvider::class));
		$this->plugin->onEnableStateChange(true);
		$this->logger = new CapturingLogger();
		PluginFaultHandler::setInstance(new PluginFaultHandler(
			$this->logger,
			fn() : array => [$this->plugin->getName() => $this->plugin],
			function(Plugin $plugin) : void{}
		));
		FaultyAsyncTask::$completed = false;
	}

	protected function tearDown() : void{
		PluginFaultHandler::setInstance(null);
		$this->pool->shutdown();
	}

	private function collectAll() : void{
		$start = microtime(true);
		while($this->pool->collectTasks()){
			if(microtime(true) - $start > 30){
				self::fail("Tasks did not complete within 30 seconds");
			}
			usleep(20 * 1000);
		}
	}

	public function testOnCompletionFailureIsIsolated() : void{
		$this->pool->submitTask(new FaultyAsyncTask(false));
		$this->collectAll();

		self::assertTrue(FaultyAsyncTask::$completed);
		self::assertCount(1, $this->logger->messages(\LogLevel::ERROR, "[FaultyTest v1.2.3] Unhandled exception in onCompletion() of async task"));
	}

	public function testWorkerFailureIsIsolatedAndWorkerSurvives() : void{
		$this->pool->submitTask(new FaultyAsyncTask(true));
		$this->collectAll();

		self::assertFalse(FaultyAsyncTask::$completed, "onCompletion() must not be called when onRun() failed");
		$errors = $this->logger->messages(\LogLevel::ERROR, "[FaultyTest v1.2.3] Unhandled exception in onRun() of async task");
		self::assertCount(1, $errors);
		self::assertStringContainsString("worker failure", $errors[0], "The worker's trace should be logged");

		//the worker is still usable
		$this->pool->submitTask(new FaultyAsyncTask(false));
		$this->collectAll();
		self::assertTrue(FaultyAsyncTask::$completed);
	}

	public function testCoreWorkerFailureStillCrashes() : void{
		$this->pool->submitTask(new CoreFailingAsyncTask());
		$this->expectException(ThreadCrashException::class);
		$this->collectAll();
	}
}
