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

namespace pocketmine\world\mobs\spawn;

/**
 * Bedrock slime chunks. Unlike Java Edition they do not depend on the world seed: the chunk is a slime chunk when the
 * first output of a Mersenne Twister (MT19937) seeded with (chunkX * 0x1f1f1f1f) ^ chunkZ (32-bit unsigned) is a
 * multiple of 10, i.e. about 1 chunk in 10.
 *
 * Reverse engineered by @protolambda and @jocopa3 (https://gist.github.com/protolambda/00b85bf34a75fd8176342b1ad28bfccc),
 * as used by https://github.com/depressed-pho/slime-finder-pe, whose known slime chunks are checked by the tests.
 */
final class SlimeChunk{
	private const MASK = 0xffffffff;
	private const M = 397;

	private function __construct(){
		//NOOP
	}

	public static function isSlimeChunk(int $chunkX, int $chunkZ) : bool{
		$seed = ((($chunkX & self::MASK) * 0x1f1f1f1f) & self::MASK) ^ ($chunkZ & self::MASK);
		return self::firstMt19937Output($seed) % 10 === 0;
	}

	/**
	 * First 32-bit output of MT19937 seeded with $seed. Only the state words 0, 1 and 397 are needed for it.
	 */
	public static function firstMt19937Output(int $seed) : int{
		$mt0 = $seed & self::MASK;
		$prev = $mt0;
		$mt1 = 0;
		$mtM = 0;
		for($i = 1; $i <= self::M; ++$i){
			//1812433253 * (prev ^ (prev >> 30)) + i, in 32 bits (the product of two 32-bit values fits in 63 bits)
			$prev = (1812433253 * ($prev ^ ($prev >> 30)) + $i) & self::MASK;
			if($i === 1){
				$mt1 = $prev;
			}
		}
		$mtM = $prev;
		$y = ($mt0 & 0x80000000) | ($mt1 & 0x7fffffff);
		$value = $mtM ^ ($y >> 1) ^ (($y & 1) !== 0 ? 0x9908b0df : 0);
		//tempering
		$value ^= $value >> 11;
		$value ^= ($value << 7) & 0x9d2c5680;
		$value ^= ($value << 15) & 0xefc60000;
		$value ^= $value >> 18;
		return $value & self::MASK;
	}
}
