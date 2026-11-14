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
use pocketmine\plugin\faulty\FaultyCode;
use pocketmine\plugin\faulty\FaultyTestPlugin;
use pocketmine\Server;
use pocketmine\TimeTrackingSleeperHandler;
use pocketmine\timings\TimingsHandler;

final class SleeperNotifierFaultIsolationTest extends TestCase{
	private CapturingLogger $logger;
	private FaultyTestPlugin $plugin;
	private TimeTrackingSleeperHandler $sleeper;

	protected function setUp() : void{
		$server = $this->createMock(Server::class);
		$server->method("getLogger")->willReturn(new NullThreadSafeLogger());
		$this->plugin = FaultyTestPlugin::create($server, $this->createMock(PluginLoader::class), $this->createMock(ResourceProvider::class));
		$this->plugin->onEnableStateChange(true);
		$this->logger = new CapturingLogger();
		$this->sleeper = new TimeTrackingSleeperHandler(new TimingsHandler("test sleeper"));
		$this->installHandler(false);
	}

	protected function tearDown() : void{
		PluginFaultHandler::setInstance(null);
	}

	private function installHandler(bool $crash) : void{
		PluginFaultHandler::setInstance(new PluginFaultHandler(
			$this->logger,
			fn() : array => [$this->plugin->getName() => $this->plugin],
			function(Plugin $plugin) : void{},
			$crash
		));
	}

	/**
	 * @phpstan-param \Closure() : void $handler
	 */
	private function notify(\Closure $handler) : void{
		$this->sleeper->addNotifier($handler)->createNotifier()->wakeupSleeper();
	}

	public function testPluginNotifierFailureIsIsolated() : void{
		$laterCalls = 0;
		$this->notify(FaultyCode::noArgs());
		$this->notify(function() use (&$laterCalls) : void{
			$laterCalls++;
		});

		$this->sleeper->processNotifications();

		self::assertSame(1, $laterCalls, "Other notifiers must still be processed");
		self::assertCount(1, $this->logger->messages(\LogLevel::ERROR, "[FaultyTest v1.2.3] Unhandled exception in sleeper notifier"));
	}

	public function testCoreFailureCalledFromPluginNotifierIsAttributedToPlugin() : void{
		//e.g. a database result callback which calls Player::getNetworkSession() for a player who already quit
		$this->notify(static function() : void{
			FaultyCode::callThrough(static function() : void{
				throw new \LogicException("Player is not connected");
			});
		});
		$this->sleeper->processNotifications();
		self::assertCount(1, $this->logger->messages(\LogLevel::ERROR, "[FaultyTest v1.2.3] Unhandled exception in sleeper notifier"));
	}

	public function testCoreNotifierFailureStillCrashes() : void{
		$this->notify(static function() : void{
			throw new \RuntimeException("core failure");
		});
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("core failure");
		$this->sleeper->processNotifications();
	}

	public function testCrashModePropagates() : void{
		$this->installHandler(true);
		$this->notify(FaultyCode::noArgs());
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("plugin failure");
		$this->sleeper->processNotifications();
	}
}
