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

namespace pocketmine\world\generator\vanilla\noise;

use pocketmine\world\generator\vanilla\random\RandomSource;
use function abs;
use function count;
use function max;
use function min;
use function sqrt;
use const INF;

/**
 * Parameters of a "normal noise" (two offset octave stacks), as found in worldgen/noise/*.json
 * (ported from misode/deepslate NormalNoise, MIT).
 */
final class NormalNoiseParameters{
	public const NORMALIZE_DISABLED = 0;
	public const NORMALIZE_ENABLED = 1;
	public const NORMALIZE_LEGACY = 2;

	private const INPUT_FACTOR = 1.0181268882175227;
	private const TARGET_DEVIATION = 0.3333333333333333;

	/**
	 * @var array{int, float, float}[]
	 * @phpstan-var list<array{int, float, float}> index, frequency, amplitude
	 */
	private array $octaves = [];
	private float $normalizationFactor;

	/**
	 * @param float[] $amplitudeModifiers
	 * @phpstan-param list<float> $amplitudeModifiers
	 */
	public function __construct(
		public readonly float $baseAmplitude,
		public readonly int $baseOctave,
		public readonly int $octaveCount,
		public readonly int $normalize,
		public readonly array $amplitudeModifiers
	){
		$frequency = 2.0 ** $baseOctave;
		$amplitude = $baseAmplitude;
		if($normalize !== self::NORMALIZE_DISABLED){
			$amplitude *= (0.5 ** -($octaveCount - 1)) / ((0.5 ** -$octaveCount) - 1);
		}
		for($i = 0; $i < $octaveCount; ++$i){
			$modifier = $amplitudeModifiers[$i] ?? 1.0;
			if($modifier !== 0.0){
				$this->octaves[] = [$baseOctave + $i, $frequency, $amplitude * $modifier];
			}
			$frequency *= 2;
			$amplitude *= 0.5;
		}

		$targetAmplitude = 0.0;
		$variance = 0.0;
		foreach($this->octaves as [, , $a]){
			$targetAmplitude += abs($a);
			$deviation = 0.2702247831245211 * abs($a);
			$variance += $deviation * $deviation;
		}
		$inputDeviation = sqrt($variance);
		$factor = $inputDeviation === 0.0 ? 0.0 : ($targetAmplitude * self::TARGET_DEVIATION) / ($inputDeviation * sqrt(2));
		if($normalize === self::NORMALIZE_LEGACY && $factor !== 0.0){
			$min = INF;
			$max = -INF;
			for($i = 0; $i < $octaveCount; ++$i){
				if(($amplitudeModifiers[$i] ?? 1.0) !== 0.0){
					$min = min($min, $i);
					$max = max($max, $i);
				}
			}
			$parityExpectedDeviation = 0.1 * (1 + 1 / ($max - $min + 1));
			$factor = $baseAmplitude * 0.5 * self::TARGET_DEVIATION / $parityExpectedDeviation;
		}
		$this->normalizationFactor = $factor;
	}

	/**
	 * Octaves with a non-zero amplitude: [octave index, frequency, amplitude].
	 * @phpstan-return list<array{int, float, float}>
	 */
	public function getOctaves() : array{
		return $this->octaves;
	}

	public function getNormalizationFactor() : float{
		return $this->normalizationFactor;
	}

	/**
	 * Legacy initialization of the Nether biome noises (worlds with a legacy random source): each stack is made of
	 * sequentially seeded octaves, skipping the unused ones (octave 0 down to the first octave), from one random.
	 */
	public function createLegacyNetherBiome(RandomSource $random) : NoiseStack{
		$amplitudes = [];
		for($i = 0; $i < $this->octaveCount; ++$i){
			$amplitudes[] = $this->amplitudeModifiers[$i] ?? 1.0;
		}
		$first = self::legacyLayers($random, $this->baseOctave, $amplitudes);
		$second = self::legacyLayers($random, $this->baseOctave, $amplitudes);
		$valueFactor = $this->normalizationFactor * $this->baseAmplitude;
		$stack = new NoiseStack();
		foreach($first as [$noise, $frequency, $amplitude]){
			$stack->addLayer($noise, $frequency, $amplitude * $valueFactor);
		}
		foreach($second as [$noise, $frequency, $amplitude]){
			$stack->addLayer($noise, $frequency * self::INPUT_FACTOR, $amplitude * $valueFactor);
		}
		return $stack;
	}

	/**
	 * @param float[] $amplitudes
	 * @phpstan-param list<float> $amplitudes
	 * @return array{ImprovedNoise, float, float}[]
	 * @phpstan-return list<array{ImprovedNoise, float, float}>
	 */
	private static function legacyLayers(RandomSource $random, int $firstOctave, array $amplitudes) : array{
		$octaves = count($amplitudes);
		$zeroOctaveIndex = -$firstOctave;
		/** @var (ImprovedNoise|null)[] $levels */
		$levels = [];
		$zeroOctave = new ImprovedNoise($random);
		if($zeroOctaveIndex >= 0 && $zeroOctaveIndex < $octaves && $amplitudes[$zeroOctaveIndex] !== 0.0){
			$levels[$zeroOctaveIndex] = $zeroOctave;
		}
		for($i = $zeroOctaveIndex - 1; $i >= 0; --$i){
			if($i < $octaves && $amplitudes[$i] !== 0.0){
				$levels[$i] = new ImprovedNoise($random);
			}else{
				$random->consume(262);
			}
		}
		$frequency = 2.0 ** -$zeroOctaveIndex;
		$amplitude = (2.0 ** ($octaves - 1)) / ((2.0 ** $octaves) - 1);
		$layers = [];
		for($i = 0; $i < $octaves; ++$i){
			if(isset($levels[$i])){
				$layers[] = [$levels[$i], $frequency, $amplitude * $amplitudes[$i]];
			}
			$frequency *= 2;
			$amplitude /= 2;
		}
		return $layers;
	}

	public function create(RandomSource $random) : NoiseStack{
		$first = $random->forkPositional();
		$second = $random->forkPositional();
		$stack = new NoiseStack();
		foreach($this->octaves as [$index, $frequency, $amplitude]){
			$name = "octave_" . $index;
			$a = new ImprovedNoise($first->fromHashOf($name));
			$b = new ImprovedNoise($second->fromHashOf($name));
			$valueFactor = $this->normalizationFactor * $amplitude;
			$stack->addLayer($a, $frequency, $valueFactor);
			$stack->addLayer($b, $frequency * self::INPUT_FACTOR, $valueFactor);
		}
		return $stack;
	}
}
