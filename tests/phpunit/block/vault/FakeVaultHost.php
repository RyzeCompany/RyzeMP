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

use pocketmine\block\utils\VaultState;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\utils\Utils;
use function count;

/**
 * A vault without a world: players are UUIDs at a distance, loot tables give fixed items.
 */
final class FakeVaultHost implements VaultHost{
	public int $time = 0;
	public bool $ominous = false;
	public VaultState $state = VaultState::INACTIVE;
	/**
	 * Players: UUID => [entity ID, distance in blocks].
	 * @phpstan-var array<string, array{int, float}>
	 */
	public array $players = [];
	/**
	 * Items each loot table gives.
	 * @phpstan-var array<string, list<Item>>
	 */
	public array $tables = [];
	/** @phpstan-var list<string> */
	public array $rolledTables = [];
	/** @phpstan-var list<int> */
	public array $rolledFor = [];
	/** @phpstan-var list<Item> */
	public array $ejected = [];
	/** @phpstan-var list<int> */
	public array $ejectTimes = [];
	/** @phpstan-var list<string> */
	public array $events = [];
	public int $displayIndex = 0;

	public function getGameTime() : int{ return $this->time; }

	public function detectPlayers(float $range) : array{
		$found = [];
		foreach(Utils::stringifyKeys($this->players) as $uuid => [$entityId, $distance]){
			if($distance < $range){
				$found[$uuid] = $entityId;
			}
		}
		return $found;
	}

	public function rollDisplayItem(string $lootTable) : ?Item{
		$items = $this->tables[$lootTable] ?? [];
		if(count($items) === 0){
			return null;
		}
		return $items[$this->displayIndex++ % count($items)];
	}

	public function rollRewards(string $lootTable, int $playerEntityId, Item $key) : array{
		$this->rolledTables[] = $lootTable;
		$this->rolledFor[] = $playerEntityId;
		return $this->tables[$lootTable] ?? [];
	}

	public function isOminous() : bool{ return $this->ominous; }

	public function setVaultState(VaultState $state) : void{
		$this->state = $state;
	}

	public function ejectItem(Item $item) : void{
		$this->ejected[] = $item;
		$this->ejectTimes[] = $this->time;
	}

	public function onActivated(bool $active, bool $ominous) : void{
		$this->events[] = ($active ? "activate" : "deactivate") . ($ominous ? " ominous" : "");
	}

	public function onKeyInserted() : void{
		$this->events[] = "insert";
	}

	public function onShutter(bool $open) : void{
		$this->events[] = $open ? "open" : "close";
	}

	public function onInsertFailed(bool $rewardedPlayer) : void{
		$this->events[] = $rewardedPlayer ? "reject rewarded" : "insert fail";
	}

	/**
	 * Items of a reward table: an emerald, two diamonds and an arrow.
	 * @phpstan-return list<Item>
	 */
	public static function rewardItems() : array{
		return [VanillaItems::EMERALD(), VanillaItems::DIAMOND()->setCount(2), VanillaItems::ARROW()];
	}
}
