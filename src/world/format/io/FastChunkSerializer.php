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

use pmmp\encoding\BE;
use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use function array_values;
use function class_exists;
use function count;
use function pack;
use function strlen;
use function unpack;

/**
 * This class provides a serializer used for transmitting chunks between threads.
 * The serialization format **is not intended for permanent storage** and may change without warning.
 */
final class FastChunkSerializer{
	private const FLAG_POPULATED = 1 << 1;
	private const FLAG_PENDING_TILES = 1 << 2;
	private const FLAG_PENDING_ENTITIES = 1 << 3;

	private function __construct(){
		//NOOP
	}

	private static function serializePalettedArray(ByteBufferWriter $stream, PalettedBlockArray $array) : void{
		$wordArray = $array->getWordArray();
		$palette = $array->getPalette();

		Byte::writeUnsigned($stream, $array->getBitsPerBlock());
		$stream->writeByteArray($wordArray);
		$serialPalette = pack("L*", ...$palette);
		BE::writeUnsignedInt($stream, strlen($serialPalette));
		$stream->writeByteArray($serialPalette);
	}

	/**
	 * Whether chunkutils2's FastSubChunkSerializer (>= 0.3.8) does the subchunk section: same bytes as the PHP code below.
	 * Null until first use.
	 */
	private static ?bool $nativeSubChunks = null;

	/**
	 * @internal for tests: forces the PHP (false) or native (true) subchunk code, or back to auto-detection (null).
	 */
	public static function setNativeSubChunks(?bool $native) : void{
		if($native === true && !class_exists(FastSubChunkSerializer::class, false)){
			throw new \LogicException("chunkutils2 >= 0.3.8 is required for native subchunk serialization");
		}
		self::$nativeSubChunks = $native;
	}

	/**
	 * Reference implementation of the subchunk section (see FastSubChunkSerializer).
	 * @param SubChunk[] $subChunks
	 * @phpstan-param array<int, SubChunk> $subChunks
	 */
	private static function serializeSubChunks(ByteBufferWriter $stream, array $subChunks) : void{
		$count = count($subChunks);
		Byte::writeUnsigned($stream, $count);

		foreach($subChunks as $y => $subChunk){
			Byte::writeSigned($stream, $y);
			BE::writeUnsignedInt($stream, $subChunk->getEmptyBlockId());

			$layers = $subChunk->getBlockLayers();
			Byte::writeUnsigned($stream, count($layers));
			foreach($layers as $blocks){
				self::serializePalettedArray($stream, $blocks);
			}
			self::serializePalettedArray($stream, $subChunk->getBiomeArray());

		}
	}

	/**
	 * Reference implementation of the subchunk section (see FastSubChunkSerializer).
	 * @return SubChunk[]
	 * @phpstan-return array<int, SubChunk>
	 */
	private static function deserializeSubChunks(ByteBufferReader $stream) : array{
		$subChunks = [];

		$count = Byte::readUnsigned($stream);
		for($subCount = 0; $subCount < $count; ++$subCount){
			$y = Byte::readSigned($stream);
			//TODO: why the heck are we using big-endian here?
			$airBlockId = BE::readUnsignedInt($stream);

			$layers = [];
			for($i = 0, $layerCount = Byte::readUnsigned($stream); $i < $layerCount; ++$i){
				$layers[] = self::deserializePalettedArray($stream);
			}
			$biomeArray = self::deserializePalettedArray($stream);
			$subChunks[$y] = new SubChunk($airBlockId, $layers, $biomeArray);
		}
		return $subChunks;
	}

	/**
	 * Fast-serializes the chunk for passing between threads
	 * TODO: tiles and entities
	 */
	public static function serializeTerrain(Chunk $chunk) : string{
		$stream = new ByteBufferWriter();
		$pendingTiles = $chunk->getPendingTileNBT();
		$pendingEntities = $chunk->getPendingEntityNBT();
		Byte::writeUnsigned($stream, ($chunk->isPopulated() ? self::FLAG_POPULATED : 0) | (count($pendingTiles) > 0 ? self::FLAG_PENDING_TILES : 0) | (count($pendingEntities) > 0 ? self::FLAG_PENDING_ENTITIES : 0));

		//subchunks
		$subChunks = $chunk->getSubChunks();
		if(self::$nativeSubChunks ??= class_exists(FastSubChunkSerializer::class, false)){
			$stream->writeByteArray(FastSubChunkSerializer::serialize($subChunks));
		}else{
			self::serializeSubChunks($stream, $subChunks);
		}

		if(count($pendingTiles) > 0){
			//block entity data from world generators (see Chunk::addPendingTileNBT())
			$nbtSerializer = new LittleEndianNbtSerializer();
			BE::writeUnsignedInt($stream, count($pendingTiles));
			foreach($pendingTiles as $nbt){
				$serialNbt = $nbtSerializer->write(new TreeRoot($nbt));
				BE::writeUnsignedInt($stream, strlen($serialNbt));
				$stream->writeByteArray($serialNbt);
			}
		}
		if(count($pendingEntities) > 0){
			//entities from world generators (see Chunk::addPendingEntityNBT())
			$nbtSerializer = new LittleEndianNbtSerializer();
			BE::writeUnsignedInt($stream, count($pendingEntities));
			foreach($pendingEntities as $nbt){
				$serialNbt = $nbtSerializer->write(new TreeRoot($nbt));
				BE::writeUnsignedInt($stream, strlen($serialNbt));
				$stream->writeByteArray($serialNbt);
			}
		}

		return $stream->getData();
	}

	private static function deserializePalettedArray(ByteBufferReader $stream) : PalettedBlockArray{
		$bitsPerBlock = Byte::readUnsigned($stream);
		$words = $stream->readByteArray(PalettedBlockArray::getExpectedWordArraySize($bitsPerBlock));
		$paletteSize = BE::readUnsignedInt($stream);
		/** @var int[] $unpackedPalette */
		$unpackedPalette = unpack("L*", $stream->readByteArray($paletteSize)); //unpack() will never fail here
		$palette = array_values($unpackedPalette);

		return PalettedBlockArray::fromData($bitsPerBlock, $words, $palette);
	}

	/**
	 * Deserializes a fast-serialized chunk
	 */
	public static function deserializeTerrain(string $data) : Chunk{
		$stream = new ByteBufferReader($data);

		$flags = Byte::readUnsigned($stream);
		$terrainPopulated = (bool) ($flags & self::FLAG_POPULATED);

		if(self::$nativeSubChunks ??= class_exists(FastSubChunkSerializer::class, false)){
			$subChunks = [];
			$offset = $stream->getOffset();
			foreach(FastSubChunkSerializer::deserialize($data, $offset) as $y => [$airBlockId, $layers, $biomeArray]){
				$subChunks[$y] = new SubChunk($airBlockId, $layers, $biomeArray);
			}
			$stream->setOffset($offset);
		}else{
			$subChunks = self::deserializeSubChunks($stream);
		}

		$chunk = new Chunk($subChunks, $terrainPopulated);
		if(($flags & self::FLAG_PENDING_TILES) !== 0){
			$nbtSerializer = new LittleEndianNbtSerializer();
			for($i = 0, $tileCount = BE::readUnsignedInt($stream); $i < $tileCount; ++$i){
				$chunk->addPendingTileNBT($nbtSerializer->read($stream->readByteArray(BE::readUnsignedInt($stream)))->mustGetCompoundTag());
			}
		}
		if(($flags & self::FLAG_PENDING_ENTITIES) !== 0){
			$nbtSerializer = new LittleEndianNbtSerializer();
			for($i = 0, $entityCount = BE::readUnsignedInt($stream); $i < $entityCount; ++$i){
				$chunk->addPendingEntityNBT($nbtSerializer->read($stream->readByteArray(BE::readUnsignedInt($stream)))->mustGetCompoundTag());
			}
		}
		return $chunk;
	}
}
