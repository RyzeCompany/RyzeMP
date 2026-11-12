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

namespace pocketmine\world\generator\vanilla\feature\type;

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\feature\Feature;
use pocketmine\world\generator\vanilla\feature\FeatureContext;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use pocketmine\world\generator\vanilla\feature\IntProvider;
use pocketmine\world\generator\vanilla\feature\PlacedFeature;
use pocketmine\world\generator\vanilla\feature\Predicates;
use pocketmine\world\generator\vanilla\feature\Providers;
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\WorldgenData;
use function abs;
use function intdiv;
use function max;
use function min;

/**
 * Feature types of the Nether (Java 26.3 names): random_neighbor_spread (glowstone, nether wart roofs),
 * single_block_pillar and projected_random_patchy_square (basalt pillars), stepped_column_cluster (basalt columns),
 * delta_feature (lava deltas), netherrack_replace_blobs (basalt and blackstone blobs) and huge_fungus.
 */
final class NetherFeatures{

	private function __construct(){
		//NOOP
	}

	/**
	 * Blocks a delta never replaces (DeltaFeature.CANNOT_REPLACE).
	 */
	private const DELTA_CANNOT_REPLACE = ["bedrock", "nether_bricks", "nether_brick_fence", "nether_brick_stairs", "nether_wart", "chest", "spawner"];

	/**
	 * random_neighbor_spread: the block at the origin, then blocks at random offsets that touch exactly one accepted
	 * neighbour (GlowstoneFeature and the nether wart roof of WeepingVinesFeature).
	 *
	 * @param mixed[] $json
	 */
	public static function randomNeighborSpread(FeatureRegistry $registry, array $json) : Feature{
		$blocks = $registry->blocks;
		return new class(
			WorldgenData::int($json, "attempts"),
			$registry->stateProvider($json["block"] ?? null),
			$blocks->blockSet($json["accepted_neighbors"] ?? null),
			Predicates::block($blocks, $json["can_replace"] ?? null),
			Providers::int($json["xz_offset"] ?? null),
			Providers::int($json["y_offset"] ?? null)
		) implements Feature{
			/**
			 * @param true[] $accepted
			 * @phpstan-param \Closure(FeatureContext, \pocketmine\world\generator\vanilla\feature\FeatureRandom, int, int, int) : ?int $block
			 * @phpstan-param array<int, true> $accepted
			 * @phpstan-param \Closure(FeatureContext, int, int, int) : bool $canReplace
			 */
			public function __construct(
				private int $attempts,
				private \Closure $block,
				private array $accepted,
				private \Closure $canReplace,
				private IntProvider $xzOffset,
				private IntProvider $yOffset
			){}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				$level = $c->level;
				$random = $c->random;
				$blockOf = $c->blocks->blockOf;
				$state = ($this->block)($c, $random, $x, $y, $z);
				if($state === null){
					return false;
				}
				$level->setState($x, $y, $z, $state);
				for($i = 0; $i < $this->attempts; ++$i){
					$px = $x + $this->xzOffset->sample($random);
					$py = $y + $this->yOffset->sample($random);
					$pz = $z + $this->xzOffset->sample($random);
					if(!($this->canReplace)($c, $px, $py, $pz)){
						continue;
					}
					$count = 0;
					for($d = 0; $d < 6; ++$d){
						if(isset($this->accepted[$blockOf[$level->getState($px + Direction::STEP_X[$d], $py + Direction::STEP_Y[$d], $pz + Direction::STEP_Z[$d])]])){
							++$count;
							if($count > 1){
								break;
							}
						}
					}
					if($count === 1){
						$state = ($this->block)($c, $random, $px, $py, $pz);
						if($state !== null){
							$level->setState($px, $py, $pz, $state);
						}
					}
				}
				return true;
			}
		};
	}

	/**
	 * single_block_pillar: a column of blocks from the origin in one direction while the blocks can be replaced
	 * (optionally continuing with a chance), then the cap feature at the last block.
	 *
	 * @param mixed[] $json
	 */
	public static function singleBlockPillar(FeatureRegistry $registry, array $json) : Feature{
		return new class(
			$registry->stateProvider($json["block"] ?? null),
			Predicates::block($registry->blocks, $json["can_replace"] ?? null),
			Direction::fromName(WorldgenData::string($json, "direction", "down")),
			isset($json["chance_to_continue"]) ? FloatMath::fround(WorldgenData::float($json, "chance_to_continue")) : null,
			isset($json["cap_feature"]) ? $registry->placedRef($json["cap_feature"]) : null
		) implements Feature{
			/**
			 * @phpstan-param \Closure(FeatureContext, \pocketmine\world\generator\vanilla\feature\FeatureRandom, int, int, int) : ?int $block
			 * @phpstan-param \Closure(FeatureContext, int, int, int) : bool $canReplace
			 */
			public function __construct(
				private \Closure $block,
				private \Closure $canReplace,
				private int $direction,
				private ?float $chanceToContinue,
				private ?PlacedFeature $cap
			){}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				$level = $c->level;
				$random = $c->random;
				$dx = Direction::STEP_X[$this->direction];
				$dy = Direction::STEP_Y[$this->direction];
				$dz = Direction::STEP_Z[$this->direction];
				$placed = false;
				$lastX = $x;
				$lastY = $y;
				$lastZ = $z;
				while(($this->canReplace)($c, $x, $y, $z)){
					if($level->isOutsideBuildHeight($y)){
						return $placed;
					}
					if($placed && $this->chanceToContinue !== null && !($random->nextFloat() < $this->chanceToContinue)){
						break;
					}
					$state = ($this->block)($c, $random, $x, $y, $z);
					if($state === null){
						break;
					}
					$level->setState($x, $y, $z, $state);
					$placed = true;
					$lastX = $x;
					$lastY = $y;
					$lastZ = $z;
					$x += $dx;
					$y += $dy;
					$z += $dz;
				}
				if($placed && $this->cap !== null){
					$this->cap->place($c, $lastX, $lastY, $lastZ);
				}
				return $placed;
			}
		};
	}

	/**
	 * projected_random_patchy_square: a square of blocks around the origin, sparser towards the corners, each one
	 * dropped down through the projectable blocks (the base of basalt pillars).
	 *
	 * @param mixed[] $json
	 */
	public static function projectedRandomPatchySquare(FeatureRegistry $registry, array $json) : Feature{
		return new class(
			WorldgenData::int($json, "size"),
			WorldgenData::int($json, "max_projection_height"),
			Predicates::block($registry->blocks, $json["project_through"] ?? null),
			$registry->stateProvider($json["block"] ?? null)
		) implements Feature{
			/**
			 * @phpstan-param \Closure(FeatureContext, int, int, int) : bool $projectThrough
			 * @phpstan-param \Closure(FeatureContext, \pocketmine\world\generator\vanilla\feature\FeatureRandom, int, int, int) : ?int $block
			 */
			public function __construct(
				private int $size,
				private int $maxProjection,
				private \Closure $projectThrough,
				private \Closure $block
			){}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				$level = $c->level;
				$random = $c->random;
				$placed = false;
				for($dx = -$this->size; $dx <= $this->size; ++$dx){
					for($dz = -$this->size; $dz <= $this->size; ++$dz){
						//the further from the axes, the less likely
						if(!($random->nextBoundedInt(10) < 10 - abs($dx) * abs($dz))){
							continue;
						}
						$px = $x + $dx;
						$py = $y;
						$pz = $z + $dz;
						$drops = $this->maxProjection;
						while(($this->projectThrough)($c, $px, $py - 1, $pz)){
							--$py;
							if(--$drops <= 0){
								break;
							}
						}
						$state = ($this->block)($c, $random, $px, $py, $pz);
						if($state !== null){
							$level->setState($px, $py, $pz, $state);
							$placed = true;
						}
					}
				}
				return $placed;
			}
		};
	}

	/**
	 * stepped_column_cluster: basalt columns (BasaltColumnsFeature), random column positions around the origin whose
	 * height decreases with the distance, each column widened to a diamond of the column reach.
	 *
	 * @param mixed[] $json
	 */
	public static function steppedColumnCluster(FeatureRegistry $registry, array $json) : Feature{
		$blocks = $registry->blocks;
		return new class(
			$registry->stateProvider($json["block"] ?? null),
			Predicates::block($blocks, $json["can_replace"] ?? null),
			$blocks->blockSet($json["cannot_place_on"] ?? []),
			isset($json["continue_through"]) ? Predicates::block($blocks, $json["continue_through"]) : fn(FeatureContext $c, int $x, int $y, int $z) : bool => false,
			WorldgenData::int($json, "cluster_reach"),
			WorldgenData::int($json, "column_count"),
			Providers::int($json["column_reach"] ?? null),
			Providers::int($json["height"] ?? null)
		) implements Feature{
			/**
			 * @param true[] $cannotPlaceOn
			 * @phpstan-param \Closure(FeatureContext, \pocketmine\world\generator\vanilla\feature\FeatureRandom, int, int, int) : ?int $block
			 * @phpstan-param \Closure(FeatureContext, int, int, int) : bool $canReplace
			 * @phpstan-param array<int, true> $cannotPlaceOn
			 * @phpstan-param \Closure(FeatureContext, int, int, int) : bool $continueThrough
			 */
			public function __construct(
				private \Closure $block,
				private \Closure $canReplace,
				private array $cannotPlaceOn,
				private \Closure $continueThrough,
				private int $clusterReach,
				private int $columnCount,
				private IntProvider $columnReach,
				private IntProvider $height
			){}

			private function canPlaceAt(FeatureContext $c, int $x, int $y, int $z) : bool{
				if(!($this->canReplace)($c, $x, $y, $z)){
					return false;
				}
				$below = $c->level->getState($x, $y - 1, $z);
				return !$c->blocks->isAir($below) && !isset($this->cannotPlaceOn[$c->blocks->blockOf[$below]]);
			}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				if(!$this->canPlaceAt($c, $x, $y, $z)){
					return false;
				}
				$random = $c->random;
				$height = $this->height->sample($random);
				$range = min($height, $this->clusterReach);
				$width = 2 * $range + 1;
				$placed = false;
				//BlockPos.randomBetweenClosed(): lazily, so the column reach is sampled between positions
				for($i = 0; $i < $this->columnCount; ++$i){
					$px = $x - $range + $random->nextBoundedInt($width);
					$py = $y + $random->nextBoundedInt(1);
					$pz = $z - $range + $random->nextBoundedInt($width);
					$columnHeight = $height - (abs($px - $x) + abs($py - $y) + abs($pz - $z));
					if($columnHeight >= 0){
						$placed = $this->placeColumn($c, $px, $py, $pz, $columnHeight, $this->columnReach->sample($random)) || $placed;
					}
				}
				return $placed;
			}

			private function placeColumn(FeatureContext $c, int $ox, int $oy, int $oz, int $columnHeight, int $reach) : bool{
				$level = $c->level;
				$placed = false;
				for($dz = -$reach; $dz <= $reach; ++$dz){
					for($dx = -$reach; $dx <= $reach; ++$dx){
						$x = $ox + $dx;
						$z = $oz + $dz;
						$distance = abs($dx) + abs($dz);
						$y = ($this->canReplace)($c, $x, $oy, $z) ? $this->findSurface($c, $x, $oy, $z, $distance) : $this->findAir($c, $x, $oy, $z, $distance);
						if($y === null){
							continue;
						}
						for($h = $columnHeight - intdiv($distance, 2); $h >= 0; --$h){
							if(($this->canReplace)($c, $x, $y, $z)){
								$state = ($this->block)($c, $c->random, $x, $y, $z);
								if($state !== null){
									$level->setState($x, $y, $z, $state);
									$placed = true;
								}
								++$y;
							}elseif(($this->continueThrough)($c, $x, $y, $z)){
								++$y;
							}else{
								break;
							}
						}
					}
				}
				return $placed;
			}

			private function findSurface(FeatureContext $c, int $x, int $y, int $z, int $limit) : ?int{
				while($y > $c->level->minY + 1 && $limit > 0){
					--$limit;
					if($this->canPlaceAt($c, $x, $y, $z)){
						return $y;
					}
					--$y;
				}
				return null;
			}

			private function findAir(FeatureContext $c, int $x, int $y, int $z, int $limit) : ?int{
				$blocks = $c->blocks;
				while($y < $c->level->maxY - 1 && $limit > 0){
					--$limit;
					$state = $c->level->getState($x, $y, $z);
					if(isset($this->cannotPlaceOn[$blocks->blockOf[$state]])){
						return null;
					}
					if($blocks->isAir($state)){
						return $y;
					}
					++$y;
				}
				return null;
			}
		};
	}

	/**
	 * delta_feature: a diamond of the contents block (lava) on flat ground with a rim (magma blocks) offset from it.
	 *
	 * @param mixed[] $json
	 */
	public static function delta(FeatureRegistry $registry, array $json) : Feature{
		$blocks = $registry->blocks;
		$cannotReplace = [];
		foreach(self::DELTA_CANNOT_REPLACE as $name){
			if($blocks->hasBlock($name)){
				$cannotReplace[$blocks->blockIndex($name)] = true;
			}
		}
		return new class(
			$blocks->stateFromJson($json["contents"] ?? null),
			$blocks->stateFromJson($json["rim"] ?? null),
			Providers::int($json["size"] ?? null),
			Providers::int($json["rim_size"] ?? null),
			$cannotReplace
		) implements Feature{
			/**
			 * @param true[] $cannotReplace
			 * @phpstan-param array<int, true> $cannotReplace
			 */
			public function __construct(
				private int $contents,
				private int $rim,
				private IntProvider $size,
				private IntProvider $rimSize,
				private array $cannotReplace
			){}

			private function isClear(FeatureContext $c, int $x, int $y, int $z) : bool{
				$blocks = $c->blocks;
				$block = $blocks->blockOf[$c->level->getState($x, $y, $z)];
				if($block === $blocks->blockOf[$this->contents] || isset($this->cannotReplace[$block])){
					return false;
				}
				for($d = 0; $d < 6; ++$d){
					$air = $blocks->isAir($c->level->getState($x + Direction::STEP_X[$d], $y + Direction::STEP_Y[$d], $z + Direction::STEP_Z[$d]));
					if($air !== ($d === Direction::UP)){
						return false;
					}
				}
				return true;
			}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				$random = $c->random;
				$level = $c->level;
				$placed = false;
				$hasRim = $random->nextDouble() < 0.9;
				$rimX = $hasRim ? $this->rimSize->sample($random) : 0;
				$rimZ = $hasRim ? $this->rimSize->sample($random) : 0;
				$hasRimForSure = $hasRim && $rimX !== 0 && $rimZ !== 0;
				$radiusX = $this->size->sample($random);
				$radiusZ = $this->size->sample($random);
				$limit = max($radiusX, $radiusZ);
				foreach(NetherFeatures::withinManhattan($x, $y, $z, $radiusX, 0, $radiusZ) as [$px, $py, $pz]){
					if(abs($px - $x) + abs($py - $y) + abs($pz - $z) > $limit){
						break;
					}
					if($this->isClear($c, $px, $py, $pz)){
						if($hasRimForSure){
							$placed = true;
							$level->setState($px, $py, $pz, $this->rim);
						}
						if($this->isClear($c, $px + $rimX, $py, $pz + $rimZ)){
							$placed = true;
							$level->setState($px + $rimX, $py, $pz + $rimZ, $this->contents);
						}
					}
				}
				return $placed;
			}
		};
	}

	/**
	 * netherrack_replace_blobs (ReplaceBlobsFeature): replaces the target block in a diamond around the first target
	 * block found below the origin.
	 *
	 * @param mixed[] $json
	 */
	public static function replaceBlobs(FeatureRegistry $registry, array $json) : Feature{
		$blocks = $registry->blocks;
		return new class(
			$blocks->blockOf[$blocks->stateFromJson($json["target"] ?? null)],
			$blocks->stateFromJson($json["state"] ?? null),
			Providers::int($json["radius"] ?? null)
		) implements Feature{
			public function __construct(
				private int $target,
				private int $state,
				private IntProvider $radius
			){}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				$level = $c->level;
				$blockOf = $c->blocks->blockOf;
				$maxY = $level->maxY - 1;
				$y = $y < $level->minY + 1 ? $level->minY + 1 : ($y > $maxY ? $maxY : $y);
				$found = false;
				while($y > $level->minY + 1){
					if($blockOf[$level->getState($x, $y, $z)] === $this->target){
						$found = true;
						break;
					}
					--$y;
				}
				if(!$found){
					return false;
				}
				$random = $c->random;
				$radiusX = $this->radius->sample($random);
				$radiusY = $this->radius->sample($random);
				$radiusZ = $this->radius->sample($random);
				$limit = max($radiusX, $radiusY, $radiusZ);
				$placed = false;
				foreach(NetherFeatures::withinManhattan($x, $y, $z, $radiusX, $radiusY, $radiusZ) as [$px, $py, $pz]){
					if(abs($px - $x) + abs($py - $y) + abs($pz - $z) > $limit){
						break;
					}
					if($blockOf[$level->getState($px, $py, $pz)] === $this->target){
						$level->setState($px, $py, $pz, $this->state);
						$placed = true;
					}
				}
				return $placed;
			}
		};
	}

	/**
	 * huge_fungus: crimson and warped fungi (HugeFungusFeature), worldgen variant (not planted).
	 *
	 * @param mixed[] $json
	 */
	public static function hugeFungus(FeatureRegistry $registry, array $json) : Feature{
		$blocks = $registry->blocks;
		return new class(
			$blocks->blockOf[$blocks->stateFromJson($json["valid_base_block"] ?? null)],
			$blocks->stateFromJson($json["stem_state"] ?? null),
			$blocks->stateFromJson($json["hat_state"] ?? null),
			$blocks->stateFromJson($json["decor_state"] ?? null),
			Predicates::block($blocks, $json["replaceable_blocks"] ?? null),
			($json["planted"] ?? false) === true,
			$blocks->defaultOf("weeping_vines"),
			$blocks->defaultOf("weeping_vines_plant"),
			$blocks->blockIndex("nether_wart_block")
		) implements Feature{
			private float $f01;
			private float $f02;
			private float $f001;
			private float $f07;
			private float $f0005;
			private float $f098;
			private float $f0083;
			private float $f007;
			private float $f006;

			/**
			 * @phpstan-param \Closure(FeatureContext, int, int, int) : bool $replaceable
			 */
			public function __construct(
				private int $validBase,
				private int $stem,
				private int $hat,
				private int $decor,
				private \Closure $replaceable,
				private bool $planted,
				private int $weepingVines,
				private int $weepingVinesPlant,
				private int $netherWartBlock
			){
				$this->f01 = FloatMath::fround(0.1);
				$this->f02 = FloatMath::fround(0.2);
				$this->f001 = FloatMath::fround(0.01);
				$this->f07 = FloatMath::fround(0.7);
				$this->f0005 = FloatMath::fround(5.0E-4);
				$this->f098 = FloatMath::fround(0.98);
				$this->f0083 = FloatMath::fround(0.083);
				$this->f007 = FloatMath::fround(0.07);
				$this->f006 = FloatMath::fround(0.06);
			}

			private function isReplaceable(FeatureContext $c, int $x, int $y, int $z, bool $checkConfig) : bool{
				if($c->blocks->isTagged($c->level->getState($x, $y, $z), "replaceable")){
					return true;
				}
				return $checkConfig && ($this->replaceable)($c, $x, $y, $z);
			}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				$level = $c->level;
				$random = $c->random;
				if($c->blocks->blockOf[$level->getState($x, $y - 1, $z)] !== $this->validBase){
					return false;
				}
				$totalHeight = $random->mthNextInt(4, 13);
				if($random->nextBoundedInt(12) === 0){
					$totalHeight *= 2;
				}
				if(!$this->planted && $y + $totalHeight + 1 >= $c->genDepth){
					return false;
				}
				$isHuge = !$this->planted && $random->nextFloat() < $this->f006;
				$level->setState($x, $y, $z, $c->blocks->air);
				$this->placeStem($c, $x, $y, $z, $totalHeight, $isHuge);
				$this->placeHat($c, $x, $y, $z, $totalHeight, $isHuge);
				return true;
			}

			private function placeStem(FeatureContext $c, int $ox, int $oy, int $oz, int $totalHeight, bool $isHuge) : void{
				$radius = $isHuge ? 1 : 0;
				for($dx = -$radius; $dx <= $radius; ++$dx){
					for($dz = -$radius; $dz <= $radius; ++$dz){
						$corner = $isHuge && abs($dx) === $radius && abs($dz) === $radius;
						for($dy = 0; $dy < $totalHeight; ++$dy){
							$x = $ox + $dx;
							$y = $oy + $dy;
							$z = $oz + $dz;
							if(!$this->isReplaceable($c, $x, $y, $z, true)){
								continue;
							}
							if($corner){
								if($c->random->nextFloat() < $this->f01){
									$c->level->setState($x, $y, $z, $this->stem);
								}
							}else{
								$c->level->setState($x, $y, $z, $this->stem);
							}
						}
					}
				}
			}

			private function placeHat(FeatureContext $c, int $ox, int $oy, int $oz, int $totalHeight, bool $isHuge) : void{
				$random = $c->random;
				$placeVines = $c->blocks->blockOf[$this->hat] === $this->netherWartBlock;
				$hatHeight = min($random->nextBoundedInt(1 + intdiv($totalHeight, 3)) + 5, $totalHeight);
				$hatStart = $totalHeight - $hatHeight;
				for($dy = $hatStart; $dy <= $totalHeight; ++$dy){
					$radius = $dy < $totalHeight - $random->nextBoundedInt(3) ? 2 : 1;
					if($hatHeight > 8 && $dy < $hatStart + 4){
						$radius = 3;
					}
					if($isHuge){
						++$radius;
					}
					for($dx = -$radius; $dx <= $radius; ++$dx){
						for($dz = -$radius; $dz <= $radius; ++$dz){
							$edgeX = $dx === -$radius || $dx === $radius;
							$edgeZ = $dz === -$radius || $dz === $radius;
							$inside = !$edgeX && !$edgeZ && $dy !== $totalHeight;
							$corner = $edgeX && $edgeZ;
							$lowEdge = $dy < $hatStart + 3;
							$x = $ox + $dx;
							$y = $oy + $dy;
							$z = $oz + $dz;
							if(!$this->isReplaceable($c, $x, $y, $z, false)){
								continue;
							}
							if($lowEdge){
								if(!$inside){
									$this->placeHatDropBlock($c, $x, $y, $z, $placeVines);
								}
							}elseif($inside){
								$this->placeHatBlock($c, $x, $y, $z, $this->f01, $this->f02, $placeVines ? $this->f01 : 0.0);
							}elseif($corner){
								$this->placeHatBlock($c, $x, $y, $z, $this->f001, $this->f07, $placeVines ? $this->f0083 : 0.0);
							}else{
								$this->placeHatBlock($c, $x, $y, $z, $this->f0005, $this->f098, $placeVines ? $this->f007 : 0.0);
							}
						}
					}
				}
			}

			private function placeHatBlock(FeatureContext $c, int $x, int $y, int $z, float $decorChance, float $hatChance, float $vinesChance) : void{
				$random = $c->random;
				if($random->nextFloat() < $decorChance){
					$c->level->setState($x, $y, $z, $this->decor);
				}elseif($random->nextFloat() < $hatChance){
					$c->level->setState($x, $y, $z, $this->hat);
					if($random->nextFloat() < $vinesChance){
						$this->tryPlaceWeepingVines($c, $x, $y, $z);
					}
				}
			}

			private function placeHatDropBlock(FeatureContext $c, int $x, int $y, int $z, bool $placeVines) : void{
				$random = $c->random;
				if($c->blocks->blockOf[$c->level->getState($x, $y - 1, $z)] === $c->blocks->blockOf[$this->hat]){
					$c->level->setState($x, $y, $z, $this->hat);
				}elseif($random->nextFloat() < 0.15){
					$c->level->setState($x, $y, $z, $this->hat);
					if($placeVines && $random->nextBoundedInt(11) === 0){
						$this->tryPlaceWeepingVines($c, $x, $y, $z);
					}
				}
			}

			private function tryPlaceWeepingVines(FeatureContext $c, int $x, int $y, int $z) : void{
				--$y;
				$level = $c->level;
				if(!$level->isEmptyBlock($x, $y, $z)){
					return;
				}
				$random = $c->random;
				$length = $random->mthNextInt(1, 5);
				if($random->nextBoundedInt(7) === 0){
					$length *= 2;
				}
				//WeepingVinesFeature.placeWeepingVinesColumn()
				for($i = 0; $i <= $length; ++$i){
					if($level->isEmptyBlock($x, $y, $z)){
						if($i === $length || !$level->isEmptyBlock($x, $y - 1, $z)){
							$level->setState($x, $y, $z, $c->blocks->withInt($this->weepingVines, "age", $random->mthNextInt(23, 25)));
							break;
						}
						$level->setState($x, $y, $z, $this->weepingVinesPlant);
					}
					--$y;
				}
			}
		};
	}

	/**
	 * BlockPos.withinManhattan(): positions by increasing Manhattan distance from the origin (within the reach on
	 * each axis), x then y increasing within a distance, each z offset followed by its mirror.
	 *
	 * @return \Generator<int, array{int, int, int}>
	 */
	public static function withinManhattan(int $ox, int $oy, int $oz, int $reachX, int $reachY, int $reachZ) : \Generator{
		$maxDepth = $reachX + $reachY + $reachZ;
		for($depth = 0; $depth <= $maxDepth; ++$depth){
			$maxX = min($reachX, $depth);
			for($x = -$maxX; $x <= $maxX; ++$x){
				$maxY = min($reachY, $depth - abs($x));
				for($y = -$maxY; $y <= $maxY; ++$y){
					$z = $depth - abs($x) - abs($y);
					if($z <= $reachZ){
						yield [$ox + $x, $oy + $y, $oz + $z];
						if($z !== 0){
							yield [$ox + $x, $oy + $y, $oz - $z];
						}
					}
				}
			}
		}
	}
}
