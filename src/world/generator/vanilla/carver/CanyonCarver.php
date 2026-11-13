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

namespace pocketmine\world\generator\vanilla\carver;

use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\WorldgenData;
use function abs;

/**
 * "minecraft:canyon" (CanyonWorldCarver): one long, tall and thin tunnel per start chunk ("ravine"), whose width
 * changes with height.
 */
final class CanyonCarver extends WorldCarver{
	private const DISTANCE = (self::RANGE * 2 - 1) * 16;

	private const PITCH_DAMPING = 0.699999988079071; //0.7F
	private const ROTATION_STEP = 0.05000000074505806; //0.05F
	private const PITCH_SPEED_DAMPING = 0.800000011920929; //0.8F
	private const YAW_SPEED_DAMPING = 0.5;

	public function __construct(
		string $id,
		float $probability,
		ValueProvider $y,
		private readonly ValueProvider $verticalRotation,
		private readonly ValueProvider $yScale,
		private readonly ValueProvider $distanceFactor,
		private readonly ValueProvider $thickness,
		private readonly int $widthSmoothness,
		private readonly ValueProvider $horizontalRadiusFactor,
		private readonly float $verticalRadiusDefaultFactor,
		private readonly float $verticalRadiusCenterFactor,
		private readonly int $genDepth
	){
		parent::__construct($id, $probability, $y);
		if($widthSmoothness <= 0){
			throw new \RuntimeException("Invalid canyon width_smoothness ($id)");
		}
	}

	/**
	 * @param mixed[] $json
	 */
	public static function fromJson(string $id, array $json, int $minY, int $height) : self{
		$shape = WorldgenData::array($json, "shape");
		return new self(
			$id,
			CarverMath::f(WorldgenData::float($json, "probability")),
			ValueProvider::height($json["y"] ?? null, $minY, $height),
			ValueProvider::float($json["vertical_rotation"] ?? null),
			ValueProvider::float($shape["y_scale"] ?? null),
			ValueProvider::float($shape["distance_factor"] ?? null),
			ValueProvider::float($shape["thickness"] ?? null),
			WorldgenData::int($shape, "width_smoothness"),
			ValueProvider::float($shape["horizontal_radius_factor"] ?? null),
			CarverMath::f(WorldgenData::float($shape, "vertical_radius_default_factor")),
			CarverMath::f(WorldgenData::float($shape, "vertical_radius_center_factor")),
			$height
		);
	}

	public function carve(CarvingRun $run, LegacyRandom $random, int $startX, int $startZ) : void{
		$x = (float) (($startX << 4) + $random->nextBoundedInt(16));
		$y = (float) $this->y->sampleInt($random);
		$z = (float) (($startZ << 4) + $random->nextBoundedInt(16));
		$yaw = CarverMath::f($random->nextFloat() * CarverMath::TWO_PI);
		$pitch = $this->verticalRotation->sampleFloat($random);
		$yScale = $this->yScale->sampleFloat($random);
		$thickness = $this->thickness->sampleFloat($random);
		$steps = (int) CarverMath::f(self::DISTANCE * $this->distanceFactor->sampleFloat($random));
		$this->doCarve($run, $random->nextLong(), $x, $y, $z, $thickness, $yaw, $pitch, 0, $steps, $yScale);
	}

	private function doCarve(CarvingRun $run, int $seed, float $x, float $y, float $z, float $thickness, float $yaw, float $pitch, int $step, int $steps, float $yScale) : void{
		$random = new LegacyRandom($seed);
		//initWidthFactors(): squared width factor per height, changing every few blocks
		$widthFactors = [];
		$factor = 1.0;
		for($i = 0, $depth = $this->genDepth; $i < $depth; ++$i){
			if($i === 0 || $random->nextBoundedInt($this->widthSmoothness) === 0){
				$factor = CarverMath::f(1.0 + CarverMath::f($random->nextFloat() * $random->nextFloat()));
			}
			$widthFactors[] = CarverMath::f($factor * $factor);
		}
		$yawSpeed = 0.0;
		$pitchSpeed = 0.0;
		for($i = $step; $i < $steps; ++$i){
			$horizontalRadius = 1.5 + CarverMath::f(CarverMath::sin(CarverMath::f(CarverMath::f($i * CarverMath::PI) / $steps)) * $thickness);
			$verticalRadius = $horizontalRadius * $yScale;
			$horizontalRadius *= $this->horizontalRadiusFactor->sampleFloat($random);
			//updateVerticalRadius()
			$center = CarverMath::f(1.0 - CarverMath::f(abs(CarverMath::f(0.5 - CarverMath::f($i / $steps))) * 2.0));
			$verticalFactor = CarverMath::f($this->verticalRadiusDefaultFactor + CarverMath::f($this->verticalRadiusCenterFactor * $center));
			$verticalRadius = $verticalFactor * $verticalRadius * CarverMath::f(CarverMath::f($random->nextFloat() * 0.25) + 0.75);

			$cosPitch = CarverMath::cos($pitch);
			$sinPitch = CarverMath::sin($pitch);
			$x += CarverMath::f(CarverMath::cos($yaw) * $cosPitch);
			$y += $sinPitch;
			$z += CarverMath::f(CarverMath::sin($yaw) * $cosPitch);
			$pitch = CarverMath::f($pitch * self::PITCH_DAMPING);
			$pitch = CarverMath::f($pitch + CarverMath::f($pitchSpeed * self::ROTATION_STEP));
			$yaw = CarverMath::f($yaw + CarverMath::f($yawSpeed * self::ROTATION_STEP));
			$pitchSpeed = CarverMath::f($pitchSpeed * self::PITCH_SPEED_DAMPING);
			$yawSpeed = CarverMath::f($yawSpeed * self::YAW_SPEED_DAMPING);
			$a = $random->nextFloat();
			$b = $random->nextFloat();
			$c = $random->nextFloat();
			$pitchSpeed = CarverMath::f($pitchSpeed + CarverMath::f(CarverMath::f(CarverMath::f($a - $b) * $c) * 2.0));
			$a = $random->nextFloat();
			$b = $random->nextFloat();
			$c = $random->nextFloat();
			$yawSpeed = CarverMath::f($yawSpeed + CarverMath::f(CarverMath::f(CarverMath::f($a - $b) * $c) * 4.0));
			if($random->nextBoundedInt(4) !== 0){
				if(!self::canReach($run, $x, $z, $i, $steps, $thickness)){
					return;
				}
				$run->carveEllipsoid($x, $y, $z, $horizontalRadius, $verticalRadius, 0.0, $widthFactors);
			}
		}
	}
}
