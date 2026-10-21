<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe;

/** Per-session transition observations. A transport receipt is not a rendering acknowledgement. */
final class DimensionTransition{

	/** @var array<int, true> */
	private array $receivedChunks = [];
	private ?float $serverAckAt = null;
	private ?float $clientAckAt = null;
	private ?float $screenStartedAt = null;
	private ?float $screenStoppedAt = null;
	private ?string $result = null;

	/** @param array<int, true> $arrivalChunks */
	public function __construct(
		private int $id,
		private ?int $loadingScreenId,
		private ?string $sourceWorld,
		private string $targetWorld,
		private int $dimension,
		private float $startedAt,
		private array $arrivalChunks,
		private bool $diagnostics
	){}

	public function getId() : int{ return $this->id; }
	public function getLoadingScreenId() : ?int{ return $this->loadingScreenId; }
	public function getSourceWorld() : ?string{ return $this->sourceWorld; }
	public function getTargetWorld() : string{ return $this->targetWorld; }
	public function getDimension() : int{ return $this->dimension; }
	public function getStartedAt() : float{ return $this->startedAt; }
	public function getServerAckAt() : ?float{ return $this->serverAckAt; }
	public function getClientAckAt() : ?float{ return $this->clientAckAt; }
	public function getScreenStartedAt() : ?float{ return $this->screenStartedAt; }
	public function getScreenStoppedAt() : ?float{ return $this->screenStoppedAt; }
	public function getResult() : ?string{ return $this->result; }
	public function hasDiagnostics() : bool{ return $this->diagnostics; }
	public function getReceivedChunkCount() : int{ return count($this->receivedChunks); }
	public function containsChunk(int $hash) : bool{ return isset($this->arrivalChunks[$hash]); }

	/** @internal */
	public function recordReceipt(int $hash) : void{
		if($this->containsChunk($hash)){
			$this->receivedChunks[$hash] = true;
		}
	}

	/** @internal */
	public function recordServerAck(float $now) : void{ $this->serverAckAt ??= $now; }
	/** @internal Untagged ACKs are observations, not proof of which screen was rendered. */
	public function recordClientAck(float $now) : void{ $this->clientAckAt ??= $now; }

	/** @internal Only matching tagged notifications may complete a modern transition. */
	public function recordScreen(bool $started, ?int $screenId, float $now) : bool{
		if($this->loadingScreenId === null || $screenId !== $this->loadingScreenId){
			return false;
		}
		if($started){
			$this->screenStartedAt ??= $now;
		}else{
			$this->screenStoppedAt ??= $now;
		}
		return true;
	}

	/** @internal */
	public function finish(string $result) : void{ $this->result = $result; }
}
