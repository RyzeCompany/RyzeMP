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
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Composter;
use pocketmine\block\Hopper;
use pocketmine\block\inventory\BrewingStandInventory;
use pocketmine\block\inventory\FurnaceInventory;
use pocketmine\block\ShulkerBox;
use pocketmine\block\tile\Barrel as TileBarrel;
use pocketmine\block\tile\BrewingStand as TileBrewingStand;
use pocketmine\block\tile\Chest as TileChest;
use pocketmine\block\tile\Container;
use pocketmine\block\tile\Crafter as TileCrafter;
use pocketmine\block\tile\Dispenser as TileDispenser;
use pocketmine\block\tile\Furnace as TileFurnace;
use pocketmine\block\tile\Hopper as TileHopper;
use pocketmine\block\tile\ShulkerBox as TileShulkerBox;
use pocketmine\entity\vehicle\ContainerMinecart;
use pocketmine\event\block\ComposterEmptyEvent;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\Potion;
use pocketmine\item\SplashPotion;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\world\format\Chunk;
use pocketmine\world\sound\ComposterEmptySound;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\World;
use function count;
use function min;
use function mt_rand;
use function range;

/**
 * Moves items in and out of containers the way hoppers, droppers and crafters do: one item at a time, through a given
 * face of the container, following the slot rules of each container type.
 *
 * Faces are the face of the container the item passes through: an item pushed down into a furnace enters through
 * {@link Facing::UP}, a hopper under a chest pulls through {@link Facing::DOWN}.
 */
final class ItemTransfer{

	/** Game ticks a hopper waits after moving an item (Bedrock: 8, Minecraft Wiki "Hopper"). */
	public const HOPPER_COOLDOWN_TICKS = 8;

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the container at the given position that hoppers, droppers and crafters can move items into and out
	 * of, or null.
	 */
	public static function getContainerAt(World $world, int $x, int $y, int $z) : ?Container{
		$tile = $world->getTileAt($x, $y, $z);
		if(
			$tile instanceof TileChest ||
			$tile instanceof TileBarrel ||
			$tile instanceof TileFurnace ||
			$tile instanceof TileBrewingStand ||
			$tile instanceof TileShulkerBox ||
			$tile instanceof TileCrafter ||
			$tile instanceof TileDispenser || //also droppers
			$tile instanceof TileHopper
		){
			return $tile;
		}
		//decorated pots, jukeboxes and chiseled bookshelves (one lookup, only for these tiles)
		return $tile !== null ? ItemTransferContainer::of($world, $x, $y, $z, $tile) : null;
	}

	/**
	 * Returns a chest or hopper minecart in the block space at the given position, or null. Hoppers move items into and
	 * out of container minecarts there when there is no container block (vanilla picks one of them at random).
	 */
	public static function getEntityContainerAt(World $world, int $x, int $y, int $z) : ?ContainerMinecart{
		if(!$world->isInWorld($x, $y, $z) || !$world->isChunkLoaded($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)){
			return null;
		}
		$found = [];
		foreach($world->getNearbyEntities(new AxisAlignedBB($x, $y, $z, $x + 1, $y + 1, $z + 1)) as $entity){
			if($entity instanceof ContainerMinecart && $entity->isAlive() && !$entity->isFlaggedForDespawn() && !$entity->isBroken()){
				$found[] = $entity;
			}
		}
		return count($found) === 0 ? null : $found[count($found) === 1 ? 0 : mt_rand(0, count($found) - 1)];
	}

	/**
	 * Adds one of the given item to the first slot of a container minecart that can take it. Returns whether it was
	 * added.
	 */
	public static function insertOneIntoEntity(ContainerMinecart $minecart, Item $item) : bool{
		if($item->isNull()){
			return false;
		}
		$inventory = $minecart->getInventory();
		return self::addOneToSlots($inventory, range(0, $inventory->getSize() - 1), $item);
	}

	/**
	 * Returns whether something at the given position can receive items (a container or a composter).
	 */
	public static function canReceiveItems(World $world, int $x, int $y, int $z) : bool{
		return self::getContainerAt($world, $x, $y, $z) !== null || self::isBlockTypeAt($world, $x, $y, $z, BlockTypeIds::COMPOSTER);
	}

	/**
	 * Returns the slots the given item may be inserted into through the given face, in the order they should be
	 * tried.
	 *
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	public static function getInsertSlots(Container $tile, Inventory $inventory, int $face, Item $item) : array{
		if($tile instanceof TileShulkerBox && $item->getBlock() instanceof ShulkerBox){
			return [];
		}
		if($inventory instanceof FurnaceInventory){
			if($face === Facing::UP){
				return [FurnaceInventory::SLOT_INPUT];
			}
			if($face !== Facing::DOWN && ($item->getFuelTime() > 0 || $item->getTypeId() === ItemTypeIds::BUCKET)){
				return [FurnaceInventory::SLOT_FUEL];
			}
			return [];
		}
		if($inventory instanceof BrewingStandInventory){
			if($face === Facing::UP){
				return self::isBrewingIngredient($inventory, $item) ? [BrewingStandInventory::SLOT_INGREDIENT] : [];
			}
			if($item->getTypeId() === ItemTypeIds::BLAZE_POWDER){
				return [BrewingStandInventory::SLOT_FUEL];
			}
			if($item instanceof Potion || $item instanceof SplashPotion || $item->getTypeId() === ItemTypeIds::GLASS_BOTTLE){
				return [BrewingStandInventory::SLOT_BOTTLE_LEFT, BrewingStandInventory::SLOT_BOTTLE_MIDDLE, BrewingStandInventory::SLOT_BOTTLE_RIGHT];
			}
			return [];
		}
		if($tile instanceof TileCrafter){
			$slot = $tile->getInsertSlotFor($item);
			return $slot !== null ? [$slot] : [];
		}
		if($tile instanceof ItemTransferContainer){
			return $tile->getInsertSlots($item);
		}
		$slots = [];
		for($i = 0, $size = $inventory->getSize(); $i < $size; ++$i){
			$slots[] = $i;
		}
		return $slots;
	}

	/**
	 * Returns the slots items may be taken out of through the given face, in the order they should be tried.
	 *
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	public static function getExtractSlots(Container $tile, Inventory $inventory, int $face) : array{
		if($inventory instanceof FurnaceInventory){
			//empty buckets left over by lava fuel can also be taken out from below
			return $inventory->getFuel()->getTypeId() === ItemTypeIds::BUCKET ?
				[FurnaceInventory::SLOT_RESULT, FurnaceInventory::SLOT_FUEL] :
				[FurnaceInventory::SLOT_RESULT];
		}
		if($inventory instanceof BrewingStandInventory){
			return [BrewingStandInventory::SLOT_BOTTLE_LEFT, BrewingStandInventory::SLOT_BOTTLE_MIDDLE, BrewingStandInventory::SLOT_BOTTLE_RIGHT];
		}
		$slots = [];
		for($i = 0, $size = $inventory->getSize(); $i < $size; ++$i){
			$slots[] = $i;
		}
		return $slots;
	}

	private static function isBrewingIngredient(BrewingStandInventory $inventory, Item $item) : bool{
		$craftingManager = $inventory->getHolder()->getWorld()->getServer()->getCraftingManager();
		foreach($craftingManager->getPotionTypeRecipes() as $recipe){
			if($recipe->getIngredient()->accepts($item)){
				return true;
			}
		}
		foreach($craftingManager->getPotionContainerChangeRecipes() as $recipe){
			if($recipe->getIngredient()->accepts($item)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Adds one of the given item to the first of the given slots that can take it. Returns whether it was added.
	 *
	 * @param int[] $slots
	 */
	public static function addOneToSlots(Inventory $inventory, array $slots, Item $item) : bool{
		$maxStack = min($inventory->getMaxStackSize(), $item->getMaxStackSize());
		foreach($slots as $slot){
			$existing = $inventory->getItem($slot);
			if($existing->isNull()){
				$inventory->setItem($slot, (clone $item)->setCount(1));
				return true;
			}
			if($existing->getCount() < $maxStack && $existing->canStackWith($item)){
				$inventory->setItem($slot, $existing->setCount($existing->getCount() + 1));
				return true;
			}
		}
		return false;
	}

	/**
	 * Tries to insert one of the given item into the container (or composter) at the given position, through the
	 * given face of it. Returns whether the item was taken.
	 */
	public static function insertOne(World $world, int $x, int $y, int $z, int $face, Item $item) : bool{
		if($item->isNull()){
			return false;
		}
		$tile = self::getContainerAt($world, $x, $y, $z);
		if($tile === null){
			$block = $world->getBlockAt($x, $y, $z);
			if($block instanceof Composter && $face === Facing::UP){
				return self::insertIntoComposter($block, $item);
			}
			return false;
		}
		$inventory = $tile->getInventory();
		$slots = self::getInsertSlots($tile, $inventory, $face, $item);
		if($tile instanceof TileHopper && self::isEmpty($inventory)){
			//Bedrock: an item pushed into an empty hopper waits for a full cooldown before moving on. Set before adding
			//the item, so that the hopper woken up by the change is scheduled after the cooldown.
			$oldNextTransferTick = $tile->getNextTransferTick();
			$tile->setNextTransferTick($world->getServer()->getTick() + self::HOPPER_COOLDOWN_TICKS);
			if(!self::addOneToSlots($inventory, $slots, $item)){
				$tile->setNextTransferTick($oldNextTransferTick);
				return false;
			}
			return true;
		}
		return self::addOneToSlots($inventory, $slots, $item);
	}

	/**
	 * Inserts as much of the given stack as possible into the container at the given position, one item at a time
	 * so the slot rules are respected. Returns the items that did not fit.
	 */
	public static function insertStack(World $world, int $x, int $y, int $z, int $face, Item $stack) : Item{
		$remaining = clone $stack;
		while(!$remaining->isNull() && self::insertOne($world, $x, $y, $z, $face, $remaining)){
			$remaining->pop();
		}
		return $remaining;
	}

	private static function insertIntoComposter(Composter $composter, Item $item) : bool{
		if(!$item->isCompostable() || $composter->getLayers() >= Composter::MAX_COMPOST_LAYERS - 1){
			return false;
		}
		$one = (clone $item)->setCount(1);
		$composter->onInteract($one, Facing::UP, $composter->getPosition()->add(0.5, 1, 0.5));
		return $one->isNull();
	}

	/**
	 * Takes bone meal out of a ready composter. Returns null if the composter is not ready.
	 */
	public static function extractFromComposter(Composter $composter) : ?Item{
		if($composter->getLayers() !== Composter::MAX_COMPOST_LAYERS){
			return null;
		}
		$ev = new ComposterEmptyEvent($composter, Composter::MIN_COMPOST_LAYERS, [VanillaItems::BONE_MEAL()]);
		$ev->call();
		if($ev->isCancelled()){
			return null;
		}
		$position = $composter->getPosition();
		$world = $position->getWorld();
		$world->setBlock($position, $composter->setLayers($ev->getLayer()));
		$world->addSound($position, new ComposterEmptySound());
		$world->addGameEvent($position, GameEvent::BLOCK_CHANGE);
		return VanillaItems::BONE_MEAL();
	}

	public static function isEmpty(Inventory $inventory) : bool{
		for($slot = 0, $size = $inventory->getSize(); $slot < $size; ++$slot){
			if(!$inventory->isSlotEmpty($slot)){
				return false;
			}
		}
		return true;
	}

	/**
	 * Wakes up the hoppers which may want to move items because the contents of the container at x/y/z changed: the
	 * container itself if it is a hopper, a hopper below it (pulling) and hoppers pointing into it (pushing).
	 */
	public static function notifyContainerChanged(World $world, int $x, int $y, int $z) : void{
		$tile = $world->getTileAt($x, $y, $z);
		$hasItems = !$tile instanceof Container || !self::isEmpty($tile->getInventory());
		self::notifyHoppersAround($world, $x, $y, $z, $hasItems);
		if($tile instanceof TileChest && ($pair = $tile->getPair()) !== null){
			//hoppers around the other half see the contents of both halves
			$pairPos = $pair->getPosition();
			self::notifyHoppersAround($world, $pairPos->getFloorX(), $pairPos->getFloorY(), $pairPos->getFloorZ(), $hasItems);
		}
	}

	private static function notifyHoppersAround(World $world, int $x, int $y, int $z, bool $hasItems) : void{
		self::wakeHopperAt($world, $x, $y, $z);
		if($hasItems){
			//a hopper below only cares if there is something to pull
			self::wakeHopperAt($world, $x, $y - 1, $z);
		}
		foreach(Facing::ALL as $face){
			if($face === Facing::DOWN){
				continue; //a hopper below the container can't point up into it
			}
			[$dx, $dy, $dz] = Facing::OFFSET[$face];
			if(!self::isBlockTypeAt($world, $x + $dx, $y + $dy, $z + $dz, BlockTypeIds::HOPPER)){
				continue;
			}
			$block = $world->getBlockAt($x + $dx, $y + $dy, $z + $dz);
			if($block instanceof Hopper && $block->getFacing() === Facing::opposite($face)){
				//hoppers pointing into the container only care if they have something to push
				$tile = $world->getTileAt($x + $dx, $y + $dy, $z + $dz);
				if($tile instanceof TileHopper && !self::isEmpty($tile->getInventory())){
					$block->wakeUp();
				}
			}
		}
	}

	public static function wakeHopperAt(World $world, int $x, int $y, int $z) : void{
		if(!self::isBlockTypeAt($world, $x, $y, $z, BlockTypeIds::HOPPER)){
			return;
		}
		$block = $world->getBlockAt($x, $y, $z);
		if($block instanceof Hopper){
			$block->wakeUp();
		}
	}

	/**
	 * Cheap check of the block type at a position, without building a Block object. False for unloaded chunks.
	 */
	public static function isBlockTypeAt(World $world, int $x, int $y, int $z, int $blockTypeId) : bool{
		if(!$world->isInWorld($x, $y, $z)){
			return false;
		}
		$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		return $chunk !== null && ($chunk->getBlockStateId($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK) >> Block::INTERNAL_STATE_DATA_BITS) === $blockTypeId;
	}
}
