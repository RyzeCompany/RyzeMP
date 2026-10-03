<?php

declare(strict_types=1);

namespace pocketmine\anticheat\timeline;

final class CombatSample{
	public function __construct(
		public readonly int $protocolId,
		public readonly int $targetRuntimeId,
		public readonly ?int $clientTick,
		public readonly float $receivedAt,
		public readonly float $reach,
		public readonly float $directionDot,
		public readonly ?int $ping
	){}
}
