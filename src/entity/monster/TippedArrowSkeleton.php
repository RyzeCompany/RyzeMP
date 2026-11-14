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

namespace pocketmine\entity\monster;

use pocketmine\entity\ai\hostile\Aim;
use pocketmine\entity\Living;
use pocketmine\item\PotionType;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use function mt_rand;

/**
 * A skeleton variant shooting tipped arrows (stray, bogged, parched). The arrow's potion is the shooter "aux_val" of
 * the Bedrock entity minus one; its effect lasts 1/8 of the potion's duration.
 */
abstract class TippedArrowSkeleton extends Skeleton{

	/**
	 * Potion of the arrows shot.
	 */
	abstract protected function getArrowPotion() : PotionType;

	/**
	 * Potion of the tipped arrows dropped when killed by a player.
	 */
	abstract protected function getDropPotion() : PotionType;

	protected function getMaxTippedArrowDrops() : int{
		return 1;
	}

	public function shootArrow(Living $target, float $distance) : void{
		if(Aim::shootArrow($this, $target, $this->getArrowPotion(), 1.6, $this->getMainHandItem()) !== null){
			$this->playMobSound(LevelSoundEvent::BOW);
		}
	}

	public function canBreathe() : bool{
		return true;
	}

	public function getDrops() : array{
		$drops = parent::getDrops();
		//bogged: killed_by_player_or_pets; stray and parched: killed_by_player
		if($this instanceof Bogged ? $this->wasRecentlyHitByPlayer() : $this->wasRecentlyHitByPlayerDirectly()){
			//looting_enchant with "limit": 1
			$count = mt_rand(0, $this->getMaxTippedArrowDrops()) + ($this->getLootingLevel() > 0 ? mt_rand(0, 1) : 0);
			if($count > 0){
				$drops[] = VanillaItems::TIPPED_ARROW()->setType($this->getDropPotion())->setCount($count);
			}
		}
		return $drops;
	}
}
