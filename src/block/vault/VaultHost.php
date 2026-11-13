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

/**
 * What {@link VaultLogic} needs from the world around a vault: the time, the players nearby, the loot tables, its
 * block state and the effects players see and hear.
 * @internal
 */
interface VaultHost{

	/** Current game time in ticks. */
	public function getGameTime() : int;

	/**
	 * Returns the players within the range of the vault (block positions closer than $range), spectators excepted:
	 * UUID => entity ID.
	 *
	 * @return int[]
	 * @phpstan-return array<string, int>
	 */
	public function detectPlayers(float $range) : array;

	/** Rolls a loot table and returns one of the items at random, or null if it gave nothing. */
	public function rollDisplayItem(string $lootTable) : ?Item;

	/**
	 * Rolls the rewards of a player (entity ID) who opened the vault with the key item.
	 *
	 * @return Item[]
	 * @phpstan-return list<Item>
	 */
	public function rollRewards(string $lootTable, int $playerEntityId, Item $key) : array;

	/** Whether the vault block is ominous. */
	public function isOminous() : bool;

	/** Changes the state of the vault block. */
	public function setVaultState(VaultState $state) : void;

	/** Ejects one item of the reward upwards. */
	public function ejectItem(Item $item) : void;

	/** The vault turned on (it shows an item) or off. */
	public function onActivated(bool $active, bool $ominous) : void;

	/** A key was taken (vanilla VAULT_INSERT_ITEM). */
	public function onKeyInserted() : void;

	/** The shutter opened (before the first item is ejected) or closed (after the last one). */
	public function onShutter(bool $open) : void;

	/**
	 * An item was refused: not the key ($rewardedPlayer false), or the player was rewarded already.
	 */
	public function onInsertFailed(bool $rewardedPlayer) : void;
}
