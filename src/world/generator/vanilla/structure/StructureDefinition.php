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

namespace pocketmine\world\generator\vanilla\structure;

/**
 * A structure of the worldgen/structure data: its type, the biomes it may start in, its decoration step and terrain
 * adaptation.
 */
final class StructureDefinition{
	public const ADAPTATION_NONE = "none";
	public const ADAPTATION_BURY = "bury";
	public const ADAPTATION_BEARD_THIN = "beard_thin";
	public const ADAPTATION_BEARD_BOX = "beard_box";
	public const ADAPTATION_ENCAPSULATE = "encapsulate";

	/** GenerationStep.Decoration names, in order. */
	public const STEPS = [
		"raw_generation", "lakes", "local_modifications", "underground_structures", "surface_structures", "strongholds",
		"underground_ores", "underground_decoration", "fluid_springs", "vegetal_decoration", "top_layer_modification"
	];

	/**
	 * @param true[]  $biomes Java biome names
	 * @param mixed[] $json
	 * @phpstan-param array<string, true> $biomes
	 */
	public function __construct(
		public readonly string $id,
		public readonly StructureType $type,
		public readonly array $biomes,
		public readonly int $step,
		public readonly string $terrainAdaptation,
		public readonly array $json
	){}

	public function isValidBiome(string $biome) : bool{
		return isset($this->biomes[$biome]);
	}
}
