<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\protocol\types;

/** Disconnect reason IDs used by VanillaMine's player API. */
final class DisconnectFailReason{
	public const UNKNOWN = 0;
	public const KICKED = 55;

	private function __construct(){}
}
