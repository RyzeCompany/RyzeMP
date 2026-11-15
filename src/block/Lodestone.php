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

use pocketmine\block\tile\Lodestone as TileLodestone;
use pocketmine\item\Compass;
use pocketmine\item\Item;
use pocketmine\item\LodestoneCompass;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\PositionTrackingDB;
use pocketmine\world\sound\LodestoneCompassLinkSound;

/**
 * Lodestone. Using a compass on it turns one compass into a lodestone compass pointing at it; using a lodestone
 * compass on it re-links that compass. Breaking it unlinks every compass pointing at it.
 */
class Lodestone extends Opaque{

	/**
	 * Returns the tracking handle of this lodestone, registering it in the position tracking database if needed.
	 */
	public function getOrCreateTrackingHandle() : ?int{
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if(!$tile instanceof TileLodestone){
			return null;
		}
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$db = PositionTrackingDB::getInstance();
		$handle = $tile->getTrackingHandle();
		$entry = $handle !== null ? $db->get($handle) : null;
		if($handle === null || $entry === null || $entry["world"] !== $world->getFolderName() || $entry["x"] !== $x || $entry["y"] !== $y || $entry["z"] !== $z){
			//new lodestone, or one whose handle belongs to another position (e.g. copied from another world)
			$handle = $db->register($world->getFolderName(), $x, $y, $z);
			$tile->setTrackingHandle($handle);
		}
		return $handle;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if(!$item instanceof Compass && !$item instanceof LodestoneCompass){
			return false;
		}
		$handle = $this->getOrCreateTrackingHandle();
		if($handle === null){
			return false;
		}

		if($item instanceof LodestoneCompass){
			$item->setTrackingHandle($handle);
		}else{
			$item->pop();
			$returnedItems[] = VanillaItems::LODESTONE_COMPASS()->setTrackingHandle($handle);
		}
		$this->position->getWorld()->addSound($this->position->add(0.5, 0.5, 0.5), new LodestoneCompassLinkSound());
		return true;
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$tile = $this->position->getWorld()->getTile($this->position);
		if($tile instanceof TileLodestone && ($handle = $tile->getTrackingHandle()) !== null){
			$entry = PositionTrackingDB::getInstance()->get($handle);
			if($entry !== null && $entry["world"] === $this->position->getWorld()->getFolderName() && $entry["x"] === $this->position->getFloorX() && $entry["y"] === $this->position->getFloorY() && $entry["z"] === $this->position->getFloorZ()){
				PositionTrackingDB::getInstance()->remove($handle);
			}
		}
		return parent::onBreak($item, $player, $returnedItems);
	}
}
