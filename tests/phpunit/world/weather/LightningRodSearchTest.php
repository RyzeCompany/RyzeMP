<?php

declare(strict_types=1);

namespace pocketmine\world\weather;

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Vector3;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\WorldData;
use pocketmine\world\World;

final class LightningRodSearchTest extends TestCase{
	public function testSubchunkPaletteSkipsColumnsWithoutRods() : void{
		$world = $this->createMock(World::class);
		$chunk = $this->getMockBuilder(Chunk::class)->disableOriginalConstructor()
			->onlyMethods(["getSubChunks", "getHighestBlockAt"])->getMock();
		$chunk->method("getSubChunks")->willReturn([]);
		$chunk->expects(self::never())->method("getHighestBlockAt");
		$world->method("getChunk")->willReturn($chunk);
		$weather = new Weather($world, $this->createMock(WorldData::class));
		self::assertNull($weather->findLightningRod(0, 70, 0));
	}

	public function testLightningRodMustBeHighestBlockInColumn() : void{
		$chunk = new Chunk([], true);
		$chunk->setBlockStateId(5, 70, 7, VanillaBlocks::LIGHTNING_ROD()->getStateId());
		$world = $this->createMock(World::class);
		$world->method("getChunk")->willReturnCallback(
			static fn(int $cx, int $cz) : ?Chunk => $cx === 0 && $cz === 0 ? $chunk : null
		);
		$weather = new Weather($world, $this->createMock(WorldData::class));
		self::assertEquals(new Vector3(5, 70, 7), $weather->findLightningRod(5, 71, 7));
		$chunk->setBlockStateId(5, 71, 7, VanillaBlocks::STONE()->getStateId());
		self::assertNull($weather->findLightningRod(5, 71, 7));
	}
}
