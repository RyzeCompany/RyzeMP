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

namespace pocketmine\world\generator\vanilla\structure\placement;

/**
 * MT19937 (32-bit Mersenne Twister), the random generator Bedrock Edition uses for structure placement. Only the
 * standard init_genrand seeding is supported; outputs are unsigned 32-bit ints.
 */
final class MersenneTwister{
	private const N = 624;
	private const M = 397;

	/** @var int[] */
	private array $mt = [];
	private int $index = self::N;

	public function __construct(int $seed){
		$seed &= 0xffffffff;
		$mt = [$seed];
		for($i = 1; $i < self::N; ++$i){
			$prev = $mt[$i - 1] ^ ($mt[$i - 1] >> 30);
			//1812433253 * prev < 2^63: no overflow
			$mt[$i] = (1812433253 * $prev + $i) & 0xffffffff;
		}
		$this->mt = $mt;
	}

	private function twist() : void{
		$mt = $this->mt;
		for($i = 0; $i < self::N; ++$i){
			$y = ($mt[$i] & 0x80000000) | ($mt[($i + 1) % self::N] & 0x7fffffff);
			$value = $mt[($i + self::M) % self::N] ^ ($y >> 1);
			if(($y & 1) !== 0){
				$value ^= 0x9908b0df;
			}
			$mt[$i] = $value;
		}
		$this->mt = $mt;
		$this->index = 0;
	}

	public function nextUnsignedInt() : int{
		if($this->index >= self::N){
			$this->twist();
		}
		$y = $this->mt[$this->index++];
		$y ^= $y >> 11;
		$y ^= ($y << 7) & 0x9d2c5680;
		$y ^= ($y << 15) & 0xefc60000;
		$y ^= $y >> 18;
		return $y & 0xffffffff;
	}

	/**
	 * Bedrock's Random::nextInt(bound): genrand_int32() % bound.
	 */
	public function nextBoundedInt(int $bound) : int{
		return $bound <= 0 ? 0 : $this->nextUnsignedInt() % $bound;
	}
}
