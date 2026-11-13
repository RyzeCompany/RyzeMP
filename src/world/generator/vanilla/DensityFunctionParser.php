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

use pocketmine\world\generator\vanilla\density\BinaryFunction;
use pocketmine\world\generator\vanilla\density\BlendedNoiseFunction;
use pocketmine\world\generator\vanilla\density\CacheFunction;
use pocketmine\world\generator\vanilla\density\ClampFunction;
use pocketmine\world\generator\vanilla\density\ConstantFunction;
use pocketmine\world\generator\vanilla\density\CubicSpline;
use pocketmine\world\generator\vanilla\density\DensityFunction;
use pocketmine\world\generator\vanilla\density\DistanceToPointFunction;
use pocketmine\world\generator\vanilla\density\EndIslandsFunction;
use pocketmine\world\generator\vanilla\density\FindTopSurfaceFunction;
use pocketmine\world\generator\vanilla\density\GradientFunction;
use pocketmine\world\generator\vanilla\density\InterpolatedFunction;
use pocketmine\world\generator\vanilla\density\IntervalSelectFunction;
use pocketmine\world\generator\vanilla\density\LerpFunction;
use pocketmine\world\generator\vanilla\density\NamedFunction;
use pocketmine\world\generator\vanilla\density\NoiseFunction;
use pocketmine\world\generator\vanilla\density\RangeChoiceFunction;
use pocketmine\world\generator\vanilla\density\ShiftNoiseFunction;
use pocketmine\world\generator\vanilla\density\SliceFunction;
use pocketmine\world\generator\vanilla\density\SplineFunction;
use pocketmine\world\generator\vanilla\density\UnaryFunction;
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\math\Int64;
use pocketmine\world\generator\vanilla\noise\BlendedNoise;
use pocketmine\world\generator\vanilla\noise\NoiseStack;
use pocketmine\world\generator\vanilla\noise\NormalNoiseParameters;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\random\PositionalRandomFactory;
use pocketmine\world\generator\vanilla\random\RandomSource;
use function array_key_exists;
use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use const INF;

/**
 * Builds a seed-bound density function graph from vanilla JSON (port of deepslate's DensityFunction.fromJson +
 * RandomState noise binding, MIT).
 *
 * Noise settings with a legacy random source (Nether, End) bind some noises differently: the base 3D noise is seeded
 * with a java.util.Random of the world seed, the Nether biome noises use the legacy octave initialization, and the
 * End islands get the world seed.
 */
final class DensityFunctionParser{
	/**
	 * Noises created with the legacy Nether biome initialization in legacy worlds, with the offset added to the world
	 * seed for their random.
	 */
	public const LEGACY_NETHER_BIOME_NOISES = [
		"minecraft:temperature" => 0,
		"minecraft:vegetation" => 1,
		"minecraft:nether/temperature" => 0,
		"minecraft:nether/vegetation" => 1,
	];

	/** @phpstan-var array<string, NamedFunction> */
	private array $named = [];
	/** @phpstan-var array<string, NoiseStack> */
	private array $noises = [];
	/** @phpstan-var array<string, true> */
	private array $resolving = [];

	/**
	 * @param int|null $legacySeed world seed when the noise settings use a legacy random source, null otherwise
	 */
	public function __construct(
		private WorldgenData $data,
		private PositionalRandomFactory $random,
		private ?int $legacySeed = null
	){}

	public function getNoise(string $id) : NoiseStack{
		$key = "minecraft:" . WorldgenData::stripNamespace($id);
		if(!isset($this->noises[$key])){
			$parameters = self::noiseParameters($this->data->noise($key));
			$legacyOffset = $this->legacySeed !== null ? (self::LEGACY_NETHER_BIOME_NOISES[$key] ?? null) : null;
			$this->noises[$key] = $legacyOffset !== null && $this->legacySeed !== null ?
				$parameters->createLegacyNetherBiome(new LegacyRandom(Int64::add($this->legacySeed, $legacyOffset))) :
				$parameters->create($this->random->fromHashOf($key));
		}
		return $this->noises[$key];
	}

	/**
	 * Random of the "old blended noise" (base 3D terrain noise).
	 */
	private function terrainRandom() : RandomSource{
		return $this->legacySeed !== null ? new LegacyRandom($this->legacySeed) : $this->random->fromHashOf("minecraft:terrain");
	}

	/**
	 * @param mixed[] $json
	 */
	public static function noiseParameters(array $json) : NormalNoiseParameters{
		$normalize = $json["normalize"] ?? null;
		$mode = match(true){
			$normalize === null, $normalize === true, $normalize === "enabled" => NormalNoiseParameters::NORMALIZE_ENABLED,
			$normalize === false, $normalize === "disabled" => NormalNoiseParameters::NORMALIZE_DISABLED,
			$normalize === "legacy" => NormalNoiseParameters::NORMALIZE_LEGACY,
			default => throw new \RuntimeException("Unknown noise normalization")
		};
		$modifiers = isset($json["amplitude_modifiers"]) ? WorldgenData::floatList($json, "amplitude_modifiers") : [];
		return new NormalNoiseParameters(
			WorldgenData::float($json, "base_amplitude", 1.0),
			WorldgenData::int($json, "base_octave", 0),
			WorldgenData::int($json, "octave_count", 1),
			$mode,
			$modifiers
		);
	}

	public function named(string $id) : NamedFunction{
		$key = "minecraft:" . WorldgenData::stripNamespace($id);
		if(isset($this->named[$key])){
			return $this->named[$key];
		}
		if(isset($this->resolving[$key])){
			throw new \RuntimeException("Recursive density function reference $key");
		}
		$this->resolving[$key] = true;
		$function = $this->parse($this->data->densityFunction($key));
		unset($this->resolving[$key]);
		return $this->named[$key] = new NamedFunction($key, $function);
	}

	public function parse(mixed $json) : DensityFunction{
		if(is_string($json)){
			return $this->named($json);
		}
		if(is_int($json) || is_float($json)){
			return new ConstantFunction((float) $json);
		}
		if(!is_array($json)){
			throw new \RuntimeException("Invalid density function");
		}
		$type = WorldgenData::stripNamespace(WorldgenData::string($json, "type"));
		$input = fn(string ...$keys) : DensityFunction => $this->parse(self::first($json, $keys));
		switch($type){
			case "constant":
				return new ConstantFunction(WorldgenData::float($json, isset($json["value"]) ? "value" : "argument"));
			case "blend_alpha":
				return new ConstantFunction(1.0, 0.0, 1.0);
			case "blend_offset":
			case "beardifier":
				return new ConstantFunction(0.0, -INF, INF);
			case "blend_density":
				return $input("input", "argument");
			case "old_blended_noise":
				return new BlendedNoiseFunction(new BlendedNoise(
					$this->terrainRandom(),
					WorldgenData::float($json, "xz_scale", 1.0),
					WorldgenData::float($json, "y_scale", 1.0),
					WorldgenData::float($json, "xz_factor", 80.0),
					WorldgenData::float($json, "y_factor", 160.0),
					WorldgenData::float($json, "smear_scale_multiplier", 8.0)
				));
			case "interpolated":
				return new InterpolatedFunction($input("input", "argument"), WorldgenData::int($json, "cell_size_xz", 4), WorldgenData::int($json, "cell_size_y", 4));
			case "cache":
			case "flat_cache":
			case "cache_2d":
			case "cache_once":
			case "cache_all_in_cell":
				return new CacheFunction($input("input", "argument"));
			case "noise":
				$noiseName = $this->noiseName(self::first($json, ["noise"]));
				return new NoiseFunction(
					$noiseName,
					$this->getNoise($noiseName),
					WorldgenData::float($json, "xz_scale", 1.0),
					WorldgenData::float($json, "y_scale", 1.0),
					isset($json["shift_x"]) ? $this->parse($json["shift_x"]) : null,
					isset($json["shift_y"]) ? $this->parse($json["shift_y"]) : null,
					isset($json["shift_z"]) ? $this->parse($json["shift_z"]) : null
				);
			case "shift_a":
			case "shift_b":
			case "shift":
				$noiseName = $this->noiseName(self::first($json, ["noise", "argument"]));
				return new ShiftNoiseFunction(
					$type === "shift_a" ? ShiftNoiseFunction::MODE_A : ($type === "shift_b" ? ShiftNoiseFunction::MODE_B : ShiftNoiseFunction::MODE_XYZ),
					$noiseName,
					$this->getNoise($noiseName)
				);
			case "find_top_surface":
				return new FindTopSurfaceFunction(
					$input("density"),
					$input("upper_bound"),
					WorldgenData::int($json, "lower_bound", 0),
					WorldgenData::int($json, "cell_height", 1)
				);
			case "range_choice":
				return new RangeChoiceFunction(
					$input("input"),
					WorldgenData::float($json, "min_inclusive", 0.0),
					WorldgenData::float($json, "max_exclusive", 1.0),
					$input("when_in_range"),
					$input("when_out_of_range")
				);
			case "clamp":
				return new ClampFunction($input("input"), WorldgenData::float($json, "min", 0.0), WorldgenData::float($json, "max", 1.0));
			case "interval_select":
				$functions = [];
				foreach(WorldgenData::array($json, "functions") as $f){
					$functions[] = $this->parse($f);
				}
				return new IntervalSelectFunction($input("input"), WorldgenData::floatList($json, "thresholds"), $functions);
			case "abs":
			case "square":
			case "cube":
			case "half_negative":
			case "quarter_negative":
			case "squeeze":
			case "negate":
			case "sqrt":
			case "log":
			case "sign":
			case "reciprocal":
				return new UnaryFunction($type, $input("input", "argument"));
			case "add":
			case "sub":
			case "mul":
			case "div":
			case "min":
			case "max":
				return new BinaryFunction($type, $input("left", "argument1"), $input("right", "argument2"));
			case "spline":
				$spline = $this->parseSpline(self::first($json, ["spline"]));
				return $spline instanceof CubicSpline ? new SplineFunction($spline) : new ConstantFunction($spline);
			case "gradient":
				return new GradientFunction(
					match(WorldgenData::string($json, "axis", "y")){
						"x" => GradientFunction::AXIS_X,
						"y" => GradientFunction::AXIS_Y,
						"z" => GradientFunction::AXIS_Z,
						default => throw new \RuntimeException("Unknown gradient axis")
					},
					match(WorldgenData::string($json, "tiling", "clamp_to_edge")){
						"clamp_to_edge" => GradientFunction::TILING_CLAMP,
						"repeat" => GradientFunction::TILING_REPEAT,
						"mirrored_repeat" => GradientFunction::TILING_MIRRORED,
						default => throw new \RuntimeException("Unknown gradient tiling")
					},
					WorldgenData::int($json, "from_coordinate", 0),
					WorldgenData::int($json, "to_coordinate", 1),
					WorldgenData::float($json, "from_value", 0.0),
					WorldgenData::float($json, "to_value", 1.0)
				);
			case "y_clamped_gradient":
				return new GradientFunction(
					GradientFunction::AXIS_Y,
					GradientFunction::TILING_CLAMP,
					WorldgenData::int($json, "from_y"),
					WorldgenData::int($json, "to_y"),
					WorldgenData::float($json, "from_value"),
					WorldgenData::float($json, "to_value")
				);
			case "lerp":
				return new LerpFunction($input("alpha"), $input("first"), $input("second"));
			case "slice":
				return new SliceFunction(
					match(WorldgenData::string($json, "axis")){
						"x" => SliceFunction::AXIS_X,
						"y" => SliceFunction::AXIS_Y,
						"z" => SliceFunction::AXIS_Z,
						default => throw new \RuntimeException("Unknown slice axis")
					},
					WorldgenData::int($json, "coordinate", 0),
					$input("input", "argument")
				);
			case "distance_to_point":
				$point = WorldgenData::floatList($json, "point");
				if(count($point) !== 3){
					throw new \RuntimeException("Invalid distance_to_point point");
				}
				return new DistanceToPointFunction($point[0], $point[1], $point[2], match(WorldgenData::string($json, "metric", "euclidean")){
					"euclidean" => DistanceToPointFunction::METRIC_EUCLIDEAN,
					"euclidean_squared" => DistanceToPointFunction::METRIC_EUCLIDEAN_SQUARED,
					"manhattan" => DistanceToPointFunction::METRIC_MANHATTAN,
					"chebyshev" => DistanceToPointFunction::METRIC_CHEBYSHEV,
					default => throw new \RuntimeException("Unknown distance metric")
				});
			case "end_outer_islands":
			case "end_islands":
				return new EndIslandsFunction($this->legacySeed ?? 0);
			default:
				throw new \RuntimeException("Unsupported density function type \"$type\"");
		}
	}

	private function noiseName(mixed $json) : string{
		if(!is_string($json)){
			throw new \RuntimeException("Inline noise definitions are not supported");
		}
		return "minecraft:" . WorldgenData::stripNamespace($json);
	}

	/**
	 * @param mixed[]  $json
	 * @param string[] $keys
	 */
	private static function first(array $json, array $keys) : mixed{
		foreach($keys as $key){
			if(array_key_exists($key, $json)){
				return $json[$key];
			}
		}
		throw new \RuntimeException("Missing field " . $keys[0]);
	}

	private function parseSpline(mixed $json) : CubicSpline|float{
		if(is_int($json) || is_float($json)){
			return CubicSpline::$vanillaFloats ? FloatMath::fround((float) $json) : (float) $json;
		}
		if(!is_array($json)){
			throw new \RuntimeException("Invalid spline");
		}
		$coordinate = $this->parse(self::first($json, ["coordinate"]));
		$locations = [];
		$values = [];
		$derivatives = [];
		foreach(WorldgenData::array($json, "points") as $point){
			if(!is_array($point)){
				throw new \RuntimeException("Invalid spline point");
			}
			$locations[] = FloatMath::fround(WorldgenData::float($point, "location", 0.0));
			$values[] = $this->parseSpline(self::first($point, ["value"]));
			$derivatives[] = FloatMath::fround(WorldgenData::float($point, "derivative", 0.0));
		}
		if(count($locations) === 0){
			return 0.0;
		}
		return new CubicSpline($coordinate, $locations, $values, $derivatives);
	}
}
