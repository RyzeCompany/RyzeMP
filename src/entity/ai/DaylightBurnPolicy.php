<?php
declare(strict_types=1);

namespace pocketmine\entity\ai;

/** Deterministic eligibility for undead sunlight ignition; probability is in Mob. */
final class DaylightBurnPolicy{
	private function __construct(){}
	public static function isExposed(bool $overworld, int $skyReduction, int $potentialSkyLight, bool $inWater, bool $raining) : bool{
		return $overworld && $skyReduction < 4 && $potentialSkyLight >= 15 && !$inWater && !$raining;
	}
}
