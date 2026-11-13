<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

/**
 * Rewrites a verified native ICE SDP answer to advertise ONLY the original
 * public Bedrock UDP endpoint, never the native 127.0.0.1 ICE backend.
 *
 * This must run AFTER the native transport signs the answer, but BEFORE the
 * HTTP response is delivered to the client and its ufrag is authorized.
 * Modifies candidates only: the signed DTLS fingerprint and server identity
 * assertion are left untouched.
 *
 * @internal
 */
final class NetherNetSharedPortSdp{
	/**
	 * @return array{sdp: string, ufrag: string}
	 */
	public static function rewriteAnswer(string $answer, string $publicIp, int $publicPort) : array{
		if(filter_var($publicIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ||
			$publicIp === "0.0.0.0" || $publicPort < 1 || $publicPort > 65535){
			throw new \InvalidArgumentException("A concrete advertised public IPv4 address and port is required");
		}
		if(strlen($answer) > 65536 || str_contains($answer, "\0")){
			throw new \InvalidArgumentException("Invalid or oversized ICE SDP answer");
		}
		$ufrag = null;
		$candidates = 0;
		$lines = preg_split('/\r\n|\n/', trim($answer, "\r\n"));
		if($lines === false){
			throw new \InvalidArgumentException("Invalid ICE SDP line endings");
		}
		foreach($lines as &$line){
			if(str_starts_with($line, "a=ice-ufrag:")){
				$parsed = substr($line, strlen("a=ice-ufrag:"));
				if($ufrag !== null || strlen($parsed) < 4 || strlen($parsed) > 256 ||
					preg_match('/\A[A-Za-z0-9+\/_=.-]+\z/D', $parsed) !== 1){
					throw new \InvalidArgumentException("Missing or ambiguous server ICE ufrag");
				}
				$ufrag = $parsed;
			}
			if(str_starts_with($line, "a=candidate:")){
				$parts = preg_split('/\s+/', $line);
				// Only a UDP component-1 host candidate may be transformed.
				if($parts === false || count($parts) < 8 || $parts[1] !== "1" ||
					strtolower($parts[2]) !== "udp" || $parts[6] !== "typ" ||
					$parts[7] !== "host" ||
					filter_var($parts[4], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false){
					throw new \InvalidArgumentException("ICE SDP contains unsupported candidate");
				}
				$parts[4] = $publicIp;
				$parts[5] = (string) $publicPort;
				// Strip optional native-only extensions, mirroring Engine.
				$line = implode(" ", array_slice($parts, 0, 8));
				++$candidates;
			}
		}
		unset($line);
		if($ufrag === null || $candidates === 0){
			throw new \InvalidArgumentException("ICE SDP must include an authenticated ufrag and host candidates");
		}
		return ["sdp" => implode("\r\n", $lines) . "\r\n", "ufrag" => $ufrag];
	}
}
