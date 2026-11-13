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

namespace pocketmine\world\generator\vanilla\feature;

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\noise\SimplexNoise;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\WorldgenData;
use function is_bool;

/**
 * Biome temperature, freezing and snowing checks of Java 26.3 (Biome.getTemperature(), shouldFreeze(), shouldSnow()),
 * in float32 like Java. During worldgen the block light is 0.
 */
final class BiomeClimate{
	private SimplexNoise $temperatureNoise;
	/** @var SimplexNoise[] */
	private array $frozenNoise = [];
	private SimplexNoise $biomeInfoNoise;
	/** @phpstan-var array<int, float> */
	private array $baseTemperature = [];
	/** @phpstan-var array<int, bool> */
	private array $frozen = [];
	/** @phpstan-var array<int, bool> */
	private array $precipitation = [];

	public function __construct(FeatureRegistry $registry){
		$this->temperatureNoise = new SimplexNoise(new LegacyRandom(1234));
		$random = new LegacyRandom(3456);
		for($i = 0; $i < 3; ++$i){
			$this->frozenNoise[] = new SimplexNoise($random);
		}
		$this->biomeInfoNoise = new SimplexNoise(new LegacyRandom(2345));
		foreach($registry->biomeNames as $index => $_){
			$json = $registry->biomeJson($index);
			$this->baseTemperature[$index] = FloatMath::fround(WorldgenData::float($json, "temperature", 0.5));
			$this->frozen[$index] = ($json["temperature_modifier"] ?? "none") === "frozen";
			$this->precipitation[$index] = is_bool($json["has_precipitation"] ?? null) ? $json["has_precipitation"] : true;
		}
	}

	private function simplex(SimplexNoise $noise, float $x, float $z) : float{
		return FloatMath::fround($noise->getValue2D($x, $z));
	}

	public function temperature(int $biome, int $x, int $y, int $z, int $seaLevel) : float{
		$base = $this->baseTemperature[$biome] ?? 0.5;
		if($this->frozen[$biome] ?? false){
			$large = FloatMath::fround(
				FloatMath::fround(
					FloatMath::fround(0.0 + FloatMath::fround(0.1428571492433548 * $this->simplex($this->frozenNoise[0], $x * 0.05, $z * 0.05))) +
					FloatMath::fround(0.2857142984867096 * $this->simplex($this->frozenNoise[1], $x * 0.05 * 0.5, $z * 0.05 * 0.5))
				) + FloatMath::fround(0.5714285969734192 * $this->simplex($this->frozenNoise[2], $x * 0.05 * 0.25, $z * 0.05 * 0.25))
			);
			$largeVariation = FloatMath::fround($large * 7.0);
			$edge = $this->simplex($this->biomeInfoNoise, $x * 0.2, $z * 0.2);
			if($largeVariation + $edge < 0.3){
				$small = $this->simplex($this->biomeInfoNoise, $x * 0.09, $z * 0.09);
				if($small < 0.8){
					$base = 0.20000000298023224;
				}
			}
		}
		$snowLevel = $seaLevel + 17;
		if($y > $snowLevel){
			$v = FloatMath::fround($this->simplex($this->temperatureNoise, FloatMath::fround($x / 8.0), FloatMath::fround($z / 8.0)) * 8.0);
			return FloatMath::fround($base - FloatMath::fround(FloatMath::fround(FloatMath::fround(FloatMath::fround($v + $y) - $snowLevel) * 0.05000000074505806) / 40.0));
		}
		return $base;
	}

	public function warmEnoughToRain(int $biome, int $x, int $y, int $z, int $seaLevel) : bool{
		return $this->temperature($biome, $x, $y, $z, $seaLevel) >= 0.15000000596046448;
	}

	public function shouldFreeze(FeatureContext $c, int $x, int $y, int $z, bool $checkNeighbors = true, ?int $biome = null) : bool{
		$level = $c->level;
		if($this->warmEnoughToRain($biome ?? $level->getBiome($x, $y, $z), $x, $y, $z, $c->seaLevel)){
			return false;
		}
		if(!$level->isInsideBuildHeight($y)){
			return false;
		}
		$state = $level->getState($x, $y, $z);
		$blocks = $c->blocks;
		if($blocks->blockOf[$state] !== $blocks->waterBlock || !$blocks->fluidIsSource($state)){
			return false;
		}
		if(!$checkNeighbors){
			return true;
		}
		foreach([Direction::WEST, Direction::EAST, Direction::NORTH, Direction::SOUTH] as $d){
			if(!$level->isWaterAt($x + Direction::STEP_X[$d], $y, $z + Direction::STEP_Z[$d])){
				return true;
			}
		}
		return false;
	}

	public function shouldSnow(FeatureContext $c, int $x, int $y, int $z, ?int $biome = null) : bool{
		$level = $c->level;
		$biome ??= $level->getBiome($x, $y, $z);
		if(!($this->precipitation[$biome] ?? true) || $this->warmEnoughToRain($biome, $x, $y, $z, $c->seaLevel)){
			return false;
		}
		if(!$level->isInsideBuildHeight($y)){
			return false;
		}
		$state = $level->getState($x, $y, $z);
		$blocks = $c->blocks;
		if(!$blocks->isAir($state) && $blocks->blockName($state) !== "minecraft:snow"){
			return false;
		}
		return $c->survival->canSurvive($level, $blocks->defaultOf("snow"), $x, $y, $z);
	}
}
