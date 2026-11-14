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

namespace pocketmine\network;

use function explode;
use function inet_pton;
use function intdiv;
use function is_numeric;
use function ord;
use function str_contains;
use function strlen;
use function substr;
use function trim;

/**
 * A set of IP addresses and networks (CIDR notation), e.g. the trusted proxies that are exempt from per-address
 * connection limits.
 */
final class AddressSet{
	/**
	 * @var string[][] binary network address, prefix length in bits
	 * @phpstan-var list<array{string, int}>
	 */
	private array $networks = [];

	/**
	 * @param string[] $entries IP addresses ("203.0.113.7", "2001:db8::1") or networks ("10.0.0.0/8", "2001:db8::/48")
	 *
	 * @throws \InvalidArgumentException if an entry is not a valid address or network
	 */
	public function __construct(array $entries){
		foreach($entries as $entry){
			$entry = trim($entry);
			if($entry === ""){
				continue;
			}
			$bits = null;
			if(str_contains($entry, "/")){
				[$entry, $length] = explode("/", $entry, 2);
				if(!is_numeric($length)){
					throw new \InvalidArgumentException("Invalid network length in \"$entry/$length\"");
				}
				$bits = (int) $length;
			}
			$binary = self::toBinary($entry);
			if($binary === null){
				throw new \InvalidArgumentException("Invalid IP address \"$entry\"");
			}
			$max = strlen($binary) * 8;
			$bits ??= $max;
			if($bits < 0 || $bits > $max){
				throw new \InvalidArgumentException("Invalid network length /$bits for \"$entry\"");
			}
			$this->networks[] = [$binary, $bits];
		}
	}

	public function isEmpty() : bool{
		return $this->networks === [];
	}

	/**
	 * Returns whether the IP address belongs to one of the addresses or networks of the set.
	 */
	public function contains(string $ip) : bool{
		if($this->networks === []){
			return false;
		}
		$binary = self::toBinary($ip);
		if($binary === null){
			return false;
		}
		foreach($this->networks as [$network, $bits]){
			if(strlen($network) === strlen($binary) && self::samePrefix($network, $binary, $bits)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the key under which per-address limits count an IP address: the address for IPv4, and the /64 prefix for
	 * IPv6 (a single subscriber usually owns a whole /64 and could use a new address for every connection).
	 * IPv4-mapped IPv6 addresses count as their IPv4 address. Invalid strings are returned unchanged.
	 */
	public static function limitKey(string $ip) : string{
		$binary = self::toBinary($ip);
		if($binary === null){
			return $ip;
		}
		return strlen($binary) === 4 ? $binary : substr($binary, 0, 8);
	}

	/**
	 * Converts an IP address (without zone or port) to its binary form, IPv4-mapped IPv6 addresses to 4 bytes.
	 */
	private static function toBinary(string $ip) : ?string{
		$ip = explode("%", trim($ip, "[] "), 2)[0];
		$binary = @inet_pton($ip);
		if($binary === false){
			return null;
		}
		if(strlen($binary) === 16 && substr($binary, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff"){
			return substr($binary, 12);
		}
		return $binary;
	}

	private static function samePrefix(string $a, string $b, int $bits) : bool{
		$bytes = intdiv($bits, 8);
		if($bytes > 0 && substr($a, 0, $bytes) !== substr($b, 0, $bytes)){
			return false;
		}
		$rest = $bits % 8;
		if($rest === 0){
			return true;
		}
		$mask = (0xff << (8 - $rest)) & 0xff;
		return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
	}
}
