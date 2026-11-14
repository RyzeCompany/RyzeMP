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

namespace pocketmine\entity\effect;

use pocketmine\entity\boss\Wither;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\object\DragonBreathCloud;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;

/**
 * Shared results of Instant Health and Instant Damage (Java HealOrHarmMobEffect): healing restores 4 << amplifier
 * health, harming deals 6 << amplifier magic damage, both scaled by the potency (splash distance, lingering clouds).
 * Undead mobs are hurt by Instant Health and healed by Instant Damage.
 *
 * @internal
 */
final class InstantHealOrHarm{

	private function __construct(){
		//NOOP
	}

	public static function apply(bool $harm, Living $entity, EffectInstance $instance, float $potency, ?Entity $source) : void{
		if($harm !== Wither::isUndead($entity)){
			self::harm($entity, (6 << $instance->getAmplifier()) * $potency, $source);
		}elseif($entity->getHealth() < $entity->getMaxHealth()){
			$entity->heal(new EntityRegainHealthEvent($entity, (4 << $instance->getAmplifier()) * $potency, EntityRegainHealthEvent::CAUSE_MAGIC));
		}
	}

	private static function harm(Living $entity, float $damage, ?Entity $source) : void{
		if($source !== null){
			$sourceOwner = $source->getOwningEntity();
			//dragon's breath has its own damage type (vanilla dragon_breath), other clouds and potions are magic
			$cause = $source instanceof DragonBreathCloud ? EntityDamageEvent::CAUSE_DRAGON_BREATH : EntityDamageEvent::CAUSE_MAGIC;
			if($sourceOwner !== null){
				$ev = new EntityDamageByChildEntityEvent($sourceOwner, $source, $entity, $cause, $damage);
			}else{
				$ev = new EntityDamageByEntityEvent($source, $entity, $cause, $damage);
			}
		}else{
			$ev = new EntityDamageEvent($entity, EntityDamageEvent::CAUSE_MAGIC, $damage);
		}
		$entity->attack($ev);
	}
}
