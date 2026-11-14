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

namespace pocketmine\network\mcpe\proxy;

use pmmp\thread\Thread as NativeThread;
use pmmp\thread\ThreadSafeArray;
use pocketmine\network\mcpe\nethernet\NetherNetFrameException;
use pocketmine\network\mcpe\nethernet\NetherNetFrameReader;
use pocketmine\network\mcpe\nethernet\NetherNetLinkThread;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\Thread;
use function array_keys;
use function array_values;
use function explode;
use function gc_disable;
use function in_array;
use function microtime;
use function ord;
use function pack;
use function random_bytes;
use function socket_accept;
use function socket_bind;
use function socket_close;
use function socket_create;
use function socket_getpeername;
use function socket_getsockname;
use function socket_last_error;
use function socket_listen;
use function socket_recv;
use function socket_select;
use function socket_send;
use function socket_set_nonblock;
use function socket_set_option;
use function socket_strerror;
use function strlen;
use function substr;
use function trim;
use function unpack;
use const AF_INET;
use const SO_REUSEADDR;
use const SOCK_DGRAM;
use const SOCK_STREAM;
use const SOCKET_EWOULDBLOCK;
use const SOL_SOCKET;
use const SOL_TCP;
use const SOL_UDP;
use const TCP_NODELAY;

/**
 * @internal
 * Listens for pm-link v2 connections from pm-proxy and moves frames between the authenticated links and the main
 * thread. A proxy proves it holds a trusted key by signing a random challenge. Several proxies can be linked at once
 * (a network with several proxies, sharing state through Redis); a proxy that connects again replaces its own old
 * link, whose sessions are dropped.
 *
 * Frames are those of the NetherNet bridge link: u32 BE length of (type + body), u8 type, body. Between this thread
 * and the main thread, every message names its link by a u32 link ID.
 */
final class ProxyLinkThread extends Thread{
	/** Main thread: a proxy authenticated; u32 link ID, then its proxy ID. */
	public const LINK_UP = "\xf1";
	/** Main thread: a link was lost; u32 link ID. */
	public const LINK_DOWN = "\xf0";
	/** Main thread: a connection was refused; the rest of the frame is why. */
	public const LINK_REFUSED = "\xf2";
	/** Main thread: a frame from a proxy; u32 link ID, then the frame (type + body). */
	public const LINK_FRAME = "\xf3";
	/** Link ID of frames the main thread sends to every link. */
	public const BROADCAST = 0xffffffff;

	public const FRAME_HELLO = 0x40;
	public const FRAME_CHALLENGE = 0x50;
	public const FRAME_HELLO_ACK = 0x51;
	public const LINK_VERSION = 2;
	/** Features told to the proxy in the hello ack: bit 0 = resource packs sent by the proxy (ticket "packs") */
	public const FEATURES = 1;

	private const MAX_OUT_BUFFER = 256 * 1024 * 1024;
	private const MAX_FRAME = 64 * 1024 * 1024;
	private const HANDSHAKE_TIMEOUT = 10;
	private const MAX_KEYS = 64;

	private bool $ready = false;
	private int $wakePort = 0;
	private string $startError = "";

	/**
	 * @param string $publicKeys trusted proxy keys (base64 DER), one per line
	 * @phpstan-param ThreadSafeArray<int, string> $mainToThread
	 * @phpstan-param ThreadSafeArray<int, string> $threadToMain
	 */
	public function __construct(
		private ThreadSafeArray $mainToThread,
		private ThreadSafeArray $threadToMain,
		private SleeperHandlerEntry $sleeperEntry,
		private string $bindHost,
		private int $bindPort,
		private string $publicKeys,
		private string $serverName,
		private string $softwareVersion
	){}

	public function startAndWait() : void{
		$this->start(NativeThread::INHERIT_NONE);
		$this->synchronized(function() : void{
			while(!$this->ready && !$this->isTerminated()){
				$this->wait();
			}
		});
		if($this->startError !== "" || $this->wakePort === 0){
			throw new \RuntimeException($this->startError !== "" ? $this->startError : "pm-link thread terminated");
		}
	}

	public function getWakePort() : int{
		return $this->wakePort;
	}

	private function fail(string $error) : void{
		$this->synchronized(function() use ($error) : void{
			$this->startError = $error;
			$this->ready = true;
			$this->notify();
		});
	}

	protected function onRun() : void{
		gc_disable();
		$notifier = $this->sleeperEntry->createNotifier();

		$keys = [];
		foreach(explode("\n", $this->publicKeys, self::MAX_KEYS) as $b64){
			if(trim($b64) !== ""){
				$keys[] = trim($b64);
			}
		}
		try{
			$verifier = new ProxyTicketVerifier($keys, $this->serverName);
		}catch(\InvalidArgumentException $e){
			$this->fail($e->getMessage());
			return;
		}

		$listen = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if($listen === false){
			$this->fail("socket_create: " . socket_strerror(socket_last_error()));
			return;
		}
		@socket_set_option($listen, SOL_SOCKET, SO_REUSEADDR, 1);
		if(!@socket_bind($listen, $this->bindHost, $this->bindPort) || !@socket_listen($listen, 8)){
			$this->fail("cannot listen on {$this->bindHost}:{$this->bindPort}: " . socket_strerror(socket_last_error($listen)));
			socket_close($listen);
			return;
		}
		socket_set_nonblock($listen);
		$wakeSocket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		if($wakeSocket === false || !@socket_bind($wakeSocket, "127.0.0.1", 0) || !@socket_getsockname($wakeSocket, $wakeAddr, $wakePort)){
			$this->fail("wake socket on 127.0.0.1: " . socket_strerror(socket_last_error()));
			socket_close($listen);
			return;
		}
		socket_set_nonblock($wakeSocket);
		$this->synchronized(function() use ($wakePort) : void{
			$this->wakePort = $wakePort;
			$this->ready = true;
			$this->notify();
		});

		/**
		 * Authenticated links, by link ID (one per proxy).
		 * @var \Socket[] $links
		 * @phpstan-var array<int, \Socket> $links
		 */
		$links = [];
		/** @phpstan-var array<int, string> $linkProxy link ID => proxy ID */
		$linkProxy = [];
		/** @phpstan-var array<int, string> $ins */
		$ins = [];
		/** @phpstan-var array<int, string> $outs */
		$outs = [];
		$nextLinkId = 1;
		/** Connection waiting for its Hello */
		$pending = null;
		$pendingIn = "";
		$pendingOut = "";
		$challenge = "";
		$pendingDeadline = 0.0;

		while(!$this->isKilled){
			$read = [$listen, $wakeSocket, ...array_values($links)];
			if($pending !== null){
				$read[] = $pending;
			}
			$write = [];
			foreach($links as $id => $socket){
				if($outs[$id] !== ""){
					$write[] = $socket;
				}
			}
			if($pending !== null && $pendingOut !== ""){
				$write[] = $pending;
			}
			$except = null;
			$n = @socket_select($read, $write, $except, 1, 0);
			$wake = false;
			/** @phpstan-var array<int, true> $drop */
			$drop = [];
			$dropPending = null;

			if($n !== false && $n > 0){
				if(in_array($wakeSocket, $read, true)){
					$discard = "";
					while(@socket_recv($wakeSocket, $discard, 64, 0) !== false){
						//drain
					}
				}
				if(in_array($listen, $read, true)){
					$accepted = @socket_accept($listen);
					if($accepted !== false){
						if($pending !== null){
							socket_close($pending);
						}
						socket_set_nonblock($accepted);
						@socket_set_option($accepted, SOL_TCP, TCP_NODELAY, 1);
						$pending = $accepted;
						$pendingIn = "";
						$challenge = random_bytes(32);
						$pendingOut = NetherNetLinkThread::encodeFrame(self::FRAME_CHALLENGE, $challenge);
						$pendingDeadline = microtime(true) + self::HANDSHAKE_TIMEOUT;
					}
				}
				if($pending !== null && in_array($pending, $read, true)){
					$buf = "";
					$len = @socket_recv($pending, $buf, 65536, 0);
					if($len === false){
						if(!self::isWouldBlock(socket_last_error($pending))){
							$dropPending = "connection error";
						}
					}elseif($len === 0 || $buf === null){
						$dropPending = "closed before authenticating";
					}else{
						$pendingIn .= $buf;
						$frame = self::takeFrame($pendingIn);
						if($frame === false){
							$dropPending = "invalid frame";
						}elseif($frame !== null){
							$proxyId = self::checkHello($frame, $challenge, $verifier);
							if($proxyId === null){
								$dropPending = "the proxy is not trusted (its public key is not in network.proxy.public-keys) or sent an invalid hello";
							}else{
								//a proxy that connects again (restarted) replaces its old link; other proxies keep theirs
								foreach($linkProxy as $oldId => $oldProxy){
									if($oldProxy === $proxyId){
										socket_close($links[$oldId]);
										unset($links[$oldId], $linkProxy[$oldId], $ins[$oldId], $outs[$oldId]);
										$this->threadToMain[] = self::LINK_DOWN . pack("N", $oldId);
									}
								}
								$id = $nextLinkId++;
								$links[$id] = $pending;
								$linkProxy[$id] = $proxyId;
								$ins[$id] = $pendingIn;
								$outs[$id] = $pendingOut . NetherNetLinkThread::encodeFrame(self::FRAME_HELLO_ACK, self::encodeString($this->serverName) . self::encodeString($this->softwareVersion) . pack("NN", ProtocolInfo::CURRENT_PROTOCOL, self::FEATURES));
								$pending = null;
								$pendingIn = "";
								$pendingOut = "";
								$this->threadToMain[] = self::LINK_UP . pack("N", $id) . $proxyId;
								$wake = true;
							}
						}
					}
				}
				if($pending !== null && $pendingOut !== "" && in_array($pending, $write, true) && !self::flush($pending, $pendingOut)){
					$dropPending ??= "connection error";
				}

				foreach($links as $id => $socket){
					if(!in_array($socket, $read, true)){
						continue;
					}
					$buf = "";
					$len = @socket_recv($socket, $buf, 262144, 0);
					if($len === false){
						if(!self::isWouldBlock(socket_last_error($socket))){
							$drop[$id] = true;
						}
					}elseif($len === 0 || $buf === null){
						$drop[$id] = true;
					}else{
						$ins[$id] .= $buf;
						$prefix = self::LINK_FRAME . pack("N", $id);
						while(($frame = self::takeFrame($ins[$id])) !== null){
							if($frame === false){
								$drop[$id] = true;
								break;
							}
							$this->threadToMain[] = $prefix . $frame;
							$wake = true;
						}
					}
				}
			}
			if($pending !== null && $dropPending === null && microtime(true) > $pendingDeadline){
				$dropPending = "no hello within " . self::HANDSHAKE_TIMEOUT . " seconds";
			}
			if($pending !== null && $dropPending !== null){
				@socket_getpeername($pending, $peer);
				socket_close($pending);
				$pending = null;
				$this->threadToMain[] = self::LINK_REFUSED . "connection from " . ($peer ?? "?") . ": " . $dropPending;
				$wake = true;
			}

			//frames from the main thread: u32 link ID (BROADCAST for every link), then the encoded frame
			/** @phpstan-var array<int, true> $queued */
			$queued = [];
			while(($message = $this->mainToThread->shift()) !== null){
				$target = unpack("N", $message);
				if($target === false){
					continue;
				}
				$target = (int) $target[1];
				$frame = substr($message, 4);
				foreach($target === self::BROADCAST ? array_keys($links) : [$target] as $id){
					if(isset($links[$id]) && strlen($outs[$id]) < self::MAX_OUT_BUFFER){
						$outs[$id] .= $frame;
						$queued[$id] = true;
					}
				}
			}
			foreach($links as $id => $socket){
				if(!isset($drop[$id]) && $outs[$id] !== "" && (isset($queued[$id]) || in_array($socket, $write, true))){
					if(!self::flush($socket, $outs[$id])){
						$drop[$id] = true;
					}
				}
			}

			foreach($drop as $id => $_){
				if(isset($links[$id])){
					socket_close($links[$id]);
					unset($links[$id], $linkProxy[$id], $ins[$id], $outs[$id]);
					$this->threadToMain[] = self::LINK_DOWN . pack("N", $id);
					$wake = true;
				}
			}

			if($wake){
				$notifier->wakeupSleeper();
			}
		}

		foreach($links as $socket){
			socket_close($socket);
		}
		if($pending !== null){
			socket_close($pending);
		}
		socket_close($listen);
		socket_close($wakeSocket);
	}

	/**
	 * Returns the proxy ID if the hello is valid and signed by a trusted proxy.
	 */
	private static function checkHello(string $frame, string $challenge, ProxyTicketVerifier $verifier) : ?string{
		if(ord($frame[0]) !== self::FRAME_HELLO){
			return null;
		}
		try{
			$r = new NetherNetFrameReader($frame, 1);
			$version = $r->u8();
			$proxyId = $r->string();
			$signature = $r->bytes($r->u16());
		}catch(NetherNetFrameException){
			return null;
		}
		if($version !== self::LINK_VERSION || $proxyId === ""){
			return null;
		}
		return $verifier->verifySignature("pm-link-v2\x00" . $challenge . $proxyId, $signature) ? $proxyId : null;
	}

	/**
	 * Removes the next complete frame from $in.
	 * @return string|false|null the frame (type + body), null if incomplete, false if invalid
	 */
	private static function takeFrame(string &$in) : string|false|null{
		if(strlen($in) < 4){
			return null;
		}
		$header = unpack("N", $in);
		if($header === false){
			return false;
		}
		$frameLen = (int) $header[1];
		if($frameLen < 1 || $frameLen > self::MAX_FRAME){
			return false;
		}
		if(strlen($in) - 4 < $frameLen){
			return null;
		}
		$frame = substr($in, 4, $frameLen);
		$in = substr($in, 4 + $frameLen);
		return $frame;
	}

	private static function flush(\Socket $socket, string &$out) : bool{
		$sent = @socket_send($socket, $out, strlen($out), 0);
		if($sent === false){
			return self::isWouldBlock(socket_last_error($socket));
		}
		$out = substr($out, $sent);
		return true;
	}

	private static function isWouldBlock(int $error) : bool{
		//SOCKET_EAGAIN has the same value on the platforms we support
		return $error === SOCKET_EWOULDBLOCK;
	}

	public static function encodeString(string $s) : string{
		$s = substr($s, 0, 0xffff);
		return pack("n", strlen($s)) . $s;
	}

	public function getThreadName() : string{
		return "ProxyLink";
	}
}
