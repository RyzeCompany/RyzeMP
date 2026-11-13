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
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use pocketmine\world\generator\vanilla\math\FloatMath;
use function ceil;
use function cos;
use function fdiv;
use function intdiv;
use function max;
use function min;
use function sin;
use const M_PI;

/**
 * minecraft:iceberg (IcebergFeature), with Java's float/double arithmetic.
 */
final class IcebergFeature implements Feature{
	private int $mainState;
	private int $packedIce;
	private int $snowBlock;
	private int $blueIce;
	private int $ice;
	private int $snow;
	private int $snowBlockState;

	/**
	 * @param mixed[] $json
	 */
	public function __construct(FeatureRegistry $registry, array $json){
		$blocks = $registry->blocks;
		$this->mainState = $blocks->stateFromJson($json["state"] ?? null);
		$this->packedIce = $blocks->blockIndex("packed_ice");
		$this->snowBlock = $blocks->blockIndex("snow_block");
		$this->blueIce = $blocks->blockIndex("blue_ice");
		$this->ice = $blocks->blockIndex("ice");
		$this->snow = $blocks->blockIndex("snow");
		$this->snowBlockState = $blocks->defaultOf("snow_block");
	}

	public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
		$random = $c->random;
		$y = $c->seaLevel;
		$snowOnTop = $random->nextDouble() > 0.7;
		$shapeAngle = $random->nextDouble() * 2.0 * M_PI;
		$shapeEllipseA = 11 - $random->nextBoundedInt(5);
		$shapeEllipseC = 3 + $random->nextBoundedInt(3);
		$isEllipse = $random->nextDouble() > 0.7;
		$overWaterHeight = $isEllipse ? $random->nextBoundedInt(6) + 6 : $random->nextBoundedInt(15) + 3;
		if(!$isEllipse && $random->nextDouble() > 0.9){
			$overWaterHeight += $random->nextBoundedInt(19) + 7;
		}
		$underWaterHeight = min($overWaterHeight + $random->nextBoundedInt(11), 18);
		$width = min($overWaterHeight + $random->nextBoundedInt(7) - $random->nextBoundedInt(5), 11);
		$a = $isEllipse ? $shapeEllipseA : 11;
		for($xo = -$a; $xo < $a; ++$xo){
			for($zo = -$a; $zo < $a; ++$zo){
				for($yOff = 0; $yOff < $overWaterHeight; ++$yOff){
					$radius = $isEllipse ? self::radiusEllipse($yOff, $overWaterHeight, $width) : self::radiusRound($random, $yOff, $overWaterHeight, $width);
					if($isEllipse || $xo < $radius){
						$this->generateIcebergBlock($c, $x, $y, $z, $overWaterHeight, $xo, $yOff, $zo, $radius, $a, $isEllipse, $shapeEllipseC, $shapeAngle, $snowOnTop);
					}
				}
			}
		}
		$this->smooth($c, $x, $y, $z, $width, $overWaterHeight, $isEllipse, $shapeEllipseA);
		for($xo = -$a; $xo < $a; ++$xo){
			for($zo = -$a; $zo < $a; ++$zo){
				for($yOff = -1; $yOff > -$underWaterHeight; --$yOff){
					$newA = $isEllipse ? (int) ceil(FloatMath::fround($a * FloatMath::fround(1.0 - FloatMath::fround((float) ($yOff * $yOff) / FloatMath::fround($underWaterHeight * 8.0))))) : $a;
					$radius = self::radiusSteep($random, -$yOff, $underWaterHeight, $width);
					if($xo < $radius){
						$this->generateIcebergBlock($c, $x, $y, $z, $underWaterHeight, $xo, $yOff, $zo, $radius, $newA, $isEllipse, $shapeEllipseC, $shapeAngle, $snowOnTop);
					}
				}
			}
		}
		$doCutOut = $isEllipse ? $random->nextDouble() > 0.1 : $random->nextDouble() > 0.7;
		if($doCutOut){
			$this->generateCutOut($c, $width, $overWaterHeight, $x, $y, $z, $isEllipse, $shapeEllipseA, $shapeAngle, $shapeEllipseC);
		}
		return true;
	}

	private function isIcebergState(int $block) : bool{
		return $block === $this->packedIce || $block === $this->snowBlock || $block === $this->blueIce;
	}

	private function generateCutOut(FeatureContext $c, int $width, int $height, int $x, int $y, int $z, bool $isEllipse, int $shapeEllipseA, float $shapeAngle, int $shapeEllipseC) : void{
		$random = $c->random;
		$signX = $random->nextBoolean() ? -1 : 1;
		$signZ = $random->nextBoolean() ? -1 : 1;
		$half = intdiv($width, 2);
		$xOff = $random->nextBoundedInt(max($half - 2, 1));
		if($random->nextBoolean()){
			$xOff = $half + 1 - $random->nextBoundedInt(max($width - $half - 1, 1));
		}
		$zOff = $random->nextBoundedInt(max($half - 2, 1));
		if($random->nextBoolean()){
			$zOff = $half + 1 - $random->nextBoundedInt(max($width - $half - 1, 1));
		}
		if($isEllipse){
			$xOff = $zOff = $random->nextBoundedInt(max($shapeEllipseA - 5, 1));
		}
		$lx = $signX * $xOff;
		$lz = $signZ * $zOff;
		$angle = $isEllipse ? $shapeAngle + M_PI / 2 : $random->nextDouble() * 2.0 * M_PI;
		for($yOff = 0; $yOff < $height - 3; ++$yOff){
			$radius = self::radiusRound($random, $yOff, $height, $width);
			$this->carve($c, $radius, $yOff, $x, $y, $z, false, $angle, $lx, $lz, $shapeEllipseA, $shapeEllipseC);
		}
		for($yOff = -1; $yOff > -$height + $random->nextBoundedInt(5); --$yOff){
			$radius = self::radiusSteep($random, -$yOff, $height, $width);
			$this->carve($c, $radius, $yOff, $x, $y, $z, true, $angle, $lx, $lz, $shapeEllipseA, $shapeEllipseC);
		}
	}

	private function carve(FeatureContext $c, int $radius, int $yOff, int $x, int $y, int $z, bool $underWater, float $angle, int $lx, int $lz, int $shapeEllipseA, int $shapeEllipseC) : void{
		$level = $c->level;
		$blocks = $c->blocks;
		$a = $radius + 1 + intdiv($shapeEllipseA, 3);
		$cc = min($radius - 3, 3) + intdiv($shapeEllipseC, 2) - 1;
		for($xo = -$a; $xo < $a; ++$xo){
			for($zo = -$a; $zo < $a; ++$zo){
				if(self::signedDistanceEllipse($xo, $zo, $lx, $lz, $a, $cc, $angle) >= 0.0){
					continue;
				}
				$px = $x + $xo;
				$py = $y + $yOff;
				$pz = $z + $zo;
				$block = $blocks->blockOf[$level->getState($px, $py, $pz)];
				if($this->isIcebergState($block) || $block === $this->snowBlock){
					if($underWater){
						$level->setState($px, $py, $pz, $blocks->water);
					}else{
						$level->setState($px, $py, $pz, $blocks->air);
						if($blocks->blockOf[$level->getState($px, $py + 1, $pz)] === $this->snow){
							$level->setState($px, $py + 1, $pz, $blocks->air);
						}
					}
				}
			}
		}
	}

	private function generateIcebergBlock(FeatureContext $c, int $x, int $y, int $z, int $height, int $xo, int $yOff, int $zo, int $radius, int $a, bool $isEllipse, int $shapeEllipseC, float $shapeAngle, bool $snowOnTop) : void{
		$random = $c->random;
		if($isEllipse){
			$ellipseC = $shapeEllipseC;
			if($yOff > 0 && $height - $yOff <= 3){
				$ellipseC -= 4 - ($height - $yOff);
			}
			$signedDist = self::signedDistanceEllipse($xo, $zo, 0, 0, $a, $ellipseC, $shapeAngle);
		}else{
			$off = FloatMath::fround(fdiv(FloatMath::fround(10.0 * max(0.20000000298023224, min(0.800000011920929, $random->nextFloat()))), (float) $radius));
			$signedDist = $off + (float) ($xo * $xo) + (float) ($zo * $zo) - (float) ($radius * $radius);
		}
		if($signedDist >= 0.0){
			return;
		}
		$compareVal = $isEllipse ? -0.5 : (float) (-6 - $random->nextBoundedInt(3));
		if($signedDist > $compareVal && $random->nextDouble() > 0.9){
			return;
		}
		$this->setIcebergBlock($c, $x + $xo, $y + $yOff, $z + $zo, $height - $yOff, $height, $isEllipse, $snowOnTop);
	}

	private function setIcebergBlock(FeatureContext $c, int $x, int $y, int $z, int $hDiff, int $height, bool $isEllipse, bool $snowOnTop) : void{
		$level = $c->level;
		$blocks = $c->blocks;
		$random = $c->random;
		$state = $level->getState($x, $y, $z);
		$block = $blocks->blockOf[$state];
		$isWater = $block === $blocks->waterBlock;
		if(!$blocks->isAir($state) && $block !== $this->snowBlock && $block !== $this->ice && !$isWater){
			return;
		}
		$randomness = !$isEllipse || $random->nextDouble() > 0.05;
		$divisor = $isEllipse ? 3 : 2;
		if($snowOnTop && !$isWater && (float) $hDiff <= (float) $random->nextBoundedInt(max(1, intdiv($height, $divisor))) + $height * 0.6 && $randomness){
			$level->setState($x, $y, $z, $this->snowBlockState);
		}else{
			$level->setState($x, $y, $z, $this->mainState);
		}
	}

	private static function signedDistanceEllipse(int $xo, int $zo, int $ox, int $oz, int $a, int $c, float $angle) : float{
		$dx = (float) ($xo - $ox);
		$dz = (float) ($zo - $oz);
		$cos = cos($angle);
		$sin = sin($angle);
		$u = fdiv($dx * $cos - $dz * $sin, (float) $a);
		$v = fdiv($dx * $sin + $dz * $cos, (float) $c);
		return $u * $u + $v * $v - 1.0;
	}

	private static function radiusRound(FeatureRandom $random, int $yOff, int $height, int $width) : int{
		$k = FloatMath::fround(3.5 - $random->nextFloat());
		$heightK = FloatMath::fround($height * $k);
		$scale = FloatMath::fround(FloatMath::fround(1.0 - FloatMath::fround(fdiv((float) ($yOff * $yOff), $heightK))) * $width);
		if($height > 15 + $random->nextBoundedInt(5)){
			$tempYOff = $yOff < 3 + $random->nextBoundedInt(6) ? intdiv($yOff, 2) : $yOff;
			$scale = FloatMath::fround(FloatMath::fround(1.0 - FloatMath::fround(fdiv((float) $tempYOff, FloatMath::fround($heightK * 0.4000000059604645)))) * $width);
		}
		return (int) ceil(FloatMath::fround($scale / 2.0));
	}

	private static function radiusEllipse(int $yOff, int $height, int $width) : int{
		$scale = FloatMath::fround(FloatMath::fround(1.0 - FloatMath::fround(fdiv((float) ($yOff * $yOff), (float) $height))) * $width);
		return (int) ceil(FloatMath::fround($scale / 2.0));
	}

	private static function radiusSteep(FeatureRandom $random, int $yOff, int $height, int $width) : int{
		$k = FloatMath::fround(1.0 + FloatMath::fround($random->nextFloat() / 2.0));
		$scale = FloatMath::fround(FloatMath::fround(1.0 - FloatMath::fround(fdiv((float) $yOff, FloatMath::fround($height * $k)))) * $width);
		return (int) ceil(FloatMath::fround($scale / 2.0));
	}

	private function smooth(FeatureContext $c, int $x, int $y, int $z, int $width, int $height, bool $isEllipse, int $shapeEllipseA) : void{
		$level = $c->level;
		$blocks = $c->blocks;
		$a = $isEllipse ? $shapeEllipseA : intdiv($width, 2);
		for($xo = -$a; $xo <= $a; ++$xo){
			for($zo = -$a; $zo <= $a; ++$zo){
				for($yOff = 0; $yOff <= $height; ++$yOff){
					$px = $x + $xo;
					$py = $y + $yOff;
					$pz = $z + $zo;
					$block = $blocks->blockOf[$level->getState($px, $py, $pz)];
					if(!$this->isIcebergState($block) && $block !== $this->snow){
						continue;
					}
					if($blocks->isAir($level->getState($px, $py - 1, $pz))){
						$level->setState($px, $py, $pz, $blocks->air);
						$level->setState($px, $py + 1, $pz, $blocks->air);
					}elseif($this->isIcebergState($block)){
						$counter = 0;
						foreach([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dz]){
							if(!$this->isIcebergState($blocks->blockOf[$level->getState($px + $dx, $py, $pz + $dz)])){
								++$counter;
							}
						}
						if($counter >= 3){
							$level->setState($px, $py, $pz, $blocks->air);
						}
					}
				}
			}
		}
	}
}
