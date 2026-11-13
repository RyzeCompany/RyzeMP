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

namespace pocketmine\world\generator\vanilla\feature;

use pocketmine\world\generator\vanilla\feature\block\BlockSurvival;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;

/**
 * Everything a feature needs while decorating a chunk: the region, the random, the block data and the generator
 * settings (vanilla's WorldGenLevel + ChunkGenerator + PlacementContext rolled into one).
 */
final class FeatureContext{
	/** Placed feature being decorated with a biome check (PlacementContext.topFeature()), or null. */
	public ?PlacedFeature $topFeature = null;

	public function __construct(
		public readonly FeatureLevel $level,
		public readonly FeatureRandom $random,
		public readonly JavaBlocks $blocks,
		public readonly BlockSurvival $survival,
		public readonly FeatureRegistry $registry,
		public readonly int $minGenY,
		public readonly int $genDepth,
		public readonly int $seaLevel,
		public readonly int $seed
	){}
}
