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
use pocketmine\block\BlockTypeIds;
use pocketmine\entity\Location;
use pocketmine\entity\object\EndCrystal as EntityEndCrystal;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\dimension\DragonFight;
use pocketmine\world\vibration\GameEvent;
use function count;

/**
 * End crystal item: placed on top of obsidian or bedrock when the two blocks above are air and no entity is in the
 * way. In the End, placing the fourth crystal around the exit portal starts the ender dragon respawn (see
 * {@link DragonFight::tryRespawn()}).
 */
class EndCrystal extends Item{

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		if($blockClicked->getTypeId() === BlockTypeIds::OBSIDIAN || $blockClicked->getTypeId() === BlockTypeIds::BEDROCK){
			$pos = $blockClicked->getPosition();
			$world = $pos->getWorld();
			//the two blocks above the clicked block, where the crystal goes (vanilla checks the same box for entities)
			$bb = new AxisAlignedBB($pos->getX(), $pos->getY() + 1, $pos->getZ(), $pos->getX() + 1, $pos->getY() + 3, $pos->getZ() + 1);
			if(
				count($world->getNearbyEntities($bb)) === 0 &&
				$blockClicked->getSide(Facing::UP)->getTypeId() === BlockTypeIds::AIR &&
				$blockClicked->getSide(Facing::UP, 2)->getTypeId() === BlockTypeIds::AIR
			){
				$crystal = new EntityEndCrystal(Location::fromObject($pos->add(0.5, 1, 0.5), $world));
				$crystal->spawnToAll();
				$world->addGameEvent($pos->add(0, 1, 0), GameEvent::ENTITY_PLACE, $player);

				$this->pop();
				if($world->getDimension() === Dimension::END){
					DragonFight::getInstance()->tryRespawn($world);
				}
				return ItemUseResult::SUCCESS;
			}
		}
		return ItemUseResult::NONE;
	}
}
