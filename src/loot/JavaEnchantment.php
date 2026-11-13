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

namespace pocketmine\loot;

use function in_array;
use function is_array;
use function is_int;

/**
 * A Java Edition enchantment definition (data/minecraft/enchantment/*.json): the values the loot functions use
 * (levels, weight, costs, supported/primary items and exclusive set).
 */
final class JavaEnchantment{
	public readonly int $maxLevel;
	public readonly int $weight;
	private int $minCostBase;
	private int $minCostPerLevel;
	private int $maxCostBase;
	private int $maxCostPerLevel;
	private mixed $supportedItems;
	private mixed $primaryItems;
	private mixed $exclusiveSet;
	/**
	 * @var string[]|null
	 * @phpstan-var list<string>|null
	 */
	private ?array $exclusive = null;

	/**
	 * @param mixed[] $json
	 */
	public function __construct(
		private LootData $data,
		public readonly string $id,
		array $json
	){
		$this->maxLevel = is_int($json["max_level"] ?? null) ? $json["max_level"] : 1;
		$this->weight = is_int($json["weight"] ?? null) ? $json["weight"] : 1;
		[$this->minCostBase, $this->minCostPerLevel] = self::cost($json["min_cost"] ?? null);
		[$this->maxCostBase, $this->maxCostPerLevel] = self::cost($json["max_cost"] ?? null);
		$this->supportedItems = $json["supported_items"] ?? [];
		$this->primaryItems = $json["primary_items"] ?? null;
		$this->exclusiveSet = $json["exclusive_set"] ?? null;
	}

	/**
	 * @phpstan-return array{int, int}
	 */
	private static function cost(mixed $json) : array{
		if(!is_array($json)){
			return [0, 0];
		}
		return [
			is_int($json["base"] ?? null) ? $json["base"] : 0,
			is_int($json["per_level_above_first"] ?? null) ? $json["per_level_above_first"] : 0
		];
	}

	public function getMinLevel() : int{
		return 1;
	}

	public function getMinCost(int $level) : int{
		return $this->minCostBase + $this->minCostPerLevel * ($level - 1);
	}

	public function getMaxCost(int $level) : int{
		return $this->maxCostBase + $this->maxCostPerLevel * ($level - 1);
	}

	/**
	 * Enchantment.canEnchant() / isSupportedItem()
	 */
	public function canEnchant(string $itemId) : bool{
		return $this->data->itemIs($itemId, $this->supportedItems);
	}

	/**
	 * Enchantment.isPrimaryItem()
	 */
	public function isPrimaryItem(string $itemId) : bool{
		return $this->canEnchant($itemId) && ($this->primaryItems === null || $this->data->itemIs($itemId, $this->primaryItems));
	}

	/**
	 * Enchantment.areCompatible()
	 */
	public static function areCompatible(JavaEnchantment $a, JavaEnchantment $b) : bool{
		return $a->id !== $b->id && !in_array($b->id, $a->getExclusiveSet(), true) && !in_array($a->id, $b->getExclusiveSet(), true);
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	private function getExclusiveSet() : array{
		return $this->exclusive ??= $this->exclusiveSet === null ? [] : $this->data->holderSet("enchantment", $this->exclusiveSet);
	}
}
