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

use pmmp\thread\Thread as NativeThread;
use pmmp\thread\ThreadSafeArray;
use pocketmine\network\AddressSet;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\thread\NonThreadSafeValue;
use pocketmine\thread\Thread;
use pocketmine\thread\ThreadCrashException;
use raklib\generic\SocketException;
use raklib\server\ipc\RakLibToUserThreadMessageSender;
use raklib\server\ipc\UserToRakLibThreadMessageReceiver;
use raklib\server\Server;
use raklib\server\ServerSocket;
use raklib\server\SimpleProtocolAcceptor;
use raklib\utils\ExceptionTraceCleaner;
use raklib\utils\InternetAddress;
use function explode;
use function gc_disable;
use function ini_set;
use function method_exists;
use function socket_bind;
use function socket_create;
use function socket_getsockname;
use function socket_set_nonblock;
use const AF_INET;
use const SOCK_DGRAM;
use const SOL_UDP;

class RakLibServer extends Thread{
	protected bool $ready = false;
	protected int $relayPort = 0;
	protected int $wakePort = 0;
	protected string $mainPath;
	/** @phpstan-var NonThreadSafeValue<InternetAddress> */
	protected NonThreadSafeValue $address;

	/**
	 * @phpstan-param ThreadSafeArray<int, string> $mainToThreadBuffer
	 * @phpstan-param ThreadSafeArray<int, string> $threadToMainBuffer
	 */
	public function __construct(
		protected ThreadSafeLogger $logger,
		protected ThreadSafeArray $mainToThreadBuffer,
		protected ThreadSafeArray $threadToMainBuffer,
		InternetAddress $address,
		protected int $serverId,
		protected int $maxMtuSize,
		protected int $protocolVersion,
		protected SleeperHandlerEntry $sleeperEntry,
		protected string $relayToken = "",
		protected bool $antiSpoofingCookies = false,
		/** Maximum replies to unconnected pings (server list) per second, 0 = no limit */
		protected int $maxPongsPerSecond = 0,
		/** Comma-separated addresses and networks never blocked automatically, e.g. proxies (loopback always is) */
		protected string $autoBlockExemptAddresses = ""
	){
		$this->mainPath = \pocketmine\PATH;
		$this->address = new NonThreadSafeValue($address);
	}

	public function startAndWait(int $options = NativeThread::INHERIT_NONE) : void{
		$this->start($options);
		$this->synchronized(function() : void{
			while(!$this->ready && !$this->isTerminated()){
				$this->wait();
			}
		});
		$crashInfo = $this->getCrashInfo();
		if($crashInfo !== null){
			if($crashInfo->getType() === SocketException::class){
				throw new SocketException($crashInfo->getMessage());
			}
			throw new ThreadCrashException("RakLib failed to start", $crashInfo);
		}
	}

	protected function onRun() : void{
		//RakLib has cycles (e.g. ServerSession <-> Server) but these cycles are explicitly cleaned up anyway, and are
		//very few, so it's pointless to waste CPU time on GC
		gc_disable();

		ini_set("display_errors", '1');
		ini_set("display_startup_errors", '1');
		\GlobalLogger::set($this->logger);

		if($this->relayToken !== ""){
			$socket = new RelayedServerSocket($this->address->deserialize(), $this->relayToken);
			$this->relayPort = $socket->getRelayPort();
		}else{
			$socket = new ServerSocket($this->address->deserialize());
		}
		$manager = new Server(
			$this->serverId,
			$this->logger,
			$socket,
			$this->maxMtuSize,
			new SimpleProtocolAcceptor($this->protocolVersion),
			new UserToRakLibThreadMessageReceiver(new PthreadsChannelReader($this->mainToThreadBuffer)),
			new RakLibToUserThreadMessageSender(new SnoozeAwarePthreadsChannelWriter($this->threadToMainBuffer, $this->sleeperEntry->createNotifier())),
			new ExceptionTraceCleaner($this->mainPath),
			recvMaxSplitParts: 512,
			cookieRotationIntervalSeconds: $this->antiSpoofingCookies ? 5 : 0
		);
		//against reflection attacks with spoofed pings (needs a RakLib with the pong limit)
		/** @phpstan-ignore function.alreadyNarrowedType (older RakLib versions lack it) */
		if($this->maxPongsPerSecond > 0 && method_exists($manager, "setPongRateLimit")){
			$manager->setPongRateLimit($this->maxPongsPerSecond);
		}
		//proxies send all their players from one address: a flood block would disconnect all of them
		/** @phpstan-ignore function.alreadyNarrowedType (older RakLib versions lack it) */
		if($this->autoBlockExemptAddresses !== "" && method_exists($manager, "setAutoBlockExemption")){
			try{
				$exempt = new AddressSet(explode(",", $this->autoBlockExemptAddresses, 256));
				$manager->setAutoBlockExemption(static fn(string $address) : bool => $exempt->contains($address));
			}catch(\InvalidArgumentException){
				//already reported by the server when it read network.connection-limits.exempt-addresses
			}
		}
		//the main thread sends a datagram here when it queued packets, so they leave at once (see WakingPthreadsChannelWriter)
		//Received datagrams are still read on RakLib's tick schedule (wakeOnReceive false): waking up for each one would
		//also wake the main thread up for each one, which costs more CPU than it saves latency once several players are
		//online. Without the socket, packets wait for RakLib's next tick, as before.
		$wakeSocket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		if($wakeSocket !== false && @socket_bind($wakeSocket, "127.0.0.1", 0) && @socket_getsockname($wakeSocket, $wakeAddress, $wakePort)){
			socket_set_nonblock($wakeSocket);
			$manager->setWakeSocket($wakeSocket, false);
			$this->wakePort = $wakePort;
		}
		$this->synchronized(function() : void{
			$this->ready = true;
			$this->notify();
		});
		while(!$this->isKilled){
			$manager->tickProcessor();
		}
		$manager->waitShutdown();
	}

	/**
	 * Loopback port of the relay socket when the UDP port is shared with the NetherNet bridge, 0 otherwise.
	 */
	public function getRelayPort() : int{
		return $this->relayPort;
	}

	/**
	 * Loopback UDP port of the socket that wakes the RakLib thread up when packets were queued, 0 if there is none.
	 */
	public function getWakePort() : int{
		return $this->wakePort;
	}

	public function getThreadName() : string{
		return "RakLib";
	}
}
