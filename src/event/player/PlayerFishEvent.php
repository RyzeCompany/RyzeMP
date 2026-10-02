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

namespace pocketmine\event\player;

use pocketmine\entity\projectile\FishingHook;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\item\Item;
use pocketmine\player\Player;

/** Called before a successful catch is spawned. Cancellation consumes the bite without rewards. */
class PlayerFishEvent extends PlayerEvent implements Cancellable{
	use CancellableTrait;

	private Item $catch;

	public function __construct(Player $player, private FishingHook $hook, Item $catch, private int $experience){
		$this->player = $player;
		$this->catch = clone $catch;
		$this->setExperience($experience);
	}

	public function getHook() : FishingHook{
		return $this->hook;
	}

	public function getCatch() : Item{
		return clone $this->catch;
	}

	public function setCatch(Item $catch) : void{
		$this->catch = clone $catch;
	}

	public function getExperience() : int{
		return $this->experience;
	}

	public function setExperience(int $experience) : void{
		if($experience < 0){
			throw new \InvalidArgumentException("Fishing experience cannot be negative");
		}
		$this->experience = $experience;
	}
}
