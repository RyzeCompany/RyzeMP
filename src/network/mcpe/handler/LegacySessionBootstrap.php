<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\handler;

use pocketmine\network\mcpe\compression\Compressor;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\VarInt;
use pmmp\encoding\DataDecodeException;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\serializer\LegacyLoginPacketDecoder;

/**
 * Preflight for the compressed Login-first transport used by 1.16.100.
 * The session may call this only before protocol selection, with experimental access enabled.
 */
final class LegacySessionBootstrap{
	private function __construct(){}

 public static function isNetworkSettingsRequest(string $payload) : bool{
  try{
   $in = new ByteBufferReader($payload);
   VarInt::readUnsignedInt($in); // frame length; the regular decoder validates the complete frame
   return (VarInt::readUnsignedInt($in) & 0x3ff) === ProtocolInfo::REQUEST_NETWORK_SETTINGS_PACKET;
  }catch(DataDecodeException){
   return false;
  }
 }

	public static function decodeLogin(string $payload, Compressor $compressor) : LoginPacket{
		// Use the bounded native decompressor; do not retry malformed modern batches
		// as legacy traffic after a protocol has already been selected.
		return LegacyLoginPacketDecoder::decodeBatch($compressor->decompress($payload));
	}
}
