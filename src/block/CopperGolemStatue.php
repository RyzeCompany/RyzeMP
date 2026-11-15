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

use pocketmine\block\utils\ComparatorReadable;
use pocketmine\block\utils\CopperMaterial;
use pocketmine\block\utils\CopperOxidation;
use pocketmine\block\utils\CopperTrait;
use pocketmine\block\utils\FacesOppositePlacingPlayerTrait;
use pocketmine\block\utils\HorizontalFacing;
use pocketmine\block\utils\SupportType;
use pocketmine\entity\golem\CopperGolem;
use pocketmine\entity\Location;
use pocketmine\item\Axe;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\sound\ScrapeSound;

/**
 * Copper golem statue. A decorative block that faces the player, oxidizes over time and can be waxed and scraped like
 * other copper blocks. Fully oxidized copper golems turn into statues; scraping an unwaxed, unoxidized statue with an
 * axe brings the copper golem back (vanilla WeatheringCopperGolemStatueBlock). The pose (kept in a block entity in
 * vanilla) is not implemented.
 */
class CopperGolemStatue extends Transparent implements HorizontalFacing, CopperMaterial, ComparatorReadable{
	use FacesOppositePlacingPlayerTrait;
	use CopperTrait{
		onInteract as copperOnInteract;
	}

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->contract(3 / 16, 0, 3 / 16)];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	/**
	 * Pose + 1 (vanilla CopperGolemStatueBlock.getAnalogOutputSignal(): 1 standing, 2 sitting, 3 running, 4 star). Poses
	 * are not implemented yet, so the statue always stands.
	 */
	public function getComparatorOutput() : int{
		return 1;
	}

	public function onRandomTick() : void{
		$next = $this->getWeatheringResult();
		if($next !== null){
			$this->oxidation = $next;
			$this->position->getWorld()->setBlock($this->position, $this);
		}
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($this->copperOnInteract($item, $face, $clickVector, $player, $returnedItems)){
			return true;
		}
		if($item instanceof Axe && !$this->waxed && $this->oxidation === CopperOxidation::NONE){
			$this->revive();
			$item->applyDamage(1);
			return true;
		}
		return false;
	}

	/**
	 * Removes the statue and spawns an unoxidized copper golem looking the same way.
	 */
	public function revive() : CopperGolem{
		$world = $this->position->getWorld();
		$world->setBlock($this->position, VanillaBlocks::AIR());
		$world->addSound($this->position, new ScrapeSound());
		$yaw = match($this->facing){
			Facing::SOUTH => 0,
			Facing::WEST => 90,
			Facing::NORTH => 180,
			default => 270
		};
		$golem = new CopperGolem(Location::fromObject($this->position->add(0.5, 0, 0.5), $world, $yaw, 0));
		$golem->spawnToAll();
		return $golem;
	}
}
