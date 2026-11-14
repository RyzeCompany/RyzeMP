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

use function mt_rand;

/**
 * A Bedrock structure spawn area ("hard-coded spawn spots"): the box of a swamp hut (witches), an ocean monument
 * (guardians), a pillager outpost (pillagers) or a nether fortress (blazes, wither skeletons, skeletons, magma cubes).
 * When a cluster spawn attempt finds a spawnable block in a column inside the box, the spawner also tries to spawn one
 * of these mobs on the highest spawnable block of the column within the box's Y range. Structure mobs count as
 * underground ("cave") mobs and their population cap is one higher.
 *
 * Provided by {@link StructureSpawnProvider}s; PocketMine-MP does not generate these structures yet.
 */
final class StructureSpawnArea{
	/**
	 * @param array[]  $mobs          [entity identifier, weight, min herd size, max herd size, optional maximum
	 *                                brightness of this mob replacing $maxBrightness]
	 * @param bool     $persistent    whether the mobs never despawn
	 * @param int|null $maxBrightness spawn only where the brightness is at most this (null: any)
	 *
	 * @phpstan-param list<array{0: string, 1: int, 2: int, 3: int, 4?: int}> $mobs
	 */
	public function __construct(
		public readonly int $minX,
		public readonly int $minY,
		public readonly int $minZ,
		public readonly int $maxX,
		public readonly int $maxY,
		public readonly int $maxZ,
		public readonly array $mobs,
		public readonly string $population = SpawnCondition::POPULATION_MONSTER,
		public readonly bool $persistent = false,
		public readonly ?int $maxBrightness = null
	){}

	public function containsColumn(int $x, int $z) : bool{
		return $x >= $this->minX && $x <= $this->maxX && $z >= $this->minZ && $z <= $this->maxZ;
	}

	/**
	 * Picks a mob by weight: [identifier, herd size, maximum brightness of the mob or null], or null if there are none.
	 *
	 * @phpstan-return array{string, int, ?int}|null
	 */
	public function pickMob() : ?array{
		$total = 0;
		foreach($this->mobs as [, $weight, , ]){
			$total += $weight;
		}
		if($total <= 0){
			return null;
		}
		$roll = mt_rand(0, $total - 1);
		foreach($this->mobs as $mob){
			[$identifier, $weight, $min, $max] = $mob;
			$roll -= $weight;
			if($roll < 0){
				return [$identifier, mt_rand($min, $max < $min ? $min : $max), $mob[4] ?? null];
			}
		}
		return null;
	}
}
