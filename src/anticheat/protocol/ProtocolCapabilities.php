<?php

declare(strict_types=1);

namespace pocketmine\anticheat\protocol;

use pocketmine\network\mcpe\protocol\ProtocolInfo;

/**
 * Describes which PlayerAuthInput fields can be trusted to exist for a
 * particular supported Bedrock protocol.
 */
final class ProtocolCapabilities{
	public function __construct(
		public readonly int $protocolId,
		public readonly bool $hasInteractRotation,
		public readonly bool $hasCameraOrientation,
		public readonly bool $hasRawMove,
		public readonly bool $hasModernOptionalInputData
	){}

	public static function fromProtocolId(int $protocolId) : self{
		return new self(
			$protocolId,
			$protocolId >= ProtocolInfo::PROTOCOL_1_21_40,
			$protocolId >= ProtocolInfo::PROTOCOL_1_21_40,
			$protocolId >= ProtocolInfo::PROTOCOL_1_21_50,
			$protocolId >= ProtocolInfo::PROTOCOL_1_26_40
		);
	}
}
