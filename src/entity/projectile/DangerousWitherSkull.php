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

namespace pocketmine\entity\projectile;

use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

/**
 * The blue wither skull (Bedrock behavior_pack/entities/wither_skull_dangerous.json): half as fast as the black one
 * (power 0.6), its explosion treats blocks as if their blast resistance were at most 4 (it breaks obsidian), and
 * hitting it sends it back (reflect_on_hurt).
 */
class DangerousWitherSkull extends WitherSkull{
	public const SPEED = 0.6;
	/** wither_skull_dangerous.json explode max_resistance */
	public const MAX_BLAST_RESISTANCE = 4.0;

	public static function getNetworkTypeId() : string{ return EntityIds::WITHER_SKULL_DANGEROUS; }

	public function isDangerous() : bool{
		return true;
	}

	public function getMaxBlastResistance() : ?float{
		return self::MAX_BLAST_RESISTANCE;
	}

	/**
	 * Sends the skull back where the attacker is looking; the attacker becomes its owner.
	 */
	public function deflect(Entity $by) : void{
		$speed = $this->motion->length();
		$this->setMotion($by->getDirectionVector()->multiply($speed > 0.1 ? $speed : self::SPEED));
		$this->setOwningEntity($by);
	}

	public function attack(EntityDamageEvent $source) : void{
		if($source instanceof EntityDamageByEntityEvent && !$this->isFlaggedForDespawn()){
			$damager = $source->getDamager();
			if($damager instanceof Living){
				$source->cancel();
				$this->deflect($damager);
				return;
			}
		}
		parent::attack($source);
	}
}
