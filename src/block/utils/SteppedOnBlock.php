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

namespace pocketmine\block\utils;

use pocketmine\entity\Entity;

/**
 * A block which acts on the entities standing on it (vanilla Block.stepOn()), like magma blocks hurting them, slime
 * slowing them down or turtle eggs being trampled.
 *
 * Every tick, an entity on the ground looks up the block under its feet (the one at 0.2 blocks below its position), and
 * calls {@link SteppedOnBlock::onEntityStepOn()} if that block implements this interface. Which block states implement
 * it is cached by state ID, so the other blocks cost nothing.
 */
interface SteppedOnBlock{

	/**
	 * Called every tick while the entity stands on this block (players included, also when they don't move).
	 */
	public function onEntityStepOn(Entity $entity) : void;
}
