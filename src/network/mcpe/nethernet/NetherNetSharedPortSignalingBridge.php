<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

/**
 * Session-scoped bridge between an authenticated WebRTC signaling server,
 * the public ICE SDP answer, and the RakLib thread's authorized ufrags.
 *
 * A caller MUST verify the client's Minecraft identity assertion and bind it
 * to the DTLS peer before invoking preparedAnswer(). This class does not
 * authenticate assertions or implement an HTTP listener.
 *
 * @internal
 */
final class NetherNetSharedPortSignalingBridge{
	/** @var array<string, string> connectionId => server ICE ufrag */
	private array $active = [];
	/** @var array<string, true> */
	private array $ufrags = [];

	/** @param \Closure(string): void $authorize @param \Closure(string): void $revoke */
	public function __construct(
		private \Closure $authorize,
		private \Closure $revoke
	){}

	/**
	 * Call only after the server generated its authenticated and signed SDP.
	 * Authorize ICE BEFORE returning the public answer to HTTP signaling.
	 * @throws \InvalidArgumentException on malformed or duplicate answers
	 */
	public function preparedAnswer(string $connectionId, string $signedSdp, string $publicIp, int $publicPort) : string{
		if($connectionId === "" || strlen($connectionId) > 256 || isset($this->active[$connectionId])){
			throw new \InvalidArgumentException("Invalid or repeated NetherNet connection ID");
		}
		$data = NetherNetSharedPortSdp::rewriteAnswer($signedSdp, $publicIp, $publicPort);
		$ufrag = $data["ufrag"];
		if(isset($this->ufrags[$ufrag])){
			throw new \InvalidArgumentException("Server ICE username fragment must be unique per live connection");
		}
		($this->authorize)($ufrag);
		$this->active[$connectionId] = $ufrag;
		$this->ufrags[$ufrag] = true;
		return $data["sdp"];
	}

	public function close(string $connectionId) : void{
		$ufrag = $this->active[$connectionId] ?? null;
		if($ufrag === null){
			return;
		}
		unset($this->active[$connectionId], $this->ufrags[$ufrag]);
		($this->revoke)($ufrag);
	}

	public function shutdown() : void{
		foreach(array_keys($this->active) as $connectionId){
			$this->close($connectionId);
		}
	}

	public function getConnectionCount() : int{ return count($this->active); }
}
