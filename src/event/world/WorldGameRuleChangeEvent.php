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

use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\world\World;

/**
 * Called when a game rule of a world is changed with {@link World::setGameRule()} (e.g. /gamerule). The value can be
 * replaced; it must have the rule's type (bool or int).
 */
final class WorldGameRuleChangeEvent extends WorldEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		World $world,
		private string $rule,
		private bool|int $oldValue,
		private bool|int $newValue
	){
		parent::__construct($world);
	}

	/** Name of the rule, see {@link \pocketmine\world\GameRules}. */
	public function getRule() : string{ return $this->rule; }

	public function getOldValue() : bool|int{ return $this->oldValue; }

	public function getNewValue() : bool|int{ return $this->newValue; }

	public function setNewValue(bool|int $newValue) : void{ $this->newValue = $newValue; }
}
