<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

use pocketmine\lang\KnownTranslationFactory;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\Network;
use pocketmine\network\PacketHandlingException;
use pocketmine\Server;

/**
 * Bridge from a VERIFIED NetherNet connection to the existing NetworkSession.
 *
 * The WebRTC implementation MUST authenticate the assertion, bind it to the
 * DTLS peer, and supply the trusted socket endpoint before calling accept().
 * Session IDs are transport-local and never exposed to clients.
 *
 * This is intentionally not a listener or a UDP multiplexor.
 *
 * @internal
 */
final class NetherNetSessionGateway{
	/** @var array<int, NetworkSession> */
	private array $sessions = [];

	public function __construct(
		private Server $server,
		private Network $network,
		private PacketBroadcaster $packetBroadcaster,
		private EntityEventBroadcaster $entityEventBroadcaster,
		private TypeConverter $typeConverter
	){}

	public static function validatePeerEndpoint(string $ip, int $port) : void{
		if(filter_var($ip, FILTER_VALIDATE_IP) === false ||
			$ip === "0.0.0.0" || $ip === "::" ||
			$port < 1 || $port > 65535){
			throw new \InvalidArgumentException("NetherNet peer endpoint must be a concrete numeric IP and port");
		}
	}

	/**
	 * Call only after the WebRTC DTLS connection and client identity have
	 * been verified. The two callbacks are adapter-owned (not user input).
	 *
	 * @param \Closure(string, bool, ?int): void $send
	 * @param \Closure(string): void $close
	 */
	public function accept(
		int $id,
		string $verifiedIp,
		int $verifiedPort,
		string $verifiedIdentityPublicKeyDer,
		\Closure $send,
		\Closure $close,
		bool $supportsReceipts = false,
		?string $verifiedXuid = null
	) : NetworkSession{
		if($id <= 0 || isset($this->sessions[$id])){
			throw new \InvalidArgumentException("Invalid or duplicate NetherNet connection ID");
		}
		self::validatePeerEndpoint($verifiedIp, $verifiedPort);
		$session = new NetworkSession(
			$this->server,
			$this->network->getSessionManager(),
			PacketPool::getInstance(),
			new NetherNetPacketSender($send, $close, $supportsReceipts),
			$this->packetBroadcaster,
			$this->entityEventBroadcaster,
			ZlibCompressor::getInstance(),
			$this->typeConverter,
			$verifiedIp,
			$verifiedPort
		);
		$session->useNetherNetTransport($verifiedIdentityPublicKeyDer, $verifiedXuid);
		$this->sessions[$id] = $session;
		return $session;
	}

	/** Handles a reassembled and unframed Bedrock batch, not a WebRTC frame. */
	public function receive(int $id, string $payload) : void{
		$session = $this->sessions[$id] ?? null;
		if($session === null){
			return;
		}
		try{
			$session->handleEncoded($payload);
		}catch(PacketHandlingException $e){
			$session->getLogger()->debug("NetherNet invalid batch: " . $e->getMessage());
			$session->disconnectWithError(
				reason: "Bad packet: " . $e->getMessage(),
				disconnectScreenMessage: KnownTranslationFactory::pocketmine_disconnect_error_badPacket()
			);
		}catch(\Throwable $e){
			$logger = $this->server->getLogger();
			$logger->emergency("Unexpected NetherNet session error for " . $session->getDisplayName() .
				": " . get_class($e) . " at " . $e->getFile() . ":" . $e->getLine());
			if($e instanceof \pocketmine\utils\AssumptionFailedError){
				$logger->error("Internal invariant: " . substr($e->getMessage(), 0, 2048));
			}
			// Deliberately omit trace arguments: packet/SDP/JWT values must not be logged.
			foreach(array_slice($e->getTrace(), 0, 16) as $i => $frame){
				$logger->error("NetherNet trace #$i " . ($frame["class"] ?? "") .
					($frame["type"] ?? "") . $frame["function"] . " at " .
					($frame["file"] ?? "[internal]") . ":" . ($frame["line"] ?? 0));
			}
			throw $e;
		}
	}

	/**
	 * Native transport must call this only when a *real* transport ACK was
	 * received. Local WebRTC enqueue success is not an acknowledgement.
	 */
	public function acknowledge(int $id, int $receiptId) : void{
		($this->sessions[$id] ?? null)?->handleAckReceipt($receiptId);
	}

	public function close(int $id, string $reason = "NetherNet disconnected") : void{
		$session = $this->sessions[$id] ?? null;
		unset($this->sessions[$id]);
		$session?->onClientDisconnect($reason);
	}

	public function shutdown() : void{
		foreach(array_keys($this->sessions) as $id){
			$this->close($id, "NetherNet interface stopped");
		}
	}

	public function getConnectionCount() : int{ return count($this->sessions); }
}
