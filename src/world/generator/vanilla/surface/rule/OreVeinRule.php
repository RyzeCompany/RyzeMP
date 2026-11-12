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

namespace pocketmine\world\generator\vanilla\surface\rule;

use pocketmine\world\generator\vanilla\density\DensityFunction;
use pocketmine\world\generator\vanilla\density\InterpolatedFunction;
use pocketmine\world\generator\vanilla\random\PositionalRandomFactory;
use pocketmine\world\generator\vanilla\surface\DensityBounds;
use pocketmine\world\generator\vanilla\surface\InterpolationCache;
use pocketmine\world\generator\vanilla\surface\MaterialContext;
use pocketmine\world\generator\vanilla\surface\MaterialRuleCompiler;
use function max;
use function min;
use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * "minecraft:ore_vein" (large copper and iron veins). The density function is the chance that a block belongs to the
 * vein; vein blocks are ore when a richness roll passes outside the gaps (raw ore blocks with a small chance), filler
 * otherwise. Same random draws as the pre-26.3 OreVeinifier ("minecraft:ore" positional random).
 *
 * Interpolated inputs (vein toggle, ridges) are interpolated over their cells per chunk, and cells whose density can
 * not be positive (interval bounds) are skipped without evaluating any block.
 */
final class OreVeinRule extends MaterialRule{
	private const CELL_WIDTH = 4;
	private const CELL_HEIGHT = 8;

	/**
	 * @var InterpolatedFunction[]
	 * @phpstan-var array<int, InterpolatedFunction>
	 */
	private readonly array $interpolated;
	private int $minBlockY = PHP_INT_MAX;
	private int $maxBlockY = PHP_INT_MIN;

	/**
	 * @var bool[]
	 * @phpstan-var array<int, bool>
	 */
	private array $cells = [];
	private int $cellsStamp = -1;

	public function __construct(
		private readonly DensityFunction $density,
		private readonly DensityFunction $richness,
		private readonly DensityFunction $fillerGap,
		private readonly int $ore,
		private readonly int $rawOre,
		private readonly int $filler,
		private readonly float $rawOreChance,
		private readonly PositionalRandomFactory $random,
		private readonly InterpolationCache $cache,
		int $minY,
		int $height
	){
		$this->interpolated = InterpolationCache::collect($density) + InterpolationCache::collect($richness) + InterpolationCache::collect($fillerGap);
		//static height range where the density can be positive (interpolated inputs at their declared ranges)
		for($y = $minY; $y < $minY + $height; $y += self::CELL_HEIGHT){
			[, $max] = DensityBounds::of($density, 0, $y, 0, 15, $y + self::CELL_HEIGHT - 1, 15, null);
			if($max > 0){
				$this->minBlockY = min($this->minBlockY, $y);
				$this->maxBlockY = max($this->maxBlockY, $y + self::CELL_HEIGHT - 1);
			}
		}
	}

	public function apply(MaterialContext $c) : int{
		$y = $c->blockY;
		if($y < $this->minBlockY || $y > $this->maxBlockY){
			return self::NONE;
		}
		if(!$this->open($c)){
			return self::NONE;
		}
		$x = $c->blockX;
		$z = $c->blockZ;
		$this->cache->inject($this->interpolated, $x, $y, $z);
		$density = $this->density->compute($x, $y, $z);
		if($density <= 0){
			return self::NONE;
		}
		$random = $this->random->at($x, $y, $z);
		if($random->nextFloat() > $density){
			return self::NONE;
		}
		if($random->nextFloat() < $this->richness->compute($x, $y, $z) && $this->fillerGap->compute($x, $y, $z) < 0){
			return $random->nextFloat() < $this->rawOreChance ? $this->rawOre : $this->ore;
		}
		return $this->filler;
	}

	/**
	 * Whether the density can be positive in the 4x8x4 cell of the block (bounds computed once per cell and chunk).
	 */
	public function open(MaterialContext $c) : bool{
		if($this->cellsStamp !== $c->chunkStamp){
			$this->cellsStamp = $c->chunkStamp;
			$this->cells = [];
		}
		$cellY = ($c->blockY - $c->minY) >> 3;
		$key = (((($c->localX >> 2) << 2) | ($c->localZ >> 2)) << 8) | $cellY;
		return $this->cells[$key] ?? $this->computeCell($key, $c, $cellY);
	}

	private function computeCell(int $key, MaterialContext $c, int $cellY) : bool{
		$x0 = $c->blockX & ~(self::CELL_WIDTH - 1);
		$z0 = $c->blockZ & ~(self::CELL_WIDTH - 1);
		$y0 = $c->minY + ($cellY << 3);
		[, $max] = DensityBounds::of($this->density, $x0, $y0, $z0, $x0 + self::CELL_WIDTH - 1, $y0 + self::CELL_HEIGHT - 1, $z0 + self::CELL_WIDTH - 1, $this->cache);
		return $this->cells[$key] = $max > 0;
	}

	public function compile(MaterialRuleCompiler $compiler) : string{
		$density = $compiler->temp();
		$random = $compiler->temp();
		return "if(\$y >= " . $this->minBlockY . " && \$y <= " . $this->maxBlockY . " && " . $compiler->bind($this) . "->open(\$c)" .
			" && ($density = " . $compiler->density($this->density, $this->cache) . ") > 0" .
			" && ({$random} = " . $compiler->bind($this->random) . "->at(\$x, \$y, \$z))->nextFloat() <= $density){\n" .
			"return ({$random}->nextFloat() < " . $compiler->density($this->richness, $this->cache) . " && " . $compiler->density($this->fillerGap, $this->cache) . " < 0) ? " .
			"({$random}->nextFloat() < " . MaterialRuleCompiler::float($this->rawOreChance) . " ? " . $this->rawOre . " : " . $this->ore . ") : " . $this->filler . ";\n}\n";
	}
}
