<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

use amber\nethernet\SignalingPeerAddress;
use amber\nethernet\TransportConfig;
use amber\nethernet\signaling\HttpSignaling;
use amber\nethernet\signaling\HttpState;

/**
 * Bounded nonblocking HTTP signaling socket, following Amber's HTTP parser
 * and state machine. Also associates the JOIN's validated Host header with
 * its transport ID for automatic wildcard-bind ICE candidate selection.
 * No DNS resolution and no separate public address setting are required for
 * IP-literal joins.
 *
 * @internal
 */
final class NetherNetHttpServer{
	/** @var resource|null */
	private mixed $listener;
	/** @var array<int, array{stream: resource, ip: string, state: HttpState, deadline: float, input: string, host: ?string, reading: bool, output: string, offset: int}> */
	private array $clients = [];
	/** @var array<string, int> */
	private array $perIp = [];
	/** @var array<int, string> transport ID => join Host */
	private array $hostsByTransport = [];
	private bool $stopped = false;

	public function __construct(
		private TransportConfig $config,
		private HttpSignaling $signaling,
		private string $bindIp,
		private int $port
	){
		if(filter_var($bindIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ||
			$port < 1 || $port > 65535){
			throw new \InvalidArgumentException("NetherNet HTTP bind must reuse server-ip and server-port");
		}
		$server = @stream_socket_server("tcp://" . $bindIp . ":" . $port, $code, $message);
		if($server === false){
			throw new \RuntimeException("NetherNet HTTP port unavailable ($code)");
		}
		if(!stream_set_blocking($server, false)){
			fclose($server);
			throw new \RuntimeException("NetherNet HTTP socket cannot become nonblocking");
		}
		$this->listener = $server;
	}

	public function advertisedIp(int $transportId) : ?string{
		return NetherNetHostAddress::resolve($this->bindIp, $this->hostsByTransport[$transportId] ?? null);
	}

	public function poll() : void{
		if($this->stopped || $this->listener === null){
			return;
		}
		$now = microtime(true);
		foreach(array_keys($this->clients) as $id){
			$this->prepare($id, $now);
		}
		$read = [$this->listener];
		$write = [];
		foreach($this->clients as $client){
			if($client["reading"]){
				$read[] = $client["stream"];
			}
			if($client["output"] !== ""){
				$write[] = $client["stream"];
			}
		}
		$except = null;
		if(@stream_select($read, $write, $except, 0, 0) === false){
			throw new \RuntimeException("NetherNet HTTP select failed");
		}
		if(in_array($this->listener, $read, true)){
			$this->accept();
		}
		foreach(array_keys($this->clients) as $id){
			$client = $this->clients[$id] ?? null;
			if($client === null){
				continue;
			}
			if($client["reading"] && in_array($client["stream"], $read, true)){
				$chunk = @fread($client["stream"], 16384);
				if($chunk === false || ($chunk === "" && feof($client["stream"]))){
					$this->drop($id);
					continue;
				}
				if($chunk !== ""){
					$this->captureHost($id, $chunk);
					$this->signaling->receive($id, $chunk);
					if(isset($this->clients[$id]) && ($transportId = $this->signaling->getTransportId($id)) !== null &&
						$this->clients[$id]["host"] !== null){
						$this->hostsByTransport[$transportId] = $this->clients[$id]["host"];
					}
				}
			}
			$this->prepare($id, microtime(true));
			if(isset($this->clients[$id]) && $this->clients[$id]["output"] !== "" &&
				in_array($this->clients[$id]["stream"], $write, true)){
				$this->write($id);
			}
		}
	}

	private function captureHost(int $id, string $chunk) : void{
		if(!isset($this->clients[$id]) || $this->clients[$id]["input"] === "" && $this->clients[$id]["host"] !== null){
			return;
		}
		// A zero byte in the first header is safe for Host inference: the
		// official parser independently rejects it before transport admission.
		$this->clients[$id]["input"] .= $chunk;
		$headerEnd = strpos($this->clients[$id]["input"], "\r\n\r\n");
		if($headerEnd !== false){
			$this->clients[$id]["host"] = NetherNetHostAddress::requestHost(substr($this->clients[$id]["input"], 0, $headerEnd + 4));
			$this->clients[$id]["input"] = "";
		}elseif(strlen($this->clients[$id]["input"]) > $this->config->maxHttpHeaderBytes){
			$this->clients[$id]["input"] = "";
		}
	}

	private function accept() : void{
		for($i = 0; $i < 16; ++$i){
			$stream = @stream_socket_accept($this->listener, 0);
			if($stream === false){
				return;
			}
			if(count($this->clients) >= $this->config->maxHttpConnections || !stream_set_blocking($stream, false)){
				fclose($stream);
				continue;
			}
			$source = stream_socket_get_name($stream, true);
			try{
				$peer = SignalingPeerAddress::fromSocketName($source === false ? "" : $source);
				if(($this->perIp[$peer->ip] ?? 0) >= $this->config->maxHttpConnectionsPerIp){
					fclose($stream);
					continue;
				}
				$id = $this->signaling->open($peer);
			}catch(\Throwable){
				fclose($stream);
				continue;
			}
			$this->clients[$id] = [
				"stream" => $stream,
				"ip" => $peer->ip,
				"state" => HttpState::HEADERS,
				"deadline" => microtime(true) + $this->config->httpHeaderTimeoutSeconds,
				"input" => "",
				"host" => null,
				"reading" => true,
				"output" => "",
				"offset" => 0
			];
			$this->perIp[$peer->ip] = ($this->perIp[$peer->ip] ?? 0) + 1;
		}
	}

	private function prepare(int $id, float $now) : void{
		if(!isset($this->clients[$id])){
			return;
		}
		$state = $this->signaling->getState($id);
		if($state === HttpState::CLOSED){
			$this->drop($id);
			return;
		}
		if($state !== $this->clients[$id]["state"]){
			$this->clients[$id]["state"] = $state;
			$this->clients[$id]["deadline"] = $now + $this->config->signalingTimeoutSeconds;
		}
		if($now >= $this->clients[$id]["deadline"]){
			$this->signaling->timeout($id);
			if($this->signaling->getState($id) === HttpState::CLOSED){
				$this->drop($id);
				return;
			}
		}
		$response = $this->signaling->takeResponse($id);
		if($response !== null){
			if(strlen($response->body) > max($this->config->maxSdpBytes, 32768)){
				$this->drop($id);
				return;
			}
			$this->clients[$id]["output"] = $response->encode();
			$this->clients[$id]["reading"] = false;
			$this->clients[$id]["state"] = HttpState::WRITING;
			$this->clients[$id]["deadline"] = $now + $this->config->signalingTimeoutSeconds;
		}
	}

	private function write(int $id) : void{
		$client = &$this->clients[$id];
		$chunk = substr($client["output"], $client["offset"], 16384);
		$length = @fwrite($client["stream"], $chunk);
		if($length === false){
			unset($client);
			$this->drop($id);
			return;
		}
		$client["offset"] += $length;
		if($client["offset"] === strlen($client["output"])){
			unset($client);
			$this->signaling->finish($id);
			$this->drop($id);
			return;
		}
		unset($client);
	}

	private function drop(int $id) : void{
		$client = $this->clients[$id] ?? null;
		if($client === null){
			return;
		}
		if(($transportId = $this->signaling->getTransportId($id)) !== null){
			unset($this->hostsByTransport[$transportId]);
		}
		unset($this->clients[$id]);
		$this->perIp[$client["ip"]]--;
		if($this->perIp[$client["ip"]] === 0){
			unset($this->perIp[$client["ip"]]);
		}
		fclose($client["stream"]);
		$this->signaling->cancel($id);
	}

	public function stop() : void{
		if($this->stopped){
			return;
		}
		$this->stopped = true;
		foreach(array_keys($this->clients) as $id){
			$this->drop($id);
		}
		if($this->listener !== null){
			fclose($this->listener);
			$this->listener = null;
		}
		$this->hostsByTransport = [];
		$this->signaling->stop();
	}

	public function __destruct(){ $this->stop(); }
}
