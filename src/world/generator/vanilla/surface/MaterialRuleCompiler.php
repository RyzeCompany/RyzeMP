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

namespace pocketmine\world\generator\vanilla\surface;

use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Filesystem;
use pocketmine\world\generator\vanilla\density\BinaryFunction;
use pocketmine\world\generator\vanilla\density\CacheFunction;
use pocketmine\world\generator\vanilla\density\ClampFunction;
use pocketmine\world\generator\vanilla\density\ConstantFunction;
use pocketmine\world\generator\vanilla\density\DensityFunction;
use pocketmine\world\generator\vanilla\density\InterpolatedFunction;
use pocketmine\world\generator\vanilla\density\NamedFunction;
use pocketmine\world\generator\vanilla\density\RangeChoiceFunction;
use pocketmine\world\generator\vanilla\density\UnaryFunction;
use pocketmine\world\generator\vanilla\surface\rule\MaterialRule;
use function class_exists;
use function count;
use function file_exists;
use function is_a;
use function is_dir;
use function is_finite;
use function is_object;
use function mkdir;
use function rename;
use function sha1;
use function spl_object_id;
use function str_contains;
use function str_replace;
use function substr;
use function var_export;
use const INF;

/**
 * Turns a material rule tree into one PHP method (nested ifs, conditions inlined), which the JIT compiles well:
 * evaluating the object tree costs a method call per node and block. Same results as MaterialRule::apply().
 */
final class MaterialRuleCompiler{
	public const NAMESPACE = "pocketmine\\world\\generator\\vanilla\\compiled";
	private const VERSION = 1;

	/** @phpstan-var list<mixed> */
	private array $bindings = [];
	/** @phpstan-var array<int, int> */
	private array $objectBindings = [];
	/** @phpstan-var array<string, string> expression => local variable */
	private array $lazyLocals = [];
	private int $temps = 0;

	/**
	 * Index of a value in the bindings array of the generated class ($b[index] in the generated code).
	 */
	public function bind(mixed $value) : string{
		if(is_object($value)){
			$id = spl_object_id($value);
			if(isset($this->objectBindings[$id])){
				return "\$b[" . $this->objectBindings[$id] . "]";
			}
			$this->objectBindings[$id] = count($this->bindings);
		}
		$this->bindings[] = $value;
		return "\$b[" . (count($this->bindings) - 1) . "]";
	}

	/**
	 * Expression evaluated at most once per block: a local variable of the generated method, set on first use.
	 */
	public function lazy(string $expression) : string{
		$local = $this->lazyLocals[$expression] ??= "\$l" . count($this->lazyLocals);
		return "($local ??= $expression)";
	}

	/**
	 * A new temporary variable name for the generated code.
	 */
	public function temp() : string{
		return "\$t" . $this->temps++;
	}

	/**
	 * PHP expression computing a density function at the block ($x, $y, $z) with the same operations (and float
	 * results) as DensityFunction::compute(). Interpolated nodes take their value from the chunk's interpolation
	 * cache, once per block; node types without an inline form are called through their compute().
	 */
	public function density(DensityFunction $f, InterpolationCache $cache) : string{
		if($f instanceof ConstantFunction){
			return self::float($f->value);
		}
		if($f instanceof NamedFunction){
			return $this->density($f->input, $cache);
		}
		if($f instanceof CacheFunction){
			return $this->density($f->input, $cache);
		}
		if($f instanceof InterpolatedFunction){
			return $this->lazy($this->bind($cache) . "->value(" . $this->bind($f) . ", \$x, \$y, \$z)");
		}
		if($f instanceof RangeChoiceFunction){
			$t = $this->temp();
			return "((($t = " . $this->density($f->input, $cache) . ") >= " . self::float($f->minInclusive) . " && $t < " . self::float($f->maxExclusive) . ") ? " .
				$this->density($f->whenInRange, $cache) . " : " . $this->density($f->whenOutOfRange, $cache) . ")";
		}
		if($f instanceof ClampFunction){
			$t = $this->temp();
			return "((($t = " . $this->density($f->input, $cache) . ") < " . self::float($f->min) . ") ? " . self::float($f->min) . " : ($t > " . self::float($f->max) . " ? " . self::float($f->max) . " : $t))";
		}
		if($f instanceof UnaryFunction){
			$input = $this->density($f->input, $cache);
			return match($f->op){
				UnaryFunction::ABS => "abs($input)",
				UnaryFunction::NEGATE => "(-$input)",
				default => "\\" . UnaryFunction::class . "::apply(" . var_export($f->op, true) . ", $input)"
			};
		}
		if($f instanceof BinaryFunction){
			$a = $this->temp();
			$left = $this->density($f->left, $cache);
			$right = $this->density($f->right, $cache);
			switch($f->op){
				case BinaryFunction::ADD:
					return "($left + $right)";
				case BinaryFunction::SUB:
					return "($left - $right)";
				case BinaryFunction::MUL:
					return "((($a = $left) === 0.0) ? 0.0 : $a * $right)";
				case BinaryFunction::DIV:
					return "((($a = $left) === 0.0) ? 0.0 : $a / $right)";
				case BinaryFunction::MIN:
					$b = $this->temp();
					return "((($a = $left) < " . self::float($f->right->minValue) . ") ? $a : ((($b = $right) > $a) ? $a : $b))";
				case BinaryFunction::MAX:
					$b = $this->temp();
					return "((($a = $left) > " . self::float($f->right->maxValue) . ") ? $a : ((($b = $right) < $a) ? $a : $b))";
			}
		}
		//generic fallback: make the interpolated inputs of this subgraph use the cache, then evaluate it
		$nodes = InterpolationCache::collect($f);
		$inject = count($nodes) === 0 ? "" : $this->bind($cache) . "->inject(" . $this->bind($nodes) . ", \$x, \$y, \$z), ";
		return "(" . $inject . $this->bind($f) . "->compute((float) \$x, (float) \$y, (float) \$z))";
	}

	public static function float(float $value) : string{
		if(!is_finite($value)){
			if($value === INF || $value === -INF){
				return $value > 0 ? "INF" : "(-INF)";
			}
			throw new \InvalidArgumentException("Cannot compile NaN");
		}
		$code = var_export($value, true);
		return str_contains($code, ".") || str_contains($code, "E") || str_contains($code, "e") ? "(" . $code . ")" : "(" . $code . ".0)";
	}

	public static function create(MaterialRule $rule, string $cacheDir) : MaterialRule{
		$compiler = new self();
		$body = $rule->compile($compiler);
		$locals = "";
		foreach($compiler->lazyLocals as $local){
			$locals .= "\t\t$local = null;\n";
		}
		$source = "<?php\n\ndeclare(strict_types=1);\n\nnamespace " . self::NAMESPACE . ";\n\n" .
			"use pocketmine\\world\\generator\\vanilla\\surface\\MaterialContext;\n" .
			"use pocketmine\\world\\generator\\vanilla\\surface\\rule\\MaterialRule;\n\n" .
			"/** Generated from the vanilla material rules by " . self::class . ". */\n" .
			"final class __CLASS__ extends MaterialRule{\n" .
			"\t/** @param mixed[] \$bindings */\n" .
			"\tpublic function __construct(private array \$bindings){}\n\n" .
			"\tpublic function apply(MaterialContext \$c) : int{\n" .
			"\t\t\$b = \$this->bindings;\n" .
			"\t\t\$x = \$c->blockX;\n" .
			"\t\t\$y = \$c->blockY;\n" .
			"\t\t\$z = \$c->blockZ;\n" .
			$locals .
			$body .
			"\t\treturn -1;\n" .
			"\t}\n\n" .
			"\tpublic function compile(\\pocketmine\\world\\generator\\vanilla\\surface\\MaterialRuleCompiler \$compiler) : string{\n" .
			"\t\tthrow new \\LogicException(\"Already compiled\");\n" .
			"\t}\n" .
			"}\n";
		$className = "MaterialRules_" . substr(sha1(self::VERSION . $source), 0, 20);
		$fqcn = self::NAMESPACE . "\\" . $className;
		if(!class_exists($fqcn, false)){
			if(!is_dir($cacheDir)){
				@mkdir($cacheDir, 0777, true);
			}
			$path = $cacheDir . "/" . $className . ".php";
			if(!file_exists($path)){
				$tmp = $path . "." . spl_object_id($compiler) . ".tmp";
				Filesystem::safeFilePutContents($tmp, str_replace("__CLASS__", $className, $source));
				@rename($tmp, $path);
			}
			require_once $path;
		}
		if(!class_exists($fqcn, false) || !is_a($fqcn, MaterialRule::class, true)){
			throw new AssumptionFailedError("Generated material rules $fqcn could not be loaded");
		}
		$compiled = (new \ReflectionClass($fqcn))->newInstance($compiler->bindings);
		if(!$compiled instanceof MaterialRule){
			throw new AssumptionFailedError("Generated material rules have the wrong type");
		}
		return $compiled;
	}
}
