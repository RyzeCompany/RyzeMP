<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\handler;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\compression\DecompressionException;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\protocol\PacketDecodeException;

final class LegacySessionBootstrapTest extends TestCase{
	private const BATCH = "\x11\x01\x00\x00\x01\xa3\x0b\x02\x00\x00\x00{}\x01\x00\x00\x00x";

	public function testAcceptsRawDeflateLoginWithoutAlgorithmPrefix() : void{
		$compressed = zlib_encode(self::BATCH, ZLIB_ENCODING_RAW);
		self::assertIsString($compressed);
		$login = LegacySessionBootstrap::decodeLogin($compressed, new ZlibCompressor(7, 256, 1024));
		self::assertSame(419, $login->protocol);
		self::assertSame('{}', $login->authInfoJson);
	}

	public function testAppliesTheNativeDecompressionLimit() : void{
		$compressed = zlib_encode(str_repeat('x', 4096), ZLIB_ENCODING_RAW);
		self::assertIsString($compressed);
		$this->expectException(DecompressionException::class);
		LegacySessionBootstrap::decodeLogin($compressed, new ZlibCompressor(7, 256, 1024));
	}

	public function testRejectsCompressedNonLoginTraffic() : void{
		$compressed = zlib_encode("\x01\x02", ZLIB_ENCODING_RAW);
		self::assertIsString($compressed);
		$this->expectException(PacketDecodeException::class);
		LegacySessionBootstrap::decodeLogin($compressed, new ZlibCompressor(7, 256, 1024));
	}
}
