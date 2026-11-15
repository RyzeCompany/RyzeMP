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

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\serializer\ChunkSerializer;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\end\TheEnd;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\generator\hell\Nether;
use pocketmine\world\WorldManager;
use function sort;
use function sqrt;

class DimensionTest extends TestCase{

	public function testNetworkIdsAndRanges() : void{
		self::assertSame(DimensionIds::OVERWORLD, Dimension::OVERWORLD->getNetworkId());
		self::assertSame(DimensionIds::NETHER, Dimension::NETHER->getNetworkId());
		self::assertSame(DimensionIds::THE_END, Dimension::END->getNetworkId());
		foreach(Dimension::cases() as $dimension){
			self::assertSame($dimension, Dimension::fromNetworkId($dimension->getNetworkId()));
			//the build range must match the sub-chunks the protocol expects for the dimension
			[$minSubChunk, $maxSubChunk] = ChunkSerializer::getDimensionChunkBounds($dimension->getNetworkId());
			self::assertSame($minSubChunk * 16, $dimension->getMinY());
			self::assertSame(($maxSubChunk + 1) * 16, $dimension->getMaxY());
		}
		self::assertSame(Dimension::NETHER, Dimension::fromString("Nether"));
		self::assertSame(Dimension::END, Dimension::fromString("the_end"));
		self::assertNull(Dimension::fromString("moon"));
	}

	public function testLinkedWorldNames() : void{
		self::assertSame("world_nether", WorldManager::getLinkedWorldName("world", Dimension::OVERWORLD, Dimension::NETHER));
		self::assertSame("world_the_end", WorldManager::getLinkedWorldName("world", Dimension::OVERWORLD, Dimension::END));
		self::assertSame("world", WorldManager::getLinkedWorldName("world_nether", Dimension::NETHER, Dimension::OVERWORLD));
		self::assertSame("world_the_end", WorldManager::getLinkedWorldName("world_nether", Dimension::NETHER, Dimension::END));
		self::assertSame("world", WorldManager::getLinkedWorldName("world_the_end", Dimension::END, Dimension::OVERWORLD));
		//a Nether world without the suffix still links to "<name>" plus the suffix of the target
		self::assertSame("hell", WorldManager::getLinkedWorldName("hell", Dimension::NETHER, Dimension::OVERWORLD));
	}

	public function testGeneratorDimensions() : void{
		$manager = GeneratorManager::getInstance();
		self::assertSame(Dimension::NETHER, $manager->getGenerator("nether")?->getDimension());
		self::assertSame(Dimension::NETHER, $manager->getGenerator("hell")?->getDimension());
		self::assertSame(Dimension::END, $manager->getGenerator("the_end")?->getDimension());
		self::assertSame(TheEnd::class, $manager->getGenerator("end")?->getGeneratorClass());
		self::assertNull($manager->getGenerator("normal")?->getDimension());
		self::assertSame(Nether::class, $manager->getGenerator(Dimension::NETHER->getDefaultGeneratorName())?->getGeneratorClass());
	}

	public function testEndPillars() : void{
		$pillars = TheEnd::computePillars(1234);
		self::assertCount(TheEnd::PILLAR_COUNT, $pillars);
		$heights = [];
		foreach($pillars as [$x, $z, $radius, $height]){
			self::assertEqualsWithDelta(TheEnd::PILLAR_RING_RADIUS, sqrt($x * $x + $z * $z), 1.5);
			self::assertGreaterThanOrEqual(2, $radius);
			self::assertLessThanOrEqual(5, $radius);
			$heights[] = $height;
		}
		sort($heights);
		self::assertSame([76, 79, 82, 85, 88, 91, 94, 97, 100, 103], $heights);
		self::assertSame($pillars, TheEnd::computePillars(1234), "deterministic");
	}

	public function testEndIsland() : void{
		$end = new TheEnd(42, "");
		$center = $end->getIslandColumn(0, 0);
		self::assertNotNull($center);
		[$bottom, $top] = $center;
		self::assertGreaterThan(55, $top);
		self::assertLessThan($top - 30, $bottom);
		self::assertNull($end->getIslandColumn(200, 0));
		//the spawn platform at 100,0 floats off the island's edge
		self::assertNull($end->getIslandColumn(100, 0));
	}

	public function testNetherChunkHeight() : void{
		$chunk = new Chunk([], false);
		$chunk->setBlockStateId(0, 127, 0, VanillaBlocks::BEDROCK()->getStateId());
		self::assertSame(8, ChunkSerializer::getSubChunkCount($chunk, DimensionIds::NETHER));
		$chunk->setBlockStateId(0, 200, 0, VanillaBlocks::STONE()->getStateId());
		self::assertSame(8, ChunkSerializer::getSubChunkCount($chunk, DimensionIds::NETHER), "blocks above the Nether roof are not sent");
		self::assertSame(13, ChunkSerializer::getSubChunkCount($chunk, DimensionIds::THE_END));
		self::assertSame(17, ChunkSerializer::getSubChunkCount($chunk, DimensionIds::OVERWORLD));
	}
}
