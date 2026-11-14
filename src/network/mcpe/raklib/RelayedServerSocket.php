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

use raklib\generic\Socket;
use raklib\generic\SocketException;
use raklib\server\ServerSocket;
use raklib\utils\InternetAddress;
use function chr;
use function hash_equals;
use function inet_ntop;
use function inet_pton;
use function ord;
use function pack;
use function socket_getsockname;
use function socket_last_error;
use function socket_recvfrom;
use function socket_sendto;
use function socket_strerror;
use function strlen;
use function substr;
use function trim;
use function unpack;
use const SOCKET_EWOULDBLOCK;

/**
 * RakLib socket used when the NetherNet bridge owns the server's UDP port and shares it with RakNet: the bridge keeps
 * WebRTC datagrams for itself and relays every other datagram (RakNet, Query) to this loopback socket with the real
 * address of the sender, and sends what RakLib writes out of the shared port. RakLib sees real client addresses, so
 * sessions, bans and rate limits work as usual.
 *
 * Datagram format (both ways): u8 type. DATA (0x00): u8 IP version (4 or 6), IP address (4 or 16 bytes), u16 port,
 * payload. HELLO (0x01, bridge -> RakLib only): token, sent periodically so RakLib learns (again, after a restart of
 * the bridge) which loopback port the bridge sends from. Datagrams from any other port are ignored.
 */
class RelayedServerSocket extends ServerSocket{
	private const TYPE_DATA = 0x00;
	private const TYPE_HELLO = 0x01;

	/** Maximum number of datagrams skipped (hello, foreign) in one readPacket() call */
	private const MAX_SKIPPED = 256;

	private int $relayPort;
	private int $bridgePort = 0;

	/**
	 * @param InternetAddress $publicAddress address of the shared port (bound by the bridge), reported to RakLib
	 * @throws SocketException
	 */
	public function __construct(
		private InternetAddress $publicAddress,
		private string $token
	){
		parent::__construct(new InternetAddress("127.0.0.1", 0, 4));
		if(!@socket_getsockname($this->socket, $address, $port)){
			$error = socket_last_error($this->socket);
			throw new SocketException("Failed to get the port of the RakNet relay socket: " . trim(socket_strerror($error)), $error);
		}
		$this->relayPort = $port;
	}

	public function getBindAddress() : InternetAddress{
		return $this->publicAddress;
	}

	/**
	 * Loopback port the bridge must relay RakNet datagrams to.
	 */
	public function getRelayPort() : int{
		return $this->relayPort;
	}

	public function enableBroadcast() : bool{
		return false;
	}

	public function disableBroadcast() : bool{
		return true;
	}

	public function readPacket(?string &$source, ?int &$port) : ?string{
		for($skipped = 0; $skipped < self::MAX_SKIPPED; ++$skipped){
			$buffer = "";
			$from = "";
			$fromPort = 0;
			if(@socket_recvfrom($this->socket, $buffer, 65535, 0, $from, $fromPort) === false){
				$errno = socket_last_error($this->socket);
				if($errno === SOCKET_EWOULDBLOCK){
					return null;
				}
				throw new SocketException("Failed to recv (errno $errno): " . trim(socket_strerror($errno)), $errno);
			}
			if($from !== "127.0.0.1" || $buffer === ""){
				continue;
			}
			$type = ord($buffer[0]);
			if($type === self::TYPE_HELLO){
				if(hash_equals($this->token, substr($buffer, 1))){
					$this->bridgePort = $fromPort;
				}
				continue;
			}
			if($type !== self::TYPE_DATA || $fromPort !== $this->bridgePort || strlen($buffer) < 2){
				continue;
			}
			$version = ord($buffer[1]);
			$ipLength = $version === 4 ? 4 : ($version === 6 ? 16 : 0);
			if($ipLength === 0 || strlen($buffer) < 2 + $ipLength + 2){
				continue;
			}
			$ip = inet_ntop(substr($buffer, 2, $ipLength));
			$portBytes = unpack("n", $buffer, 2 + $ipLength);
			if($ip === false || $portBytes === false){
				continue;
			}
			$source = $ip;
			$port = (int) $portBytes[1];
			return substr($buffer, 2 + $ipLength + 2);
		}
		return null;
	}

	public function writePacket(string $buffer, string $dest, int $port) : int{
		if($this->bridgePort === 0){
			//bridge not running (yet): the datagram is lost, as on a congested network
			return strlen($buffer);
		}
		$ip = @inet_pton($dest);
		if($ip === false){
			throw new SocketException("Invalid destination address $dest");
		}
		$datagram = chr(self::TYPE_DATA) . chr(strlen($ip) === 4 ? 4 : 6) . $ip . pack("n", $port) . $buffer;
		$result = @socket_sendto($this->socket, $datagram, strlen($datagram), 0, "127.0.0.1", $this->bridgePort);
		if($result === false){
			$errno = socket_last_error($this->socket);
			throw new SocketException("Failed to relay to $dest $port (errno $errno): " . trim(socket_strerror($errno)), $errno);
		}
		return strlen($buffer);
	}
}
