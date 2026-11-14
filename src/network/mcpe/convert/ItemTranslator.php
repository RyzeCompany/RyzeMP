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

namespace pocketmine\network\mcpe\convert;

use pocketmine\data\bedrock\item\BlockItemIdMap;
use pocketmine\data\bedrock\item\ItemDeserializer;
use pocketmine\data\bedrock\item\ItemSerializer;
use pocketmine\data\bedrock\item\ItemTypeDeserializeException;
use pocketmine\data\bedrock\item\ItemTypeSerializeException;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\item\Item;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\serializer\ItemTypeDictionary;
use pocketmine\utils\AssumptionFailedError;

/**
 * This class handles translation between network item ID+metadata to PocketMine-MP internal ID+metadata and vice versa.
 */
final class ItemTranslator{
	public const NO_BLOCK_RUNTIME_ID = 0; //this is technically a valid block runtime ID, but is used to represent "no block" (derp mojang)

	public function __construct(
		private ItemTypeDictionary $itemTypeDictionary,
		private BlockStateDictionary $blockStateDictionary,
		private ItemSerializer $itemSerializer,
		private ItemDeserializer $itemDeserializer,
		private BlockItemIdMap $blockItemIdMap
	){}

	/**
	 * @return int[]|null
	 * @phpstan-return array{int, int, ?int}|null
	 */
	public function toNetworkIdQuiet(Item $item) : ?array{
		try{
			return $this->toNetworkId($item);
		}catch(ItemTypeSerializeException){
			return null;
		}
	}

	/**
	 * @return int[]|CompoundTag[]|null[]|null
	 * @phpstan-return array{int, int, ?int, ?CompoundTag}|null
	 */
	public function toNetworkIdAndNbtQuiet(Item $item) : ?array{
		try{
			return $this->toNetworkIdAndNbt($item);
		}catch(ItemTypeSerializeException){
			return null;
		}
	}

	/**
	 * @return int[]
	 * @phpstan-return array{int, int, ?int}
	 *
	 * @throws ItemTypeSerializeException
	 */
	public function toNetworkId(Item $item) : array{
		[$id, $meta, $blockRuntimeId] = $this->toNetworkIdAndNbt($item);
		return [$id, $meta, $blockRuntimeId];
	}

	/**
	 * Like {@link ItemTranslator::toNetworkId()}, also returning the NBT clients need: the item's named tag merged with
	 * the tag the item type is saved in (e.g. Type 1 for ominous banners), or null if there is none.
	 *
	 * @return int[]|CompoundTag[]|null[]
	 * @phpstan-return array{int, int, ?int, ?CompoundTag}
	 *
	 * @throws ItemTypeSerializeException
	 */
	public function toNetworkIdAndNbt(Item $item) : array{
		//TODO: we should probably come up with a cache for this

		$itemData = $this->itemSerializer->serializeType($item);

		$numericId = $this->itemTypeDictionary->fromStringId($itemData->getName());
		$blockStateData = $itemData->getBlock();

		if($blockStateData !== null){
			$blockRuntimeId = $this->blockStateDictionary->lookupStateIdFromData($blockStateData);
			if($blockRuntimeId === null){
				throw new AssumptionFailedError("Unmapped blockstate returned by blockstate serializer: " . $blockStateData->toNbt());
			}
		}else{
			$blockRuntimeId = null;
		}

		return [$numericId, $itemData->getMeta(), $blockRuntimeId, $itemData->getTag()];
	}

	/**
	 * Returns the tag the serializer keeps the type of the item in (e.g. Type 1 for ominous banners), without the
	 * item's own named tag, or null if the type has none.
	 */
	public function getTypeTag(Item $item) : ?CompoundTag{
		try{
			$plain = clone $item;
			try{
				$plain->clearNamedTag();
			}catch(SavedDataLoadingException){
				//items that can't lose their NBT (firework rockets and stars): a fresh item of the same type
				$plain = $this->itemDeserializer->deserializeType($this->itemSerializer->serializeType($item));
			}
			return $this->itemSerializer->serializeType($plain)->getTag();
		}catch(ItemTypeSerializeException|ItemTypeDeserializeException){
			return null;
		}
	}

	/**
	 * @throws ItemTypeSerializeException
	 */
	public function toNetworkNbt(Item $item) : CompoundTag{
		//TODO: this relies on the assumption that network item NBT is the same as disk item NBT, which may not always
		//be true - if we stick on an older world version while updating network version, this could be a problem (and
		//may be a problem for multi version implementations)
		return $this->itemSerializer->serializeStack($item)->toNbt();
	}

	/**
	 * @param CompoundTag|null $nbt NBT sent by the client, for item types saved in a tag (e.g. ominous banners)
	 *
	 * @throws TypeConversionException
	 */
	public function fromNetworkId(int $networkId, int $networkMeta, int $networkBlockRuntimeId, ?CompoundTag $nbt = null) : Item{
		try{
			$stringId = $this->itemTypeDictionary->fromIntId($networkId);
		}catch(\InvalidArgumentException $e){
			//TODO: a quiet version of fromIntId() would be better than catching InvalidArgumentException
			throw TypeConversionException::wrap($e, "Invalid network itemstack ID $networkId");
		}

		$blockStateData = null;
		if($this->blockItemIdMap->lookupBlockId($stringId) !== null){
			$blockStateData = $this->blockStateDictionary->generateDataFromStateId($networkBlockRuntimeId);
			if($blockStateData === null){
				throw new TypeConversionException("Blockstate runtimeID $networkBlockRuntimeId does not correspond to any known blockstate");
			}
		}elseif($networkBlockRuntimeId !== self::NO_BLOCK_RUNTIME_ID){
			throw new TypeConversionException("Item $stringId is not a blockitem, but runtime ID $networkBlockRuntimeId was provided");
		}

		try{
			return $this->itemDeserializer->deserializeType(new SavedItemData($stringId, $networkMeta, $blockStateData, $nbt));
		}catch(ItemTypeDeserializeException $e){
			throw TypeConversionException::wrap($e, "Invalid network itemstack data");
		}
	}
}
