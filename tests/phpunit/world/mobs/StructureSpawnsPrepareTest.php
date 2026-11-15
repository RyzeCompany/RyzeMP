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

namespace pocketmine\world\mobs;

use pocketmine\world\mobs\spawn\StructureSpawnProvider;
use pocketmine\world\mobs\spawn\StructureSpawns;
use pocketmine\world\redstone\RedstoneTestCase;
use pocketmine\world\World;

/**
 * Structure spawn providers set up their per-world data when the world loads (the vanilla one builds a whole world
 * generator), not during the first spawn attempt of a running server.
 */
final class StructureSpawnsPrepareTest extends RedstoneTestCase{
	private StructureSpawnProvider $provider;
	/** @var list<array{string, int, int}> */
	public array $calls = [];

	protected function setUp() : void{
		$test = $this;
		$this->provider = new class($test) implements StructureSpawnProvider{
			public function __construct(private StructureSpawnsPrepareTest $test){}

			public function getSpawnAreas(World $world, int $x, int $z) : array{
				$this->test->calls[] = [$world->getFolderName(), $x, $z];
				return [];
			}
		};
		StructureSpawns::register($this->provider);
		parent::setUp();
	}

	protected function tearDown() : void{
		StructureSpawns::unregister($this->provider);
		parent::tearDown();
	}

	public function testProvidersArePreparedWhenTheWorldLoads() : void{
		//the test world's spawn is 0, 1, 0
		self::assertSame([["redstone_test", 0, 0]], $this->calls);
	}
}
