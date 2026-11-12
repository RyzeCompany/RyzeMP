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

namespace pocketmine\world\generator\vanilla\compiler;

use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Utils;
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
use pocketmine\world\generator\vanilla\noise\BlendedNoise;
use pocketmine\world\generator\vanilla\noise\ImprovedNoise;
use pocketmine\world\generator\vanilla\noise\NoiseStack;
use function array_map;
use function count;
use function implode;
use function is_finite;
use function is_nan;
use function preg_replace;
use function rtrim;
use function spl_object_id;
use function sprintf;
use function str_contains;
use function str_repeat;
use function str_replace;

/**
 * Compiles seed-bound density function graphs into straight-line PHP methods (no objects or closures in the
 * evaluation path), so that the JIT can turn them into native float code.
 *
 * The generated source only depends on the shape of the graph and on seed-independent constants (frequencies,
 * amplitudes, spline points...). Seed-dependent data (noise permutations and offsets) is read from properties
 * filled by the generated constructor, so one compiled file serves every seed.
 *
 * Two forms are generated:
 *  - scalar: `name(float $x, float $y, float $z) : float`. Named functions and caches become memoized methods
 *    (per (x, z) column when the value does not depend on y, like vanilla's flat cache; per position otherwise);
 *  - batch: `name(float $x, float $z, array $Y) : array`, evaluating a whole column of cell corners. Every node is
 *    a short loop over the active points, and lazy nodes split the point list instead of branching. This is the
 *    shape PHP's JIT compiles best, and it lets x/z dependent work be done once per column.
 * Both keep vanilla's lazy evaluation and short-circuits (range_choice, interval_select, lerp, mul, min/max), so
 * they compute exactly the same values as the interpreted graph (checked by tests).
 */
final class DensityCompiler{
	/** @phpstan-var array<int, int> spl_object_id(NoiseStack) => index */
	private array $noiseIds = [];
	/**
	 * @var NoiseStack[]
	 * @phpstan-var list<NoiseStack>
	 */
	private array $noises = [];
	/** @phpstan-var array<int, int> */
	private array $blendedIds = [];
	/**
	 * @var BlendedNoise[]
	 * @phpstan-var list<BlendedNoise>
	 */
	private array $blendeds = [];
	/**
	 * Functions evaluated through the interpreter (fallback for nodes that make no sense in compiled code).
	 * @var DensityFunction[]
	 * @phpstan-var list<DensityFunction>
	 */
	private array $fallbacks = [];
	/**
	 * Generated methods (source).
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $methods = [];
	/**
	 * Extra properties needed by the methods (declarations).
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $properties = [];
	/** @phpstan-var array<int, string> root object id => method name, for sub-roots (find_top_surface) */
	private array $subRoots = [];
	private int $methodCounter = 0;
	/** @phpstan-var array<string, true> */
	private array $declaredOctaves = [];
	/** @phpstan-var array<string, array{string, string, string, ?string}> */
	private array $loopStacks = [];
	/**
	 * Generate octave stacks unrolled (one block of code per octave) instead of as loops. Unrolled code is faster
	 * without JIT, loops are faster with the tracing JIT (long linear traces get aborted).
	 */
	public static bool $unrollOctaves = false;
	/**
	 * Inline the octave sampling code instead of calling ImprovedNoise::sampleRaw(). PHP 8.4's tracing JIT fails to
	 * compile ("JIT compilation error") loops that contain the inlined sampler, while it compiles (and inlines) the
	 * call just fine; the function JIT prefers inlined code.
	 */
	public static bool $inlineOctaves = false;

	// ---- per-method state ----
	private int $temp = 0;
	/** @phpstan-var array<int, string> interpolated object id => variable holding the interpolated value */
	private array $channelVars = [];
	/** @phpstan-var array<int, bool> */
	private array $containsChannelCache = [];
	/** @phpstan-var array<int, string> inner node id => memo method name */
	private array $memoMethods = [];
	/** @phpstan-var array<int, string> spline object id => method name */
	private array $splineMethods = [];
	/** @phpstan-var array<int, string> node id => plain (non-memoized) method name */
	private array $plainMethods = [];

	/**
	 * Compiles $root into a public method $name(float $x, float $y, float $z) : float.
	 */
	public function compileRoot(DensityFunction $root, string $name) : void{
		$this->methods[] = $this->buildMethod($root, $name, true);
	}

	/**
	 * Compiles an expression into a code fragment for inlining into a hand-written loop: returns [statements, result].
	 * The fragment may use $x, $y, $z and the given channel variables (values of interpolated functions).
	 *
	 * @param string[] $channelVars
	 * @phpstan-param array<int, string> $channelVars
	 * @return string[]
	 * @phpstan-return array{string, string}
	 */
	public function compileInline(DensityFunction $root, array $channelVars, string $prefix) : array{
		$savedTemp = $this->temp;
		$this->temp = 0;
		$this->channelVars = $channelVars;
		$this->containsChannelCache = [];
		$stmts = [];
		$result = $this->expr($root, $stmts);
		$this->channelVars = [];
		$this->containsChannelCache = [];
		$this->temp = $savedTemp;
		$code = implode("\n", $stmts);
		//make temporaries unique to the fragment
		$rename = fn(string $s) : string => preg_replace('/\$t(\d+)\b/', '$' . $prefix . 't$1', $s) ?? throw new AssumptionFailedError("preg_replace failed");
		return [$rename($code), $rename($result)];
	}

	/**
	 * Builds a method computing $root. Saves and restores the per-method state, so it can be called while another
	 * method is being compiled.
	 */
	private function buildMethod(DensityFunction $root, string $name, bool $public, ?string $memoKey = null) : string{
		$savedTemp = $this->temp;
		$savedChannels = $this->channelVars;
		$savedCache = $this->containsChannelCache;
		$this->temp = 0;
		$this->channelVars = [];
		$this->containsChannelCache = [];

		$stmts = [];
		$result = $this->expr($root, $stmts);

		$src = "\t" . ($public ? "public" : "private") . " function $name(float \$x, float \$y, float \$z) : float{\n";
		if($memoKey !== null){
			$flat = $memoKey === "xz";
			$this->properties[] = "\tprivate float \$mx$name = NAN;\n\tprivate float \$mz$name = NAN;\n\tprivate float \$mv$name = 0.0;" . ($flat ? "" : "\n\tprivate float \$my$name = NAN;");
			$src .= "\t\tif(\$x === \$this->mx$name && \$z === \$this->mz$name" . ($flat ? "" : " && \$y === \$this->my$name") . "){\n\t\t\treturn \$this->mv$name;\n\t\t}\n";
			$src .= self::indent(implode("\n", $stmts), 2) . "\n";
			$src .= "\t\t\$this->mx$name = \$x;\n\t\t\$this->mz$name = \$z;\n" . ($flat ? "" : "\t\t\$this->my$name = \$y;\n");
			$src .= "\t\treturn \$this->mv$name = $result;\n\t}\n";
		}else{
			$src .= self::indent(implode("\n", $stmts), 2) . "\n";
			$src .= "\t\treturn $result;\n\t}\n";
		}

		$this->temp = $savedTemp;
		$this->channelVars = $savedChannels;
		$this->containsChannelCache = $savedCache;
		return $src;
	}

	private static function indent(string $code, int $level) : string{
		if($code === ""){
			return "";
		}
		$pad = str_repeat("\t", $level);
		return $pad . str_replace("\n", "\n" . $pad, $code);
	}

	/** Whether the subtree uses a channel variable (so it must be inlined in the chunk loop). */
	private function containsChannel(DensityFunction $f) : bool{
		if(count($this->channelVars) === 0){
			return false;
		}
		$id = spl_object_id($f);
		if(isset($this->containsChannelCache[$id])){
			return $this->containsChannelCache[$id];
		}
		$result = isset($this->channelVars[$id]);
		if(!$result){
			foreach($f->children() as $child){
				if($this->containsChannel($child)){
					$result = true;
					break;
				}
			}
		}
		return $this->containsChannelCache[$id] = $result;
	}

	private static function isTrivial(DensityFunction $f) : bool{
		while($f instanceof NamedFunction || $f instanceof CacheFunction){
			$f = $f->input;
		}
		return $f instanceof ConstantFunction || $f instanceof GradientFunction;
	}

	/**
	 * Returns the name of a memoized method computing $f's input: memoized per column when the value does not depend
	 * on y (vanilla flat cache), else per position (vanilla cache_once). Shared by every user of the function.
	 */
	private function memoMethod(DensityFunction $f) : string{
		$inner = $f;
		while($inner instanceof NamedFunction || $inner instanceof CacheFunction){
			$inner = $inner->input;
		}
		$id = spl_object_id($inner);
		if(isset($this->memoMethods[$id])){
			return $this->memoMethods[$id];
		}
		$name = "memo" . ($this->methodCounter++);
		$this->memoMethods[$id] = $name;
		$this->methods[] = $this->buildMethod($inner, $name, false, $inner->dependsOnY() ? "xyz" : "xz");
		return $name;
	}

	/**
	 * Expression for an operand that may be emitted on several branches: big subtrees become their own method so the
	 * code is not duplicated.
	 * @param string[] $stmts
	 * @phpstan-param list<string> $stmts
	 */
	private function operand(DensityFunction $f, array &$stmts) : string{
		if($f instanceof ConstantFunction || isset($this->channelVars[spl_object_id($f)]) || $f instanceof NamedFunction || $f instanceof CacheFunction || $this->containsChannel($f)){
			return $this->expr($f, $stmts);
		}
		$id = spl_object_id($f);
		if(!isset($this->plainMethods[$id])){
			$name = "part" . ($this->methodCounter++);
			$this->plainMethods[$id] = $name;
			$this->methods[] = $this->buildMethod($f, $name, false);
		}
		$t = $this->newTemp();
		$stmts[] = "$t = \$this->{$this->plainMethods[$id]}(\$x, \$y, \$z);";
		return $t;
	}

	private function newTemp() : string{
		return '$t' . ($this->temp++);
	}

	public static function float(float $v) : string{
		if(is_nan($v)){
			return "NAN";
		}
		if(!is_finite($v)){
			return $v > 0 ? "INF" : "-INF";
		}
		$s = sprintf("%.17g", $v);
		if(!str_contains($s, ".") && !str_contains($s, "e") && !str_contains($s, "E")){
			$s .= ".0";
		}
		return "(" . $s . ")";
	}

	/**
	 * Emits statements computing $f into $stmts and returns a PHP expression for its value
	 * (a variable or a literal; never an expression with side effects).
	 * @param string[] $stmts
	 * @phpstan-param list<string> $stmts
	 */
	private function expr(DensityFunction $f, array &$stmts) : string{
		$id = spl_object_id($f);
		if(isset($this->channelVars[$id])){
			return $this->channelVars[$id];
		}
		if($f instanceof ConstantFunction){
			return self::float($f->value);
		}
		if($f instanceof NamedFunction || $f instanceof CacheFunction){
			if(self::isTrivial($f) || $this->containsChannel($f)){
				return $this->expr($f->input, $stmts);
			}
			$method = $this->memoMethod($f);
			$t = $this->newTemp();
			$stmts[] = "$t = \$this->$method(\$x, \$y, \$z);";
			return $t;
		}
		if($f instanceof NoiseFunction){
			$sx = $f->shiftX !== null ? $this->expr($f->shiftX, $stmts) : null;
			$sy = $f->shiftY !== null ? $this->expr($f->shiftY, $stmts) : null;
			$sz = $f->shiftZ !== null ? $this->expr($f->shiftZ, $stmts) : null;
			$n = $this->noiseMethod($f->noise);
			$cx = $this->scaled('$x', $f->xzScale, $sx);
			$cy = $this->scaled('$y', $f->yScale, $sy);
			$cz = $this->scaled('$z', $f->xzScale, $sz);
			$t = $this->newTemp();
			$stmts[] = "$t = \$this->$n($cx, $cy, $cz);";
			return $t;
		}
		if($f instanceof ShiftNoiseFunction){
			$n = $this->noiseMethod($f->noise);
			$t = $this->newTemp();
			$stmts[] = match($f->mode){
				ShiftNoiseFunction::MODE_A => "$t = \$this->$n(\$x * 0.25, 0.0, \$z * 0.25) * 4.0;",
				ShiftNoiseFunction::MODE_B => "$t = \$this->$n(\$z * 0.25, \$x * 0.25, 0.0) * 4.0;",
				ShiftNoiseFunction::MODE_XYZ => "$t = \$this->$n(\$x * 0.25, \$y * 0.25, \$z * 0.25) * 4.0;",
			};
			return $t;
		}
		if($f instanceof BlendedNoiseFunction){
			$n = $this->blendedMethod($f->noise);
			$t = $this->newTemp();
			$stmts[] = "$t = \$this->$n(\$x, \$y, \$z);";
			return $t;
		}
		if($f instanceof UnaryFunction){
			$a = $this->expr($f->input, $stmts);
			$t = $this->newTemp();
			$stmts[] = match($f->op){
				UnaryFunction::ABS => "$t = abs($a);",
				UnaryFunction::SQUARE => "$t = $a * $a;",
				UnaryFunction::CUBE => "$t = $a * $a * $a;",
				UnaryFunction::HALF_NEGATIVE => "$t = $a > 0 ? $a : $a * 0.5;",
				UnaryFunction::QUARTER_NEGATIVE => "$t = $a > 0 ? $a : $a * 0.25;",
				UnaryFunction::SQUEEZE => "$t = $a < -1.0 ? -1.0 : ($a > 1.0 ? 1.0 : $a);\n$t = $t / 2 - $t * $t * $t / 24;",
				UnaryFunction::NEGATE => "$t = -$a;",
				UnaryFunction::SQRT => "$t = sqrt($a);",
				UnaryFunction::LOG => "$t = log($a);",
				UnaryFunction::SIGN => "$t = $a > 0 ? 1.0 : ($a < 0 ? -1.0 : $a);",
				UnaryFunction::RECIPROCAL => "$t = 1 / $a;",
				default => throw new \InvalidArgumentException("Unknown unary op")
			};
			return $t;
		}
		if($f instanceof BinaryFunction){
			$a = $this->expr($f->left, $stmts);
			$t = $this->newTemp();
			$inner = [];
			switch($f->op){
				case BinaryFunction::ADD:
				case BinaryFunction::SUB:
					$b = $this->expr($f->right, $stmts);
					$stmts[] = "$t = $a " . ($f->op === BinaryFunction::ADD ? "+" : "-") . " $b;";
					return $t;
				case BinaryFunction::MUL:
				case BinaryFunction::DIV:
					$b = $this->expr($f->right, $inner);
					$op = $f->op === BinaryFunction::MUL ? "*" : "/";
					if($f->right instanceof ConstantFunction || $f->left instanceof ConstantFunction){
						//vanilla turns these into a plain multiply/divide (no short-circuit)
						foreach($inner as $s){
							$stmts[] = $s;
						}
						$stmts[] = "$t = $a $op $b;";
						return $t;
					}
					$stmts[] = "if($a == 0){\n\t$t = 0.0;\n}else{\n" . self::indent(implode("\n", $inner), 1) . ($inner !== [] ? "\n" : "") . "\t$t = $a $op $b;\n}";
					return $t;
				case BinaryFunction::MIN:
				case BinaryFunction::MAX:
					$b = $this->expr($f->right, $inner);
					$isMin = $f->op === BinaryFunction::MIN;
					$bound = $isMin ? $f->right->minValue : $f->right->maxValue;
					$skip = $isMin ? "$a < " . self::float($bound) : "$a > " . self::float($bound);
					$pick = $isMin ? "$t = $a < $b ? $a : $b;" : "$t = $a > $b ? $a : $b;";
					if(!is_finite($bound)){
						foreach($inner as $s){
							$stmts[] = $s;
						}
						$stmts[] = $pick;
						return $t;
					}
					$stmts[] = "if($skip){\n\t$t = $a;\n}else{\n" . self::indent(implode("\n", $inner), 1) . ($inner !== [] ? "\n" : "") . "\t$pick\n}";
					return $t;
			}
			throw new \InvalidArgumentException("Unknown binary op");
		}
		if($f instanceof RangeChoiceFunction){
			$v = $this->expr($f->input, $stmts);
			$t = $this->newTemp();
			$in = [];
			$a = $this->expr($f->whenInRange, $in);
			$out = [];
			$b = $this->expr($f->whenOutOfRange, $out);
			$stmts[] = "if($v >= " . self::float($f->minInclusive) . " && $v < " . self::float($f->maxExclusive) . "){\n" .
				self::indent(implode("\n", $in), 1) . ($in !== [] ? "\n" : "") . "\t$t = $a;\n}else{\n" .
				self::indent(implode("\n", $out), 1) . ($out !== [] ? "\n" : "") . "\t$t = $b;\n}";
			return $t;
		}
		if($f instanceof ClampFunction){
			$v = $this->expr($f->input, $stmts);
			$t = $this->newTemp();
			$min = self::float($f->min);
			$max = self::float($f->max);
			$stmts[] = "$t = $v < $min ? $min : ($v > $max ? $max : $v);";
			return $t;
		}
		if($f instanceof GradientFunction){
			$axis = match($f->axis){
				GradientFunction::AXIS_X => '$x',
				GradientFunction::AXIS_Y => '$y',
				GradientFunction::AXIS_Z => '$z',
			};
			$t = $this->newTemp();
			$range = (float) ($f->toCoordinate - $f->fromCoordinate);
			$from = self::float($f->fromValue);
			$to = self::float($f->toValue);
			if($f->tiling === GradientFunction::TILING_CLAMP){
				$stmts[] = "$t = ($axis - " . self::float((float) $f->fromCoordinate) . ") / " . self::float($range) . ";\n" .
					"$t = $t < 0 ? $from : ($t > 1 ? $to : $from + $t * ($to - $from));";
				return $t;
			}
			$k = count($this->fallbacks);
			$this->fallbacks[] = $f;
			$stmts[] = "$t = \$this->fallback[$k]->compute(\$x, \$y, \$z);";
			return $t;
		}
		if($f instanceof LerpFunction){
			$a = $this->expr($f->alpha, $stmts);
			$t = $this->newTemp();
			$first = [];
			$fv = $this->operand($f->first, $first);
			$second = [];
			$sv = $this->operand($f->second, $second);
			$u = $this->newTemp();
			$stmts[] = "if($a == 0){\n" . self::indent(implode("\n", $first), 1) . ($first !== [] ? "\n" : "") . "\t$t = $fv;\n" .
				"}elseif($a == 1){\n" . self::indent(implode("\n", $second), 1) . ($second !== [] ? "\n" : "") . "\t$t = $sv;\n" .
				"}else{\n" . self::indent(implode("\n", $first), 1) . ($first !== [] ? "\n" : "") . self::indent(implode("\n", $second), 1) . ($second !== [] ? "\n" : "") .
				"\t" . self::flerp($t, $u, $a, $fv, $sv) . "\n}";
			return $t;
		}
		if($f instanceof IntervalSelectFunction){
			$v = $this->expr($f->input, $stmts);
			$t = $this->newTemp();
			$code = "";
			foreach($f->functions as $i => $fn){
				$inner = [];
				$r = $this->expr($fn, $inner);
				$body = self::indent(implode("\n", $inner), 1) . ($inner !== [] ? "\n" : "") . "\t$t = $r;\n";
				if($i === 0){
					$code .= "if($v < " . self::float($f->thresholds[0]) . "){\n" . $body;
				}elseif($i < count($f->thresholds)){
					$code .= "}elseif($v < " . self::float($f->thresholds[$i]) . "){\n" . $body;
				}else{
					$code .= "}else{\n" . $body . "}";
				}
			}
			$stmts[] = $code;
			return $t;
		}
		if($f instanceof FindTopSurfaceFunction){
			$ub = $this->expr($f->upperBound, $stmts);
			$densityMethod = $this->subRoot($f->density);
			$t = $this->newTemp();
			$i = $this->newTemp();
			$cell = $f->cellHeight;
			$lower = $f->lowerBound;
			$stmts[] = "$i = (int) (floor($ub / $cell) * $cell);\n$t = (float) $lower;\nfor(; $i >= $lower; $i -= $cell){\n\tif(\$this->$densityMethod(\$x, (float) $i, \$z) > 0){\n\t\t$t = (float) $i;\n\t\tbreak;\n\t}\n}";
			return $t;
		}
		if($f instanceof SplineFunction){
			return $this->spline($f->spline, $stmts);
		}
		if($f instanceof SliceFunction){
			$method = $this->subRoot($f->input);
			$c = self::float((float) $f->coordinate);
			$t = $this->newTemp();
			$stmts[] = match($f->axis){
				SliceFunction::AXIS_X => "$t = \$this->$method($c, \$y, \$z);",
				SliceFunction::AXIS_Y => "$t = \$this->$method(\$x, $c, \$z);",
				SliceFunction::AXIS_Z => "$t = \$this->$method(\$x, \$y, $c);",
			};
			return $t;
		}
		if($f instanceof DistanceToPointFunction){
			$t = $this->newTemp();
			$dx = self::float($f->pointX) . " - \$x";
			$dy = self::float($f->pointY) . " - \$y";
			$dz = self::float($f->pointZ) . " - \$z";
			$stmts[] = "{$t}x = $dx;\n{$t}y = $dy;\n{$t}z = $dz;";
			$stmts[] = match($f->metric){
				DistanceToPointFunction::METRIC_EUCLIDEAN => "$t = sqrt({$t}x * {$t}x + {$t}y * {$t}y + {$t}z * {$t}z);",
				DistanceToPointFunction::METRIC_EUCLIDEAN_SQUARED => "$t = {$t}x * {$t}x + {$t}y * {$t}y + {$t}z * {$t}z;",
				DistanceToPointFunction::METRIC_MANHATTAN => "$t = abs({$t}x) + abs({$t}y) + abs({$t}z);",
				DistanceToPointFunction::METRIC_CHEBYSHEV => "$t = max(abs({$t}x), abs({$t}y), abs({$t}z));",
			};
			return $t;
		}
		if($f instanceof EndIslandsFunction){
			//seeded object with its own cache: evaluated by the interpreter
			$k = count($this->fallbacks);
			$this->fallbacks[] = $f;
			$t = $this->newTemp();
			$stmts[] = "$t = \$this->fallback[$k]->compute(\$x, \$y, \$z);";
			return $t;
		}
		if($f instanceof InterpolatedFunction){
			//outside of a chunk filler: interpolate on the fly through the interpreter
			$k = count($this->fallbacks);
			$this->fallbacks[] = $f;
			$t = $this->newTemp();
			$stmts[] = "$t = \$this->fallback[$k]->compute(\$x, \$y, \$z);";
			return $t;
		}
		throw new \InvalidArgumentException("Cannot compile " . $f::class);
	}

	private function scaled(string $coordinate, float $scale, ?string $shift) : string{
		$base = $scale === 0.0 ? "0.0" : ($scale === 1.0 ? $coordinate : "$coordinate * " . self::float($scale));
		return $shift === null ? $base : "$base + $shift";
	}

	/** float32 rounding of an expression into $target (uses $tmp as scratch). */
	private static function fround(string $target, string $tmp, string $expr) : string{
		return "$target = $expr;\n$tmp = $target * 536870913.0;\n$target = $tmp - ($tmp - $target);";
	}

	/** $target = fround(b + fround(a * fround(c - b))) */
	private static function flerp(string $target, string $tmp, string $a, string $b, string $c) : string{
		return "$target = $c - $b;\n$tmp = $target * 536870913.0;\n$target = $tmp - ($tmp - $target);\n" .
			"$target = $a * $target;\n$tmp = $target * 536870913.0;\n$target = $tmp - ($tmp - $target);\n" .
			"$target = $b + $target;\n$tmp = $target * 536870913.0;\n$target = $tmp - ($tmp - $target);";
	}

	/**
	 * @param string[] $stmts
	 * @phpstan-param list<string> $stmts
	 */
	private function spline(CubicSpline $s, array &$stmts) : string{
		$c = $this->expr($s->coordinate, $stmts);
		$coord = $this->newTemp();
		$tmp = $this->newTemp();
		if(CubicSpline::$vanillaFloats){
			$stmts[] = self::fround($coord, $tmp, $c);
		}else{
			$stmts[] = "$coord = $c;";
		}
		$t = $this->newTemp();
		$n = count($s->locations) - 1;
		$code = "";
		$valueOf = function(int $i, array &$out) use ($s) : string{
			$v = $s->values[$i];
			if(!$v instanceof CubicSpline){
				return self::float($v);
			}
			//nested splines are needed by two segments each: compile them once as methods
			$id = spl_object_id($v);
			if(!isset($this->splineMethods[$id])){
				$name = "spline" . ($this->methodCounter++);
				$this->splineMethods[$id] = $name;
				$this->methods[] = $this->buildMethod(new SplineFunction($v), $name, false);
			}
			$r = $this->newTemp();
			$out[] = "$r = \$this->{$this->splineMethods[$id]}(\$x, \$y, \$z);";
			return $r;
		};
		//below the first point: linear extension
		$inner = [];
		$v0 = $valueOf(0, $inner);
		$code .= "if($coord < " . self::float($s->locations[0]) . "){\n" . self::indent(implode("\n", $inner), 1) . ($inner !== [] ? "\n" : "") .
			self::indent($this->extend($t, $tmp, $v0, $s->derivatives[0], $coord, $s->locations[0]), 1) . "\n";
		for($i = 0; $i < $n; ++$i){
			$inner = [];
			$val0 = $valueOf($i, $inner);
			$val1 = $valueOf($i + 1, $inner);
			$code .= "}elseif($coord < " . self::float($s->locations[$i + 1]) . "){\n" . self::indent(implode("\n", $inner), 1) . ($inner !== [] ? "\n" : "") .
				self::indent($this->segment($t, $tmp, $coord, $s->locations[$i], $s->locations[$i + 1], $s->derivatives[$i], $s->derivatives[$i + 1], $val0, $val1), 1) . "\n";
		}
		$inner = [];
		$vn = $valueOf($n, $inner);
		$code .= "}else{\n" . self::indent(implode("\n", $inner), 1) . ($inner !== [] ? "\n" : "") .
			self::indent($this->extend($t, $tmp, $vn, $s->derivatives[$n], $coord, $s->locations[$n]), 1) . "\n}";
		$stmts[] = $code;
		return $t;
	}

	private function extend(string $t, string $tmp, string $value, float $derivative, string $coord, float $location) : string{
		return self::fround($t, $tmp, "$coord - " . self::float($location)) . "\n" .
			self::fround($t, $tmp, self::float($derivative) . " * $t") . "\n" .
			self::fround($t, $tmp, "$value + $t");
	}

	private function segment(string $t, string $tmp, string $coord, float $loc0, float $loc1, float $der0, float $der1, string $val0, string $val1) : string{
		$span = $loc1 - $loc0;
		//span is computed in float32 by vanilla; both locations are floats so the difference rounds the same way
		$spanVar = $this->newTemp();
		$f = $this->newTemp();
		$dv = $this->newTemp();
		$f8 = $this->newTemp();
		$f9 = $this->newTemp();
		$a = $this->newTemp();
		$b = $this->newTemp();
		return self::fround($spanVar, $tmp, self::float($loc1) . " - " . self::float($loc0)) . "\n" .
			self::fround($f, $tmp, "$coord - " . self::float($loc0)) . "\n" .
			self::fround($f, $tmp, "$f / $spanVar") . "\n" .
			self::fround($dv, $tmp, "$val1 - $val0") . "\n" .
			self::fround($f8, $tmp, self::float($der0) . " * $spanVar") . "\n" .
			self::fround($f8, $tmp, "$f8 - $dv") . "\n" .
			self::fround($f9, $tmp, self::float(-$der1) . " * $spanVar") . "\n" .
			self::fround($f9, $tmp, "$f9 + $dv") . "\n" .
			self::flerp($a, $tmp, $f, $val0, $val1) . "\n" .
			self::flerp($b, $tmp, $f, $f8, $f9) . "\n" .
			self::fround($t, $tmp, "1.0 - $f") . "\n" .
			self::fround($t, $tmp, "$f * $t") . "\n" .
			self::fround($t, $tmp, "$t * $b") . "\n" .
			self::fround($t, $tmp, "$a + $t");
	}

	private function subRoot(DensityFunction $f) : string{
		$id = spl_object_id($f);
		if(isset($this->subRoots[$id])){
			return $this->subRoots[$id];
		}
		$name = "sub" . ($this->methodCounter++);
		$this->subRoots[$id] = $name;
		$this->methods[] = $this->buildMethod($f, $name, false);
		return $name;
	}

	private function noiseMethod(NoiseStack $noise) : string{
		$id = spl_object_id($noise);
		if(isset($this->noiseIds[$id])){
			return "noise" . $this->noiseIds[$id];
		}
		$k = count($this->noises);
		$this->noiseIds[$id] = $k;
		$this->noises[] = $noise;
		if(self::$unrollOctaves){
			$body = "\$v = 0.0;\n";
			foreach($noise->octaves as $i => $octave){
				$prop = "n{$k}_$i";
				$this->declareOctave($prop);
				$body .= self::octaveCode($prop, $noise->frequencies[$i], $noise->amplitudes[$i], '$x', '$y', '$z', null);
			}
		}else{
			$body = $this->stackLoop("n$k", "\$b->noises[$k]->octaves", "\$b->noises[$k]->frequencies", "\$b->noises[$k]->amplitudes", null, count($noise->octaves), '$x', '$y', '$z');
		}
		$this->methods[] = "\tprivate function noise$k(float \$x, float \$y, float \$z) : float{\n" . self::indent($body, 2) . "\n\t\treturn \$v;\n\t}\n";
		return "noise$k";
	}

	/**
	 * Octave stack as a loop over per-octave arrays (short, loop-shaped code: what the tracing JIT handles best).
	 */
	private function stackLoop(string $prefix, string $octavesExpr, string $freqExpr, string $ampExpr, ?string $fudgeExpr, int $count, string $x, string $y, string $z) : string{
		if(!isset($this->loopStacks[$prefix])){
			$this->loopStacks[$prefix] = [$octavesExpr, $freqExpr, $ampExpr, $fudgeExpr];
			$decl = "";
			foreach(["p", "gx", "gy", "gz", "xo", "yo", "zo", "f", "a"] as $field){
				$decl .= "\t/** @var mixed[] */\n\tprivate array \${$prefix}_$field = [];\n";
			}
			if($fudgeExpr !== null){
				$decl .= "\t/** @var float[] */\n\tprivate array \${$prefix}_fu = [];\n";
			}
			$this->properties[] = rtrim($decl);
		}
		$code = "\$v = 0.0;\n\$P_ = \$this->{$prefix}_p;\n\$GX_ = \$this->{$prefix}_gx;\n\$GY_ = \$this->{$prefix}_gy;\n\$GZ_ = \$this->{$prefix}_gz;\n" .
			"\$XO_ = \$this->{$prefix}_xo;\n\$YO_ = \$this->{$prefix}_yo;\n\$ZO_ = \$this->{$prefix}_zo;\n\$F_ = \$this->{$prefix}_f;\n\$A_ = \$this->{$prefix}_a;\n" .
			($fudgeExpr !== null ? "\$FU_ = \$this->{$prefix}_fu;\n" : "");
		$code .= "for(\$o = 0; \$o < $count; ++\$o){\n";
		$code .= self::indent(self::octaveCodeExpr('$F_[$o]', '$A_[$o]', '$XO_[$o]', '$YO_[$o]', '$ZO_[$o]', '$P_[$o]', '$GX_[$o]', '$GY_[$o]', '$GZ_[$o]', $x, $y, $z, $fudgeExpr !== null ? '$FU_[$o]' : null), 1);
		$code .= "\n}\n";
		return $code;
	}

	private function declareOctave(string $prop) : void{
		if(isset($this->declaredOctaves[$prop])){
			return;
		}
		$this->declaredOctaves[$prop] = true;
		$this->properties[] = "\t/** @var int[] */\n\tprivate array \${$prop}p;\n\t/** @var float[] */\n\tprivate array \${$prop}gx;\n\t/** @var float[] */\n\tprivate array \${$prop}gy;\n\t/** @var float[] */\n\tprivate array \${$prop}gz;\n\tprivate float \${$prop}xo;\n\tprivate float \${$prop}yo;\n\tprivate float \${$prop}zo;";
	}

	/**
	 * Inline code adding amplitude * octave(x * frequency, ...) to $v. With $fudge != null, uses the smeared
	 * (y-fudged) variant of the blended noise.
	 */
	private static function octaveCode(string $prop, float $frequency, float $amplitude, string $x, string $y, string $z, ?float $fudge) : string{
		return self::octaveCodeExpr(self::float($frequency), self::float($amplitude), "\$this->{$prop}xo", "\$this->{$prop}yo", "\$this->{$prop}zo",
			"\$this->{$prop}p", "\$this->{$prop}gx", "\$this->{$prop}gy", "\$this->{$prop}gz", $x, $y, $z, $fudge !== null ? self::float($fudge) : null);
	}

	/**
	 * Octave sampling code with every input given as a PHP expression (literals/properties when unrolled, array
	 * elements when generated as a loop).
	 */
	private static function octaveCodeExpr(string $fr, string $amp, string $xo, string $yo, string $zo, string $pExpr, string $gxExpr, string $gyExpr, string $gzExpr, string $x, string $y, string $z, ?string $fudge) : string{
		$code = "\$sx = $x * $fr;\n\$sy = $y * $fr;\n\$sz = $z * $fr;\n";
		$code .= "\$ax = \$sx - floor(\$sx * 2.98023223876953125E-8 + 0.5) * 33554432.0 + $xo;\n";
		$code .= "\$ay = \$sy - floor(\$sy * 2.98023223876953125E-8 + 0.5) * 33554432.0 + $yo;\n";
		$code .= "\$az = \$sz - floor(\$sz * 2.98023223876953125E-8 + 0.5) * 33554432.0 + $zo;\n";
		if(!self::$inlineOctaves){
			$noise = "\\" . ImprovedNoise::class;
			return $code . ($fudge !== null ?
				"\$v += $amp * $noise::sampleSmearedRaw($pExpr, $gxExpr, $gyExpr, $gzExpr, \$ax, \$ay, \$az, \$sy, $fudge);\n" :
				"\$v += $amp * $noise::sampleRaw($pExpr, $gxExpr, $gyExpr, $gzExpr, \$ax, \$ay, \$az);\n");
		}
		$code .= "\$fx = floor(\$ax);\n\$fy = floor(\$ay);\n\$fz = floor(\$az);\n";
		$code .= "\$rx = \$ax - \$fx;\n\$ry = \$ay - \$fy;\n\$rz = \$az - \$fz;\n";
		if($fudge !== null){
			$code .= "\$lim = (\$sy >= 0 && \$sy < \$ry) ? \$sy : \$ry;\n";
			$code .= "\$gry = \$ry - floor(\$lim / $fudge + 1.0E-7) * $fudge;\n";
		}else{
			$code .= "\$gry = \$ry;\n";
		}
		$code .= "\$ix = (int) \$fx;\n\$iy = (int) \$fy;\n\$iz = (int) \$fz;\n";
		$code .= "\$p = $pExpr;\n\$gx = $gxExpr;\n\$gy = $gyExpr;\n\$gz = $gzExpr;\n";
		$code .= <<<'CODE'
$x0 = $p[$ix & 255];
$x1 = $p[($ix + 1) & 255];
$xy00 = $x0 + $iy;
$xy01 = $p[($xy00 + 1) & 255];
$xy00 = $p[$xy00 & 255];
$xy10 = $x1 + $iy;
$xy11 = $p[($xy10 + 1) & 255];
$xy10 = $p[$xy10 & 255];
$rx1 = $rx - 1.0;
$ry1 = $gry - 1.0;
$rz1 = $rz - 1.0;
$i = ($xy00 + $iz) & 255;
$d000 = $gx[$i] * $rx + $gy[$i] * $gry + $gz[$i] * $rz;
$i = ($xy10 + $iz) & 255;
$d100 = $gx[$i] * $rx1 + $gy[$i] * $gry + $gz[$i] * $rz;
$i = ($xy01 + $iz) & 255;
$d010 = $gx[$i] * $rx + $gy[$i] * $ry1 + $gz[$i] * $rz;
$i = ($xy11 + $iz) & 255;
$d110 = $gx[$i] * $rx1 + $gy[$i] * $ry1 + $gz[$i] * $rz;
$i = ($xy00 + $iz + 1) & 255;
$d001 = $gx[$i] * $rx + $gy[$i] * $gry + $gz[$i] * $rz1;
$i = ($xy10 + $iz + 1) & 255;
$d101 = $gx[$i] * $rx1 + $gy[$i] * $gry + $gz[$i] * $rz1;
$i = ($xy01 + $iz + 1) & 255;
$d011 = $gx[$i] * $rx + $gy[$i] * $ry1 + $gz[$i] * $rz1;
$i = ($xy11 + $iz + 1) & 255;
$d111 = $gx[$i] * $rx1 + $gy[$i] * $ry1 + $gz[$i] * $rz1;
$ux = $rx * $rx * $rx * ($rx * ($rx * 6.0 - 15.0) + 10.0);
$uy = $ry * $ry * $ry * ($ry * ($ry * 6.0 - 15.0) + 10.0);
$uz = $rz * $rz * $rz * ($rz * ($rz * 6.0 - 15.0) + 10.0);
$a = $d000 + $ux * ($d100 - $d000);
$b = $d010 + $ux * ($d110 - $d010);
$c = $d001 + $ux * ($d101 - $d001);
$d = $d011 + $ux * ($d111 - $d011);
$a = $a + $uy * ($b - $a);
$c = $c + $uy * ($d - $c);

CODE;
		$code .= "\$v += $amp * (\$a + \$uz * (\$c - \$a));\n";
		return $code;
	}

	private function blendedMethod(BlendedNoise $noise) : string{
		$id = spl_object_id($noise);
		if(isset($this->blendedIds[$id])){
			return "blended" . $this->blendedIds[$id];
		}
		$k = count($this->blendeds);
		$this->blendedIds[$id] = $k;
		$this->blendeds[] = $noise;
		$stack = function(int $s, string $x, string $y, string $z) use ($noise, $k) : string{
			if(!self::$unrollOctaves){
				return $this->stackLoop("b{$k}_$s", "\$b->blendeds[$k]->noises[$s]", "\$b->blendeds[$k]->frequencies[$s]", "\$b->blendeds[$k]->amplitudes[$s]", "\$b->blendeds[$k]->fudgeScales[$s]", count($noise->noises[$s]), $x, $y, $z);
			}
			$code = "\$v = 0.0;\n";
			foreach($noise->noises[$s] as $i => $octave){
				$prop = "b{$k}_{$s}_$i";
				$this->declareOctave($prop);
				$code .= self::octaveCode($prop, $noise->frequencies[$s][$i], $noise->amplitudes[$s][$i], $x, $y, $z, $noise->fudgeScales[$s][$i]);
			}
			return $code;
		};
		$xz = self::float($noise->xzMultiplier);
		$ym = self::float($noise->yMultiplier);
		$body = "\$xx = \$x * $xz;\n\$yy = \$y * $ym;\n\$zz = \$z * $xz;\n";
		$body .= "\$mx = \$xx / " . self::float($noise->xzFactor) . ";\n\$my = \$yy / " . self::float($noise->yFactor) . ";\n\$mz = \$zz / " . self::float($noise->xzFactor) . ";\n";
		$body .= $stack(2, '$mx', '$my', '$mz');
		$body .= "\$main = \$v + 0.5;\n";
		$body .= "if(\$main <= 0){\n" . self::indent($stack(0, '$xx', '$yy', '$zz'), 1) . "\n\treturn \$v;\n}\n";
		$body .= "if(\$main >= 1){\n" . self::indent($stack(1, '$xx', '$yy', '$zz'), 1) . "\n\treturn \$v;\n}\n";
		$body .= $stack(0, '$xx', '$yy', '$zz') . "\$lo = \$v;\n" . $stack(1, '$xx', '$yy', '$zz') . "\$hi = \$v;\n";
		$body .= self::flerp('$r', '$tmp', '$main', '$lo', '$hi') . "\nreturn \$r;";
		$this->methods[] = "\tprivate function blended$k(float \$x, float \$y, float \$z) : float{\n" . self::indent($body, 2) . "\n\t}\n";
		return "blended$k";
	}

	// ------------------------------------------------------------------------------------------------------------
	// Batch (column) mode: evaluates a function for every y of a column at once. Every node becomes a short loop over
	// the active points, which is the shape the tracing JIT compiles well (long straight-line per-point code is not).
	// Lazy nodes (range_choice, min/max, mul, lerp...) split the active point list instead of branching.
	// ------------------------------------------------------------------------------------------------------------

	private int $batchTemp = 0;
	/** @phpstan-var array<string, string> */
	private array $batchNoiseMethods = [];
	/** @phpstan-var array<int, string> */
	private array $batchBlendedMethods = [];
	/** @phpstan-var array<int, string> node id => memo array variable (per batch method) */
	private array $batchMemo = [];

	/**
	 * Compiles $root into `public function $name(float $x, float $z, array $Y) : array`, returning the values for
	 * every y in $Y (keys 0..count-1).
	 */
	public function compileBatchRoot(DensityFunction $root, string $name) : void{
		$this->batchTemp = 0;
		$this->batchMemo = [];
		$savedTemp = $this->temp;
		$this->temp = 0;
		$stmts = [];
		[$isArray, $v] = $this->bexpr($root, '$L0', $stmts);
		$src = "\t/**\n\t * @param float[] \$Y\n\t * @return float[]\n\t */\n\tpublic function $name(float \$x, float \$z, array \$Y) : array{\n";
		$src .= "\t\t\$y = 0.0;\n\t\t\$L0 = array_keys(\$Y);\n";
		foreach($this->batchMemoVariables() as $var){
			$src .= "\t\t$var = [];\n";
		}
		$src .= self::indent(implode("\n", $stmts), 2) . "\n";
		if($isArray){
			$src .= "\t\treturn $v;\n";
		}else{
			$src .= "\t\treturn array_fill(0, count(\$Y), $v);\n";
		}
		$src .= "\t}\n";
		$this->temp = $savedTemp;
		$this->methods[] = $src;
	}

	/**
	 * @return string[]
	 * @phpstan-return array<int, string>
	 */
	private function batchMemoVariables() : array{
		return $this->batchMemo;
	}

	private function newBatch() : string{
		return '$B' . ($this->batchTemp++);
	}

	private function newList() : string{
		return '$L' . (++$this->batchTemp);
	}

	/** Element access for a batch value. */
	private static function at(bool $isArray, string $v) : string{
		return $isArray ? $v . '[$j]' : $v;
	}

	/**
	 * @param string[] $stmts
	 * @phpstan-param list<string> $stmts
	 * @return array{bool, string} [is per-point array, expression]
	 */
	private function bexpr(DensityFunction $f, string $list, array &$stmts) : array{
		if(!$f->dependsOnY()){
			//scalar for the whole column (memoized per column by the scalar code)
			return [false, $this->expr($f, $stmts)];
		}
		$id = spl_object_id($f);
		if($f instanceof NamedFunction || $f instanceof CacheFunction){
			if(self::isTrivial($f)){
				return $this->bexpr($f->input, $list, $stmts);
			}
			$var = $this->batchMemo[$id] ??= '$M' . count($this->batchMemo);
			$need = $this->newList();
			$inner = [];
			[$isArray, $v] = $this->bexpr($f->input, $need, $inner);
			$stmts[] = "$need = [];\nforeach($list as \$j){\n\tif(!isset({$var}[\$j])){\n\t\t{$need}[] = \$j;\n\t}\n}\nif(count($need) > 0){\n" .
				self::indent(implode("\n", $inner), 1) . ($inner !== [] ? "\n" : "") .
				"\tforeach($need as \$j){\n\t\t{$var}[\$j] = " . self::at($isArray, $v) . ";\n\t}\n}";
			return [true, $var];
		}
		if($f instanceof GradientFunction){
			if($f->tiling !== GradientFunction::TILING_CLAMP || $f->axis !== GradientFunction::AXIS_Y){
				return $this->bfallback($f, $list, $stmts);
			}
			$r = $this->newBatch();
			$range = self::float((float) ($f->toCoordinate - $f->fromCoordinate));
			$fromC = self::float((float) $f->fromCoordinate);
			$from = self::float($f->fromValue);
			$to = self::float($f->toValue);
			$stmts[] = "$r = [];\nforeach($list as \$j){\n\t\$g = (\$Y[\$j] - $fromC) / $range;\n\t{$r}[\$j] = \$g < 0 ? $from : (\$g > 1 ? $to : $from + \$g * ($to - $from));\n}";
			return [true, $r];
		}
		if($f instanceof NoiseFunction){
			$sx = $f->shiftX !== null ? $this->bexpr($f->shiftX, $list, $stmts) : [false, "0.0"];
			$sy = $f->shiftY !== null ? $this->bexpr($f->shiftY, $list, $stmts) : [false, "0.0"];
			$sz = $f->shiftZ !== null ? $this->bexpr($f->shiftZ, $list, $stmts) : [false, "0.0"];
			$coord = function(string $c, float $scale, array $shift) : array{
				$base = $scale === 0.0 ? "0.0" : "$c * " . self::float($scale);
				return [$shift[0] || $c === '$Y[$j]', $shift[1] === "0.0" ? $base : "$base + " . self::at($shift[0], $shift[1])];
			};
			$prefix = $this->stackPrefix($f->noise);
			$r = $this->newBatch();
			if(!$sx[0] && !$sy[0] && !$sz[0]){
				//common case: x/z constant over the column. Put the octave loops in their own small method: the tracing
				//JIT fails to compile loops inside functions with hundreds of local variables.
				$ys = self::float($f->yScale);
				$key = "$prefix|$ys|" . ($sy[1] === "0.0" ? "0" : "1");
				if(!isset($this->batchNoiseMethods[$key])){
					$name = "bnoise" . ($this->methodCounter++);
					$this->batchNoiseMethods[$key] = $name;
					$cy = $f->yScale === 0.0 ? "\$shy" : ($sy[1] === "0.0" ? "\$Y[\$j] * $ys" : "\$Y[\$j] * $ys + \$shy");
					$body = $this->batchStack($prefix, $f->noise->count(), [false, '$cx'], [true, $cy], [false, '$cz'], '$L', '$r', false, true);
					$this->methods[] = "\t/**\n\t * @param int[] \$L\n\t * @param float[] \$Y\n\t * @return float[]\n\t */\n\tprivate function $name(array \$L, float \$cx, float \$cz, array \$Y, float \$shy) : array{\n" . self::indent($body, 2) . "\n\t\treturn \$r;\n\t}\n";
				}
				[, $cxe] = $coord('$x', $f->xzScale, $sx);
				[, $cze] = $coord('$z', $f->xzScale, $sz);
				$stmts[] = "$r = \$this->{$this->batchNoiseMethods[$key]}($list, $cxe, $cze, \$Y, " . $sy[1] . ");";
				return [true, $r];
			}
			$stmts[] = $this->batchStack($prefix, $f->noise->count(), $coord('$x', $f->xzScale, $sx), $coord('$Y[$j]', $f->yScale, $sy), $coord('$z', $f->xzScale, $sz), $list, $r, false, true);
			return [true, $r];
		}
		if($f instanceof ShiftNoiseFunction){
			return $this->bfallback($f, $list, $stmts);
		}
		if($f instanceof BlendedNoiseFunction){
			return $this->bblended($f->noise, $list, $stmts);
		}
		if($f instanceof UnaryFunction){
			[$ia, $a] = $this->bexpr($f->input, $list, $stmts);
			$r = $this->newBatch();
			$e = self::at($ia, $a);
			$body = match($f->op){
				UnaryFunction::ABS => "{$r}[\$j] = abs($e);",
				UnaryFunction::SQUARE => "\$u = $e;\n{$r}[\$j] = \$u * \$u;",
				UnaryFunction::CUBE => "\$u = $e;\n{$r}[\$j] = \$u * \$u * \$u;",
				UnaryFunction::HALF_NEGATIVE => "\$u = $e;\n{$r}[\$j] = \$u > 0 ? \$u : \$u * 0.5;",
				UnaryFunction::QUARTER_NEGATIVE => "\$u = $e;\n{$r}[\$j] = \$u > 0 ? \$u : \$u * 0.25;",
				UnaryFunction::SQUEEZE => "\$u = $e;\n\$u = \$u < -1.0 ? -1.0 : (\$u > 1.0 ? 1.0 : \$u);\n{$r}[\$j] = \$u / 2 - \$u * \$u * \$u / 24;",
				UnaryFunction::NEGATE => "{$r}[\$j] = -$e;",
				UnaryFunction::SQRT => "{$r}[\$j] = sqrt($e);",
				UnaryFunction::LOG => "{$r}[\$j] = log($e);",
				UnaryFunction::SIGN => "\$u = $e;\n{$r}[\$j] = \$u > 0 ? 1.0 : (\$u < 0 ? -1.0 : \$u);",
				UnaryFunction::RECIPROCAL => "{$r}[\$j] = 1 / $e;",
				default => throw new \InvalidArgumentException("Unknown unary op")
			};
			$stmts[] = "$r = [];\nforeach($list as \$j){\n" . self::indent($body, 1) . "\n}";
			return [true, $r];
		}
		if($f instanceof BinaryFunction){
			[$ia, $a] = $this->bexpr($f->left, $list, $stmts);
			$r = $this->newBatch();
			$ea = self::at($ia, $a);
			switch($f->op){
				case BinaryFunction::ADD:
				case BinaryFunction::SUB:
					[$ib, $b] = $this->bexpr($f->right, $list, $stmts);
					$op = $f->op === BinaryFunction::ADD ? "+" : "-";
					$stmts[] = "$r = [];\nforeach($list as \$j){\n\t{$r}[\$j] = $ea $op " . self::at($ib, $b) . ";\n}";
					return [true, $r];
				case BinaryFunction::MUL:
				case BinaryFunction::DIV:
					$op = $f->op === BinaryFunction::MUL ? "*" : "/";
					if($f->right instanceof ConstantFunction || $f->left instanceof ConstantFunction){
						[$ib, $b] = $this->bexpr($f->right, $list, $stmts);
						$stmts[] = "$r = [];\nforeach($list as \$j){\n\t{$r}[\$j] = $ea $op " . self::at($ib, $b) . ";\n}";
						return [true, $r];
					}
					$sub = $this->newList();
					$inner = [];
					[$ib, $b] = $this->bexpr($f->right, $sub, $inner);
					$stmts[] = "$r = [];\n$sub = [];\nforeach($list as \$j){\n\tif($ea == 0){\n\t\t{$r}[\$j] = 0.0;\n\t}else{\n\t\t{$sub}[] = \$j;\n\t}\n}\n" .
						"if(count($sub) > 0){\n" . self::indent(implode("\n", $inner), 1) . ($inner !== [] ? "\n" : "") .
						"\tforeach($sub as \$j){\n\t\t{$r}[\$j] = $ea $op " . self::at($ib, $b) . ";\n\t}\n}";
					return [true, $r];
				case BinaryFunction::MIN:
				case BinaryFunction::MAX:
					$isMin = $f->op === BinaryFunction::MIN;
					$bound = $isMin ? $f->right->minValue : $f->right->maxValue;
					$pickFor = fn(string $eb) : string => $isMin ? "\$u = $ea;\n\$w = $eb;\n{$r}[\$j] = \$u < \$w ? \$u : \$w;" : "\$u = $ea;\n\$w = $eb;\n{$r}[\$j] = \$u > \$w ? \$u : \$w;";
					if(!is_finite($bound)){
						[$ib, $b] = $this->bexpr($f->right, $list, $stmts);
						$stmts[] = "$r = [];\nforeach($list as \$j){\n" . self::indent($pickFor(self::at($ib, $b)), 1) . "\n}";
						return [true, $r];
					}
					$sub = $this->newList();
					$inner = [];
					[$ib, $b] = $this->bexpr($f->right, $sub, $inner);
					$pick = $pickFor(self::at($ib, $b));
					$skip = $isMin ? "$ea < " . self::float($bound) : "$ea > " . self::float($bound);
					$stmts[] = "$r = [];\n$sub = [];\nforeach($list as \$j){\n\tif($skip){\n\t\t{$r}[\$j] = $ea;\n\t}else{\n\t\t{$sub}[] = \$j;\n\t}\n}\n" .
						"if(count($sub) > 0){\n" . self::indent(implode("\n", $inner), 1) . ($inner !== [] ? "\n" : "") .
						"\tforeach($sub as \$j){\n" . self::indent($pick, 2) . "\n\t}\n}";
					return [true, $r];
			}
			throw new \InvalidArgumentException("Unknown binary op");
		}
		if($f instanceof RangeChoiceFunction){
			[$iv, $v] = $this->bexpr($f->input, $list, $stmts);
			$ev = self::at($iv, $v);
			$in = $this->newList();
			$out = $this->newList();
			$inStmts = [];
			[$ia, $a] = $this->bexpr($f->whenInRange, $in, $inStmts);
			$outStmts = [];
			[$ib, $b] = $this->bexpr($f->whenOutOfRange, $out, $outStmts);
			$r = $this->newBatch();
			$stmts[] = "$in = [];\n$out = [];\nforeach($list as \$j){\n\t\$u = $ev;\n\tif(\$u >= " . self::float($f->minInclusive) . " && \$u < " . self::float($f->maxExclusive) . "){\n\t\t{$in}[] = \$j;\n\t}else{\n\t\t{$out}[] = \$j;\n\t}\n}\n$r = [];\n" .
				"if(count($in) > 0){\n" . self::indent(implode("\n", $inStmts), 1) . ($inStmts !== [] ? "\n" : "") . "\tforeach($in as \$j){\n\t\t{$r}[\$j] = " . self::at($ia, $a) . ";\n\t}\n}\n" .
				"if(count($out) > 0){\n" . self::indent(implode("\n", $outStmts), 1) . ($outStmts !== [] ? "\n" : "") . "\tforeach($out as \$j){\n\t\t{$r}[\$j] = " . self::at($ib, $b) . ";\n\t}\n}";
			return [true, $r];
		}
		if($f instanceof ClampFunction){
			[$iv, $v] = $this->bexpr($f->input, $list, $stmts);
			$r = $this->newBatch();
			$min = self::float($f->min);
			$max = self::float($f->max);
			$stmts[] = "$r = [];\nforeach($list as \$j){\n\t\$u = " . self::at($iv, $v) . ";\n\t{$r}[\$j] = \$u < $min ? $min : (\$u > $max ? $max : \$u);\n}";
			return [true, $r];
		}
		if($f instanceof LerpFunction){
			[$ia, $a] = $this->bexpr($f->alpha, $list, $stmts);
			$ea = self::at($ia, $a);
			$l0 = $this->newList();
			$l1 = $this->newList();
			$lm = $this->newList();
			$lf = $this->newList();
			$ls = $this->newList();
			$fs = [];
			[$if, $fv] = $this->bexpr($f->first, $lf, $fs);
			$ss = [];
			[$is, $sv] = $this->bexpr($f->second, $ls, $ss);
			$r = $this->newBatch();
			$ef = self::at($if, $fv);
			$es = self::at($is, $sv);
			$stmts[] = "$l0 = [];\n$l1 = [];\n$lm = [];\n$lf = [];\n$ls = [];\nforeach($list as \$j){\n\t\$u = $ea;\n\tif(\$u == 0){\n\t\t{$l0}[] = \$j;\n\t\t{$lf}[] = \$j;\n\t}elseif(\$u == 1){\n\t\t{$l1}[] = \$j;\n\t\t{$ls}[] = \$j;\n\t}else{\n\t\t{$lm}[] = \$j;\n\t\t{$lf}[] = \$j;\n\t\t{$ls}[] = \$j;\n\t}\n}\n" .
				"if(count($lf) > 0){\n" . self::indent(implode("\n", $fs), 1) . "\n}\n" .
				"if(count($ls) > 0){\n" . self::indent(implode("\n", $ss), 1) . "\n}\n" .
				"$r = [];\nforeach($l0 as \$j){\n\t{$r}[\$j] = $ef;\n}\nforeach($l1 as \$j){\n\t{$r}[\$j] = $es;\n}\n" .
				"foreach($lm as \$j){\n\t\$u = $ea;\n\t\$w = $ef;\n\t\$q = $es;\n" . self::indent(self::flerp('$res', '$tmp', '$u', '$w', '$q'), 1) . "\n\t{$r}[\$j] = \$res;\n}";
			return [true, $r];
		}
		if($f instanceof IntervalSelectFunction){
			[$iv, $v] = $this->bexpr($f->input, $list, $stmts);
			$ev = self::at($iv, $v);
			$lists = [];
			foreach($f->functions as $i => $fn){
				$lists[$i] = $this->newList();
			}
			$split = implode("\n", array_map(fn(string $l) : string => "$l = [];", $lists)) . "\nforeach($list as \$j){\n\t\$u = $ev;\n";
			foreach($f->thresholds as $i => $threshold){
				$split .= ($i === 0 ? "\tif" : "\telseif") . "(\$u < " . self::float($threshold) . "){\n\t\t{$lists[$i]}[] = \$j;\n\t}";
			}
			$split .= "else{\n\t\t{$lists[count($f->thresholds)]}[] = \$j;\n\t}\n}";
			$r = $this->newBatch();
			$code = $split . "\n$r = [];\n";
			foreach($f->functions as $i => $fn){
				$inner = [];
				[$ib, $b] = $this->bexpr($fn, $lists[$i], $inner);
				$code .= "if(count({$lists[$i]}) > 0){\n" . self::indent(implode("\n", $inner), 1) . ($inner !== [] ? "\n" : "") . "\tforeach({$lists[$i]} as \$j){\n\t\t{$r}[\$j] = " . self::at($ib, $b) . ";\n\t}\n}\n";
			}
			$stmts[] = rtrim($code);
			return [true, $r];
		}
		return $this->bfallback($f, $list, $stmts);
	}

	/**
	 * Per-point fallback through a compiled scalar method.
	 * @param string[] $stmts
	 * @phpstan-param list<string> $stmts
	 * @return array{bool, string}
	 */
	private function bfallback(DensityFunction $f, string $list, array &$stmts) : array{
		$id = spl_object_id($f);
		if(!isset($this->plainMethods[$id])){
			$name = "part" . ($this->methodCounter++);
			$this->plainMethods[$id] = $name;
			$this->methods[] = $this->buildMethod($f, $name, false);
		}
		$r = $this->newBatch();
		$stmts[] = "$r = [];\nforeach($list as \$j){\n\t{$r}[\$j] = \$this->{$this->plainMethods[$id]}(\$x, \$Y[\$j], \$z);\n}";
		return [true, $r];
	}

	private function stackPrefix(NoiseStack $noise) : string{
		$id = spl_object_id($noise);
		if(!isset($this->noiseIds[$id])){
			$this->noiseMethod($noise);
		}
		$k = $this->noiseIds[$id];
		$prefix = "n$k";
		if(!isset($this->loopStacks[$prefix])){
			//make sure the per-octave arrays exist even when the scalar code was generated unrolled
			$this->stackLoop($prefix, "\$b->noises[$k]->octaves", "\$b->noises[$k]->frequencies", "\$b->noises[$k]->amplitudes", null, $noise->count(), '$x', '$y', '$z');
		}
		return $prefix;
	}

	/**
	 * Loop over octaves (outer) and active points (inner). Coordinates that are the same for the whole column are
	 * processed once per octave.
	 * @param array{bool, string} $cx
	 * @param array{bool, string} $cy
	 * @param array{bool, string} $cz
	 */
	private function batchStack(string $prefix, int $count, array $cx, array $cy, array $cz, string $list, string $out, bool $fudge, bool $init) : string{
		$code = $init ? "$out = [];\nforeach($list as \$j){\n\t{$out}[\$j] = 0.0;\n}\n" : "";
		$code .= "\$P_ = \$this->{$prefix}_p;\n\$GX_ = \$this->{$prefix}_gx;\n\$GY_ = \$this->{$prefix}_gy;\n\$GZ_ = \$this->{$prefix}_gz;\n" .
			"\$XO_ = \$this->{$prefix}_xo;\n\$YO_ = \$this->{$prefix}_yo;\n\$ZO_ = \$this->{$prefix}_zo;\n\$F_ = \$this->{$prefix}_f;\n\$A_ = \$this->{$prefix}_a;\n" .
			($fudge ? "\$FU_ = \$this->{$prefix}_fu;\n" : "");
		$axis = function(string $c, string $expr, string $offset) : string{
			return "\$s$c = ($expr) * \$fr;\n\$a$c = \$s$c - floor(\$s$c * 2.98023223876953125E-8 + 0.5) * 33554432.0 + $offset;\n" .
				"\$f$c = floor(\$a$c);\n\$r$c = \$a$c - \$f$c;\n\$i$c = (int) \$f$c;\n\$u$c = \$r$c * \$r$c * \$r$c * (\$r$c * (\$r$c * 6.0 - 15.0) + 10.0);\n";
		};
		$loop = "for(\$o = 0; \$o < $count; ++\$o){\n";
		$loop .= "\t\$p = \$P_[\$o];\n\t\$gx = \$GX_[\$o];\n\t\$gy = \$GY_[\$o];\n\t\$gz = \$GZ_[\$o];\n\t\$fr = \$F_[\$o];\n\t\$amp = \$A_[\$o];\n\t\$xo = \$XO_[\$o];\n\t\$yo = \$YO_[\$o];\n\t\$zo = \$ZO_[\$o];\n" . ($fudge ? "\t\$fu = \$FU_[\$o];\n" : "");
		$perPoint = "";
		foreach(['x' => $cx, 'z' => $cz] as $c => [$isArray, $expr]){
			$code1 = $axis($c, $expr, "\$" . $c . "o");
			if($isArray){
				$perPoint .= $code1;
			}else{
				$loop .= self::indent($code1, 1) . "\n";
			}
		}
		if(!$cx[0]){
			$loop .= "\t\$x0 = \$p[\$ix & 255];\n\t\$x1 = \$p[(\$ix + 1) & 255];\n\t\$rx1 = \$rx - 1.0;\n";
		}else{
			$perPoint .= "\$x0 = \$p[\$ix & 255];\n\$x1 = \$p[(\$ix + 1) & 255];\n\$rx1 = \$rx - 1.0;\n";
		}
		if(!$cz[0]){
			$loop .= "\t\$rz1 = \$rz - 1.0;\n";
		}else{
			$perPoint .= "\$rz1 = \$rz - 1.0;\n";
		}
		$perPoint .= "\$sy = (" . $cy[1] . ") * \$fr;\n\$ay = \$sy - floor(\$sy * 2.98023223876953125E-8 + 0.5) * 33554432.0 + \$yo;\n";
		if(!self::$inlineOctaves){
			$noise = "\\" . ImprovedNoise::class;
			$perPoint .= $fudge ?
				"{$out}[\$j] += \$amp * $noise::sampleSmearedRaw(\$p, \$gx, \$gy, \$gz, \$ax, \$ay, \$az, \$sy, \$fu);" :
				"{$out}[\$j] += \$amp * $noise::sampleRaw(\$p, \$gx, \$gy, \$gz, \$ax, \$ay, \$az);";
			$loop .= "\tforeach($list as \$j){\n" . self::indent($perPoint, 2) . "\n\t}\n}";
			return $code . $loop;
		}
		$perPoint .= "\$fy = floor(\$ay);\n\$ry = \$ay - \$fy;\n\$iy = (int) \$fy;\n";
		if($fudge){
			$perPoint .= "\$lim = (\$sy >= 0 && \$sy < \$ry) ? \$sy : \$ry;\n\$gry = \$ry - floor(\$lim / \$fu + 1.0E-7) * \$fu;\n";
		}else{
			$perPoint .= "\$gry = \$ry;\n";
		}
		$perPoint .= <<<'CODE'
$xy00 = $x0 + $iy;
$xy01 = $p[($xy00 + 1) & 255];
$xy00 = $p[$xy00 & 255];
$xy10 = $x1 + $iy;
$xy11 = $p[($xy10 + 1) & 255];
$xy10 = $p[$xy10 & 255];
$ry1 = $gry - 1.0;
$i = ($xy00 + $iz) & 255;
$d000 = $gx[$i] * $rx + $gy[$i] * $gry + $gz[$i] * $rz;
$i = ($xy10 + $iz) & 255;
$d100 = $gx[$i] * $rx1 + $gy[$i] * $gry + $gz[$i] * $rz;
$i = ($xy01 + $iz) & 255;
$d010 = $gx[$i] * $rx + $gy[$i] * $ry1 + $gz[$i] * $rz;
$i = ($xy11 + $iz) & 255;
$d110 = $gx[$i] * $rx1 + $gy[$i] * $ry1 + $gz[$i] * $rz;
$i = ($xy00 + $iz + 1) & 255;
$d001 = $gx[$i] * $rx + $gy[$i] * $gry + $gz[$i] * $rz1;
$i = ($xy10 + $iz + 1) & 255;
$d101 = $gx[$i] * $rx1 + $gy[$i] * $gry + $gz[$i] * $rz1;
$i = ($xy01 + $iz + 1) & 255;
$d011 = $gx[$i] * $rx + $gy[$i] * $ry1 + $gz[$i] * $rz1;
$i = ($xy11 + $iz + 1) & 255;
$d111 = $gx[$i] * $rx1 + $gy[$i] * $ry1 + $gz[$i] * $rz1;
$uy = $ry * $ry * $ry * ($ry * ($ry * 6.0 - 15.0) + 10.0);
$a = $d000 + $ux * ($d100 - $d000);
$b = $d010 + $ux * ($d110 - $d010);
$c = $d001 + $ux * ($d101 - $d001);
$d = $d011 + $ux * ($d111 - $d011);
$a = $a + $uy * ($b - $a);
$c = $c + $uy * ($d - $c);

CODE;
		$perPoint .= "{$out}[\$j] += \$amp * (\$a + \$uz * (\$c - \$a));";
		$loop .= "\tforeach($list as \$j){\n" . self::indent($perPoint, 2) . "\n\t}\n}";
		return $code . $loop;
	}

	/**
	 * @param string[] $stmts
	 * @phpstan-param list<string> $stmts
	 * @return array{bool, string}
	 */
	private function bblended(BlendedNoise $noise, string $list, array &$stmts) : array{
		$id = spl_object_id($noise);
		if(!isset($this->batchBlendedMethods[$id])){
			$name = "bblended" . ($this->methodCounter++);
			$this->batchBlendedMethods[$id] = $name;
			$inner = [];
			[, $v] = $this->bblendedInline($noise, '$L', $inner);
			$this->methods[] = "\t/**\n\t * @param int[] \$L\n\t * @param float[] \$Y\n\t * @return float[]\n\t */\n\tprivate function $name(array \$L, float \$x, float \$z, array \$Y) : array{\n" . self::indent(implode("\n", $inner), 2) . "\n\t\treturn $v;\n\t}\n";
		}
		$r = $this->newBatch();
		$stmts[] = "$r = \$this->{$this->batchBlendedMethods[$id]}($list, \$x, \$z, \$Y);";
		return [true, $r];
	}

	/**
	 * @param string[] $stmts
	 * @phpstan-param list<string> $stmts
	 * @return array{bool, string}
	 */
	private function bblendedInline(BlendedNoise $noise, string $list, array &$stmts) : array{
		$id = spl_object_id($noise);
		if(!isset($this->blendedIds[$id])){
			$this->blendedMethod($noise);
		}
		$k = $this->blendedIds[$id];
		foreach([0, 1, 2] as $s){
			$prefix = "b{$k}_$s";
			if(!isset($this->loopStacks[$prefix])){
				$this->stackLoop($prefix, "\$b->blendeds[$k]->noises[$s]", "\$b->blendeds[$k]->frequencies[$s]", "\$b->blendeds[$k]->amplitudes[$s]", "\$b->blendeds[$k]->fudgeScales[$s]", count($noise->noises[$s]), '$x', '$y', '$z');
			}
		}
		$xz = self::float($noise->xzMultiplier);
		$ym = self::float($noise->yMultiplier);
		$xf = self::float($noise->xzFactor);
		$yf = self::float($noise->yFactor);
		$main = $this->newBatch();
		$lo = $this->newBatch();
		$hi = $this->newBatch();
		$r = $this->newBatch();
		$needLo = $this->newList();
		$needHi = $this->newList();
		$code = $this->batchStack("b{$k}_2", count($noise->noises[2]), [false, "(\$x * $xz) / $xf"], [true, "(\$Y[\$j] * $ym) / $yf"], [false, "(\$z * $xz) / $xf"], $list, $main, true, true) . "\n";
		$code .= "$needLo = [];\n$needHi = [];\nforeach($list as \$j){\n\t\$u = {$main}[\$j] + 0.5;\n\t{$main}[\$j] = \$u;\n\tif(\$u < 1){\n\t\t{$needLo}[] = \$j;\n\t}\n\tif(\$u > 0){\n\t\t{$needHi}[] = \$j;\n\t}\n}\n";
		$code .= $this->batchStack("b{$k}_0", count($noise->noises[0]), [false, "\$x * $xz"], [true, "\$Y[\$j] * $ym"], [false, "\$z * $xz"], $needLo, $lo, true, true) . "\n";
		$code .= $this->batchStack("b{$k}_1", count($noise->noises[1]), [false, "\$x * $xz"], [true, "\$Y[\$j] * $ym"], [false, "\$z * $xz"], $needHi, $hi, true, true) . "\n";
		$code .= "$r = [];\nforeach($list as \$j){\n\t\$u = {$main}[\$j];\n\tif(\$u <= 0){\n\t\t{$r}[\$j] = {$lo}[\$j];\n\t}elseif(\$u >= 1){\n\t\t{$r}[\$j] = {$hi}[\$j];\n\t}else{\n\t\t\$w = {$lo}[\$j];\n\t\t\$q = {$hi}[\$j];\n" .
			self::indent(self::flerp('$res', '$tmp', '$u', '$w', '$q'), 2) . "\n\t\t{$r}[\$j] = \$res;\n\t}\n}";
		$stmts[] = $code;
		return [true, $r];
	}

	/**
	 * Adds a hand-written method (e.g. a chunk filling loop using compileInline() fragments).
	 */
	public function addMethod(string $source) : void{
		$this->methods[] = $source;
	}

	public function addProperty(string $declaration) : void{
		$this->properties[] = $declaration;
	}

	/**
	 * Returns the full class source. The constructor takes the seed-bound data returned by getBindings().
	 */
	public function buildClass(string $className, string $namespace) : string{
		$src = "<?php\n\n// Generated by " . self::class . ". Do not edit.\n\ndeclare(strict_types=1);\n\nnamespace $namespace;\n\n";
		$src .= "final class $className extends \\" . CompiledKernel::class . "{\n";
		$src .= "\t/** @var \\" . DensityFunction::class . "[] */\n\tprivate array \$fallback;\n";
		$src .= implode("\n", $this->properties) . "\n\n";
		$src .= "\tpublic function __construct(\\" . KernelBindings::class . " \$b){\n";
		$src .= "\t\t\$this->fallback = \$b->fallbacks;\n";
		foreach(self::$unrollOctaves ? $this->noises : [] as $k => $noise){
			foreach($noise->octaves as $i => $o){
				$src .= self::bindOctave("n{$k}_$i", "\$b->noises[$k]->octaves[$i]");
			}
		}
		foreach(self::$unrollOctaves ? $this->blendeds : [] as $k => $noise){
			foreach($noise->noises as $s => $list){
				foreach($list as $i => $o){
					$src .= self::bindOctave("b{$k}_{$s}_$i", "\$b->blendeds[$k]->noises[$s][$i]");
				}
			}
		}
		foreach(Utils::stringifyKeys($this->loopStacks) as $prefix => [$octavesExpr, $freqExpr, $ampExpr, $fudgeExpr]){
			$src .= "\t\tforeach($octavesExpr as \$o){\n";
			foreach(["p" => "p", "gx" => "gx", "gy" => "gy", "gz" => "gz", "xo" => "xo", "yo" => "yo", "zo" => "zo"] as $field => $source){
				$src .= "\t\t\t\$this->{$prefix}_{$field}[] = \$o->$source;\n";
			}
			$src .= "\t\t}\n\t\t\$this->{$prefix}_f = $freqExpr;\n\t\t\$this->{$prefix}_a = $ampExpr;\n";
			if($fudgeExpr !== null){
				$src .= "\t\t\$this->{$prefix}_fu = $fudgeExpr;\n";
			}
		}
		$src .= "\t}\n\n";
		$src .= implode("\n", $this->methods);
		$src .= "}\n";
		return $src;
	}

	private static function bindOctave(string $prop, string $source) : string{
		return "\t\t\$o = $source;\n\t\t\$this->{$prop}p = \$o->p;\n\t\t\$this->{$prop}gx = \$o->gx;\n\t\t\$this->{$prop}gy = \$o->gy;\n\t\t\$this->{$prop}gz = \$o->gz;\n\t\t\$this->{$prop}xo = \$o->xo;\n\t\t\$this->{$prop}yo = \$o->yo;\n\t\t\$this->{$prop}zo = \$o->zo;\n";
	}

	public function getBindings() : KernelBindings{
		return new KernelBindings($this->noises, $this->blendeds, $this->fallbacks);
	}
}
