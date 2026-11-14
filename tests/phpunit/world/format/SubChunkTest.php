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

namespace pocketmine\world\format;

use PHPUnit\Framework\TestCase;
use pocketmine\data\bedrock\BiomeIds;

class SubChunkTest extends TestCase{

	/**
	 * Test that a cloned SubChunk instance doesn't influence the original
	 */
	public function testClone() : void{
		$sub1 = new SubChunk(0, [], new PalettedBlockArray(BiomeIds::OCEAN));

		$sub1->setBlockStateId(0, 0, 0, 1);
		$sub1->getBlockLightArray()->set(0, 0, 0, 1);
		$sub1->getBlockSkyLightArray()->set(0, 0, 0, 1);

		$sub2 = clone $sub1;

		$sub2->setBlockStateId(0, 0, 0, 2);
		$sub2->getBlockLightArray()->set(0, 0, 0, 2);
		$sub2->getBlockSkyLightArray()->set(0, 0, 0, 2);

		self::assertNotSame($sub1->getBlockStateId(0, 0, 0), $sub2->getBlockStateId(0, 0, 0));
		self::assertNotSame($sub1->getBlockLightArray()->get(0, 0, 0), $sub2->getBlockLightArray()->get(0, 0, 0));
		self::assertNotSame($sub1->getBlockSkyLightArray()->get(0, 0, 0), $sub2->getBlockSkyLightArray()->get(0, 0, 0));
	}

	/**
	 * collectGarbage() drops only the empty layers at the end (the native serializer side of this is covered by
	 * FastChunkSerializerNativeTest::testAfterCollectGarbage()).
	 */
	public function testCollectGarbageDropsTrailingEmptyLayers() : void{
		$layer0 = new PalettedBlockArray(5);
		$layer0->set(0, 0, 0, 7);
		$sub = new SubChunk(0, [$layer0, new PalettedBlockArray(0), new PalettedBlockArray(0)], new PalettedBlockArray(BiomeIds::OCEAN));
		$sub->collectGarbage();
		self::assertCount(1, $sub->getBlockLayers());
		self::assertSame(7, $sub->getBlockStateId(0, 0, 0));

		//an empty layer 0 stays while layer 1 has water in it
		$water = new SubChunk(0, [new PalettedBlockArray(0), new PalettedBlockArray(9)], new PalettedBlockArray(BiomeIds::OCEAN));
		$water->collectGarbage();
		self::assertCount(2, $water->getBlockLayers());
		self::assertSame(9, $water->getLiquidLayerStateId(0, 0, 0));

		$empty = new SubChunk(0, [new PalettedBlockArray(0), new PalettedBlockArray(0)], new PalettedBlockArray(BiomeIds::OCEAN));
		$empty->collectGarbage();
		self::assertTrue($empty->isEmptyFast());
	}
}
