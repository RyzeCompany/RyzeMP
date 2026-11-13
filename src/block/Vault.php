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

namespace pocketmine\block;

use pocketmine\block\tile\Vault as TileVault;
use pocketmine\block\utils\FacesOppositePlacingPlayerTrait;
use pocketmine\block\utils\HorizontalFacing;
use pocketmine\block\utils\SupportType;
use pocketmine\block\utils\VaultState;
use pocketmine\block\vault\VaultConfig;
use pocketmine\block\vault\VaultLogic;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\player\Player;

/**
 * Vault (https://minecraft.wiki/w/Vault). It turns on when a player it didn't reward yet comes within 4 blocks and shows
 * the items it may give; using its key item on it (a trial key, or an ominous trial key for the vaults of ominous trial
 * chambers) takes the key and ejects a reward rolled for the player, who is remembered for good (see
 * {@link VaultLogic}). Its block entity ({@link TileVault}) keeps the config and the rewarded players. It only runs in
 * loaded chunks. Never drops, like vanilla.
 */
class Vault extends Transparent implements HorizontalFacing{
	use FacesOppositePlacingPlayerTrait;

	protected VaultState $vaultState = VaultState::INACTIVE;
	protected bool $ominous = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacing($this->facing);
		$w->enum($this->vaultState);
		$w->bool($this->ominous);
	}

	public function getVaultState() : VaultState{ return $this->vaultState; }

	/** @return $this */
	public function setVaultState(VaultState $state) : self{
		$this->vaultState = $state;
		return $this;
	}

	public function isOminous() : bool{ return $this->ominous; }

	/** @return $this */
	public function setOminous(bool $ominous) : self{
		$this->ominous = $ominous;
		return $this;
	}

	public function getLightLevel() : int{
		return $this->vaultState === VaultState::INACTIVE ? 6 : 12;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	public function isAffectedBySilkTouch() : bool{
		return false;
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	private function getTile() : ?TileVault{
		$tile = $this->position->getWorld()->getTile($this->position);
		return $tile instanceof TileVault ? $tile : null;
	}

	/**
	 * An active vault takes its key (vanilla VaultBlock.useItemOn): other items, and the keys of players it rewarded
	 * already, are refused with a sound. An inactive vault does nothing (the item is used as usual).
	 */
	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$tile = $this->getTile();
		if($tile === null){
			//vaults generated before vaults had a block entity: give them one, it starts looking for players
			$world = $this->position->getWorld();
			$tile = new TileVault($world, $this->position->asVector3());
			if($this->ominous){
				$tile->setConfig(VaultConfig::ominous());
			}
			$world->addTile($tile);
			return false;
		}
		if($player === null || $item->isNull() || $this->vaultState !== VaultState::ACTIVE){
			return false;
		}
		$host = $tile->getHost();
		$host->setBlock($this);
		$oldState = $this->vaultState;
		$taken = VaultLogic::tryInsertKey($this->vaultState, $tile->getConfig(), $tile->getData(), $host, $player->getUniqueId()->toString(), $player->getId(), $item);
		if($taken > 0){
			$item->pop($taken);
		}
		$this->applyChanges($tile, $oldState);
		return true;
	}

	/**
	 * Runs the vault (its block entity holds the state machine's data) and schedules its next update.
	 */
	public function onScheduledUpdate() : void{
		$tile = $this->getTile();
		if($tile === null){
			return;
		}
		//several update chains may be started (block entity loaded, key used...): keep a single one
		$now = $this->position->getWorld()->getServer()->getTick();
		if($tile->getLastUpdateTick() === $now){
			return;
		}
		$tile->setLastUpdateTick($now);

		$host = $tile->getHost();
		$host->setBlock($this);
		$oldState = $this->vaultState;
		VaultLogic::tick($this->vaultState, $tile->getConfig(), $tile->getData(), $host);
		$this->applyChanges($tile, $oldState);
	}

	/**
	 * Sends the changes of the vault to the players who see it and schedules its next update.
	 */
	private function applyChanges(TileVault $tile, VaultState $oldState) : void{
		$world = $this->position->getWorld();
		$data = $tile->getData();
		if($this->vaultState !== $oldState){
			if($data->isSharedDirty()){
				$data->clearSharedDirty();
				$tile->clearSpawnCompoundCache();
			}
			$world->setBlock($this->position, $this); //also resends the block entity
		}elseif($data->isSharedDirty()){
			$data->clearSharedDirty();
			$tile->broadcastSharedData();
		}
		$world->scheduleDelayedBlockUpdate($this->position, VaultLogic::getNextUpdateDelay($this->vaultState, $data, $tile->getHost()));
	}
}
