<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\protocol\types;

/** Bedrock map icon IDs shared by the supported client versions. */
final class MapDecorationType{
	public const MARKER_WHITE = 0;
	public const SQUARE_WHITE = 6;
	public const MANSION = 14;
	public const MONUMENT = 15;
	public const VILLAGE_DESERT = 17;
	public const VILLAGE_PLAINS = 18;
	public const VILLAGE_SAVANNA = 19;
	public const VILLAGE_SNOWY = 20;
	public const VILLAGE_TAIGA = 21;
	public const JUNGLE_TEMPLE = 22;
	public const WITCH_HUT = 23;
	public const TRIAL_CHAMBERS = 24;

	private function __construct(){}
}
