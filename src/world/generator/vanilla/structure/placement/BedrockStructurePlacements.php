<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure\placement;

/**
 * The switchable table of structure positions: which structure sets use Bedrock Edition's own placement (Mersenne
 * Twister, see {@link BedrockSpreadPlacement}) instead of the Java placement of the structure_set data.
 *
 * Only the constants documented in OPCIONES-GENERADOR-VANILLA.md (section 3.3) are listed: villages (salt 10387312,
 * spacing 34, 26 offsets) and woodland mansions (10387319, 80, 60). Other sets keep Java's placement until their
 * Bedrock constants are verified with BDS (/locate); the candidates are listed in STRUCTURES-PLAN.md.
 */
final class BedrockStructurePlacements{
	/**
	 * Java structure set id => [salt, spacing, range (offsets per axis), triangular spread]
	 */
	public const TABLE = [
		"minecraft:villages" => [10387312, 34, 26, true],
		"minecraft:woodland_mansions" => [10387319, 80, 60, true],
	];

	/** Bedrock positions where known (TABLE), Java's elsewhere. */
	public const MODE_BEDROCK = "bedrock";
	/** Java Edition positions for every structure set. */
	public const MODE_JAVA = "java";

	private function __construct(){
		//NOOP
	}

	/**
	 * The placement to use for a structure set: Bedrock's when the mode is "bedrock" and the set is in the table,
	 * otherwise the Java placement read from the data. The Java placement's frequency and exclusion zone are kept.
	 */
	public static function select(string $setId, StructurePlacement $java, string $mode) : StructurePlacement{
		$entry = self::TABLE[$setId] ?? null;
		if($mode !== self::MODE_BEDROCK || $entry === null){
			return $java;
		}
		[$salt, $spacing, $range, $triangular] = $entry;
		return new BedrockSpreadPlacement($salt, $spacing, $range, $triangular, $java->frequency, $java->frequencyReduction, $java->exclusionSet, $java->exclusionChunks, $java->locateOffset);
	}
}
