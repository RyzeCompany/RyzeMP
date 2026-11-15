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

use pocketmine\entity\Entity;
use pocketmine\inventory\Inventory;
use pocketmine\loot\LootContext;
use pocketmine\loot\LootTables;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\world\Position;

/**
 * Lazy container loot (vanilla's RandomizableContainer): a container generated with a loot table and a seed is filled
 * from the table the first time its contents are needed (opened by a player, emptied by a hopper, broken...), with
 * the same items for the same seed. Until then only the table name and the seed are stored.
 *
 * The seed is a Java long (stored as "LootTableSeedLong"); "LootTableSeed" keeps its low 32 bits, the type Bedrock
 * uses, and is read when the long is missing (worlds from Bedrock).
 */
trait LootTableContainerTrait{
	private ?string $lootTable = null;
	private int $lootTableSeed = 0;

	abstract public function getRealInventory() : Inventory;

	abstract protected function getPosition() : Position;

	/**
	 * Returns the loot table that will fill the container (Bedrock path or Java id), or null if it was filled already.
	 */
	public function getLootTable() : ?string{
		return $this->lootTable;
	}

	public function getLootTableSeed() : int{
		return $this->lootTableSeed;
	}

	/**
	 * Sets the loot table that fills the container when it is first used (Bedrock path such as
	 * "loot_tables/chests/simple_dungeon.json" or Java id such as "minecraft:chests/simple_dungeon"). A seed of 0 rolls
	 * different items each time; any other seed always gives the same items.
	 */
	public function setLootTable(?string $lootTable, int $seed = 0) : void{
		$this->lootTable = $lootTable;
		$this->lootTableSeed = $seed;
	}

	protected function loadLootTable(CompoundTag $nbt) : void{
		$tableTag = $nbt->getTag(Container::TAG_LOOT_TABLE);
		$this->lootTable = $tableTag instanceof StringTag && $tableTag->getValue() !== "" ? $tableTag->getValue() : null;
		$longTag = $nbt->getTag(Container::TAG_LOOT_TABLE_SEED_LONG);
		$intTag = $nbt->getTag(Container::TAG_LOOT_TABLE_SEED);
		$this->lootTableSeed = $longTag instanceof LongTag ? $longTag->getValue() : ($intTag instanceof IntTag ? $intTag->getValue() : 0);
	}

	protected function saveLootTable(CompoundTag $nbt) : void{
		if($this->lootTable !== null){
			$nbt->setString(Container::TAG_LOOT_TABLE, $this->lootTable);
			$nbt->setInt(Container::TAG_LOOT_TABLE_SEED, ($this->lootTableSeed & 0xffffffff) - (($this->lootTableSeed & 0x80000000) << 1));
			$nbt->setLong(Container::TAG_LOOT_TABLE_SEED_LONG, $this->lootTableSeed);
		}
	}

	/**
	 * Fills the container from its loot table, if it has one (RandomizableContainer.unpackLootTable()). $entity is the
	 * entity using the container (usually the player opening it), if any.
	 */
	public function unpackLootTable(?Entity $entity = null) : void{
		if($this->lootTable === null){
			return;
		}
		$name = $this->lootTable;
		$seed = $this->lootTableSeed;
		$this->lootTable = null;
		$this->lootTableSeed = 0;
		$table = LootTables::get($name);
		if($table === null){
			\GlobalLogger::get()->debug("Loot table $name of the container at " . $this->getPosition() . " is not available, the container stays empty");
			return;
		}
		$position = $this->getPosition();
		$context = LootContext::seeded($seed);
		$context->world = $position->getWorld();
		$context->origin = new Vector3($position->getFloorX() + 0.5, $position->getFloorY() + 0.5, $position->getFloorZ() + 0.5);
		$context->thisEntity = $entity;
		$table->fillInventory($this->getRealInventory(), $context);
	}
}
