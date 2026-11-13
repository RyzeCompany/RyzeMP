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

use pocketmine\data\bedrock\item\ItemTypeDeserializeException;
use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\item\Item;
use pocketmine\item\PotterySherd;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\PotterySherdType;
use pocketmine\item\VanillaItems;
use pocketmine\loot\LootContext;
use pocketmine\loot\LootTables;
use pocketmine\math\Vector3;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use function count;

/**
 * Decorated pot block entity: the pattern on each of the 4 sides and the stack of items stored inside.
 * The vanilla "sherds" tag lists the item used for each side as [back, left, right, front]; "minecraft:brick" (or a
 * missing/empty entry) means a plain side. The same tag is used at the root of the decorated pot item.
 *
 * A pot may hold a loot table instead of an item yet ("LootTable" and "LootTableSeed", like containers): it is rolled
 * the first time the item is needed, e.g. when the pot breaks.
 */
class DecoratedPot extends Spawnable{
	public const TAG_SHERDS = "sherds"; //TAG_List<TAG_String>
	public const TAG_ITEM = "item"; //TAG_Compound
	public const TAG_ANIMATION = "animation"; //TAG_Byte, only sent to clients

	public const SIDE_BACK = 0;
	public const SIDE_LEFT = 1;
	public const SIDE_RIGHT = 2;
	public const SIDE_FRONT = 3;

	public const ANIMATION_NONE = 0;
	public const ANIMATION_WOBBLE_FAIL = 1;
	public const ANIMATION_WOBBLE_SUCCESS = 2;

	private const BRICK_ID = "minecraft:brick";

	/**
	 * @var (PotterySherdType|null)[]
	 * @phpstan-var array{PotterySherdType|null, PotterySherdType|null, PotterySherdType|null, PotterySherdType|null}
	 */
	private array $sherds = [null, null, null, null];

	private ?Item $item = null;

	/** Loot table of the item inside, rolled the first time the item is needed (vanilla RandomizableContainer). */
	private ?string $lootTable = null;
	private int $lootTableSeed = 0;

	/**
	 * @return (PotterySherdType|null)[] [back, left, right, front], null for a plain (brick) side
	 * @phpstan-return array{PotterySherdType|null, PotterySherdType|null, PotterySherdType|null, PotterySherdType|null}
	 */
	public function getSherds() : array{ return $this->sherds; }

	/**
	 * @param (PotterySherdType|null)[] $sherds
	 * @phpstan-param array{PotterySherdType|null, PotterySherdType|null, PotterySherdType|null, PotterySherdType|null} $sherds
	 */
	public function setSherds(array $sherds) : void{
		$this->sherds = $sherds;
	}

	public function getItem() : ?Item{
		$this->unpackLootTable();
		return $this->item !== null ? clone $this->item : null;
	}

	public function setItem(?Item $item) : void{
		$this->unpackLootTable();
		$this->item = $item === null || $item->isNull() ? null : clone $item;
	}

	/**
	 * Rolls the loot table of the pot, if it has one (vanilla DecoratedPotBlockEntity: when its item is first read or
	 * changed, e.g. when it breaks). Trial chamber corridor pots use pots/trial_chambers/corridor.
	 */
	private function unpackLootTable() : void{
		if($this->lootTable === null || $this->item !== null){
			return;
		}
		$world = $this->position->getWorld();
		$name = $this->lootTable;
		try{
			$table = LootTables::forWorld($world)?->get($name);
			if($table === null){
				return; //do not destroy unopened loot when a pinned worldgen cache is missing
			}
			$context = LootContext::seeded($this->lootTableSeed);
			$context->world = $world;
			$context->origin = new Vector3($this->position->getFloorX() + 0.5, $this->position->getFloorY() + 0.5, $this->position->getFloorZ() + 0.5);
			$this->item = $table->generate($context)[0] ?? null;
			$this->lootTable = null;
			$this->lootTableSeed = 0;
			$this->clearSpawnCompoundCache();
		}catch(\RuntimeException|\UnexpectedValueException|\JsonException $e){
			$world->getLogger()->warning("Unable to roll decorated pot loot at " . $this->position . ": " . $e->getMessage());
		}
	}

	public static function sherdToId(?PotterySherdType $type) : string{
		if($type === null){
			return self::BRICK_ID;
		}
		return GlobalItemDataHandlers::getSerializer()->serializeType((new PotterySherd(new ItemIdentifier(ItemTypeIds::POTTERY_SHERD), "Pottery Sherd"))->setSherdType($type))->getName();
	}

	public static function idToSherd(string $id) : ?PotterySherdType{
		if($id === "" || $id === self::BRICK_ID){
			return null;
		}
		try{
			$item = GlobalItemDataHandlers::getDeserializer()->deserializeType(new SavedItemData($id));
		}catch(ItemTypeDeserializeException){
			return null;
		}
		return $item instanceof PotterySherd ? $item->getSherdType() : null;
	}

	/**
	 * @phpstan-return array{PotterySherdType|null, PotterySherdType|null, PotterySherdType|null, PotterySherdType|null}
	 */
	public static function readSherds(CompoundTag $nbt) : array{
		$result = [null, null, null, null];
		$list = $nbt->getListTag(self::TAG_SHERDS, StringTag::class);
		if($list !== null){
			foreach($list->getValue() as $i => $tag){
				if($i >= 4){
					break;
				}
				$result[$i] = self::idToSherd($tag->getValue());
			}
		}
		return $result;
	}

	/**
	 * @param (PotterySherdType|null)[] $sherds
	 */
	public static function writeSherds(CompoundTag $nbt, array $sherds) : void{
		$list = new ListTag([], NBT::TAG_String);
		foreach($sherds as $sherd){
			$list->push(new StringTag(self::sherdToId($sherd)));
		}
		$nbt->setTag(self::TAG_SHERDS, $list);
	}

	/**
	 * @param (PotterySherdType|null)[] $sherds
	 */
	public static function hasDecorations(array $sherds) : bool{
		foreach($sherds as $sherd){
			if($sherd !== null){
				return true;
			}
		}
		return false;
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->sherds = self::readSherds($nbt);
		$itemTag = $nbt->getCompoundTag(self::TAG_ITEM);
		$this->item = null;
		if($itemTag !== null && count($itemTag) > 0){
			$item = Item::safeNbtDeserialize($itemTag, "DecoratedPot ($this->position)");
			$this->item = $item->isNull() ? null : $item;
		}
		//same tags as containers; Bedrock has the 32-bit seed only
		$tableTag = $nbt->getTag(Container::TAG_LOOT_TABLE);
		$this->lootTable = $tableTag instanceof StringTag && $tableTag->getValue() !== "" ? $tableTag->getValue() : null;
		$longTag = $nbt->getTag(Container::TAG_LOOT_TABLE_SEED_LONG);
		$intTag = $nbt->getTag(Container::TAG_LOOT_TABLE_SEED);
		$this->lootTableSeed = $longTag instanceof LongTag ? $longTag->getValue() : ($intTag instanceof IntTag ? $intTag->getValue() : 0);
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		self::writeSherds($nbt, $this->sherds);
		if($this->item !== null){
			$nbt->setTag(self::TAG_ITEM, $this->item->nbtSerialize());
		}
		if($this->lootTable !== null){
			$nbt->setString(Container::TAG_LOOT_TABLE, $this->lootTable);
			$nbt->setInt(Container::TAG_LOOT_TABLE_SEED, ($this->lootTableSeed & 0xffffffff) - (($this->lootTableSeed & 0x80000000) << 1));
			$nbt->setLong(Container::TAG_LOOT_TABLE_SEED_LONG, $this->lootTableSeed);
		}
	}

	public function copyDataFromItem(Item $item) : void{
		//MCPE stores the sherds at the root of the item tag
		$this->sherds = self::readSherds($item->getNamedTag());
	}

	public function getCleanedNBT() : ?CompoundTag{
		$nbt = parent::getCleanedNBT();
		$nbt?->removeTag(self::TAG_ITEM, Container::TAG_LOOT_TABLE, Container::TAG_LOOT_TABLE_SEED, Container::TAG_LOOT_TABLE_SEED_LONG);
		return $nbt;
	}

	protected function getRequiredNetworkBlockName() : string{
		return BlockTypeNames::DECORATED_POT;
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt, TypeConverter $typeConverter) : void{
		self::writeSherds($nbt, $this->sherds);
		if($this->item !== null && $typeConverter->getItemTranslator()->toNetworkIdQuiet($this->item) !== null){
			$nbt->setTag(self::TAG_ITEM, $typeConverter->getItemTranslator()->toNetworkNbt($this->item));
		}
	}

	/**
	 * Creates the packet that makes clients play the wobble animation of the pot.
	 */
	public function createAnimationPacket(TypeConverter $typeConverter, int $animation) : BlockActorDataPacket{
		$nbt = $this->getSpawnCompound($typeConverter);
		$nbt->setByte(self::TAG_ANIMATION, $animation);
		return BlockActorDataPacket::create(BlockPosition::fromVector3($this->position), new CacheableNbt($nbt));
	}
}
