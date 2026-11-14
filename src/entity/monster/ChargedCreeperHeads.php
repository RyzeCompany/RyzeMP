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

use pocketmine\block\utils\MobHeadType;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

/**
 * Mob heads dropped by mobs killed by a charged creeper's explosion: zombies, skeletons, wither skeletons, creepers and
 * piglins drop their head (not their variants: husks, strays, drowned... drop nothing extra). Bedrock drops a head
 * for every such mob the explosion kills (Java only for the first one, Creeper.increaseDroppedSkulls()).
 *
 * @internal
 */
final class ChargedCreeperHeads{

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the head of the mob type, or null if this type has none to drop.
	 */
	public static function getHeadType(string $networkTypeId) : ?MobHeadType{
		return match($networkTypeId){
			EntityIds::ZOMBIE => MobHeadType::ZOMBIE,
			EntityIds::SKELETON => MobHeadType::SKELETON,
			EntityIds::WITHER_SKELETON => MobHeadType::WITHER_SKELETON,
			EntityIds::CREEPER => MobHeadType::CREEPER,
			EntityIds::PIGLIN => MobHeadType::PIGLIN,
			default => null
		};
	}

	/**
	 * Whether the damage comes from the explosion of a charged creeper.
	 */
	public static function isChargedCreeperExplosion(?EntityDamageEvent $cause) : bool{
		if(!$cause instanceof EntityDamageByEntityEvent || $cause->getCause() !== EntityDamageEvent::CAUSE_ENTITY_EXPLOSION){
			return false;
		}
		$damager = $cause->getDamager();
		return $damager instanceof Creeper && $damager->isPowered();
	}

	/**
	 * Returns the head the mob drops if a charged creeper's explosion killed it, null otherwise.
	 */
	public static function getHeadDrop(Living $victim) : ?Item{
		$type = self::getHeadType($victim::getNetworkTypeId());
		if($type === null || !self::isChargedCreeperExplosion($victim->getLastDamageCause())){
			return null;
		}
		return VanillaBlocks::MOB_HEAD()->setMobHeadType($type)->asItem();
	}
}
