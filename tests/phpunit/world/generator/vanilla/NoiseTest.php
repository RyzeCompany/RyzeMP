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

namespace pocketmine\world\generator\vanilla;

use PHPUnit\Framework\TestCase;
use pocketmine\utils\Utils;
use pocketmine\world\generator\vanilla\density\CubicSpline;
use pocketmine\world\generator\vanilla\density\DensityFunction;
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\noise\BlendedNoise;
use pocketmine\world\generator\vanilla\noise\NormalNoiseParameters;
use pocketmine\world\generator\vanilla\random\XoroshiroRandom;
use const INF;

class NoiseTest extends TestCase{

	public function testNormalNoise() : void{
		$pos = XoroshiroRandom::create(42)->forkPositional();
		$offset = (new NormalNoiseParameters(0.9381732587751005, -3, 4, NormalNoiseParameters::NORMALIZE_ENABLED, [1.0, 1.0, 1.0, 0.0]))->create($pos->fromHashOf("minecraft:offset"));
		foreach(FixtureLoader::points("normalNoise") as [$x, $y, $z, $value]){
			self::assertSame((float) $value, $offset->get3D($x, $y, $z), "offset noise at $x $y $z");
		}
		$cheese = (new NormalNoiseParameters(0.8361300524356068, -8, 9, NormalNoiseParameters::NORMALIZE_ENABLED, [0.5, 1.0, 2.0, 1.0, 2.0, 1.0, 0.0, 2.0, 0.0]))->create($pos->fromHashOf("minecraft:cave_cheese"));
		foreach(FixtureLoader::points("caveCheeseNoise") as [$x, $y, $z, $value]){
			self::assertSame((float) $value, $cheese->get3D($x, $y, $z), "cave cheese noise at $x $y $z");
		}
	}

	public function testBlendedNoise() : void{
		$noise = new BlendedNoise(XoroshiroRandom::create(42)->forkPositional()->fromHashOf("minecraft:terrain"), 0.25, 0.125, 80, 160, 8);
		foreach(FixtureLoader::points("blendedNoise") as [$x, $y, $z, $value]){
			self::assertSame((float) $value, $noise->sample($x, $y, $z), "blended noise at $x $y $z");
		}
	}

	public function testSplines() : void{
		$coordinate = new class(-INF, INF) extends DensityFunction{
			public function compute(float $x, float $y, float $z) : float{
				return $x;
			}

			public function children() : array{
				return [];
			}
		};
		foreach(Utils::stringifyKeys(FixtureLoader::splines()) as $name => $case){
			$locations = [];
			$values = [];
			$derivatives = [];
			foreach($case["points"] as [$location, $value, $derivative]){
				$locations[] = FloatMath::fround($location);
				$values[] = FloatMath::fround($value);
				$derivatives[] = FloatMath::fround($derivative);
			}
			$spline = new CubicSpline($coordinate, $locations, $values, $derivatives);
			foreach($case["values"] as [$input, $expected]){
				self::assertSame((float) $expected, $spline->compute($input, 0, 0), "spline $name at $input");
			}
		}
	}
}
