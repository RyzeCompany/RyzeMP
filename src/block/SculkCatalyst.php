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

use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\world\particle\SculkCatalystBloomParticle;
use pocketmine\world\sound\SculkCatalystBloomSound;

/**
 * Sculk catalyst (https://minecraft.wiki/w/Sculk_Catalyst), ported from Java's SculkCatalystBlock(Entity).
 *
 * When a mob which drops experience dies within 8 blocks (the closest catalyst wins), the catalyst absorbs the
 * experience instead of letting it drop, blooms (the "bloom" state for 8 ticks, soul particles and sound) and spreads
 * sculk, sculk veins and occasionally sensors and shriekers from where the mob died, with as much charge as experience
 * (see {@link \pocketmine\block\utils\SculkSpreader}). The charges move every other tick through scheduled block
 * updates of the catalyst; they are kept in memory only.
 */
class SculkCatalyst extends Opaque{
	public const BLOOM_TICKS = 8;

	protected bool $bloom = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->bool($this->bloom);
	}

	public function isBlooming() : bool{ return $this->bloom; }

	/** @return $this */
	public function setBlooming(bool $bloom) : self{
		$this->bloom = $bloom;
		return $this;
	}

	public function getLightLevel() : int{
		return 6;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	public function getXpDropAmount() : int{
		return 5;
	}

	/**
	 * Absorbs the experience of a mob which died near the catalyst: the sculk charge starts at the given position.
	 */
	public function absorbExperience(int $x, int $y, int $z, int $xp) : void{
		$world = $this->position->getWorld();
		$world->getVibrations()->getCatalystSpreader($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ())->addCursors($x, $y, $z, $xp);
		$world->getVibrations()->setCatalystBloomEnd($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ(), $world->getServer()->getTick() + self::BLOOM_TICKS);
		if(!$this->bloom){
			$this->bloom = true;
			$world->setBlock($this->position, $this);
		}
		$world->scheduleDelayedBlockUpdate($this->position, 1);
		$world->addParticle($this->position->add(0.5, 1.15, 0.5), new SculkCatalystBloomParticle());
		$world->addSound($this->position->add(0.5, 0.5, 0.5), new SculkCatalystBloomSound());
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$now = $world->getServer()->getTick();
		$vibrations = $world->getVibrations();
		$spreading = false;
		if($vibrations->hasCatalystSpreader($x, $y, $z)){
			$spreader = $vibrations->getCatalystSpreader($x, $y, $z);
			$spreader->updateCursors($world, $x, $y, $z);
			if($spreader->hasCursors()){
				$spreading = true;
			}else{
				$vibrations->removeCatalystSpreader($x, $y, $z);
			}
		}

		//the spread may have replaced this block (it never does in vanilla, but a plugin could)
		$current = $world->getBlockAt($x, $y, $z);
		if(!$current instanceof SculkCatalyst){
			return;
		}
		if($current->bloom){
			$bloomEnd = $vibrations->getCatalystBloomEnd($x, $y, $z, $now + self::BLOOM_TICKS);
			if($now >= $bloomEnd){
				$current->bloom = false;
				$world->setBlock($this->position, $current);
				$vibrations->clearCatalystBloomEnd($x, $y, $z);
			}elseif(!$spreading){
				$world->scheduleDelayedBlockUpdate($this->position, $bloomEnd - $now);
			}
		}
		if($spreading){
			$world->scheduleDelayedBlockUpdate($this->position, 1);
		}
	}

	public function onNearbyBlockChange() : void{
		parent::onNearbyBlockChange();
		//scheduled updates are not saved: a catalyst reloaded while blooming must still stop
		if($this->bloom){
			$world = $this->position->getWorld();
			if(!$world->isBlockUpdateScheduled($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ())){
				$world->scheduleDelayedBlockUpdate($this->position, 1);
			}
		}
	}
}
