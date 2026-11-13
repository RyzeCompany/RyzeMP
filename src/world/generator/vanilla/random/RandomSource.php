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

/**
 * Random source with the semantics of the vanilla (1.18+) world generator random sources.
 */
interface RandomSource{

	/** Uniform 32-bit signed int. */
	public function nextInt() : int;

	/** Uniform int in [0, bound). */
	public function nextBoundedInt(int $bound) : int;

	public function nextLong() : int;

	public function nextFloat() : float;

	public function nextDouble() : float;

	public function consume(int $count) : void;

	public function fork() : RandomSource;

	public function forkPositional() : PositionalRandomFactory;
}
