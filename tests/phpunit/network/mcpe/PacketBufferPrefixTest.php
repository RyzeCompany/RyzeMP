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

namespace pocketmine\network\mcpe;

use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use function implode;
use function iterator_to_array;
use function str_repeat;

/**
 * Sessions concatenate length-prefixed packet buffers instead of encoding the batch at flush time: the bytes must
 * be exactly what PacketBatch::encodeRaw() produces.
 */
final class PacketBufferPrefixTest extends TestCase{

	/**
	 * @return string[][]
	 * @phpstan-return list<array{list<string>}>
	 */
	public static function batchProvider() : array{
		return [
			[[""]],
			[["a"]],
			[[str_repeat("b", 127)]],
			[[str_repeat("c", 128)]],
			[[str_repeat("d", 16383), str_repeat("e", 16384)]],
			[[str_repeat("f", 2097152)]],
			[["\x12\x00\x01movement", "\x3a\xff", str_repeat("g", 300), ""]],
		];
	}

	/**
	 * @dataProvider batchProvider
	 * @param string[] $buffers
	 * @phpstan-param list<string> $buffers
	 */
	public function testPrefixedConcatenationMatchesEncodeRaw(array $buffers) : void{
		$writer = new ByteBufferWriter();
		PacketBatch::encodeRaw($writer, $buffers);
		$expected = $writer->getData();

		$prefixed = [];
		foreach($buffers as $buffer){
			$prefixed[] = NetworkSession::prefixPacketBuffer($buffer);
		}
		$actual = implode("", $prefixed);

		self::assertSame($expected, $actual);
		self::assertSame($buffers, iterator_to_array(PacketBatch::decodeRaw(new ByteBufferReader($actual)), false));
	}
}
