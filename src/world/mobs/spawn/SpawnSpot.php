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

namespace pocketmine\world\mobs\spawn;

use function max;

/**
 * A position where the spawn algorithm found room for a mob: the block the mob's feet are in and what is around it.
 * Built by {@link \pocketmine\world\mobs\NaturalMobSpawner} from the world, or directly by tests.
 */
final class SpawnSpot{
	/** The feet are in air (or a block without collision, like grass or a flower) above a solid top surface. */
	public const MEDIUM_AIR = 0;
	/** The feet are in water above a solid top surface. */
	public const MEDIUM_WATER = 1;
	/** The feet are in air above lava (striders). */
	public const MEDIUM_LAVA = 2;

	/**
	 * @param bool   $surface        whether this is the highest spawnable position of the column (Bedrock "surface";
	 *                               any other position is "underground", like caves or the ground under leaves)
	 * @param string $blockBelow     Bedrock name of the block below the feet (e.g. "minecraft:grass_block")
	 * @param string $blockAtFeet    Bedrock name of the block at the feet ("minecraft:air", "minecraft:snow_layer"...)
	 * @param int    $skyLight       raw sky light at the feet (0-15, before the time of day)
	 * @param float  $playerDistance distance to the nearest player
	 */
	public function __construct(
		public readonly int $x,
		public readonly int $y,
		public readonly int $z,
		public readonly bool $surface,
		public readonly int $medium,
		public readonly string $blockBelow,
		public readonly string $blockAtFeet,
		public readonly int $blockLight,
		public readonly int $skyLight,
		public readonly int $biomeId,
		public readonly float $playerDistance
	){}

	/**
	 * Bedrock brightness: the highest of block light and sky light dimmed by the time of day (and the weather if
	 * $skyReduction includes it).
	 */
	public function getBrightness(int $skyReduction) : int{
		return max($this->blockLight, $this->skyLight - $skyReduction);
	}
}
