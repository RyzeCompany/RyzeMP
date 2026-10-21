<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\raklib;

use raklib\server\ProtocolAcceptor;

/** Keep RakNet 10 gated independently from the primary transport. */
final class MinecraftProtocolAcceptor implements ProtocolAcceptor{
	public function __construct(
		private int $primaryVersion,
		private bool $allowLegacy
	){}

	public function accepts(int $protocolVersion) : bool{
		return $protocolVersion === $this->primaryVersion || ($this->allowLegacy && $protocolVersion === 10);
	}

	public function getPrimaryVersion() : int{
		return $this->primaryVersion;
	}
}
