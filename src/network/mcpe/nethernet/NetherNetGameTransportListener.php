<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

use amber\nethernet\Channel;
use amber\nethernet\Connection;
use amber\nethernet\SendResult;
use amber\nethernet\TransportListener;
use amber\nethernet\SignalingPeerAddress;

/**
 * Maps Amber's VERIFIED WebRTC peers into ordinary PMMP NetworkSession.
 *
 * Composition:
 *  Transport -> NetherNetHttpAnswerAdapter -> HttpSignaling -> this listener.
 * All callbacks are polled from the main thread (never the RakLib worker).
 * Transport identity is checked a second time against Bedrock login via
 * NetworkSession::useNetherNetTransport().
 *
 * @internal
 */
final class NetherNetGameTransportListener implements TransportListener{
	/** @var array<int, Connection> */
	private array $connections = [];

	public function __construct(private NetherNetSessionGateway $gateway){}

	public function onAnswer(int $id, string $answerSdp) : void{
		// The HTTP adapter handles this; never log signed SDP or credentials.
	}

	public function onOpen(Connection $connection) : void{
		$id = $connection->getId();
		// The concrete Amber implementation retains the trusted socket from
		// its HTTP signaling listener. We MUST NOT trust SDP candidate IPs.
		if(!method_exists($connection, "getSignalingPeerAddress")){
			$connection->close("Missing trusted signaling endpoint");
			return;
		}
		$peer = $connection->getSignalingPeerAddress();
		if(!$peer instanceof SignalingPeerAddress || $id <= 0 || isset($this->connections[$id])){
			$connection->close("Missing or duplicate verified signaling session");
			return;
		}
		$identity = $connection->getIdentity();
		$identityDer = $identity->publicKeyDer;
		try{
			$this->gateway->accept(
				$id,
				$peer->ip,
				$peer->port, // trusted TCP source port, not the ICE source port
				$identityDer,
				static function(string $payload, bool $immediate, ?int $receiptId) use ($connection) : void{
					if($receiptId !== null){
						throw new \LogicException("WebRTC does not provide a peer-ACK receipt");
					}
					if($connection->send($payload, Channel::RELIABLE, $immediate) !== SendResult::ACCEPTED){
						$connection->close("NetherNet outgoing queue is full");
					}
				},
				static function(string $reason) use ($connection) : void{
					$connection->close($reason);
				}
			);
			$this->connections[$id] = $connection;
		}catch(\Throwable $error){
			$connection->close("Cannot initialize authenticated NetherNet session");
			throw $error;
		}
	}

	public function onPayload(Connection $connection, string $payload, Channel $channel) : void{
		$id = $connection->getId();
		if(isset($this->connections[$id])){
			$this->gateway->receive($id, $payload);
		}
	}

	public function onClose(int $id, string $code, string $reason) : void{
		unset($this->connections[$id]);
		$this->gateway->close($id, "NetherNet disconnected: " . $code);
	}

	public function shutdown() : void{
		foreach($this->connections as $connection){
			$connection->close("NetherNet listener shutdown");
		}
		$this->connections = [];
		$this->gateway->shutdown();
	}
}
