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

namespace pocketmine\world\format\io\leveldb;

use PHPUnit\Framework\TestCase;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\data\bedrock\block\BlockStateSerializer;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;
use pocketmine\utils\BinaryStream;
use pocketmine\utils\Random;
use pocketmine\world\format\io\BaseWorldProvider;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\format\PalettedBlockArray;
use function array_keys;
use function count;
use function min;

/**
 * LevelDB::serializeBlockPalette() keeps the serialized NBT of each palette entry: the bytes must be exactly those of
 * the previous code (one TreeRoot per entry written with writeMultiple()), for every known block state, with the cache
 * cold, warm and starting over when full.
 */
final class LevelDBPaletteCacheTest extends TestCase{

	/**
	 * LevelDB::serializeBlockPalette() before the change, verbatim.
	 */
	private static function oldSerialize(BlockStateSerializer $serializer, BinaryStream $stream, PalettedBlockArray $blocks) : void{
		$stream->putByte($blocks->getBitsPerBlock() << 1);
		$stream->put($blocks->getWordArray());

		$palette = $blocks->getPalette();
		if($blocks->getBitsPerBlock() !== 0){
			$stream->putLInt(count($palette));
		}
		$tags = [];
		foreach($palette as $p){
			$tags[] = new TreeRoot($serializer->serialize($p)->toNbt());
		}

		$stream->put((new LittleEndianNbtSerializer())->writeMultiple($tags));
	}

	private static function newSerialize(LevelDB $provider, BinaryStream $stream, PalettedBlockArray $blocks) : void{
		(fn() => $this->serializeBlockPalette($stream, $blocks))->call($provider);
	}

	private static function provider() : LevelDB{
		$provider = (new \ReflectionClass(LevelDB::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(BaseWorldProvider::class, "blockStateSerializer"))->setValue($provider, GlobalBlockStateHandlers::getSerializer());
		return $provider;
	}

	private static function assertSameBytes(LevelDB $provider, PalettedBlockArray $blocks, string $message) : void{
		$old = new BinaryStream();
		self::oldSerialize(GlobalBlockStateHandlers::getSerializer(), $old, $blocks);
		$new = new BinaryStream();
		self::newSerialize($provider, $new, $blocks);
		self::assertSame($old->getBuffer(), $new->getBuffer(), $message);
	}

	public function testEveryKnownState() : void{
		$provider = self::provider();
		$states = array_keys(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates());
		self::assertGreaterThan(2048, count($states), "enough states to fill the cache several times");
		$random = new Random(1);
		//every state in palettes of all sizes, twice (cold, then warm or after the cache started over)
		for($pass = 0; $pass < 2; ++$pass){
			for($offset = 0, $size = 1; $offset < count($states); $offset += $size, $size = 1 + $random->nextBoundedInt(300)){
				$blocks = new PalettedBlockArray($states[$offset]);
				$end = min(count($states), $offset + $size);
				for($i = $offset + 1, $cell = 0; $i < $end; ++$i, ++$cell){
					$blocks->set($cell & 0xf, ($cell >> 4) & 0xf, ($cell >> 8) & 0xf, $states[$i]);
				}
				self::assertSameBytes($provider, $blocks, "pass $pass, states $offset..$end");
			}
		}
	}

	public function testRandomPalettes() : void{
		$provider = self::provider();
		$states = array_keys(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates());
		$random = new Random(2);
		//a small working set, like real chunks: mostly cache hits
		$common = [];
		for($i = 0; $i < 200; ++$i){
			$common[] = $states[$random->nextBoundedInt(count($states))];
		}
		for($n = 0; $n < 300; ++$n){
			$blocks = new PalettedBlockArray($common[$random->nextBoundedInt(count($common))]);
			$entries = $random->nextBoundedInt(5) === 0 ? 0 : $random->nextBoundedInt(64);
			for($i = 0; $i < $entries; ++$i){
				$blocks->set($random->nextBoundedInt(16), $random->nextBoundedInt(16), $random->nextBoundedInt(16), $common[$random->nextBoundedInt(count($common))]);
			}
			if($random->nextBoolean()){
				$blocks->collectGarbage();
			}
			self::assertSameBytes($provider, $blocks, "palette $n");
		}
	}
}
