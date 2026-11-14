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

namespace pocketmine\network\mcpe\nethernet;

use pmmp\thread\Thread as NativeThread;
use pmmp\thread\ThreadSafeArray;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\Thread;
use function chr;
use function explode;
use function gc_disable;
use function hash_equals;
use function implode;
use function in_array;
use function ord;
use function socket_accept;
use function socket_bind;
use function socket_close;
use function socket_connect;
use function socket_create;
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
use function unlink;
use const AF_INET;
use const AF_UNIX;
use const SO_RCVBUF;
use const SO_SNDBUF;
use const SOCK_DGRAM;
use const SOCK_STREAM;
use const SOCKET_EWOULDBLOCK;
use const SOL_SOCKET;
use const SOL_TCP;
use const SOL_UDP;
use const TCP_NODELAY;

/**
 * Owns the link to the NetherNet bridge process. What the bridge sends is handed to the main thread as raw byte chunks
 * (one per read; the main thread splits them into frames), waking it up through the sleeper. Frames queued by the main
 * thread are written to the bridge whenever this thread wakes up: when the main thread asks for it (once per burst),
 * and also whenever data from the bridge arrives, so frames queued during a tick leave without waiting for its end.
 *
 * Besides the data strings, $threadToMain carries ints: LINK_UP_INT when the bridge connected and authenticated,
 * LINK_DOWN_INT when the link was lost.
 *
 * The bridge connects over a Unix socket when one could be opened (a write costs a fraction of a TCP write on loopback,
 * on Windows especially), else over TCP. The listeners stay open for the lifetime of the thread so a restarted bridge
 * can reconnect.
 */
final class NetherNetLinkThread extends Thread{
	/** @deprecated The link state is signalled with LINK_UP_INT and LINK_DOWN_INT now. */
	public const LINK_UP = "\xf1";
	/** @deprecated The link state is signalled with LINK_UP_INT and LINK_DOWN_INT now. */
	public const LINK_DOWN = "\xf0";

	public const LINK_UP_INT = 1;
	public const LINK_DOWN_INT = -1;

	/** Unix socket paths longer than this are not used (sun_path is 104 bytes on macOS, 108 elsewhere). */
	public const MAX_UNIX_PATH = 100;

	private const MAX_OUT_BUFFER = 256 * 1024 * 1024;
	/** Socket buffers of the link in each direction: a burst fits without waiting for the other side */
	private const SOCKET_BUFFER = 4 * 1024 * 1024;

	private bool $ready = false;
	private int $port = 0;
	private int $wakePort = 0;
	private string $startError = "";
	private string $unixPathInUse = "";
	private string $unixError = "";
	private string $linkDescription = "";

	/**
	 * @phpstan-param ThreadSafeArray<int, string> $mainToThread
	 * @phpstan-param ThreadSafeArray<int, string|int> $threadToMain
	 * @param string $unixPaths Unix socket paths to try, one per line (the first one that works is offered to the
	 *                          bridge), "" for TCP only
	 */
	public function __construct(
		private ThreadSafeArray $mainToThread,
		private ThreadSafeArray $threadToMain,
		private SleeperHandlerEntry $sleeperEntry,
		private string $token,
		private string $unixPaths = ""
	){}

	/**
	 * Starts the thread and returns the loopback TCP port the bridge must connect to.
	 * @throws \RuntimeException
	 */
	public function startAndWait() : int{
		$this->start(NativeThread::INHERIT_NONE);
		$this->synchronized(function() : void{
			while(!$this->ready && !$this->isTerminated()){
				$this->wait();
			}
		});
		if($this->startError !== "" || $this->port === 0){
			throw new \RuntimeException("NetherNet link failed to start: " . ($this->startError !== "" ? $this->startError : "thread terminated"));
		}
		return $this->port;
	}

	/**
	 * Loopback UDP port that wakes the thread up when the main thread queued frames.
	 */
	public function getWakePort() : int{
		return $this->wakePort;
	}

	/**
	 * Unix socket the bridge should connect to, or "" if none could be opened (see getUnixError()).
	 */
	public function getUnixPath() : string{
		return $this->unixPathInUse;
	}

	public function getUnixError() : string{
		return $this->unixError;
	}

	/**
	 * Transport and link version of the current link, for logging.
	 */
	public function getLinkDescription() : string{
		return $this->linkDescription;
	}

	private function fail(string $error) : void{
		$this->synchronized(function() use ($error) : void{
			$this->startError = $error;
			$this->ready = true;
			$this->notify();
		});
	}

	/**
	 * Opens the Unix socket listener and checks that a connection to it works (on Windows, some folders accept the
	 * bind but refuse connections).
	 */
	private static function openUnixListener(string $path, string &$error) : ?\Socket{
		if(strlen($path) > self::MAX_UNIX_PATH){
			$error = "path too long";
			return null;
		}
		@unlink($path);
		$listen = @socket_create(AF_UNIX, SOCK_STREAM, 0);
		if($listen === false){
			$error = "socket_create: " . socket_strerror(socket_last_error());
			return null;
		}
		if(!@socket_bind($listen, $path) || !@socket_listen($listen, 4)){
			$error = "bind/listen: " . socket_strerror(socket_last_error($listen));
			socket_close($listen);
			@unlink($path);
			return null;
		}
		$client = @socket_create(AF_UNIX, SOCK_STREAM, 0);
		$ok = $client !== false && @socket_connect($client, $path);
		if($ok){
			$accepted = @socket_accept($listen);
			$ok = $accepted !== false;
			if($accepted !== false){
				socket_close($accepted);
			}
		}else{
			$error = "test connection: " . socket_strerror($client !== false ? socket_last_error($client) : socket_last_error());
		}
		if($client !== false){
			socket_close($client);
		}
		if(!$ok){
			socket_close($listen);
			@unlink($path);
			return null;
		}
		socket_set_nonblock($listen);
		return $listen;
	}

	protected function onRun() : void{
		gc_disable();
		$notifier = $this->sleeperEntry->createNotifier();

		$listen = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if($listen === false){
			$this->fail("socket_create: " . socket_strerror(socket_last_error()));
			return;
		}
		if(!@socket_bind($listen, "127.0.0.1", 0) || !@socket_listen($listen, 4) || !@socket_getsockname($listen, $addr, $port)){
			$this->fail("bind/listen on 127.0.0.1: " . socket_strerror(socket_last_error($listen)));
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
		$unixListen = null;
		$unixPath = "";
		$unixErrors = [];
		foreach($this->unixPaths !== "" ? explode("\n", $this->unixPaths, 8) : [] as $candidate){
			$error = "";
			$unixListen = self::openUnixListener($candidate, $error);
			if($unixListen !== null){
				$unixPath = $candidate;
				break;
			}
			$unixErrors[] = "$candidate: $error";
		}
		$unixError = implode(", ", $unixErrors);
		$this->synchronized(function() use ($port, $wakePort, $unixPath, $unixError) : void{
			$this->port = $port;
			$this->wakePort = $wakePort;
			$this->unixPathInUse = $unixPath;
			$this->unixError = $unixError;
			$this->ready = true;
			$this->notify();
		});

		$link = null;
		$linkIsUnix = false;
		$authed = false;
		$in = "";
		$out = "";
		while(!$this->isKilled){
			//Event-driven: wakes up when the bridge sends something, when queued data can be written, or when the main
			//thread queues frames (it sends a datagram to the wake socket, see wakeUp()). The timeout is only a safety net.
			$read = [$wakeSocket];
			if($link !== null){
				$read[] = $link;
			}else{
				$read[] = $listen;
				if($unixListen !== null){
					$read[] = $unixListen;
				}
			}
			$write = $link !== null && $authed && $out !== "" ? [$link] : [];
			$except = null;
			$n = @socket_select($read, $write, $except, 1, 0);
			$wake = false;
			$drop = false;

			if($n !== false && $n > 0){
				if(in_array($wakeSocket, $read, true)){
					$discard = "";
					while(@socket_recv($wakeSocket, $discard, 64, 0) !== false){
						//drain
					}
				}
				if($link === null){
					foreach([$listen, $unixListen] as $listener){
						if($listener !== null && in_array($listener, $read, true)){
							$accepted = @socket_accept($listener);
							if($accepted !== false){
								socket_set_nonblock($accepted);
								@socket_set_option($accepted, SOL_SOCKET, SO_SNDBUF, self::SOCKET_BUFFER);
								@socket_set_option($accepted, SOL_SOCKET, SO_RCVBUF, self::SOCKET_BUFFER);
								$linkIsUnix = $listener === $unixListen;
								if(!$linkIsUnix){
									@socket_set_option($accepted, SOL_TCP, TCP_NODELAY, 1);
								}
								$link = $accepted;
								$authed = false;
								$in = "";
								$out = "";
								break;
							}
						}
					}
				}elseif(in_array($link, $read, true)){
					$buf = "";
					$len = @socket_recv($link, $buf, 262144, 0);
					if($len === false){
						$drop = !self::isWouldBlock(socket_last_error($link));
					}elseif($len === 0 || $buf === null){
						$drop = true;
					}elseif($authed){
						$this->threadToMain[] = $buf;
						$wake = true;
					}else{
						$in .= $buf;
						$version = self::readAuth($in, $this->token);
						if($version === false){
							$drop = true;
						}elseif($version !== null){
							$authed = true;
							$this->linkDescription = ($linkIsUnix ? "Unix socket" : "TCP") . ", link version $version";
							$this->threadToMain[] = self::LINK_UP_INT;
							if($in !== ""){
								$this->threadToMain[] = $in;
								$in = "";
							}
							$wake = true;
						}
					}
				}
			}

			//frames queued by the main thread go out on every wakeup, not only when the main thread asks for it
			while(($frame = $this->mainToThread->shift()) !== null){
				if($link !== null && $authed && strlen($out) < self::MAX_OUT_BUFFER){
					$out .= $frame;
				}
			}
			if(!$drop && $link !== null && $out !== ""){
				$sent = @socket_send($link, $out, strlen($out), 0);
				if($sent === false){
					$drop = !self::isWouldBlock(socket_last_error($link));
				}else{
					$out = substr($out, $sent);
				}
			}

			if($drop && $link !== null){
				socket_close($link);
				$link = null;
				$in = "";
				$out = "";
				if($authed){
					$this->threadToMain[] = self::LINK_DOWN_INT;
					$wake = true;
				}
				$authed = false;
			}

			if($wake){
				$notifier->wakeupSleeper();
			}
		}

		if($link !== null){
			socket_close($link);
		}
		socket_close($listen);
		socket_close($wakeSocket);
		if($unixListen !== null){
			socket_close($unixListen);
			@unlink($unixPath);
		}
	}

	private static function isWouldBlock(int $error) : bool{
		//SOCKET_EAGAIN has the same value on the platforms we support
		return $error === SOCKET_EWOULDBLOCK;
	}

	/**
	 * Checks the first frame of a link, which must be AUTH carrying the token given to the bridge process, optionally
	 * followed by the link version of the bridge (bridges before link version 2 send the token only). Consumes the
	 * frame from $in.
	 *
	 * @return int|false|null the link version, false if the link must be dropped, null if more data is needed
	 */
	private static function readAuth(string &$in, string $token) : int|false|null{
		if(strlen($in) < 4){
			return null;
		}
		$frameLen = (ord($in[0]) << 24) | (ord($in[1]) << 16) | (ord($in[2]) << 8) | ord($in[3]);
		if($frameLen < 3 || $frameLen > 4096){
			return false;
		}
		if(strlen($in) < 4 + $frameLen){
			return null;
		}
		$frame = substr($in, 4, $frameLen);
		$in = substr($in, 4 + $frameLen);
		$tokenLen = (ord($frame[1]) << 8) | ord($frame[2]);
		if($frame[0] !== "\x00" || $frameLen < 3 + $tokenLen || !hash_equals($token, substr($frame, 3, $tokenLen))){
			return false;
		}
		return $frameLen > 3 + $tokenLen ? ord($frame[3 + $tokenLen]) : 1;
	}

	public function getThreadName() : string{
		return "NetherNetLink";
	}

	/**
	 * Encodes a frame for the wire. Type is a single byte.
	 */
	public static function encodeFrame(int $type, string $body) : string{
		$len = strlen($body) + 1;
		return chr(($len >> 24) & 0xff) . chr(($len >> 16) & 0xff) . chr(($len >> 8) & 0xff) . chr($len & 0xff) . chr($type) . $body;
	}
}
