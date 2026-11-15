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

use pocketmine\block\Block;
use pocketmine\entity\Location;
use pocketmine\entity\object\ArmorStand as ArmorStandEntity;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\vibration\GameEvent;
use function fmod;
use function round;

class ArmorStand extends Item{

	public function getMaxStackSize() : int{
		return 16;
	}

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		if(!$blockReplace->canBeReplaced()){
			return ItemUseResult::NONE;
		}
		$pos = $blockReplace->getPosition();
		$world = $player->getWorld();
		//the stand needs two free blocks
		$aboveReplace = $world->getBlock($pos->up());
		if(!$aboveReplace->canBeReplaced()){
			return ItemUseResult::FAIL;
		}
		$box = new AxisAlignedBB($pos->x + 0.25, $pos->y, $pos->z + 0.25, $pos->x + 0.75, $pos->y + 1.975, $pos->z + 0.75);
		foreach($world->getCollidingEntities($box) as $entity){
			if($entity instanceof ArmorStandEntity){
				return ItemUseResult::FAIL;
			}
		}

		//faces the player, rounded to 45 degrees like vanilla
		$yaw = fmod(round(($player->getLocation()->getYaw() + 180) / 45) * 45, 360);
		$entity = new ArmorStandEntity(Location::fromObject($pos->add(0.5, 0, 0.5), $world, $yaw, 0));
		if($this->hasCustomName()){
			$entity->setNameTag($this->getCustomName());
		}
		$entity->spawnToAll();
		$entity->playPlaceSound();
		$world->addGameEvent($entity->getPosition(), GameEvent::ENTITY_PLACE, $player);
		$this->pop();
		return ItemUseResult::SUCCESS;
	}
}
