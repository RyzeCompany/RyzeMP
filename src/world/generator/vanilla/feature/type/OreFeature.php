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

use pocketmine\world\generator\vanilla\feature\Feature;
use pocketmine\world\generator\vanilla\feature\FeatureContext;
use pocketmine\world\generator\vanilla\feature\FeatureLevel;
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use pocketmine\world\generator\vanilla\feature\Mth;
use pocketmine\world\generator\vanilla\feature\Predicates;
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\random\RandomSource;
use pocketmine\world\generator\vanilla\WorldgenData;
use function ceil;
use function cos;
use function floor;
use function is_array;
use function max;
use function sin;

/**
 * minecraft:ore (OreFeature) and the shared target logic of minecraft:scattered_ore (AbstractOreFeature).
 */
final class OreFeature implements Feature{
	/**
	 * @var array[] [rule test, Java state]
	 * @phpstan-var list<array{\Closure(int, FeatureRandom|RandomSource, int) : bool, int}>
	 */
	private array $targets = [];

	/**
	 * @param mixed[] $json
	 */
	public function __construct(FeatureRegistry $registry, array $json, private readonly bool $scattered = false){
		$this->size = WorldgenData::int($json, "size");
		$this->discardChance = FloatMath::fround(WorldgenData::float($json, "discard_chance_on_air_exposure", 0.0));
		foreach(WorldgenData::array($json, "targets") as $target){
			if(!is_array($target)){
				throw new \RuntimeException("Invalid ore target");
			}
			$this->targets[] = [Predicates::rule($registry->blocks, $target["target"] ?? null), $registry->blocks->stateFromJson($target["state"] ?? null)];
		}
	}

	private int $size;
	private float $discardChance;

	public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
		return $this->scattered ? $this->placeScattered($c, $x, $y, $z) : $this->placeOre($c, $x, $y, $z);
	}

	private function placeOre(FeatureContext $c, int $ox, int $oy, int $oz) : bool{
		$random = $c->random;
		$size = $this->size;
		$dir = FloatMath::fround($random->nextFloat() * Mth::PI_F);
		$spreadXY = FloatMath::fround($size / 8.0);
		$maxRadius = (int) ceil(FloatMath::fround(FloatMath::fround(FloatMath::fround(FloatMath::fround($size / 16.0) * 2.0) + 1.0) / 2.0));
		$x0 = $ox + sin($dir) * $spreadXY;
		$x1 = $ox - sin($dir) * $spreadXY;
		$z0 = $oz + cos($dir) * $spreadXY;
		$z1 = $oz - cos($dir) * $spreadXY;
		$y0 = (float) ($oy + $random->nextBoundedInt(3) - 2);
		$y1 = (float) ($oy + $random->nextBoundedInt(3) - 2);
		$ceilSpread = (int) ceil($spreadXY);
		$xStart = $ox - $ceilSpread - $maxRadius;
		$yStart = $oy - 2 - $maxRadius;
		$zStart = $oz - $ceilSpread - $maxRadius;
		$sizeXZ = 2 * ($ceilSpread + $maxRadius);
		$sizeY = 2 * (2 + $maxRadius);
		for($px = $xStart; $px <= $xStart + $sizeXZ; ++$px){
			for($pz = $zStart; $pz <= $zStart + $sizeXZ; ++$pz){
				if($yStart <= $c->level->getHeight(FeatureLevel::OCEAN_FLOOR_WG, $px, $pz)){
					return $this->doPlace($c, $x0, $x1, $z0, $z1, $y0, $y1, $xStart, $yStart, $zStart, $sizeXZ, $sizeY);
				}
			}
		}
		return false;
	}

	private function doPlace(FeatureContext $c, float $x0, float $x1, float $z0, float $z1, float $y0, float $y1, int $xStart, int $yStart, int $zStart, int $sizeXZ, int $sizeY) : bool{
		$random = $c->random;
		$level = $c->level;
		$size = $this->size;
		$placed = 0;
		$tested = [];
		$data = [];
		for($i = 0; $i < $size; ++$i){
			$step = FloatMath::fround($i / $size);
			$xx = $x0 + $step * ($x1 - $x0);
			$yy = $y0 + $step * ($y1 - $y0);
			$zz = $z0 + $step * ($z1 - $z0);
			$ss = $random->nextDouble() * $size / 16.0;
			$r = ((FloatMath::fround(Mth::sin(FloatMath::fround(Mth::PI_F * $step)) + 1.0)) * $ss + 1.0) / 2.0;
			$data[$i * 4] = $xx;
			$data[$i * 4 + 1] = $yy;
			$data[$i * 4 + 2] = $zz;
			$data[$i * 4 + 3] = $r;
		}
		for($i1 = 0; $i1 < $size - 1; ++$i1){
			if(!($data[$i1 * 4 + 3] > 0.0)){
				continue;
			}
			for($i2 = $i1 + 1; $i2 < $size; ++$i2){
				if(!($data[$i2 * 4 + 3] > 0.0)){
					continue;
				}
				$dx = $data[$i1 * 4] - $data[$i2 * 4];
				$dy = $data[$i1 * 4 + 1] - $data[$i2 * 4 + 1];
				$dz = $data[$i1 * 4 + 2] - $data[$i2 * 4 + 2];
				$dr = $data[$i1 * 4 + 3] - $data[$i2 * 4 + 3];
				if($dr * $dr > $dx * $dx + $dy * $dy + $dz * $dz){
					if($dr > 0.0){
						$data[$i2 * 4 + 3] = -1.0;
					}else{
						$data[$i1 * 4 + 3] = -1.0;
					}
				}
			}
		}
		$targets = $this->targets;
		$discard = $this->discardChance;
		for($i = 0; $i < $size; ++$i){
			$r = $data[$i * 4 + 3];
			if(!($r >= 0.0)){
				continue;
			}
			$xx = $data[$i * 4];
			$yy = $data[$i * 4 + 1];
			$zz = $data[$i * 4 + 2];
			$xMin = max((int) floor($xx - $r), $xStart);
			$yMin = max((int) floor($yy - $r), $yStart);
			$zMin = max((int) floor($zz - $r), $zStart);
			$xMax = max((int) floor($xx + $r), $xMin);
			$yMax = max((int) floor($yy + $r), $yMin);
			$zMax = max((int) floor($zz + $r), $zMin);
			for($x = $xMin; $x <= $xMax; ++$x){
				$xd = ($x + 0.5 - $xx) / $r;
				if(!($xd * $xd < 1.0)){
					continue;
				}
				for($y = $yMin; $y <= $yMax; ++$y){
					$yd = ($y + 0.5 - $yy) / $r;
					if(!($xd * $xd + $yd * $yd < 1.0)){
						continue;
					}
					for($z = $zMin; $z <= $zMax; ++$z){
						$zd = ($z + 0.5 - $zz) / $r;
						if(!($xd * $xd + $yd * $yd + $zd * $zd < 1.0)){
							continue;
						}
						if($level->isOutsideBuildHeight($y)){
							continue;
						}
						$bit = ($x - $xStart) + ($y - $yStart) * $sizeXZ + ($z - $zStart) * $sizeXZ * $sizeY;
						if(isset($tested[$bit])){
							continue;
						}
						$tested[$bit] = true;
						if(!$level->ensureCanWrite($x, $y, $z)){
							continue;
						}
						$state = $level->getState($x, $y, $z);
						foreach($targets as [$test, $ore]){
							if($this->canPlaceOre($c, $state, $test, $x, $y, $z, $discard)){
								$level->setState($x, $y, $z, $ore);
								++$placed;
								break;
							}
						}
					}
				}
			}
		}
		return $placed > 0;
	}

	/**
	 * @phpstan-param \Closure(int, FeatureRandom|RandomSource, int) : bool $test
	 */
	private function canPlaceOre(FeatureContext $c, int $state, \Closure $test, int $x, int $y, int $z, float $discard) : bool{
		if(!$test($state, $c->random, $y)){
			return false;
		}
		//shouldSkipAirCheck()
		if($discard <= 0.0){
			return true;
		}
		if($discard < 1.0 && !($c->random->nextFloat() < $discard)){
			return true;
		}
		$level = $c->level;
		$blocks = $c->blocks;
		return !(
			$blocks->isAir($level->getState($x, $y - 1, $z)) || $blocks->isAir($level->getState($x, $y + 1, $z)) ||
			$blocks->isAir($level->getState($x, $y, $z - 1)) || $blocks->isAir($level->getState($x, $y, $z + 1)) ||
			$blocks->isAir($level->getState($x - 1, $y, $z)) || $blocks->isAir($level->getState($x + 1, $y, $z))
		);
	}

	private function placeScattered(FeatureContext $c, int $ox, int $oy, int $oz) : bool{
		$random = $c->random;
		$level = $c->level;
		$tries = $random->nextBoundedInt($this->size + 1);
		for($i = 0; $i < $tries; ++$i){
			$max = $i < 7 ? $i : 7;
			$x = $ox + self::axisOffset($c, $max);
			$y = $oy + self::axisOffset($c, $max);
			$z = $oz + self::axisOffset($c, $max);
			$state = $level->getState($x, $y, $z);
			foreach($this->targets as [$test, $ore]){
				if($this->canPlaceOre($c, $state, $test, $x, $y, $z, $this->discardChance)){
					$level->setState($x, $y, $z, $ore);
					break;
				}
			}
		}
		return true;
	}

	private static function axisOffset(FeatureContext $c, int $max) : int{
		$v = FloatMath::fround(FloatMath::fround($c->random->nextFloat() - $c->random->nextFloat()) * $max);
		return (int) floor($v + 0.5);
	}
}
