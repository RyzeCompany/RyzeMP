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

use pocketmine\world\generator\vanilla\math\Int64;
use pocketmine\world\generator\vanilla\random\XoroshiroRandom;
use function log;
use function sqrt;

/**
 * Vanilla's WorldgenRandom over a Xoroshiro128++ source, as used for biome decoration (ChunkGenerator
 * .applyBiomeDecoration): every value comes from next(bits) = (int) (xoroshiro.nextLong() >>> (64 - bits)), and the
 * int/float/double/long/gaussian helpers are the java.util.Random style ones of BitRandomSource / LegacyRandomSource.
 *
 * Note: the gaussian cache is NOT reset by setSeed() (WorldgenRandom overrides setSeed and only reseeds the source),
 * so it carries over between features of the same chunk, like in vanilla.
 */
final class FeatureRandom{
	private const SILVER_RATIO_64 = 7640891576956012809;
	private const GOLDEN_RATIO_64 = -7046029254386353131;

	private int $lo = 0;
	private int $hi = 0;
	private bool $haveNextNextGaussian = false;
	private float $nextNextGaussian = 0.0;

	public function __construct(int $seed = 0){
		$this->setSeed($seed);
	}

	public function setSeed(int $seed) : void{
		$lo = $seed ^ self::SILVER_RATIO_64;
		$hi = Int64::add($lo, self::GOLDEN_RATIO_64);
		$this->lo = XoroshiroRandom::mixStafford13($lo);
		$this->hi = XoroshiroRandom::mixStafford13($hi);
		if($this->lo === 0 && $this->hi === 0){
			$this->lo = self::GOLDEN_RATIO_64;
			$this->hi = self::SILVER_RATIO_64;
		}
	}

	/**
	 * Raw Xoroshiro128++ output.
	 */
	public function rawLong() : int{
		$lo = $this->lo;
		$hi = $this->hi;
		//result = rotl(lo + hi, 17) + lo
		$s = (($lo & 0xffffffff) + ($hi & 0xffffffff));
		$sum = (((($lo >> 32) + ($hi >> 32) + ($s >> 32)) & 0xffffffff) << 32) | ($s & 0xffffffff);
		$rot = ($sum << 17) | (($sum >> 47) & 0x1ffff);
		$s = (($rot & 0xffffffff) + ($lo & 0xffffffff));
		$result = (((($rot >> 32) + ($lo >> 32) + ($s >> 32)) & 0xffffffff) << 32) | ($s & 0xffffffff);
		$hi ^= $lo;
		$this->lo = (($lo << 49) | (($lo >> 15) & 0x1ffffffffffff)) ^ $hi ^ ($hi << 21);
		$this->hi = ($hi << 28) | (($hi >> 36) & 0xfffffff);
		return $result;
	}

	/**
	 * (int) (nextLong() >>> (64 - bits)), for 1 <= bits <= 32.
	 */
	public function next(int $bits) : int{
		$v = ($this->rawLong() >> (64 - $bits)) & ((1 << $bits) - 1);
		return $bits === 32 && $v >= 0x80000000 ? $v - 0x100000000 : $v;
	}

	public function nextInt() : int{
		return $this->next(32);
	}

	/**
	 * Uniform int in [0, bound) (java.util.Random algorithm).
	 */
	public function nextBoundedInt(int $bound) : int{
		if($bound <= 0){
			throw new \InvalidArgumentException("Bound must be positive");
		}
		if(($bound & ($bound - 1)) === 0){
			//(int) ((bound * (long) next(31)) >> 31)
			return ($bound * $this->next(31)) >> 31;
		}
		do{
			$sample = $this->next(31);
			$modulo = $sample % $bound;
			$check = $sample - $modulo + ($bound - 1);
		}while($check >= 0x80000000); //int overflow in Java
		return $modulo;
	}

	/** RandomSource.nextIntBetweenInclusive / Mth.randomBetweenInclusive */
	public function nextIntBetweenInclusive(int $min, int $max) : int{
		return $this->nextBoundedInt($max - $min + 1) + $min;
	}

	/** RandomSource.nextInt(origin, bound) */
	public function nextIntRange(int $origin, int $bound) : int{
		if($origin >= $bound){
			throw new \InvalidArgumentException("bound - origin is non positive");
		}
		return $origin + $this->nextBoundedInt($bound - $origin);
	}

	/** Mth.nextInt(random, min, max): min if min >= max */
	public function mthNextInt(int $min, int $max) : int{
		return $min >= $max ? $min : $this->nextBoundedInt($max - $min + 1) + $min;
	}

	public function nextLong() : int{
		$upper = $this->next(32);
		$lower = $this->next(32);
		return Int64::add($upper << 32, $lower);
	}

	public function nextBoolean() : bool{
		return $this->next(1) !== 0;
	}

	/** float32 in [0, 1) */
	public function nextFloat() : float{
		return $this->next(24) * 5.9604644775390625E-8;
	}

	public function nextDouble() : float{
		$upper = $this->next(26);
		$lower = $this->next(27);
		return (($upper << 27) + $lower) * 1.1102230246251565E-16;
	}

	public function nextGaussian() : float{
		if($this->haveNextNextGaussian){
			$this->haveNextNextGaussian = false;
			return $this->nextNextGaussian;
		}
		do{
			$x = 2.0 * $this->nextDouble() - 1.0;
			$y = 2.0 * $this->nextDouble() - 1.0;
			$r = $x * $x + $y * $y;
		}while($r >= 1.0 || $r === 0.0);
		$multiplier = sqrt(-2.0 * log($r) / $r);
		$this->nextNextGaussian = $y * $multiplier;
		$this->haveNextNextGaussian = true;
		return $x * $multiplier;
	}

	/**
	 * Pending second value of nextGaussian() (the native decoration continues the same sequence).
	 * @phpstan-return array{bool, float}
	 */
	public function getGaussianState() : array{
		return [$this->haveNextNextGaussian, $this->nextNextGaussian];
	}

	public function setGaussianState(bool $have, float $value) : void{
		$this->haveNextNextGaussian = $have;
		$this->nextNextGaussian = $value;
	}

	public function consumeCount(int $count) : void{
		for($i = 0; $i < $count; ++$i){
			$this->next(32);
		}
	}

	/** RandomSource.triangle(mean, spread) for doubles */
	public function triangle(float $mean, float $spread) : float{
		return $mean + $spread * ($this->nextDouble() - $this->nextDouble());
	}

	/**
	 * WorldgenRandom.setDecorationSeed(levelSeed, minBlockX, minBlockZ): returns the decoration seed.
	 */
	public function setDecorationSeed(int $levelSeed, int $x, int $z) : int{
		$this->setSeed($levelSeed);
		$a = $this->nextLong() | 1;
		$b = $this->nextLong() | 1;
		$seed = Int64::add(Int64::mul($x, $a), Int64::mul($z, $b)) ^ $levelSeed;
		$this->setSeed($seed);
		return $seed;
	}

	/**
	 * WorldgenRandom.setFeatureSeed(decorationSeed, index, step).
	 */
	public function setFeatureSeed(int $decorationSeed, int $index, int $step) : void{
		$this->setSeed(Int64::add($decorationSeed, $index + 10000 * $step));
	}

	/**
	 * WorldgenRandom.fork(): a plain Xoroshiro source (with its own nextInt(bound) semantics).
	 */
	public function fork() : XoroshiroRandom{
		return new XoroshiroRandom($this->rawLong(), $this->rawLong());
	}
}
