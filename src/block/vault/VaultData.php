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
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\utils\Utils;
use function array_key_first;
use function array_pop;
use function array_values;
use function count;
use function max;

/**
 * The state of a vault (vanilla VaultServerData and VaultSharedData): the players it rewarded, the reward being
 * ejected, the item it shows and the players near it that can open it.
 *
 * Bedrock saves it in the "data" tag of the block entity. Rewarded players are kept by UUID (saved in
 * "pm_rewarded_player_uuids"); the actor IDs of Bedrock's "rewarded_players" (worlds from Bedrock) are kept as they
 * are. Timers are server ticks and are not saved: a loaded vault updates at once.
 */
final class VaultData{
	public const TAG_DISPLAY_ITEM = "display_item"; //TAG_Compound, saved item
	public const TAG_ITEMS_TO_EJECT = "items_to_eject"; //TAG_List<TAG_Compound>, the next one last
	public const TAG_REWARDED_PLAYERS = "rewarded_players"; //TAG_List<TAG_Long>, Bedrock actor unique IDs
	public const TAG_STATE_UPDATING_RESUMES_AT = "state_updating_resumes_at"; //TAG_Long
	public const TAG_TOTAL_EJECTIONS_NEEDED = "total_ejections_needed"; //TAG_Int
	/** UUIDs of the players the vault rewarded (PocketMine). */
	public const TAG_REWARDED_PLAYER_UUIDS = "pm_rewarded_player_uuids"; //TAG_List<TAG_String>

	/** Players remembered at most; the oldest are forgotten (vanilla VaultServerData.MAX_REWARD_PLAYERS). */
	public const MAX_REWARDED_PLAYERS = 128;

	/**
	 * @var true[]
	 * @phpstan-var array<string, true>
	 */
	private array $rewardedPlayers = [];
	/**
	 * Bedrock actor IDs read from "rewarded_players", saved back unchanged.
	 * @var int[]
	 * @phpstan-var list<int>
	 */
	private array $bedrockRewardedPlayers = [];
	/**
	 * @var Item[]
	 * @phpstan-var list<Item>
	 */
	private array $itemsToEject = [];
	private int $totalEjectionsNeeded = 0;

	/** Server tick before which the state isn't updated (vanilla state_updating_resumes_at). */
	public int $stateUpdatingResumesAt = 0;
	/** Server tick of the last refused key sound. */
	public int $lastInsertFailTimestamp = -1000;

	private ?Item $displayItem = null;
	/**
	 * Players near the vault that it can reward: UUID => entity ID.
	 * @var int[]
	 * @phpstan-var array<string, int>
	 */
	private array $connectedPlayers = [];
	/** Whether the item shown or the connected players changed since the last {@link VaultData::clearSharedDirty()}. */
	private bool $sharedDirty = false;

	public function hasRewardedPlayer(string $uuid) : bool{
		return isset($this->rewardedPlayers[$uuid]);
	}

	/**
	 * Remembers a rewarded player (vanilla addToRewardedPlayers), forgetting the oldest beyond
	 * {@link VaultData::MAX_REWARDED_PLAYERS}.
	 */
	public function addRewardedPlayer(string $uuid) : void{
		unset($this->rewardedPlayers[$uuid]);
		$this->rewardedPlayers[$uuid] = true;
		while(count($this->rewardedPlayers) > self::MAX_REWARDED_PLAYERS){
			unset($this->rewardedPlayers[array_key_first($this->rewardedPlayers)]);
		}
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getRewardedPlayers() : array{
		$result = [];
		foreach(Utils::stringifyKeys($this->rewardedPlayers) as $uuid => $_){
			$result[] = $uuid;
		}
		return $result;
	}

	/**
	 * Items left to eject, the next one last.
	 * @return Item[]
	 * @phpstan-return list<Item>
	 */
	public function getItemsToEject() : array{
		return $this->itemsToEject;
	}

	/**
	 * @param Item[] $items
	 * @phpstan-param list<Item> $items
	 */
	public function setItemsToEject(array $items) : void{
		$this->itemsToEject = array_values($items);
		$this->totalEjectionsNeeded = count($this->itemsToEject);
	}

	public function getNextItemToEject() : ?Item{
		$count = count($this->itemsToEject);
		return $count > 0 ? $this->itemsToEject[$count - 1] : null;
	}

	public function popNextItemToEject() : ?Item{
		return array_pop($this->itemsToEject);
	}

	public function markEjectionFinished() : void{
		$this->totalEjectionsNeeded = 0;
	}

	public function getTotalEjectionsNeeded() : int{
		return $this->totalEjectionsNeeded;
	}

	public function getDisplayItem() : ?Item{
		return $this->displayItem;
	}

	public function setDisplayItem(?Item $item) : void{
		if($item !== null && $item->isNull()){
			$item = null;
		}
		if($item === null ? $this->displayItem === null : $this->displayItem !== null && $this->displayItem->equalsExact($item)){
			return;
		}
		$this->displayItem = $item !== null ? clone $item : null;
		$this->sharedDirty = true;
	}

	public function hasConnectedPlayers() : bool{
		return count($this->connectedPlayers) > 0;
	}

	/**
	 * @return int[] UUID => entity ID
	 * @phpstan-return array<string, int>
	 */
	public function getConnectedPlayers() : array{
		return $this->connectedPlayers;
	}

	/**
	 * Sets the players near the vault (UUID => entity ID); those it rewarded already are left out (vanilla
	 * updateConnectedPlayersWithinRange).
	 *
	 * @param int[] $players
	 * @phpstan-param array<string, int> $players
	 */
	public function setConnectedPlayers(array $players) : void{
		$connected = [];
		foreach(Utils::stringifyKeys($players) as $uuid => $entityId){
			if(!isset($this->rewardedPlayers[$uuid])){
				$connected[$uuid] = $entityId;
			}
		}
		if($connected !== $this->connectedPlayers){
			$this->connectedPlayers = $connected;
			$this->sharedDirty = true;
		}
	}

	public function isSharedDirty() : bool{
		return $this->sharedDirty;
	}

	public function clearSharedDirty() : void{
		$this->sharedDirty = false;
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->rewardedPlayers = [];
		$uuids = $nbt->getListTag(self::TAG_REWARDED_PLAYER_UUIDS);
		if($uuids !== null){
			foreach($uuids as $tag){
				if($tag instanceof StringTag && $tag->getValue() !== ""){
					$this->addRewardedPlayer($tag->getValue());
				}
			}
		}
		$this->bedrockRewardedPlayers = [];
		$ids = $nbt->getListTag(self::TAG_REWARDED_PLAYERS);
		if($ids !== null){
			foreach($ids as $tag){
				if($tag instanceof LongTag){
					$this->bedrockRewardedPlayers[] = $tag->getValue();
				}
			}
		}
		$this->itemsToEject = [];
		$items = $nbt->getListTag(self::TAG_ITEMS_TO_EJECT);
		if($items !== null){
			foreach($items as $tag){
				if($tag instanceof CompoundTag && count($tag) > 0){
					$item = Item::safeNbtDeserialize($tag, "Vault item to eject");
					if(!$item->isNull()){
						$this->itemsToEject[] = $item;
					}
				}
			}
		}
		$this->totalEjectionsNeeded = max(count($this->itemsToEject), $nbt->getInt(self::TAG_TOTAL_EJECTIONS_NEEDED, 0));
		$display = $nbt->getCompoundTag(self::TAG_DISPLAY_ITEM);
		$this->displayItem = null;
		if($display !== null && count($display) > 0){
			$item = Item::safeNbtDeserialize($display, "Vault display item");
			$this->displayItem = $item->isNull() ? null : $item;
		}
		$this->stateUpdatingResumesAt = 0;
	}

	public function writeSaveData(CompoundTag $nbt) : void{
		if($this->displayItem !== null){
			$nbt->setTag(self::TAG_DISPLAY_ITEM, $this->displayItem->nbtSerialize());
		}
		$items = [];
		foreach($this->itemsToEject as $item){
			$items[] = $item->nbtSerialize();
		}
		$nbt->setTag(self::TAG_ITEMS_TO_EJECT, new ListTag($items, NBT::TAG_Compound));
		$ids = [];
		foreach($this->bedrockRewardedPlayers as $id){
			$ids[] = new LongTag($id);
		}
		$nbt->setTag(self::TAG_REWARDED_PLAYERS, new ListTag($ids, NBT::TAG_Long));
		$uuids = [];
		foreach($this->getRewardedPlayers() as $uuid){
			$uuids[] = new StringTag($uuid);
		}
		$nbt->setTag(self::TAG_REWARDED_PLAYER_UUIDS, new ListTag($uuids, NBT::TAG_String));
		//server ticks mean nothing to another server: a loaded vault updates at once
		$nbt->setLong(self::TAG_STATE_UPDATING_RESUMES_AT, 0);
		$nbt->setInt(self::TAG_TOTAL_EJECTIONS_NEEDED, $this->totalEjectionsNeeded);
	}
}
