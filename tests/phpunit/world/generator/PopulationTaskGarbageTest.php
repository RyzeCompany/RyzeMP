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

namespace pocketmine\world\generator;

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\world\ChunkManager;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\executor\ThreadLocalGeneratorContext;
use pocketmine\world\World;
use function count;

final class PopulationTaskGarbageTest extends TestCase{
	private const WORLD_ID = 987654;

	protected function tearDown() : void{
		ThreadLocalGeneratorContext::unregister(self::WORLD_ID);
	}

	public function testPopulatedChunksComeBackWithoutPaletteGarbage() : void{
		//every chunk gets many block types, then all of them but stone are overwritten: unused palette entries
		$generator = new class(0, "") extends Generator{
			public function generateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{
				$chunk = $world->getChunk($chunkX, $chunkZ) ?? throw new \AssertionError("chunk expected");
				$blocks = [VanillaBlocks::DIRT(), VanillaBlocks::GRAVEL(), VanillaBlocks::SAND(), VanillaBlocks::OAK_PLANKS(), VanillaBlocks::GLASS()];
				for($y = 0; $y < 32; ++$y){
					for($x = 0; $x < 16; ++$x){
						$chunk->setBlockStateId($x, $y, $x, $blocks[($x + $y) % count($blocks)]->getStateId());
					}
				}
				for($y = 0; $y < 32; ++$y){
					for($x = 0; $x < 16; ++$x){
						$chunk->setBlockStateId($x, $y, $x, VanillaBlocks::STONE()->getStateId());
					}
				}
			}

			public function populateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{
			}
		};
		ThreadLocalGeneratorContext::register(new ThreadLocalGeneratorContext($generator, -64, 320), self::WORLD_ID);

		$adjacent = [];
		for($x = -1; $x <= 1; ++$x){
			for($z = -1; $z <= 1; ++$z){
				if($x !== 0 || $z !== 0){
					$adjacent[World::chunkHash($x, $z)] = null;
				}
			}
		}
		$result = [];
		$task = new PopulationTask(self::WORLD_ID, 0, 0, null, $adjacent, function(Chunk $centerChunk, array $adjacentChunks) use (&$result) : void{
			$result = [$centerChunk, ...$adjacentChunks];
		});
		$task->onRun();
		$task->onCompletion();

		self::assertCount(9, $result, "the center chunk and the 8 generated neighbours come back");
		$checked = 0;
		foreach($result as $chunk){
			foreach($chunk->getSubChunks() as $y => $subChunk){
				foreach($subChunk->getBlockLayers() as $layer){
					$collected = clone $layer;
					$collected->collectGarbage();
					self::assertCount(count($collected->getPalette()), $layer->getPalette(), "subchunk $y has unused palette entries");
					self::assertSame($collected->getBitsPerBlock(), $layer->getBitsPerBlock());
					++$checked;
				}
			}
			self::assertSame(VanillaBlocks::STONE()->getStateId(), $chunk->getBlockStateId(3, 5, 3));
		}
		self::assertGreaterThan(0, $checked);
	}
}
