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

namespace pocketmine\event\entity;

use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;

/**
 * Called when a mob picks a new attack target (like Paper's EntityTargetLivingEntityEvent): a monster spotting a player,
 * a zombie going for a villager, a wolf hunting, a mob fighting back... Cancelling it keeps the mob without a target;
 * the target can also be replaced. NPC plugins use it so that mobs leave their NPCs alone.
 * @phpstan-extends EntityEvent<Mob>
 */
class EntityTargetEvent extends EntityEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		Mob $entity,
		private Living $target
	){
		$this->entity = $entity;
	}

	public function getTarget() : Living{
		return $this->target;
	}

	public function setTarget(Living $target) : void{
		$this->target = $target;
	}
}
