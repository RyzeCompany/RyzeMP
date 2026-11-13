<?php

/*
 * Adapted from VanillaMine (LGPL-3.0-or-later).
 * Copyright (c) PocketMine Team and VanillaMine contributors.
 */
declare(strict_types=1);

namespace pocketmine\event\world;

use pocketmine\entity\object\LightningBolt;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;

/** Plugins may cancel a lightning strike before the bolt spawns. */
final class LightningStrikeEvent extends WorldEvent implements Cancellable{
	use CancellableTrait;

	public const CAUSE_WEATHER = 0;
	public const CAUSE_TRAP = 1;
	public const CAUSE_CUSTOM = 2;
	public const CAUSE_TRIDENT = 3;

	public function __construct(private LightningBolt $bolt, private int $cause){
		parent::__construct($bolt->getWorld());
	}

	public function getBolt() : LightningBolt{ return $this->bolt; }
	public function getCause() : int{ return $this->cause; }
}
