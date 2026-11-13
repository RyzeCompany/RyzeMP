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

use pocketmine\utils\AssumptionFailedError;
use pocketmine\world\generator\vanilla\math\Int64;
use function is_int;
use function md5;
use function unpack;

final class XoroshiroPositionalRandomFactory implements PositionalRandomFactory{

	public function __construct(
		private int $seedLo,
		private int $seedHi
	){}

	public function at(int $x, int $y, int $z) : XoroshiroRandom{
		return new XoroshiroRandom(Int64::positionSeed($x, $y, $z) ^ $this->seedLo, $this->seedHi);
	}

	public function fromHashOf(string $name) : XoroshiroRandom{
		$parts = unpack("J2", md5($name, true));
		if($parts === false || !isset($parts[1], $parts[2]) || !is_int($parts[1]) || !is_int($parts[2])){
			throw new AssumptionFailedError("Unpacking an md5 hash cannot fail");
		}
		return new XoroshiroRandom($parts[1] ^ $this->seedLo, $parts[2] ^ $this->seedHi);
	}
}
