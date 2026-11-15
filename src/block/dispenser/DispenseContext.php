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

use pocketmine\block\Block;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\particle\DispenseParticle;
use pocketmine\world\sound\ClickSound;
use pocketmine\world\sound\Sound;
use pocketmine\world\World;
use function cos;
use function log;
use function mt_getrandmax;
use function mt_rand;
use function sqrt;
use const M_PI;

/**
 * A dispenser (or dropper, or crafter) about to dispense an item.
 */
final class DispenseContext{

	public function __construct(
		private World $world,
		private Vector3 $position,
		private int $facing,
		private Inventory $inventory
	){
		$this->position = $position->floor();
	}

	public function getWorld() : World{ return $this->world; }

	/**
	 * Position of the dispensing block.
	 */
	public function getPosition() : Vector3{ return $this->position; }

	/**
	 * Direction the dispenser faces.
	 */
	public function getFacing() : int{ return $this->facing; }

	public function getInventory() : Inventory{ return $this->inventory; }

	/**
	 * Position of the block in front of the dispenser.
	 */
	public function getTargetPosition() : Vector3{
		return $this->position->getSide($this->facing);
	}

	public function getTargetBlock() : Block{
		return $this->world->getBlock($this->getTargetPosition());
	}

	/**
	 * Point items and projectiles come out of: 0.7 blocks from the centre of the dispenser, towards its front.
	 */
	public function getDispensePosition() : Vector3{
		[$dx, $dy, $dz] = Facing::OFFSET[$this->facing];
		return $this->position->add(0.5 + $dx * 0.7, 0.5 + $dy * 0.7, 0.5 + $dz * 0.7);
	}

	/**
	 * Puts an item produced while dispensing back into the dispenser, or drops it in front if it doesn't fit.
	 */
	public function addOrDrop(Item $item) : void{
		foreach($this->inventory->addItem($item) as $leftover){
			DefaultDispenseBehavior::spawnItem($this, $leftover);
		}
	}

	/**
	 * Plays the sound and smoke puff of a successful dispense.
	 */
	public function playEffects(?Sound $sound = null) : void{
		$center = $this->position->add(0.5, 0.5, 0.5);
		$this->world->addSound($center, $sound ?? new ClickSound());
		$this->world->addParticle($this->getDispensePosition(), new DispenseParticle($this->facing));
	}

	/**
	 * Normally distributed random number (mean 0, standard deviation 1).
	 */
	public static function nextGaussian() : float{
		$u1 = (mt_rand() + 1) / (mt_getrandmax() + 1);
		$u2 = mt_rand() / mt_getrandmax();
		return sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
	}
}
