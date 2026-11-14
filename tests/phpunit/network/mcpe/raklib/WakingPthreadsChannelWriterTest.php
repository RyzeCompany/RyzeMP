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

namespace pocketmine\network\mcpe\raklib;

use PHPUnit\Framework\TestCase;
use pmmp\thread\ThreadSafeArray;
use pocketmine\snooze\SleeperHandler;
use function socket_bind;
use function socket_close;
use function socket_create;
use function socket_getsockname;
use function socket_recv;
use function socket_select;
use function socket_set_nonblock;
use const AF_INET;
use const SOCK_DGRAM;
use const SOL_UDP;

final class WakingPthreadsChannelWriterTest extends TestCase{

	public function testOneWakeupPerBurst() : void{
		$sleeper = new SleeperHandler();
		/** @phpstan-var ThreadSafeArray<int, string> $buffer */
		$buffer = new ThreadSafeArray();
		$state = new \stdClass();
		$state->wakeups = 0;
		$state->writer = null;
		$entry = $sleeper->addNotifier(function() use ($state) : void{
			$state->wakeups++;
			if($state->writer instanceof WakingPthreadsChannelWriter){
				$state->writer->wake();
			}
		});
		$writer = new WakingPthreadsChannelWriter($buffer, $entry->createNotifier());
		$state->writer = $writer;

		$target = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		self::assertNotFalse($target);
		self::assertTrue(socket_bind($target, "127.0.0.1", 0));
		self::assertTrue(socket_getsockname($target, $address, $port));
		socket_set_nonblock($target);
		$sender = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		self::assertNotFalse($sender);
		$writer->setTarget($sender, $port);

		//a burst of writes (e.g. every session flushing at the end of a tick): one notification, one datagram
		for($i = 0; $i < 10; $i++){
			$writer->write("packet $i");
		}
		self::assertCount(10, $buffer);
		self::assertSame(0, $state->wakeups, "nothing is sent before the main thread processes its notifications");
		$sleeper->processNotifications();
		self::assertSame(1, $state->wakeups);
		self::assertSame(1, self::countDatagrams($target));

		//no writes, no wakeup
		$sleeper->processNotifications();
		self::assertSame(1, $state->wakeups);
		self::assertSame(0, self::countDatagrams($target));

		//the next burst wakes the thread up again
		$writer->write("again");
		$sleeper->processNotifications();
		self::assertSame(2, $state->wakeups);
		self::assertSame(1, self::countDatagrams($target));
		self::assertCount(11, $buffer, "the writer only appends: the RakLib thread consumes");

		socket_close($sender);
		socket_close($target);
	}

	public function testWithoutTargetNothingIsSent() : void{
		$sleeper = new SleeperHandler();
		/** @phpstan-var ThreadSafeArray<int, string> $buffer */
		$buffer = new ThreadSafeArray();
		$state = new \stdClass();
		$state->writer = null;
		$entry = $sleeper->addNotifier(function() use ($state) : void{
			if($state->writer instanceof WakingPthreadsChannelWriter){
				$state->writer->wake();
			}
		});
		$writer = new WakingPthreadsChannelWriter($buffer, $entry->createNotifier());
		$state->writer = $writer;
		$writer->write("x");
		$sleeper->processNotifications();
		self::assertSame(["x"], (array) $buffer);
	}

	public function testHeldWakeupsAreSentOnRelease() : void{
		$sleeper = new SleeperHandler();
		/** @phpstan-var ThreadSafeArray<int, string> $buffer */
		$buffer = new ThreadSafeArray();
		$state = new \stdClass();
		$state->wakeups = 0;
		$entry = $sleeper->addNotifier(function() use ($state) : void{
			$state->wakeups++;
		});
		$writer = new WakingPthreadsChannelWriter($buffer, $entry->createNotifier());
		$target = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		self::assertNotFalse($target);
		self::assertTrue(socket_bind($target, "127.0.0.1", 0));
		self::assertTrue(socket_getsockname($target, $address, $port));
		socket_set_nonblock($target);
		$sender = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		self::assertNotFalse($sender);
		$writer->setTarget($sender, $port);

		//replies written while received packets are handled: no notification, one datagram when the handler is done
		$writer->holdWakeups();
		$writer->write("reply 1");
		$writer->write("reply 2");
		$writer->releaseWakeups();
		self::assertSame(1, self::countDatagrams($target));
		$sleeper->processNotifications();
		self::assertSame(0, $state->wakeups);

		//nothing written while held: nothing sent
		$writer->holdWakeups();
		$writer->releaseWakeups();
		self::assertSame(0, self::countDatagrams($target));

		socket_close($sender);
		socket_close($target);
	}

	private static function countDatagrams(\Socket $socket) : int{
		//give a datagram in flight on the loopback interface a moment to arrive
		$read = [$socket];
		$write = $except = null;
		@socket_select($read, $write, $except, 0, 200_000);
		$count = 0;
		$buf = "";
		while(@socket_recv($socket, $buf, 64, 0) !== false){
			$count++;
		}
		return $count;
	}
}
