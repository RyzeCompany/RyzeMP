<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

/**
 * Select the original server-ip (when concrete); on wildcard binds use the
 * IPv4 address that the JOIN client used in its HTTP Host header. This avoids
 * a separate advertised-IP setting and never advertises 0.0.0.0.
 *
 * Host is routing information, never a source of authentication. TLS/DTLS
 * identity verification remains independent. We do not perform blocking DNS
 * resolution inside the game tick or let untrusted Host names trigger lookups.
 *
 * @internal
 */
final class NetherNetHostAddress{
	public static function resolve(string $serverIp, ?string $joinHost) : ?string{
		if(filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false){
			return null;
		}
		if($serverIp !== "0.0.0.0"){
			return $serverIp;
		}
		if($joinHost === null || strlen($joinHost) > 253){
			return null;
		}
		$address = $joinHost;
		if(preg_match('/^([0-9.]+):([0-9]{1,5})$/D', $joinHost, $m) === 1){
			$address = $m[1];
		}
		return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false &&
			$address !== "0.0.0.0" ? $address : null;
	}

	/**
	 * Extract an already validated request's single Host field from a bounded
	 * HTTP header. Missing, duplicate, malformed or folded headers fail closed.
	 */
	public static function requestHost(string $header) : ?string{
		if(strlen($header) > 8192 || !str_ends_with($header, "\r\n\r\n")){
			return null;
		}
		$hosts = [];
		foreach(explode("\r\n", substr($header, 0, -4)) as $line){
			if(preg_match('/^Host:[ \t]*([A-Za-z0-9._-]+(?::[0-9]{1,5})?)$/iD', $line, $m) === 1){
				$hosts[] = $m[1];
			}elseif(preg_match('/^Host\s*:/iD', $line) === 1){
				return null;
			}
		}
		return count($hosts) === 1 ? $hosts[0] : null;
	}
}
