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

namespace pocketmine\entity;

use pocketmine\entity\animal\SulfurCube;
use pocketmine\entity\object\LeashKnot;
use pocketmine\entity\vehicle\Boat;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\math\AxisAlignedBB;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\sound\ItemBreakSound;
use pocketmine\world\sound\MobSound;
use pocketmine\world\vibration\GameEvent;

/**
 * What players do with leads on entities besides leashing and letting go (Bedrock 1.21.90 Chase the Skies,
 * minecraft:leashable_to): a sneaking player leading mobs uses a boat, an adult happy ghast or another leashable_to mob
 * to tie the mobs it leads nearby (within their lead's length) to that entity instead of a fence (towing boats with a
 * happy ghast), and shears cut every lead of the entity they are used on, the one leading it and those it holds (Java
 * Entity.shearOffAllLeashConnections(): the leads drop, the shears snip and take 1 damage).
 *
 * @internal
 */
final class LeashInteractions{

	/**
	 * Entities with minecraft:leashable_to whatever their state (BP 1.26.50).
	 */
	private const LEASHABLE_TO_ALWAYS = [
		EntityIds::ALLAY => true,
		EntityIds::BOAT => true,
		EntityIds::CHEST_BOAT => true,
		EntityIds::COPPER_GOLEM => true,
		EntityIds::FROG => true,
		EntityIds::IRON_GOLEM => true,
		EntityIds::SNOW_GOLEM => true,
		EntityIds::ZOMBIE_NAUTILUS => true
	];

	/**
	 * Entities with minecraft:leashable_to in their adult component group (BP 1.26.50).
	 */
	private const LEASHABLE_TO_ADULTS = [
		EntityIds::ARMADILLO => true,
		EntityIds::AXOLOTL => true,
		EntityIds::BEE => true,
		EntityIds::CAMEL => true,
		EntityIds::CAT => true,
		EntityIds::CHICKEN => true,
		EntityIds::COW => true,
		EntityIds::DONKEY => true,
		EntityIds::FOX => true,
		EntityIds::GLOW_SQUID => true,
		EntityIds::GOAT => true,
		EntityIds::HAPPY_GHAST => true,
		EntityIds::HOGLIN => true,
		EntityIds::HORSE => true,
		EntityIds::LLAMA => true,
		EntityIds::MOOSHROOM => true,
		EntityIds::MULE => true,
		EntityIds::NAUTILUS => true,
		EntityIds::OCELOT => true,
		EntityIds::PARROT => true,
		EntityIds::PIG => true,
		EntityIds::POLAR_BEAR => true,
		EntityIds::RABBIT => true,
		EntityIds::SHEEP => true,
		EntityIds::SKELETON_HORSE => true,
		EntityIds::SNIFFER => true,
		EntityIds::SQUID => true,
		EntityIds::STRIDER => true,
		EntityIds::TRADER_LLAMA => true,
		EntityIds::WOLF => true,
		EntityIds::ZOGLIN => true,
		EntityIds::ZOMBIE_HORSE => true
	];

	/** Range of the leads of an entity that shears cut (the longest Bedrock lead breaks beyond 14 blocks). */
	private const SHEAR_RANGE = 16.0;
	/** Range around a leashable_to entity in which the mobs a player leads are tied to it (the longest lead). */
	private const TIE_SEARCH_RANGE = 14.0;

	/** @phpstan-var \WeakMap<Player, int>|null */
	private static ?\WeakMap $playerLeads = null;

	private function __construct(){
		//NOOP
	}

	/**
	 * Whether the leads a player holds can be tied to the entity (Bedrock minecraft:leashable_to).
	 */
	public static function isLeashableTo(Entity $entity) : bool{
		if(!$entity->isAlive() || $entity->isClosed() || $entity->isFlaggedForDespawn()){
			return false;
		}
		$id = $entity::getNetworkTypeId();
		if(isset(self::LEASHABLE_TO_ALWAYS[$id])){
			return true;
		}
		if(isset(self::LEASHABLE_TO_ADULTS[$id])){
			return !$entity instanceof Ageable || !$entity->isBaby();
		}
		return match($id){
			//camel_husk_with_no_hostile_rider, sulfur_cube_medium_with_block
			EntityIds::CAMEL_HUSK => $entity instanceof Leashable && $entity->canBeLeashed(),
			EntityIds::SULFUR_CUBE => $entity instanceof SulfurCube && !$entity->isBaby() && $entity->getAbsorbedBlock() !== null,
			default => false
		};
	}

	/**
	 * Handles a player using the entity with the item: tying the mobs the player leads to it, or cutting its leads
	 * with shears. Returns whether something happened.
	 */
	public static function interact(Entity $entity, Player $player, Item $item) : bool{
		if(self::tieLeashedMobs($player, $entity)){
			return true;
		}
		return $item->getTypeId() === ItemTypeIds::SHEARS && self::cutLeads($entity, $player);
	}

	/**
	 * Keeps count of the entities each player leads, so that interactions of players leading nothing (feeding,
	 * breeding, riding...) don't look for them. Called by {@link LeashTrait} whenever a holder changes.
	 */
	public static function onLeashHolderChanged(?Entity $old, ?Entity $new) : void{
		if($old === $new){
			return;
		}
		$counts = self::$playerLeads ??= new \WeakMap();
		if($old instanceof Player && isset($counts[$old])){
			if($counts[$old] <= 1){
				unset($counts[$old]);
			}else{
				$counts[$old]--;
			}
		}
		if($new instanceof Player){
			$counts[$new] = ($counts[$new] ?? 0) + 1;
		}
	}

	/**
	 * Returns how many entities the player leads (at most: an entity unloaded while led still counts).
	 */
	public static function getLeadCount(Player $player) : int{
		return self::$playerLeads[$player] ?? 0;
	}

	/**
	 * Ties the mobs the player leads around the entity if it is leashable_to and the player sneaks (Java
	 * Entity.interact(): isSecondaryUseActive()). Returns whether any was tied.
	 */
	public static function tieLeashedMobs(Player $player, Entity $target) : bool{
		if(!$player->isSneaking() || self::getLeadCount($player) === 0 || !self::isLeashableTo($target) || !$target instanceof Leashable || !$target->canBeLeashed()){
			return false;
		}
		$tied = false;
		//Java Leashable.leashableInArea(): the player's mobs around the target, within their lead's length of it
		$pos = $target->getPosition();
		$r = self::TIE_SEARCH_RANGE;
		foreach($target->getWorld()->getNearbyEntities(new AxisAlignedBB($pos->x - $r, $pos->y - $r, $pos->z - $r, $pos->x + $r, $pos->y + $r, $pos->z + $r), $target) as $mob){
			//the users of LeashTrait; Java Leashable.canHaveALeashAttachedTo(): not to an entity the mob holds, and
			//within the lead's length
			if(
				($mob instanceof Mob || $mob instanceof Squid || $mob instanceof Boat) && $mob->getLeashHolder() === $player && $mob->canBeLeashed() &&
				$target->getLeashHolder() !== $mob && $pos->distance($mob->getPosition()) <= $mob->getLeashBreakDistance()
			){
				$mob->setLeashHolder($target);
				$tied = true;
			}
		}
		if($tied){
			$target->getWorld()->addSound($target->getPosition(), new MobSound(LevelSoundEvent::LEAD_LEASH, $target::getNetworkTypeId()));
			$target->getWorld()->addGameEvent($target->getPosition(), GameEvent::ENTITY_ACTION, $player);
		}
		return $tied;
	}

	/**
	 * Cuts every lead of the entity: the one leading it and those it holds. The leads drop and the shears in the
	 * player's hand take 1 damage. Returns whether there was any lead.
	 */
	public static function cutLeads(Entity $entity, ?Player $player) : bool{
		$cut = false;
		if($entity instanceof Leashable && $entity->isLeashed()){
			$entity->unleash(true);
			$cut = true;
		}
		foreach(LeashKnot::getMobsLeashedTo($entity, self::SHEAR_RANGE) as $mob){
			$mob->unleash(true);
			$cut = true;
		}
		if(!$cut){
			return false;
		}
		//Java Entity.shearOffAllLeashConnections(): the shears snip, dropAllLeashConnections(): GameEvent.SHEAR
		$entity->getWorld()->addSound($entity->getPosition(), new MobSound(LevelSoundEvent::SHEAR, $entity::getNetworkTypeId()));
		$entity->getWorld()->addGameEvent($entity->getPosition(), GameEvent::SHEAR, $player);
		if($player !== null && $player->hasFiniteResources()){
			$shears = $player->getInventory()->getItemInHand();
			if($shears instanceof Durable){
				$shears->applyDamage(1);
				if($shears->isBroken()){
					$player->broadcastSound(new ItemBreakSound());
				}
				$player->getInventory()->setItemInHand($shears);
			}
		}
		return true;
	}
}
