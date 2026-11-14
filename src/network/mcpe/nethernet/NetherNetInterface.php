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

use pmmp\thread\ThreadSafeArray;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\network\AdvancedNetworkInterface;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\Network;
use pocketmine\network\NetworkInterfaceStartException;
use pocketmine\network\PacketHandlingException;
use pocketmine\player\GameMode;
use pocketmine\plugin\PluginFaultHandler;
use pocketmine\Server;
use pocketmine\snooze\SleeperNotifier;
use pocketmine\thread\ThreadCrashException;
use pocketmine\timings\Timings;
use pocketmine\utils\Utils;
use Symfony\Component\Filesystem\Path;
use function bin2hex;
use function chr;
use function dirname;
use function getenv;
use function getmypid;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function microtime;
use function min;
use function ord;
use function pack;
use function preg_match;
use function random_bytes;
use function socket_close;
use function socket_create;
use function socket_sendto;
use function socket_set_nonblock;
use function str_contains;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function time;
use function usleep;
use const AF_INET;
use const JSON_THROW_ON_ERROR;
use const PHP_INT_MAX;
use const SOCK_DGRAM;
use const SOL_UDP;

/**
 * Network interface for the NetherNet transport (WebRTC data channels with HTTP signaling), used by Bedrock clients
 * 1.26.50 and newer. The transport itself is terminated by a native helper program (pm-nethernet) that relays game
 * batches over a loopback link; this class turns them into NetworkSessions, exactly like RakLibInterface does for
 * RakNet. RakNet keeps working side by side for older clients.
 */
final class NetherNetInterface implements AdvancedNetworkInterface{
	//PocketMine -> bridge
	private const FRAME_DATA = 0x01;
	private const FRAME_CLOSE = 0x02;
	private const FRAME_STATUS = 0x10;
	private const FRAME_BLOCK = 0x11;
	private const FRAME_UNBLOCK = 0x12;
	private const FRAME_SHUTDOWN = 0x13;
	private const FRAME_RAW_SEND = 0x14;

	//bridge -> PocketMine
	private const FRAME_READY = 0x20;
	private const FRAME_OPEN = 0x21;
	private const FRAME_RECV = 0x22;
	private const FRAME_CLOSED = 0x23;
	private const FRAME_LATENCY = 0x24;
	private const FRAME_LOG = 0x25;
	private const FRAME_ACK = 0x26;
	private const FRAME_RAW_RECV = 0x27;

	private const NO_RECEIPT = 0xffffffff;

	private const MAX_FRAME = 64 * 1024 * 1024;

	private const START_TIMEOUT_SECONDS = 15;
	private const MAX_RESTART_DELAY = 300;
	/** First restart delay when the bridge owns the shared UDP port: RakNet and Query are down until it is back */
	private const SHARED_PORT_RESTART_DELAY = 1;
	private const RESTART_DELAY = 5;

	private Network $network;
	private \Logger $logger;

	/** @var NetworkSession[] */
	private array $sessions = [];

	/** @phpstan-var ThreadSafeArray<int, string> */
	private ThreadSafeArray $mainToThread;
	/** @phpstan-var ThreadSafeArray<int, string|int> */
	private ThreadSafeArray $threadToMain;
	private ?NetherNetLinkThread $thread = null;
	private int $sleeperNotifierId = -1;
	private int $linkPort = 0;
	/** Unix socket offered to the bridge ("" = TCP only) */
	private string $linkUnixPath = "";
	/** Incomplete frame at the end of the data read from the link so far */
	private string $inBuffer = "";
	/** Main-thread socket that wakes the link thread up when frames are queued */
	private ?\Socket $wakeSocket = null;
	private int $wakePort = 0;
	/**
	 * Once started, frames queued by the main thread wake the link thread up once per burst: the first frame posts a
	 * notification to the main thread's own sleeper, which wakes the link thread when the main thread processes
	 * notifications (at the end of the tick, after every session flushed its batch, or right after the frames of the
	 * bridge were handled). Before that (startup) and while shutting down, every frame wakes it at once.
	 */
	private bool $deferWake = false;
	private bool $wakePending = false;
	private ?SleeperNotifier $wakeNotifier = null;
	private int $wakeNotifierId = -1;
	private string $token;

	private ?NetherNetProcess $process = null;
	private bool $linkUp = false;
	/** Set when the data from the link cannot be split into frames any more; cleared when a bridge (re)connects */
	private bool $linkCorrupt = false;
	private bool $bridgeReady = false;
	private bool $shuttingDown = false;
	private float $nextRestart = 0.0;
	private int $restartDelay;
	private int $failuresInRow = 0;

	private string $lastStatus = "";

	/** @var string[] */
	private array $rawPacketFilters = [];

	/**
	 * Addresses blocked by the server (IP bans, bad packets), replayed to a restarted bridge.
	 * @var int[]
	 * @phpstan-var array<string, int>
	 */
	private array $blockedAddresses = [];

	public function __construct(
		private Server $server,
		private NetherNetConfig $config,
		private string $bridgeLogFile,
		private PacketBroadcaster $packetBroadcaster,
		private EntityEventBroadcaster $entityEventBroadcaster,
		private TypeConverter $typeConverter,
		?string $token = null
	){
		$this->logger = new \PrefixedLogger($server->getLogger(), "NetherNet");
		$this->token = $token ?? self::generateToken();
		$this->restartDelay = $this->initialRestartDelay();
		$this->mainToThread = new ThreadSafeArray();
		$this->threadToMain = new ThreadSafeArray();
	}

	/**
	 * Secret shared with the bridge process (link authentication) and with RakLib's relay socket.
	 */
	public static function generateToken() : string{
		return bin2hex(random_bytes(16));
	}

	private function initialRestartDelay() : int{
		return $this->config->isUdpPortShared() ? self::SHARED_PORT_RESTART_DELAY : self::RESTART_DELAY;
	}

	public function start() : void{
		$sleeperEntry = $this->server->getTickSleeper()->addNotifier(function() : void{
			Timings::$connection->startTiming();
			try{
				$this->processFrames();
			}finally{
				Timings::$connection->stopTiming();
			}
		});
		$this->sleeperNotifierId = $sleeperEntry->getNotifierId();

		$unixPaths = $this->chooseUnixPaths();
		$this->thread = new NetherNetLinkThread($this->mainToThread, $this->threadToMain, $sleeperEntry, $this->token, $unixPaths);
		try{
			$this->linkPort = $this->thread->startAndWait();
			$this->wakePort = $this->thread->getWakePort();
			$this->linkUnixPath = $this->thread->getUnixPath();
			if($unixPaths !== "" && $this->linkUnixPath === ""){
				$this->logger->debug("Could not open a Unix socket for the link (" . $this->thread->getUnixError() . "), the bridge connects over TCP");
			}
			$wakeSocket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
			if($wakeSocket === false){
				throw new \RuntimeException("could not create the link wake socket");
			}
			socket_set_nonblock($wakeSocket);
			$this->wakeSocket = $wakeSocket;
		}catch(\RuntimeException $e){
			$this->stopThread();
			throw new NetworkInterfaceStartException($e->getMessage(), 0, $e);
		}

		try{
			$this->spawnBridge();
			$this->waitUntilReady();
		}catch(NetworkInterfaceStartException $e){
			$this->stopBridge();
			$this->stopThread();
			throw $e;
		}

		$wakeEntry = $this->server->getTickSleeper()->addNotifier(function() : void{
			$this->wakePending = false;
			$this->wakeLinkThread();
		});
		$this->wakeNotifierId = $wakeEntry->getNotifierId();
		$this->wakeNotifier = $wakeEntry->createNotifier();
		$this->deferWake = true;
	}

	/**
	 * The link uses a Unix socket, unless the environment variable PM_NETHERNET_LINK is "tcp". Candidates, of which the
	 * link thread takes the first that works (else the link uses TCP): next to the bridge log, in the working directory
	 * (relative path: the bridge inherits the working directory; sun_path is short and data folders can be deep), and
	 * in the temporary folder.
	 */
	private function chooseUnixPaths() : string{
		if(getenv("PM_NETHERNET_LINK") === "tcp"){
			return "";
		}
		$name = "nethernet-" . getmypid() . ".sock";
		$candidates = [];
		foreach([Path::join(dirname($this->bridgeLogFile), $name), $name, Path::join(sys_get_temp_dir(), $name)] as $path){
			if(strlen($path) <= NetherNetLinkThread::MAX_UNIX_PATH){
				$candidates[] = $path;
			}
		}
		return implode("\n", $candidates);
	}

	/**
	 * @throws NetworkInterfaceStartException
	 */
	private function spawnBridge() : void{
		$this->linkUp = false;
		$this->bridgeReady = false;
		$this->process = new NetherNetProcess(
			$this->config->binaryPath,
			$this->config->toArguments($this->linkPort),
			$this->token,
			$this->bridgeLogFile,
			$this->config->environment + [
				//bridges before link version 2 ignore it and connect over TCP
				"PM_NETHERNET_LINK_UNIX" => $this->linkUnixPath
			]
		);
		$this->process->start();
	}

	/**
	 * Blocks during server startup until the bridge reports that it is listening, so that configuration errors (port in
	 * use, bad certificate...) are reported right away and the server can carry on with RakNet only.
	 * @throws NetworkInterfaceStartException
	 */
	private function waitUntilReady() : void{
		$deadline = microtime(true) + self::START_TIMEOUT_SECONDS;
		while(!$this->bridgeReady){
			$this->processFrames();
			if($this->bridgeReady){
				break;
			}
			if($this->process === null || !$this->process->isRunning()){
				$code = $this->process?->getExitCode() ?? -1;
				throw new NetworkInterfaceStartException("the bridge exited during startup (code $code)" . $this->formatLogTail());
			}
			if(microtime(true) > $deadline){
				throw new NetworkInterfaceStartException("the bridge did not become ready within " . self::START_TIMEOUT_SECONDS . " seconds" . $this->formatLogTail());
			}
			usleep(10_000);
		}
	}

	private function formatLogTail() : string{
		$tail = $this->process?->getLogTail() ?? "";
		return $tail !== "" ? ". Last output of the bridge:\n" . $tail : "";
	}

	public function setNetwork(Network $network) : void{
		$this->network = $network;
	}

	public function tick() : void{
		if($this->thread === null){
			return;
		}
		if(!$this->thread->isRunning()){
			$e = $this->thread->getCrashInfo();
			if($e !== null){
				throw new ThreadCrashException("NetherNet link thread crashed", $e);
			}
			throw new \RuntimeException("NetherNet link thread stopped without crash information");
		}
		if($this->shuttingDown){
			return;
		}

		if($this->process !== null && !$this->process->isRunning()){
			$code = $this->process->getExitCode();
			$this->process->close();
			$this->process = null;
			$this->bridgeReady = false;
			$this->disconnectAll("NetherNet bridge stopped");
			$this->failuresInRow++;
			$this->logger->error("The NetherNet bridge exited unexpectedly (code $code), restarting in {$this->restartDelay} seconds. " . ($this->config->isUdpPortShared() ?
				"RakNet and Query on the shared UDP port are unavailable until then." :
				"RakNet is unaffected."
			) . $this->formatLogTailOf($code));
			$this->nextRestart = microtime(true) + $this->restartDelay;
			$this->restartDelay = min(self::MAX_RESTART_DELAY, $this->restartDelay * 2);
		}

		if($this->process === null && microtime(true) >= $this->nextRestart){
			try{
				$this->spawnBridge();
			}catch(NetworkInterfaceStartException $e){
				$this->logger->error("Could not restart the NetherNet bridge: " . $e->getMessage());
				$this->process = null;
				$this->nextRestart = microtime(true) + $this->restartDelay;
				$this->restartDelay = min(self::MAX_RESTART_DELAY, $this->restartDelay * 2);
			}
		}
	}

	private function formatLogTailOf(int $code) : string{
		return $code !== 0 ? " See " . $this->bridgeLogFile . " for details." : "";
	}

	private function processFrames() : void{
		while(($item = $this->threadToMain->shift()) !== null){
			if(is_string($item)){
				$this->handleLinkData($item);
			}elseif($item === NetherNetLinkThread::LINK_UP_INT){
				$this->onLinkUp();
			}else{
				$this->onLinkDown();
			}
		}
	}

	/**
	 * Splits the data read from the link into frames and handles them. Received game batches (by far the most frequent
	 * frames) are decoded inline.
	 */
	private function handleLinkData(string $data) : void{
		if($this->linkCorrupt){
			return;
		}
		$buffer = $this->inBuffer !== "" ? $this->inBuffer . $data : $data;
		$this->inBuffer = "";
		$length = strlen($buffer);
		$offset = 0;
		while($length - $offset >= 4){
			$frameLength = (ord($buffer[$offset]) << 24) | (ord($buffer[$offset + 1]) << 16) | (ord($buffer[$offset + 2]) << 8) | ord($buffer[$offset + 3]);
			if($frameLength < 1 || $frameLength > self::MAX_FRAME){
				//the stream cannot be resynchronised (the bridge only sends valid frames, so this is a bug): restart the
				//bridge, its new link starts clean
				$this->logger->error("Invalid frame length $frameLength from the NetherNet bridge, restarting it");
				$this->linkCorrupt = true;
				$this->stopBridge();
				return;
			}
			if($length - $offset - 4 < $frameLength){
				break;
			}
			if(ord($buffer[$offset + 4]) === self::FRAME_RECV && $frameLength >= 6){
				//u32 session, u8 channel (0 = ReliableDataChannel, 1 = UnreliableDataChannel), payload
				$sessionId = (ord($buffer[$offset + 5]) << 24) | (ord($buffer[$offset + 6]) << 16) | (ord($buffer[$offset + 7]) << 8) | ord($buffer[$offset + 8]);
				$this->onPacketReceive($sessionId, substr($buffer, $offset + 10, $frameLength - 6));
			}else{
				try{
					$this->handleFrame(substr($buffer, $offset + 4, $frameLength));
				}catch(NetherNetFrameException $e){
					$this->logger->error("Invalid frame from the NetherNet bridge: " . $e->getMessage());
				}
			}
			$offset += 4 + $frameLength;
		}
		if($offset < $length){
			$this->inBuffer = $offset === 0 ? $buffer : substr($buffer, $offset);
		}
	}

	private function onLinkUp() : void{
		$this->inBuffer = "";
		$this->linkCorrupt = false;
		$this->linkUp = true;
		$this->logger->debug("Bridge connected to the link (" . ($this->thread?->getLinkDescription() ?? "?") . ")");
		if($this->lastStatus !== ""){
			$this->send(self::FRAME_STATUS, $this->lastStatus);
		}
		$now = time();
		foreach(Utils::stringifyKeys($this->blockedAddresses) as $ip => $until){
			if($until > $now){
				$this->send(self::FRAME_BLOCK, self::encodeString($ip) . pack("N", $until === PHP_INT_MAX ? 0 : $until - $now));
			}else{
				unset($this->blockedAddresses[$ip]);
			}
		}
	}

	private function onLinkDown() : void{
		$this->inBuffer = "";
		$this->linkCorrupt = false;
		$this->linkUp = false;
		$this->bridgeReady = false;
		$this->disconnectAll("NetherNet bridge stopped");
	}

	/**
	 * @throws NetherNetFrameException
	 */
	private function handleFrame(string $frame) : void{
		$r = new NetherNetFrameReader($frame, 1);
		switch(ord($frame[0])){
			case self::FRAME_READY:
				$info = $r->string();
				$this->bridgeReady = true;
				$this->failuresInRow = 0;
				$this->restartDelay = $this->initialRestartDelay();
				$this->logInfo($info);
				break;
			case self::FRAME_OPEN:
				$sessionId = $r->u32();
				$ip = $r->string();
				$port = $r->u16();
				$networkId = $r->string();
				$publicKey = $r->string();
				$this->onClientConnect($sessionId, $ip, $port, $networkId, $publicKey !== "" ? $publicKey : null);
				break;
			case self::FRAME_RECV:
				$sessionId = $r->u32();
				$r->u8(); //channel: 0 = ReliableDataChannel, 1 = UnreliableDataChannel
				$this->onPacketReceive($sessionId, $r->rest());
				break;
			case self::FRAME_CLOSED:
				$sessionId = $r->u32();
				$reason = $r->string();
				$this->onClientDisconnect($sessionId, $reason);
				break;
			case self::FRAME_LATENCY:
				$sessionId = $r->u32();
				$ms = $r->u32();
				if(isset($this->sessions[$sessionId])){
					$this->sessions[$sessionId]->updatePing($ms);
				}
				break;
			case self::FRAME_ACK:
				$sessionId = $r->u32();
				$receipt = $r->u32();
				if(isset($this->sessions[$sessionId])){
					$this->sessions[$sessionId]->handleAckReceipt($receipt);
				}
				break;
			case self::FRAME_RAW_RECV:
				$ip = $r->string();
				$port = $r->u16();
				$payload = $r->rest();
				foreach($this->rawPacketFilters as $pattern){
					if(preg_match($pattern, $payload) === 1){
						$this->network->processRawPacket($this, $ip, $port, $payload);
						break;
					}
				}
				break;
			case self::FRAME_LOG:
				$level = $r->u8();
				$message = $r->string();
				match($level){
					0 => $this->logger->debug($message),
					1 => $this->logger->info($message),
					2 => $this->logger->warning($message),
					default => $this->logger->error($message),
				};
				break;
			default:
				throw new NetherNetFrameException("Unknown frame type " . ord($frame[0]));
		}
	}

	private function logInfo(string $json) : void{
		try{
			$info = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
		}catch(\JsonException){
			$info = null;
		}
		if(!is_array($info)){
			$this->logger->info("NetherNet bridge ready");
			return;
		}
		$signaling = self::infoString($info, "signaling");
		$udp = self::infoString($info, "udp");
		$https = ($info["https"] ?? false) === true;
		$this->logger->info("Listening for NetherNet (Bedrock 1.26.50+) clients: signaling on TCP $signaling (" . ($https ? "HTTP and HTTPS" : "HTTP") . "), WebRTC on UDP $udp (" . self::infoString($info, "udpMode") . "). Bridge " . self::infoString($info, "version"));
		if(($info["identityCreated"] ?? false) === true){
			$this->logger->notice("Created a new NetherNet identity key in " . $this->config->identityFile . ". Back it up: changing it makes returning players see the first-use trust prompt again.");
		}
		$this->logger->debug("Identity key fingerprint (SHA-256 of the public key): " . self::infoString($info, "fingerprint"));
	}

	/**
	 * @param mixed[] $info
	 */
	private static function infoString(array $info, string $key) : string{
		$value = $info[$key] ?? null;
		return is_string($value) ? $value : "?";
	}

	private function onClientConnect(int $sessionId, string $ip, int $port, string $networkId, ?string $publicKey) : void{
		if(!$this->network->getSessionManager()->acceptsNewSession($ip)){
			//too many connections from this address that have not logged in yet (logged by the session manager)
			$this->send(self::FRAME_CLOSE, pack("N", $sessionId) . self::encodeString("Too many connections from your address, try again"));
			return;
		}
		$session = new NetworkSession(
			$this->server,
			$this->network->getSessionManager(),
			PacketPool::getInstance(),
			new NetherNetPacketSender($sessionId, $this, $publicKey),
			$this->packetBroadcaster,
			$this->entityEventBroadcaster,
			ZlibCompressor::getInstance(),
			$this->typeConverter,
			$ip,
			$port
		);
		$this->sessions[$sessionId] = $session;
		$session->getLogger()->debug("transport=nethernet session, network ID $networkId, identity " . ($publicKey !== null ? "asserted" : "not asserted"));
	}

	private function onPacketReceive(int $sessionId, string $payload) : void{
		if(!isset($this->sessions[$sessionId])){
			return;
		}
		$session = $this->sessions[$sessionId];
		$name = $session->getDisplayName();
		try{
			$session->handleEncoded($payload);
		}catch(PacketHandlingException $e){
			$logger = $session->getLogger();
			$session->disconnectWithError(
				reason: "Bad packet: " . $e->getMessage(),
				disconnectScreenMessage: KnownTranslationFactory::pocketmine_disconnect_error_badPacket()
			);
			//intentionally doesn't use logException, we don't want spammy packet error traces to appear in release mode
			$logger->debug(implode("\n", Utils::printableExceptionInfo($e)));
			//only this player is disconnected, like on BDS (blocking the address dropped everyone sharing it)
		}catch(\Throwable $e){
			try{
				//plugin code reached while handling the packet (e.g. a plugin-defined block, item or form) failed:
				//log it and drop this player only, instead of crashing the server
				PluginFaultHandler::isolate($e, null, "handling of a packet from session $name");
			}catch(\Throwable $e){
				$this->server->getLogger()->emergency("Crash occurred while handling a packet from session: $name");
				throw $e;
			}
			if($session->isConnected()){
				//the packet was handled only halfway, so the session's state is unknown
				$session->disconnectWithError(
					reason: "Internal server error: " . $e->getMessage(),
					disconnectScreenMessage: KnownTranslationFactory::pocketmine_disconnect_error_internal()
				);
			}
		}
	}

	private function onClientDisconnect(int $sessionId, string $reason) : void{
		if(isset($this->sessions[$sessionId])){
			$session = $this->sessions[$sessionId];
			unset($this->sessions[$sessionId]);
			$session->getLogger()->debug("NetherNet connection closed: $reason");
			//the bridge reports ICE failure when the client stopped answering connectivity checks
			$session->onClientDisconnect(str_contains($reason, "ICE transport entered unrecoverable state") ?
				KnownTranslationFactory::pocketmine_disconnect_error_timeout() :
				KnownTranslationFactory::pocketmine_disconnect_clientDisconnect()
			);
		}
	}

	private function disconnectAll(string $reason) : void{
		$sessions = $this->sessions;
		$this->sessions = [];
		foreach($sessions as $session){
			$session->onClientDisconnect($reason);
		}
	}

	/**
	 * @internal Called by NetherNetPacketSender.
	 */
	public function putPacket(int $sessionId, string $payload, ?int $receiptId) : void{
		if(isset($this->sessions[$sessionId])){
			//the frame is built in one go (header and body), so the payload is copied once
			$this->queue(pack("NCNN", strlen($payload) + 9, self::FRAME_DATA, $sessionId, $receiptId !== null && $receiptId >= 0 && $receiptId < self::NO_RECEIPT ? $receiptId : self::NO_RECEIPT) . $payload);
		}
	}

	/**
	 * @internal Called by NetherNetPacketSender.
	 */
	public function close(int $sessionId, string $reason) : void{
		if(isset($this->sessions[$sessionId])){
			unset($this->sessions[$sessionId]);
			$this->send(self::FRAME_CLOSE, pack("N", $sessionId) . self::encodeString($reason));
		}
	}

	private function send(int $type, string $body) : void{
		$this->queue(NetherNetLinkThread::encodeFrame($type, $body));
	}

	/**
	 * Queues an encoded frame for the link thread.
	 */
	private function queue(string $frame) : void{
		if($this->linkUp){
			$this->mainToThread[] = $frame;
			if($this->deferWake && $this->wakeNotifier !== null){
				if(!$this->wakePending){
					$this->wakePending = true;
					$this->wakeNotifier->wakeupSleeper();
				}
			}elseif($this->mainToThread->count() === 1){
				//the link thread drains the whole queue once woken up: only the first frame of a burst needs a wakeup
				$this->wakeLinkThread();
			}
		}
	}

	private function wakeLinkThread() : void{
		if($this->wakeSocket !== null){
			@socket_sendto($this->wakeSocket, chr(0), 1, 0, "127.0.0.1", $this->wakePort);
		}
	}

	private static function encodeString(string $s) : string{
		$s = substr($s, 0, 0xffff);
		return pack("n", strlen($s)) . $s;
	}

	public function setName(string $name) : void{
		$info = $this->server->getQueryInformation();
		$world = $this->server->getWorldManager()->getDefaultWorld();
		try{
			$status = json_encode([
				"name" => $name,
				"protocol" => ProtocolInfo::CURRENT_PROTOCOL,
				"version" => ProtocolInfo::MINECRAFT_VERSION_NETWORK,
				"level" => $world !== null ? $world->getDisplayName() : $this->server->getName(),
				"players" => $info->getPlayerCount(),
				"maxPlayers" => $info->getMaxPlayerCount(),
				"gameType" => match($this->server->getGamemode()){
					GameMode::SURVIVAL => 0,
					GameMode::ADVENTURE => 2,
					default => 1
				},
			], JSON_THROW_ON_ERROR);
		}catch(\JsonException $e){
			$this->logger->debug("Could not encode status: " . $e->getMessage());
			return;
		}
		if($status !== $this->lastStatus){
			$this->lastStatus = $status;
			$this->send(self::FRAME_STATUS, $status);
		}
	}

	public function blockAddress(string $address, int $timeout = 300) : void{
		$this->blockedAddresses[$address] = $timeout > 0 ? time() + $timeout : PHP_INT_MAX;
		$this->send(self::FRAME_BLOCK, self::encodeString($address) . pack("N", $timeout > 0 ? $timeout : 0));
	}

	public function unblockAddress(string $address) : void{
		unset($this->blockedAddresses[$address]);
		$this->send(self::FRAME_UNBLOCK, self::encodeString($address));
	}

	public function sendRawPacket(string $address, int $port, string $payload) : void{
		//only used when the bridge owns the UDP port without RakNet (Query replies)
		if($this->config->queryToLink){
			$this->send(self::FRAME_RAW_SEND, self::encodeString($address) . pack("n", $port) . $payload);
		}
	}

	public function addRawPacketFilter(string $regex) : void{
		//the bridge only hands over Query datagrams (0xfe 0xfd), and only when it owns the UDP port without RakNet
		$this->rawPacketFilters[] = $regex;
	}

	public function shutdown() : void{
		$this->shuttingDown = true;
		$this->deferWake = false; //nothing processes the sleeper's notifications from here on
		$this->wakeLinkThread();
		$this->send(self::FRAME_SHUTDOWN, "");
		$this->disconnectAll("Server closed");
		//give the link thread a moment to flush the shutdown frame
		$deadline = microtime(true) + 1;
		while($this->process !== null && $this->process->isRunning() && microtime(true) < $deadline){
			usleep(10_000);
		}
		$this->stopBridge();
		$this->stopThread();
	}

	private function stopBridge() : void{
		if($this->process !== null){
			$this->process->stop();
			$this->process = null;
		}
	}

	private function stopThread() : void{
		if($this->sleeperNotifierId !== -1){
			$this->server->getTickSleeper()->removeNotifier($this->sleeperNotifierId);
			$this->sleeperNotifierId = -1;
		}
		if($this->wakeNotifierId !== -1){
			$this->server->getTickSleeper()->removeNotifier($this->wakeNotifierId);
			$this->wakeNotifierId = -1;
			$this->wakeNotifier = null;
			$this->deferWake = false;
		}
		$thread = $this->thread;
		if($thread !== null){
			$this->wakeLinkThread(); //so that it notices quit() at once
			$thread->quit();
			$this->thread = null;
		}
		if($this->wakeSocket !== null){
			socket_close($this->wakeSocket);
			$this->wakeSocket = null;
		}
	}
}
