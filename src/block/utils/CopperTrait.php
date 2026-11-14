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

namespace pocketmine\block\utils;

use pocketmine\block\Block;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Axe;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\format\Chunk;
use pocketmine\world\particle\CopperWaxParticle;
use function abs;
use function mt_getrandmax;
use function mt_rand;

trait CopperTrait{
	private CopperOxidation $oxidation = CopperOxidation::NONE;
	private bool $waxed = false;

	public function describeBlockItemState(RuntimeDataDescriber $w) : void{
		$w->enum($this->oxidation);
		$w->bool($this->waxed);
	}

	public function getOxidation() : CopperOxidation{ return $this->oxidation; }

	/** @return $this */
	public function setOxidation(CopperOxidation $oxidation) : self{
		$this->oxidation = $oxidation;
		return $this;
	}

	public function isWaxed() : bool{ return $this->waxed; }

	/** @return $this */
	public function setWaxed(bool $waxed) : self{
		$this->waxed = $waxed;
		return $this;
	}

	/**
	 * @param Item[] &$returnedItems
	 * @see Block::onInteract()
	 */
	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if(!$this->waxed && $item->getTypeId() === ItemTypeIds::HONEYCOMB){
			$this->waxed = true;
			$this->position->getWorld()->setBlock($this->position, $this);
			//only the level event: the client plays the sound with the particles (BDS sends no separate sound for these
			//feedback events, checked for bone meal, turtle eggs and lightning)
			$this->position->getWorld()->addParticle($this->position, new CopperWaxParticle(CopperWaxParticle::WAX_ON));
			$item->pop();
			return true;
		}

		if($item instanceof Axe){
			if($this->waxed){
				$this->waxed = false;
				$this->position->getWorld()->setBlock($this->position, $this);
				$this->position->getWorld()->addParticle($this->position, new CopperWaxParticle(CopperWaxParticle::WAX_OFF));
				$item->applyDamage(1);
				return true;
			}

			$previousOxidation = $this->oxidation->getPrevious();
			if($previousOxidation !== null){
				$this->oxidation = $previousOxidation;
				$this->position->getWorld()->setBlock($this->position, $this);
				$this->position->getWorld()->addParticle($this->position, new CopperWaxParticle(CopperWaxParticle::SCRAPE));
				$item->applyDamage(1);
				return true;
			}
		}

		return false;
	}

	/**
	 * Unwaxed copper weathers on random ticks until it is fully oxidized: only those states are registered for random
	 * ticks.
	 */
	public function ticksRandomly() : bool{
		return !$this->waxed && $this->oxidation->getNext() !== null;
	}

	public function onRandomTick() : void{
		$next = $this->getWeatheringResult();
		if($next !== null){
			$this->oxidation = $next;
			$this->position->getWorld()->setBlock($this->position, $this);
		}
	}

	/**
	 * Vanilla weathering, for copper blocks that oxidize over time (call it from onRandomTick()).
	 * Returns the next oxidation stage if the block should oxidize on this random tick, null otherwise.
	 *
	 * Like vanilla, there is a 1 in ~17.6 chance to try; the attempt fails if an unwaxed copper block within 4 blocks
	 * (Manhattan distance) is less oxidized, and otherwise succeeds with a chance of ((more oxidized + 1) / (same or
	 * more oxidized + 1))², times 0.75 for unoxidized copper.
	 */
	protected function getWeatheringResult() : ?CopperOxidation{
		$next = $this->oxidation->getNext();
		if($this->waxed || $next === null || mt_rand() / mt_getrandmax() >= 0.05688889){
			return null;
		}
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$minY = $world->getMinY();
		$maxY = $world->getMaxY();
		$oxidation = $this->oxidation->value;
		$same = 0;
		$higher = 0;
		//unwaxed copper blocks within 4 blocks (Manhattan distance), read by state ID (unloaded chunks are skipped)
		for($dx = -4; $dx <= 4; ++$dx){
			$restX = 4 - abs($dx);
			for($dz = -$restX; $dz <= $restX; ++$dz){
				$chunk = $world->getChunk(($x + $dx) >> Chunk::COORD_BIT_SIZE, ($z + $dz) >> Chunk::COORD_BIT_SIZE);
				if($chunk === null){
					continue;
				}
				$localX = ($x + $dx) & Chunk::COORD_MASK;
				$localZ = ($z + $dz) & Chunk::COORD_MASK;
				$restY = $restX - abs($dz);
				for($dy = -$restY; $dy <= $restY; ++$dy){
					$blockY = $y + $dy;
					if(($dx === 0 && $dy === 0 && $dz === 0) || $blockY < $minY || $blockY >= $maxY){
						continue;
					}
					$other = CopperWeathering::getUnwaxedOxidation($chunk->getBlockStateId($localX, $blockY, $localZ));
					if($other === CopperWeathering::NOT_WEATHERING_COPPER){
						continue;
					}
					if($other < $oxidation){
						return null;
					}
					if($other > $oxidation){
						++$higher;
					}else{
						++$same;
					}
				}
			}
		}
		$chance = ($higher + 1) / ($higher + $same + 1);
		$chance *= $chance;
		if($this->oxidation === CopperOxidation::NONE){
			$chance *= 0.75;
		}
		return mt_rand() / mt_getrandmax() < $chance ? $next : null;
	}
}
