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

namespace pocketmine\item\enchantment;

use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\item\Item;

/**
 * In Bedrock a mob killed by the hit of a Fire Aspect weapon is already burning when it dies, so it drops cooked
 * food. The target is set on fire before the damage is applied; the full burn comes later from
 * {@link FireAspectEnchantment::onPostAttack()}.
 *
 * @internal
 */
final class FireAspectIgnition{

	private function __construct(){
		//NOOP
	}

	/**
	 * Sets the target on fire for a second if the weapon has Fire Aspect and the target is not burning yet. Returns
	 * whether it did, for {@link FireAspectIgnition::revert()}.
	 */
	public static function beforeHit(Entity $target, Item $weapon) : bool{
		if(!$target instanceof Living || $target->isOnFire() || !$weapon->hasEnchantment(VanillaEnchantments::FIRE_ASPECT())){
			return false;
		}
		$target->setOnFire(1);
		return $target->isOnFire();
	}

	/**
	 * Puts out the fire set by {@link FireAspectIgnition::beforeHit()} when the hit was cancelled.
	 */
	public static function revert(Entity $target, bool $ignited) : void{
		if($ignited && !$target->isClosed()){
			$target->setFireTicks(0);
		}
	}
}
