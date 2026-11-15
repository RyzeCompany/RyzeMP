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

namespace pocketmine\block\dispenser;

use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\utils\Utils;

/**
 * Drops one item in front of the dispenser, like a dropper. Used for every item without a special behaviour.
 */
class DefaultDispenseBehavior implements DispenseBehavior{
	/** Vanilla dispense speed used for the random spread of dropped items. */
	private const SPEED = 6;

	public function dispense(DispenseContext $context, Item $stack) : ?Item{
		$one = (clone $stack)->setCount(1);
		self::spawnItem($context, $one);
		$context->playEffects();
		return $stack->setCount($stack->getCount() - 1);
	}

	/**
	 * Spawns an item entity flying out of the front of the dispenser (vanilla DefaultDispenseItemBehavior.spawnItem).
	 */
	public static function spawnItem(DispenseContext $context, Item $item) : void{
		$facing = $context->getFacing();
		[$dx, , $dz] = Facing::OFFSET[$facing];
		$pos = $context->getDispensePosition();
		$pos = $pos->subtract(0, Facing::axis($facing) === Axis::Y ? 0.125 : 0.15625, 0);
		$speed = Utils::getRandomFloat() * 0.1 + 0.2;
		$spread = 0.0172275 * self::SPEED;
		$motion = new Vector3(
			self::triangle($dx * $speed, $spread),
			self::triangle(0.2, $spread),
			self::triangle($dz * $speed, $spread)
		);
		$context->getWorld()->dropItem($pos, $item, $motion);
	}

	private static function triangle(float $mean, float $spread) : float{
		return $mean + $spread * (Utils::getRandomFloat() - Utils::getRandomFloat());
	}
}
