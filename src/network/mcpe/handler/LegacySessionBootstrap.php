<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\handler;

use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\serializer\LegacyLoginPacketDecoder;

/**
 * Preflight for the compressed Login-first transport used by 1.16.100.
 * Callers must still check ACCEPTED_PROTOCOL before authentication or spawning.
 * Not wired into the live session until the gameplay codecs are complete.
 */
final class LegacySessionBootstrap{
	private function __construct(){}

	public static function decodeLogin(string $payload, Compressor $compressor) : LoginPacket{
		// Use the bounded native decompressor; do not retry malformed modern batches
		// as legacy traffic after a protocol has already been selected.
		return LegacyLoginPacketDecoder::decodeBatch($compressor->decompress($payload));
	}
}
