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

namespace pocketmine\world\dimension;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\lang\Language;
use pocketmine\math\Vector3;
use pocketmine\scheduler\AsyncPool;
use pocketmine\Server;
use pocketmine\ServerConfigGroup;
use pocketmine\utils\Config;
use pocketmine\world\ChunkLoader;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\WorldData;
use pocketmine\world\format\io\WritableWorldProvider;
use pocketmine\world\redstone\NullThreadSafeLogger;
use pocketmine\world\World;
use function file_exists;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * A real {@link World} of a given dimension backed by in-memory chunks, ticked by hand.
 */
abstract class DimensionWorldTestCase extends TestCase{
	/** @phpstan-var Server&MockObject */
	protected Server $server;
	protected World $world;
	protected int $tick = 0;
	protected string $registryPath;

	/** @var string[] */
	private array $tempFiles = [];

	protected function getDimension() : Dimension{
		return Dimension::OVERWORLD;
	}

	protected function setUp() : void{
		$ymlPath = sys_get_temp_dir() . "/pm-dimension-test-" . uniqid() . ".yml";
		$propertiesPath = sys_get_temp_dir() . "/pm-dimension-test-" . uniqid() . ".properties";
		$this->registryPath = sys_get_temp_dir() . "/pm-dimension-test-" . uniqid() . ".json";
		$this->tempFiles = [$ymlPath, $propertiesPath, $this->registryPath];
		$config = new ServerConfigGroup(
			//test worlds have no players: every entity is ticked (no simulation distance)
			new Config($ymlPath, Config::YAML, ["redstone" => ["enabled" => false], "chunk-ticking" => ["simulation-distance" => 0]]),
			new Config($propertiesPath, Config::PROPERTIES, [])
		);
		NetherPortalRegistry::setInstance(new NetherPortalRegistry($this->registryPath));

		$server = $this->createMock(Server::class);
		$server->method("getLogger")->willReturn(new NullThreadSafeLogger());
		$server->method("getLanguage")->willReturn($this->createMock(Language::class));
		$server->method("getConfigGroup")->willReturn($config);
		$server->method("getViewDistance")->willReturn(8);
		$server->method("getTick")->willReturnCallback(fn() => $this->tick);
		$this->server = $server;

		$this->world = $this->createWorld("dimension_test", $this->getDimension());
		$this->tick = 1;
	}

	/**
	 * Creates another world on the same server, with the same loaded chunks: x -3..7, z -3..3.
	 */
	protected function createWorld(string $name, Dimension $dimension) : World{
		$worldData = $this->createMock(WorldData::class);
		$worldData->method("getName")->willReturn($name);
		$worldData->method("getGenerator")->willReturn("flat");
		$worldData->method("getGeneratorOptions")->willReturn("");
		$worldData->method("getSpawn")->willReturn(new Vector3(0, 1, 0));

		$provider = $this->createMock(WritableWorldProvider::class);
		$provider->method("getWorldMinY")->willReturn(-64);
		$provider->method("getWorldMaxY")->willReturn(320);
		$provider->method("getWorldData")->willReturn($worldData);
		$provider->method("loadChunk")->willReturn(null);

		$world = new World($this->server, $name, $provider, $this->createMock(AsyncPool::class), $dimension);

		$loader = new class implements ChunkLoader{};
		for($cx = -3; $cx <= 7; ++$cx){
			for($cz = -3; $cz <= 3; ++$cz){
				$world->registerChunkLoader($loader, $cx, $cz, false);
				$world->setChunk($cx, $cz, new Chunk([], true));
			}
		}
		return $world;
	}

	protected function tearDown() : void{
		NetherPortalRegistry::reset();
		PortalTravel::reset();
		EndGatewayTravel::reset();
		foreach($this->tempFiles as $file){
			if(file_exists($file)){
				unlink($file);
			}
		}
	}

	protected function set(int $x, int $y, int $z, Block $block) : void{
		$this->world->setBlockAt($x, $y, $z, $block);
	}

	protected function get(int $x, int $y, int $z) : Block{
		return $this->world->getBlockAt($x, $y, $z);
	}

	protected function fill(int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ, Block $block) : void{
		for($x = $minX; $x <= $maxX; ++$x){
			for($y = $minY; $y <= $maxY; ++$y){
				for($z = $minZ; $z <= $maxZ; ++$z){
					$this->world->setBlockAt($x, $y, $z, $block, false);
				}
			}
		}
	}

	/**
	 * Builds an obsidian frame on the X axis with the given interior size; the interior's bottom-left block is at x,y,z.
	 */
	protected function buildFrameX(int $x, int $y, int $z, int $width, int $height, bool $corners = true) : void{
		$obsidian = VanillaBlocks::OBSIDIAN();
		for($w = -1; $w <= $width; ++$w){
			for($h = -1; $h <= $height; ++$h){
				$edgeW = $w === -1 || $w === $width;
				$edgeH = $h === -1 || $h === $height;
				if(($edgeW || $edgeH) && ($corners || !($edgeW && $edgeH))){
					$this->set($x + $w, $y + $h, $z, $obsidian);
				}
			}
		}
	}

	protected function runTicks(int $ticks) : void{
		for($i = 0; $i < $ticks; ++$i){
			$this->world->doTick(++$this->tick);
		}
	}
}
