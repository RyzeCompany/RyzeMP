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
namespace pocketmine\entity\effect;

use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageEvent;

/**
 * An effect that acts when the entity that has it takes damage (Infested). Checked only when damage was dealt, for the
 * entity's active effects.
 */
interface DamageAwareEffect{

	/**
	 * Called after the entity with this effect took damage and survived it.
	 */
	public function onEntityDamaged(Living $entity, EffectInstance $instance, EntityDamageEvent $source) : void;
}
