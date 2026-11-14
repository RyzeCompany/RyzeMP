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

use pocketmine\data\SavedDataLoadingException;
use pocketmine\entity\ai\goal\PickupItemsGoal;
use pocketmine\entity\object\ItemEntity;
use pocketmine\event\entity\EntityItemPickupEvent;
use pocketmine\item\Item;
use pocketmine\item\Totem;
use pocketmine\item\VanillaItems;
use pocketmine\loot\bedrock\BedrockEquipmentTables;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\player\Player;
use pocketmine\world\GameRules;
use pocketmine\world\RegionalDifficulty;
use function count;
use function max;
use function mt_getrandmax;
use function mt_rand;

/**
 * Main hand and off hand items of a mob (swords, bows, crossbows, tridents, potions being drunk, gold being admired),
 * shown to players with MobEquipmentPacket and saved as the Bedrock "Mainhand"/"Offhand" lists, and the equipment
 * helpers of mobs that also wear armor (the armor inventory of {@link \pocketmine\entity\Living}): spawn equipment
 * from Bedrock equipment tables, equipment drops and guaranteed drops (slots of {@link MobEquipment}), and item pickup
 * with equip_item (see {@link MobShareables}).
 *
 * The using class must call {@link HeldItemsTrait::loadHeldItems()} and {@link HeldItemsTrait::saveHeldItems()}.
 */
trait HeldItemsTrait{
	private ?Item $mainHandItem = null;
	private ?Item $offHandItem = null;
	/** Bit mask of the {@link MobEquipment} slots always dropped on death (vanilla drop chance 1, e.g. picked items). */
	private int $guaranteedDropSlots = 0;
	/** Bit mask of the {@link MobEquipment} slots never dropped on death (vanilla drop chance 0). */
	private int $noDropSlots = 0;
	/** What the mob picks up, null if it doesn't (see {@link HeldItemsTrait::initItemPickup()}). */
	private ?MobShareables $shareables = null;
	private bool $canPickUpLoot = false;

	public function getMainHandItem() : Item{
		return $this->mainHandItem !== null ? clone $this->mainHandItem : VanillaItems::AIR();
	}

	public function setMainHandItem(Item $item) : void{
		$this->mainHandItem = $item->isNull() ? null : clone $item;
		$this->sendHeldItems($this->getViewers());
	}

	protected function holdsItemInMainHand() : bool{
		return $this->mainHandItem !== null;
	}

	protected function getMainHandAttackBonus() : float{
		//the item's attack damage without the fist's 1 (Bedrock: a stone sword adds 5, an iron axe 5, a golden axe 3)
		return $this->mainHandItem !== null ? max(0, $this->mainHandItem->getAttackPoints() - 1) : 0.0;
	}

	public function getOffHandItem() : Item{
		return $this->offHandItem !== null ? clone $this->offHandItem : VanillaItems::AIR();
	}

	public function setOffHandItem(Item $item) : void{
		$this->offHandItem = $item->isNull() ? null : clone $item;
		$this->sendHeldItems($this->getViewers());
	}

	protected function holdsTotemOfUndying() : bool{
		return $this->mainHandItem instanceof Totem || $this->offHandItem instanceof Totem;
	}

	protected function consumeTotemOfUndying() : void{
		if($this->mainHandItem instanceof Totem){
			$this->mainHandItem->pop();
			$this->setMainHandItem($this->mainHandItem);
		}elseif($this->offHandItem instanceof Totem){
			$this->offHandItem->pop();
			$this->setOffHandItem($this->offHandItem);
		}
	}

	protected function loadHeldItems(CompoundTag $nbt) : void{
		foreach(["Mainhand" => false, "Offhand" => true] as $tagName => $offHand){
			$list = $nbt->getListTag($tagName, CompoundTag::class);
			if($list === null || count($list) === 0){
				continue;
			}
			$tag = $list->get(0);
			if(!$tag instanceof CompoundTag){
				continue;
			}
			try{
				$item = Item::nbtDeserialize($tag);
			}catch(SavedDataLoadingException){
				continue;
			}
			if($item->isNull()){
				continue;
			}
			if($offHand){
				$this->offHandItem = $item;
			}else{
				$this->mainHandItem = $item;
			}
		}
		$this->guaranteedDropSlots = $nbt->getByte(MobEquipment::TAG_GUARANTEED_DROPS, 0) & 0x3f;
		$this->noDropSlots = $nbt->getByte(MobEquipment::TAG_NO_DROPS, 0) & 0x3f;
	}

	protected function saveHeldItems(CompoundTag $nbt) : void{
		$nbt->setTag("Mainhand", new ListTag($this->mainHandItem !== null ? [$this->mainHandItem->nbtSerialize()] : [], NBT::TAG_Compound));
		$nbt->setTag("Offhand", new ListTag($this->offHandItem !== null ? [$this->offHandItem->nbtSerialize()] : [], NBT::TAG_Compound));
		if($this->guaranteedDropSlots !== 0){
			$nbt->setByte(MobEquipment::TAG_GUARANTEED_DROPS, $this->guaranteedDropSlots);
		}else{
			$nbt->removeTag(MobEquipment::TAG_GUARANTEED_DROPS);
		}
		if($this->noDropSlots !== 0){
			$nbt->setByte(MobEquipment::TAG_NO_DROPS, $this->noDropSlots);
		}else{
			$nbt->removeTag(MobEquipment::TAG_NO_DROPS);
		}
		if($this->shareables !== null){
			$nbt->setByte(MobEquipment::TAG_CAN_PICK_UP_LOOT, $this->canPickUpLoot ? 1 : 0);
		}
	}

	/**
	 * Lets the mob pick up items (behavior.pickup_items with minecraft:shareables, see {@link MobShareables}), to call
	 * from initEntity() after {@link HeldItemsTrait::loadHeldItems()}. With pickup_based_on_chance, whether this mob
	 * picks up items is rolled once like Java (0.55 x the special multiplier of the regional difficulty: never on Easy
	 * nor in young worlds) and saved.
	 *
	 * Only a mob which picks up items gets the {@link PickupItemsGoal} (at $goalPriority, the behavior.pickup_items
	 * priority; null for mobs with their own pickup goal): the others pay nothing for it.
	 */
	private function initItemPickup(CompoundTag $nbt, MobShareables $shareables, ?int $goalPriority) : void{
		$this->shareables = $shareables;
		$tag = $nbt->getTag(MobEquipment::TAG_CAN_PICK_UP_LOOT);
		if($tag instanceof ByteTag){
			$this->canPickUpLoot = $tag->getValue() !== 0;
		}elseif(!$shareables->pickupBasedOnChance){
			$this->canPickUpLoot = true;
		}else{
			$chance = MobShareables::PICKUP_CHANCE * RegionalDifficulty::getSpecialMultiplierAt($this->getWorld(), $this->location);
			$this->canPickUpLoot = $chance > 0 && mt_rand() / mt_getrandmax() < $chance;
		}
		if($this->canPickUpLoot && $goalPriority !== null){
			$this->goalSelector->addGoal($goalPriority, new PickupItemsGoal(
				$this,
				$this->wantsToPickUp(...),
				$this->pickUpItemEntity(...),
				fn() : bool => GameRules::allowsMobGriefing($this->getWorld()),
				MobShareables::PICKUP_DISTANCE
			));
		}
	}

	/**
	 * Returns the item in a {@link MobEquipment} slot, null when empty.
	 */
	private function getEquipmentItem(int $slot) : ?Item{
		if($slot === MobEquipment::SLOT_MAINHAND){
			return $this->mainHandItem;
		}
		if($slot === MobEquipment::SLOT_OFFHAND){
			return $this->offHandItem;
		}
		$item = $this->armorInventory->getItem($slot);
		return $item->isNull() ? null : $item;
	}

	/**
	 * Whether the mob wants the item (behavior.equip_item with its shareables): into an empty slot, or better than what
	 * it has there (see {@link MobShareables::isBetter()}). Mobs which pick up any item take items they don't list
	 * only into an empty main hand.
	 */
	private function wantsToPickUp(Item $item) : bool{
		$shareables = $this->shareables;
		if($shareables === null || $item->isNull() || $shareables->isExcluded($item) || !$this->isAlive()){
			return false;
		}
		$priority = $shareables->getPriority($item);
		if($priority === null){
			return $shareables->canPickUpAnyItem && $this->mainHandItem === null;
		}
		//one priority lookup: the slot follows from the item, the priority is passed on
		$current = $this->getEquipmentItem(MobEquipment::getSlotFor($item));
		return $current === null || $shareables->isBetter($item, $current, $priority);
	}

	/**
	 * Picks up the item (one of the stack, or the whole stack for mobs without singular_pickup) and equips it, if the
	 * mob wants it and {@link EntityItemPickupEvent} isn't cancelled: the item it replaces is dropped, the new one always
	 * drops on death (Java setGuaranteedDrop) and the mob no longer despawns.
	 */
	private function pickUpItemEntity(ItemEntity $entity) : bool{
		$shareables = $this->shareables;
		if($shareables === null || !$this->canPickUpLoot || !PickupItemsGoal::isAvailable($entity)){
			return false;
		}
		$item = $entity->getItem();
		if(!$this->wantsToPickUp($item)){
			return false;
		}
		$taken = $shareables->singularPickup ? 1 : $item->getCount();
		$ev = new EntityItemPickupEvent($this, $entity, (clone $item)->setCount($taken), $shareables->getSlotFor($item) < MobEquipment::SLOT_MAINHAND ? $this->armorInventory : null);
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}
		//a plugin may have changed the item: it goes to its own slot
		$picked = $ev->getItem();
		if($picked->isNull()){
			return false;
		}
		NetworkBroadcastUtils::broadcastEntityEvent(
			$entity->getViewers(),
			fn(EntityEventBroadcaster $broadcaster, array $recipients) => $broadcaster->onPickUpItem($recipients, $this, $entity)
		);
		if($item->getCount() > $taken){
			$entity->setStackSize($item->getCount() - $taken);
		}else{
			$entity->flagForDespawn();
		}
		$this->equipPickedItem($shareables->getSlotFor($picked), $picked);
		return true;
	}

	/**
	 * Puts an item the mob picked up in a slot: the item there is dropped (unless it never drops), the new one always
	 * drops on death, and the mob becomes persistent.
	 */
	private function equipPickedItem(int $slot, Item $item) : void{
		$old = $this->getEquipmentItem($slot);
		if($old !== null && ($this->noDropSlots & (1 << $slot)) === 0){
			$this->getWorld()->dropItem($this->location->add(0, 0.5, 0), clone $old);
		}
		if($slot === MobEquipment::SLOT_MAINHAND){
			$this->mainHandItem = clone $item;
		}elseif($slot === MobEquipment::SLOT_OFFHAND){
			$this->offHandItem = clone $item;
		}else{
			$this->armorInventory->setItem($slot, clone $item);
		}
		$this->noDropSlots &= ~(1 << $slot);
		$this->setGuaranteedDrop($slot);
		$this->setPersistent(true);
		if($slot >= MobEquipment::SLOT_MAINHAND){
			$this->sendHeldItems($this->getViewers());
		}
	}

	/**
	 * Drops every held and worn item, intact, except those which never drop (vanilla drop chance 0), and empties the
	 * slots: Bedrock minecraft:transformation drop_equipment (a zombie villager being cured).
	 */
	protected function dropAllEquipment() : void{
		$items = $this->armorInventory->getContents();
		if($this->mainHandItem !== null){
			$items[MobEquipment::SLOT_MAINHAND] = $this->mainHandItem;
		}
		if($this->offHandItem !== null){
			$items[MobEquipment::SLOT_OFFHAND] = $this->offHandItem;
		}
		$world = $this->getWorld();
		$pos = $this->location->add(0, 0.5, 0);
		foreach($items as $slot => $item){
			if(($this->noDropSlots & (1 << $slot)) === 0){
				$world->dropItem($pos, $item);
			}
		}
		$this->mainHandItem = null;
		$this->offHandItem = null;
		$this->guaranteedDropSlots = 0;
		$this->noDropSlots = 0;
		$this->armorInventory->clearAll();
		$this->sendHeldItems($this->getViewers());
	}

	/**
	 * Puts a dispensed item (armor, carved pumpkin, head) in an armor slot (vanilla EquipmentDispenseItemBehavior):
	 * it always drops on death and the mob becomes persistent. The caller checks that the slot is empty.
	 *
	 * @internal
	 */
	public function equipDispensedArmor(int $slot, Item $item) : void{
		$this->armorInventory->setItem($slot, clone $item);
		$this->noDropSlots &= ~(1 << $slot);
		$this->setGuaranteedDrop($slot);
		$this->setPersistent(true);
	}

	/**
	 * Makes the item in a {@link MobEquipment} slot always drop on death (vanilla drop chance 1: items a mob picked
	 * up), or drop with the normal chance again.
	 */
	protected function setGuaranteedDrop(int $slot, bool $guaranteed = true) : void{
		if($guaranteed){
			$this->guaranteedDropSlots |= 1 << $slot;
		}else{
			$this->guaranteedDropSlots &= ~(1 << $slot);
		}
	}

	protected function isGuaranteedDrop(int $slot) : bool{
		return ($this->guaranteedDropSlots & (1 << $slot)) !== 0;
	}

	/**
	 * Rolls a Bedrock equipment table (see {@link BedrockEquipmentTables}) with the regional difficulty of the mob's
	 * position and equips the result: armor in the armor slots, weapons in the main hand, nautilus shells and the like
	 * in the off hand (see {@link MobEquipment::getSlotFor()}). Unless $keep, the current equipment is removed first
	 * (a Bedrock component group replacing the equipment component).
	 */
	protected function equipFromTable(string $table, bool $keep = false) : void{
		$world = $this->getWorld();
		$items = BedrockEquipmentTables::roll($table, $world->getDifficulty(), RegionalDifficulty::getSpecialMultiplierAt($world, $this->location));
		if(!$keep){
			$this->mainHandItem = null;
			$this->offHandItem = null;
			$this->guaranteedDropSlots = 0;
			$this->noDropSlots = 0;
			$this->armorInventory->clearAll();
		}
		foreach($items as $item){
			$slot = MobEquipment::getSlotFor($item);
			if($slot === MobEquipment::SLOT_MAINHAND && $this->mainHandItem !== null && $this->offHandItem === null){
				$slot = MobEquipment::SLOT_OFFHAND;
			}
			if($slot === MobEquipment::SLOT_MAINHAND){
				$this->mainHandItem = $item;
			}elseif($slot === MobEquipment::SLOT_OFFHAND){
				$this->offHandItem = $item;
			}else{
				$this->armorInventory->setItem($slot, $item);
			}
		}
		$this->sendHeldItems($this->getViewers());
	}

	/**
	 * Equips items rolled from an equipment loot table (vanilla EquipmentUser.equip, e.g. the equipment of the mobs of
	 * ominous trial spawners): each item goes to its slot (see {@link MobEquipment::getSlotFor()}) unless an earlier
	 * item took it; only the slots filled change. With $neverDrop, these items never drop on death (vanilla slot drop
	 * chance 0).
	 *
	 * @internal
	 * @param Item[] $items
	 */
	public function equipLootItems(array $items, bool $neverDrop) : void{
		$filled = 0;
		foreach($items as $item){
			if($item->isNull()){
				continue;
			}
			$slot = MobEquipment::getSlotFor($item);
			if(($filled & (1 << $slot)) !== 0){
				continue;
			}
			$filled |= 1 << $slot;
			if($slot === MobEquipment::SLOT_MAINHAND){
				$this->mainHandItem = clone $item;
			}elseif($slot === MobEquipment::SLOT_OFFHAND){
				$this->offHandItem = clone $item;
			}else{
				$this->armorInventory->setItem($slot, clone $item);
			}
			$this->guaranteedDropSlots &= ~(1 << $slot);
			if($neverDrop){
				$this->noDropSlots |= 1 << $slot;
			}else{
				$this->noDropSlots &= ~(1 << $slot);
			}
		}
		if(($filled & ((1 << MobEquipment::SLOT_MAINHAND) | (1 << MobEquipment::SLOT_OFFHAND))) !== 0){
			$this->sendHeldItems($this->getViewers());
		}
	}

	/**
	 * @param Player[] $players
	 */
	protected function sendHeldItems(array $players) : void{
		if(count($players) === 0){
			return;
		}
		$converter = TypeConverter::getInstance();
		NetworkBroadcastUtils::broadcastPackets($players, [
			MobEquipmentPacket::create($this->getId(), ItemStackWrapper::legacy($converter->coreItemStackToNet($this->getMainHandItem())), 0, 0, ContainerIds::INVENTORY),
			MobEquipmentPacket::create($this->getId(), ItemStackWrapper::legacy($converter->coreItemStackToNet($this->getOffHandItem())), 0, 0, ContainerIds::OFFHAND)
		]);
	}

	protected function sendSpawnPacket(Player $player) : void{
		parent::sendSpawnPacket($player);
		$this->sendHeldItems([$player]);
	}

	/**
	 * Returns the number of equipped items (held and worn), for the vanilla experience formula
	 * "5 + equipment count * (1-3)".
	 */
	protected function getEquipmentCount() : int{
		$count = ($this->mainHandItem !== null ? 1 : 0) + ($this->offHandItem !== null ? 1 : 0);
		foreach($this->getArmorInventory()->getContents() as $item){
			if(!$item->isNull()){
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Returns the experience of a monster with equipment: $base + (1-3) per equipped item, when killed by a player.
	 */
	protected function equipmentXp(int $base) : int{
		if(!$this->wasRecentlyHitByPlayer()){
			return 0;
		}
		$xp = $base;
		for($i = $this->getEquipmentCount(); $i > 0; --$i){
			$xp += mt_rand(1, 3);
		}
		return $xp;
	}

	/**
	 * Returns the held items dropped on death: vanilla drops spawned equipment with an 8.5% chance (+1% per Looting
	 * level) when killed by a player. Items with $alwaysDropOffHand (e.g. a drowned's nautilus shell) always drop.
	 *
	 * @return Item[]
	 */
	protected function getHeldItemDrops(bool $alwaysDropOffHand = false) : array{
		$drops = [];
		if($this->mainHandItem !== null && ($drop = $this->rollEquipmentDrop($this->mainHandItem, MobEquipment::SLOT_MAINHAND)) !== null){
			$drops[] = $drop;
		}
		if($this->offHandItem !== null && ($drop = $this->rollEquipmentDrop($this->offHandItem, MobEquipment::SLOT_OFFHAND, $alwaysDropOffHand)) !== null){
			$drops[] = $drop;
		}
		return $drops;
	}

	/**
	 * Returns the held items and the worn armor dropped on death (see {@link HeldItemsTrait::getHeldItemDrops()}):
	 * each piece of spawned equipment with an 8.5% chance (+1% per Looting level) when killed by a player, with a random
	 * part of its durability left; guaranteed drops always, intact.
	 *
	 * @return Item[]
	 */
	protected function getEquipmentDrops(bool $alwaysDropOffHand = false) : array{
		$drops = $this->getHeldItemDrops($alwaysDropOffHand);
		foreach($this->armorInventory->getContents() as $slot => $item){
			if(($drop = $this->rollEquipmentDrop($item, $slot)) !== null){
				$drops[] = $drop;
			}
		}
		return $drops;
	}

	/**
	 * Vanilla equipment drop (Java Mob.dropCustomDeathLoot): guaranteed slots always drop as they are; other items
	 * only on player kills, with the default chance, and damaged.
	 */
	private function rollEquipmentDrop(Item $item, int $slot, bool $always = false) : ?Item{
		if(($this->noDropSlots & (1 << $slot)) !== 0){
			return null;
		}
		if($always || $this->isGuaranteedDrop($slot)){
			return clone $item;
		}
		if(!$this->wasRecentlyHitByPlayer() || mt_rand(0, 999) >= MobEquipment::DEFAULT_DROP_CHANCE + MobEquipment::LOOTING_DROP_CHANCE * $this->getLootingLevel()){
			return null;
		}
		return MobEquipment::randomizeDropDamage(clone $item);
	}
}
