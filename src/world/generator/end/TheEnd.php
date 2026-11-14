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

namespace pocketmine\world\generator\end;

use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\utils\Random;
use pocketmine\world\ChunkManager;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\Generator;
use pocketmine\world\generator\noise\Simplex;
use function abs;
use function cos;
use function intdiv;
use function max;
use function min;
use function sin;
use function sqrt;
use const M_PI;

/**
 * Simplified End: the main island of end stone around 0,0 with the ring of ten obsidian pillars. There are no outer
 * islands, chorus plants, End cities or exit portal (the exit portal only matters after the dragon is killed).
 */
class TheEnd extends Generator{
	/** Average radius of the main island, in blocks. */
	public const ISLAND_RADIUS = 76;
	/** Y of the island surface at its centre. */
	public const ISLAND_SURFACE_Y = 62;
	private const ISLAND_DEPTH = 48;

	public const PILLAR_COUNT = 10;
	public const PILLAR_RING_RADIUS = 42;

	private Simplex $edgeNoise;
	private Simplex $surfaceNoise;

	/**
	 * @var int[][] x, z, radius, top Y
	 * @phpstan-var list<array{int, int, int, int}>
	 */
	private array $pillars = [];

	public function __construct(int $seed, string $preset){
		parent::__construct($seed, $preset);

		$random = new Random($seed);
		$this->edgeNoise = new Simplex($random, 2, 1 / 2, 1 / 32);
		$this->surfaceNoise = new Simplex($random, 2, 1 / 2, 1 / 16);
		$this->pillars = self::computePillars($seed);
	}

	/**
	 * Vanilla places ten pillars on a circle of radius 42; their heights are 76, 79, ... 103 in a seed-dependent order
	 * and the taller pillars are wider.
	 *
	 * @return int[][]
	 * @phpstan-return list<array{int, int, int, int}>
	 */
	public static function computePillars(int $seed) : array{
		$heights = [];
		for($i = 0; $i < self::PILLAR_COUNT; ++$i){
			$heights[] = $i;
		}
		$random = new Random($seed ^ 0x5eed_e4d);
		for($i = self::PILLAR_COUNT - 1; $i > 0; --$i){
			$j = $random->nextBoundedInt($i + 1);
			[$heights[$i], $heights[$j]] = [$heights[$j], $heights[$i]];
		}

		$pillars = [];
		for($i = 0; $i < self::PILLAR_COUNT; ++$i){
			$angle = 2 * (-M_PI + (M_PI / self::PILLAR_COUNT) * $i);
			$heightIndex = $heights[$i];
			$pillars[] = [
				(int) (self::PILLAR_RING_RADIUS * cos($angle)),
				(int) (self::PILLAR_RING_RADIUS * sin($angle)),
				2 + intdiv($heightIndex, 3),
				76 + $heightIndex * 3
			];
		}
		return $pillars;
	}

	/**
	 * Returns the [bottom, top] Y of the island at the given column, or null if the column is outside the island.
	 *
	 * @return int[]|null
	 * @phpstan-return array{int, int}|null
	 */
	public function getIslandColumn(int $x, int $z) : ?array{
		$distance = sqrt($x * $x + $z * $z);
		$radius = self::ISLAND_RADIUS + $this->edgeNoise->noise2D($x, $z, true) * 10;
		if($distance >= $radius){
			return null;
		}
		$t = $distance / $radius;
		$top = self::ISLAND_SURFACE_Y - (int) ($t * $t * 6) + (int) ($this->surfaceNoise->noise2D($x, $z, true) * 2);
		$depth = (int) (self::ISLAND_DEPTH * sqrt(1 - $t)) + 1;
		return [max(1, $top - $depth), $top];
	}

	public function generateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{
		$chunk = $world->getChunk($chunkX, $chunkZ) ?? throw new \InvalidArgumentException("Chunk $chunkX $chunkZ does not yet exist");

		$endStone = VanillaBlocks::END_STONE()->getStateId();
		$obsidian = VanillaBlocks::OBSIDIAN()->getStateId();
		$bedrock = VanillaBlocks::BEDROCK()->getStateId();

		$baseX = $chunkX << Chunk::COORD_BIT_SIZE;
		$baseZ = $chunkZ << Chunk::COORD_BIT_SIZE;
		$nearOrigin = abs($baseX) <= 128 && abs($baseZ) <= 128;

		for($x = 0; $x < Chunk::EDGE_LENGTH; ++$x){
			for($z = 0; $z < Chunk::EDGE_LENGTH; ++$z){
				for($y = 0; $y < 256; ++$y){
					$chunk->setBiomeId($x, $y, $z, BiomeIds::THE_END);
				}
				if(!$nearOrigin){
					continue;
				}
				$worldX = $baseX + $x;
				$worldZ = $baseZ + $z;
				$column = $this->getIslandColumn($worldX, $worldZ);
				if($column !== null){
					[$bottom, $top] = $column;
					for($y = $bottom; $y <= $top; ++$y){
						$chunk->setBlockStateId($x, $y, $z, $endStone);
					}
				}

				foreach($this->pillars as [$pillarX, $pillarZ, $radius, $height]){
					$dx = $worldX - $pillarX;
					$dz = $worldZ - $pillarZ;
					if($dx * $dx + $dz * $dz > $radius * $radius + 1){
						continue;
					}
					$pillarBottom = min(self::ISLAND_SURFACE_Y - 10, $column !== null ? $column[0] : self::ISLAND_SURFACE_Y - 10);
					for($y = $pillarBottom; $y < $height; ++$y){
						$chunk->setBlockStateId($x, $y, $z, $obsidian);
					}
					if($dx === 0 && $dz === 0){
						$chunk->setBlockStateId($x, $height, $z, $bedrock); //where the End crystal would sit
					}
				}
			}
		}
	}

	public function populateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{
		//NOOP: no decorations yet
	}
}
