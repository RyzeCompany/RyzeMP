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

namespace pocketmine\world\generator\vanilla\compiler;

use pocketmine\world\generator\vanilla\density\DensityFunction;
use pocketmine\world\generator\vanilla\noise\BlendedNoise;
use pocketmine\world\generator\vanilla\noise\NoiseStack;

/**
 * Seed-bound data handed to a generated kernel's constructor.
 */
final class KernelBindings{

	/**
	 * @param NoiseStack[]      $noises
	 * @param BlendedNoise[]    $blendeds
	 * @param DensityFunction[] $fallbacks
	 * @phpstan-param list<NoiseStack>      $noises
	 * @phpstan-param list<BlendedNoise>    $blendeds
	 * @phpstan-param list<DensityFunction> $fallbacks
	 */
	public function __construct(
		public readonly array $noises,
		public readonly array $blendeds,
		public readonly array $fallbacks
	){}
}
