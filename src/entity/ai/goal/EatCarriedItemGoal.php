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

namespace pocketmine\entity\ai\goal;

use pocketmine\entity\animation\ConsumingItemAnimation;
use pocketmine\entity\Mob;
use pocketmine\item\Item;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;

/**
 * Eats the food the mob carries (vanilla behavior.eat_carried_item of foxes): once it held the food for $delay ticks
 * while $canEat allows it (on the ground, no target...), $eat consumes it. The last ticks show the eating particles
 * and play the eating sound (Java Fox.aiStep(): entity event 45 and the eating sound just before eating).
 *
 * It needs no control of its own: the mob may walk around while it chews. Without food it costs one call of $heldFood
 * per goal evaluation.
 */
class EatCarriedItemGoal extends Goal{
	/** Ticks before eating from which the eating particles and sound play. */
	private const CHEWING_TICKS = 12;

	private int $ticks = 0;

	/**
	 * @phpstan-param \Closure() : ?Item $heldFood returns the food carried by the mob, null when it carries none (or nothing edible)
	 * @phpstan-param \Closure(Item) : void $eat consumes the food
	 * @phpstan-param \Closure() : bool $canEat whether the mob may eat now
	 */
	public function __construct(
		protected Mob $mob,
		protected \Closure $heldFood,
		protected \Closure $eat,
		protected \Closure $canEat,
		protected int $delay = 28
	){}

	public function getFlags() : int{
		return 0;
	}

	public function canStart() : bool{
		return ($this->heldFood)() !== null && ($this->canEat)();
	}

	public function canContinue() : bool{
		return $this->canStart();
	}

	public function start() : void{
		$this->ticks = 0;
	}

	public function tick() : void{
		$food = ($this->heldFood)();
		if($food === null){
			return;
		}
		++$this->ticks;
		if($this->ticks >= $this->delay){
			$this->ticks = 0;
			($this->eat)($food);
			return;
		}
		if($this->ticks >= $this->delay - self::CHEWING_TICKS && $this->ticks % 4 === 0){
			$this->mob->broadcastAnimation(new ConsumingItemAnimation($this->mob, $food));
			$this->mob->playMobSound(LevelSoundEvent::EAT);
		}
	}
}
