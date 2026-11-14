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
use pocketmine\block\utils\SupportType;
use pocketmine\block\utils\UnderwaterBoneMeal;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\particle\BoneMealUseParticle;

class Fertilizer extends Item{

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		//the clicked block didn't use the bone meal: like vanilla, grow water plants in front of a sturdy face
		if($blockClicked->getSupportType($face) === SupportType::FULL){
			$pos = $blockReplace->getPosition();
			if(UnderwaterBoneMeal::grow($pos->getWorld(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), $face, $this, $player)){
				$pos->getWorld()->addParticle($pos, new BoneMealUseParticle());
				return ItemUseResult::SUCCESS;
			}
		}
		return ItemUseResult::NONE;
	}
}
