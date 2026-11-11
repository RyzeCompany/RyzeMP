<?php

/*
 * Adapted from VanillaMine's WeatherChangeEvent.
 * Copyright (c) PocketMine Team and VanillaMine contributors.
 * SPDX-License-Identifier: LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace pocketmine\event\world;

use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\world\World;

/**
 * Fired before a world's weather changes; cancellation preserves the old state.
 */
final class WeatherChangeEvent extends WorldEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		World $world,
		private bool $wasRaining,
		private bool $wasThundering,
		private bool $raining,
		private bool $thundering
	){
		parent::__construct($world);
	}

	public function wasRaining() : bool{ return $this->wasRaining; }
	public function wasThundering() : bool{ return $this->wasThundering; }
	public function isRaining() : bool{ return $this->raining; }
	public function isThundering() : bool{ return $this->thundering; }
}
