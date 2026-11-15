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

use pocketmine\block\BlockTypeIds;
use pocketmine\entity\Ageable;
use pocketmine\entity\animal\AbstractHorse;
use pocketmine\entity\animal\HappyGhast;
use pocketmine\entity\animal\Pig;
use pocketmine\entity\animal\Strider;
use pocketmine\entity\animal\TameableAnimal;
use pocketmine\entity\animal\Wolf;
use pocketmine\entity\Living;
use pocketmine\entity\monster\ZombieNautilus;
use pocketmine\entity\riding\MountInventoryHolder;
use pocketmine\inventory\HorseInventory;
use pocketmine\item\Harness;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\math\AxisAlignedBB;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\world\sound\MobSound;

/**
 * Dispensers put animal gear on the first animal in front that can take it into an empty slot, like a player using it
 * (Java EquipmentDispenseItemBehavior, and DispenseItemBehavior for chests): saddles on pigs, striders, camels and
 * tamed horses, donkeys, mules and nautiluses, horse and nautilus armor on those that wear it, carpets on tamed
 * llamas, wolf armor on tamed wolves, a harness on a happy ghast, and a chest on tamed donkeys, mules and llamas.
 * Babies take nothing. Without such an animal the item is dropped like any other.
 *
 * @internal
 */
final class AnimalEquipmentDispenseBehavior implements DispenseBehavior{

	/**
	 * Types of the items this behaviour is registered for (the chest and the carpet are block items).
	 *
	 * @return int[]
	 */
	public static function getItemTypeIds() : array{
		return [
			ItemTypeIds::SADDLE,
			ItemTypeIds::LEATHER_HORSE_ARMOR,
			ItemTypeIds::IRON_HORSE_ARMOR,
			ItemTypeIds::GOLDEN_HORSE_ARMOR,
			ItemTypeIds::DIAMOND_HORSE_ARMOR,
			ItemTypeIds::COPPER_HORSE_ARMOR,
			ItemTypeIds::NETHERITE_HORSE_ARMOR,
			ItemTypeIds::COPPER_NAUTILUS_ARMOR,
			ItemTypeIds::IRON_NAUTILUS_ARMOR,
			ItemTypeIds::GOLDEN_NAUTILUS_ARMOR,
			ItemTypeIds::DIAMOND_NAUTILUS_ARMOR,
			ItemTypeIds::NETHERITE_NAUTILUS_ARMOR,
			ItemTypeIds::WOLF_ARMOR,
			ItemTypeIds::HARNESS,
			ItemTypeIds::fromBlockTypeId(BlockTypeIds::CARPET),
			ItemTypeIds::fromBlockTypeId(BlockTypeIds::CHEST)
		];
	}

	public function __construct(
		private DispenseBehavior $fallback
	){}

	public function dispense(DispenseContext $context, Item $stack) : ?Item{
		$target = $context->getTargetPosition();
		$box = new AxisAlignedBB($target->x, $target->y, $target->z, $target->x + 1, $target->y + 1, $target->z + 1);
		$single = (clone $stack)->setCount(1);
		foreach($context->getWorld()->getNearbyEntities($box) as $entity){
			if($entity instanceof Living && $entity->isAlive() && !$entity->isFlaggedForDespawn() && self::equip($entity, $single)){
				$context->playEffects();
				return $stack->setCount($stack->getCount() - 1);
			}
		}
		return $this->fallback->dispense($context, $stack);
	}

	/**
	 * Puts the item (a single one) on the animal if it can take it now, with the sound of a player doing it. Returns
	 * whether it did.
	 */
	public static function equip(Living $entity, Item $item) : bool{
		if($entity instanceof Ageable && $entity->isBaby()){
			return false;
		}
		$typeId = $item->getTypeId();
		if($entity instanceof Pig || $entity instanceof Strider){
			if($typeId !== ItemTypeIds::SADDLE || $entity->isSaddled()){
				return false;
			}
			$entity->setSaddled(true);
			self::playSound($entity, LevelSoundEvent::SADDLE);
			return true;
		}
		if($entity instanceof Wolf){
			if($typeId !== ItemTypeIds::WOLF_ARMOR || !$entity->isTamed() || $entity->getWolfArmor() !== null){
				return false;
			}
			$entity->setWolfArmor($item);
			self::playSound($entity, LevelSoundEvent::ARMOR_EQUIP_WOLF);
			return true;
		}
		if($entity instanceof HappyGhast){
			if(!$item instanceof Harness || $entity->getHarness() !== null){
				return false;
			}
			$entity->setHarness($item);
			self::playSound($entity, LevelSoundEvent::ARMOR_EQUIP_GENERIC);
			return true;
		}
		if($typeId === ItemTypeIds::fromBlockTypeId(BlockTypeIds::CHEST)){
			if(!$entity instanceof AbstractHorse || !$entity->canHaveChest() || $entity->isChested() || !$entity->isTamed()){
				return false;
			}
			$entity->setChested(true);
			self::playSound($entity, LevelSoundEvent::ADD_CHEST);
			return true;
		}
		if($entity instanceof MountInventoryHolder && self::isTamedMount($entity)){
			//the mount's inventory listener plays the equip sound
			$inventory = $entity->getInventory();
			if($inventory->isSlotEmpty(HorseInventory::SLOT_EQUIP) && $entity->canHoldInSlot(HorseInventory::SLOT_EQUIP, $item)){
				$inventory->setItem(HorseInventory::SLOT_EQUIP, $item);
				return true;
			}
			if($entity->hasArmorSlot() && $inventory->slotExists(HorseInventory::SLOT_ARMOR) && $inventory->isSlotEmpty(HorseInventory::SLOT_ARMOR) && $entity->canHoldInSlot(HorseInventory::SLOT_ARMOR, $item)){
				$inventory->setItem(HorseInventory::SLOT_ARMOR, $item);
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a mount may be equipped: horses, donkeys, mules, llamas and nautiluses once tamed, camels always.
	 */
	private static function isTamedMount(MountInventoryHolder $mount) : bool{
		return match(true){
			$mount instanceof AbstractHorse => $mount->isTamed() || !$mount->isTameable(),
			$mount instanceof TameableAnimal, $mount instanceof ZombieNautilus => $mount->isTamed(),
			default => true
		};
	}

	private static function playSound(Living $entity, string $sound) : void{
		$entity->getWorld()->addSound($entity->getPosition(), new MobSound($sound, $entity::getNetworkTypeId()));
	}
}
