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

use PHPUnit\Framework\TestCase;

class AddressSetTest extends TestCase{

	public function testContains() : void{
		$set = new AddressSet([" 203.0.113.7 ", "10.0.0.0/8", "2001:db8::/48", "::ffff:192.0.2.1", "172.16.0.0/12", ""]);
		foreach([
			"203.0.113.7" => true,
			"::ffff:203.0.113.7" => true,
			"203.0.113.8" => false,
			"10.200.1.2" => true,
			"11.0.0.1" => false,
			"172.31.255.255" => true,
			"172.32.0.0" => false,
			"2001:db8:0:ffff::1" => true,
			"2001:db8:1::1" => false,
			"192.0.2.1" => true,
			"[2001:db8::5]" => true,
			"2001:db8::1%eth0" => true,
			"not an address" => false,
			"" => false
		] as $ip => $expected){
			self::assertSame($expected, $set->contains($ip), "contains($ip)");
		}
		self::assertTrue((new AddressSet([]))->isEmpty());
		self::assertFalse((new AddressSet([]))->contains("1.2.3.4"));
	}

	public function testInvalidEntries() : void{
		foreach(["1.2.3.4/33", "nonsense", "10.0.0.0/x", "2001:db8::/129"] as $entry){
			try{
				new AddressSet([$entry]);
				self::fail("$entry was accepted");
			}catch(\InvalidArgumentException){
				self::addToAssertionCount(1);
			}
		}
	}

	public function testLimitKey() : void{
		self::assertSame(AddressSet::limitKey("2001:db8:1:2::1"), AddressSet::limitKey("2001:db8:1:2:ffff::9"));
		self::assertNotSame(AddressSet::limitKey("2001:db8:1:2::1"), AddressSet::limitKey("2001:db8:1:3::1"));
		self::assertSame(AddressSet::limitKey("203.0.113.7"), AddressSet::limitKey("::ffff:203.0.113.7"));
		self::assertNotSame(AddressSet::limitKey("203.0.113.7"), AddressSet::limitKey("203.0.113.8"));
	}
}
