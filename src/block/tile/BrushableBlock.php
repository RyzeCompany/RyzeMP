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

namespace pocketmine\block\tile;

use pocketmine\block\BrushableBlock as BrushableBlockState;
use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\loot\LootContext;
use pocketmine\loot\LootStack;
use pocketmine\loot\LootTables;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use function count;

/**
 * Block entity of suspicious sand and gravel ("BrushableBlock" in vanilla). Holds either a loot table that is rolled
 * the first time the block is brushed, or the item that was already rolled, plus the brushing progress.
 */
class BrushableBlock extends Spawnable{
	public const TAG_LOOT_TABLE = "LootTable"; //TAG_String
	public const TAG_LOOT_TABLE_SEED = "LootTableSeed"; //TAG_Int
	/** the full Java seed (LootTableSeed only holds its low 32 bits, the type Bedrock uses) */
	public const TAG_LOOT_TABLE_SEED_LONG = "LootTableSeedLong"; //TAG_Long
	public const TAG_ITEM = "item"; //TAG_Compound
	public const TAG_BRUSH_COUNT = "brush_count"; //TAG_Int
	public const TAG_BRUSH_DIRECTION = "brush_direction"; //TAG_Byte
	public const TAG_TYPE = "type"; //TAG_String

	private ?string $lootTable = null;
	private int $lootTableSeed = 0;
	private ?Item $item = null;
	private int $brushCount = 0;
	private int $brushDirection = 6; //vanilla default: no direction

	/** server tick before which brushing does nothing (not saved) */
	public int $cooldownEndsAtTick = 0;
	/** server tick at which unfinished brushing starts to decay (not saved) */
	public int $brushCountResetsAtTick = 0;

	public function getLootTable() : ?string{ return $this->lootTable; }

	/**
	 * Sets the loot table rolled when the block is first brushed (Bedrock path or Java id). With a seed other than 0,
	 * the same seed always uncovers the same item.
	 */
	public function setLootTable(?string $lootTable, int $seed = 0) : void{
		$this->lootTable = $lootTable;
		$this->lootTableSeed = $seed;
	}

	public function getLootTableSeed() : int{ return $this->lootTableSeed; }

	public function getItem() : ?Item{ return $this->item !== null ? clone $this->item : null; }

	public function setItem(?Item $item) : void{
		$this->item = $item === null || $item->isNull() ? null : clone $item;
	}

	/** Reveals one item using the generated seed and the world's pinned loot data. */
	public function unpackLootTable(?Entity $brushingEntity = null, ?Item $brush = null) : ?Item{
		if($this->lootTable === null){
			return $this->getItem();
		}
		$world = $this->position->getWorld();
		try{
			$table = LootTables::forWorld($world)?->get($this->lootTable);
			if($table === null){
				return null;
			}
			$context = LootContext::seeded($this->lootTableSeed);
			$context->world = $world;
			$context->origin = $this->position->add(0.5, 0.5, 0.5);
			$context->thisEntity = $brushingEntity;
			if($brush !== null){
				$context->tool = new LootStack(GlobalItemDataHandlers::getSerializer()->serializeType($brush)->getName());
			}
			$this->item = $table->generate($context)[0] ?? null;
			$this->lootTable = null;
			$this->lootTableSeed = 0;
			$this->clearSpawnCompoundCache();
		}catch(\UnexpectedValueException|\RuntimeException|\JsonException $e){
			$world->getLogger()->warning("Unable to reveal generated archaeology loot at " . $this->position . ": " . $e->getMessage());
		}
		return $this->getItem();
	}

	public function getBrushCount() : int{ return $this->brushCount; }

	public function setBrushCount(int $brushCount) : void{
		$this->brushCount = $brushCount;
		$this->clearSpawnCompoundCache();
	}

	public function getBrushDirection() : int{ return $this->brushDirection; }

	public function setBrushDirection(int $brushDirection) : void{
		$this->brushDirection = $brushDirection;
		$this->clearSpawnCompoundCache();
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$lootTable = $nbt->getString(self::TAG_LOOT_TABLE, "");
		$this->lootTable = $lootTable !== "" ? $lootTable : null;
		$longSeed = $nbt->getTag(self::TAG_LOOT_TABLE_SEED_LONG);
		$intSeed = $nbt->getTag(self::TAG_LOOT_TABLE_SEED);
		$this->lootTableSeed = $longSeed instanceof LongTag ? $longSeed->getValue() : ($intSeed instanceof IntTag ? $intSeed->getValue() : 0);
		$itemTag = $nbt->getCompoundTag(self::TAG_ITEM);
		$this->item = null;
		if($itemTag !== null && count($itemTag) > 0){
			$item = Item::safeNbtDeserialize($itemTag, "BrushableBlock ($this->position)");
			$this->item = $item->isNull() ? null : $item;
		}
		$this->brushCount = $nbt->getInt(self::TAG_BRUSH_COUNT, 0);
		$this->brushDirection = $nbt->getByte(self::TAG_BRUSH_DIRECTION, 6);
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		if($this->lootTable !== null){
			$nbt->setString(self::TAG_LOOT_TABLE, $this->lootTable);
			$nbt->setInt(self::TAG_LOOT_TABLE_SEED, ($this->lootTableSeed & 0xffffffff) - (($this->lootTableSeed & 0x80000000) << 1));
			$nbt->setLong(self::TAG_LOOT_TABLE_SEED_LONG, $this->lootTableSeed);
		}
		if($this->item !== null){
			$nbt->setTag(self::TAG_ITEM, $this->item->nbtSerialize());
		}
		$nbt->setInt(self::TAG_BRUSH_COUNT, $this->brushCount);
		$nbt->setByte(self::TAG_BRUSH_DIRECTION, $this->brushDirection);
	}

	protected function getRequiredNetworkBlockName() : ?string{
		$block = $this->getBlock();
		return $block instanceof BrushableBlockState ? GlobalBlockStateHandlers::getSerializer()->serializeBlock($block)->getName() : null;
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt, TypeConverter $typeConverter) : void{
		if($this->item !== null && $typeConverter->getItemTranslator()->toNetworkIdQuiet($this->item) !== null){
			$nbt->setTag(self::TAG_ITEM, $typeConverter->getItemTranslator()->toNetworkNbt($this->item));
		}
		$nbt->setInt(self::TAG_BRUSH_COUNT, $this->brushCount);
		$nbt->setByte(self::TAG_BRUSH_DIRECTION, $this->brushDirection);
	}
}
