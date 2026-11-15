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

use pmmp\thread\ThreadSafeArray;
use pocketmine\event\player\PlayerProxyTransferEvent;
use pocketmine\event\server\ProxyMessageReceiveEvent;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\network\AdvancedNetworkInterface;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\nethernet\NetherNetFrameException;
use pocketmine\network\mcpe\nethernet\NetherNetFrameReader;
use pocketmine\network\mcpe\nethernet\NetherNetLinkThread;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\PlayerListEntry;
use pocketmine\network\mcpe\serializer\ChunkSerializer;
use pocketmine\network\Network;
use pocketmine\network\NetworkInterfaceStartException;
use pocketmine\network\PacketHandlingException;
use pocketmine\network\proxy\ProxyNetwork;
use pocketmine\permission\DefaultPermissions;
use pocketmine\permission\Permission;
use pocketmine\permission\PermissionManager;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\plugin\PluginFaultHandler;
use pocketmine\Server;
use pocketmine\thread\ThreadCrashException;
use pocketmine\timings\Timings;
use pocketmine\utils\Utils;
use pocketmine\VersionInfo;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\format\Chunk;
use function array_keys;
use function array_unique;
use function array_values;
use function chr;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_strtolower;
use function min;
use function ord;
use function pack;
use function socket_close;
use function socket_create;
use function socket_sendto;
use function socket_set_nonblock;
use function strlen;
use function substr;
use function time;
use const AF_INET;
use const JSON_THROW_ON_ERROR;
use const PHP_INT_MIN;
use const SOCK_DGRAM;
use const SOL_UDP;

/**
 * @internal
 * Network interface of a server behind pm-proxy (network.proxy in pocketmine.yml). Players arrive over pm-link from
 * a trusted proxy that already checked their login with Xbox Live; the proxy owns the client connections, so this
 * server opens no RakNet or NetherNet port of its own. See the pm-proxy documentation.
 */
final class ProxyLinkInterface implements AdvancedNetworkInterface, ProxyNetwork{
	//server -> proxy
	private const FRAME_DATA = 0x01;
	private const FRAME_CLOSE = 0x02;
	private const FRAME_STATUS = 0x10;
	private const FRAME_BLOCK = 0x11;
	private const FRAME_UNBLOCK = 0x12;
	private const FRAME_SHUTDOWN = 0x13;
	private const FRAME_TRANSFER_REQUEST = 0x15;
	private const FRAME_FALLBACK = 0x16;
	private const FRAME_EMPTY_CHUNKS = 0x17;
	private const FRAME_MESSAGE = 0x18;
	private const FRAME_SEND_PLAYER = 0x19;
	private const FRAME_ALERT = 0x1a;
	private const FRAME_DETACH_CANCEL = 0x1b;

	//proxy -> server
	private const FRAME_OPEN = 0x41;
	private const FRAME_DETACH = 0x42;
	private const FRAME_NETWORK_INFO = 0x43;
	private const FRAME_NETWORK_STATE = 0x44;
	private const FRAME_MESSAGE_IN = 0x45;
	private const FRAME_RECV = 0x22;
	private const FRAME_CLOSED = 0x23;
	private const FRAME_LATENCY = 0x24;
	private const FRAME_ACK = 0x26;

	private const NO_RECEIPT = 0xffffffff;
	private const SESSION_MASK = 0xffffffff;
	/** How long a transfer asked from this server waits for the proxy to detach the player, in ticks. */
	private const REQUEST_VALID_TICKS = 200;

	private Network $network;
	private \Logger $logger;

	/**
	 * @var NetworkSession[]
	 * @phpstan-var array<int, NetworkSession>
	 */
	private array $sessions = [];

	/** @phpstan-var ThreadSafeArray<int, string> */
	private ThreadSafeArray $mainToThread;
	/** @phpstan-var ThreadSafeArray<int, string> */
	private ThreadSafeArray $threadToMain;
	private ?ProxyLinkThread $thread = null;
	private int $sleeperNotifierId = -1;
	private ?\Socket $wakeSocket = null;
	private int $wakePort = 0;

	private ProxyTicketVerifier $verifier;
	/**
	 * Linked proxies: link ID => proxy ID.
	 * @phpstan-var array<int, string>
	 */
	private array $links = [];
	/**
	 * Servers and groups of the network, as announced by the proxy.
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $networkTargets = [];
	/**
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $servers = [];
	/**
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $groups = [];
	/** Players on the network, from the proxy's network state. */
	private int $globalPlayers = 0;
	/**
	 * Players and link state of each server, from the proxy's network state.
	 * @phpstan-var array<string, array{int, bool}>
	 */
	private array $serverStates = [];
	/**
	 * Player name => server, from the proxy's network state.
	 * @phpstan-var array<string, string>
	 */
	private array $onlinePlayers = [];
	/**
	 * Lower-case player name => player name.
	 * @phpstan-var array<string, string>
	 */
	private array $onlinePlayerNames = [];
	/**
	 * Actions to run at a server tick (camera moves of cinematic transfers).
	 * @phpstan-var array<int, array{int, \Closure() : void}>
	 */
	private array $scheduled = [];
	private string $lastStatus = "";
	/**
	 * Transfers asked from this server (PlayerProxyTransferEvent already called), by session ID: when the proxy then
	 * detaches the player, the event is not called again.
	 * @phpstan-var array<int, int> session ID => server tick
	 */
	private array $requestedTransfers = [];

	/**
	 * @param string[] $publicKeys trusted proxy keys (base64 DER)
	 * @phpstan-param list<string> $publicKeys
	 *
	 * @throws \InvalidArgumentException if a public key is invalid
	 */
	public function __construct(
		private Server $server,
		private string $bindHost,
		private int $bindPort,
		private array $publicKeys,
		private string $serverName,
		private PacketBroadcaster $packetBroadcaster,
		private EntityEventBroadcaster $entityEventBroadcaster,
		private TypeConverter $typeConverter
	){
		$this->logger = new \PrefixedLogger($server->getLogger(), "Proxy");
		$this->verifier = new ProxyTicketVerifier($publicKeys, $serverName);
		$this->mainToThread = new ThreadSafeArray();
		$this->threadToMain = new ThreadSafeArray();
	}

	public function start() : void{
		if(!$this->verifier->hasKeys()){
			throw new NetworkInterfaceStartException("network.proxy.public-keys is empty: no proxy could connect");
		}
		$sleeperEntry = $this->server->getTickSleeper()->addNotifier(function() : void{
			Timings::$connection->startTiming();
			try{
				$this->processFrames();
			}finally{
				Timings::$connection->stopTiming();
			}
		});
		$this->sleeperNotifierId = $sleeperEntry->getNotifierId();

		$this->thread = new ProxyLinkThread(
			$this->mainToThread,
			$this->threadToMain,
			$sleeperEntry,
			$this->bindHost,
			$this->bindPort,
			implode("\n", $this->publicKeys),
			$this->serverName,
			VersionInfo::NAME . " " . VersionInfo::VERSION()->getFullVersion(true)
		);
		try{
			$this->thread->startAndWait();
			$this->wakePort = $this->thread->getWakePort();
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
		$this->logger->info("Waiting for pm-proxy on TCP {$this->bindHost}:{$this->bindPort} as server \"{$this->serverName}\". Players can only join through the proxy.");

		//network commands: pm-proxy runs /server, /hub, /glist, /find, /send and /alert itself and checks their permissions
		//(its player-groups and permissions, or its permissions plugin), like BungeeCord. Here they are only registered so
		//that players see them in the command list.
		$operatorRoot = PermissionManager::getInstance()->getPermission(DefaultPermissions::ROOT_OPERATOR);
		$userRoot = PermissionManager::getInstance()->getPermission(DefaultPermissions::ROOT_USER);
		if($operatorRoot !== null && $userRoot !== null){
			DefaultPermissions::registerPermission(new Permission(ProxyServerCommand::PERMISSION, "Allows the user to move to any server of the network with /server"), [$userRoot]);
			DefaultPermissions::registerPermission(new Permission(ProxyHubCommand::PERMISSION, "Allows the user to go back to the lobby with /hub"), [$userRoot]);
		}
		$this->server->getCommandMap()->registerAll("pocketmine", [new ProxyServerCommand($this), new ProxyHubCommand($this)]);
		ProxyNetworkCommands::register($this->server, $this);
	}

	public function setNetwork(Network $network) : void{
		$this->network = $network;
	}

	public function tick() : void{
		if($this->thread !== null && !$this->thread->isRunning()){
			$e = $this->thread->getCrashInfo();
			if($e !== null){
				throw new ThreadCrashException("pm-link thread crashed", $e);
			}
			throw new \RuntimeException("pm-link thread stopped without crash information");
		}
		if(count($this->scheduled) > 0){
			$tick = $this->server->getTick();
			$due = [];
			foreach($this->scheduled as $i => [$at, $action]){
				if($at <= $tick){
					$due[] = $action;
					unset($this->scheduled[$i]);
				}
			}
			foreach($due as $action){
				$action();
			}
		}
	}

	/**
	 * @phpstan-param \Closure() : void $action
	 */
	private function schedule(int $ticks, \Closure $action) : void{
		$this->scheduled[] = [$this->server->getTick() + $ticks, $action];
	}

	/**
	 * @internal
	 * Called by NetworkSession when a player transferred by the proxy has spawned here.
	 */
	public function onTransferSpawned(NetworkSession $session, ProxyTicket $ticket) : void{
		$player = $session->getPlayer();
		if($ticket->style !== ProxyTransferStyle::CINEMATIC || $player === null){
			return;
		}
		//the client's camera is still up in the sky where the previous server left it: bring it down onto the player
		$session->getLogger()->debug("Cinematic transfer: camera flying down");
		$player->setNoClientPredictions();
		ProxyCamera::flyDown($session, $player);
		$this->watchDescent($session, $player, 0, $player->getHealth());
	}

	/**
	 * The player cannot move while the camera comes down: if it gets hurt meanwhile, give it back its camera and its
	 * movement at once (no invulnerability, which would get in the way of combat plugins).
	 */
	private function watchDescent(NetworkSession $session, Player $player, int $tick, float $health) : void{
		if(!$session->isConnected()){
			return;
		}
		if($tick >= ProxyCamera::DURATION_TICKS || !$player->isAlive() || $player->getHealth() < $health){
			ProxyCamera::release($session);
			$player->setNoClientPredictions(false);
			return;
		}
		$this->schedule(1, fn() => $this->watchDescent($session, $player, $tick + 1, $health));
	}

	private function processFrames() : void{
		while(($frame = $this->threadToMain->shift()) !== null){
			try{
				$this->handleFrame($frame);
			}catch(NetherNetFrameException $e){
				$this->logger->error("Invalid frame from the proxy: " . $e->getMessage());
			}
		}
	}

	/**
	 * @throws NetherNetFrameException
	 */
	private function handleFrame(string $frame) : void{
		if($frame[0] === ProxyLinkThread::LINK_REFUSED){
			$this->logger->warning("Refused a pm-link " . substr($frame, 1));
			return;
		}
		$link = (new NetherNetFrameReader($frame, 1))->u32();
		switch($frame[0]){
			case ProxyLinkThread::LINK_UP:
				$proxyId = substr($frame, 5);
				$this->links[$link] = $proxyId;
				$this->logger->info("Proxy \"$proxyId\" connected" . (count($this->links) > 1 ? " (" . count($this->links) . " proxies linked)" : ""));
				if($this->lastStatus !== ""){
					$this->send(self::FRAME_STATUS, $this->lastStatus, $link);
				}
				$this->sendEmptyChunks($link);
				return;
			case ProxyLinkThread::LINK_DOWN:
				$proxyId = $this->links[$link] ?? "?";
				unset($this->links[$link]);
				$this->logger->warning("Proxy \"$proxyId\" disconnected");
				if(count($this->links) === 0){
					$this->globalPlayers = 0;
					$this->serverStates = [];
					$this->onlinePlayers = [];
					$this->onlinePlayerNames = [];
				}
				$this->disconnectAll("Proxy disconnected", $link);
				return;
			case ProxyLinkThread::LINK_FRAME:
				$frame = substr($frame, 5);
				break;
			default:
				throw new NetherNetFrameException("Unknown message from the link thread");
		}

		//session IDs are the proxy's own: they become keys unique among the links
		$r = new NetherNetFrameReader($frame, 1);
		switch(ord($frame[0])){
			case self::FRAME_OPEN:
				$sessionId = self::sessionKey($link, $r->u32());
				$ip = $r->string();
				$port = $r->u16();
				$ticket = $r->bytes($r->u32());
				$signature = $r->bytes($r->u16());
				$this->onClientConnect($sessionId, $ip, $port, $ticket, $signature);
				break;
			case self::FRAME_NETWORK_INFO:
				$this->onNetworkInfo($r->rest());
				break;
			case self::FRAME_NETWORK_STATE:
				$this->onNetworkState($r->rest());
				break;
			case self::FRAME_MESSAGE_IN:
				$source = $r->string();
				$channel = $r->string();
				(new ProxyMessageReceiveEvent($source, $channel, $r->rest()))->call();
				break;
			case self::FRAME_DETACH:
				$sessionId = self::sessionKey($link, $r->u32());
				$target = $r->string();
				$style = $r->hasMore() ? $r->string() : ProxyTransferStyle::DIMENSION;
				$this->onDetach($sessionId, $target, $style);
				break;
			case self::FRAME_RECV:
				$sessionId = self::sessionKey($link, $r->u32());
				$r->u8(); //channel
				$this->onPacketReceive($sessionId, $r->rest());
				break;
			case self::FRAME_CLOSED:
				$sessionId = self::sessionKey($link, $r->u32());
				$reason = $r->string();
				$this->onClientDisconnect($sessionId, $reason);
				break;
			case self::FRAME_LATENCY:
				$sessionId = self::sessionKey($link, $r->u32());
				$ms = $r->u32();
				if(isset($this->sessions[$sessionId])){
					$this->sessions[$sessionId]->updatePing($ms);
				}
				break;
			case self::FRAME_ACK:
				$sessionId = self::sessionKey($link, $r->u32());
				$receipt = $r->u32();
				if(isset($this->sessions[$sessionId])){
					$this->sessions[$sessionId]->handleAckReceipt($receipt);
				}
				break;
			default:
				throw new NetherNetFrameException("Unknown frame type " . ord($frame[0]));
		}
	}

	private function onClientConnect(int $sessionId, string $ip, int $port, string $ticketJson, string $signature) : void{
		if(isset($this->sessions[$sessionId])){
			$this->logger->warning("Proxy reused session ID $sessionId, dropping the old session");
			$this->onClientDisconnect($sessionId, "session ID reused");
		}
		try{
			$ticket = $this->verifier->verify($ticketJson, $signature, time());
			if($ticket->sessionId !== ($sessionId & self::SESSION_MASK) || $ticket->ip !== $ip || $ticket->port !== $port){
				throw new ProxyTicketException("Ticket is for another session");
			}
			$linkProxy = $this->links[$sessionId >> 32] ?? "";
			if($ticket->proxyId !== $linkProxy){
				throw new ProxyTicketException("Ticket from proxy \"$ticket->proxyId\" over the link of \"$linkProxy\"");
			}
		}catch(ProxyTicketException $e){
			$this->logger->warning("Refused player from $ip:$port: " . $e->getMessage());
			$this->sendSession($sessionId, self::FRAME_CLOSE, ProxyLinkThread::encodeString("Invalid proxy ticket: " . $e->getMessage()));
			return;
		}

		$session = new NetworkSession(
			$this->server,
			$this->network->getSessionManager(),
			PacketPool::getInstance(),
			new ProxyPacketSender($sessionId, $this, $ticket),
			$this->packetBroadcaster,
			$this->entityEventBroadcaster,
			ZlibCompressor::getInstance(),
			$this->typeConverter,
			$ip,
			$port
		);
		$this->sessions[$sessionId] = $session;
		$session->getLogger()->debug("transport=proxy session from proxy \"{$ticket->proxyId}\", player $ticket->name, Xbox Live " . ($ticket->xbox ? "yes" : "no"));
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
		}catch(\Throwable $e){
			try{
				PluginFaultHandler::isolate($e, null, "handling of a packet from session $name");
			}catch(\Throwable $e){
				$this->server->getLogger()->emergency("Crash occurred while handling a packet from session: $name");
				throw $e;
			}
			if($session->isConnected()){
				$session->disconnectWithError(
					reason: "Internal server error: " . $e->getMessage(),
					disconnectScreenMessage: KnownTranslationFactory::pocketmine_disconnect_error_internal()
				);
			}
		}
	}

	/**
	 * The proxy moves the player to another server of the network. Undo what only this server knows the client has
	 * (open window, forms, this server's player list) and let the player leave without a Disconnect packet; the proxy
	 * cleans up entities, boss bars, scoreboards and effects itself.
	 */
	private function onDetach(int $sessionId, string $target, string $style) : void{
		if(!isset($this->sessions[$sessionId])){
			return;
		}
		$session = $this->sessions[$sessionId];
		$player = $session->getPlayer();
		$requestedHere = ($this->requestedTransfers[$sessionId] ?? PHP_INT_MIN) > $this->server->getTick() - self::REQUEST_VALID_TICKS;
		unset($this->requestedTransfers[$sessionId]);
		if($player !== null && $player->isConnected() && $player->spawned){
			if(!$player->isAlive()){
				//it would arrive on the death screen of a server that does not know it died
				$this->cancelDetach($sessionId, "You cannot change server while dead.");
				return;
			}
			if(!$requestedHere){
				//moved from elsewhere (/send, another server's plugin): plugins of this server (combat loggers...) decide too
				$ev = new PlayerProxyTransferEvent($player, $target, $style);
				$ev->call();
				if($ev->isCancelled()){
					$this->cancelDetach($sessionId, "");
					return;
				}
			}
		}
		if($style === ProxyTransferStyle::CINEMATIC && $player !== null && $player->isConnected() && $player->spawned){
			//the camera flies up into the sky first; the player leaves once it is up there
			$session->getLogger()->debug("Cinematic transfer to \"$target\": camera flying up");
			$player->setNoClientPredictions();
			ProxyCamera::flyUp($session, $player);
			$this->watchClimb($sessionId, $session, $player, $target, 0, $player->getHealth());
			return;
		}
		$this->release($session, $target);
	}

	/**
	 * The player cannot move while the camera flies up. If it gets hurt or dies meanwhile, the server change is called
	 * off and it gets its camera and movement back: no invulnerability (that would get in the way of combat plugins),
	 * and getting hit never helps to escape a fight.
	 */
	private function watchClimb(int $sessionId, NetworkSession $session, Player $player, string $target, int $tick, float $health) : void{
		if(($this->sessions[$sessionId] ?? null) !== $session || !$player->isConnected()){
			return;
		}
		if(!$player->isAlive() || $player->getHealth() < $health){
			$session->getLogger()->debug("Cinematic transfer to \"$target\" called off: the player was hurt");
			ProxyCamera::release($session);
			$player->setNoClientPredictions(false);
			$this->cancelDetach($sessionId, $player->isAlive() ? "You were hurt: server change cancelled." : "");
			return;
		}
		if($tick === ProxyCamera::FADE_OUT_TICK){
			ProxyCamera::fadeOut($session);
		}
		if($tick >= ProxyCamera::LEAVE_TICK){
			$this->release($session, $target);
			return;
		}
		$this->schedule(1, fn() => $this->watchClimb($sessionId, $session, $player, $target, $tick + 1, $health));
	}

	/**
	 * Tells the proxy that the player stays here after all; it shows the reason (if any) to the player.
	 */
	private function cancelDetach(int $sessionId, string $reason) : void{
		$this->sendSession($sessionId, self::FRAME_DETACH_CANCEL, ProxyLinkThread::encodeString($reason));
	}

	private function release(NetworkSession $session, string $target) : void{
		$player = $session->getPlayer();
		if($player !== null && $player->isConnected()){
			$player->removeCurrentWindow();
			$player->closeAllForms();
			$entries = [];
			foreach($this->server->getOnlinePlayers() as $online){
				$entries[] = PlayerListEntry::createRemovalEntry($online->getUniqueId());
			}
			if(count($entries) > 0){
				$session->sendDataPacket(PlayerListPacket::remove($entries));
			}
		}
		$session->getLogger()->debug("Transferred by the proxy to server \"$target\"");
		$session->disconnect("Transferred to $target", notify: false);
	}

	private function onClientDisconnect(int $sessionId, string $reason) : void{
		if(isset($this->sessions[$sessionId])){
			$session = $this->sessions[$sessionId];
			unset($this->sessions[$sessionId], $this->requestedTransfers[$sessionId]);
			$session->getLogger()->debug("Proxied connection closed: $reason");
			$session->onClientDisconnect(KnownTranslationFactory::pocketmine_disconnect_clientDisconnect());
		}
	}

	/**
	 * Drops the sessions of one link (or of every link).
	 */
	private function disconnectAll(string $reason, ?int $link = null) : void{
		foreach($this->sessions as $key => $session){
			if($link === null || $key >> 32 === $link){
				unset($this->sessions[$key], $this->requestedTransfers[$key]);
				$session->onClientDisconnect($reason);
			}
		}
	}

	/**
	 * Key of a session: the proxy's session ID is only unique on its link.
	 */
	private static function sessionKey(int $link, int $sessionId) : int{
		return ($link << 32) | $sessionId;
	}

	public function putPacket(int $sessionId, string $payload, ?int $receiptId) : void{
		if(isset($this->sessions[$sessionId])){
			$this->sendSession($sessionId, self::FRAME_DATA, pack("N", $receiptId !== null && $receiptId >= 0 && $receiptId < self::NO_RECEIPT ? $receiptId : self::NO_RECEIPT) . $payload);
		}
	}

	public function close(int $sessionId, string $reason) : void{
		if(isset($this->sessions[$sessionId])){
			unset($this->sessions[$sessionId]);
			$this->sendSession($sessionId, self::FRAME_CLOSE, ProxyLinkThread::encodeString($reason));
		}
	}

	/**
	 * Sends a frame to one link, or to every link.
	 */
	private function send(int $type, string $body, int $link = ProxyLinkThread::BROADCAST) : void{
		if(count($this->links) > 0){
			$this->mainToThread[] = pack("N", $link) . NetherNetLinkThread::encodeFrame($type, $body);
			//the link thread drains the whole queue once woken up: only the first frame of a burst needs a wakeup
			if($this->mainToThread->count() === 1){
				$this->wakeLinkThread();
			}
		}
	}

	/**
	 * Sends a frame about a session to the proxy it belongs to: the body starts with the proxy's session ID.
	 */
	private function sendSession(int $key, int $type, string $rest) : void{
		$this->send($type, pack("N", $key & self::SESSION_MASK) . $rest, $key >> 32);
	}

	/**
	 * Requests about the whole network go to one proxy: every proxy links every server, and proxies share their
	 * players through Redis.
	 */
	private function sendNetwork(int $type, string $body) : void{
		if(count($this->links) > 0){
			$this->send($type, $body, min(array_keys($this->links)));
		}
	}

	private function wakeLinkThread() : void{
		if($this->wakeSocket !== null){
			@socket_sendto($this->wakeSocket, chr(0), 1, 0, "127.0.0.1", $this->wakePort);
		}
	}

	public function setName(string $name) : void{
		$info = $this->server->getQueryInformation();
		try{
			$status = json_encode([
				"name" => $name,
				"protocol" => ProtocolInfo::CURRENT_PROTOCOL,
				"version" => ProtocolInfo::MINECRAFT_VERSION_NETWORK,
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
		//the proxy owns the client connections: it applies IP blocks (bans) itself
		$this->send(self::FRAME_BLOCK, ProxyLinkThread::encodeString($address) . pack("N", $timeout > 0 ? $timeout : 0));
	}

	public function unblockAddress(string $address) : void{
		$this->send(self::FRAME_UNBLOCK, ProxyLinkThread::encodeString($address));
	}

	public function sendRawPacket(string $address, int $port, string $payload) : void{
		//no raw packets: Query is answered by the proxy
	}

	public function addRawPacketFilter(string $regex) : void{
		//no raw packets over pm-link
	}

	/**
	 * Gives the proxy an empty chunk of each dimension (LevelChunkPacket payload). When a player changes server it
	 * overwrites with it every chunk the previous server sent: real clients keep a dimension's terrain even through the
	 * detour of a dimension change, which would leave the previous server's terrain visible.
	 */
	private function sendEmptyChunks(int $link) : void{
		$body = "";
		foreach([Dimension::OVERWORLD, Dimension::NETHER, Dimension::END] as $dimension){
			$payload = ChunkSerializer::serializeFullChunk(new Chunk([], false), $dimension->getNetworkId(), $this->typeConverter->getBlockTranslator());
			$body .= chr($dimension->getNetworkId()) . pack("N", strlen($payload)) . $payload;
		}
		$this->send(self::FRAME_EMPTY_CHUNKS, $body, $link);
	}

	private function onNetworkInfo(string $json) : void{
		try{
			$info = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
		}catch(\JsonException $e){
			$this->logger->debug("Invalid network info from the proxy: " . $e->getMessage());
			return;
		}
		if(!is_array($info)){
			return;
		}
		$lists = [];
		foreach(["servers", "groups"] as $key){
			$lists[$key] = [];
			$list = $info[$key] ?? null;
			if(!is_array($list)){
				continue;
			}
			foreach($list as $name){
				if(is_string($name) && $name !== ""){
					$lists[$key][] = $name;
				}
			}
		}
		$this->servers = $lists["servers"];
		$this->groups = $lists["groups"];
		$this->networkTargets = array_values(array_unique([...$this->servers, ...$this->groups]));
		$this->logger->debug("Network: " . implode(", ", $this->networkTargets));
	}

	/**
	 * Player counts and who is where, sent by the proxy when they change (at most once a second).
	 */
	private function onNetworkState(string $json) : void{
		try{
			$state = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
		}catch(\JsonException $e){
			$this->logger->debug("Invalid network state from the proxy: " . $e->getMessage());
			return;
		}
		if(!is_array($state)){
			return;
		}
		$players = $state["players"] ?? 0;
		$this->globalPlayers = is_int($players) ? $players : 0;
		$this->serverStates = [];
		$servers = $state["servers"] ?? [];
		if(is_array($servers)){
			foreach(Utils::promoteKeys($servers) as $name => $server){
				if(is_array($server)){
					$count = $server["players"] ?? 0;
					//JSON object keys that look like numbers come back as ints
					$this->serverStates[(string) $name] = [is_int($count) ? $count : 0, ($server["online"] ?? false) === true];
				}
			}
		}
		$this->onlinePlayers = [];
		$this->onlinePlayerNames = [];
		$online = $state["online"] ?? [];
		if(is_array($online)){
			foreach(Utils::promoteKeys($online) as $name => $server){
				if(is_string($server)){
					//JSON object keys that look like numbers come back as ints
					$name = (string) $name;
					$this->onlinePlayers[$name] = $server;
					$this->onlinePlayerNames[mb_strtolower($name)] = $name;
				}
			}
		}
	}

	public function getServerName() : string{
		return $this->serverName;
	}

	/**
	 * Servers and groups of the network (from the proxy), for /server.
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getNetworkTargets() : array{
		return $this->networkTargets;
	}

	private function sessionIdOf(Player $player) : ?int{
		$session = $player->getNetworkSession();
		foreach($this->sessions as $sessionId => $s){
			if($s === $session){
				return $sessionId;
			}
		}
		return null;
	}

	/**
	 * Whether a player of this server came through the proxy (and can be moved to another server of the network).
	 */
	public function isProxied(Player $player) : bool{
		return $this->sessionIdOf($player) !== null;
	}

	/**
	 * Asks the proxy to move a player to a server or group of the network (or {@link self::TARGET_HUB}), after
	 * {@link PlayerProxyTransferEvent}. The proxy tells the player if the target is unknown or unreachable.
	 *
	 * @param string $style one of {@link ProxyTransferStyle}, or "" for the proxy's transfer-style
	 *
	 * @return bool false if the event was cancelled or the player did not come through the proxy
	 */
	public function transfer(Player $player, string $target, string $style = "") : bool{
		$sessionId = $this->sessionIdOf($player);
		if($sessionId === null){
			return false;
		}
		$ev = new PlayerProxyTransferEvent($player, $target, $style);
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}
		$this->requestedTransfers[$sessionId] = $this->server->getTick();
		$this->sendSession($sessionId, self::FRAME_TRANSFER_REQUEST, ProxyLinkThread::encodeString($ev->getTarget()) . ProxyLinkThread::encodeString($ev->getStyle()));
		return true;
	}

	public function isConnected() : bool{
		return count($this->links) > 0;
	}

	public function getServers() : array{
		return $this->servers;
	}

	public function getGroups() : array{
		return $this->groups;
	}

	public function isNetworkTarget(string $name) : bool{
		return $name === self::TARGET_HUB || in_array($name, $this->networkTargets, true);
	}

	public function getGlobalPlayerCount() : int{
		return $this->globalPlayers;
	}

	public function getServerPlayerCount(string $server) : int{
		return $this->serverStates[$server][0] ?? 0;
	}

	public function isServerOnline(string $server) : bool{
		return $this->serverStates[$server][1] ?? false;
	}

	public function getOnlinePlayers() : array{
		return $this->onlinePlayers;
	}

	public function findPlayer(string $name) : ?string{
		$exact = $this->onlinePlayerNames[mb_strtolower($name)] ?? null;
		return $exact !== null ? $this->onlinePlayers[$exact] : null;
	}

	public function sendPlayer(string $name, string $target, string $style = "") : void{
		$this->sendNetwork(self::FRAME_SEND_PLAYER, ProxyLinkThread::encodeString($name) . ProxyLinkThread::encodeString($target) . ProxyLinkThread::encodeString($style));
	}

	public function sendMessage(string $channel, string $data, ?string $server = null) : void{
		$this->sendNetwork(self::FRAME_MESSAGE, ProxyLinkThread::encodeString($channel) . ProxyLinkThread::encodeString($server ?? "") . $data);
	}

	public function broadcastAlert(string $message) : void{
		$this->sendNetwork(self::FRAME_ALERT, ProxyLinkThread::encodeString($message));
	}

	/**
	 * The server is stopping: hand every player back to the proxy, which moves them to another server instead of
	 * showing them a "Server closed" screen.
	 */
	public function releasePlayersForShutdown(string $reason) : void{
		$sessions = $this->sessions;
		foreach($sessions as $sessionId => $session){
			$this->sendSession($sessionId, self::FRAME_FALLBACK, ProxyLinkThread::encodeString($reason));
			$session->disconnect($reason, notify: false);
		}
	}

	public function shutdown() : void{
		$this->send(self::FRAME_SHUTDOWN, "");
		$this->disconnectAll("Server closed");
		$this->stopThread();
	}

	private function stopThread() : void{
		if($this->sleeperNotifierId !== -1){
			$this->server->getTickSleeper()->removeNotifier($this->sleeperNotifierId);
			$this->sleeperNotifierId = -1;
		}
		$thread = $this->thread;
		if($thread !== null){
			$this->wakeLinkThread(); //flushes queued frames (the shutdown) and notices quit() at once
			$thread->quit();
			$this->thread = null;
		}
		if($this->wakeSocket !== null){
			socket_close($this->wakeSocket);
			$this->wakeSocket = null;
		}
	}
}
