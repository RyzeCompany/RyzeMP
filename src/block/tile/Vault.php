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

use pocketmine\block\vault\VaultConfig;
use pocketmine\block\vault\VaultData;
use pocketmine\block\vault\WorldVaultHost;
use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\math\Vector3;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\player\Player;
use pocketmine\world\World;

/**
 * Block entity of a vault: its config ({@link VaultConfig}: key item, loot table, ranges) and its state
 * ({@link VaultData}: rewarded players, reward being ejected, item shown). The vault block runs it (see
 * {@link \pocketmine\block\Vault}).
 *
 * Saved like Bedrock: "config" and "data" compounds. Clients get the item shown and the players near the vault that it
 * can reward ("display_item", "connected_players", "connected_particle_range"), like Bedrock sends them.
 */
class Vault extends Spawnable{
	public const TAG_CONFIG = "config"; //TAG_Compound
	public const TAG_DATA = "data"; //TAG_Compound
	/** Network only: entity IDs of the players near the vault that it can reward (particles fly to them). */
	public const TAG_CONNECTED_PLAYERS = "connected_players"; //TAG_List<TAG_Long>
	/** Network only: range of the particles flying to the connected players. */
	public const TAG_CONNECTED_PARTICLE_RANGE = "connected_particle_range"; //TAG_Float
	/** Network: the item shown inside the vault (an empty item for none). */
	public const TAG_DISPLAY_ITEM = VaultData::TAG_DISPLAY_ITEM; //TAG_Compound

	private VaultConfig $config;
	private VaultData $data;
	private int $lastUpdateTick = -1;
	private ?WorldVaultHost $host = null;

	public function __construct(World $world, Vector3 $pos){
		$this->config = new VaultConfig();
		$this->data = new VaultData();
		parent::__construct($world, $pos);
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$config = $nbt->getCompoundTag(self::TAG_CONFIG);
		$this->config = $config !== null ? VaultConfig::fromNbt($config) : new VaultConfig();
		$this->data = new VaultData();
		$data = $nbt->getCompoundTag(self::TAG_DATA);
		if($data !== null){
			$this->data->readSaveData($data);
		}
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$nbt->setTag(self::TAG_CONFIG, $this->config->toNbt());
		$data = CompoundTag::create();
		$this->data->writeSaveData($data);
		$nbt->setTag(self::TAG_DATA, $data);
	}

	protected function getRequiredNetworkBlockName() : string{
		return BlockTypeNames::VAULT;
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt, TypeConverter $typeConverter) : void{
		$players = [];
		foreach($this->data->getConnectedPlayers() as $entityId){
			$players[] = new LongTag($entityId);
		}
		$nbt->setFloat(self::TAG_CONNECTED_PARTICLE_RANGE, $this->config->getDeactivationRange());
		$nbt->setTag(self::TAG_CONNECTED_PLAYERS, new ListTag($players, NBT::TAG_Long));
		$display = $this->data->getDisplayItem();
		$nbt->setTag(self::TAG_DISPLAY_ITEM, $display !== null && $typeConverter->getItemTranslator()->toNetworkIdQuiet($display) !== null ?
			$typeConverter->getItemTranslator()->toNetworkNbt($display) : CompoundTag::create()
			->setByte("Count", 0)
			->setShort("Damage", 0)
			->setString("Name", "")
			->setByte("WasPickedUp", 0));
	}

	public function getConfig() : VaultConfig{
		return $this->config;
	}

	/**
	 * Sets the key item, the loot table and the ranges of the vault.
	 */
	public function setConfig(VaultConfig $config) : void{
		$this->config = $config;
		$this->clearSpawnCompoundCache();
	}

	/**
	 * Returns whether the vault rewarded the player already (each player may open a vault once).
	 */
	public function hasRewardedPlayer(Player $player) : bool{
		return $this->data->hasRewardedPlayer($player->getUniqueId()->toString());
	}

	/**
	 * @internal
	 */
	public function getData() : VaultData{
		return $this->data;
	}

	/**
	 * @internal
	 */
	public function getHost() : WorldVaultHost{
		return $this->host ??= new WorldVaultHost($this);
	}

	/**
	 * Server tick of the last vault update, to keep a single update chain (not saved).
	 * @internal
	 */
	public function getLastUpdateTick() : int{ return $this->lastUpdateTick; }

	/**
	 * @internal
	 */
	public function setLastUpdateTick(int $tick) : void{ $this->lastUpdateTick = $tick; }

	/**
	 * Sends the item shown and the connected players to the players who can see the vault.
	 */
	public function broadcastSharedData() : void{
		$this->clearSpawnCompoundCache();
		$this->position->getWorld()->broadcastPacketToViewersByTypeConverter(
			$this->position,
			fn(TypeConverter $converter) : array => $this->canSpawnTo($converter) ?
				[BlockActorDataPacket::create(BlockPosition::fromVector3($this->position), $this->getSerializedSpawnCompound($converter))] : []
		);
	}
}
