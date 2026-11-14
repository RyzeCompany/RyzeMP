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

namespace pocketmine\item;

use function min;

/**
 * Wings worn in the chest slot that let the player glide.
 *
 * Vanilla (https://minecraft.wiki/w/Elytra): 432 durability on Java, 433 on Bedrock; loses 1 durability per second
 * of gliding; never breaks: at 1 durability left it stops working until repaired with phantom membranes.
 * Gives no armor points and is not damaged by attacks.
 */
class Elytra extends Armor{

	/**
	 * Returns whether the elytra can be used to glide (it has more than 1 durability left).
	 */
	public function canGlide() : bool{
		return $this->damage < $this->getMaxDurability() - 1;
	}

	public function applyDamage(int $amount) : bool{
		if($this->isUnbreakable() || !$this->canGlide()){
			return false;
		}

		$amount -= $this->getUnbreakingDamageReduction($amount);

		//elytra never break: they stop at 1 durability left
		$this->damage = min($this->damage + $amount, $this->getMaxDurability() - 1);

		return true;
	}

	protected function getUnbreakingDamageReduction(int $amount) : int{
		//elytra use the tool rule for Unbreaking (1 / (level + 1) chance to take damage), not the armor one
		return Durable::getUnbreakingDamageReduction($amount);
	}

	/**
	 * Returns whether the given item can repair this elytra in an anvil.
	 */
	public function isValidRepairMaterial(Item $item) : bool{
		return $item->getTypeId() === ItemTypeIds::PHANTOM_MEMBRANE;
	}
}
