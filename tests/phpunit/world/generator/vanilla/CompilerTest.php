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
use pocketmine\world\generator\vanilla\compiler\CompiledKernel;
use pocketmine\world\generator\vanilla\compiler\DensityCompiler;
use pocketmine\world\generator\vanilla\density\BinaryFunction;
use pocketmine\world\generator\vanilla\density\BlendedNoiseFunction;
use pocketmine\world\generator\vanilla\density\CacheFunction;
use pocketmine\world\generator\vanilla\density\ClampFunction;
use pocketmine\world\generator\vanilla\density\ConstantFunction;
use pocketmine\world\generator\vanilla\density\CubicSpline;
use pocketmine\world\generator\vanilla\density\DensityFunction;
use pocketmine\world\generator\vanilla\density\GradientFunction;
use pocketmine\world\generator\vanilla\density\IntervalSelectFunction;
use pocketmine\world\generator\vanilla\density\LerpFunction;
use pocketmine\world\generator\vanilla\density\NamedFunction;
use pocketmine\world\generator\vanilla\density\NoiseFunction;
use pocketmine\world\generator\vanilla\density\RangeChoiceFunction;
use pocketmine\world\generator\vanilla\density\ShiftNoiseFunction;
use pocketmine\world\generator\vanilla\density\SplineFunction;
use pocketmine\world\generator\vanilla\density\UnaryFunction;
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\noise\BlendedNoise;
use pocketmine\world\generator\vanilla\noise\NormalNoiseParameters;
use pocketmine\world\generator\vanilla\random\XoroshiroRandom;
use function class_exists;
use function file_put_contents;
use function method_exists;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * The generated code must produce exactly the same values as the interpreted graph, in both scalar and batch form.
 */
class CompilerTest extends TestCase{

	private function buildGraph() : DensityFunction{
		$pos = XoroshiroRandom::create(7)->forkPositional();
		$noiseA = (new NormalNoiseParameters(1.0, -5, 3, NormalNoiseParameters::NORMALIZE_ENABLED, [1.0, 0.5, 1.0]))->create($pos->fromHashOf("a"));
		$noiseB = (new NormalNoiseParameters(1.5, -7, 2, NormalNoiseParameters::NORMALIZE_ENABLED, []))->create($pos->fromHashOf("b"));
		$shiftX = new CacheFunction(new ShiftNoiseFunction(ShiftNoiseFunction::MODE_A, "shift", $noiseB));
		$shiftZ = new CacheFunction(new ShiftNoiseFunction(ShiftNoiseFunction::MODE_B, "shift", $noiseB));
		$continents = new NamedFunction("test:continents", new CacheFunction(new NoiseFunction("b", $noiseB, 0.25, 0.0, $shiftX, null, $shiftZ)));
		$spline = new SplineFunction(new CubicSpline($continents,
			[FloatMath::fround(-0.5), FloatMath::fround(0.0), FloatMath::fround(0.4)],
			[FloatMath::fround(0.1), new CubicSpline($continents, [FloatMath::fround(-0.2), FloatMath::fround(0.2)], [FloatMath::fround(0.5), FloatMath::fround(-0.3)], [0.0, FloatMath::fround(0.7)]), FloatMath::fround(0.9)],
			[FloatMath::fround(0.3), 0.0, FloatMath::fround(-1.2)]
		));
		$gradient = new GradientFunction(GradientFunction::AXIS_Y, GradientFunction::TILING_CLAMP, -64, 320, 1.5, -1.5);
		$depth = new NamedFunction("test:depth", new BinaryFunction(BinaryFunction::ADD, $gradient, $spline));
		$noise3d = new NoiseFunction("a", $noiseA, 1.0, 0.5, null, null, null);
		$blended = new BlendedNoiseFunction(new BlendedNoise($pos->fromHashOf("terrain"), 0.25, 0.125, 80, 160, 8));
		$caves = new IntervalSelectFunction($noise3d, [-0.2, 0.2], [
			new UnaryFunction(UnaryFunction::SQUARE, $noise3d),
			new UnaryFunction(UnaryFunction::CUBE, $blended),
			new ClampFunction(new UnaryFunction(UnaryFunction::QUARTER_NEGATIVE, $depth), -0.3, 0.3)
		]);
		$main = new RangeChoiceFunction($depth, -1000000.0, 0.2,
			new BinaryFunction(BinaryFunction::MIN, $depth, new BinaryFunction(BinaryFunction::MUL, $caves, new ConstantFunction(5.0))),
			new BinaryFunction(BinaryFunction::MAX, $blended, $caves)
		);
		$lerp = new LerpFunction(new GradientFunction(GradientFunction::AXIS_Y, GradientFunction::TILING_CLAMP, 0, 40, 0.0, 1.0), new ConstantFunction(0.1171875), $main);
		return new UnaryFunction(UnaryFunction::SQUEEZE, new BinaryFunction(BinaryFunction::MUL, $lerp, $depth));
	}

	public function testCompiledMatchesInterpreted() : void{
		foreach([false, true] as $inline){
			DensityCompiler::$inlineOctaves = $inline;
			$graph = $this->buildGraph();
			$compiler = new DensityCompiler();
			$compiler->compileRoot($graph, "scalar");
			$compiler->compileBatchRoot($graph, "batch");
			foreach(["temperature", "vegetation", "continents", "erosion", "depth", "ridges", "aquiferBarrier", "aquiferFloodedness", "aquiferSpread", "aquiferLava", "aquiferExclusion", "aquiferSurfaceLevel"] as $name){
				$compiler->compileRoot(new ConstantFunction(0.0), $name);
			}
			$compiler->addMethod("\tpublic function fillDensity(int \$baseX, int \$baseZ) : array{\n\t\treturn [];\n\t}\n");
			$className = "CompilerTestKernel_" . uniqid();
			$file = sys_get_temp_dir() . "/" . $className . ".php";
			file_put_contents($file, $compiler->buildClass($className, "pocketmine\\world\\generator\\vanilla\\compiled"));
			try{
				require $file;
			}finally{
				@unlink($file);
			}
			$fqcn = "pocketmine\\world\\generator\\vanilla\\compiled\\" . $className;
			self::assertTrue(class_exists($fqcn, false));
			$kernel = (new \ReflectionClass($fqcn))->newInstance($compiler->getBindings());
			self::assertInstanceOf(CompiledKernel::class, $kernel);
			self::assertTrue(method_exists($kernel, "scalar") && method_exists($kernel, "batch"));

			$ys = [];
			for($y = -64; $y <= 320; $y += 8){
				$ys[] = (float) $y;
			}
			foreach([[0, 0], [-1234, 5678], [99999, -31], [4, -4]] as [$x, $z]){
				$batch = $kernel->batch((float) $x, (float) $z, $ys);
				foreach($ys as $i => $y){
					$expected = $graph->compute($x, $y, $z);
					self::assertSame($expected, $kernel->scalar((float) $x, $y, (float) $z), "scalar at $x $y $z");
					self::assertSame($expected, $batch[$i], "batch at $x $y $z");
				}
			}
		}
		DensityCompiler::$inlineOctaves = false;
	}
}
