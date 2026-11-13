<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

use pocketmine\network\mcpe\PacketSender;

/**
 * Bridges already-decoded NetherNet game batches to NetworkSession.
 *
 * The transport adapter owns WebRTC and must supply callbacks for sending
 * plaintext Bedrock batches and closing the *verified* connection.
 * Do not prefix bytes with RakNet's FE marker: that belongs to RakLib only.
 *
 * The send callback receives a receipt ID, if requested. It MUST complete
 * that receipt only on a genuine transport acknowledgment, never merely when
 * the local outgoing queue accepts a message.
 *
 * @internal
 */
final class NetherNetPacketSender implements PacketSender{
	private bool $closed = false;

	/**
	 * @param \Closure(string, bool, ?int): void $send
	 * @param \Closure(string): void $close
	 */
	public function __construct(
		private \Closure $send,
		private \Closure $close,
		private bool $supportsReceipts = false
	){}

	public function send(string $payload, bool $immediate, ?int $receiptId) : void{
		if($this->closed){
			return;
		}
		if($receiptId !== null && !$this->supportsReceipts){
			// Pretending that a local WebRTC queue admission is a peer ACK would
			// corrupt NetworkSession's write-receipt semantics.
			throw new \LogicException("NetherNet transport has no real packet receipt callback");
		}
		($this->send)($payload, $immediate, $receiptId);
	}

	public function close(string $reason = "unknown reason") : void{
		if(!$this->closed){
			$this->closed = true;
			($this->close)($reason);
		}
	}
}
