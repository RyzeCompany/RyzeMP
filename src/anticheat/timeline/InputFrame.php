<?php

declare(strict_types=1);

namespace pocketmine\anticheat\timeline;

/**
 * Immutable, compact snapshot of one PlayerAuthInput packet.
 *
 * We intentionally don't retain the packet object, Player or World here.
 * This keeps the timeline cheap and prevents accidental long-lived references.
 */
final class InputFrame{
	public function __construct(
		public readonly int $clientTick,
		public readonly int $protocolId,
		public readonly int $inputMode,
		public readonly int $playMode,
		public readonly int $interactionMode,
		public readonly float $receivedAt,
		public readonly float $x,
		public readonly float $y,
		public readonly float $z,
		public readonly float $deltaX,
		public readonly float $deltaY,
		public readonly float $deltaZ,
		public readonly float $yaw,
		public readonly float $pitch
	){}
}
