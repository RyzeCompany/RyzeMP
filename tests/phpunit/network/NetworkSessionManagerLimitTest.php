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
use pocketmine\network\mcpe\NetworkSession;

class NetworkSessionManagerLimitTest extends TestCase{

	private function session(string $ip, bool $connected = true) : NetworkSession{
		$session = $this->createMock(NetworkSession::class);
		$session->method("getIp")->willReturn($ip);
		$session->method("isConnected")->willReturn($connected);
		return $session;
	}

	private function manager(int $limit, string ...$exempt) : NetworkSessionManager{
		$manager = new NetworkSessionManager();
		$manager->setPendingLoginLimit($limit, new AddressSet($exempt), new class extends \SimpleLogger{
			public function log($level, $message){
				//silent
			}
		});
		return $manager;
	}

	public function testPendingLimitPerAddress() : void{
		$manager = $this->manager(3, "192.0.2.1");
		$sessions = [];
		for($i = 0; $i < 3; $i++){
			self::assertTrue($manager->acceptsNewSession("203.0.113.7"));
			$manager->add($sessions[] = $this->session("203.0.113.7"));
		}
		self::assertFalse($manager->acceptsNewSession("203.0.113.7"), "fourth pending session from one address");
		self::assertTrue($manager->acceptsNewSession("198.51.100.1"), "another address");
		self::assertTrue($manager->acceptsNewSession("::ffff:192.0.2.1"), "exempt address");

		//players who sent their login do not count: many players can share one address
		$manager->markLoginReceived($sessions[0]);
		self::assertSame(2, $manager->getPendingLoginSessionCount("203.0.113.7"));
		self::assertTrue($manager->acceptsNewSession("203.0.113.7"));
		for($i = 0; $i < 50; $i++){
			$manager->add($s = $this->session("203.0.113.7"));
			$manager->markLoginReceived($s);
		}
		self::assertSame(2, $manager->getPendingLoginSessionCount("203.0.113.7"));
		self::assertTrue($manager->acceptsNewSession("203.0.113.7"));

		$manager->remove($sessions[1]);
		$manager->remove($sessions[1]);
		self::assertSame(1, $manager->getPendingLoginSessionCount("203.0.113.7"));
	}

	public function testIpv6CountsPerPrefix() : void{
		$manager = $this->manager(2);
		$manager->add($this->session("2001:db8:1:2::1"));
		$manager->add($this->session("2001:db8:1:2::2"));
		self::assertFalse($manager->acceptsNewSession("2001:db8:1:2::3"));
		self::assertTrue($manager->acceptsNewSession("2001:db8:1:3::1"));
	}

	public function testDisconnectedPendingSessionsAreReleased() : void{
		$manager = $this->manager(1);
		$manager->add($this->session("203.0.113.7", connected: false));
		self::assertFalse($manager->acceptsNewSession("203.0.113.7"));
		$manager->tick();
		self::assertSame(0, $manager->getPendingLoginSessionCount("203.0.113.7"), "a session that left before logging in stayed pending");
		self::assertTrue($manager->acceptsNewSession("203.0.113.7"));
		self::assertSame(0, $manager->getSessionCount());
		self::assertSame(0, $manager->getValidSessionCount());
	}

	public function testNoLimit() : void{
		$manager = new NetworkSessionManager();
		for($i = 0; $i < 100; $i++){
			$manager->add($this->session("203.0.113.7"));
		}
		self::assertTrue($manager->acceptsNewSession("203.0.113.7"));
		$limited = $this->manager(0);
		$limited->add($this->session("203.0.113.7"));
		self::assertTrue($limited->acceptsNewSession("203.0.113.7"));
	}
}
