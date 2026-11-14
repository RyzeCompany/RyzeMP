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

namespace pocketmine\world\map;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use function file_put_contents;
use function sys_get_temp_dir;
use function uniqid;

class MapDataStoreTest extends TestCase{
	private string $path;

	protected function setUp() : void{
		$this->path = Path::join(sys_get_temp_dir(), uniqid("pm_maps_", true));
	}

	protected function tearDown() : void{
		(new Filesystem())->remove($this->path);
	}

	public function testIdAllocationPersists() : void{
		$store = new MapDataStore($this->path);
		self::assertFalse($store->wasAllocated(1));
		$first = $store->allocateId();
		$second = $store->allocateId();
		self::assertSame(1, $first, "map ID 0 means no map, so the first ID must be 1");
		self::assertSame(2, $second);
		self::assertTrue($store->wasAllocated($second));
		self::assertFalse($store->wasAllocated(0));

		//a new store (server restart) continues after the last ID handed out, even if no map data was saved
		$reloaded = new MapDataStore($this->path);
		self::assertTrue($reloaded->wasAllocated($second));
		self::assertFalse($reloaded->wasAllocated($second + 1));
		self::assertSame(3, $reloaded->allocateId());
	}

	public function testCorruptedCounterContinuesAfterSavedMaps() : void{
		$store = new MapDataStore($this->path);
		$store->save(new MapData($store->allocateId(), "world", 0, 0));
		$store->save(new MapData($store->allocateId(), "world", 0, 0));
		file_put_contents(Path::join($this->path, "idcounts.json"), "{not json");
		self::assertSame(3, (new MapDataStore($this->path))->allocateId());
	}

	public function testSaveAndLoad() : void{
		$store = new MapDataStore($this->path);
		$map = new MapData($store->allocateId(), "world", -100, 250, 2, true, 7);
		$map->setPixel(3, 4, 0x123456);
		$store->save($map);
		self::assertFalse($map->hasChanged());

		$loaded = (new MapDataStore($this->path))->load($map->getId());
		self::assertNotNull($loaded);
		self::assertSame($map->getId(), $loaded->getId());
		self::assertSame("world", $loaded->getWorldName());
		self::assertSame(-100, $loaded->getCenterX());
		self::assertSame(250, $loaded->getCenterZ());
		self::assertSame(2, $loaded->getScale());
		self::assertTrue($loaded->isLocked());
		self::assertSame(7, $loaded->getParentMapId());
		self::assertSame(0x123456, $loaded->getPixel(3, 4));
		self::assertSame(-1, $loaded->getPixel(4, 3));
		self::assertSame($map->getPixels(), $loaded->getPixels());
		self::assertFalse($loaded->hasChanged());

		self::assertNull($store->load(12345));
	}

	public function testCorruptedMapIsIgnored() : void{
		$store = new MapDataStore($this->path);
		$store->save(new MapData($store->allocateId(), "world", 0, 0));
		file_put_contents(Path::join($this->path, "map_1.dat"), "garbage");
		self::assertNull($store->load(1));
	}
}
