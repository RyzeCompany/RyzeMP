<?php

declare(strict_types=1);

namespace pocketmine\anticheat\impulse;

final class ExpectedImpulse{
	public function __construct(
		public readonly float $x,
		public readonly float $y,
		public readonly float $z,
		public readonly string $source,
		public readonly int $serverTick,
		public readonly int $protocolId,
		public readonly ?int $clientTickAtSend,
		public readonly float $createdAt
	){}
}
