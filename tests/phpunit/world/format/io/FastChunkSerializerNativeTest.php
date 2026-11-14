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

namespace pocketmine\world\format\io;

use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use function array_keys;
use function class_exists;
use function count;
use function mt_rand;
use function mt_srand;
use function substr;

/**
 * chunkutils2's FastSubChunkSerializer must give the same bytes and the same chunks as the PHP code of
 * FastChunkSerializer.
 */
final class FastChunkSerializerNativeTest extends TestCase{

	protected function setUp() : void{
		if(!class_exists(FastSubChunkSerializer::class, false)){
			self::markTestSkipped("chunkutils2 >= 0.3.8 is not loaded");
		}
	}

	protected function tearDown() : void{
		FastChunkSerializer::setNativeSubChunks(null);
	}

	/**
	 * A paletted array with about $distinct different values (so every bits-per-block size gets used).
	 */
	private static function randomArray(int $distinct) : PalettedBlockArray{
		$array = new PalettedBlockArray(mt_rand(0, 20000));
		if($distinct > 1){
			$values = [];
			for($i = 0; $i < $distinct; ++$i){
				$values[] = mt_rand(0, 0xffffffff >> 1);
			}
			for($i = 0, $n = mt_rand(1, 4096); $i < $n; ++$i){
				$array->set(mt_rand(0, 15), mt_rand(0, 15), mt_rand(0, 15), $values[mt_rand(0, $distinct - 1)]);
			}
		}
		return $array;
	}

	private static function randomChunk() : Chunk{
		$sizes = [1, 2, 3, 4, 5, 7, 9, 17, 33, 65, 200, 300, 1000, 4096];
		$subChunks = [];
		for($y = Chunk::MIN_SUBCHUNK_INDEX; $y <= Chunk::MAX_SUBCHUNK_INDEX; ++$y){
			$layers = [];
			for($l = 0, $n = mt_rand(0, 2); $l < $n; ++$l){
				$layers[] = self::randomArray($sizes[mt_rand(0, count($sizes) - 1)]);
			}
			$subChunks[$y] = new SubChunk(mt_rand(0, 0x7fffffff), $layers, self::randomArray($sizes[mt_rand(0, 6)]));
		}
		$chunk = new Chunk($subChunks, mt_rand(0, 1) === 1);
		if(mt_rand(0, 2) === 0){
			$chunk->addPendingTileNBT(CompoundTag::create()->setString("id", "Chest")->setInt("x", 1)->setInt("y", 2)->setInt("z", 3));
		}
		if(mt_rand(0, 2) === 0){
			$chunk->addPendingEntityNBT(CompoundTag::create()->setString("identifier", "minecraft:villager_v2"));
		}
		return $chunk;
	}

	private static function serialize(Chunk $chunk, bool $native) : string{
		FastChunkSerializer::setNativeSubChunks($native);
		return FastChunkSerializer::serializeTerrain($chunk);
	}

	private static function deserialize(string $data, bool $native) : Chunk{
		FastChunkSerializer::setNativeSubChunks($native);
		return FastChunkSerializer::deserializeTerrain($data);
	}

	private static function assertSameChunk(Chunk $expected, Chunk $actual) : void{
		self::assertSame($expected->isPopulated(), $actual->isPopulated());
		$expectedSubChunks = $expected->getSubChunks();
		$actualSubChunks = $actual->getSubChunks();
		self::assertSame(array_keys($expectedSubChunks), array_keys($actualSubChunks));
		foreach($expectedSubChunks as $y => $subChunk){
			$other = $actualSubChunks[$y];
			self::assertSame($subChunk->getEmptyBlockId(), $other->getEmptyBlockId());
			$layers = $subChunk->getBlockLayers();
			$otherLayers = $other->getBlockLayers();
			self::assertSame(array_keys($layers), array_keys($otherLayers));
			foreach($layers as $i => $layer){
				self::assertSame($layer->getBitsPerBlock(), $otherLayers[$i]->getBitsPerBlock());
				self::assertSame($layer->getWordArray(), $otherLayers[$i]->getWordArray());
				self::assertSame($layer->getPalette(), $otherLayers[$i]->getPalette());
			}
			self::assertSame($subChunk->getBiomeArray()->getWordArray(), $other->getBiomeArray()->getWordArray());
			self::assertSame($subChunk->getBiomeArray()->getPalette(), $other->getBiomeArray()->getPalette());
		}
		self::assertEquals($expected->getPendingTileNBT(), $actual->getPendingTileNBT());
		self::assertEquals($expected->getPendingEntityNBT(), $actual->getPendingEntityNBT());
	}

	public function testSameBytesAndChunks() : void{
		mt_srand(20261015);
		for($i = 0; $i < 60; ++$i){
			$chunk = self::randomChunk();
			$php = self::serialize($chunk, false);
			self::assertSame($php, self::serialize($chunk, true), "serialized chunk $i");

			$fromPhp = self::deserialize($php, false);
			$fromNative = self::deserialize($php, true);
			self::assertSameChunk($fromPhp, $fromNative);
			self::assertSame($php, self::serialize($fromNative, false));
		}
	}

	public function testEmptyAndSparseChunks() : void{
		$chunks = [
			new Chunk([], false),
			new Chunk([0 => new SubChunk(5, [], new PalettedBlockArray(1))], true),
			new Chunk([-4 => new SubChunk(5, [new PalettedBlockArray(7)], new PalettedBlockArray(1)), 19 => new SubChunk(0, [], new PalettedBlockArray(2))], true),
		];
		foreach($chunks as $i => $chunk){
			$php = self::serialize($chunk, false);
			self::assertSame($php, self::serialize($chunk, true), "chunk $i");
			self::assertSameChunk(self::deserialize($php, false), self::deserialize($php, true));
		}
	}

	/**
	 * A subchunk which dropped empty layers in collectGarbage() must still serialize natively (chunkutils2 0.3.8-0.3.12
	 * threw "malformed SubChunk" when the property was left as a PHP reference, crashing the server).
	 */
	public function testAfterCollectGarbage() : void{
		$layer0 = new PalettedBlockArray(5);
		$layer0->set(0, 0, 0, 7);
		$subChunk = new SubChunk(0, [$layer0, new PalettedBlockArray(0)], new PalettedBlockArray(1));
		$subChunk->collectGarbage();
		self::assertCount(1, $subChunk->getBlockLayers());
		$chunk = new Chunk([0 => $subChunk], true);
		self::assertSame(self::serialize($chunk, false), self::serialize($chunk, true));
	}

	public function testTruncatedDataThrows() : void{
		mt_srand(7);
		$data = self::serialize(self::randomChunk(), false);
		$this->expectException(\Exception::class);
		self::deserialize(substr($data, 0, 2000), true);
	}
}
