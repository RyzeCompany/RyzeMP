<?php

declare(strict_types=1);

namespace pocketmine\world\weather;

use PHPUnit\Framework\TestCase;
use pocketmine\block\Block;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\WorldData;
use pocketmine\world\World;

/** Test column geometry without waiting for random weather ticks. */
final class WeatherColumnTest extends TestCase{
	public function testAlreadyLoadedColumnAndSkyExposure() : void{
		$world = $this->createMock(World::class);
		$chunk = $this->createMock(Chunk::class);
		$chunk->method("getHighestBlockAt")->willReturn(70);
		$world->method("getChunk")->willReturn($chunk);
		$world->method("getMinY")->willReturn(-64);
		$ground = $this->createMock(Block::class);
		$ground->method("isSolid")->willReturn(true);
		$world->method("getBlockAt")->willReturn($ground);
		$weather = new Weather($world, $this->createMock(WorldData::class));

		self::assertSame(71, $weather->getPrecipitationHeight(3, 5));
		self::assertTrue($weather->isExposedToSky(3, 71, 5));
		self::assertFalse($weather->isExposedToSky(3, 70, 5));
	}

	public function testUnloadedChunksNeverCountAsExposed() : void{
		$world = $this->createMock(World::class);
		$world->method("getChunk")->willReturn(null);
		$world->expects(self::never())->method("getBlockAt");
		$weather = new Weather($world, $this->createMock(WorldData::class));

		self::assertNull($weather->getPrecipitationHeight(100, 100));
		self::assertFalse($weather->isExposedToSky(100, 90, 100));
	}
}
