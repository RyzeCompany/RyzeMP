<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

use pmmp\thread\ThreadSafeArray;
use raklib\server\ServerSocket;
use raklib\utils\InternetAddress;

/**
 * Single public UDP socket owner: RakLib receives all legacy datagrams while
 * authorized ICE/DTLS peers are forwarded to a PRIVATE 127.0.0.1 ICE UDP mux.
 *
 * The backend must be started separately by the authenticated NetherNet
 * signaling transport and must bind only 127.0.0.1:$iceBackendPort.
 *
 * A connected loopback UDP socket per public peer preserves its 5-tuple for
 * native ICE; the native backend sees the relay address, not the player's IP.
 * The validated public address is supplied separately by signaling.
 *
 * Thread ownership: instantiated and driven ONLY on RakLib's worker thread.
 * The command queue is the sole cross-thread authorization interface.
 *
 * @internal
 */
final class NetherNetSharedPortSocket extends ServerSocket{
	private const MAX_AUTHORIZED = 1024;
	private const MAX_RELAYS = 256;
	private const MAX_DATAGRAM = 65535;
	private const IDLE_SECONDS = 120;
	private const MAX_READ_SKIP = 32;
	private const MAX_BACKEND_DRAIN = 64;

	/** @var array<string, true> */
	private array $authorized = [];
	/** @var array<string, array{socket: \Socket, ufrag: string, ip: string, port: int, last: float}> */
	private array $relays = [];
	private float $lastCleanup = 0.0;

	/** @phpstan-param ThreadSafeArray<int, string> $commands */
	public function __construct(
		InternetAddress $bindAddress,
		private int $iceBackendPort,
		private ThreadSafeArray $commands
	){
		if($bindAddress->getVersion() !== 4){
			throw new \InvalidArgumentException("The first shared-port implementation supports IPv4 only");
		}
		if($iceBackendPort < 1 || $iceBackendPort > 65535){
			throw new \InvalidArgumentException("Invalid private ICE backend UDP port");
		}
		parent::__construct($bindAddress);
	}

	/** Parses STUN USERNAME as "server-ufrag:client-ufrag", with bounded TLVs. */
	public static function requestedUfrag(string $datagram) : ?string{
		if(NetherNetDatagramClassifier::classify($datagram) !== NetherNetDatagramClassifier::STUN ||
			strlen($datagram) < 20 || unpack("n", substr($datagram, 0, 2))[1] !== 1){
			return null;
		}
		$end = strlen($datagram);
		for($i = 20; $i + 4 <= $end;){
			$type = unpack("n", substr($datagram, $i, 2))[1];
			$length = unpack("n", substr($datagram, $i + 2, 2))[1];
			$i += 4;
			if($length > $end - $i){
				return null;
			}
			if($type === 6){
				if($length < 3 || $length > 512){
					return null;
				}
				$user = substr($datagram, $i, $length);
				$colon = strpos($user, ":");
				$ufrag = $colon === false ? "" : substr($user, 0, $colon);
				return self::validUfrag($ufrag) ? $ufrag : null;
			}
			$i += ($length + 3) & ~3;
		}
		return null;
	}

	private static function validUfrag(string $ufrag) : bool{
		return strlen($ufrag) >= 4 && strlen($ufrag) <= 256 &&
			preg_match('/\A[A-Za-z0-9+\/_=.-]+\z/D', $ufrag) === 1;
	}

	private function receiveCommands() : void{
		for($i = 0; $i < 128 && ($command = $this->commands->shift()) !== null; ++$i){
			$ufrag = substr($command, 1);
			if($command === "" || !self::validUfrag($ufrag)){
				continue;
			}
			if($command[0] === "+"){
				if(count($this->authorized) < self::MAX_AUTHORIZED || isset($this->authorized[$ufrag])){
					$this->authorized[$ufrag] = true;
				}
			}elseif($command[0] === "-"){
				unset($this->authorized[$ufrag]);
				foreach($this->relays as $key => $relay){
					if($relay["ufrag"] === $ufrag){
						socket_close($relay["socket"]);
						unset($this->relays[$key]);
					}
				}
			}
		}
	}

	private static function peerKey(string $ip, int $port) : string{
		return $ip . ":" . $port;
	}

	private function relayFor(string $ip, int $port, string $ufrag) : ?string{
		$key = self::peerKey($ip, $port);
		if(isset($this->relays[$key])){
			if($this->relays[$key]["ufrag"] === $ufrag){
				return $key;
			}
			socket_close($this->relays[$key]["socket"]);
			unset($this->relays[$key]);
		}
		if(count($this->relays) >= self::MAX_RELAYS){
			return null;
		}
		$socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		if($socket === false){
			return null;
		}
		if(!@socket_bind($socket, "127.0.0.1", 0) ||
			!@socket_connect($socket, "127.0.0.1", $this->iceBackendPort) ||
			!@socket_set_nonblock($socket)){
			socket_close($socket);
			return null;
		}
		$this->relays[$key] = [
			"socket" => $socket,
			"ufrag" => $ufrag,
			"ip" => $ip,
			"port" => $port,
			"last" => microtime(true)
		];
		return $key;
	}

	private function handleInbound(string $datagram, string $ip, int $port) : bool{
		$kind = NetherNetDatagramClassifier::classify($datagram);
		if($kind === NetherNetDatagramClassifier::RAKNET){
			return false;
		}
		$key = self::peerKey($ip, $port);
		$ufrag = self::requestedUfrag($datagram);
		if($ufrag !== null){
			if(!isset($this->authorized[$ufrag])){
				return true; // never forward unknown ICE credentials to native
			}
			$key = $this->relayFor($ip, $port, $ufrag);
		}elseif(!isset($this->relays[$key])){
			return true; // DTLS and STUN without USERNAME need an existing route
		}
		if($key !== null && isset($this->relays[$key])){
			$relay = &$this->relays[$key];
			if(isset($this->authorized[$relay["ufrag"]])){
				$relay["last"] = microtime(true);
				@socket_send($relay["socket"], $datagram, strlen($datagram), 0);
			}
			unset($relay);
		}
		return true;
	}

	/**
	 * Called by RakLib's existing tight nonblocking poll loop. Relayed packets
	 * are consumed internally and never presented as unknown RakNet messages.
	 */
	public function readPacket(?string &$source, ?int &$port) : ?string{
		$this->receiveCommands();
		$this->drainBackend();
		for($i = 0; $i < self::MAX_READ_SKIP; ++$i){
			$packet = parent::readPacket($source, $port);
			if($packet === null){
				return null;
			}
			if(!$this->handleInbound($packet, $source ?? "", $port ?? 0)){
				return $packet;
			}
		}
		return null;
	}

	private function drainBackend() : void{
		$now = microtime(true);
		if($now - $this->lastCleanup >= 30){
			$this->lastCleanup = $now;
			foreach($this->relays as $key => $relay){
				if(!isset($this->authorized[$relay["ufrag"]]) || $now - $relay["last"] > self::IDLE_SECONDS){
					socket_close($relay["socket"]);
					unset($this->relays[$key]);
				}
			}
		}
		if($this->relays === []){
			return;
		}
		$read = array_map(static fn(array $relay) => $relay["socket"], $this->relays);
		$write = $except = [];
		$ready = @socket_select($read, $write, $except, 0, 0);
		if($ready === false || $ready === 0){
			return;
		}
		$index = [];
		foreach($this->relays as $key => $relay){
			$index[spl_object_id($relay["socket"])] = $key;
		}
		$count = 0;
		foreach($read as $socket){
			if(++$count > self::MAX_BACKEND_DRAIN){
				break;
			}
			$key = $index[spl_object_id($socket)] ?? null;
			if($key === null || !isset($this->relays[$key])){
				continue;
			}
			$bytes = "";
			$len = @socket_recv($socket, $bytes, self::MAX_DATAGRAM, MSG_DONTWAIT);
			if($len === false || $len === 0){
				continue;
			}
			$relay = &$this->relays[$key];
			$relay["last"] = $now;
			// RakLib's public socket is the only egress to the real peer.
			parent::writePacket($bytes, $relay["ip"], $relay["port"]);
			unset($relay);
		}
	}

	public function close() : void{
		foreach($this->relays as $relay){
			socket_close($relay["socket"]);
		}
		$this->relays = [];
		$this->authorized = [];
		parent::close();
	}
}
