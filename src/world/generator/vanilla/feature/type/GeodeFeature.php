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
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use pocketmine\world\generator\vanilla\feature\FloatNoise;
use pocketmine\world\generator\vanilla\feature\IntProvider;
use pocketmine\world\generator\vanilla\feature\Providers;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\WorldgenData;
use function count;
use function is_array;
use function sqrt;

/**
 * minecraft:geode (amethyst geodes).
 */
final class GeodeFeature implements Feature{
	/** @phpstan-var \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int */
	private \Closure $filling;
	/** @phpstan-var \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int */
	private \Closure $innerLayer;
	/** @phpstan-var \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int */
	private \Closure $alternateInnerLayer;
	/** @phpstan-var \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int */
	private \Closure $middleLayer;
	/** @phpstan-var \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int */
	private \Closure $outerLayer;
	/** @var int[] */
	private array $innerPlacements = [];
	/** @phpstan-var array<int, true> */
	private array $cannotReplace;
	/** @phpstan-var array<int, true> */
	private array $invalidBlocks;
	private float $fillingLayer;
	private float $innerLayerSize;
	private float $middleLayerSize;
	private float $outerLayerSize;
	private float $generateCrackChance;
	private float $baseCrackSize;
	private int $crackPointOffset;
	private float $usePotentialPlacementsChance;
	private float $useAlternateLayer0Chance;
	private bool $placementsRequireLayer0Alternate;
	private IntProvider $outerWallDistance;
	private IntProvider $distributionPoints;
	private IntProvider $pointOffset;
	private int $minGenOffset;
	private int $maxGenOffset;
	private float $noiseMultiplier;
	private int $invalidBlocksThreshold;
	private ?FloatNoise $noise = null;
	private int $noiseSeed = 0;

	/**
	 * @param mixed[] $json
	 */
	public function __construct(FeatureRegistry $registry, array $json){
		$blocks = $registry->blocks;
		$b = WorldgenData::array($json, "blocks");
		$this->filling = $registry->stateProvider($b["filling_provider"] ?? null);
		$this->innerLayer = $registry->stateProvider($b["inner_layer_provider"] ?? null);
		$this->alternateInnerLayer = $registry->stateProvider($b["alternate_inner_layer_provider"] ?? null);
		$this->middleLayer = $registry->stateProvider($b["middle_layer_provider"] ?? null);
		$this->outerLayer = $registry->stateProvider($b["outer_layer_provider"] ?? null);
		foreach(WorldgenData::array($b, "inner_placements") as $state){
			$this->innerPlacements[] = $blocks->stateFromJson($state);
		}
		$this->cannotReplace = $blocks->blockSet($b["cannot_replace"] ?? null);
		$this->invalidBlocks = $blocks->blockSet($b["invalid_blocks"] ?? null);
		$layers = is_array($json["layers"] ?? null) ? $json["layers"] : [];
		$this->fillingLayer = WorldgenData::float($layers, "filling", 1.7);
		$this->innerLayerSize = WorldgenData::float($layers, "inner_layer", 2.2);
		$this->middleLayerSize = WorldgenData::float($layers, "middle_layer", 3.2);
		$this->outerLayerSize = WorldgenData::float($layers, "outer_layer", 4.2);
		$crack = is_array($json["crack"] ?? null) ? $json["crack"] : [];
		$this->generateCrackChance = WorldgenData::float($crack, "generate_crack_chance", 1.0);
		$this->baseCrackSize = WorldgenData::float($crack, "base_crack_size", 2.0);
		$this->crackPointOffset = WorldgenData::int($crack, "crack_point_offset", 2);
		$this->usePotentialPlacementsChance = WorldgenData::float($json, "use_potential_placements_chance", 0.35);
		$this->useAlternateLayer0Chance = WorldgenData::float($json, "use_alternate_layer0_chance", 0.0);
		$this->placementsRequireLayer0Alternate = ($json["placements_require_layer0_alternate"] ?? true) === true;
		$this->outerWallDistance = isset($json["outer_wall_distance"]) ? Providers::int($json["outer_wall_distance"]) : Providers::int(["type" => "uniform", "min_inclusive" => 4, "max_inclusive" => 5]);
		$this->distributionPoints = isset($json["distribution_points"]) ? Providers::int($json["distribution_points"]) : Providers::int(["type" => "uniform", "min_inclusive" => 3, "max_inclusive" => 4]);
		$this->pointOffset = isset($json["point_offset"]) ? Providers::int($json["point_offset"]) : Providers::int(["type" => "uniform", "min_inclusive" => 1, "max_inclusive" => 2]);
		$this->minGenOffset = WorldgenData::int($json, "min_gen_offset", -16);
		$this->maxGenOffset = WorldgenData::int($json, "max_gen_offset", 16);
		$this->noiseMultiplier = WorldgenData::float($json, "noise_multiplier", 0.05);
		$this->invalidBlocksThreshold = WorldgenData::int($json, "invalid_blocks_threshold");
	}

	private function safeSetBlock(FeatureContext $c, int $x, int $y, int $z, ?int $state) : void{
		if($state === null){
			return;
		}
		if(isset($this->cannotReplace[$c->blocks->blockOf[$c->level->getState($x, $y, $z)]])){
			return;
		}
		$c->level->setState($x, $y, $z, $state);
	}

	public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
		$random = $c->random;
		$level = $c->level;
		$blocks = $c->blocks;
		$numPoints = $this->distributionPoints->sample($random);
		if($this->noise === null || $this->noiseSeed !== $c->seed){
			$this->noise = new FloatNoise(["base_octave" => -4, "amplitude_modifiers" => [1.0], "normalize" => "legacy"], new LegacyRandom($c->seed));
			$this->noiseSeed = $c->seed;
		}
		$noise = $this->noise;
		$crackSizeAdjustment = $numPoints / $this->outerWallDistance->max;
		$innerAir = 1.0 / sqrt($this->fillingLayer);
		$innermostBlockLayer = 1.0 / sqrt($this->innerLayerSize + $crackSizeAdjustment);
		$innerCrust = 1.0 / sqrt($this->middleLayerSize + $crackSizeAdjustment);
		$outerCrust = 1.0 / sqrt($this->outerLayerSize + $crackSizeAdjustment);
		$crackSize = 1.0 / sqrt($this->baseCrackSize + $random->nextDouble() / 2.0 + ($numPoints > 3 ? $crackSizeAdjustment : 0.0));
		$shouldGenerateCrack = $random->nextFloat() < $this->generateCrackChance;
		$invalid = 0;
		$points = [];
		for($i = 0; $i < $numPoints; ++$i){
			$px = $x + $this->outerWallDistance->sample($random);
			$py = $y + $this->outerWallDistance->sample($random);
			$pz = $z + $this->outerWallDistance->sample($random);
			$state = $level->getState($px, $py, $pz);
			if($blocks->isAir($state) || isset($this->invalidBlocks[$blocks->blockOf[$state]])){
				if(++$invalid > $this->invalidBlocksThreshold){
					return false;
				}
			}
			$points[] = [$px, $py, $pz, $this->pointOffset->sample($random)];
		}
		$crackPoints = [];
		if($shouldGenerateCrack){
			$offsetIndex = $random->nextBoundedInt(4);
			$crackOffset = $numPoints * 2 + 1;
			[$cx, $cz] = match($offsetIndex){
				0 => [$crackOffset, 0],
				1 => [0, $crackOffset],
				2 => [$crackOffset, $crackOffset],
				default => [0, 0]
			};
			foreach([7, 5, 1] as $cy){
				$crackPoints[] = [$x + $cx, $y + $cy, $z + $cz];
			}
		}
		$potentialCrystals = [];
		for($pz = $z + $this->minGenOffset; $pz <= $z + $this->maxGenOffset; ++$pz){
			for($py = $y + $this->minGenOffset; $py <= $y + $this->maxGenOffset; ++$py){
				for($px = $x + $this->minGenOffset; $px <= $x + $this->maxGenOffset; ++$px){
					//the noise only shifts each term by +-noiseMultiplier * |noise| (|noise| < 2): skip the (slow) noise far
					//outside the shell; nothing is placed and no random is drawn there
					$base = 0.0;
					foreach($points as [$qx, $qy, $qz, $offset]){
						$dx = $px - $qx;
						$dy = $py - $qy;
						$dz = $pz - $qz;
						$base += 1.0 / sqrt((float) ($dx * $dx + $dy * $dy + $dz * $dz) + $offset);
					}
					if($base + $numPoints * $this->noiseMultiplier * 2.0 < $outerCrust){
						continue;
					}
					$noiseOffset = $noise->get($px, $py, $pz) * $this->noiseMultiplier;
					$distSumShell = 0.0;
					foreach($points as [$qx, $qy, $qz, $offset]){
						$dx = $px - $qx;
						$dy = $py - $qy;
						$dz = $pz - $qz;
						$distSumShell += 1.0 / sqrt((float) ($dx * $dx + $dy * $dy + $dz * $dz) + $offset) + $noiseOffset;
					}
					if($distSumShell < $outerCrust){
						continue;
					}
					if($distSumShell >= $innerAir){
						$this->safeSetBlock($c, $px, $py, $pz, ($this->filling)($c, $random, $px, $py, $pz));
						continue;
					}
					$distSumCrack = 0.0;
					foreach($crackPoints as [$qx, $qy, $qz]){
						$dx = $px - $qx;
						$dy = $py - $qy;
						$dz = $pz - $qz;
						$distSumCrack += 1.0 / sqrt((float) ($dx * $dx + $dy * $dy + $dz * $dz) + $this->crackPointOffset) + $noiseOffset;
					}
					if($shouldGenerateCrack && $distSumCrack >= $crackSize){
						$this->safeSetBlock($c, $px, $py, $pz, $blocks->air);
					}elseif($distSumShell >= $innermostBlockLayer){
						$useAlternate = $random->nextFloat() < $this->useAlternateLayer0Chance;
						if($useAlternate){
							$this->safeSetBlock($c, $px, $py, $pz, ($this->alternateInnerLayer)($c, $random, $px, $py, $pz));
						}else{
							$this->safeSetBlock($c, $px, $py, $pz, ($this->innerLayer)($c, $random, $px, $py, $pz));
						}
						if((!$this->placementsRequireLayer0Alternate || $useAlternate) && $random->nextFloat() < $this->usePotentialPlacementsChance){
							$potentialCrystals[] = [$px, $py, $pz];
						}
					}elseif($distSumShell >= $innerCrust){
						$this->safeSetBlock($c, $px, $py, $pz, ($this->middleLayer)($c, $random, $px, $py, $pz));
					}elseif($distSumShell >= $outerCrust){
						$this->safeSetBlock($c, $px, $py, $pz, ($this->outerLayer)($c, $random, $px, $py, $pz));
					}
				}
			}
		}
		$count = count($this->innerPlacements);
		foreach($potentialCrystals as [$px, $py, $pz]){
			$state = $this->innerPlacements[$random->nextBoundedInt($count)];
			foreach([0, 1, 2, 3, 4, 5] as $d){
				if($blocks->hasProperty($state, "facing")){
					$state = $blocks->with($state, "facing", Direction::NAMES[$d]);
				}
				$tx = $px + Direction::STEP_X[$d];
				$ty = $py + Direction::STEP_Y[$d];
				$tz = $pz + Direction::STEP_Z[$d];
				$target = $level->getState($tx, $ty, $tz);
				if($blocks->hasProperty($state, "waterlogged")){
					$state = $blocks->withBool($state, "waterlogged", $blocks->fluidIsSource($target));
				}
				if($blocks->isAir($target) || ($blocks->blockOf[$target] === $blocks->waterBlock && $blocks->fluidIsSource($target))){
					$this->safeSetBlock($c, $tx, $ty, $tz, $state);
					break;
				}
			}
		}
		return true;
	}
}
