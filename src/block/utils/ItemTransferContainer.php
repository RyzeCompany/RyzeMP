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

use pocketmine\block\ChiseledBookshelf;
use pocketmine\block\Jukebox;
use pocketmine\block\tile\ChiseledBookshelf as TileChiseledBookshelf;
use pocketmine\block\tile\Container;
use pocketmine\block\tile\DecoratedPot as TileDecoratedPot;
use pocketmine\block\tile\Jukebox as TileJukebox;
use pocketmine\block\tile\Tile;
use pocketmine\inventory\Inventory;
use pocketmine\item\Book;
use pocketmine\item\EnchantedBook;
use pocketmine\item\Item;
use pocketmine\item\Record;
use pocketmine\item\WritableBookBase;
use pocketmine\world\World;

/**
 * Container view of the blocks that hoppers, droppers and crafters move items into and out of although their block
 * entity is not a regular container (vanilla Java: these block entities are Containers; Bedrock behaves the same):
 *
 * - decorated pot: one stack of a single item type;
 * - jukebox: one music disc, which starts playing when it is put in and stops when it is taken out;
 * - chiseled bookshelf: 6 slots of one book each; the shown books and the last interacted slot follow the changes.
 *
 * Created for one transfer and not kept: it holds a copy of the items, and changes are written back to the block.
 *
 * @internal
 */
final class ItemTransferContainer implements Container{
	public const DECORATED_POT = 0;
	public const JUKEBOX = 1;
	public const CHISELED_BOOKSHELF = 2;

	/**
	 * @phpstan-param self::DECORATED_POT|self::JUKEBOX|self::CHISELED_BOOKSHELF $kind
	 */
	private function __construct(
		private int $kind,
		private ItemTransferSlotsInventory $inventory
	){}

	/**
	 * Returns the container view of the given block entity, or null if it is not one of the blocks handled here.
	 */
	public static function of(World $world, int $x, int $y, int $z, ?Tile $tile) : ?self{
		if($tile instanceof TileDecoratedPot){
			return self::forDecoratedPot($world, $x, $y, $z, $tile);
		}
		if($tile instanceof TileJukebox){
			return self::forJukebox($world, $x, $y, $z, $tile);
		}
		if($tile instanceof TileChiseledBookshelf){
			return self::forChiseledBookshelf($world, $x, $y, $z, $tile);
		}
		return null;
	}

	private static function forDecoratedPot(World $world, int $x, int $y, int $z, TileDecoratedPot $tile) : self{
		$stored = $tile->getItem();
		return new self(self::DECORATED_POT, new ItemTransferSlotsInventory(1, Inventory::MAX_STACK, $stored !== null ? [0 => $stored] : [],
			static function(int $slot, Item $item) use ($world, $x, $y, $z, $tile) : void{
				$tile->setItem($item);
				$world->getRedstone()?->updateComparatorsReading($x, $y, $z);
				ItemTransfer::notifyContainerChanged($world, $x, $y, $z);
			}
		));
	}

	private static function forJukebox(World $world, int $x, int $y, int $z, TileJukebox $tile) : self{
		$record = $tile->getRecord();
		return new self(self::JUKEBOX, new ItemTransferSlotsInventory(1, 1, $record !== null ? [0 => $record] : [],
			static function(int $slot, Item $item) use ($world, $x, $y, $z) : void{
				$block = $world->getBlockAt($x, $y, $z, cached: false); //the block entity may have changed without a block update
				if($block instanceof Jukebox){
					$block->setRecordFromItemTransfer($item instanceof Record ? $item : null);
				}
				ItemTransfer::notifyContainerChanged($world, $x, $y, $z);
			}
		));
	}

	private static function forChiseledBookshelf(World $world, int $x, int $y, int $z, TileChiseledBookshelf $tile) : self{
		$realInventory = $tile->getRealInventory();
		return new self(self::CHISELED_BOOKSHELF, new ItemTransferSlotsInventory($realInventory->getSize(), 1, $realInventory->getContents(),
			static function(int $slot, Item $item) use ($world, $x, $y, $z, $realInventory) : void{
				$realInventory->setItem($slot, $item);
				$block = $world->getBlockAt($x, $y, $z, cached: false); //the block entity may have changed without a block update
				$bookshelfSlot = ChiseledBookshelfSlot::tryFrom($slot);
				if($block instanceof ChiseledBookshelf && $bookshelfSlot !== null){
					//vanilla ChiseledBookShelfBlockEntity.updateState()
					$block->setSlot($bookshelfSlot, !$item->isNull());
					$block->setLastInteractedSlot($bookshelfSlot);
					$world->setBlock($block->getPosition(), $block);
				}
				ItemTransfer::notifyContainerChanged($world, $x, $y, $z);
			}
		));
	}

	/**
	 * @phpstan-return self::DECORATED_POT|self::JUKEBOX|self::CHISELED_BOOKSHELF
	 */
	public function getKind() : int{
		return $this->kind;
	}

	/**
	 * Returns the slots the given item may be put into, in the order they should be tried.
	 *
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	public function getInsertSlots(Item $item) : array{
		return match($this->kind){
			//the pot takes more of the same item only (checked when the item is added)
			self::DECORATED_POT => [0],
			//vanilla JukeboxBlockEntity.canPlaceItem(): a disc, into an empty jukebox
			self::JUKEBOX => $item instanceof Record && $this->inventory->isSlotEmpty(0) ? [0] : [],
			self::CHISELED_BOOKSHELF => self::isBookshelfBook($item) ? $this->getEmptySlots() : [],
		};
	}

	/**
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	private function getEmptySlots() : array{
		$slots = [];
		for($i = 0, $size = $this->inventory->getSize(); $i < $size; ++$i){
			if($this->inventory->isSlotEmpty($i)){
				$slots[] = $i;
			}
		}
		return $slots;
	}

	/**
	 * Items of the Bedrock item tag minecraft:bookshelf_books (book, enchanted book, writable book, written book).
	 */
	public static function isBookshelfBook(Item $item) : bool{
		return $item instanceof Book || $item instanceof EnchantedBook || $item instanceof WritableBookBase;
	}

	public function getInventory() : ItemTransferSlotsInventory{
		return $this->inventory;
	}

	public function getRealInventory() : ItemTransferSlotsInventory{
		return $this->inventory;
	}

	public function canOpenWith(string $key) : bool{
		return true;
	}
}
