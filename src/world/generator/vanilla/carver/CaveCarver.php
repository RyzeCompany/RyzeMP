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
use function intdiv;
use function is_bool;

/**
 * "minecraft:cave" (CaveWorldCarver): a few tunnels per start chunk, sometimes from a round room, wandering with
 * random pitch/yaw changes and splitting once into two thinner branches.
 */
final class CaveCarver extends WorldCarver{
	/** Steps of a tunnel: (getRange() * 2 - 1) * 16 */
	private const DISTANCE = (self::RANGE * 2 - 1) * 16;

	private const STEEP_PITCH_DAMPING = 0.9200000166893005; //0.92F
	private const PITCH_DAMPING = 0.699999988079071; //0.7F
	private const ROTATION_STEP = 0.10000000149011612; //0.1F
	private const PITCH_SPEED_DAMPING = 0.8999999761581421; //0.9F
	private const YAW_SPEED_DAMPING = 0.75;

	public function __construct(
		string $id,
		float $probability,
		ValueProvider $y,
		private readonly ValueProvider $count,
		private readonly ValueProvider $horizontalRadiusMultiplier,
		private readonly ValueProvider $verticalRadiusMultiplier,
		private readonly ValueProvider $startVerticalRadiusMultiplier,
		private readonly ValueProvider $floorLevel,
		private readonly ValueProvider $roomVerticalRadiusMultiplier,
		private readonly ValueProvider $thickness,
		private readonly bool $weirdThicknessBias
	){
		parent::__construct($id, $probability, $y);
	}

	/**
	 * @param mixed[] $json
	 */
	public static function fromJson(string $id, array $json, int $minY, int $height) : self{
		$weird = $json["weird_thickness_bias"] ?? false;
		if(!is_bool($weird)){
			throw new \RuntimeException("Expected boolean for \"weird_thickness_bias\" ($id)");
		}
		return new self(
			$id,
			CarverMath::f(WorldgenData::float($json, "probability")),
			ValueProvider::height($json["y"] ?? null, $minY, $height),
			ValueProvider::int($json["count"] ?? null),
			ValueProvider::float($json["horizontal_radius_multiplier"] ?? null),
			ValueProvider::float($json["vertical_radius_multiplier"] ?? null),
			ValueProvider::float($json["start_vertical_radius_multiplier"] ?? 1.0),
			ValueProvider::float($json["floor_level"] ?? null),
			ValueProvider::float($json["room_vertical_radius_multiplier"] ?? null),
			ValueProvider::float($json["thickness"] ?? null),
			$weird
		);
	}

	public function carve(CarvingRun $run, LegacyRandom $random, int $startX, int $startZ) : void{
		$distance = self::DISTANCE;
		$count = $this->count->sampleInt($random);
		for($i = 0; $i < $count; ++$i){
			$x = (float) (($startX << 4) + $random->nextBoundedInt(16));
			$y = (float) $this->y->sampleInt($random);
			$z = (float) (($startZ << 4) + $random->nextBoundedInt(16));
			$horizontalMultiplier = $this->horizontalRadiusMultiplier->sampleFloat($random);
			$verticalMultiplier = $this->verticalRadiusMultiplier->sampleFloat($random);
			$startVerticalMultiplier = $this->startVerticalRadiusMultiplier->sampleFloat($random);
			$floorLevel = $this->floorLevel->sampleFloat($random);
			$tunnels = 1;
			if($random->nextBoundedInt(4) === 0){
				$roomVerticalMultiplier = $this->roomVerticalRadiusMultiplier->sampleFloat($random);
				$roomRadius = CarverMath::f(1.0 + CarverMath::f($random->nextFloat() * 6.0));
				$this->createRoom($run, $x, $y, $z, $roomRadius, $roomVerticalMultiplier, $floorLevel);
				$tunnels += $random->nextBoundedInt(4);
			}
			for($t = 0; $t < $tunnels; ++$t){
				$yaw = CarverMath::f($random->nextFloat() * CarverMath::TWO_PI);
				$pitch = CarverMath::f(CarverMath::f($random->nextFloat() - 0.5) / 4.0);
				$thickness = $this->thickness->sampleFloat($random);
				if($this->weirdThicknessBias && $random->nextBoundedInt(10) === 0){
					$bias = CarverMath::f($random->nextFloat() * $random->nextFloat());
					$thickness = CarverMath::f($thickness * CarverMath::f(CarverMath::f($bias * 3.0) + 1.0));
				}
				$steps = $distance - $random->nextBoundedInt(intdiv($distance, 4));
				$this->createTunnel($run, $random->nextLong(), $x, $y, $z, $horizontalMultiplier, $verticalMultiplier, $thickness, $yaw, $pitch, 0, $steps, $startVerticalMultiplier, $floorLevel);
			}
		}
	}

	private function createRoom(CarvingRun $run, float $x, float $y, float $z, float $radius, float $verticalMultiplier, float $floorLevel) : void{
		$horizontalRadius = 1.5 + CarverMath::f(CarverMath::sin(CarverMath::HALF_PI) * $radius);
		$verticalRadius = $horizontalRadius * $verticalMultiplier;
		$run->carveEllipsoid($x + 1.0, $y, $z, $horizontalRadius, $verticalRadius, $floorLevel, null);
	}

	private function createTunnel(CarvingRun $run, int $seed, float $x, float $y, float $z, float $horizontalMultiplier, float $verticalMultiplier, float $thickness, float $yaw, float $pitch, int $step, int $steps, float $yScale, float $floorLevel) : void{
		$random = new LegacyRandom($seed);
		$splitStep = $random->nextBoundedInt(intdiv($steps, 2)) + intdiv($steps, 4);
		$steep = $random->nextBoundedInt(6) === 0;
		$pitchDamping = $steep ? self::STEEP_PITCH_DAMPING : self::PITCH_DAMPING;
		$yawSpeed = 0.0;
		$pitchSpeed = 0.0;
		for($i = $step; $i < $steps; ++$i){
			$horizontalRadius = 1.5 + CarverMath::f(CarverMath::sin(CarverMath::f(CarverMath::f(CarverMath::PI * $i) / $steps)) * $thickness);
			$verticalRadius = $horizontalRadius * $yScale;
			$cosPitch = CarverMath::cos($pitch);
			$x += CarverMath::f(CarverMath::cos($yaw) * $cosPitch);
			$y += CarverMath::sin($pitch);
			$z += CarverMath::f(CarverMath::sin($yaw) * $cosPitch);
			$pitch = CarverMath::f($pitch * $pitchDamping);
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
			if($i === $splitStep && $thickness > 1.0){
				$seed1 = $random->nextLong();
				$thickness1 = CarverMath::f(CarverMath::f($random->nextFloat() * 0.5) + 0.5);
				$this->createTunnel($run, $seed1, $x, $y, $z, $horizontalMultiplier, $verticalMultiplier, $thickness1, CarverMath::f($yaw - CarverMath::HALF_PI), CarverMath::f($pitch / 3.0), $i, $steps, 1.0, $floorLevel);
				$seed2 = $random->nextLong();
				$thickness2 = CarverMath::f(CarverMath::f($random->nextFloat() * 0.5) + 0.5);
				$this->createTunnel($run, $seed2, $x, $y, $z, $horizontalMultiplier, $verticalMultiplier, $thickness2, CarverMath::f($yaw + CarverMath::HALF_PI), CarverMath::f($pitch / 3.0), $i, $steps, 1.0, $floorLevel);
				return;
			}
			if($random->nextBoundedInt(4) !== 0){
				if(!self::canReach($run, $x, $z, $i, $steps, $thickness)){
					return;
				}
				$run->carveEllipsoid($x, $y, $z, $horizontalRadius * $horizontalMultiplier, $verticalRadius * $verticalMultiplier, $floorLevel, null);
			}
		}
	}
}
