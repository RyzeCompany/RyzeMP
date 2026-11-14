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

use pocketmine\block\utils\SteppedOnBlock;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByBlockEvent;
use pocketmine\event\entity\EntityDamageEvent;

class Magma extends Opaque implements SteppedOnBlock{

	public function getLightLevel() : int{
		return 3;
	}

	public function hasEntityCollision() : bool{
		return false; //it hurts the entities standing on it, not the ones inside it (see onEntityStepOn())
	}

	public function onEntityInside(Entity $entity) : bool{
		return true;
	}

	public function onEntityStepOn(Entity $entity) : void{
		//vanilla MagmaBlock.stepOn(): hot floor damage unless sneaking; Frost Walker boots, fire immunity and Fire
		//Resistance protect (checked here too, so that no damage event is fired every tick for them)
		if(
			$entity instanceof Living &&
			!$entity->isSneaking() &&
			!$entity->isFireProof() &&
			$entity->getFrostWalkerLevel() === 0 &&
			!$entity->getEffects()->has(VanillaEffects::FIRE_RESISTANCE())
		){
			$entity->attack(new EntityDamageByBlockEvent($this, $entity, EntityDamageEvent::CAUSE_MAGMA, 1));
		}
	}

	public function burnsForever() : bool{
		return true;
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		//builds a missing whirlpool above (e.g. ocean floors of chunks generated before bubble columns existed)
		BubbleColumn::onColumnSourceRandomTick($this);
	}
}
