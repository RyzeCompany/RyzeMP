<?php

declare(strict_types=1);

namespace pocketmine\anticheat\timeline;

final class MovementSample{
	public function __construct(
		public readonly int $protocolId,
		public readonly int $tickDelta,
		public readonly float $timeDelta,
		public readonly float $horizontalPerTick,
		public readonly float $verticalPerTick,
		public readonly float $distancePerTick,
		public readonly ?int $ping,
		public readonly bool $sprinting,
		public readonly bool $flying,
		public readonly bool $swimming,
		public readonly bool $gliding
	){}
}
