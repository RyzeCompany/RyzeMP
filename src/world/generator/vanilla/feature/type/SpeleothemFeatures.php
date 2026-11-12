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
use pocketmine\world\generator\vanilla\feature\FeatureLevel;
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use pocketmine\world\generator\vanilla\feature\IntProvider;
use pocketmine\world\generator\vanilla\feature\Mth;
use pocketmine\world\generator\vanilla\feature\Providers;
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\WorldgenData;
use function abs;
use function floor;
use function intdiv;
use function log;
use function max;
use function min;
use function sqrt;
use const PHP_INT_MAX;

/**
 * Pointed dripstone and sulfur spikes: minecraft:speleothem, minecraft:speleothem_cluster and
 * minecraft:large_dripstone (SpeleothemUtils).
 */
final class SpeleothemFeatures{
	private const SPELEOTHEM = 0;
	private const CLUSTER = 1;
	private const LARGE = 2;

	private int $mode;
	private int $baseBlock;
	private int $baseBlockIndex;
	private int $pointedBlock;
	private int $pointedBlockIndex;
	/** @phpstan-var array<int, true> */
	private array $replaceable;
	//speleothem
	private float $chanceOfTallerGeneration = 0.2;
	private float $chanceOfDirectionalSpread = 0.7;
	private float $chanceOfSpreadRadius2 = 0.5;
	private float $chanceOfSpreadRadius3 = 0.5;
	//cluster / large
	private int $searchRange = 30;
	private ?IntProvider $height = null;
	private ?IntProvider $radius = null;
	private int $maxHeightDiff = 0;
	private int $heightDeviation = 0;
	private ?IntProvider $layerThickness = null;
	/** @phpstan-var \Closure(FeatureRandom) : float */
	private \Closure $density;
	/** @phpstan-var \Closure(FeatureRandom) : float */
	private \Closure $wetness;
	private float $chanceAtMaxDistance = 0.0;
	private int $maxDistanceFromEdge = 1;
	private int $maxDistanceFromCenter = 1;
	/** @phpstan-var \Closure(FeatureRandom) : float */
	private \Closure $heightScale;
	private float $maxRadiusToHeightRatio = 0.0;
	/** @phpstan-var \Closure(FeatureRandom) : float */
	private \Closure $stalactiteBluntness;
	/** @phpstan-var \Closure(FeatureRandom) : float */
	private \Closure $stalagmiteBluntness;
	/** @phpstan-var \Closure(FeatureRandom) : float */
	private \Closure $windSpeed;
	private int $minRadiusForWind = 0;
	private float $minBluntnessForWind = 0.0;
	/** @phpstan-var array<int, true> */
	private array $baseStone;

	/**
	 * @param mixed[] $json
	 */
	private function __construct(FeatureRegistry $registry, array $json, int $mode){
		$blocks = $registry->blocks;
		$this->mode = $mode;
		$zero = fn(FeatureRandom $r) : float => 0.0;
		$this->density = $this->wetness = $this->heightScale = $this->stalactiteBluntness = $this->stalagmiteBluntness = $this->windSpeed = $zero;
		$this->baseBlock = $mode === self::LARGE ? $blocks->defaultOf("dripstone_block") : $blocks->stateFromJson($json["base_block"] ?? null);
		$this->pointedBlock = $mode === self::LARGE ? $blocks->defaultOf("pointed_dripstone") : $blocks->stateFromJson($json["pointed_block"] ?? null);
		$this->baseBlockIndex = $blocks->blockOf[$this->baseBlock];
		$this->pointedBlockIndex = $blocks->blockOf[$this->pointedBlock];
		$this->replaceable = $blocks->blockSet($json["replaceable_blocks"] ?? null);
		$this->baseStone = $blocks->tag("minecraft:base_stone_overworld");
		switch($mode){
			case self::SPELEOTHEM:
				$this->chanceOfTallerGeneration = FloatMath::fround(WorldgenData::float($json, "chance_of_taller_generation", 0.20000000298023224));
				$this->chanceOfDirectionalSpread = FloatMath::fround(WorldgenData::float($json, "chance_of_directional_spread", 0.699999988079071));
				$this->chanceOfSpreadRadius2 = FloatMath::fround(WorldgenData::float($json, "chance_of_spread_radius2", 0.5));
				$this->chanceOfSpreadRadius3 = FloatMath::fround(WorldgenData::float($json, "chance_of_spread_radius3", 0.5));
				break;
			case self::CLUSTER:
				$this->searchRange = WorldgenData::int($json, "floor_to_ceiling_search_range");
				$this->height = Providers::int($json["height"] ?? null);
				$this->radius = Providers::int($json["radius"] ?? null);
				$this->maxHeightDiff = WorldgenData::int($json, "max_stalagmite_stalactite_height_diff");
				$this->heightDeviation = WorldgenData::int($json, "height_deviation");
				$this->layerThickness = Providers::int($json["speleothem_block_layer_thickness"] ?? null);
				$this->density = Providers::float($json["density"] ?? null);
				$this->wetness = Providers::float($json["wetness"] ?? null);
				$this->chanceAtMaxDistance = FloatMath::fround(WorldgenData::float($json, "chance_of_speleothem_at_max_distance_from_center"));
				$this->maxDistanceFromEdge = WorldgenData::int($json, "max_distance_from_edge_affecting_chance_of_speleothem");
				$this->maxDistanceFromCenter = WorldgenData::int($json, "max_distance_from_center_affecting_height_bias");
				break;
			case self::LARGE:
				$this->searchRange = WorldgenData::int($json, "floor_to_ceiling_search_range", 30);
				$this->radius = Providers::int($json["column_radius"] ?? null);
				$this->heightScale = Providers::float($json["height_scale"] ?? null);
				$this->maxRadiusToHeightRatio = FloatMath::fround(WorldgenData::float($json, "max_column_radius_to_cave_height_ratio"));
				$this->stalactiteBluntness = Providers::float($json["stalactite_bluntness"] ?? null);
				$this->stalagmiteBluntness = Providers::float($json["stalagmite_bluntness"] ?? null);
				$this->windSpeed = Providers::float($json["wind_speed"] ?? null);
				$this->minRadiusForWind = WorldgenData::int($json, "min_radius_for_wind");
				$this->minBluntnessForWind = FloatMath::fround(WorldgenData::float($json, "min_bluntness_for_wind"));
				break;
		}
	}

	/**
	 * @param mixed[] $json
	 */
	public static function create(FeatureRegistry $registry, array $json, string $type) : Feature{
		$self = new self($registry, $json, match($type){
			"speleothem" => self::SPELEOTHEM,
			"speleothem_cluster" => self::CLUSTER,
			default => self::LARGE
		});
		return new class($self) implements Feature{
			public function __construct(private SpeleothemFeatures $impl){}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				return $this->impl->place($c, $x, $y, $z);
			}
		};
	}

	public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
		return match($this->mode){
			self::SPELEOTHEM => $this->placeSpeleothem($c, $x, $y, $z),
			self::CLUSTER => $this->placeCluster($c, $x, $y, $z),
			default => $this->placeLarge($c, $x, $y, $z)
		};
	}

	// ---- SpeleothemUtils ----

	private function isEmptyOrWater(FeatureContext $c, int $state) : bool{
		return $c->blocks->isAir($state) || $c->blocks->blockOf[$state] === $c->blocks->waterBlock;
	}

	private function isEmptyOrWaterOrLava(FeatureContext $c, int $state) : bool{
		$block = $c->blocks->blockOf[$state];
		return $c->blocks->isAir($state) || $block === $c->blocks->waterBlock || $block === $c->blocks->lavaBlock;
	}

	private function isBase(FeatureContext $c, int $state) : bool{
		$block = $c->blocks->blockOf[$state];
		return $block === $this->baseBlockIndex || isset($this->replaceable[$block]);
	}

	private function placeBaseBlockIfPossible(FeatureContext $c, int $x, int $y, int $z) : bool{
		if(isset($this->replaceable[$c->blocks->blockOf[$c->level->getState($x, $y, $z)]])){
			$c->level->setState($x, $y, $z, $this->baseBlock);
			return true;
		}
		return false;
	}

	private function pointed(FeatureContext $c, int $direction, string $thickness) : int{
		$blocks = $c->blocks;
		return $blocks->with($blocks->with($this->pointedBlock, "vertical_direction", $direction === Direction::UP ? "up" : "down"), "thickness", $thickness);
	}

	private function growSpeleothem(FeatureContext $c, int $x, int $y, int $z, int $tip, int $height, bool $mergedTip) : void{
		$dy = Direction::STEP_Y[$tip];
		if(!$this->isBase($c, $c->level->getState($x, $y - $dy, $z))){
			return;
		}
		$column = [];
		if($height >= 3){
			$column[] = "base";
			for($i = 0; $i < $height - 3; ++$i){
				$column[] = "middle";
			}
		}
		if($height >= 2){
			$column[] = "frustum";
		}
		if($height >= 1){
			$column[] = $mergedTip ? "tip_merge" : "tip";
		}
		$blocks = $c->blocks;
		foreach($column as $thickness){
			$state = $this->pointed($c, $tip, $thickness);
			$state = $blocks->withBool($state, "waterlogged", $c->level->isWaterAt($x, $y, $z));
			$c->level->setState($x, $y, $z, $state);
			$y += $dy;
		}
	}

	// ---- speleothem ----

	private function placeSpeleothem(FeatureContext $c, int $x, int $y, int $z) : bool{
		$random = $c->random;
		$level = $c->level;
		$above = $this->isBase($c, $level->getState($x, $y + 1, $z));
		$below = $this->isBase($c, $level->getState($x, $y - 1, $z));
		if($above && $below){
			$tip = $random->nextBoolean() ? Direction::DOWN : Direction::UP;
		}elseif($above){
			$tip = Direction::DOWN;
		}elseif($below){
			$tip = Direction::UP;
		}else{
			return false;
		}
		$ry = $y - Direction::STEP_Y[$tip];
		$this->createPatchOfBaseBlocks($c, $x, $ry, $z);
		$height = $random->nextFloat() < $this->chanceOfTallerGeneration && $this->isEmptyOrWater($c, $level->getState($x, $y + Direction::STEP_Y[$tip], $z)) ? 2 : 1;
		$this->growSpeleothem($c, $x, $y, $z, $tip, $height, false);
		return true;
	}

	private function createPatchOfBaseBlocks(FeatureContext $c, int $x, int $y, int $z) : void{
		$random = $c->random;
		$this->placeBaseBlockIfPossible($c, $x, $y, $z);
		foreach(Direction::PLANE_HORIZONTAL as $d){
			if(!($random->nextFloat() <= $this->chanceOfDirectionalSpread)){
				continue;
			}
			$x1 = $x + Direction::STEP_X[$d];
			$z1 = $z + Direction::STEP_Z[$d];
			$this->placeBaseBlockIfPossible($c, $x1, $y, $z1);
			if(!($random->nextFloat() <= $this->chanceOfSpreadRadius2)){
				continue;
			}
			$d2 = $random->nextBoundedInt(6);
			$x2 = $x1 + Direction::STEP_X[$d2];
			$y2 = $y + Direction::STEP_Y[$d2];
			$z2 = $z1 + Direction::STEP_Z[$d2];
			$this->placeBaseBlockIfPossible($c, $x2, $y2, $z2);
			if(!($random->nextFloat() <= $this->chanceOfSpreadRadius3)){
				continue;
			}
			$d3 = $random->nextBoundedInt(6);
			$this->placeBaseBlockIfPossible($c, $x2 + Direction::STEP_X[$d3], $y2 + Direction::STEP_Y[$d3], $z2 + Direction::STEP_Z[$d3]);
		}
	}

	/**
	 * Column.scan(): [floor, ceiling] (null when not found), or null if the start is not inside.
	 * @phpstan-param \Closure(int) : bool $inside
	 * @phpstan-param \Closure(int) : bool $edge
	 * @phpstan-return array{?int, ?int}|null
	 */
	private function scanColumn(FeatureContext $c, int $x, int $y, int $z, \Closure $inside, \Closure $edge) : ?array{
		$level = $c->level;
		if(!$inside($level->getState($x, $y, $z))){
			return null;
		}
		$result = [];
		foreach([1, -1] as $dy){
			$py = $y;
			for($i = 1; $i < $this->searchRange && $inside($level->getState($x, $py, $z)); ++$i){
				$py += $dy;
			}
			$result[] = $edge($level->getState($x, $py, $z)) ? $py : null;
		}
		return [$result[1], $result[0]];
	}

	// ---- speleothem_cluster ----

	private function placeCluster(FeatureContext $c, int $x, int $y, int $z) : bool{
		$random = $c->random;
		if(!$this->isEmptyOrWater($c, $c->level->getState($x, $y, $z))){
			return false;
		}
		$height = $this->height?->sample($random) ?? 0;
		$wetness = ($this->wetness)($random);
		$density = ($this->density)($random);
		$xRadius = $this->radius?->sample($random) ?? 0;
		$zRadius = $this->radius?->sample($random) ?? 0;
		for($dx = -$xRadius; $dx <= $xRadius; ++$dx){
			for($dz = -$zRadius; $dz <= $zRadius; ++$dz){
				$chance = $this->chanceOfStalagmiteOrStalactite($xRadius, $zRadius, $dx, $dz);
				$this->placeColumn($c, $x + $dx, $y, $z + $dz, $dx, $dz, $wetness, $chance, $height, $density);
			}
		}
		return true;
	}

	private function chanceOfStalagmiteOrStalactite(int $xRadius, int $zRadius, int $dx, int $dz) : float{
		$distanceFromEdge = min($xRadius - abs($dx), $zRadius - abs($dz));
		//Mth.clampedMap(float...) in float32
		$t = FloatMath::fround(FloatMath::fround($distanceFromEdge - 0.0) / FloatMath::fround($this->maxDistanceFromEdge - 0.0));
		if($t < 0.0){
			return $this->chanceAtMaxDistance;
		}
		if($t > 1.0){
			return 1.0;
		}
		return Mth::lerpF($t, $this->chanceAtMaxDistance, 1.0);
	}

	private function placeColumn(FeatureContext $c, int $x, int $y, int $z, int $dx, int $dz, float $wetness, float $chance, int $clusterHeight, float $density) : void{
		$level = $c->level;
		$random = $c->random;
		$blocks = $c->blocks;
		$column = $this->scanColumn($c, $x, $y, $z,
			fn(int $s) : bool => $this->isEmptyOrWater($c, $s),
			fn(int $s) : bool => !$this->isEmptyOrWater($c, $s)
		);
		if($column === null){
			return;
		}
		[$baseFloor, $ceiling] = $column;
		if($ceiling === null && $baseFloor === null){
			return;
		}
		$wantPool = $random->nextFloat() < $wetness;
		$floor = $baseFloor;
		if($wantPool && $baseFloor !== null && $this->canPlacePool($c, $x, $baseFloor, $z)){
			$floor = $baseFloor - 1;
			$level->setState($x, $baseFloor, $z, $blocks->water);
		}
		$wantStalactite = $random->nextDouble() < $chance;
		$stalactiteHeight = 0;
		if($ceiling !== null && $wantStalactite && !$this->isLava($c, $x, $ceiling, $z)){
			$thickness = $this->layerThickness?->sample($random) ?? 0;
			$this->replaceBlocksWithBaseBlocks($c, $x, $ceiling, $z, $thickness, 1);
			$maxHeight = $floor !== null ? min($clusterHeight, $ceiling - $floor) : $clusterHeight;
			$stalactiteHeight = $this->speleothemHeight($random, $dx, $dz, $density, $maxHeight);
		}
		$wantStalagmite = $random->nextDouble() < $chance;
		$stalagmiteHeight = 0;
		if($floor !== null && $wantStalagmite && !$this->isLava($c, $x, $floor, $z)){
			$thickness = $this->layerThickness?->sample($random) ?? 0;
			$this->replaceBlocksWithBaseBlocks($c, $x, $floor, $z, $thickness, -1);
			if($ceiling !== null){
				$stalagmiteHeight = max(0, $stalactiteHeight + $random->nextIntBetweenInclusive(-$this->maxHeightDiff, $this->maxHeightDiff));
			}else{
				$stalagmiteHeight = $this->speleothemHeight($random, $dx, $dz, $density, $clusterHeight);
			}
		}
		if($ceiling !== null && $floor !== null && $ceiling - $stalactiteHeight <= $floor + $stalagmiteHeight){
			$lowestStalactiteBottom = max($ceiling - $stalactiteHeight, $floor + 1);
			$highestStalagmiteTop = min($floor + $stalagmiteHeight, $ceiling - 1);
			$actualStalactiteBottom = $random->nextIntBetweenInclusive($lowestStalactiteBottom, $highestStalagmiteTop + 1);
			$actualStalagmiteTop = $actualStalactiteBottom - 1;
			$actualStalactiteHeight = $ceiling - $actualStalactiteBottom;
			$actualStalagmiteHeight = $actualStalagmiteTop - $floor;
		}else{
			$actualStalactiteHeight = $stalactiteHeight;
			$actualStalagmiteHeight = $stalagmiteHeight;
		}
		//column height after the pool: Column.withFloor()
		$columnHeight = $ceiling !== null && $floor !== null ? $ceiling - $floor - 1 : null;
		$mergeTips = $random->nextBoolean() && $actualStalactiteHeight > 0 && $actualStalagmiteHeight > 0 && $columnHeight !== null && $actualStalactiteHeight + $actualStalagmiteHeight === $columnHeight;
		if($ceiling !== null){
			$this->growSpeleothem($c, $x, $ceiling - 1, $z, Direction::DOWN, $actualStalactiteHeight, $mergeTips);
		}
		if($floor !== null){
			$this->growSpeleothem($c, $x, $floor + 1, $z, Direction::UP, $actualStalagmiteHeight, $mergeTips);
		}
	}

	private function isLava(FeatureContext $c, int $x, int $y, int $z) : bool{
		return $c->blocks->blockOf[$c->level->getState($x, $y, $z)] === $c->blocks->lavaBlock;
	}

	private function speleothemHeight(FeatureRandom $random, int $dx, int $dz, float $density, int $maxHeight) : int{
		if(!($random->nextFloat() <= $density)){
			return 0;
		}
		$distance = abs($dx) + abs($dz);
		//Mth.clampedMap(double): distance in [0, maxDistanceFromCenter] -> [maxHeight / 2, 0]
		$t = $distance / $this->maxDistanceFromCenter;
		$mean = $t < 0.0 ? $maxHeight / 2.0 : ($t > 1.0 ? 0.0 : $maxHeight / 2.0 + $t * (0.0 - $maxHeight / 2.0));
		$mean = FloatMath::fround($mean);
		//ClampedNormalFloat.sample(random, mean, deviation, 0, maxHeight)
		$v = FloatMath::fround($mean + FloatMath::fround(FloatMath::fround($random->nextGaussian()) * $this->heightDeviation));
		$v = $v < 0.0 ? 0.0 : ($v > $maxHeight ? (float) $maxHeight : $v);
		return (int) $v;
	}

	private function canPlacePool(FeatureContext $c, int $x, int $y, int $z) : bool{
		$level = $c->level;
		$blocks = $c->blocks;
		$block = $blocks->blockOf[$level->getState($x, $y, $z)];
		if($block === $blocks->waterBlock || $block === $this->baseBlockIndex || $block === $this->pointedBlockIndex){
			return false;
		}
		if($blocks->isWater($level->getState($x, $y + 1, $z))){
			return false;
		}
		foreach(Direction::PLANE_HORIZONTAL as $d){
			if(!$this->canBeAdjacentToWater($c, $x + Direction::STEP_X[$d], $y, $z + Direction::STEP_Z[$d])){
				return false;
			}
		}
		return $this->canBeAdjacentToWater($c, $x, $y - 1, $z);
	}

	private function canBeAdjacentToWater(FeatureContext $c, int $x, int $y, int $z) : bool{
		$state = $c->level->getState($x, $y, $z);
		return isset($this->baseStone[$c->blocks->blockOf[$state]]) || $c->blocks->isWater($state);
	}

	private function replaceBlocksWithBaseBlocks(FeatureContext $c, int $x, int $y, int $z, int $count, int $dy) : void{
		for($i = 0; $i < $count; ++$i){
			if(!$this->placeBaseBlockIfPossible($c, $x, $y, $z)){
				return;
			}
			$y += $dy;
		}
	}

	// ---- large_dripstone ----

	private static function speleothemShapeHeight(float $xzDistanceFromCenter, float $radius, float $scale, float $bluntness) : float{
		if($xzDistanceFromCenter < $bluntness){
			$xzDistanceFromCenter = $bluntness;
		}
		$r = $xzDistanceFromCenter / $radius * 0.384;
		$part1 = 0.75 * ($r ** 1.3333333333333333);
		$part2 = $r ** 0.6666666666666666;
		$part3 = 0.3333333333333333 * log($r);
		$height = max($scale * ($part1 - $part2 - $part3), 0.0);
		return $height / 0.384 * $radius;
	}

	private function placeLarge(FeatureContext $c, int $x, int $y, int $z) : bool{
		$random = $c->random;
		$level = $c->level;
		if(!$this->isEmptyOrWater($c, $level->getState($x, $y, $z))){
			return false;
		}
		$column = $this->scanColumn($c, $x, $y, $z,
			fn(int $s) : bool => $this->isEmptyOrWater($c, $s),
			fn(int $s) : bool => $this->isBase($c, $s) || $c->blocks->blockOf[$s] === $c->blocks->lavaBlock
		);
		if($column === null || $column[0] === null || $column[1] === null){
			return false;
		}
		[$floor, $ceiling] = $column;
		$columnHeight = $ceiling - $floor - 1;
		if($columnHeight < 4){
			return false;
		}
		$radiusProvider = $this->radius ?? IntProvider::constant(1);
		$maxByHeight = (int) FloatMath::fround($columnHeight * $this->maxRadiusToHeightRatio);
		$maxRadius = max($radiusProvider->min, min($radiusProvider->max, $maxByHeight));
		$radius = $random->nextIntBetweenInclusive($radiusProvider->min, $maxRadius);
		$stalactite = [$x, $ceiling - 1, $z, false, $radius, ($this->stalactiteBluntness)($random), ($this->heightScale)($random)];
		$stalagmite = [$x, $floor + 1, $z, true, $radius, ($this->stalagmiteBluntness)($random), ($this->heightScale)($random)];
		$wind = null;
		if($stalactite[4] >= $this->minRadiusForWind && $stalactite[5] >= $this->minBluntnessForWind && $stalagmite[4] >= $this->minRadiusForWind && $stalagmite[5] >= $this->minBluntnessForWind){
			$speed = ($this->windSpeed)($random);
			$direction = FloatMath::fround(FloatMath::fround($random->nextFloat() * 3.1415927410125732) + 0.0);
			$wind = [$y, FloatMath::fround(Mth::cos($direction) * $speed), FloatMath::fround(Mth::sin($direction) * $speed), 16 - $radius];
		}
		$stalactiteOk = $this->moveBackUntilEmbedded($c, $stalactite, $wind);
		$stalagmiteOk = $this->moveBackUntilEmbedded($c, $stalagmite, $wind);
		if($stalactiteOk){
			$this->placeLargeBlocks($c, $stalactite, $wind);
		}
		if($stalagmiteOk){
			$this->placeLargeBlocks($c, $stalagmite, $wind);
		}
		return true;
	}

	/**
	 * @param float[]|null $wind [originY, speedX, speedZ, maxOffset]
	 * @phpstan-param array{int, float, float, int}|null $wind
	 * @phpstan-return array{int, int}
	 */
	private static function windOffset(?array $wind, int $y) : array{
		if($wind === null){
			return [0, 0];
		}
		$dy = $wind[0] - $y;
		$dx = (int) floor($wind[1] * $dy);
		$dz = (int) floor($wind[2] * $dy);
		return [max(-$wind[3], min($wind[3], $dx)), max(-$wind[3], min($wind[3], $dz))];
	}

	/**
	 * @param mixed[] $d [x, y, z, pointingUp, radius, bluntness, scale]
	 * @phpstan-param array{int, int, int, bool, int, float, float} $d
	 */
	private static function heightAtRadius(array $d, float $checkRadius) : int{
		return (int) self::speleothemShapeHeight($checkRadius, $d[4], $d[6], $d[5]);
	}

	/**
	 * @param mixed[] $d
	 * @phpstan-param array{int, int, int, bool, int, float, float} $d
	 * @phpstan-param array{int, float, float, int}|null $wind
	 */
	private function moveBackUntilEmbedded(FeatureContext $c, array &$d, ?array $wind) : bool{
		while($d[4] > 1){
			[$x, $y, $z] = $d;
			$maxTries = min(10, self::heightAtRadius($d, 0.0));
			for($i = 0; $i < $maxTries; ++$i){
				if($c->blocks->blockOf[$c->level->getState($x, $y, $z)] === $c->blocks->lavaBlock){
					return false;
				}
				[$wx, $wz] = self::windOffset($wind, $y);
				if($this->isCircleMostlyEmbeddedInStone($c, $x + $wx, $y, $z + $wz, $d[4])){
					$d[1] = $y;
					return true;
				}
				$y += $d[3] ? -1 : 1;
			}
			$d[4] = intdiv($d[4], 2);
		}
		return false;
	}

	private function isCircleMostlyEmbeddedInStone(FeatureContext $c, int $x, int $y, int $z, int $radius) : bool{
		if($this->isEmptyOrWaterOrLava($c, $c->level->getState($x, $y, $z))){
			return false;
		}
		$increment = FloatMath::fround(6.0 / $radius);
		for($angle = 0.0; $angle < 6.2831854820251465; $angle = FloatMath::fround($angle + $increment)){
			$dx = (int) FloatMath::fround(Mth::cos($angle) * $radius);
			$dz = (int) FloatMath::fround(Mth::sin($angle) * $radius);
			if($this->isEmptyOrWaterOrLava($c, $c->level->getState($x + $dx, $y, $z + $dz))){
				return false;
			}
		}
		return true;
	}

	/**
	 * @param mixed[] $d
	 * @phpstan-param array{int, int, int, bool, int, float, float} $d
	 * @phpstan-param array{int, float, float, int}|null $wind
	 */
	private function placeLargeBlocks(FeatureContext $c, array $d, ?array $wind) : void{
		$random = $c->random;
		$level = $c->level;
		[$rx, $ry, $rz, $up, $radius] = $d;
		$dripstone = $this->baseBlock;
		for($dx = -$radius; $dx <= $radius; ++$dx){
			for($dz = -$radius; $dz <= $radius; ++$dz){
				$current = FloatMath::fround(sqrt((float) ($dx * $dx + $dz * $dz)));
				if($current > $radius){
					continue;
				}
				$height = self::heightAtRadius($d, $current);
				if($height <= 0){
					continue;
				}
				if($random->nextFloat() < 0.2){
					$height = (int) FloatMath::fround($height * FloatMath::fround(FloatMath::fround($random->nextFloat() * FloatMath::fround(1.0 - 0.800000011920929)) + 0.800000011920929));
				}
				$px = $rx + $dx;
				$py = $ry;
				$pz = $rz + $dz;
				$outOfStone = false;
				$maxY = $up ? $level->getHeight(FeatureLevel::WORLD_SURFACE_WG, $px, $pz) : PHP_INT_MAX;
				for($i = 0; $i < $height; ++$i){
					if($py >= $maxY){
						break;
					}
					[$wx, $wz] = self::windOffset($wind, $py);
					$state = $level->getState($px + $wx, $py, $pz + $wz);
					if($this->isEmptyOrWaterOrLava($c, $state)){
						$outOfStone = true;
						$level->setState($px + $wx, $py, $pz + $wz, $dripstone);
					}elseif($outOfStone && isset($this->baseStone[$c->blocks->blockOf[$state]])){
						break;
					}
					$py += $up ? 1 : -1;
				}
			}
		}
	}
}
