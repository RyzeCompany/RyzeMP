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

namespace pocketmine\block\vault;

use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\world\generator\vanilla\structure\TrialChamberContent;
use function count;
use function max;

/**
 * What a vault takes and gives (vanilla VaultConfig, saved by Bedrock in the "config" tag of the block entity): the
 * loot table of the rewards, the key item, the ranges within which players make it active, and an optional loot table
 * for the item it shows instead of the reward table. Trial chamber vaults use a trial key and
 * chests/trial_chambers/reward, ominous ones an ominous trial key and chests/trial_chambers/reward_ominous.
 */
final class VaultConfig{
	public const TAG_LOOT_TABLE = "loot_table"; //TAG_String, Bedrock path ("loot_tables/chests/trial_chambers/reward.json")
	public const TAG_ACTIVATION_RANGE = "activation_range"; //TAG_Float
	public const TAG_DEACTIVATION_RANGE = "deactivation_range"; //TAG_Float
	public const TAG_KEY_ITEM = "key_item"; //TAG_Compound, saved item
	public const TAG_OVERRIDE_LOOT_TABLE_TO_DISPLAY = "override_loot_table_to_display"; //TAG_String, "" for none

	public const DEFAULT_LOOT_TABLE = "loot_tables/chests/trial_chambers/reward.json";
	public const OMINOUS_LOOT_TABLE = "loot_tables/chests/trial_chambers/reward_ominous.json";
	public const DEFAULT_ACTIVATION_RANGE = 4.0;
	public const DEFAULT_DEACTIVATION_RANGE = 4.5;

	private Item $keyItem;

	public function __construct(
		private string $lootTable = self::DEFAULT_LOOT_TABLE,
		private float $activationRange = self::DEFAULT_ACTIVATION_RANGE,
		private float $deactivationRange = self::DEFAULT_DEACTIVATION_RANGE,
		?Item $keyItem = null,
		private ?string $overrideLootTableToDisplay = null
	){
		$this->keyItem = $keyItem !== null ? clone $keyItem : TrialChamberContent::trialKey();
		//vanilla refuses configs that would turn the vault off as soon as it turns on
		$this->deactivationRange = max($this->activationRange, $this->deactivationRange);
	}

	/**
	 * The config of the vaults of the ominous trial chamber rewards: an ominous trial key and the ominous rewards.
	 */
	public static function ominous() : self{
		return new self(self::OMINOUS_LOOT_TABLE, keyItem: TrialChamberContent::trialKey(ominous: true));
	}

	/** Loot table of the rewards (Bedrock path or Java id). */
	public function getLootTable() : string{ return $this->lootTable; }

	public function getActivationRange() : float{ return $this->activationRange; }

	public function getDeactivationRange() : float{ return $this->deactivationRange; }

	/**
	 * The key: an item of the same type is taken from the player (as many as the count of this item). A vault without
	 * a key (air) never opens.
	 */
	public function getKeyItem() : Item{ return clone $this->keyItem; }

	/** Loot table the shown item is picked from, if not the reward table. */
	public function getOverrideLootTableToDisplay() : ?string{ return $this->overrideLootTableToDisplay; }

	/** Loot table the shown item is picked from. */
	public function getLootTableToDisplay() : string{
		return $this->overrideLootTableToDisplay ?? $this->lootTable;
	}

	public function hasKey() : bool{
		return !$this->keyItem->isNull();
	}

	/**
	 * Whether the item opens the vault (vanilla VaultBlockEntity.Server.isValidToInsert): the same item as the key,
	 * at least as many.
	 */
	public function isKey(Item $item) : bool{
		return !$item->isNull() && $this->hasKey() && $item->equals($this->keyItem, true, false) && $item->getCount() >= $this->keyItem->getCount();
	}

	public static function fromNbt(CompoundTag $nbt) : self{
		$lootTable = $nbt->getTag(self::TAG_LOOT_TABLE);
		$override = $nbt->getTag(self::TAG_OVERRIDE_LOOT_TABLE_TO_DISPLAY);
		$keyTag = $nbt->getCompoundTag(self::TAG_KEY_ITEM);
		$key = null;
		if($keyTag !== null){
			$key = count($keyTag) > 0 ? Item::safeNbtDeserialize($keyTag, "Vault key item") : VanillaItems::AIR();
		}
		return new self(
			$lootTable instanceof StringTag && $lootTable->getValue() !== "" ? $lootTable->getValue() : self::DEFAULT_LOOT_TABLE,
			self::readRange($nbt, self::TAG_ACTIVATION_RANGE, self::DEFAULT_ACTIVATION_RANGE),
			self::readRange($nbt, self::TAG_DEACTIVATION_RANGE, self::DEFAULT_DEACTIVATION_RANGE),
			$key,
			$override instanceof StringTag && $override->getValue() !== "" ? $override->getValue() : null
		);
	}

	public static function readRange(CompoundTag $nbt, string $name, float $default) : float{
		$tag = $nbt->getTag($name);
		//Bedrock saves floats, Java doubles
		return $tag instanceof FloatTag || $tag instanceof DoubleTag ? max(0.0, $tag->getValue()) : $default;
	}

	public function toNbt() : CompoundTag{
		return CompoundTag::create()
			->setFloat(self::TAG_ACTIVATION_RANGE, $this->activationRange)
			->setFloat(self::TAG_DEACTIVATION_RANGE, $this->deactivationRange)
			->setTag(self::TAG_KEY_ITEM, $this->keyItem->nbtSerialize())
			->setString(self::TAG_LOOT_TABLE, $this->lootTable)
			->setString(self::TAG_OVERRIDE_LOOT_TABLE_TO_DISPLAY, $this->overrideLootTableToDisplay ?? "");
	}
}
