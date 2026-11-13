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
 * Xoroshiro128++ as used by the 1.18+ world generator (ported from misode/deepslate, MIT).
 */
final class XoroshiroRandom implements RandomSource{
	private const SILVER_RATIO_64 = 7640891576956012809;
	private const GOLDEN_RATIO_64 = -7046029254386353131;
	private const STAFFORD_1 = -4658895280553007687;
	private const STAFFORD_2 = -7723592293110705685;

	public function __construct(
		private int $seedLo,
		private int $seedHi
	){
		if($seedLo === 0 && $seedHi === 0){
			$this->seedLo = self::GOLDEN_RATIO_64;
			$this->seedHi = self::SILVER_RATIO_64;
		}
	}

	public static function create(int $seed) : self{
		$lo = $seed ^ self::SILVER_RATIO_64;
		$hi = Int64::add($lo, self::GOLDEN_RATIO_64);
		return new self(self::mixStafford13($lo), self::mixStafford13($hi));
	}

	public static function mixStafford13(int $value) : int{
		$value = Int64::mul($value ^ Int64::ushr($value, 30), self::STAFFORD_1);
		$value = Int64::mul($value ^ Int64::ushr($value, 27), self::STAFFORD_2);
		return $value ^ Int64::ushr($value, 31);
	}

	public function getSeedLo() : int{ return $this->seedLo; }

	public function getSeedHi() : int{ return $this->seedHi; }

	public function nextLong() : int{
		$lo = $this->seedLo;
		$hi = $this->seedHi;
		$result = Int64::add(Int64::rotl(Int64::add($lo, $hi), 17), $lo);
		$hi ^= $lo;
		$this->seedLo = Int64::rotl($lo, 49) ^ $hi ^ ($hi << 21);
		$this->seedHi = Int64::rotl($hi, 28);
		return $result;
	}

	public function consume(int $count) : void{
		for($i = 0; $i < $count; ++$i){
			$this->nextLong();
		}
	}

	public function nextInt() : int{
		return Int64::toInt32($this->nextLong());
	}

	public function nextBoundedInt(int $bound) : int{
		if($bound <= 0){
			throw new \InvalidArgumentException("Bound must be positive");
		}
		$value = $this->nextLong() & 0xffffffff;
		$product = $value * $bound;
		$productLo = $product & 0xffffffff;
		if($productLo < $bound){
			$threshold = (0x100000000 - $bound) % $bound;
			while($productLo < $threshold){
				$value = $this->nextLong() & 0xffffffff;
				$product = $value * $bound;
				$productLo = $product & 0xffffffff;
			}
		}
		return $product >> 32;
	}

	public function nextFloat() : float{
		return Int64::ushr($this->nextLong(), 40) * 5.9604644775390625E-8;
	}

	public function nextDouble() : float{
		return Int64::ushr($this->nextLong(), 11) * 1.1102230246251565E-16;
	}

	public function fork() : RandomSource{
		return new self($this->nextLong(), $this->nextLong());
	}

	public function forkPositional() : XoroshiroPositionalRandomFactory{
		return new XoroshiroPositionalRandomFactory($this->nextLong(), $this->nextLong());
	}
}
