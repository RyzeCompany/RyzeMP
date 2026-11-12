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

/**
 * A parsed vanilla IntProvider: the sampler and its value range.
 */
final class IntProvider{
	/**
	 * @phpstan-param \Closure(FeatureRandom) : int $sample
	 */
	public function __construct(
		public readonly \Closure $sample,
		public readonly int $min,
		public readonly int $max
	){}

	public function sample(FeatureRandom $random) : int{
		return ($this->sample)($random);
	}

	public static function constant(int $value) : self{
		return new self(fn(FeatureRandom $random) : int => $value, $value, $value);
	}
}
