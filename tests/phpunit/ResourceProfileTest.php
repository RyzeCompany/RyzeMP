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
use pocketmine\scheduler\AsyncPool;
use pocketmine\thread\log\AttachableThreadSafeLogger;
use pocketmine\timings\Timings;
use pocketmine\utils\Config;
use pocketmine\world\generator\vanilla\native\VanillaNativeBackend;
use pocketmine\world\redstone\NullThreadSafeLogger;
use pocketmine\world\WorldManager;
use pocketmine\YmlServerProperties as Yml;
use Symfony\Component\Filesystem\Path;
use function copy;
use function ini_get;
use function ini_set;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class ResourceProfileTest extends TestCase{
	/** @var string[] */
	private array $tempFiles = [];
	private string|false $memoryLimit = false;

	protected function setUp() : void{
		$this->memoryLimit = ini_get("memory_limit"); //MemoryManager sets it from main-hard-limit
		Timings::init();
	}

	protected function tearDown() : void{
		ActiveResourceProfile::set(ResourceProfile::PERFORMANCE);
		if($this->memoryLimit !== false){
			ini_set("memory_limit", $this->memoryLimit);
		}
		foreach($this->tempFiles as $file){
			@unlink($file);
		}
	}

	/**
	 * @param mixed[] $yml
	 * @phpstan-param array<string, mixed> $yml
	 */
	private function config(array $yml) : ServerConfigGroup{
		$ymlPath = sys_get_temp_dir() . "/pm-profile-" . uniqid() . ".yml";
		$propertiesPath = sys_get_temp_dir() . "/pm-profile-" . uniqid() . ".properties";
		$this->tempFiles[] = $ymlPath;
		$this->tempFiles[] = $propertiesPath;
		return new ServerConfigGroup(
			new Config($ymlPath, Config::YAML, $yml),
			new Config($propertiesPath, Config::PROPERTIES, [])
		);
	}

	public function testParsing() : void{
		self::assertSame(ResourceProfile::PERFORMANCE, ResourceProfile::fromString("performance"));
		self::assertSame(ResourceProfile::ECONOMY, ResourceProfile::fromString("economy"));
		self::assertSame(ResourceProfile::PERFORMANCE, ResourceProfile::fromString("rendimiento"));
		self::assertSame(ResourceProfile::ECONOMY, ResourceProfile::fromString("ahorro"));
		self::assertSame(ResourceProfile::ECONOMY, ResourceProfile::fromString("  Economy "));
		self::assertSame(ResourceProfile::ECONOMY, ResourceProfile::fromString("AHORRO"));
		self::assertNull(ResourceProfile::fromString(""));
		self::assertNull(ResourceProfile::fromString("fast"));
	}

	public function testDefaultIsPerformance() : void{
		$server = (new \ReflectionClass(Server::class))->newInstanceWithoutConstructor();
		self::assertSame(ResourceProfile::PERFORMANCE, $server->getResourceProfile());
		ActiveResourceProfile::set(ResourceProfile::ECONOMY);
		self::assertSame(ResourceProfile::ECONOMY, $server->getResourceProfile(), "the server reports the active profile");
	}

	/**
	 * A setting still at the value shipped in pocketmine.yml follows the profile, so the shipped values must be the
	 * performance values.
	 */
	public function testShippedValuesArePerformanceValues() : void{
		$path = Path::join(sys_get_temp_dir(), "pm-profile-shipped-" . uniqid() . ".yml");
		$this->tempFiles[] = $path;
		self::assertTrue(copy(Path::join(\pocketmine\RESOURCE_PATH, "pocketmine.yml"), $path));
		$config = new Config($path, Config::YAML);
		$p = ResourceProfile::PERFORMANCE;
		self::assertSame($p->getAsyncWorkerIdleTimeout(), $config->getNested(Yml::MEMORY_ASYNC_WORKER_IDLE_TIMEOUT));
		self::assertSame($p->getChunkGenerationWorkers(), $config->getNested(Yml::CHUNK_GENERATION_MAX_WORKERS));
		self::assertSame("auto", $config->getNested(Yml::SETTINGS_ASYNC_WORKERS));
	}

	public function testResolveIntProfileOrOwnerValue() : void{
		$key = Yml::MEMORY_ASYNC_WORKER_IDLE_TIMEOUT;
		$eco = ResourceProfile::ECONOMY;
		$perf = ResourceProfile::PERFORMANCE;
		//missing: the profile
		self::assertSame(60, $eco->resolveInt($this->config([]), $key, 300, $eco->getAsyncWorkerIdleTimeout()));
		self::assertSame(300, $perf->resolveInt($this->config([]), $key, 300, $perf->getAsyncWorkerIdleTimeout()));
		//shipped value: the profile
		$shipped = $this->config(["memory" => ["async-worker-idle-timeout" => 300]]);
		self::assertSame(60, $eco->resolveInt($shipped, $key, 300, $eco->getAsyncWorkerIdleTimeout()));
		//anything else: the owner's value wins, in both profiles
		$owner = $this->config(["memory" => ["async-worker-idle-timeout" => 120]]);
		self::assertSame(120, $eco->resolveInt($owner, $key, 300, $eco->getAsyncWorkerIdleTimeout()));
		self::assertSame(120, $perf->resolveInt($owner, $key, 300, $perf->getAsyncWorkerIdleTimeout()));
		$zero = $this->config(["memory" => ["async-worker-idle-timeout" => 0]]);
		self::assertSame(0, $eco->resolveInt($zero, $key, 300, $eco->getAsyncWorkerIdleTimeout()));
		//a section instead of a number: the profile
		$broken = $this->config(["memory" => ["async-worker-idle-timeout" => ["x" => 1]]]);
		self::assertSame(60, $eco->resolveInt($broken, $key, 300, $eco->getAsyncWorkerIdleTimeout()));
	}

	public function testEconomyNeverAboveTheCostOfPerformance() : void{
		$p = ResourceProfile::PERFORMANCE;
		$e = ResourceProfile::ECONOMY;
		self::assertLessThan($p->getChunkUnloadDelay(), $e->getChunkUnloadDelay());
		self::assertLessThan($p->getLevelDBWriteBufferSize(), $e->getLevelDBWriteBufferSize());
		self::assertLessThan($p->getMaxAutoAsyncWorkers(), $e->getMaxAutoAsyncWorkers());
		self::assertLessThan($p->getChunkGenerationWorkers(), $e->getChunkGenerationWorkers());
		self::assertGreaterThanOrEqual(1, $e->getChunkGenerationWorkers());
		self::assertLessThan($p->getAsyncWorkerIdleTimeout(), $e->getAsyncWorkerIdleTimeout());
	}

	public function testAutoAsyncWorkers() : void{
		self::assertSame(Server::MAX_AUTO_ASYNC_WORKERS, ResourceProfile::PERFORMANCE->getMaxAutoAsyncWorkers());
		self::assertSame(4, ResourceProfile::PERFORMANCE->getAutoAsyncWorkerCount(16));
		self::assertSame(2, ResourceProfile::PERFORMANCE->getAutoAsyncWorkerCount(4));
		self::assertSame(3, ResourceProfile::PERFORMANCE->getAutoAsyncWorkerCount(5));
		self::assertSame(2, ResourceProfile::PERFORMANCE->getAutoAsyncWorkerCount(1));
		self::assertSame(2, ResourceProfile::ECONOMY->getAutoAsyncWorkerCount(16));
		self::assertSame(2, ResourceProfile::ECONOMY->getAutoAsyncWorkerCount(1));
	}

	public function testVanillagenEnvironment() : void{
		$env = VanillaNativeBackend::buildEnvironment(["PATH" => "x"], "tok", ResourceProfile::PERFORMANCE->getVanillagenGoGc());
		self::assertSame(["PATH" => "x", VanillaNativeBackend::ENV_TOKEN => "tok"], $env, "performance: Go's default");
		$env = VanillaNativeBackend::buildEnvironment(["PATH" => "x"], "tok", ResourceProfile::ECONOMY->getVanillagenGoGc());
		self::assertSame("50", $env["GOGC"] ?? null);
		$env = VanillaNativeBackend::buildEnvironment(["GOGC" => "200"], "tok", ResourceProfile::ECONOMY->getVanillagenGoGc());
		self::assertSame("200", $env["GOGC"], "the owner's GOGC is kept");
	}

	/**
	 * @param mixed[] $yml
	 * @phpstan-param array<string, mixed> $yml
	 */
	private function memoryManager(ResourceProfile $profile, array $yml) : MemoryManager{
		$server = $this->createMock(Server::class);
		$server->method("getLogger")->willReturn(new NullThreadSafeLogger());
		$server->method("getConfigGroup")->willReturn($this->config($yml));
		ActiveResourceProfile::set($profile);
		$worldManager = $this->createMock(WorldManager::class);
		$worldManager->method("getWorlds")->willReturn([]);
		$server->method("getWorldManager")->willReturn($worldManager);
		$pool = $this->createMock(AsyncPool::class);
		$pool->method("getRunningWorkers")->willReturn([]);
		$server->method("getAsyncPool")->willReturn($pool);
		return new MemoryManager($server);
	}

	/**
	 * @return int[]
	 * @phpstan-return array{idle: int, spare: int, min: int, max: int}
	 */
	private static function memorySettings(MemoryManager $manager) : array{
		$gc = (new \ReflectionProperty(MemoryManager::class, "cycleGcManager"))->getValue($manager);
		self::assertInstanceOf(GarbageCollectorManager::class, $gc);
		$idle = (new \ReflectionProperty(MemoryManager::class, "idleWorkerTimeout"))->getValue($manager);
		$spare = (new \ReflectionProperty(MemoryManager::class, "cycleThresholdSpareTime"))->getValue($manager);
		$min = (new \ReflectionProperty(GarbageCollectorManager::class, "minThreshold"))->getValue($gc);
		$max = (new \ReflectionProperty(GarbageCollectorManager::class, "maxThreshold"))->getValue($gc);
		self::assertIsInt($idle);
		self::assertIsInt($spare);
		self::assertIsInt($min);
		self::assertIsInt($max);
		return ["idle" => $idle, "spare" => $spare, "min" => $min, "max" => $max];
	}

	public function testMemoryManagerReadsProfile() : void{
		$shipped = ["memory" => [
			"async-worker-idle-timeout" => 300,
			"garbage-collection" => ["cycle-threshold-min" => 1_000_000, "cycle-threshold-max" => 4_000_000, "cycle-threshold-spare-time" => 200_000]
		]];
		self::assertSame(["idle" => 300, "spare" => 200_000, "min" => 1_000_000, "max" => 4_000_000], self::memorySettings($this->memoryManager(ResourceProfile::PERFORMANCE, $shipped)));
		//the garbage collection thresholds are the same in both profiles (lower ones only cost CPU)
		self::assertSame(["idle" => 60, "spare" => 200_000, "min" => 1_000_000, "max" => 4_000_000], self::memorySettings($this->memoryManager(ResourceProfile::ECONOMY, $shipped)));
		self::assertSame(["idle" => 60, "spare" => 200_000, "min" => 1_000_000, "max" => 4_000_000], self::memorySettings($this->memoryManager(ResourceProfile::ECONOMY, [])), "missing settings");

		$owner = ["memory" => [
			"async-worker-idle-timeout" => 900,
			"garbage-collection" => ["cycle-threshold-min" => 500_000, "cycle-threshold-max" => 2_000_000, "cycle-threshold-spare-time" => 0]
		]];
		self::assertSame(["idle" => 900, "spare" => 0, "min" => 500_000, "max" => 2_000_000], self::memorySettings($this->memoryManager(ResourceProfile::ECONOMY, $owner)), "the owner's values win");
		self::assertSame(["idle" => 900, "spare" => 0, "min" => 500_000, "max" => 2_000_000], self::memorySettings($this->memoryManager(ResourceProfile::PERFORMANCE, $owner)));
	}

	public function testServerReadsProfileFromServerProperties() : void{
		$read = function(string $value) : ResourceProfile{
			$propertiesPath = sys_get_temp_dir() . "/pm-profile-" . uniqid() . ".properties";
			$ymlPath = sys_get_temp_dir() . "/pm-profile-" . uniqid() . ".yml";
			$this->tempFiles[] = $propertiesPath;
			$this->tempFiles[] = $ymlPath;
			$server = (new \ReflectionClass(Server::class))->newInstanceWithoutConstructor();
			(new \ReflectionProperty(Server::class, "configGroup"))->setValue($server, new ServerConfigGroup(
				new Config($ymlPath, Config::YAML, []),
				new Config($propertiesPath, Config::PROPERTIES, $value === "" ? [] : [ServerProperties::PERFORMANCE_PROFILE => $value])
			));
			$logger = new class extends AttachableThreadSafeLogger{
				public int $warnings = 0;
				public int $infos = 0;

				public function emergency($message){}

				public function alert($message){}

				public function critical($message){}

				public function error($message){}

				public function warning($message){
					$this->warnings++;
				}

				public function notice($message){}

				public function info($message){
					$this->infos++;
				}

				public function debug($message){}

				public function log($level, $message){}

				public function logException(\Throwable $e, $trace = null){}
			};
			(new \ReflectionProperty(Server::class, "logger"))->setValue($server, $logger);
			$profile = (new \ReflectionMethod(Server::class, "readResourceProfile"))->invoke($server);
			self::assertInstanceOf(ResourceProfile::class, $profile);
			self::assertSame($value === "turbo" ? 1 : 0, $logger->warnings);
			self::assertSame(1, $logger->infos, "the active profile is logged in one line");
			return $profile;
		};
		self::assertSame(ResourceProfile::PERFORMANCE, $read(""), "missing");
		self::assertSame(ResourceProfile::PERFORMANCE, $read("performance"));
		self::assertSame(ResourceProfile::ECONOMY, $read("economy"));
		self::assertSame(ResourceProfile::ECONOMY, $read("ahorro"));
		self::assertSame(ResourceProfile::PERFORMANCE, $read("rendimiento"));
		self::assertSame(ResourceProfile::PERFORMANCE, $read("turbo"), "unknown: warning and performance");
	}
}
