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
use pocketmine\network\mcpe\protocol\ClientboundMapItemDataPacket;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use function pack;
use function sys_get_temp_dir;
use function uniqid;
use function unpack;

/**
 * Maps keep the dimension they were made in: saved in the "dimension" tag and sent as the packet's dimension ID.
 */
final class MapDimensionTest extends TestCase{
	private string $path;

	protected function setUp() : void{
		$this->path = Path::join(sys_get_temp_dir(), uniqid("pm_maps_dim_", true));
	}

	protected function tearDown() : void{
		(new Filesystem())->remove($this->path);
	}

	private function world(Dimension $dimension) : World{
		$world = $this->createMock(World::class);
		$world->method("getFolderName")->willReturn("world_" . $dimension->name);
		$world->method("getDimension")->willReturn($dimension);
		return $world;
	}

	private static function packetDimension(MapManager $manager, MapData $map) : int{
		$packet = (new \ReflectionMethod(MapManager::class, "createPacket"))->invoke($manager, $map);
		self::assertInstanceOf(ClientboundMapItemDataPacket::class, $packet);
		return $packet->dimensionId;
	}

	public function testDimensionSavedAndLoaded() : void{
		foreach([DimensionIds::OVERWORLD, DimensionIds::NETHER, DimensionIds::THE_END] as $dimension){
			$map = new MapData(3, "world", 10, -20, 1, false, -1, null, $dimension);
			$nbt = $map->toNbt();
			self::assertSame($dimension, $nbt->getByte("dimension"));
			self::assertSame($dimension, MapData::fromNbt($nbt)->getDimension());
		}
		//maps saved before the dimension was kept, and unknown values, are Overworld maps
		$old = (new MapData(4, "world", 0, 0))->toNbt();
		$old->removeTag("dimension");
		self::assertSame(DimensionIds::OVERWORLD, MapData::fromNbt($old)->getDimension());
		$old->setByte("dimension", 9);
		self::assertSame(DimensionIds::OVERWORLD, MapData::fromNbt($old)->getDimension());
	}

	public function testNewMapsTakeTheWorldDimension() : void{
		$manager = new MapManager(new MapDataStore($this->path));
		$nether = $manager->createMap($this->world(Dimension::NETHER), 5, 5);
		self::assertSame(DimensionIds::NETHER, $nether->getDimension());
		self::assertSame(DimensionIds::NETHER, self::packetDimension($manager, $nether));

		$end = $manager->createMap($this->world(Dimension::END), 0, 0, 2);
		self::assertSame(DimensionIds::THE_END, self::packetDimension($manager, $end));
		self::assertSame(DimensionIds::OVERWORLD, self::packetDimension($manager, $manager->createMap($this->world(Dimension::OVERWORLD), 0, 0)));

		//zooming out and locking keep it
		self::assertSame(DimensionIds::NETHER, $manager->createScaledMap($nether, $manager->allocateMapId())->getDimension());
		self::assertSame(DimensionIds::THE_END, $manager->createLockedCopy($end, $manager->allocateMapId())->getDimension());

		//and it survives a restart
		$reloaded = new MapManager(new MapDataStore($this->path));
		self::assertSame(DimensionIds::NETHER, $reloaded->getMap($nether->getId())?->getDimension());
	}

	/**
	 * Java MapItem#update ceiling noise, computed with explicit 32-bit packing as an independent reference.
	 */
	private static function javaCeilingIsDirt(int $x, int $z) : bool{
		$n = self::int32($x + $z * 231871);
		$n = self::int32(self::int32(self::int32($n * $n) * 31287121) + self::int32($n * 11));
		return (($n >> 20) & 1) === 0;
	}

	private static function int32(int $value) : int{
		$unpacked = unpack("l", pack("l", $value & 0xffffffff));
		self::assertIsArray($unpacked);
		self::assertIsInt($unpacked[1]);
		return $unpacked[1];
	}

	public function testNetherMapsDrawTheCeilingNoise() : void{
		$colors = BlockMapColors::getInstance();
		$dirt = BlockMapColors::shade($colors->getColorByName("minecraft:dirt"), BlockMapColors::SHADE_NORMAL);
		$stone = BlockMapColors::shade($colors->getColorByName("minecraft:stone"), BlockMapColors::SHADE_NORMAL);
		$renderer = new MapRenderer($colors);
		$chunk = new Chunk([], false);
		$getChunk = static fn(int $chunkX, int $chunkZ) : Chunk => $chunk;

		$map = new MapData(1, "world_nether", 0, 0, 0, false, -1, null, DimensionIds::NETHER);
		self::assertSame(MapRenderer::getUpdateRadius(0) >> 1, MapRenderer::getMapUpdateRadius($map), "half the radius under a ceiling");
		self::assertGreaterThan(0, $renderer->renderColumn($map, $getChunk, 64, 64, 40));
		$seen = [];
		for($pixelY = 0; $pixelY < MapData::SIZE; ++$pixelY){
			$pixel = $map->getPixel(64, $pixelY);
			if($pixelY - 40 >= 64){
				self::assertSame(-1, $pixel, "outside the halved radius at row $pixelY");
				continue;
			}
			if($pixelY - 40 >= 62){
				continue; //edge of the circle: checkerboard
			}
			//pixel (64, y) covers block (0, y - 64)
			self::assertSame(self::javaCeilingIsDirt(0, $pixelY - 64) ? $dirt : $stone, $pixel, "row $pixelY");
			$seen[$pixel] = true;
		}
		self::assertCount(2, $seen, "both dirt and stone show");

		//the same empty terrain in the Overworld draws nothing
		$overworld = new MapData(2, "world", 0, 0);
		self::assertSame(MapRenderer::getUpdateRadius(0), MapRenderer::getMapUpdateRadius($overworld));
		self::assertSame(0, $renderer->renderColumn($overworld, $getChunk, 64, 64, 64));
	}
}
