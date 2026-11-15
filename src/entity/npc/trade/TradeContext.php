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

namespace pocketmine\entity\npc\trade;

use pocketmine\item\Item;
use pocketmine\utils\Random;

/**
 * What a trade table needs to know about the trader while it makes offers: the random source, the trader's mark
 * variant (villager biome type, for "is_mark_variant" filters) and dimension (for "in_overworld"). While a trade is
 * built, item functions can also change the wanted items, e.g. the enchanted book price
 * ({@link TradeContext::getWant()}).
 *
 * @internal
 */
final class TradeContext{
	/**
	 * Wanted items of the trade being built.
	 *
	 * @var Item[]
	 * @phpstan-var list<Item>
	 */
	private array $wants = [];

	/**
	 * @phpstan-param (\Closure(Item $map, string $destination) : ?Item)|null $explorationMaps makes the explorer map of
	 *                a destination ("village_plains", "monument"...) from the trade's map item, null if it can't; no
	 *                explorer maps without it
	 */
	public function __construct(
		private Random $random,
		private int $markVariant = 0,
		private bool $inOverworld = true,
		private ?\Closure $explorationMaps = null
	){}

	public function getRandom() : Random{ return $this->random; }

	public function getMarkVariant() : int{ return $this->markVariant; }

	public function isInOverworld() : bool{ return $this->inOverworld; }

	/**
	 * Explorer map of the destination made from the trade's map item, or null if the trader can't make one.
	 */
	public function createExplorationMap(Item $map, string $destination) : ?Item{
		return $this->explorationMaps !== null ? ($this->explorationMaps)($map, $destination) : null;
	}

	/**
	 * Wanted item of the trade being built (0 = first price, 1 = second), or null if there is none. Changes to the
	 * returned item (e.g. its count) are kept.
	 */
	public function getWant(int $index) : ?Item{
		return $this->wants[$index] ?? null;
	}

	/**
	 * @param Item[] $wants
	 * @phpstan-param list<Item> $wants
	 */
	public function setWants(array $wants) : void{
		$this->wants = $wants;
	}

	/** Random integer between min and max, both included. */
	public function nextRange(int $min, int $max) : int{
		return $max > $min ? $this->random->nextRange($min, $max) : $min;
	}
}
