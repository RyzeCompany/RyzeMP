<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

/**
 * Conservative wire classification for a future single-port UDP dispatcher.
 *
 * Never decide a session's transport solely from untrusted bytes: the socket
 * owner must pin validated peer addresses and send post-handshake datagrams
 * only to that owning transport. This class only detects initial ICE/DTLS
 * signatures and avoids misrouting RakNet unconnected pings as STUN.
 *
 * @internal
 */
final class NetherNetDatagramClassifier{
	public const RAKNET = 0;
	public const STUN = 1;
	public const DTLS = 2;

	public static function classify(string $datagram) : int{
		$length = strlen($datagram);
		// RFC 5389: first two bits 00, 20-byte header, magic cookie,
		// and a 32-bit-aligned declared message length matching the datagram.
		if($length >= 20 && (ord($datagram[0]) & 0xc0) === 0 &&
			substr($datagram, 4, 4) === "\x21\x12\xa4\x42"){
			$declared = (ord($datagram[2]) << 8) | ord($datagram[3]);
			if(($declared & 3) === 0 && $declared === $length - 20){
				return self::STUN;
			}
		}

		// DTLS 1.0/1.2/1.3 records: content type 20..63 and FE/FF major.
		// A minimal DTLS record is 13 bytes and carries a uint16 body size.
		if($length >= 13){
			$type = ord($datagram[0]);
			$major = ord($datagram[1]);
			$minor = ord($datagram[2]);
			if($type >= 20 && $type <= 63 && $major === 0xfe && in_array($minor, [0xff, 0xfd, 0xfc], true)){
				$recordLength = (ord($datagram[11]) << 8) | ord($datagram[12]);
				if($recordLength <= $length - 13){
					return self::DTLS;
				}
			}
		}

		// Connected RakNet is resolved by the dispatcher's authenticated
		// session-to-peer routing, NOT by RFC7983's overlapping first byte.
		return self::RAKNET;
	}
}
