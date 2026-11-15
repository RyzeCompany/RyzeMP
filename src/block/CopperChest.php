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

use pocketmine\block\tile\Chest as TileChest;
use pocketmine\block\utils\CopperMaterial;
use pocketmine\block\utils\CopperTrait;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\player\Player;

/**
 * Copper chest: a chest that pairs with copper chests of any oxidation like a normal chest, oxidizes over time and can
 * be waxed and scraped like other copper blocks. Both halves of a double chest always change together.
 */
class CopperChest extends Chest implements CopperMaterial{
	use CopperTrait{
		onInteract as copperOnInteract;
	}

	public function onRandomTick() : void{
		$next = $this->getWeatheringResult();
		if($next !== null){
			$this->oxidation = $next;
			$this->position->getWorld()->setBlock($this->position, $this);
			$this->syncPair();
		}
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($this->copperOnInteract($item, $face, $clickVector, $player, $returnedItems)){
			$this->syncPair();
			return true;
		}
		return parent::onInteract($item, $face, $clickVector, $player, $returnedItems);
	}

	/**
	 * Gives the other half of a double chest the same oxidation and wax as this one.
	 */
	private function syncPair() : void{
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if(!$tile instanceof TileChest || ($pair = $tile->getPair()) === null){
			return;
		}
		$pairBlock = $world->getBlock($pair->getPosition());
		if($pairBlock instanceof CopperChest && ($pairBlock->getOxidation() !== $this->oxidation || $pairBlock->isWaxed() !== $this->waxed)){
			$world->setBlock($pairBlock->getPosition(), $pairBlock->setOxidation($this->oxidation)->setWaxed($this->waxed));
		}
	}

	public function getFuelTime() : int{
		return 0;
	}
}
