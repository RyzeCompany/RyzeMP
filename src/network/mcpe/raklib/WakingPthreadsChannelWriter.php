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

use pmmp\thread\ThreadSafeArray;
use pocketmine\snooze\SleeperNotifier;
use raklib\server\ipc\InterThreadChannelWriter;
use function chr;
use function socket_sendto;

/**
 * Main thread -> RakLib thread channel that wakes the RakLib thread up (a datagram to its wake socket) once the main
 * thread is done writing, so that packets leave at once instead of at RakLib's next tick (up to 10 ms later, 15.6 ms
 * on Windows).
 *
 * One wakeup per burst, not per message: the first write after a wakeup posts a notification to the main thread's own
 * sleeper, which runs {@link WakingPthreadsChannelWriter::wake()} as soon as the main thread processes notifications:
 * when the tick is over (every session flushed its batch), or right after the handler of received packets (their
 * replies). A write costs an array append and a flag check.
 *
 * @internal
 */
final class WakingPthreadsChannelWriter implements InterThreadChannelWriter{
	private bool $wakePending = false;
	private bool $held = false;
	private ?\Socket $socket = null;
	private int $port = 0;

	/**
	 * @phpstan-param ThreadSafeArray<int, string> $buffer
	 */
	public function __construct(
		private ThreadSafeArray $buffer,
		private SleeperNotifier $selfNotifier
	){}

	/**
	 * Sets where to send the wakeups (the RakLib thread's wake socket on 127.0.0.1). Until then, nothing is sent.
	 */
	public function setTarget(\Socket $socket, int $port) : void{
		$this->socket = $socket;
		$this->port = $port;
	}

	public function write(string $str) : void{
		$this->buffer[] = $str;
		if(!$this->wakePending){
			$this->wakePending = true;
			if(!$this->held){
				$this->selfNotifier->wakeupSleeper();
			}
		}
	}

	/**
	 * Holds wakeups back until {@link WakingPthreadsChannelWriter::releaseWakeups()}, which wakes the RakLib thread up
	 * directly if anything was written meanwhile (used around the handling of received packets: their replies leave
	 * without a round trip through the sleeper).
	 */
	public function holdWakeups() : void{
		$this->held = true;
	}

	public function releaseWakeups() : void{
		$this->held = false;
		if($this->wakePending){
			$this->wake();
		}
	}

	/**
	 * Wakes the RakLib thread up. Called from the main thread's sleeper (see the class description).
	 */
	public function wake() : void{
		$this->wakePending = false;
		if($this->socket !== null){
			@socket_sendto($this->socket, chr(0), 1, 0, "127.0.0.1", $this->port);
		}
	}
}
