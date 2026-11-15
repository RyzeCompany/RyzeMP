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

namespace pocketmine\entity;

use PHPUnit\Framework\TestCase;
use pocketmine\math\AxisAlignedBB;
use pocketmine\utils\Random;
use function function_exists;
use function is_array;
use function is_nan;
use function opcache_get_status;
use const INF;
use const NAN;

/**
 * Entity::move() clips its movement with private helpers instead of calling AxisAlignedBB::calculate[XYZ]Offset() for
 * each box: they must answer exactly what the calls answer, including touching and inverted boxes.
 */
final class EntityMoveClipEquivalenceTest extends TestCase{

	private static function value(Random $random, bool $nonFinite) : float{
		return match($random->nextBoundedInt(14)){
			0 => 0.0,
			1 => -0.0,
			2 => 1.0,
			3 => 0.5,
			4 => $nonFinite ? NAN : 1e300,
			5 => $nonFinite ? INF : -1e300,
			6 => $nonFinite ? -INF : 2.0,
			7 => (float) $random->nextBoundedInt(3),
			default => $random->nextSignedFloat() * 2
		};
	}

	private static function box(Random $random, bool $special, bool $nonFinite) : AxisAlignedBB{
		if($special){
			//any values, inverted boxes too (the fields are public)
			$bb = new AxisAlignedBB(0, 0, 0, 0, 0, 0);
			$bb->minX = self::value($random, $nonFinite);
			$bb->minY = self::value($random, $nonFinite);
			$bb->minZ = self::value($random, $nonFinite);
			$bb->maxX = self::value($random, $nonFinite);
			$bb->maxY = self::value($random, $nonFinite);
			$bb->maxZ = self::value($random, $nonFinite);
			return $bb;
		}
		//boxes on a grid of halves, so that many of them touch exactly
		$x = $random->nextBoundedInt(6) * 0.5 - 1.5;
		$y = $random->nextBoundedInt(6) * 0.5 - 1.5;
		$z = $random->nextBoundedInt(6) * 0.5 - 1.5;
		return new AxisAlignedBB($x, $y, $z, $x + $random->nextBoundedInt(4) * 0.5, $y + $random->nextBoundedInt(4) * 0.5, $z + $random->nextBoundedInt(4) * 0.5);
	}

	private static function assertSameFloat(float $expected, float $actual, string $message) : void{
		if(is_nan($expected)){
			self::assertNan($actual, $message);
		}else{
			self::assertSame($expected, $actual, $message);
		}
	}

	private function check(bool $nonFinite) : void{
		$clip = [];
		foreach(["X", "Y", "Z"] as $axis){
			$clip[$axis] = (new \ReflectionMethod(Entity::class, "clipOffset$axis"))->getClosure(null);
		}
		$random = new Random(1023);
		for($i = 0; $i < 20000; ++$i){
			$special = $random->nextBoundedInt(5) === 0;
			$list = [];
			for($n = $random->nextBoundedInt(6); $n > 0; --$n){
				$list[] = self::box($random, $special && $random->nextBoolean(), $nonFinite);
			}
			$moveBB = self::box($random, $special && $random->nextBoolean(), $nonFinite);
			$d = $special && $random->nextBoolean() ? self::value($random, $nonFinite) : $random->nextSignedFloat() * 1.5;

			$x = $y = $z = $d;
			foreach($list as $bb){
				$x = $bb->calculateXOffset($moveBB, $x);
				$y = $bb->calculateYOffset($moveBB, $y);
				$z = $bb->calculateZOffset($moveBB, $z);
			}
			self::assertSameFloat($x, $clip["X"]($list, $moveBB, $d), "X #$i");
			self::assertSameFloat($y, $clip["Y"]($list, $moveBB, $d), "Y #$i");
			self::assertSameFloat($z, $clip["Z"]($list, $moveBB, $d), "Z #$i");
		}
	}

	public function testSameOffsetsAsAxisAlignedBB() : void{
		$this->check(false);
	}

	/**
	 * With NaN and infinite coordinates. The tracing JIT of PHP 8.4 doesn't give the same answers for those from one
	 * call of AxisAlignedBB::calculateZOffset() to the next (the same calls in two loops can differ), so this is only
	 * checked without it.
	 */
	public function testSameOffsetsWithNonFiniteValues() : void{
		$status = function_exists("opcache_get_status") ? opcache_get_status(false) : false;
		if(is_array($status) && ($status["jit"]["on"] ?? false) === true){
			self::markTestSkipped("the JIT answers differently for non-finite values from one call to the next");
		}
		$this->check(true);
	}
}
