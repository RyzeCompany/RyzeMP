<?php

declare(strict_types=1);

namespace pocketmine\world\format\io\leveldb;

use PHPUnit\Framework\TestCase;
use pocketmine\world\format\io\exception\UnsupportedWorldFormatException;

final class DimensionStorageTest extends TestCase{

	public function testKeyIdentityAndExactFiltering() : void{
		foreach([0, 1, 2] as $dimension){
			$index = DimensionStorage::chunkIndex(-12, 23, $dimension);
			self::assertSame($dimension === 0 ? 8 : 12, strlen($index));
			foreach([ChunkDataKey::OLD_VERSION, ChunkDataKey::NEW_VERSION] as $tag){
				self::assertSame($dimension, DimensionStorage::versionKeyDimension($index . $tag));
			}
			self::assertNull(DimensionStorage::versionKeyDimension($index . ChunkDataKey::SUBCHUNK . "\x00"));
		}
		self::assertNull(DimensionStorage::versionKeyDimension("player_record"));
		self::assertNull(DimensionStorage::versionKeyDimension(pack("V3", 0, 0, 99) . ChunkDataKey::NEW_VERSION));
	}

	public function testAutoPrefersNativeAndPreservesSeparateWorlds() : void{
		self::assertSame(1, DimensionStorage::select([0 => true, 1 => true], 1));
		self::assertSame(2, DimensionStorage::select([0 => true, 2 => true], 2));
		self::assertSame(0, DimensionStorage::select([0 => true], 1));
		self::assertSame(0, DimensionStorage::select([], 2));
		self::assertSame(0, DimensionStorage::select([0 => true, 1 => true], 1, 0));
		self::assertSame(2, DimensionStorage::select([], 2, 2));
	}

	public function testWrongDimensionIsRejectedInsteadOfGeneratingOverIt() : void{
		$this->expectException(UnsupportedWorldFormatException::class);
		DimensionStorage::select([2 => true], 1);
	}

	public function testExplicitAbsentSourceIsRejected() : void{
		$this->expectException(UnsupportedWorldFormatException::class);
		DimensionStorage::select([1 => true], 1, 0);
	}
}
