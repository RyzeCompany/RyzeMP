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

namespace pocketmine\world\generator\vanilla\random;

use pocketmine\world\generator\vanilla\math\Int64;

/**
 * The 48-bit linear congruential generator of java.util.Random, used by the legacy (pre-1.18 seeded) noises.
 */
final class LegacyRandom implements RandomSource{
	private const MULTIPLIER = 0x5DEECE66D;
	private const INCREMENT = 0xB;
	private const MASK = (1 << 48) - 1;

	private int $seed;

	public function __construct(int $seed){
		$this->setSeed($seed);
	}

	public function setSeed(int $seed) : void{
		$this->seed = ($seed ^ self::MULTIPLIER) & self::MASK;
	}

	private function next(int $bits) : int{
		$this->seed = (Int64::mul($this->seed, self::MULTIPLIER) + self::INCREMENT) & self::MASK;
		return Int64::toInt32($this->seed >> (48 - $bits));
	}

	public function consume(int $count) : void{
		for($i = 0; $i < $count; ++$i){
			$this->next(1);
		}
	}

	public function nextInt() : int{
		return $this->next(32);
	}

	public function nextBoundedInt(int $bound) : int{
		if($bound <= 0){
			throw new \InvalidArgumentException("Bound must be positive");
		}
		if(($bound & ($bound - 1)) === 0){
			return Int64::toInt32(($bound * $this->next(31)) >> 31);
		}
		do{
			$bits = $this->next(31);
			$value = $bits % $bound;
		}while(Int64::toInt32($bits - $value + ($bound - 1)) < 0);
		return $value;
	}

	public function nextLong() : int{
		$hi = $this->next(32);
		$lo = $this->next(32);
		return Int64::add($hi << 32, $lo);
	}

	public function nextBoolean() : bool{
		return $this->next(1) !== 0;
	}

	public function nextFloat() : float{
		return $this->next(24) * 5.9604644775390625E-8;
	}

	public function nextDouble() : float{
		$hi = $this->next(26);
		$lo = $this->next(27);
		return (($hi << 27) + $lo) * 1.1102230246251565E-16;
	}

	public function fork() : RandomSource{
		return new self($this->nextLong());
	}

	public function forkPositional() : PositionalRandomFactory{
		$seed = $this->nextLong();
		return new class($seed) implements PositionalRandomFactory{
			public function __construct(private int $seed){}

			public function at(int $x, int $y, int $z) : RandomSource{
				return new LegacyRandom(Int64::positionSeed($x, $y, $z) ^ $this->seed);
			}

			public function fromHashOf(string $name) : RandomSource{
				return new LegacyRandom(Int64::javaStringHash($name) ^ $this->seed);
			}
		};
	}
}
