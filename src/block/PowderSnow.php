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

use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\event\entity\EntityExtinguishEvent;
use pocketmine\item\Item;
use pocketmine\player\Player;
use pocketmine\world\GameRules;
use pocketmine\world\particle\BlockBreakParticle;
use pocketmine\world\sound\BucketFillPowderSnowSound;
use pocketmine\world\sound\Sound;
use function floor;

/**
 * Powder snow. It has no collision on the server, so entities and items sink through it; players wearing leather
 * boots can stand and walk on it because the client handles their movement. Entities inside it are extinguished (the
 * fire melts the block, see {@link PowderSnow::onEntityInside()}) and take no fall damage, and freeze (see {@link Living::doFreezeTick()}). It drops nothing, even with silk touch, and is
 * picked up with an empty bucket.
 *
 * Mobs inside it are slowed down; foxes, rabbits, endermites, silverfish and mobs wearing leather boots walk on it
 * (see {@link Mob::canStandOnPowderSnow()}).
 */
class PowderSnow extends Transparent{

	protected function recalculateCollisionBoxes() : array{
		return [];
	}

	public function isSolid() : bool{
		return false;
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	public function onEntityInside(Entity $entity) : bool{
		$entity->resetFallDistance();
		if($entity instanceof Living){
			$entity->setInPowderSnow();
		}
		if($entity instanceof Mob && (int) floor($entity->getLocation()->y) === $this->position->getFloorY()){
			//only while its feet are in it, like vanilla; players are slowed down by their client
			$entity->makeStuckInBlock($this, 0.9, 1.5, 0.9);
		}
		if($entity->isOnFire()){
			$entity->extinguish(EntityExtinguishEvent::CAUSE_POWDER_SNOW);
			//the fire melts the powder snow (vanilla PowderSnowBlock.entityInside()): always for players, mobgriefing for
			//anything else
			$world = $this->position->getWorld();
			if($entity instanceof Player || GameRules::allowsMobGriefing($world)){
				$world->setBlock($this->position, VanillaBlocks::AIR());
				$world->addParticle($this->position->add(0.5, 0.5, 0.5), new BlockBreakParticle($this));
				return false;
			}
		}
		return true;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	public function isAffectedBySilkTouch() : bool{
		return false;
	}

	public function getBucketFillSound() : Sound{
		return new BucketFillPowderSnowSound();
	}
}
