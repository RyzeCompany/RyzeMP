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
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\PluginCommand;
use pocketmine\command\SimpleCommandMap;
use pocketmine\event\EventPriority;
use pocketmine\event\fixtures\TestChildEvent;
use pocketmine\event\HandlerListManager;
use pocketmine\event\plugin\PluginErrorEvent;
use pocketmine\lang\Language;
use pocketmine\lang\Translatable;
use pocketmine\network\PacketHandlingException;
use pocketmine\permission\DefaultPermissions;
use pocketmine\permission\Permission;
use pocketmine\permission\PermissionManager;
use pocketmine\plugin\faulty\FaultyCode;
use pocketmine\plugin\faulty\FaultyCommandExecutor;
use pocketmine\plugin\faulty\FaultyTestPlugin;
use pocketmine\promise\PromiseResolver;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskScheduler;
use pocketmine\Server;
use pocketmine\utils\AssumptionFailedError;

final class PluginFaultHandlerTest extends TestCase{
	private const PERMISSION = "pocketmine.test.faulty";

	private Server $server;
	private PluginManager $pluginManager;
	private FaultyTestPlugin $plugin;
	private CapturingLogger $logger;
	private float $time = 1000.0;
	/** @var string[] */
	private array $disabled = [];
	private int $laterCalls = 0;

	protected function setUp() : void{
		HandlerListManager::global()->unregisterAll();

		$this->server = $this->createMock(Server::class);
		$this->server->method("getLogger")->willReturn(new NullThreadSafeLogger());
		$this->pluginManager = new PluginManager($this->server, null);
		$this->plugin = FaultyTestPlugin::create($this->server, $this->createMock(PluginLoader::class), $this->createMock(ResourceProvider::class));
		$this->plugin->onEnableStateChange(true);
		$this->logger = new CapturingLogger();
		$this->time = 1000.0;
		$this->disabled = [];
		$this->laterCalls = 0;
		$this->installHandler();
	}

	protected function tearDown() : void{
		PluginFaultHandler::setInstance(null);
		HandlerListManager::global()->unregisterAll();
	}

	private function installHandler(bool $crash = false, int $disableAfterErrors = PluginFaultHandler::DEFAULT_DISABLE_AFTER_ERRORS, float $window = PluginFaultHandler::DEFAULT_ERROR_WINDOW_SECONDS) : PluginFaultHandler{
		$handler = new PluginFaultHandler(
			$this->logger,
			fn() : array => [$this->plugin->getName() => $this->plugin],
			function(Plugin $plugin) : void{
				$this->disabled[] = $plugin->getName();
				$this->pluginManager->disablePlugin($plugin);
			},
			$crash,
			$disableAfterErrors,
			$window,
			fn() : float => $this->time
		);
		PluginFaultHandler::setInstance($handler);
		return $handler;
	}

	private function handler() : PluginFaultHandler{
		return PluginFaultHandler::getInstance() ?? throw new AssumptionFailedError("No fault handler installed");
	}

	/**
	 * Registers a failing plugin handler, followed by a working one which counts its calls.
	 */
	private function registerFailingHandler() : void{
		$this->pluginManager->registerEvent(TestChildEvent::class, FaultyCode::eventHandler(), EventPriority::LOW, $this->plugin);
		$this->pluginManager->registerEvent(TestChildEvent::class, function(TestChildEvent $event) : void{
			$this->laterCalls++;
		}, EventPriority::HIGH, $this->plugin);
	}

	public function testEventHandlerFailureIsIsolated() : void{
		$this->registerFailingHandler();

		(new TestChildEvent())->call();

		self::assertSame(1, $this->laterCalls, "Handlers after the failing one should still be called");
		$errors = $this->logger->messages(\LogLevel::ERROR, "[FaultyTest v1.2.3] Unhandled exception in event handler");
		self::assertCount(1, $errors);
		self::assertStringContainsString("plugin failure", $errors[0]);
		self::assertStringContainsString("Stack trace", $errors[0]);
		self::assertSame(1, $this->handler()->getRecentErrorCount($this->plugin));
		self::assertTrue($this->plugin->isEnabled());
	}

	public function testNoHandlerInstalledPropagates() : void{
		PluginFaultHandler::setInstance(null);
		$this->registerFailingHandler();

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("plugin failure");
		(new TestChildEvent())->call();
	}

	public function testCrashModePropagates() : void{
		$this->installHandler(crash: true);
		$this->registerFailingHandler();

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("plugin failure");
		(new TestChildEvent())->call();
	}

	public function testControlFlowExceptionPropagates() : void{
		$this->pluginManager->registerEvent(TestChildEvent::class, function(TestChildEvent $event) : void{
			throw new PacketHandlingException("bad packet");
		}, EventPriority::NORMAL, $this->plugin);

		$this->expectException(PacketHandlingException::class);
		(new TestChildEvent())->call();
	}

	public function testCoreFailureIsNotAttributed() : void{
		$e = new \RuntimeException("core failure");
		self::assertNull($this->handler()->attribute($e));
		self::assertNull($this->handler()->attribute($e, null, function() : void{}));

		$this->expectExceptionObject($e);
		PluginFaultHandler::isolate($e, null, "test");
	}

	public function testAttributionByOriginAndTrace() : void{
		self::assertSame($this->plugin, $this->handler()->attribute(new \RuntimeException("x"), null, FaultyCode::noArgs()));
		self::assertSame($this->plugin, $this->handler()->attribute(new \RuntimeException("x"), null, new FaultyCommandExecutor()));

		try{
			FaultyCode::callThrough(function() : void{
				throw new \RuntimeException("thrown by core code called by the plugin");
			});
			self::fail("Expected exception");
		}catch(\RuntimeException $e){
			self::assertSame($this->plugin, $this->handler()->attribute($e));
		}

		try{
			(FaultyCode::noArgs())();
			self::fail("Expected exception");
		}catch(\RuntimeException $e){
			//wrapped by core code: the previous exception is checked too
			self::assertSame($this->plugin, $this->handler()->attribute(new \LogicException("wrapper", 0, $e)));
		}
	}

	public function testScriptPluginAttribution() : void{
		//script plugins are a single .php file: their "plugin file" is that file
		$scriptPlugin = FaultyTestPlugin::create($this->server, $this->createMock(PluginLoader::class), $this->createMock(ResourceProvider::class), "ScriptPlugin", __DIR__ . "/faulty/FaultyCode.php");
		$handler = new PluginFaultHandler($this->logger, fn() : array => ["ScriptPlugin" => $scriptPlugin], function(Plugin $plugin) : void{});

		self::assertSame($scriptPlugin, $handler->attribute(new \RuntimeException("x"), null, FaultyCode::noArgs()));
		self::assertNull($handler->attribute(new \RuntimeException("x"), null, new FaultyCommandExecutor()), "Other files of the same directory don't belong to a script plugin");
	}

	public function testPluginErrorEventCanSuppressLog() : void{
		$events = [];
		$this->pluginManager->registerEvent(PluginErrorEvent::class, function(PluginErrorEvent $event) use (&$events) : void{
			$events[] = $event;
			$event->cancel();
		}, EventPriority::NORMAL, $this->plugin);
		$this->registerFailingHandler();

		(new TestChildEvent())->call();

		self::assertCount(1, $events);
		self::assertSame($this->plugin, $events[0]->getPlugin());
		self::assertSame("plugin failure", $events[0]->getThrowable()->getMessage());
		self::assertStringContainsString("event handler", $events[0]->getContext());
		self::assertSame(1, $events[0]->getRecentErrorCount());
		self::assertFalse($events[0]->willPluginBeDisabled());
		self::assertCount(0, $this->logger->messages(\LogLevel::ERROR), "Cancelling the event should suppress the log");
		self::assertSame(1, $this->handler()->getRecentErrorCount($this->plugin), "Suppressed failures still count");
	}

	public function testFailingPluginErrorEventHandlerDoesNotRecurse() : void{
		$calls = 0;
		$this->pluginManager->registerEvent(PluginErrorEvent::class, function(PluginErrorEvent $event) use (&$calls) : void{
			$calls++;
			throw new \RuntimeException("error handler failure");
		}, EventPriority::NORMAL, $this->plugin);
		$this->registerFailingHandler();

		(new TestChildEvent())->call();

		self::assertSame(1, $calls);
		self::assertCount(2, $this->logger->messages(\LogLevel::ERROR));
	}

	public function testDisableAfterErrorsIsDeferred() : void{
		$this->installHandler(disableAfterErrors: 3);
		$this->registerFailingHandler();

		for($i = 1; $i <= 2; ++$i){
			(new TestChildEvent())->call();
		}
		self::assertFalse($this->handler()->isPendingDisable($this->plugin));
		self::assertSame(2, $this->laterCalls);

		(new TestChildEvent())->call(); //3rd failure
		self::assertTrue($this->handler()->isPendingDisable($this->plugin));
		self::assertSame(2, $this->laterCalls, "The plugin's remaining handlers must be skipped once it is pending disable");
		self::assertTrue($this->plugin->isEnabled(), "The plugin must not be disabled while its code is on the stack");
		self::assertCount(1, $this->logger->messages(\LogLevel::CRITICAL, "Disabling plugin FaultyTest v1.2.3 due to an unhandled exception in event handler"));

		(new TestChildEvent())->call();
		self::assertSame(2, $this->laterCalls);
		self::assertSame(3, $this->handler()->getRecentErrorCount($this->plugin), "Pending plugins' handlers are not called at all");

		$this->handler()->processPendingDisables();
		self::assertSame(["FaultyTest"], $this->disabled);
		self::assertFalse($this->plugin->isEnabled());
		self::assertFalse($this->handler()->isPendingDisable($this->plugin));
		self::assertSame(1, $this->plugin->disableCalls);

		//its handlers were unregistered by PluginManager::disablePlugin()
		(new TestChildEvent())->call();
		self::assertSame(2, $this->laterCalls);
	}

	public function testDefaultPolicyIsTenErrorsPerMinute() : void{
		$this->installHandler();
		$this->registerFailingHandler();

		for($i = 1; $i <= 9; ++$i){
			(new TestChildEvent())->call();
			$this->time += 1;
		}
		self::assertFalse($this->handler()->isPendingDisable($this->plugin));
		(new TestChildEvent())->call();
		self::assertTrue($this->handler()->isPendingDisable($this->plugin));
	}

	public function testErrorWindow() : void{
		$this->installHandler(disableAfterErrors: 3, window: 60);
		$this->registerFailingHandler();

		foreach([0, 30, 70] as $t){
			$this->time = 1000.0 + $t;
			(new TestChildEvent())->call();
		}
		self::assertFalse($this->handler()->isPendingDisable($this->plugin), "The first error is out of the window");
		self::assertSame(2, $this->handler()->getRecentErrorCount($this->plugin));

		$this->time = 1080.0;
		(new TestChildEvent())->call();
		self::assertTrue($this->handler()->isPendingDisable($this->plugin));
	}

	public function testZeroThresholdNeverDisables() : void{
		$this->installHandler(disableAfterErrors: 0);
		$this->registerFailingHandler();

		for($i = 0; $i < 50; ++$i){
			(new TestChildEvent())->call();
		}
		self::assertFalse($this->handler()->isPendingDisable($this->plugin));
		self::assertSame(50, $this->laterCalls);
	}

	public function testDisablePluginExceptionDisablesPlugin() : void{
		$this->pluginManager->registerEvent(TestChildEvent::class, function(TestChildEvent $event) : void{
			throw new DisablePluginException();
		}, EventPriority::NORMAL, $this->plugin);

		(new TestChildEvent())->call();
		self::assertTrue($this->handler()->isPendingDisable($this->plugin));
		self::assertCount(0, $this->logger->messages(\LogLevel::ERROR));
		$this->handler()->processPendingDisables();
		self::assertFalse($this->plugin->isEnabled());
	}

	public function testFailuresOfDisabledPluginAreQuietened() : void{
		$this->installHandler(disableAfterErrors: 1);
		PluginFaultHandler::isolate(new \RuntimeException("first"), $this->plugin, "test");
		$this->handler()->processPendingDisables();
		self::assertFalse($this->plugin->isEnabled());
		self::assertCount(1, $this->logger->messages(\LogLevel::ERROR));

		//e.g. a custom block left behind by the disabled plugin keeps failing
		PluginFaultHandler::isolate(new \RuntimeException("second"), $this->plugin, "test");
		PluginFaultHandler::isolate(new \RuntimeException("third"), $this->plugin, "test");
		self::assertCount(2, $this->logger->messages(\LogLevel::ERROR));
		self::assertCount(1, $this->logger->messages(\LogLevel::DEBUG, "third"));
	}

	public function testRepeatingTaskFailureCancelsTask() : void{
		$handler = $this->plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask(FaultyCode::noArgs()), 1);
		self::assertSame($this->plugin, $handler->getOwnerPlugin());

		$this->plugin->getScheduler()->mainThreadHeartbeat(1);
		self::assertTrue($handler->isCancelled());
		self::assertCount(1, $this->logger->messages(\LogLevel::ERROR, "[FaultyTest v1.2.3] Unhandled exception in task"));

		$this->plugin->getScheduler()->mainThreadHeartbeat(2);
		$this->plugin->getScheduler()->mainThreadHeartbeat(3);
		self::assertCount(1, $this->logger->messages(\LogLevel::ERROR));
	}

	public function testTaskWithoutOwnerAttributedByTrace() : void{
		$scheduler = new TaskScheduler();
		$handler = $scheduler->scheduleTask(new ClosureTask(function() : void{
			FaultyCode::callThrough(function() : void{
				throw new \RuntimeException("deep failure");
			});
		}));
		$scheduler->mainThreadHeartbeat(1);
		self::assertTrue($handler->isCancelled());
		self::assertCount(1, $this->logger->messages(\LogLevel::ERROR, "deep failure"));
		$scheduler->shutdown();
	}

	public function testCoreTaskFailurePropagates() : void{
		$scheduler = new TaskScheduler();
		$scheduler->scheduleTask(new ClosureTask(function() : void{
			throw new \RuntimeException("core task failure");
		}));
		try{
			$this->expectExceptionMessage("core task failure");
			$scheduler->mainThreadHeartbeat(1);
		}finally{
			$scheduler->shutdown();
		}
	}

	/**
	 * @phpstan-param list<string> $messages
	 */
	private function createSender(array &$messages) : CommandSender{
		$sender = $this->createMock(CommandSender::class);
		$sender->method("hasPermission")->willReturn(true);
		$sender->method("getLanguage")->willReturn($this->createMock(Language::class));
		$sender->method("sendMessage")->willReturnCallback(function(Translatable|string $message) use (&$messages) : void{
			$messages[] = $message instanceof Translatable ? $message->getText() : $message;
		});
		return $sender;
	}

	private function createCommandMap() : SimpleCommandMap{
		$permissionManager = PermissionManager::getInstance();
		if($permissionManager->getPermission(DefaultPermissions::ROOT_USER) === null){
			DefaultPermissions::registerCorePermissions(); //needed by the default commands
		}
		if($permissionManager->getPermission(self::PERMISSION) === null){
			$permissionManager->addPermission(new Permission(self::PERMISSION));
		}
		return new SimpleCommandMap($this->server);
	}

	public function testPluginCommandFailure() : void{
		$map = $this->createCommandMap();
		$command = new PluginCommand("faultycmd", $this->plugin, new FaultyCommandExecutor());
		$command->setPermission(self::PERMISSION);
		$map->register("faulty", $command);

		$messages = [];
		self::assertTrue($map->dispatch($this->createSender($messages), "faultycmd arg"));
		self::assertCount(1, $messages);
		self::assertStringContainsString("An unknown error occurred while attempting to perform this command", $messages[0]);
		self::assertCount(1, $this->logger->messages(\LogLevel::ERROR, "[FaultyTest v1.2.3] Unhandled exception in command /faultycmd"));
	}

	public function testCoreCommandFailurePropagates() : void{
		$map = $this->createCommandMap();
		$command = new class("corecmd") extends Command{
			public function execute(CommandSender $sender, string $commandLabel, array $args){
				throw new \RuntimeException("core command failure");
			}
		};
		$command->setPermission(self::PERMISSION);
		$map->register("test", $command);

		$messages = [];
		$this->expectExceptionMessage("core command failure");
		$map->dispatch($this->createSender($messages), "corecmd");
	}

	public function testPromiseCallbackFailure() : void{
		/** @phpstan-var PromiseResolver<int> $resolver */
		$resolver = new PromiseResolver();
		$results = [];
		$resolver->getPromise()->onCompletion(FaultyCode::oneArg(), function() : void{});
		$resolver->getPromise()->onCompletion(function(int $value) use (&$results) : void{
			$results[] = $value;
		}, function() : void{});

		$resolver->resolve(42);
		self::assertSame([42], $results, "Callbacks after the failing one should still be called");
		self::assertCount(1, $this->logger->messages(\LogLevel::ERROR, "promise success callback"));
	}

	public function testCorePromiseCallbackFailurePropagates() : void{
		/** @phpstan-var PromiseResolver<int> $resolver */
		$resolver = new PromiseResolver();
		$resolver->getPromise()->onCompletion(function(int $value) : void{
			throw new \RuntimeException("core callback failure");
		}, function() : void{});

		$this->expectExceptionMessage("core callback failure");
		$resolver->resolve(1);
	}

	public function testOnEnableFailureDisablesPlugin() : void{
		$plugin = FaultyTestPlugin::create($this->server, $this->createMock(PluginLoader::class), $this->createMock(ResourceProvider::class), "FaultyTest2");
		$plugin->failOnEnable = true;
		$this->installHandlerFor($plugin);

		self::assertFalse($this->pluginManager->enablePlugin($plugin));
		self::assertFalse($plugin->isEnabled());
		self::assertSame(1, $plugin->disableCalls);
		self::assertCount(1, $this->logger->messages(\LogLevel::ERROR, "[FaultyTest2 v1.2.3] Unhandled exception in onEnable()"));
	}

	public function testOnDisableFailureIsLoggedAndCleanupHappens() : void{
		$plugin = FaultyTestPlugin::create($this->server, $this->createMock(PluginLoader::class), $this->createMock(ResourceProvider::class), "FaultyTest2");
		$plugin->failOnDisable = true;
		$this->installHandlerFor($plugin);
		self::assertTrue($this->pluginManager->enablePlugin($plugin));
		$task = $plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{}), 1);

		$this->pluginManager->disablePlugin($plugin);
		self::assertFalse($plugin->isEnabled());
		self::assertTrue($task->isCancelled(), "The scheduler must still be shut down");
		self::assertCount(1, $this->logger->messages(\LogLevel::ERROR, "[FaultyTest2 v1.2.3] Unhandled exception in onDisable()"));
	}

	public function testOnEnableFailureInCrashModePropagates() : void{
		$plugin = FaultyTestPlugin::create($this->server, $this->createMock(PluginLoader::class), $this->createMock(ResourceProvider::class), "FaultyTest2");
		$plugin->failOnEnable = true;
		$this->installHandler(crash: true);

		$this->expectExceptionMessage("onEnable failure");
		$this->pluginManager->enablePlugin($plugin);
	}

	private function installHandlerFor(Plugin $plugin) : void{
		PluginFaultHandler::setInstance(new PluginFaultHandler(
			$this->logger,
			fn() : array => [$plugin->getName() => $plugin, $this->plugin->getName() => $this->plugin],
			fn(Plugin $p) => $this->pluginManager->disablePlugin($p),
			clock: fn() : float => $this->time
		));
	}

	public function testPendingPluginTasksAreSkipped() : void{
		$this->installHandler(disableAfterErrors: 1);
		$runs = 0;
		$this->plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() use (&$runs) : void{
			$runs++;
		}), 1);
		PluginFaultHandler::isolate(new \RuntimeException("x"), $this->plugin, "test");
		self::assertTrue($this->handler()->isPendingDisable($this->plugin));

		$this->plugin->getScheduler()->mainThreadHeartbeat(1);
		self::assertSame(0, $runs);
		self::assertCount(1, $this->logger->messages(\LogLevel::CRITICAL));
	}
}
