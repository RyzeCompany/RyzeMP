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

namespace pocketmine\block;

use pocketmine\block\dispenser\DefaultDispenseBehavior;
use pocketmine\block\dispenser\DispenseContext;
use pocketmine\block\utils\ItemTransfer;
use pocketmine\item\Item;
use pocketmine\math\Facing;

/**
 * Dropper: works like a dispenser, but always drops the item, or puts it into the container (or chest/hopper minecart)
 * it faces (and does nothing if that container is full).
 */
class Dropper extends Dispenser{

	protected function dispenseItem(DispenseContext $context, Item $stack) : ?Item{
		$world = $context->getWorld();
		$target = $context->getTargetPosition();
		$x = $target->getFloorX();
		$y = $target->getFloorY();
		$z = $target->getFloorZ();
		if(ItemTransfer::canReceiveItems($world, $x, $y, $z)){
			if(!ItemTransfer::insertOne($world, $x, $y, $z, Facing::opposite($context->getFacing()), $stack)){
				return null;
			}
			$context->playEffects();
			return $stack->setCount($stack->getCount() - 1);
		}
		//a chest or hopper minecart in front (vanilla HopperBlockEntity.getContainerAt() includes container entities)
		$minecart = ItemTransfer::getEntityContainerAt($world, $x, $y, $z);
		if($minecart !== null){
			if(!ItemTransfer::insertOneIntoEntity($minecart, $stack)){
				return null;
			}
			$context->playEffects();
			return $stack->setCount($stack->getCount() - 1);
		}
		return (new DefaultDispenseBehavior())->dispense($context, $stack);
	}
}
