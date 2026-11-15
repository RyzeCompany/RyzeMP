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

namespace pocketmine\item;

use pocketmine\block\ShulkerBox;
use pocketmine\data\bedrock\item\SavedItemStackData;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use pocketmine\world\sound\BundleDropContentsSound;
use function array_pop;
use function array_splice;
use function count;
use function intdiv;
use function max;
use function min;
use function usort;

/**
 * A bundle stores a mixed stack of items, limited by weight rather than by slot count.
 *
 * Bedrock rules (BedrockData item components of minecraft:bundle: minecraft:storage_item max_slots=64,
 * minecraft:storage_weight_limit max_weight_limit=64, minecraft:storage_weight_modifier weight_in_storage_item=4,
 * shulker boxes banned): each item weighs 64 / its max stack size, a bundle inside a bundle weighs 4 plus the weight
 * of its own contents, and the total may not exceed 64.
 *
 * The contents are ordered from the oldest (index 0) to the most recently added stack (last index), which is also
 * the order of the slots of the client's dynamic container for the bundle.
 */
class Bundle extends Item{
	public const MAX_WEIGHT = 64;
	public const MAX_SLOTS = 64;
	/** Weight of a bundle stored inside another bundle, not counting its contents. */
	public const BUNDLE_WEIGHT = 4;

	/**
	 * List of the stored items, each with its Slot byte, as written by Bedrock and PowerNukkitX.
	 */
	public const TAG_CONTENTS = "storage_item_component_content"; //TAG_List<TAG_Compound>
	/**
	 * ID of the client-side dynamic container showing the contents. Only meaningful on the network; it is assigned by
	 * the network session and stripped from saved data.
	 */
	public const TAG_BUNDLE_ID = "bundle_id"; //TAG_Int

	/**
	 * @var Item[]
	 * @phpstan-var list<Item>
	 */
	private array $contents = [];

	public function getMaxStackSize() : int{
		return 1;
	}

	/**
	 * Returns the stored items, from the oldest to the most recently added stack.
	 *
	 * @return Item[]
	 * @phpstan-return list<Item>
	 */
	public function getContents() : array{
		return Utils::cloneObjectArray($this->contents);
	}

	/**
	 * Replaces the stored items. Empty items are skipped. The weight limit is not enforced here; use
	 * {@link Bundle::getMaxAddableCount()} or {@link Bundle::addItem()} for player-driven insertions.
	 *
	 * @param Item[] $contents ordered from the oldest to the most recently added stack
	 * @phpstan-param list<Item> $contents
	 *
	 * @return $this
	 */
	public function setContents(array $contents) : self{
		$result = [];
		foreach($contents as $item){
			if(!$item->isNull()){
				$result[] = clone $item;
			}
		}
		if(count($result) > self::MAX_SLOTS){
			throw new \InvalidArgumentException("A bundle cannot hold more than " . self::MAX_SLOTS . " stacks");
		}
		$this->contents = $result;
		return $this;
	}

	public function isEmpty() : bool{
		return count($this->contents) === 0;
	}

	/**
	 * Returns the total weight of the stored items (0-64 for a bundle filled through normal gameplay).
	 */
	public function getContentsWeight() : int{
		$weight = 0;
		foreach($this->contents as $item){
			$weight += self::getStackWeight($item);
		}
		return $weight;
	}

	/**
	 * Returns how full the bundle is, between 0 and 1. This is what the client shows as the fullness bar.
	 */
	public function getFullness() : float{
		return min(1.0, $this->getContentsWeight() / self::MAX_WEIGHT);
	}

	/**
	 * Returns the weight of one item of the given type inside a bundle.
	 */
	public static function getItemWeight(Item $item) : int{
		if($item instanceof Bundle){
			return self::BUNDLE_WEIGHT + $item->getContentsWeight();
		}
		return max(1, intdiv(self::MAX_WEIGHT, max(1, $item->getMaxStackSize())));
	}

	/**
	 * Returns the weight of the whole stack inside a bundle.
	 */
	public static function getStackWeight(Item $item) : int{
		return self::getItemWeight($item) * $item->getCount();
	}

	/**
	 * Returns whether the item may be stored in a bundle at all (vanilla bans shulker boxes).
	 */
	public static function canBeStored(Item $item) : bool{
		return !$item->isNull() && !($item->getBlock() instanceof ShulkerBox);
	}

	/**
	 * Returns how many items of the given stack fit in this bundle.
	 */
	public function getMaxAddableCount(Item $item) : int{
		if(!self::canBeStored($item)){
			return 0;
		}
		$remaining = self::MAX_WEIGHT - $this->getContentsWeight();
		if($remaining <= 0){
			return 0;
		}
		$fit = intdiv($remaining, self::getItemWeight($item));
		if($fit > 0 && $this->findMergeTarget($item) === null && count($this->contents) >= self::MAX_SLOTS){
			return 0;
		}
		return min($item->getCount(), $fit);
	}

	private function findMergeTarget(Item $item) : ?int{
		foreach($this->contents as $index => $stored){
			if($stored->canStackWith($item) && $stored->getCount() < $stored->getMaxStackSize()){
				return $index;
			}
		}
		return null;
	}

	/**
	 * Inserts as many items of the stack as fit. A stack of the same item that still has room absorbs them and
	 * becomes the most recently added stack, as in vanilla; any remainder becomes a new stack on top.
	 *
	 * @return int the number of items inserted (the given item is not modified)
	 */
	public function addItem(Item $item) : int{
		$toAdd = $this->getMaxAddableCount($item);
		if($toAdd <= 0){
			return 0;
		}
		$left = $toAdd;
		$mergeIndex = $this->findMergeTarget($item);
		if($mergeIndex !== null){
			$stored = $this->contents[$mergeIndex];
			$moved = min($left, $stored->getMaxStackSize() - $stored->getCount());
			$stored->setCount($stored->getCount() + $moved);
			$left -= $moved;
			array_splice($this->contents, $mergeIndex, 1);
			$this->contents[] = $stored;
		}
		if($left > 0){
			$this->contents[] = (clone $item)->setCount($left);
		}
		return $toAdd;
	}

	/**
	 * Removes and returns the most recently added stack, or null if the bundle is empty.
	 */
	public function removeNewest() : ?Item{
		$item = array_pop($this->contents);
		return $item;
	}

	public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems) : ItemUseResult{
		//Bedrock (since 1.21.40) empties one stack per use, the most recently added one (Minecraft Wiki, Bundle)
		$item = $this->removeNewest();
		if($item === null){
			return ItemUseResult::NONE;
		}
		$player->dropItem($item);
		$player->getWorld()->addSound($player->getPosition(), new BundleDropContentsSound());
		return ItemUseResult::SUCCESS;
	}

	protected function deserializeCompoundTag(CompoundTag $tag) : void{
		parent::deserializeCompoundTag($tag);
		$tag->removeTag(self::TAG_BUNDLE_ID);

		$this->contents = [];
		$contentsTag = $tag->getListTag(self::TAG_CONTENTS, CompoundTag::class);
		if($contentsTag !== null){
			$slots = [];
			foreach($contentsTag as $itemTag){
				$item = Item::safeNbtDeserialize($itemTag, "Bundle contents");
				if(!$item->isNull()){
					$slots[] = [$itemTag->getByte(SavedItemStackData::TAG_SLOT, count($slots)), $item];
				}
			}
			usort($slots, fn(array $a, array $b) => $a[0] <=> $b[0]);
			foreach($slots as [, $item]){
				if(count($this->contents) >= self::MAX_SLOTS){
					break;
				}
				$this->contents[] = $item;
			}
		}
	}

	protected function serializeCompoundTag(CompoundTag $tag) : void{
		parent::serializeCompoundTag($tag);
		if(count($this->contents) > 0){
			$contentsTag = new ListTag();
			foreach($this->contents as $slot => $item){
				$contentsTag->push($item->nbtSerialize($slot));
			}
			$tag->setTag(self::TAG_CONTENTS, $contentsTag);
		}else{
			$tag->removeTag(self::TAG_CONTENTS);
		}
	}

	public function __clone(){
		parent::__clone();
		$this->contents = Utils::cloneObjectArray($this->contents);
	}
}
