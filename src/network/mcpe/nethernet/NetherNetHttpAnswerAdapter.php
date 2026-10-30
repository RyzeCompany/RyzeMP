<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

use amber\nethernet\Channel;
use amber\nethernet\Connection;
use amber\nethernet\TransportListener;
use amber\nethernet\signaling\HttpSignaling;

/**
 * The key to using amber-pm/nethernet without modifying upstream:
 *
 * Transport -> this adapter -> HttpSignaling -> HTTP socket.
 *
 * Normally HttpSignaling::onAnswer() prepares its HTTP response BEFORE it
 * notifies its own listener. Rewriting that notification would be too late.
 * Instead, install this adapter directly as TransportListener in Transport,
 * which authorizes the authenticated ICE ufrag and rewrites candidate
 * addresses BEFORE forwarding to HttpSignaling::onAnswer().
 *
 * The Amber Transport has already verified the client assertion and signed
 * the server answer by the time it emits this callback.
 *
 * @internal
 */
final class NetherNetHttpAnswerAdapter implements TransportListener{
	private ?HttpSignaling $signaling = null;

	public function __construct(
		private NetherNetSharedPortSignalingBridge $bridge,
		private \Closure $resolveAdvertisedIp,
		private int $advertisedPort
	){}

	public function bind(HttpSignaling $signaling) : void{
		if($this->signaling !== null){
			throw new \LogicException("NetherNet signaling adapter already bound");
		}
		$this->signaling = $signaling;
	}

	private function signaling() : HttpSignaling{
		return $this->signaling ?? throw new \LogicException("NetherNet signaling adapter not bound");
	}

	public function onAnswer(int $id, string $answerSdp) : void{
		$connectionId = (string) $id;
		$publicIp = ($this->resolveAdvertisedIp)($id);
		if(!is_string($publicIp) || NetherNetHostAddress::resolve($publicIp, null) === null || $publicIp === "0.0.0.0"){
			throw new \InvalidArgumentException("Cannot infer a concrete public IPv4 from server-ip or the join Host header");
		}
		$answer = $this->bridge->preparedAnswer(
			$connectionId, $answerSdp, $publicIp, $this->advertisedPort
		);
		try{
			$this->signaling()->onAnswer($id, $answer);
		}catch(\Throwable $e){
			$this->bridge->close($connectionId);
			throw $e;
		}
	}

	public function onOpen(Connection $connection) : void{
		$this->signaling()->onOpen($connection);
	}

	public function onPayload(Connection $connection, string $payload, Channel $channel) : void{
		$this->signaling()->onPayload($connection, $payload, $channel);
	}

	public function onClose(int $id, string $code, string $reason) : void{
		$this->bridge->close((string) $id);
		$this->signaling()->onClose($id, $code, $reason);
	}
}
