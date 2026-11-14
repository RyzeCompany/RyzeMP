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

namespace pocketmine;

use PHPUnit\Framework\TestCase;
use pocketmine\entity\Entity;
use pocketmine\player\Player;
use pocketmine\scheduler\AsyncPool;
use pocketmine\timings\Timings;
use pocketmine\utils\Config;
use pocketmine\world\redstone\NullThreadSafeLogger;
use pocketmine\world\WorldManager;
use function ini_get;
use function ini_set;
use function intdiv;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class MemoryManagerLowMemoryTest extends TestCase{
	/** @var string[] */
	private array $tempFiles = [];
	private string|false $memoryLimit = false;

	protected function setUp() : void{
		$this->memoryLimit = ini_get("memory_limit"); //MemoryManager sets it from main-hard-limit
		Timings::init();
	}

	protected function tearDown() : void{
		if($this->memoryLimit !== false){
			ini_set("memory_limit", $this->memoryLimit);
		}
		foreach($this->tempFiles as $file){
			@unlink($file);
		}
	}

	/**
	 * @param mixed[]  $memory
	 * @param Player[] $players
	 */
	private function manager(array $memory, array $players = []) : MemoryManager{
		$ymlPath = sys_get_temp_dir() . "/pm-memory-" . uniqid() . ".yml";
		$propertiesPath = sys_get_temp_dir() . "/pm-memory-" . uniqid() . ".properties";
		$this->tempFiles[] = $ymlPath;
		$this->tempFiles[] = $propertiesPath;
		$config = new ServerConfigGroup(
			new Config($ymlPath, Config::YAML, ["memory" => $memory]),
			new Config($propertiesPath, Config::PROPERTIES, [])
		);
		$server = $this->createMock(Server::class);
		$server->method("getLogger")->willReturn(new NullThreadSafeLogger());
		$server->method("getConfigGroup")->willReturn($config);
		$server->method("getViewDistance")->willReturn(8);
		$server->method("getOnlinePlayers")->willReturn($players);
		$worldManager = $this->createMock(WorldManager::class);
		$worldManager->method("getWorlds")->willReturn([]);
		$server->method("getWorldManager")->willReturn($worldManager);
		$pool = $this->createMock(AsyncPool::class);
		$pool->method("getRunningWorkers")->willReturn([]);
		$server->method("getAsyncPool")->willReturn($pool);
		return new MemoryManager($server);
	}

	private static function mainLimit(MemoryManager $manager) : int{
		$value = (new \ReflectionMethod(MemoryManager::class, "getMainLimit"))->invoke($manager);
		self::assertIsInt($value);
		return $value;
	}

	public function testAutoLimitFromHardLimit() : void{
		self::assertSame(intdiv(1024 * 1024 * 1024 * 70, 100), self::mainLimit($this->manager(["main-limit" => 0, "main-hard-limit" => 1024])));
		self::assertSame(intdiv(2048 * 1024 * 1024 * 85, 100), self::mainLimit($this->manager(["main-limit" => 0, "main-hard-limit" => 2048, "auto-main-limit-percent" => 85])));
		self::assertSame(0, self::mainLimit($this->manager(["main-limit" => 0, "main-hard-limit" => 1024, "auto-main-limit-percent" => 0])), "disabled");
		self::assertSame(0, self::mainLimit($this->manager(["main-limit" => 0, "main-hard-limit" => 0])), "no hard limit");
		self::assertSame(500 * 1024 * 1024, self::mainLimit($this->manager(["main-limit" => 500, "main-hard-limit" => 1024])), "main-limit wins");
	}

	public function testLowMemoryCapsViewDistanceAndRefreshesPlayers() : void{
		$player = $this->createMock(Player::class);
		$player->expects(self::once())->method("refreshViewDistance");
		//keep the mock from running Player::onDispose() when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		//1 MB: always over it
		$manager = $this->manager(["main-limit" => 1, "main-hard-limit" => 0, "check-rate" => 1, "continuous-trigger-rate" => 1000, "max-chunks" => ["chunk-radius" => 4]], [$player]);
		self::assertSame(8, $manager->getViewDistance(8));
		self::assertFalse($manager->isLowMemory());

		$manager->check();
		self::assertTrue($manager->isLowMemory());
		self::assertSame(6, $manager->getViewDistance(8));
		self::assertSame(5, $manager->getViewDistance(5));

		//no further step before the continuous trigger is due
		$manager->check();
		self::assertSame(6, $manager->getViewDistance(8));
	}
}
