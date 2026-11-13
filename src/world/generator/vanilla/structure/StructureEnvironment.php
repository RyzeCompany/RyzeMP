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

use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\structure\template\StructureTemplates;

/**
 * The world a generator's structures are computed for: seed, height range, terrain sampler and biomes. One per
 * generator (dimension) instance.
 */
final class StructureEnvironment{

	/**
	 * @phpstan-param \Closure(int, int, int) : string $biomeAt Java biome name of the noise biome cell at a block
	 *                position (BiomeSource.getNoiseBiome(QuartPos.fromBlock(...)))
	 */
	public function __construct(
		public readonly int $seed,
		public readonly int $minY,
		public readonly int $height,
		public readonly int $seaLevel,
		public readonly string $dimension,
		public readonly TerrainSampler $terrain,
		private \Closure $biomeAt,
		public readonly StructureRegistry $registry,
		public readonly StructureTemplates $templates,
		public readonly JavaBlocks $blocks
	){}

	/** @phpstan-var (\Closure(string, int, int, int) : bool)|null */
	private ?\Closure $coldEnoughToSnow = null;

	/**
	 * @phpstan-param \Closure(string, int, int, int) : bool $check biome name, x, y, z => Biome.coldEnoughToSnow()
	 */
	public function setColdEnoughToSnow(\Closure $check) : void{
		$this->coldEnoughToSnow = $check;
	}

	/**
	 * Biome.coldEnoughToSnow() for the biome at a position (false when unknown).
	 */
	public function isColdEnoughToSnow(int $x, int $y, int $z) : bool{
		return $this->coldEnoughToSnow !== null && ($this->coldEnoughToSnow)($this->getBiome($x, $y, $z), $x, $y, $z);
	}

	/**
	 * Java biome name of the noise biome at a block position.
	 */
	public function getBiome(int $x, int $y, int $z) : string{
		return ($this->biomeAt)($x, $y, $z);
	}

	/** Highest block y (inclusive). */
	public function getMaxY() : int{
		return $this->minY + $this->height - 1;
	}
}
