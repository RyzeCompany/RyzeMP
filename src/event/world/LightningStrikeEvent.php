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

namespace pocketmine\event\world;

use pocketmine\entity\object\LightningBolt;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;

/**
 * Called when a lightning bolt is about to strike (before it is spawned). Cancelling it prevents the strike.
 */
final class LightningStrikeEvent extends WorldEvent implements Cancellable{
	use CancellableTrait;

	/** Struck by a thunderstorm. */
	public const CAUSE_WEATHER = 0;
	/** Visual bolt of a skeleton trap going off. */
	public const CAUSE_TRAP = 1;
	/** Spawned by a command or a plugin. */
	public const CAUSE_CUSTOM = 2;
	/** Called by a trident with Channeling in a thunderstorm. */
	public const CAUSE_TRIDENT = 3;

	public function __construct(
		private LightningBolt $bolt,
		private int $cause
	){
		parent::__construct($bolt->getWorld());
	}

	public function getBolt() : LightningBolt{ return $this->bolt; }

	public function getCause() : int{ return $this->cause; }
}
