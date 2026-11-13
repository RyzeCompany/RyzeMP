<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

/**
 * NetherNet WebRTC data-channel framing (official one-byte fragment countdown).
 *
 * One decoder instance MUST belong to one peer AND one data channel.
 * This is not a WebRTC implementation or a socket listener.
 *
 * @internal
 */
final class NetherNetFrameCodec{
	private const MAX_REASSEMBLED_SIZE = 8 * 1024 * 1024;

	private int $remaining = 0;
	private string $pending = "";

	/**
	 * Splits a Bedrock batch into SCTP messages including the NetherNet header.
	 * @return list<string>
	 */
	public static function fragment(string $payload, int $maxMessageBytes, bool $reliable) : array{
		if($maxMessageBytes < 2){
			throw new \InvalidArgumentException("Max SCTP message size must be at least 2 bytes");
		}
		$len = strlen($payload);
		if($len > self::MAX_REASSEMBLED_SIZE){
			throw new \LengthException("NetherNet batch exceeds safe size");
		}
		$fragmentSize = $maxMessageBytes - 1;
		if(!$reliable && $len > $fragmentSize){
			throw new \LengthException("Unreliable NetherNet channel cannot fragment");
		}
		$count = max(1, (int) ceil($len / $fragmentSize));
		if($count > 256){
			throw new \LengthException("NetherNet fragment counter exceeds one byte");
		}
		$out = [];
		for($i = 0; $i < $count; ++$i){
			$out[] = chr($count - $i - 1) . substr($payload, $i * $fragmentSize, $fragmentSize);
		}
		return $out;
	}

	/**
	 * Consumes exactly one SCTP message; returns a completed batch or null.
	 * @throws \UnexpectedValueException for illegal sequence or channel misuse
	 */
	public function receive(string $message, bool $reliable) : ?string{
		if($message === ""){
			$this->reset();
			throw new \UnexpectedValueException("Missing NetherNet fragment header");
		}
		$remaining = ord($message[0]);
		$chunk = substr($message, 1);
		if(!$reliable && $remaining !== 0){
			$this->reset();
			throw new \UnexpectedValueException("Unreliable channel cannot carry NetherNet fragments");
		}
		if($this->remaining === 0 && $this->pending === ""){
			if($remaining === 0){
				if(strlen($chunk) > self::MAX_REASSEMBLED_SIZE){
					throw new \LengthException("NetherNet message exceeds safe size");
				}
				return $chunk;
			}
		}else if($remaining !== $this->remaining - 1){
			$this->reset();
			throw new \UnexpectedValueException("Out-of-order NetherNet fragments");
		}
		if(strlen($chunk) > self::MAX_REASSEMBLED_SIZE - strlen($this->pending)){
			$this->reset();
			throw new \LengthException("Reassembled NetherNet message exceeds safe size");
		}
		$this->pending .= $chunk;
		$this->remaining = $remaining;
		if($remaining === 0){
			$complete = $this->pending;
			$this->reset();
			return $complete;
		}
		return null;
	}

	public function reset() : void{
		$this->remaining = 0;
		$this->pending = "";
	}
}
